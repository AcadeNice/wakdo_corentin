<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Csrf;
use App\Auth\GuardResult;
use App\Auth\PasswordHasher;
use App\Auth\PinThrottle;
use App\Auth\PinVerifier;
use App\Auth\UserRepository;
use App\Core\DatabaseInterface;
use App\Core\Response;

/**
 * Profil self-service : definition / changement du PIN d'action sensible de
 * l'utilisateur connecte (prerequis au modele "identifiant equipier + PIN" des
 * actions sensibles, RG-T13). Accessible a tout utilisateur authentifie ; aucune
 * permission specifique (on n'agit que sur son propre compte = session userId).
 *
 * Le PIN est un credential sensible : le (re)definir exige le mot de passe COURANT
 * (re-verification d'identite sur poste a session partagee, meme posture que la
 * verification PIN d'ADR-0004) ET ecrit une ligne `audit_log` (ADR-0004, RG-T14).
 *
 * Corrige au contre-audit du 30/09 (D-1) : cette RE-VERIFICATION est desormais
 * throttled -- avant ce correctif, elle pouvait etre tentee sans aucune limite
 * ni trace, ce qui laissait une personne devant une session ouverte (le poste
 * partage que le modele de menace decrit) essayer des mots de passe sans armer
 * le verrou de connexion. Reutilise PinThrottle (RG-T22, ADR-0005) avec
 * l'UTILISATEUR DE SESSION comme cle -- la meme table/politique que le throttle
 * du PIN d'action sensible, plutot qu'une dimension dediee : c'est la MEME
 * identite qui echoue une verification d'identite, que ce soit ici (mot de
 * passe) ou ailleurs (email+PIN) ; conflater les deux ne relache rien (un
 * attaquant qui epuiserait l'un epuise aussi l'autre) et evite une septieme
 * table pour un seul champ (Rasoir d'Ockham). SET du PIN lui-meme toujours pas
 * throttle (seul l'ACCES au formulaire l'est desormais) : un utilisateur qui
 * vient de passer la re-verification peut enregistrer son PIN sans limite
 * artificielle. L'audit ne porte que l'evenement (set vs change vs echec de
 * re-verification), jamais le PIN, un hash, ni le mot de passe saisi.
 *
 * Non `final` : les tests sous-classent pour injecter des doubles.
 */
class ProfileController extends AdminController
{
    // Message clair pour un equipier non technique, sans reveler si le mot de
    // passe soumis etait correct (D-1).
    private const REAUTH_LOCKED_MESSAGE = 'Trop de tentatives. Réessayez dans quelques minutes.';

    /**
     * @param array<string, string> $params
     */
    public function showPin(array $params = []): Response
    {
        $guard = $this->guard();
        if ($guard instanceof Response) {
            return $guard;
        }

        $userId = $guard->userId;
        if ($userId === null) {
            return Response::make('', 302, ['Location' => '/login']);
        }

        return $this->adminView('admin/profile/pin', [
            'title'     => 'Mon PIN - Wakdo Admin',
            'activeNav' => '',
            'pinIsSet'  => $this->userRepository()->pinIsSet($userId),
            'error'     => null,
        ] + $this->pinLengthPolicy(), $guard);
    }

