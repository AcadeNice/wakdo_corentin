<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Csrf;
use App\Auth\GuardResult;
use App\Auth\PasswordHasher;
use App\Auth\PinVerifier;
use App\Auth\UserRepository;
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
 * Le SET du PIN n'est PAS throttle : la surface de brute-force est la VERIFICATION
 * du PIN (couverte par pin_throttle / RG-T22, ADR-0005), pas sa definition par un
 * utilisateur deja authentifie. L'audit ne porte que l'evenement (set vs change),
 * jamais le PIN ni un hash.
 *
 * Non `final` : les tests sous-classent pour injecter des doubles.
 */
class ProfileController extends AdminController
{
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
        ], $guard);
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
            return Response::make('Requete invalide.', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $pin = $form['pin'] ?? '';
        $confirm = $form['pin_confirm'] ?? '';
        $currentPassword = $form['current_password'] ?? '';
        $error = null;

        if (!$this->pinVerifier()->meetsLengthPolicy($pin)) {
            $error = 'Le PIN doit etre uniquement numerique et respecter la longueur requise.';
        } elseif ($pin !== $confirm) {
            $error = 'Les PIN ne correspondent pas.';
        }

        if ($error !== null) {
            return $this->renderPinForm($guard, $userId, $error, 422);
        }

        // Re-verification d'identite : (re)definir un credential sensible exige le mot
        // de passe courant. Message generique (ne distingue pas mot de passe vide /
        // faux) ; verify paie le cout argon2id, sans leurre dedie ici car l'utilisateur
        // est deja authentifie (l'enumeration de comptes ne s'applique pas a sa propre
        // session). Echec -> 422 (requete bien formee, semantiquement refusee).
        if (!$this->passwordHasher()->verify($currentPassword, $this->currentPasswordHash($userId))) {
            return $this->renderPinForm($guard, $userId, 'Mot de passe actuel incorrect.', 422);
        }

        // `pinIsSet` AVANT l'ecriture : distingue une premiere definition d'un changement
        // pour le libelle d'audit (aucune valeur sensible n'est tracee).
        $wasSet = $this->userRepository()->pinIsSet($userId);

        // Gate sur 1 ligne affectee : une cible inexistante (0 ligne) ne doit pas
        // produire un faux "PIN enregistre" (defense en profondeur).
        if ($this->userRepository()->setPinHash($userId, $this->passwordHasher()->hash($pin)) !== 1) {
            return $this->renderPinForm($guard, $userId, 'Echec de l enregistrement du PIN.', 500);
        }

        // Trace d'audit (ADR-0004, RG-T14) : l'acteur est l'utilisateur de session
        // (action self-service, pas de PIN equipier tiers). Le summary ne porte que
        // l'evenement set/change, jamais le PIN ni un hash.
        $this->writePinAudit($userId, $guard->roleId ?? 0, $wasSet);

        $this->setFlash('PIN enregistre.');

        return Response::make('', 302, ['Location' => '/admin/profile/pin']);
    }

    private function renderPinForm(GuardResult $guard, int $userId, ?string $error, int $status): Response
    {
        return $this->adminView('admin/profile/pin', [
            'title'     => 'Mon PIN - Wakdo Admin',
            'activeNav' => '',
            'pinIsSet'  => $this->userRepository()->pinIsSet($userId),
            'error'     => $error,
        ], $guard, $status);
    }

    protected function userRepository(): UserRepository
    {
        return new UserRepository($this->database);
    }

    protected function pinVerifier(): PinVerifier
    {
        return new PinVerifier($this->database, $this->config, $this->passwordHasher());
    }

    protected function passwordHasher(): PasswordHasher
    {
        return new PasswordHasher($this->config);
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
                'summary' => $wasSet ? 'PIN modifie (self-service)' : 'PIN defini (self-service)',
            ],
        );
    }
}
