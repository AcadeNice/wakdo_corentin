<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Core\DatabaseInterface;

/**
 * Throttle du PIN d'action sensible (mlt.md RG-T22, complement de RG-T13).
 *
 * Les echecs de PIN sont comptabilises PAR UTILISATEUR AGISSANT (l'identite de
 * session authentifiee qui soumet email+PIN, RG-T02), dans la table dediee
 * `pin_throttle`, STRICTEMENT SEPAREE des compteurs de connexion
 * (user.failed_login_attempts / login_throttle) : un echec de PIN ne doit jamais
 * incrementer un compteur de login, sinon spammer le PIN d'une victime
 * verrouillerait sa CONNEXION (escalade de DoS sur une surface plus sensible).
 *
 * La dimension est l'AGISSANT et non l'email cible (un compteur par email cible
 * serait contourne par rotation d'emails, RG-T13 verifiant un email arbitraire)
 * ni l'IP (un verrou par IP priverait de re-autorisation tous les equipiers
 * honnetes d'un poste a session partagee). Le verrou est un backoff degressif
 * (ThrottlePolicy dimension 'pin'), pas un blocage definitif.
 *
 * Reutilise la forme exacte de l'upsert IP atomique d'AuthService (increment cote
 * SQL sous verrou de ligne, fenetre glissante reinitialisee en SQL). N'ecrit
 * AUCUNE ligne audit_log (le controleur ecrit deja la ligne pin.failed). $now est
 * injecte pour des tests deterministes.
 *
 * `final` : pas de seam de sous-classe necessaire (la Database est injectee, donc
 * substituable par un double en test).
 */
final class PinThrottle
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly Config $config,
    ) {
    }

    /**
     * Vrai si l'utilisateur agissant est actuellement verrouille. A evaluer AVANT
     * la verification du PIN (gate-before-verify, comme AuthService). actorUserId
     * <= 0 (pas de session, ne devrait pas arriver derriere guard()) => non
     * verrouille (defensif).
     */
    public function isLocked(int $actorUserId, ?int $now = null): bool
    {
        if ($actorUserId <= 0) {
            return false;
        }

        $now ??= time();

        $row = $this->db->fetch(
            'SELECT lockout_until FROM pin_throttle WHERE actor_user_id = :uid',
            ['uid' => $actorUserId],
        );

        $lockoutUntil = is_string($row['lockout_until'] ?? null) ? (string) $row['lockout_until'] : null;

        return ThrottlePolicy::fromConfig($this->config, 'pin')->isLockedUntil($lockoutUntil, $now);
    }

    /**
     * Enregistre un echec de PIN pour l'utilisateur agissant, en une transaction
     * (RG-T08) : upsert atomique du compteur (fenetre glissante reinitialisee en SQL
     * si expiree, verrou de ligne anti lost-update) puis pose du verrou degressif.
     * Ne touche JAMAIS user ni login_throttle (RG-T22) et n'ecrit pas d'audit_log.
     */
    public function recordFailure(int $actorUserId, ?int $now = null): void
    {
        if ($actorUserId <= 0) {
            return;
        }

        // Variante autonome : ouvre sa propre transaction. Le controleur, lui,
        // prefere recordFailureWithin() pour ecrire la trace pin.failed et cet
        // increment dans UNE SEULE transaction (RG-T08).
        $this->db->transaction(function (DatabaseInterface $db) use ($actorUserId, $now): void {
            $this->recordFailureWithin($db, $actorUserId, $now);
        });
    }

    /**
     * Variante SANS transaction propre : suppose que l'appelant a deja ouvert une
     * transaction (le controleur enveloppe la trace audit pin.failed (RG-T14) et
     * cet increment dans la meme, RG-T08 : pas d'etat partiel si crash entre les
     * deux). Memes effets que recordFailure : upsert atomique sous verrou de ligne,
     * fenetre glissante reinitialisee en SQL, backoff degressif. Ne touche jamais
     * user ni login_throttle (RG-T22).
     */
    public function recordFailureWithin(DatabaseInterface $db, int $actorUserId, ?int $now = null): void
    {
        if ($actorUserId <= 0) {
            return;
        }

        $now ??= time();
        $nowDt = date('Y-m-d H:i:s', $now);
        $windowSeconds = $this->config->int('PIN_THROTTLE_WINDOW_SECONDS', 900);
        $windowCutoff = date('Y-m-d H:i:s', $now - $windowSeconds);
        $policy = ThrottlePolicy::fromConfig($this->config, 'pin');

        // Increment ATOMIQUE cote SQL sous le verrou de ligne pris par l'upsert
        // (anti lost-update sous POSTs concurrents). Placeholders distincts : en
        // prepare reelle (EMULATE_PREPARES = false) un meme nom ne peut etre lie
        // qu'une fois. Meme forme que AuthService (dimension IP).
        $db->execute(
            'INSERT INTO pin_throttle (actor_user_id, failed_attempts, window_started_at, last_attempt_at) '
            . 'VALUES (:uid, 1, :now_i, :now_li) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'failed_attempts = IF(window_started_at < :cutoff, 1, failed_attempts + 1), '
            . 'window_started_at = IF(window_started_at < :cutoff2, :now_w, window_started_at), '
            . 'last_attempt_at = :now_lu',
            [
                'uid' => $actorUserId,
                'now_i' => $nowDt,
                'now_li' => $nowDt,
                'cutoff' => $windowCutoff,
                'cutoff2' => $windowCutoff,
                'now_w' => $nowDt,
                'now_lu' => $nowDt,
            ],
        );

        // Relit le compteur autoritaire (ligne deja verrouillee par cette tx)
        // pour calculer le backoff en PHP, puis pose le verrou.
        $row = $db->fetch('SELECT failed_attempts FROM pin_throttle WHERE actor_user_id = :uid', ['uid' => $actorUserId]);
        $attempts = (int) ($row['failed_attempts'] ?? 1);
        $lockSeconds = $policy->lockoutSeconds($attempts);
        $lockUntil = $lockSeconds > 0 ? date('Y-m-d H:i:s', $now + $lockSeconds) : null;

        $db->execute(
            'UPDATE pin_throttle SET lockout_until = :lock WHERE actor_user_id = :uid',
            ['lock' => $lockUntil, 'uid' => $actorUserId],
        );
    }

    /**
     * PIN valide : remet a zero le compteur de l'utilisateur agissant (un manager
     * qui s'est trompe puis a reussi n'est pas penalise plus tard). UPDATE simple
     * (0 ligne si aucune n'existait, benin), SANS transaction propre : le controleur
     * l'appelle apres l'effet reussi, sur sa propre connexion.
     */
    public function reset(int $actorUserId, ?int $now = null): void
    {
        if ($actorUserId <= 0) {
            return;
        }

        $now ??= time();
        $nowDt = date('Y-m-d H:i:s', $now);

        $this->db->execute(
            'UPDATE pin_throttle SET failed_attempts = 0, lockout_until = NULL, '
            . 'window_started_at = :now_w, last_attempt_at = :now_l WHERE actor_user_id = :uid',
            ['now_w' => $nowDt, 'now_l' => $nowDt, 'uid' => $actorUserId],
        );
    }
}
