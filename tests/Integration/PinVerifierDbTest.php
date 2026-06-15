<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Auth\PasswordHasher;
use App\Auth\PinVerifier;
use App\Core\Config;
use App\Core\Database;

/**
 * Verification du PIN (RG-T13) contre une vraie MariaDB : prouve la lecture reelle
 * de user.pin_hash et le filtre is_active = 1.
 *
 * Auto-skip si WAKDO_DB_TESTS != 1 ou base injoignable. Cree un user jetable
 * (email .invalid) avec un PIN connu, supprime en tearDown.
 */
final class PinVerifierDbTest extends TestCase
{
    private const PIN = '4729';

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

        $roleRow = $this->db->fetch('SELECT id FROM role ORDER BY id LIMIT 1');
        $roleId = (int) ($roleRow['id'] ?? 0);
        self::assertGreaterThan(0, $roleId, 'role seede attendu');

        $hasher = new PasswordHasher($this->config);
        $this->db->execute(
            'INSERT INTO user (email, password_hash, pin_hash, first_name, last_name, role_id, is_active) '
            . 'VALUES (:email, :pwd, :pin, :fn, :ln, :role, 1)',
            [
                'email' => 'it-pin-' . bin2hex(random_bytes(6)) . '@wakdo.invalid',
                'pwd' => $hasher->hash('IntegrationPass1'),
                'pin' => $hasher->hash(self::PIN),
                'fn' => 'Integration',
                'ln' => 'Pin',
                'role' => $roleId,
            ],
        );
        $this->userId = (int) ($this->db->fetch('SELECT LAST_INSERT_ID() AS id')['id'] ?? 0);
    }

    protected function tearDown(): void
    {
        if ($this->userId === 0) {
            return;
        }

        $this->db->execute('DELETE FROM user WHERE id = :id', ['id' => $this->userId]);
        $this->userId = 0;
    }

    private function verifier(): PinVerifier
    {
        return new PinVerifier($this->db, $this->config, new PasswordHasher($this->config));
    }

    public function testVerifyAgainstRealPinHash(): void
    {
        $verifier = $this->verifier();

        self::assertTrue($verifier->verify($this->userId, self::PIN));
        self::assertFalse($verifier->verify($this->userId, '0000'));
    }

    public function testVerifyFalseWhenPinHashNull(): void
    {
        $this->db->execute('UPDATE user SET pin_hash = NULL WHERE id = :id', ['id' => $this->userId]);

        self::assertFalse($this->verifier()->verify($this->userId, self::PIN));
    }

    public function testVerifyFalseWhenUserInactive(): void
    {
        // Compte desactive mais pin_hash encore valide : le filtre is_active = 1
        // doit refuser (un equipier desactive ne re-autorise plus d'action sensible).
        $this->db->execute('UPDATE user SET is_active = 0 WHERE id = :id', ['id' => $this->userId]);

        self::assertFalse($this->verifier()->verify($this->userId, self::PIN));
    }
}
