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
 * Utilise par les operations sensibles du back-office (annulation de commande,
 * ajustement et inventaire de stock, suppressions, modifications de prix, gestion
 * des utilisateurs et des roles) et, pour ses bornes de longueur, par la definition
 * du PIN (ProfileController).
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
     * Modele "identifiant equipier + PIN" (RG-T13) : sur un poste a session
     * partagee, l'individu qui realise l'action sensible se ré-authentifie par
     * email + PIN. Resout l'utilisateur ACTIF par email, verifie le PIN contre son
     * pin_hash, et renvoie son identite {id, role_id} (l'acteur ecrit dans
     * audit_log) ou null. Email/PIN absent ou inconnu : verify leurre (timing).
     *
     * @return array{id: int, role_id: int}|null
     */
    public function resolveActingUser(string $email, string $pin): ?array
    {
        if ($pin === '' || $email === '') {
            $this->hasher->verifyDecoy($pin);

            return null;
        }

        $row = $this->db->fetch(
            'SELECT id, role_id, pin_hash FROM user WHERE email = :email AND is_active = 1 LIMIT 1',
            ['email' => $email],
        );

        $hash = is_string($row['pin_hash'] ?? null) ? (string) $row['pin_hash'] : '';

        if ($hash === '' || !$this->hasher->verify($pin, $hash)) {
            if ($hash === '') {
                $this->hasher->verifyDecoy($pin);
            }

            return null;
        }

        return ['id' => (int) ($row['id'] ?? 0), 'role_id' => (int) ($row['role_id'] ?? 0)];
    }

    /**
     * Paie le cout de hachage d'un leurre argon2id sans verifier de PIN reel. Sert
     * au chemin "acteur verrouille" (RG-T22) : quand le throttle bloque AVANT toute
     * verification, on paie quand meme ce cout pour egaliser le timing avec le
     * chemin mauvais-PIN. Sans lui, une reponse verrouillee reviendrait en
     * microsecondes (aucun verify) et trahirait l'etat de verrou par la latence.
     */
    public function payTimingDecoy(string $pin): void
    {
        $this->hasher->verifyDecoy($pin);
    }

    /**
     * Politique de PIN a verifier cote serveur avant de hacher un nouveau PIN
     * (P3, definition du PIN) : chiffres ASCII uniquement, bornes min ET max
     * (RG-T18). ctype_digit garantit le charset numerique, ce qui rend strlen
     * fiable comme nombre de caracteres.
     */
    public function meetsLengthPolicy(string $pin): bool
    {
        return $pin !== ''
            && ctype_digit($pin)
            && strlen($pin) >= $this->minLength()
            && strlen($pin) <= $this->maxLength();
    }

    /**
     * Bornes de longueur du PIN, exposees au formulaire de definition pour qu'il les
     * controle pendant la saisie (Cr 2.b.1) : une seule source, la meme que la regle
     * serveur ci-dessus, pour que le client applique la regle du serveur.
     */
    public function minLength(): int
    {
        return $this->config->int('STAFF_PIN_MIN_LENGTH', 4);
    }

    public function maxLength(): int
    {
        return $this->config->int('STAFF_PIN_MAX_LENGTH', 12);
    }
}
