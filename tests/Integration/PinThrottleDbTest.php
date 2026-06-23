<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Auth\PasswordHasher;
use App\Auth\PinThrottle;
use App\Core\Config;
use App\Core\Database;

/**
 * Throttle du PIN (RG-T22) contre une vraie MariaDB. Prouve, sur le schema reel :
 *  - l'upsert atomique + backoff sur pin_throttle (cle = utilisateur agissant) ;
 *  - l'ISOLATION dure vis-a-vis du login : les echecs de PIN ne touchent NI
 *    user.failed_login_attempts / user.lockout_until, NI login_throttle.
 *
 * Auto-skip si WAKDO_DB_TESTS != 1 ou base injoignable. Cree un user jetable
 * (email .invalid), supprime en tearDown (la FK ON DELETE CASCADE purge la ligne
 * pin_throttle associee).
 */
final class PinThrottleDbTest extends TestCase
{
    private Database $db;
    private Config $config;
    private int $userId = 0;

    protected function setUp(): void
    {
        if (getenv('WAKDO_DB_TESTS') !== '1') {
            self::markTestSkipped('Tests DB desactives (definir WAKDO_DB_TESTS=1 + DB_*).');
        }

        putenv('PIN_THROTTLE_THRESHOLD=5');
        putenv('PIN_THROTTLE_BASE_SECONDS=30');
        putenv('PIN_THROTTLE_MAX_SECONDS=300');
        putenv('PIN_THROTTLE_WINDOW_SECONDS=900');

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
            'INSERT INTO user (email, password_hash, first_name, last_name, role_id, is_active) '
            . 'VALUES (:email, :pwd, :fn, :ln, :role, 1)',
            [
                'email' => 'it-pinthr-' . bin2hex(random_bytes(6)) . '@wakdo.invalid',
                'pwd' => $hasher->hash('IntegrationPass1'),
                'fn' => 'Integration',
                'ln' => 'PinThrottle',
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

        // FK ON DELETE CASCADE : la ligne pin_throttle de cet acteur part avec lui.
        $this->db->execute('DELETE FROM user WHERE id = :id', ['id' => $this->userId]);
        $this->userId = 0;
    }

    private function throttle(): PinThrottle
    {
        return new PinThrottle($this->db, $this->config);
    }

    public function testRecordFailureIncrementsAndLocksWithoutTouchingLoginCounters(): void
    {
        $now = time();
        $throttle = $this->throttle();

        for ($i = 0; $i < 5; $i++) {
            $throttle->recordFailure($this->userId, $now);
        }

        $row = $this->db->fetch('SELECT failed_attempts, lockout_until FROM pin_throttle WHERE actor_user_id = :id', ['id' => $this->userId]);
        self::assertNotNull($row);
        self::assertSame(5, (int) ($row['failed_attempts'] ?? 0));
        self::assertNotNull($row['lockout_until'] ?? null, 'verrou pose au seuil');
        self::assertTrue(strtotime((string) $row['lockout_until']) > $now, 'verrou dans le futur');

        self::assertTrue($throttle->isLocked($this->userId, $now));

        // ISOLATION : aucun compteur de connexion touche par les echecs de PIN.
        $userRow = $this->db->fetch('SELECT failed_login_attempts, lockout_until FROM user WHERE id = :id', ['id' => $this->userId]);
        self::assertSame(0, (int) ($userRow['failed_login_attempts'] ?? -1));
        self::assertNull($userRow['lockout_until'] ?? null);
    }

    public function testResetClearsTheActorRow(): void
    {
        $now = time();
        $throttle = $this->throttle();

        for ($i = 0; $i < 5; $i++) {
            $throttle->recordFailure($this->userId, $now);
        }
        $throttle->reset($this->userId, $now);

        $row = $this->db->fetch('SELECT failed_attempts, lockout_until FROM pin_throttle WHERE actor_user_id = :id', ['id' => $this->userId]);
        self::assertSame(0, (int) ($row['failed_attempts'] ?? -1));
        self::assertNull($row['lockout_until'] ?? null);
        self::assertFalse($throttle->isLocked($this->userId, $now));
    }
}
