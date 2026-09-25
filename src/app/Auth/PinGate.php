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
                $db->execute(
                    'INSERT INTO audit_log (actor_user_id, actor_role_id, action_code, entity_type, entity_id, summary) '
                    . 'VALUES (:uid, :rid, :code, :etype, :eid, :summary)',
                    [
                        'uid'     => null,
                        'rid'     => null,
                        'code'    => 'pin.failed',
                        'etype'   => $entityType,
                        'eid'     => $normalizedEntityId,
                        'summary' => 'Échec PIN action sensible (email tenté: ' . $email . ')',
                    ],
                );
                $this->throttle->recordFailureWithin($db, $actorSessionUserId);
            });

            return null;
        }

        return $actor;
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
