<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\DatabaseInterface;

/**
 * Acces aux donnees de l'entite user : definition du PIN self-service ET gestion
 * complete des comptes back-office (mlt domaine 10 : create/update/deactivate +
 * effacement RGPD). Lecture seule d'affichage topbar = UserDirectory.
 *
 * Allowlist d'ecriture (RG-T16) : aucune methode ne lie `pin_hash`, `is_active`
 * (hors deactivate/anonymise dedies) ni les compteurs de throttle depuis une
 * requete. Le hash de mot de passe est calcule par l'appelant (PasswordHasher),
 * jamais le mot de passe en clair. L'anonymisation RGPD (mlt 10.5) preserve la
 * ligne (FK entrantes stock_movement/customer_order/audit_log) en la vidant.
 */
final class UserRepository
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Liste pour le back-office, avec le libelle de role (JOIN). Pas de hash ni de
     * secret expose. Inclut les comptes anonymises (tombstones) pour tracabilite.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->fetchAll(
            'SELECT u.id, u.email, u.first_name, u.last_name, u.role_id, u.is_active, '
            . 'u.last_login_at, u.anonymized_at, r.label AS role_label, r.code AS role_code '
            . 'FROM user u JOIN role r ON r.id = u.role_id '
            . 'ORDER BY u.is_active DESC, u.last_name, u.first_name',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT id, email, first_name, last_name, role_id, is_active, anonymized_at '
            . 'FROM user WHERE id = :id',
            ['id' => $id],
        );
    }

    public function emailExists(string $email, int $exceptId = 0): bool
    {
        return $this->db->fetch(
            'SELECT id FROM user WHERE email = :email AND id <> :id',
            ['email' => $email, 'id' => $exceptId],
        ) !== null;
    }

    /** Le role existe ET est actif (PRE-3 de CREATE_USER, vecteur d'escalade). */
    public function activeRoleExists(int $roleId): bool
    {
        return $this->db->fetch('SELECT id FROM role WHERE id = :id AND is_active = 1', ['id' => $roleId]) !== null;
    }

    /**
     * Creation (mlt 10.1). `is_active` est pose cote serveur (=1), pas lie a la
     * requete (RG-T16). Le hash est argon2id, calcule par l'appelant. Retourne l'id.
     *
     * @param array{email: string, password_hash: string, first_name: string, last_name: string, role_id: int} $data
     */
    public function create(array $data): int
    {
        $this->db->execute(
            'INSERT INTO user (email, password_hash, first_name, last_name, role_id, is_active) '
            . 'VALUES (:email, :hash, :first, :last, :role, 1)',
            [
                'email' => $data['email'],
                'hash'  => $data['password_hash'],
                'first' => $data['first_name'],
                'last'  => $data['last_name'],
                'role'  => $data['role_id'],
            ],
        );

        return (int) ($this->db->fetch('SELECT LAST_INSERT_ID() AS id')['id'] ?? 0);
    }

    /**
     * Mise a jour (mlt 10.2). Allowlist RG-T16 : email/prenom/nom/role_id/is_active.
     * Le mot de passe (re-hachage optionnel) et le PIN passent par des methodes
     * dediees, jamais lies ici.
     *
     * @param array{email: string, first_name: string, last_name: string, role_id: int, is_active: int} $data
     */
    public function update(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE user SET email = :email, first_name = :first, last_name = :last, '
            . 'role_id = :role, is_active = :active WHERE id = :id',
            [
                'email'  => $data['email'],
                'first'  => $data['first_name'],
                'last'   => $data['last_name'],
                'role'   => $data['role_id'],
                'active' => $data['is_active'],
                'id'     => $id,
            ],
        );
    }

    /** Re-hachage du mot de passe par un admin (mlt 10.2 RG-1, reset cote admin). */
    public function setPasswordHash(int $id, string $hash): int
    {
        return $this->db->execute('UPDATE user SET password_hash = :hash WHERE id = :id', ['hash' => $hash, 'id' => $id]);
    }

    /**
     * Reinitialise le PIN d'un equipier (admin) : on le met a NULL plutot que d'en
     * poser un (l'admin n'a pas a connaitre le PIN d'autrui) ; l'equipier le
     * redefinit ensuite en self-service (ProfileController).
     */
    public function clearPin(int $id): int
    {
        return $this->db->execute('UPDATE user SET pin_hash = NULL WHERE id = :id', ['id' => $id]);
    }

    /** Desactivation (mlt 10.3) : soft, l'historique reste intact. */
    public function deactivate(int $id): int
    {
        return $this->db->execute('UPDATE user SET is_active = 0 WHERE id = :id', ['id' => $id]);
    }

    /**
     * Anonymisation RGPD (mlt 10.5 RG-1) : vide la PII en GARDANT la ligne (les FK
     * entrantes stock_movement/customer_order/audit_log restent valides). Email ->
     * placeholder unique en `.invalid` (RFC 2606), conserve l'unicite sans etre
     * identifiant. Idempotence : ne reanonymise pas une ligne deja anonymisee
     * (clause anonymized_at IS NULL) -> 0 ligne affectee si deja fait.
     */
    public function anonymise(int $id): int
    {
        return $this->db->execute(
            "UPDATE user SET email = CONCAT('anon-', id, '@wakdo.invalid'), first_name = '', "
            . "last_name = '', password_hash = '', pin_hash = NULL, password_reset_token_hash = NULL, "
            . 'is_active = 0, anonymized_at = NOW() WHERE id = :id AND anonymized_at IS NULL',
            ['id' => $id],
        );
    }

    /**
     * Nombre d'administrateurs ACTIFS (role code 'admin'). Garde-fou : empeche de
     * desactiver/anonymiser/retrograder le dernier admin actif (verrouillage total
     * du back-office). Ce garde-fou va au-dela du mlt (qui ne borne que
     * l'auto-desactivation) mais previent un lock-out irrecuperable.
     */
    public function activeAdminCount(): int
    {
        return (int) ($this->db->fetch(
            "SELECT COUNT(*) AS n FROM user u JOIN role r ON r.id = u.role_id "
            . "WHERE r.code = 'admin' AND u.is_active = 1",
        )['n'] ?? 0);
    }

    /** L'utilisateur a-t-il le role admin (actif ou non) ? */
    public function isAdmin(int $id): bool
    {
        return $this->db->fetch(
            "SELECT u.id FROM user u JOIN role r ON r.id = u.role_id WHERE u.id = :id AND r.code = 'admin'",
            ['id' => $id],
        ) !== null;
    }

    /**
     * Retourne le nombre de lignes affectees (1 attendu). Le hash argon2id
     * change a chaque appel (sel aleatoire), donc une cible existante donne
     * toujours 1 ; 0 revele une cible inexistante (defense en profondeur).
     */
    public function setPinHash(int $userId, string $hash): int
    {
        return $this->db->execute('UPDATE user SET pin_hash = :hash WHERE id = :id', ['hash' => $hash, 'id' => $userId]);
    }

    public function pinIsSet(int $userId): bool
    {
        return $this->db->fetch(
            'SELECT id FROM user WHERE id = :id AND pin_hash IS NOT NULL',
            ['id' => $userId],
        ) !== null;
    }
}
