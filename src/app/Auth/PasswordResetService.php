<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Core\DatabaseInterface;

/**
 * Reinitialisation de mot de passe (mlt.md 12.3), en deux phases : demande puis
 * confirmation. Sans fuite d'enumeration (reponse neutre), token CSPRNG hashe au
 * repos, usage unique, confirmation transactionnelle.
 */
final class PasswordResetService
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly Config $config,
        private readonly PasswordHasher $hasher,
        private readonly Mailer $mailer,
    ) {
    }

    /**
     * Phase demande (RG-1/RG-2). Retour void : la reponse cote controleur est
     * neutre que l'email existe ou non (anti-enumeration). Si l'email resout un
     * utilisateur actif : token CSPRNG 32 octets, on stocke son hash SHA-256 et
     * une expiration NOW()+TTL, et on envoie le token BRUT une seule fois.
     */
    public function requestReset(string $email, string $baseUrl, ?int $now = null): void
    {
        $now ??= time();

        $user = $this->db->fetch(
            'SELECT id FROM user WHERE email = :email AND is_active = 1 LIMIT 1',
            ['email' => $email],
        );

        if ($user === null) {
            return;
        }

        $userId = (int) ($user['id'] ?? 0);

        // Token a haute entropie (256 bits). Stocke en SHA-256 : un hash rapide
        // suffit (la robustesse vient de l'entropie, pas d'un KDF lent), et le
        // brut n'est jamais persiste. Voir comment de confirmReset().
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $ttl = $this->config->int('PASSWORD_RESET_TTL', 3600);
        $expiresAt = date('Y-m-d H:i:s', $now + $ttl);

        $this->db->execute(
            'UPDATE user SET password_reset_token_hash = :hash, password_reset_expires_at = :exp WHERE id = :id',
            ['hash' => $tokenHash, 'exp' => $expiresAt, 'id' => $userId],
        );

        $resetUrl = rtrim($baseUrl, '/') . '/reset_password?token=' . $rawToken;
        $this->mailer->sendPasswordReset($email, $resetUrl);
    }

    /**
     * Phase confirmation (RG-3/RG-4). Hash du token soumis, recherche par hash +
     * expiration future (la recherche par egalite sur un token 256 bits EST la
     * comparaison ; pas de souci de temps constant car ce n'est pas un secret a
     * faible entropie et la colonne n'est jamais renvoyee au client). Min 8
     * caracteres, nouveau hash argon2id, token efface (usage unique), compteurs
     * remis a zero, audit_log : le tout dans une transaction.
     */
    public function confirmReset(string $rawToken, string $newPassword, ?int $now = null): AuthResult
    {
        $now ??= time();

        if (strlen($newPassword) < 8) {
            return AuthResult::failure('Le mot de passe doit contenir au moins 8 caracteres.');
        }

        if ($rawToken === '') {
            return AuthResult::failure('Lien invalide ou expire.');
        }

        $tokenHash = hash('sha256', $rawToken);
        $nowDt = date('Y-m-d H:i:s', $now);

        $user = $this->db->fetch(
            'SELECT id, role_id, password_reset_token_hash FROM user '
            . 'WHERE password_reset_token_hash = :hash AND password_reset_expires_at > :now '
            . 'AND is_active = 1 LIMIT 1',
            ['hash' => $tokenHash, 'now' => $nowDt],
        );

        if ($user === null) {
            return AuthResult::failure('Lien invalide ou expire.');
        }

        $userId = (int) ($user['id'] ?? 0);
        $roleId = (int) ($user['role_id'] ?? 0);
        $newHash = $this->hasher->hash($newPassword);

        $this->db->transaction(function (DatabaseInterface $db) use ($userId, $roleId, $newHash): void {
            // Usage unique : on efface token + expiration et on remet les
            // compteurs anti brute-force a zero (le compte redevient utilisable).
            $db->execute(
                'UPDATE user SET password_hash = :hash, password_reset_token_hash = NULL, '
                . 'password_reset_expires_at = NULL, failed_login_attempts = 0, lockout_until = NULL '
                . 'WHERE id = :id',
                ['hash' => $newHash, 'id' => $userId],
            );

            $db->execute(
                'INSERT INTO audit_log (actor_user_id, actor_role_id, action_code, entity_type, entity_id, summary) '
                . 'VALUES (:uid, :rid, :code, :etype, :eid, :summary)',
                [
                    'uid' => $userId,
                    'rid' => $roleId,
                    'code' => 'auth.password_reset',
                    'etype' => 'user',
                    'eid' => $userId,
                    'summary' => 'Reinitialisation du mot de passe',
                ],
            );
        });

        return AuthResult::success($userId, $roleId, '/login?reset=ok');
    }
}
