<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PDOException;
use PHPUnit\Framework\TestCase;
use Throwable;
use App\Auth\RoleRepository;
use App\Core\Config;
use App\Core\Database;

/**
 * RoleRepository (RBAC, mlt 10.4) contre une vraie MariaDB (schema migre + seede).
 * Auto-skip si WAKDO_DB_TESTS != 1. Role jetable (code it-role-*) ; CASCADE retire
 * role_permission + role_visible_source a la suppression du role (teardown simple).
 */
final class RoleRepositoryDbTest extends TestCase
{
    private Database $db;
    private string $code = '';
    private int $permA = 0;
    private int $permB = 0;

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
        $this->code = 'it-role-' . bin2hex(random_bytes(4));
        $this->permA = (int) ($this->db->fetch("SELECT id FROM permission WHERE code = 'stats.read'")['id'] ?? 0);
        $this->permB = (int) ($this->db->fetch("SELECT id FROM permission WHERE code = 'user.read'")['id'] ?? 0);
    }

    protected function tearDown(): void
    {
        if ($this->code !== '') {
            $this->db->execute('DELETE FROM role WHERE code = :c', ['c' => $this->code]); // CASCADE perms + sources
        }
    }

    private function makeRole(RoleRepository $repo): int
    {
        return $repo->createRole([
            'code'          => $this->code,
            'label'         => 'IT Role',
            'description'   => 'jetable',
            'default_route' => '/admin/dashboard',
            'order_source'  => null,
        ]);
    }

    public function testCreateRoleAndCodeUnique(): void
    {
        $repo = new RoleRepository($this->db);
        $id = $this->makeRole($repo);
        self::assertGreaterThan(0, $id);

        $found = $repo->findRole($id);
        self::assertNotNull($found);
        self::assertSame($this->code, (string) $found['code']);
        self::assertTrue($repo->codeExists($this->code));
        self::assertFalse($repo->codeExists($this->code, $id)); // s'exclut lui-meme

        $violated = false;
        try {
            $repo->createRole(['code' => $this->code, 'label' => 'Dup', 'description' => null, 'default_route' => null, 'order_source' => null]);
        } catch (PDOException $exception) {
            $violated = (string) $exception->getCode() === '23000';
        }
        self::assertTrue($violated, 'uk_role_code doit rejeter un doublon.');
    }

    public function testSetPermissionsReplacesAndExposesCodes(): void
    {
        $repo = new RoleRepository($this->db);
        $id = $this->makeRole($repo);

        $repo->setPermissions($id, [$this->permA, $this->permB]);
        $ids = $repo->permissionIdsFor($id);
        sort($ids);
        $expected = [$this->permA, $this->permB];
        sort($expected);
        self::assertSame($expected, $ids);

        $codes = $repo->permissionCodesFor($id);
        self::assertContains('stats.read', $codes);
        self::assertContains('user.read', $codes);

        // Delete-and-reinsert : la nouvelle selection REMPLACE l'ancienne.
        $repo->setPermissions($id, [$this->permA]);
        self::assertSame([$this->permA], $repo->permissionIdsFor($id));

        // 23 permissions au catalogue (fige au seed).
        self::assertCount(23, $repo->allPermissions());
    }

    public function testSetVisibleSourcesReplaces(): void
    {
        $repo = new RoleRepository($this->db);
        $id = $this->makeRole($repo);

        $repo->setVisibleSources($id, ['counter', 'drive']);
        $sources = $repo->visibleSources($id);
        sort($sources);
        self::assertSame(['counter', 'drive'], $sources);

        $repo->setVisibleSources($id, ['kiosk']);
        self::assertSame(['kiosk'], $repo->visibleSources($id));
    }

    public function testUpdateRoleKeepsCodeImmutable(): void
    {
        $repo = new RoleRepository($this->db);
        $id = $this->makeRole($repo);

        $repo->updateRole($id, [
            'label'         => 'Relabelled',
            'description'   => 'maj',
            'default_route' => '/admin/stats',
            'order_source'  => 'counter',
            'is_active'     => 1,
        ]);
        $updated = $repo->findRole($id);
        self::assertNotNull($updated);
        self::assertSame('Relabelled', (string) $updated['label']);
        self::assertSame('/admin/stats', (string) $updated['default_route']);
        self::assertSame('counter', (string) $updated['order_source']);
        self::assertSame($this->code, (string) $updated['code']); // code inchange (immuable)
    }
}
