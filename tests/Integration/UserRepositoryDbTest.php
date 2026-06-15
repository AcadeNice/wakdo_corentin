<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Auth\PasswordHasher;
use App\Auth\UserRepository;
use App\Core\Config;
use App\Core\Database;

/**
 * Ecriture du PIN (UserRepository) contre une vraie MariaDB. Auto-skip si
 * WAKDO_DB_TESTS != 1. User jetable (.invalid), supprime en tearDown.
 */
final class UserRepositoryDbTest extends TestCase
{
    private Database $db;
    private Config $config;
    private int $userId = 0;

    protected function setUp(): void
    {
        if (getenv('WAKDO_DB_TESTS') !== '1') {
            self::markTestSkipped('Tests DB desactives (definir WAKDO_DB_TESTS=1 + DB_*).');
        }

        $this->config = new Config();
        $this->db = new Database($this->config);

        try {
            $this->db->fetch('SELECT 1');
        } catch (Throwable $exception) {
            self::markTestSkipped('Base injoignable: ' . $exception->getMessage());
        }

        $roleId = (int) ($this->db->fetch('SELECT id FROM role ORDER BY id LIMIT 1')['id'] ?? 0);
        $hasher = new PasswordHasher($this->config);
        $this->db->execute(
            'INSERT INTO user (email, password_hash, first_name, last_name, role_id, is_active) '
            . 'VALUES (:email, :pwd, :fn, :ln, :role, 1)',
            [
                'email' => 'it-userrepo-' . bin2hex(random_bytes(6)) . '@wakdo.invalid',
                'pwd' => $hasher->hash('IntegrationPass1'),
                'fn' => 'Integration',
                'ln' => 'UserRepo',
                'role' => $roleId,
            ],
        );
        $this->userId = (int) ($this->db->fetch('SELECT LAST_INSERT_ID() AS id')['id'] ?? 0);
    }

    protected function tearDown(): void
    {
        if ($this->userId !== 0) {
            $this->db->execute('DELETE FROM user WHERE id = :id', ['id' => $this->userId]);
            $this->userId = 0;
        }
    }

    public function testSetPinHashAndPinIsSet(): void
    {
        $repo = new UserRepository($this->db);
        $hasher = new PasswordHasher($this->config);

        // Aucun PIN au depart.
        self::assertFalse($repo->pinIsSet($this->userId));

        $repo->setPinHash($this->userId, $hasher->hash('4729'));

        self::assertTrue($repo->pinIsSet($this->userId));

        // Le hash stocke est verifiable et n'est pas le PIN en clair.
        $stored = (string) ($this->db->fetch('SELECT pin_hash FROM user WHERE id = :id', ['id' => $this->userId])['pin_hash'] ?? '');
        self::assertNotSame('4729', $stored);
        self::assertTrue($hasher->verify('4729', $stored));
    }
}
