<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Core\DatabaseInterface;

/**
 * Verrou de la dimension COMPTE de RG-8 (`user.failed_login_attempts` /
 * `lockout_until`), extrait pour etre reutilise HORS du flux AUTHENTICATE_USER
 * (D-1.a, contre-audit 30/09, revue adverse).
 *
 * Avant ce correctif, `ProfileController::updatePin()` comptait l'echec de
 * re-verification du mot de passe courant sur `pin_throttle` (RG-T22, cle =
 * utilisateur de SESSION) -- la MEME table que le throttle du PIN d'action
 * sensible. Or TOUTE action PIN reussie remet ce compteur a zero pour
 * l'utilisateur de session (`PinGate::reset()`, `PinThrottle::reset()`),
 * QUEL QUE SOIT l'equipier dont l'email+PIN a ete verifie
 * (`PinVerifier::resolveActingUser()` accepte tout compte actif) : sur le
 * poste partage du modele de menace, un collegue pouvait donc alterner des
 * echecs de mot de passe et des actions PIN anodines avec SES PROPRES
 * identifiants pour ne jamais laisser le compteur de re-verification
 * atteindre le seuil.
 *
 * Correction retenue (la plus simple) : la re-verification du mot de passe
 * porte sur le MEME secret que la connexion (le mot de passe du compte) --
 * elle merite donc le MEME budget, `user.failed_login_attempts`/
 * `lockout_until`, jamais celui du PIN.
 *
 * Extraite pour que le motif SQL (increment atomique, relecture SOUS LE
 * VERROU DE LIGNE pris par cet UPDATE, dans la MEME transaction que
 * l'appelant) n'existe QU'A UN SEUL ENDROIT : `AuthService::recordFailure()`
 * (dimension compte de AUTHENTICATE_USER, RG-8) et
 * `ProfileController::recordReauthFailure()` (D-1.a) appellent tous deux
 * `recordFailureWithin()`, chacun DANS SA PROPRE transaction (elle n'ouvre
 * jamais la sienne dans cette variante -- c'est `recordFailure()`, la seule a
 * le faire, qui sert les appelants qui n'ont pas deja de transaction ouverte).
 *
 * Consequence assumee : des echecs repetes de re-verification sur
 * `/admin/profile/pin` comptent desormais AUSSI vers le verrou de CONNEXION
 * du compte (et reciproquement) -- coherent, puisque les deux essaient le
 * MEME mot de passe.
 */
final class AccountLockout
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly Config $config,
    ) {
    }

    /**
     * Vrai si le compte est actuellement verrouille. A evaluer AVANT toute
     * verification de mot de passe (gate-before-verify, RG-8 PRE-3).
     */
    public function isLocked(int $userId, ?int $now = null): bool
    {
        $now ??= time();

        $row = $this->db->fetch('SELECT lockout_until FROM user WHERE id = :id', ['id' => $userId]);
        $lockoutUntil = is_string($row['lockout_until'] ?? null) ? (string) $row['lockout_until'] : null;

        return ThrottlePolicy::fromConfig($this->config, 'account')->isLockedUntil($lockoutUntil, $now);
    }

    /**
     * Enregistre un echec, dans sa PROPRE transaction. Ne trace RIEN dans
     * audit_log : l'appelant ecrit sa propre ligne (ex. `auth.reauth_failed`)
     * dans la transaction de son choix -- voir recordFailureWithin() pour
     * combiner les deux dans UNE seule transaction (RG-T08).
     */
    public function recordFailure(int $userId, ?int $now = null): void
    {
        $this->db->transaction(function (DatabaseInterface $db) use ($userId, $now): void {
            $this->recordFailureWithin($db, $userId, $now);
        });
    }

    /**
     * Variante SANS transaction propre : l'appelant a deja ouvert la sienne
     * (ex. ProfileController::recordReauthFailure(), qui y ecrit aussi la
     * trace d'audit dans la MEME transaction, RG-T08). Increment ATOMIQUE cote
     * SQL (`failed_login_attempts = failed_login_attempts + 1`), puis
     * relecture SOUS LE VERROU DE LIGNE pris par cet UPDATE (persiste jusqu'au
     * commit de cette transaction) pour calculer le backoff -- meme garantie
     * que `AuthService::recordFailure()` pour cette dimension (D-3).
     */
    public function recordFailureWithin(DatabaseInterface $db, int $userId, ?int $now = null): void
    {
        $now ??= time();
        $nowDt = date('Y-m-d H:i:s', $now);
        $policy = ThrottlePolicy::fromConfig($this->config, 'account');

        $db->execute(
            'UPDATE user SET failed_login_attempts = failed_login_attempts + 1, last_failed_login_at = :now '
            . 'WHERE id = :id',
            ['now' => $nowDt, 'id' => $userId],
        );

        $row = $db->fetch('SELECT failed_login_attempts FROM user WHERE id = :id', ['id' => $userId]);
        $attempts = (int) ($row['failed_login_attempts'] ?? 1);
        $lockSeconds = $policy->lockoutSeconds($attempts);
        $lockUntil = $lockSeconds > 0 ? date('Y-m-d H:i:s', $now + $lockSeconds) : null;

        $db->execute(
            'UPDATE user SET lockout_until = :lock WHERE id = :id',
            ['lock' => $lockUntil, 'id' => $userId],
        );
    }

    /**
     * Succes : remet a zero le compteur du compte. N'efface PAS
     * `last_failed_login_at` (simple historique, pas une donnee de securite) ;
     * n'ecrit rien dans `login_throttle` (dimension IP, hors de propos ici).
     */
    public function reset(int $userId): void
    {
        $this->db->execute(
            'UPDATE user SET failed_login_attempts = 0, lockout_until = NULL WHERE id = :id',
            ['id' => $userId],
        );
    }
}
