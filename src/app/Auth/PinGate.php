<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\DatabaseInterface;

/**
 * Porte du PIN d'action sensible (RG-T13 + throttle RG-T22), factorisee pour
 * l'API d'administration JSON (`App\Controllers\Admin\Api`).
 *
 * Reproduit la meme sequence que celle deja en place dans UserController/
 * RoleController (methode privee `resolvePin`) et ProductController/
 * MenuController (bloc inline de destroy()/update()) : verrou evalue AVANT la
 * verification (gate-before-verify, leurre de timing sous verrou actif), puis
 * sur PIN invalide, trace `pin.failed` (RG-T14) + increment du throttle
 * (RG-T22) dans UNE seule transaction (RG-T08). `entity_id` suit la meme regle
 * que `UserController::logFailedPin()`/`RoleController::logFailedPin()` : un id
 * 0 (creation, avant que la ligne existe) est ecrit `NULL`, jamais `0` (une FK
 * `entity_id = 0` designerait une ligne qui n'existe pas).
 *
 * Les controleurs HTML existants gardent leur propre copie historique (moindre
 * risque de regression a les toucher pour un CRUD deja en production) ; ce
 * service evite de re-dupliquer une septieme fois la meme sequence pour les six
 * ressources de l'API JSON.
 *
 * `auditFailedPin()` (RGPD art. 5.1.c, minimisation) est en revanche PARTAGEE par
 * les 7 points d'ecriture de `pin.failed` (les 6 controleurs HTML delegants +
 * `resolve()` ci-dessous pour l'API) : c'est la SEULE fonction qui ecrit cette
 * ligne d'audit, precisement pour qu'un correctif comme celui-ci n'ait qu'un
 * seul endroit a changer.
 */
