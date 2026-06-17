<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\DatabaseInterface;

/**
 * Acces aux donnees RBAC en ECRITURE (mlt 10.4 MANAGE_RBAC) : roles, matrice
 * role_permission, sources visibles role_visible_source. La lecture des
 * permissions pour l'autorisation reste dans Authorizer (rechargee a chaque
 * verification). Le catalogue `permission` est fige au seed (lecture seule ici).
 *
 * Invariants : le `code` d'un role est UNIQUE et IMMUABLE apres creation (cle
 * referencee par la logique applicative). La matrice et les sources sont reposees
 * en delete-and-reinsert dans UNE transaction (RG-T08 / mlt 10.4 RG-1).
 */
final class RoleRepository
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allRoles(): array
    {
        return $this->db->fetchAll(
            'SELECT id, code, label, description, default_route, order_source, is_active '
            . 'FROM role ORDER BY id',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRole(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT id, code, label, description, default_route, order_source, is_active '
            . 'FROM role WHERE id = :id',
            ['id' => $id],
        );
    }

    public function codeExists(string $code, int $exceptId = 0): bool
    {
        return $this->db->fetch(
            'SELECT id FROM role WHERE code = :code AND id <> :id',
            ['code' => $code, 'id' => $exceptId],
        ) !== null;
    }

    /**
     * Catalogue complet des permissions (fige au seed), pour peupler la matrice.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allPermissions(): array
    {
        return $this->db->fetchAll('SELECT id, code, label FROM permission ORDER BY id');
    }

    /**
     * @return list<int>
     */
    public function permissionIdsFor(int $roleId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT permission_id FROM role_permission WHERE role_id = :id',
            ['id' => $roleId],
        );

        return array_map(static fn (array $r): int => (int) ($r['permission_id'] ?? 0), $rows);
    }

    /**
     * Codes de permission d'un role (pour le diff d'audit RG-6 : add/remove).
     *
     * @return list<string>
     */
    public function permissionCodesFor(int $roleId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT p.code FROM role_permission rp JOIN permission p ON p.id = rp.permission_id '
            . 'WHERE rp.role_id = :id ORDER BY p.code',
            ['id' => $roleId],
        );

        return array_map(static fn (array $r): string => (string) ($r['code'] ?? ''), $rows);
    }

    /**
     * Reecrit la matrice d'un role (mlt 10.4 RG-1) : DELETE puis INSERT des paires
     * selectionnees, dans UNE transaction. L'appelant a deja filtre les
     * permission_id au catalogue existant (PRE-3).
     *
     * @param list<int> $permissionIds
     */
    public function setPermissions(int $roleId, array $permissionIds): void
    {
        $this->db->transaction(function (DatabaseInterface $db) use ($roleId, $permissionIds): void {
            $this->replacePermissions($db, $roleId, $permissionIds);
        });
    }

    /**
     * Variante SANS transaction propre : reecrit la matrice sur le $db fourni, pour
     * que le controleur l'enrobe dans UNE transaction avec l'ecriture d'audit (RG-6,
     * audit du diff dans la meme transaction que l'effet). Ne pas appeler hors d'une
     * transaction de l'appelant.
     *
     * @param list<int> $permissionIds
     */
    public function replacePermissions(DatabaseInterface $db, int $roleId, array $permissionIds): void
    {
        $db->execute('DELETE FROM role_permission WHERE role_id = :id', ['id' => $roleId]);
        foreach (array_values(array_unique($permissionIds)) as $permissionId) {
            $db->execute(
                'INSERT INTO role_permission (role_id, permission_id) VALUES (:role, :perm)',
                ['role' => $roleId, 'perm' => $permissionId],
            );
        }
    }

    /**
     * Creation d'un role personnalise (mlt 10.4 RG-4). `is_active` pose cote serveur.
     * Retourne l'id. Allowlist RG-T16.
     *
     * @param array{code: string, label: string, description: ?string, default_route: ?string, order_source: ?string} $data
     */
    public function createRole(array $data): int
    {
        $this->db->execute(
            'INSERT INTO role (code, label, description, default_route, order_source, is_active) '
            . 'VALUES (:code, :label, :description, :route, :source, 1)',
            [
                'code'        => $data['code'],
                'label'       => $data['label'],
                'description' => $data['description'],
                'route'       => $data['default_route'],
                'source'      => $data['order_source'],
            ],
        );

        return (int) ($this->db->fetch('SELECT LAST_INSERT_ID() AS id')['id'] ?? 0);
    }

    /**
     * Mise a jour d'un role. Le `code` n'est PAS lie (immuable apres creation).
     *
     * @param array{label: string, description: ?string, default_route: ?string, order_source: ?string, is_active: int} $data
     */
    public function updateRole(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE role SET label = :label, description = :description, default_route = :route, '
            . 'order_source = :source, is_active = :active WHERE id = :id',
            [
                'label'       => $data['label'],
                'description' => $data['description'],
                'route'       => $data['default_route'],
                'source'      => $data['order_source'],
                'active'      => $data['is_active'],
                'id'          => $id,
            ],
        );
    }

    /**
     * @return list<string>
     */
    public function visibleSources(int $roleId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT source FROM role_visible_source WHERE role_id = :id',
            ['id' => $roleId],
        );

        return array_map(static fn (array $r): string => (string) ($r['source'] ?? ''), $rows);
    }

    /**
     * Reecrit les sources visibles d'un role (delete-and-reinsert, tx). L'appelant
     * filtre $sources a l'ENUM valide ('kiosk','counter','drive').
     *
     * @param list<string> $sources
     */
    public function setVisibleSources(int $roleId, array $sources): void
    {
        $this->db->transaction(function (DatabaseInterface $db) use ($roleId, $sources): void {
            $this->replaceVisibleSources($db, $roleId, $sources);
        });
    }

    /**
     * Variante SANS transaction propre (cf. replacePermissions), pour enrobage par
     * le controleur dans une transaction unique.
     *
     * @param list<string> $sources
     */
    public function replaceVisibleSources(DatabaseInterface $db, int $roleId, array $sources): void
    {
        $db->execute('DELETE FROM role_visible_source WHERE role_id = :id', ['id' => $roleId]);
        foreach (array_values(array_unique($sources)) as $source) {
            $db->execute(
                'INSERT INTO role_visible_source (role_id, source) VALUES (:role, :source)',
                ['role' => $roleId, 'source' => $source],
            );
        }
    }
}
