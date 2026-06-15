<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Core\DatabaseInterface;

/**
 * PIN d'action sensible (mlt.md RG-T13). Sur un poste a session partagee, le PIN
 * re-authentifie l'individu avant une action sensible (annulation, prix/TVA,
 * suppression, correction d'inventaire, gestion utilisateur, RBAC, effacement PII)
 * et fournit l'actor_user_id ecrit dans audit_log (RG-T14).
 *
 * Ce service est le PRIMITIF de verification, reutilise par chaque operation
 * sensible en P3 : il verifie le PIN soumis contre user.pin_hash (argon2id, meme
 * hacheur que le mot de passe). Le flux complet (PIN + audit dans la meme
 * transaction que l'effet) est decrit dans docs/uml/security-sequence.md.
 *
 * NB P2 : aucune operation sensible n'existe encore (elles arrivent en P3), donc
 * ce primitif n'est pas encore cable a une route ; il est ecrit et teste ici pour
 * que P3 s'y branche. La definition d'un PIN (set/change) releve de la gestion
 * utilisateur (P3, 10.1/10.2).
 */
final class PinVerifier
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly Config $config,
        private readonly PasswordHasher $hasher,
    ) {
    }

    /**
     * Vrai si $pin correspond au pin_hash de l'utilisateur actif $userId. Un PIN
     * vide, un compte inactif/absent ou un pin_hash non defini renvoient false,
     * sans distinction (ne revele pas la raison de l'echec).
     */
    public function verify(int $userId, string $pin): bool
    {
        if ($pin === '') {
            return false;
        }

        $row = $this->db->fetch(
            'SELECT pin_hash FROM user WHERE id = :id AND is_active = 1',
            ['id' => $userId],
        );

        $hash = is_string($row['pin_hash'] ?? null) ? (string) $row['pin_hash'] : '';

        if ($hash === '') {
            // Egalise le timing avec le chemin mauvais-PIN (verify argon2id) : sans
            // ce leurre, un compte sans PIN (ou inactif/absent) repondrait plus vite,
            // revelant par la latence quels comptes ont un PIN defini (anti-enumeration,
            // meme posture que AuthService RG-2). Le leurre est mis en cache process.
            $this->hasher->verifyDecoy($pin);

            return false;
        }

        return $this->hasher->verify($pin, $hash);
    }

    /**
     * Politique de PIN a verifier cote serveur avant de hacher un nouveau PIN
     * (P3, definition du PIN) : chiffres ASCII uniquement, bornes min ET max
     * (RG-T18). ctype_digit garantit le charset numerique, ce qui rend strlen
     * fiable comme nombre de caracteres.
     */
    public function meetsLengthPolicy(string $pin): bool
    {
        $min = $this->config->int('STAFF_PIN_MIN_LENGTH', 4);
        $max = $this->config->int('STAFF_PIN_MAX_LENGTH', 12);

        return $pin !== '' && ctype_digit($pin) && strlen($pin) >= $min && strlen($pin) <= $max;
    }
}