final class PinGate
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly PinVerifier $verifier,
        private readonly PinThrottle $throttle,
    ) {
    }

    /**
     * Resout l'acteur agissant (email + PIN, modele "identifiant equipier + PIN",
     * RG-T13). Renvoie null sur verrou actif ou PIN invalide ; l'appelant traduit
     * en reponse 422 PIN_INVALID.
     *
     * @return array{id: int, role_id: int}|null
     */
    public function resolve(int $actorSessionUserId, string $email, string $pin, string $entityType, ?int $entityId): ?array
    {
        if ($actorSessionUserId > 0 && $this->throttle->isLocked($actorSessionUserId)) {
            $this->verifier->payTimingDecoy($pin);

            return null;
        }

        $actor = $this->verifier->resolveActingUser($email, $pin);
        if ($actor === null) {
            // Meme normalisation que UserController/RoleController::logFailedPin() :
            // 0 (creation) -> NULL, jamais une FK vers une ligne qui n'existe pas.
            $normalizedEntityId = ($entityId !== null && $entityId > 0) ? $entityId : null;
            $this->db->transaction(function (DatabaseInterface $db) use ($email, $entityType, $normalizedEntityId, $actorSessionUserId): void {
                self::auditFailedPin($db, $email, $entityType, $normalizedEntityId, 'action sensible');
                $this->throttle->recordFailureWithin($db, $actorSessionUserId);
            });

            return null;
        }

        return $actor;
    }

    /**
     * Ecrit la ligne d'audit `pin.failed` (RG-T14) SANS jamais y ecrire l'adresse
     * SAISIE au formulaire -- minimisation des donnees (RGPD art. 5.1.c). Point
     * d'ecriture UNIQUE, partage par les 6 controleurs HTML (RoleController,
     * UserController, MenuController, IngredientController, OrderAdminController,
     * ProductController) et par `resolve()` ci-dessus (API JSON) : les 7 endroits
     * qui tracaient auparavant `(email tenté: ...)` en clair passent tous par ici.
     *
     * Pourquoi ce changement : `summary` est un texte libre. Il echappe a la
     * retention (AUDIT_LOG_RETENTION_DAYS, 365 jours par defaut) comme a
     * l'effacement RGPD d'un compte (`UserRepository::anonymise()` ne touche QUE
     * la ligne `user`, jamais les lignes `audit_log` deja ecrites) : une adresse
     * tapee -- par erreur, ou lors d'une tentative de brute-force -- y survivait
     * donc indefiniment, y compris pour un email qui ne correspond a AUCUN
     * compte. On ne garde plus que ce qui sert reellement au signal de securite
     * (le CONTEXTE de l'action, et l'identifiant STABLE du compte quand l'adresse
     * en designe un) : `entity_id`/l'id d'un compte existant ne sont pas des
     * donnees personnelles portees par CETTE ligne (ce sont des cles etrangeres
     * logiques vers des lignes qui, elles, restent soumises a l'effacement/
     * l'anonymisation independamment) -- jamais l'adresse elle-meme.
     */
    public static function auditFailedPin(
        DatabaseInterface $db,
        string $email,
        string $entityType,
        ?int $entityId,
        string $context,
    ): void {
        $targetUserId = self::lookupExistingUserId($db, $email);
        $summary = 'Échec PIN ' . $context . ($targetUserId === null ? ' (adresse inconnue)' : '');

        $db->execute(
            'INSERT INTO audit_log (actor_user_id, actor_role_id, action_code, entity_type, entity_id, summary, details) '
            . 'VALUES (:uid, :rid, :code, :etype, :eid, :summary, :details)',
            [
                'uid'     => null,
                'rid'     => null,
                'code'    => 'pin.failed',
                'etype'   => $entityType,
                'eid'     => $entityId,
                'summary' => $summary,
                'details' => (string) json_encode(['target_user_id' => $targetUserId, 'context' => $context]),
            ],
        );
    }

    /**
     * Identifiant du compte (actif ou non -- on n'authentifie personne ici, on
     * identifie une CIBLE pour l'audit) dont l'adresse email correspond
     * exactement a celle saisie, ou null si aucun compte ne correspond
     * ("adresse inconnue"). Projection `id AS target_user_id` volontairement
     * distincte de `UserRepository::emailExists()` (`AND id <> :id`, verifie une
     * unicite) et de `PasswordResetService` (`AND is_active = 1`, authentifie) :
     * cette recherche-ci sert uniquement a documenter la ligne d'audit.
     */
    private static function lookupExistingUserId(DatabaseInterface $db, string $email): ?int
    {
        if ($email === '') {
            return null;
        }

        $row = $db->fetch('SELECT id AS target_user_id FROM user WHERE email = :email LIMIT 1', ['email' => $email]);

        return $row !== null ? (int) ($row['target_user_id'] ?? 0) : null;
    }

    /**
     * PIN valide + effet reussi : remet a zero le compteur de l'utilisateur
     * AGISSANT (RG-T22). Cle = l'acteur de SESSION (celui qui a soumis la
     * requete), jamais l'equipier resolu par le PIN.
     */
    public function reset(int $actorSessionUserId): void
    {
        $this->throttle->reset($actorSessionUserId);
    }

    /**
     * Ligne d'audit de l'effet reussi, dans la meme transaction que l'ecriture
     * metier (RG-T08/RG-T14). Meme forme exacte que les `writeAudit()` prives des
     * controleurs HTML (colonne `details` en JSON, nullable).
     *
     * @param array<string, mixed>|null $details
     */
    public function writeAudit(
        DatabaseInterface $db,
        string $action,
        int $userId,
        int $roleId,
        string $entityType,
        ?int $entityId,
        string $summary,
        ?array $details = null,
    ): void {
        $db->execute(
            'INSERT INTO audit_log (actor_user_id, actor_role_id, action_code, entity_type, entity_id, summary, details) '
            . 'VALUES (:uid, :rid, :code, :etype, :eid, :summary, :details)',
            [
                'uid'     => $userId,
                'rid'     => $roleId,
                'code'    => $action,
                'etype'   => $entityType,
                'eid'     => $entityId,
                'summary' => $summary,
                'details' => $details !== null ? (string) json_encode($details) : null,
            ],
        );
    }
}