    /**
     * @param array<string, string> $params
     */
    public function updatePin(array $params = []): Response
    {
        $guard = $this->guard();
        if ($guard instanceof Response) {
            return $guard;
        }

        $userId = $guard->userId;
        if ($userId === null) {
            return Response::make('', 302, ['Location' => '/login']);
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return Response::make('Requête invalide.', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $pin = $form['pin'] ?? '';
        $confirm = $form['pin_confirm'] ?? '';
        $currentPassword = $form['current_password'] ?? '';
        $error = null;

        if (!$this->pinVerifier()->meetsLengthPolicy($pin)) {
            $error = 'Le PIN doit être uniquement numérique et respecter la longueur requise.';
        } elseif ($pin !== $confirm) {
            $error = 'Les PIN ne correspondent pas.';
        }

        if ($error !== null) {
            return $this->renderPinForm($guard, $userId, $error, 422);
        }

        // D-1 (contre-audit 30/09) : verrou evalue AVANT toute verification (gate-
        // before-verify, meme posture que le login et que PinGate::resolve()). Un
        // compte deja verrouille ne paie MEME PAS le cout argon2id : a la difference
        // du login, il n'y a ici qu'une seule identite possible (celle de la
        // session) -- rien a rendre indiscernable d'un "email inconnu" -- donc payer
        // ce cout n'apporterait aucune garantie de plus, juste une charge CPU
        // gratuite pendant que le verrou est actif. Message generique, ne revele pas
        // si le mot de passe fourni etait bon.
        if ($this->pinThrottle()->isLocked($userId)) {
            return $this->renderPinForm($guard, $userId, self::REAUTH_LOCKED_MESSAGE, 422);
        }

        // Re-verification d'identite : (re)definir un credential sensible exige le mot
        // de passe courant. Message generique (ne distingue pas mot de passe vide /
        // faux) ; verify paie le cout argon2id, sans leurre dedie ici car l'utilisateur
        // est deja authentifie (l'enumeration de comptes ne s'applique pas a sa propre
        // session). Echec -> 422 (requete bien formee, semantiquement refusee) + trace
        // d'audit (`auth.reauth_failed`, jamais la valeur saisie) et increment du
        // throttle, dans UNE transaction (RG-T08).
        if (!$this->passwordHasher()->verify($currentPassword, $this->currentPasswordHash($userId))) {
            $this->recordReauthFailure($userId, $guard->roleId ?? 0);

            return $this->renderPinForm($guard, $userId, 'Mot de passe actuel incorrect.', 422);
        }

        // Succes de la re-verification : remet a zero le compteur de l'utilisateur
        // de session (un equipier qui s'est trompe puis a reussi n'est pas penalise
        // plus tard, meme regle que PinGate::reset()).
        $this->pinThrottle()->reset($userId);

        // `pinIsSet` AVANT l'ecriture : distingue une premiere definition d'un changement
        // pour le libelle d'audit (aucune valeur sensible n'est tracee).
        $wasSet = $this->userRepository()->pinIsSet($userId);

        // Gate sur 1 ligne affectee : une cible inexistante (0 ligne) ne doit pas
        // produire un faux "PIN enregistre" (defense en profondeur).
        if ($this->userRepository()->setPinHash($userId, $this->passwordHasher()->hash($pin)) !== 1) {
            return $this->renderPinForm($guard, $userId, 'Échec de l\'enregistrement du PIN.', 500);
        }

        // Trace d'audit (ADR-0004, RG-T14) : l'acteur est l'utilisateur de session
        // (action self-service, pas de PIN equipier tiers). Le summary ne porte que
        // l'evenement set/change, jamais le PIN ni un hash.
        $this->writePinAudit($userId, $guard->roleId ?? 0, $wasSet);

        $this->setFlash('PIN enregistré.');

        return Response::make('', 302, ['Location' => '/admin/profile/pin']);
    }

    private function renderPinForm(GuardResult $guard, int $userId, ?string $error, int $status): Response
    {
        return $this->adminView('admin/profile/pin', [
            'title'     => 'Mon PIN - Wakdo Admin',
            'activeNav' => '',
            'pinIsSet'  => $this->userRepository()->pinIsSet($userId),
            'error'     => $error,
        ] + $this->pinLengthPolicy(), $guard, $status);
    }

    /**
     * Bornes du PIN pour le controle pendant la saisie (Cr 2.b.1), lues au meme endroit
     * que la regle serveur (PinVerifier::meetsLengthPolicy).
     *
     * @return array{pinMinLength: int, pinMaxLength: int}
     */
    private function pinLengthPolicy(): array
    {
        return [
            'pinMinLength' => $this->pinVerifier()->minLength(),
            'pinMaxLength' => $this->pinVerifier()->maxLength(),
        ];
    }

    protected function userRepository(): UserRepository
    {
        return new UserRepository($this->database);
    }

    protected function pinVerifier(): PinVerifier
    {
        return new PinVerifier($this->database, $this->config, $this->passwordHasher());
    }

    /**
     * Throttle de la re-verification d'identite (D-1), cle = utilisateur de
     * SESSION. Passe par db() (pas $this->database) : c'est la meme couture que
     * currentPasswordHash()/writePinAudit(), celle que les tests routent vers le
     * double.
     */
    protected function pinThrottle(): PinThrottle
    {
        return new PinThrottle($this->db(), $this->config);
    }

    protected function passwordHasher(): PasswordHasher
    {
        return new PasswordHasher($this->config);
    }

    /**
     * D-1 : echec de re-verification du mot de passe courant. Increment du
     * throttle (pin_throttle, cle = utilisateur de session) et trace d'audit
     * `auth.reauth_failed`, dans UNE seule transaction (RG-T08 : pas d'etat
     * partiel si la base tombe entre les deux). Aucune valeur saisie n'est
     * journalisee -- seul l'identifiant du compte agissant, deja connu de la
     * session (RGPD art. 5.1.c, meme discipline que PinGate::auditFailedPin()).
     */
    private function recordReauthFailure(int $userId, int $roleId): void
    {
        $this->db()->transaction(function (DatabaseInterface $db) use ($userId, $roleId): void {
            $this->pinThrottle()->recordFailureWithin($db, $userId);

            $db->execute(
                'INSERT INTO audit_log (actor_user_id, actor_role_id, action_code, entity_type, entity_id, summary) '
                . 'VALUES (:uid, :rid, :code, :etype, :eid, :summary)',
                [
                    'uid'     => $userId,
                    'rid'     => $roleId,
                    'code'    => 'auth.reauth_failed',
                    'etype'   => 'user',
                    'eid'     => $userId,
                    'summary' => 'Échec de re-authentification (PIN self-service)',
                ],
            );
        });
    }

    /**
     * Hash du mot de passe courant de l'utilisateur de session, pour la
     * re-verification d'identite. Lecture ciblee d'une colonne (UserRepository
     * n'expose pas le hash : son allowlist d'ecriture ne le lie jamais) ; un compte
     * absent/inactif renvoie une chaine vide -> verify echoue (refus generique).
     * is_active = 1 : un compte desactive ne peut pas (re)definir son PIN.
     */
    protected function currentPasswordHash(int $userId): string
    {
        $row = $this->db()->fetch(
            'SELECT password_hash FROM user WHERE id = :id AND is_active = 1',
            ['id' => $userId],
        );

        return is_string($row['password_hash'] ?? null) ? (string) $row['password_hash'] : '';
    }

    /**
     * Ecrit la trace d'audit du set/change de PIN (ADR-0004, RG-T14). action_code
     * `pin.set` pour les deux cas (definition ET changement) ; le summary distingue
     * via $wasSet. entity = l'utilisateur agissant (self-service). Aucune valeur
     * sensible (PIN, hash) n'est journalisee. Hors transaction : l'ecriture du PIN est
     * un seul UPDATE deja committe ; l'audit suit immediatement (pas d'effet composite
     * a rendre atomique, a la difference de l'annulation OrderRepository::cancel).
     */
    protected function writePinAudit(int $userId, int $roleId, bool $wasSet): void
    {
        $this->db()->execute(
            'INSERT INTO audit_log (actor_user_id, actor_role_id, action_code, entity_type, entity_id, summary) '
            . 'VALUES (:uid, :rid, :code, :etype, :eid, :summary)',
            [
                'uid'     => $userId,
                'rid'     => $roleId,
                'code'    => 'pin.set',
                'etype'   => 'user',
                'eid'     => $userId,
                'summary' => $wasSet ? 'PIN modifié (self-service)' : 'PIN défini (self-service)',
            ],
        );
    }
}
