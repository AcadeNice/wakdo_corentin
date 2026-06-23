<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Auth\AuthService;
use App\Auth\PasswordHasher;
use App\Auth\SessionManager;
use App\Core\Config;
use App\Core\Database;

/**
 * Test d'integration de AUTHENTICATE_USER contre une vraie MariaDB (schema migre
 * + seede). Il valide le SQL reel (requetes preparees, transaction, upsert
 * login_throttle) que les tests unitaires a FakeDatabase ne peuvent pas exercer.
 *
 * Auto-skip : ne s'execute que si WAKDO_DB_TESTS=1 ET qu'une base est joignable.
 * La CI (sans base) le saute donc, et il ne touche jamais la base par defaut.
 *
 * Isolation : chaque test cree son propre utilisateur jetable (email .invalid
 * unique) et le supprime en tearDown, avec sa ligne login_throttle (IP de test
 * dans le bloc documentation TEST-NET-2) et ses lignes audit_log.
 */
final class AuthServiceDbTest extends TestCase
{
    private const TEST_IP = '198.51.100.250';
    private const PASSWORD = 'IntegrationPass1';

    private Database $db;
    private Config $config;
    private int $userId = 0;

    protected function setUp(): void
    {
        if (getenv('WAKDO_DB_TESTS') !== '1') {
            self::markTestSkipped('Tests DB desactives (definir WAKDO_DB_TESTS=1 + DB_* pour les activer).');
        }

        $this->config = new Config();
        $this->db = new Database($this->config);

        try {
            $this->db->fetch('SELECT 1');
        } catch (Throwable $exception) {
            self::markTestSkipped('Base de donnees injoignable: ' . $exception->getMessage());
        }

        $this->cleanupThrottle();
        $this->userId = $this->createDisposableUser();
    }

    protected function tearDown(): void
    {
        if ($this->userId === 0) {
            return;
        }

        // Ordre compatible FK : audit (actor SET NULL mais on retire nos lignes),
        // throttle (par IP), puis l'utilisateur jetable.
        $this->db->execute('DELETE FROM audit_log WHERE actor_user_id = :id', ['id' => $this->userId]);
        $this->cleanupThrottle();
        $this->db->execute('DELETE FROM user WHERE id = :id', ['id' => $this->userId]);
        $this->userId = 0;
    }

    private function service(): AuthService
    {
        return new AuthService(
            $this->db,
            $this->config,
            new SessionManager($this->config, true),
            new PasswordHasher($this->config),
        );
    }

    public function testSuccessfulLoginPersistsResetCountersAndAuditSuccess(): void
    {
        $result = $this->service()->authenticate($this->email(), self::PASSWORD, self::TEST_IP);

        self::assertTrue($result->success);

        $user = $this->db->fetch(
            'SELECT failed_login_attempts, lockout_until, last_login_at FROM user WHERE id = :id',
            ['id' => $this->userId],
        );
        self::assertNotNull($user);
        self::assertSame(0, (int) ($user['failed_login_attempts'] ?? -1));
        self::assertNull($user['lockout_until']);
        self::assertNotNull($user['last_login_at']);

        self::assertSame('auth.login_success', $this->lastAuditAction());
    }

    public function testFailedLoginIncrementsAccountAndCreatesThrottleAndAuditFailure(): void
    {
        $result = $this->service()->authenticate($this->email(), 'WRONG-PASSWORD', self::TEST_IP);

        self::assertFalse($result->success);

        $user = $this->db->fetch(
            'SELECT failed_login_attempts FROM user WHERE id = :id',
            ['id' => $this->userId],
        );
        self::assertNotNull($user);
        self::assertSame(1, (int) ($user['failed_login_attempts'] ?? -1));

        $throttle = $this->db->fetch(
            'SELECT failed_attempts FROM login_throttle WHERE ip_address = :ip',
            ['ip' => self::TEST_IP],
        );
        self::assertNotNull($throttle);
        self::assertSame(1, (int) ($throttle['failed_attempts'] ?? -1));

        self::assertSame('auth.login_failed', $this->lastAuditAction());
    }

    public function testThrottleGateRejectsWhenAccountLocked(): void
    {
        // Pose un verrou compte dans le futur, puis tente avec le BON mot de passe :
        // la porte PRE-3 doit refuser avant toute verification.
        $future = date('Y-m-d H:i:s', time() + 600);
        $this->db->execute(
            'UPDATE user SET lockout_until = :lock WHERE id = :id',
            ['lock' => $future, 'id' => $this->userId],
        );

        $result = $this->service()->authenticate($this->email(), self::PASSWORD, self::TEST_IP);

        self::assertFalse($result->success);
        // last_login_at reste nul : aucune authentification n'a abouti.
        $user = $this->db->fetch('SELECT last_login_at FROM user WHERE id = :id', ['id' => $this->userId]);
        self::assertNotNull($user);
        self::assertNull($user['last_login_at']);
    }

    private function email(): string
    {
        return 'it-auth-' . $this->userId . '@wakdo.invalid';
    }

    private function createDisposableUser(): int
    {
        $roleRow = $this->db->fetch('SELECT id FROM role ORDER BY id LIMIT 1');
        $roleId = (int) ($roleRow['id'] ?? 0);
        self::assertGreaterThan(0, $roleId, 'aucun role seede: migration/seed requis');

        $hash = (new PasswordHasher($this->config))->hash(self::PASSWORD);
        // Email provisoire pour obtenir l'id, puis on le rend unique par id.
        $this->db->execute(
            'INSERT INTO user (email, password_hash, first_name, last_name, role_id, is_active) '
            . 'VALUES (:email, :hash, :fn, :ln, :role, 1)',
            [
                'email' => 'it-auth-pending-' . bin2hex(random_bytes(6)) . '@wakdo.invalid',
                'hash' => $hash,
                'fn' => 'Integration',
                'ln' => 'Test',
                'role' => $roleId,
            ],
        );

        $row = $this->db->fetch('SELECT LAST_INSERT_ID() AS id');
        $id = (int) ($row['id'] ?? 0);

        $this->db->execute(
            'UPDATE user SET email = :email WHERE id = :id',
            ['email' => 'it-auth-' . $id . '@wakdo.invalid', 'id' => $id],
        );

        return $id;
    }

    private function cleanupThrottle(): void
    {
        $this->db->execute('DELETE FROM login_throttle WHERE ip_address = :ip', ['ip' => self::TEST_IP]);
    }

    private function lastAuditAction(): ?string
    {
        $row = $this->db->fetch(
            'SELECT action_code FROM audit_log WHERE actor_user_id = :id ORDER BY id DESC LIMIT 1',
            ['id' => $this->userId],
        );

        return $row === null ? null : (string) ($row['action_code'] ?? '');
    }
}
