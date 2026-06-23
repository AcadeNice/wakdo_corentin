<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use App\Auth\Authorizer;
use App\Tests\Support\FakeDatabase;

/**
 * Verification par permission (RG-T03) : can() / permissionsFor() / roleCode()
 * testes avec un FakeDatabase, sans base reelle.
 */
final class AuthorizerTest extends TestCase
{
    private FakeDatabase $db;

    protected function setUp(): void
    {
        $this->db = new FakeDatabase();
    }

    private function authorizer(): Authorizer
    {
        return new Authorizer($this->db);
    }

    public function testCanReturnsTrueWhenPermissionGranted(): void
    {
        $this->db->canResult = true;

        self::assertTrue($this->authorizer()->can(1, 'product.create'));
        // RG-T03 : la verification lie le CODE de permission + le role_id (jamais
        // un nom de role). On asserte les parametres reellement lies a la requete.
        self::assertSame(['role' => 1, 'code' => 'product.create'], $this->lastRead()['params']);
    }

    public function testCanReturnsFalseWhenNotGranted(): void
    {
        $this->db->canResult = false;

        self::assertFalse($this->authorizer()->can(3, 'order.cancel'));
    }

    public function testPermissionsForReturnsCodes(): void
    {
        $this->db->permissionCodes = ['order.read', 'product.read', 'stock.read'];

        self::assertSame(
            ['order.read', 'product.read', 'stock.read'],
            $this->authorizer()->permissionsFor(4),
        );
        self::assertSame(['role' => 4], $this->lastRead()['params']);
    }

    public function testPermissionsForReturnsEmptyWhenNone(): void
    {
        $this->db->permissionCodes = [];

        self::assertSame([], $this->authorizer()->permissionsFor(9));
    }

    public function testRoleCodeReturnsCodeOrNull(): void
    {
        $this->db->roleRow = ['code' => 'admin'];
        self::assertSame('admin', $this->authorizer()->roleCode(1));

        $this->db->roleRow = null;
        self::assertNull($this->authorizer()->roleCode(999));
    }

    public function testCanDeniesWhenRoleInactive(): void
    {
        // Le role detient la permission (canResult) mais il est desactive : refus.
        $this->db->canResult = true;
        $this->db->roleActive = false;

        self::assertFalse($this->authorizer()->can(1, 'product.create'));
    }

    public function testPermissionsForEmptyWhenRoleInactive(): void
    {
        $this->db->permissionCodes = ['order.read', 'product.read'];
        $this->db->roleActive = false;

        self::assertSame([], $this->authorizer()->permissionsFor(4));
    }

    /**
     * @return array{sql: string, params: array<string|int, mixed>}
     */
    private function lastRead(): array
    {
        $reads = $this->db->reads;
        self::assertNotEmpty($reads, 'aucune lecture enregistree');

        return $reads[array_key_last($reads)];
    }
}
