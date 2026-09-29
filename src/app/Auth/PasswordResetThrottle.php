<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Core\DatabaseInterface;

/**
 * Throttle de la demande de reinitialisation de mot de passe (POST
 * /forgot_password, App\Controllers\PasswordResetController::submitRequest()).
 * Defaut releve : aucune limite -- 30 demandes consecutives pour la meme
 * adresse passaient toutes ; SMTP configure, chacune envoie un e-mail
 * (inondation de la boite du compte vise, en plus de remplacer le lien
 * precedent).
 *
 * DEUX dimensions ORTHOGONALES, l'une ET l'autre (pas l'une ou l'autre) :
 * l'ADRESSE demandee (freine un attaquant qui vise un compte precis depuis de
 * nombreuses IP) et l'IP source (freine un attaquant qui balaie de nombreuses
 * adresses depuis la meme IP), toutes deux dans la table `password_reset_
 * throttle` (migration 0020), distinguees par `throttle_kind` -- meme motif que
 * `role_visible_source` (discriminant + identifiant generique) plutot que deux
 * tables, puisque la forme des deux dimensions est identique.
 *
 * ANTI-ENUMERATION (RG-2 de RESET_PASSWORD, mlt.md 12.3) : l'adresse est
 * throttlee qu'elle resolve ou non vers un compte -- PasswordResetService fait
 * deja ce travail cote email-inconnu (payEnumerationDecoy()) ; ce throttle
 * applique le MEME principe a sa propre dimension. Le controleur appelant DOIT
 * renvoyer la reponse neutre identique (meme statut, meme corps) que l'adresse
 * existe, n'existe pas, ou que la limite soit atteinte : voir
 * PasswordResetController::submitRequest().
 *
 * Reutilise la forme exacte de l'upsert atomique d'AuthService/PinThrottle
 * (increment cote SQL sous verrou de ligne, fenetre glissante reinitialisee en
 * SQL). $now est injecte pour des tests deterministes.
 */
final class PasswordResetThrottle
{
    private const KIND_EMAIL = 'email';
    private const KIND_IP = 'ip';

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly Config $config,
    ) {
    }

    /**
     * Vrai si l'ADRESSE demandee OU l'IP source est actuellement verrouillee.
     * A evaluer AVANT tout travail (recherche email, generation de token,
     * envoi de mail) : gate-before-work, comme AuthService PRE-3.
     */
    public function isBlocked(string $email, string $ip, ?int $now = null): bool
    {
        $now ??= time();

        return $this->isLocked(self::KIND_EMAIL, $this->normalizeEmail($email), 'password_reset_email', $now)
            || $this->isLocked(self::KIND_IP, $ip, 'password_reset_ip', $now);
    }

    /**
     * Enregistre une demande pour les DEUX dimensions, en une transaction
     * (RG-T08). A appeler pour CHAQUE soumission dont le CSRF est valide et qui
     * n'est PAS deja bloquee (isBlocked()) -- que l'adresse existe ou non, le
     * travail et l'effet de bord doivent etre IDENTIQUES (anti-enumeration).
     */
    public function recordAttempt(string $email, string $ip, ?int $now = null): void
    {
        $now ??= time();

        $this->db->transaction(function (DatabaseInterface $db) use ($email, $ip, $now): void {
            $this->increment($db, self::KIND_EMAIL, $this->normalizeEmail($email), 'password_reset_email', $now);
            $this->increment($db, self::KIND_IP, $ip, 'password_reset_ip', $now);
        });
    }

    private function isLocked(string $kind, string $identifier, string $policyDimension, int $now): bool
    {
        $row = $this->db->fetch(
            'SELECT lockout_until FROM password_reset_throttle WHERE throttle_kind = :kind AND identifier = :id',
            ['kind' => $kind, 'id' => $identifier],
        );

        $lockoutUntil = is_string($row['lockout_until'] ?? null) ? (string) $row['lockout_until'] : null;

        return ThrottlePolicy::fromConfig($this->config, $policyDimension)->isLockedUntil($lockoutUntil, $now);
    }

    private function increment(DatabaseInterface $db, string $kind, string $identifier, string $policyDimension, int $now): void
    {
        $nowDt = date('Y-m-d H:i:s', $now);
        $windowSeconds = $this->config->int('PASSWORD_RESET_THROTTLE_WINDOW_SECONDS', 3600);
        $windowCutoff = date('Y-m-d H:i:s', $now - $windowSeconds);
        $policy = ThrottlePolicy::fromConfig($this->config, $policyDimension);

        // Increment ATOMIQUE cote SQL sous le verrou de ligne pris par l'upsert
        // (anti lost-update sous POSTs concurrents). Placeholders distincts : en
        // prepare reelle (EMULATE_PREPARES = false) un meme nom ne peut etre lie
        // qu'une fois. Meme forme que AuthService (dimension IP) / PinThrottle.
        $db->execute(
            'INSERT INTO password_reset_throttle (throttle_kind, identifier, failed_attempts, window_started_at, last_attempt_at) '
            . 'VALUES (:kind, :id, 1, :now_i, :now_li) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'failed_attempts = IF(window_started_at < :cutoff, 1, failed_attempts + 1), '
            . 'window_started_at = IF(window_started_at < :cutoff2, :now_w, window_started_at), '
            . 'last_attempt_at = :now_lu',
            [
                'kind' => $kind,
                'id' => $identifier,
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
        $row = $db->fetch(
            'SELECT failed_attempts FROM password_reset_throttle WHERE throttle_kind = :kind AND identifier = :id',
            ['kind' => $kind, 'id' => $identifier],
        );
        $attempts = (int) ($row['failed_attempts'] ?? 1);
        $lockSeconds = $policy->lockoutSeconds($attempts);
        $lockUntil = $lockSeconds > 0 ? date('Y-m-d H:i:s', $now + $lockSeconds) : null;

        $db->execute(
            'UPDATE password_reset_throttle SET lockout_until = :lock WHERE throttle_kind = :kind AND identifier = :id',
            ['lock' => $lockUntil, 'kind' => $kind, 'id' => $identifier],
        );
    }

    /**
     * Casse insensible (RFC 5321 en pratique) et espaces de bord retires, pour
     * que "Manager@Wakdo.Local" et "manager@wakdo.local " partagent le meme
     * compteur -- sinon la casse deviendrait un moyen trivial de contourner le
     * seuil par adresse.
     */
    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
