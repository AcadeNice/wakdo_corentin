<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\DatabaseInterface;

/**
 * Verification d'autorisation par PERMISSION (RG-T03), pas par nom de role : on
 * teste qu'un role detient un code de permission via role_permission. Les droits
 * sont recharges depuis la base a CHAQUE appel (mlt.md 10.4 RG-3) ; la session ne
 * porte que role_id, donc un changement RBAC prend effet a la verification suivante
 * sans invalider les sessions.
 *
 * Un role inactif (role.is_active = 0) ne confere aucune permission.
 */
final class Authorizer
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function can(int $roleId, string $permissionCode): bool
    {
        $row = $this->db->fetch(
            'SELECT 1 AS granted FROM role_permission rp '
            . 'JOIN permission p ON p.id = rp.permission_id '
            . 'JOIN role r ON r.id = rp.role_id '
            . 'WHERE rp.role_id = :role AND p.code = :code AND r.is_active = 1 LIMIT 1',
            ['role' => $roleId, 'code' => $permissionCode],
        );

        return $row !== null;
    }

    /**
     * Liste des codes de permission du role (pour /api/me et l'affichage UI).
     *
     * @return list<string>
     */
    public function permissionsFor(int $roleId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT p.code FROM role_permission rp '
            . 'JOIN permission p ON p.id = rp.permission_id '
            . 'JOIN role r ON r.id = rp.role_id '
            . 'WHERE rp.role_id = :role AND r.is_active = 1 ORDER BY p.code',
            ['role' => $roleId],
        );

        $codes = [];
        foreach ($rows as $row) {
            $code = $row['code'] ?? null;
            if (is_string($code)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Code du role (ex. 'admin', 'counter'). Lecture de metadonnee de role,
     * regroupee ici avec l'acces a role_permission pour un seul seam de donnees.
     */
    public function roleCode(int $roleId): ?string
    {
        // Filtre is_active comme can()/permissionsFor() : un role desactive ne
        // doit exposer ni droits ni libelle exploitable (coherence de l'invariant).
        $row = $this->db->fetch('SELECT r.code FROM role r WHERE r.id = :id AND r.is_active = 1', ['id' => $roleId]);

        return is_string($row['code'] ?? null) ? $row['code'] : null;
    }
}
