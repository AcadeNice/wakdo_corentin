<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Auth\Authorizer;
use App\Core\Config;
use App\Core\Database;

/**
 * Test d'integration de l'autorisation contre une vraie MariaDB (schema migre +
 * seede). Garde anti-regression du predicat de securite `AND r.is_active = 1` et
 * du filtrage par code de permission, que le double unitaire ne peut pas prouver.
 *
 * Auto-skip : ne s'execute que si WAKDO_DB_TESTS=1 ET base joignable.
 * Isolation : cree un role jetable (code unique) + une ligne role_permission,
 * supprimes en tearDown.
 */
final class AuthorizerDbTest extends TestCase
{
    private Database $db;
    private int $roleId = 0;
    private string $roleCode = '';

    protected function setUp(): void
    {
        if (getenv('WAKDO_DB_TESTS') !== '1') {
            self::markTestSkipped('Tests DB desactives (definir WAKDO_DB_TESTS=1 + DB_*).');
        }

        $this->db = new Database(new Config());

        try {
            $this->db->fetch('SELECT 1');
        } catch (Throwable $exception) {
            self::markTestSkipped('Base injoignable: ' . $exception->getMessage());
        }

        // Role jetable cree DESACTIVE, portant la permission product.read.
        $this->roleCode = 'it-rbac-' . bin2hex(random_bytes(4));
        $this->db->execute(
            'INSERT INTO role (code, label, is_active) VALUES (:code, :label, 0)',
            ['code' => $this->roleCode, 'label' => 'IT RBAC'],
        );
        $this->roleId = (int) ($this->db->fetch('SELECT LAST_INSERT_ID() AS id')['id'] ?? 0);
        $this->db->execute(
            'INSERT INTO role_permission (role_id, permission_id) '
            . 'SELECT :rid, id FROM permission WHERE code = :pc',
            ['rid' => $this->roleId, 'pc' => 'product.read'],
        );
    }

    protected function tearDown(): void
    {
        if ($this->roleId === 0) {
            return;
        }

        $this->db->execute('DELETE FROM role_permission WHERE role_id = :id', ['id' => $this->roleId]);
        $this->db->execute('DELETE FROM role WHERE id = :id', ['id' => $this->roleId]);
        $this->roleId = 0;
    }

    public function testInactiveRoleGrantsNothingThenActiveGrants(): void
    {
        $authz = new Authorizer($this->db);

        // is_active = 0 : aucun droit ni libelle, malgre la ligne role_permission.
        self::assertFalse($authz->can($this->roleId, 'product.read'));
        self::assertSame([], $authz->permissionsFor($this->roleId));
        self::assertNull($authz->roleCode($this->roleId));

        // On active le role : le meme grant devient effectif -> c'est bien le
        // predicat is_active qui gate (et non l'absence de role_permission).
        $this->db->execute('UPDATE role SET is_active = 1 WHERE id = :id', ['id' => $this->roleId]);

        self::assertTrue($authz->can($this->roleId, 'product.read'));
        self::assertSame(['product.read'], $authz->permissionsFor($this->roleId));
        self::assertSame($this->roleCode, $authz->roleCode($this->roleId));
    }

    public function testSeededAdminRoleFiltersByPermissionCode(): void
    {
        $authz = new Authorizer($this->db);
        $adminId = (int) ($this->db->fetch("SELECT id FROM role WHERE code = 'admin'")['id'] ?? 0);
        self::assertGreaterThan(0, $adminId, 'role admin seede attendu');

        // RG-T03 : filtrage par code (admin detient product.create, pas une permission inventee).
        self::assertTrue($authz->can($adminId, 'product.create'));
        self::assertFalse($authz->can($adminId, 'totally.fake.permission'));
        self::assertContains('role.manage', $authz->permissionsFor($adminId));
    }
}
