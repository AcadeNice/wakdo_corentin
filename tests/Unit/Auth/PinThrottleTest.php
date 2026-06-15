<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use App\Auth\PinThrottle;
use App\Core\Config;
use App\Tests\Support\FakeDatabase;

/**
 * Throttle du PIN d'action sensible (RG-T22) avec un FakeDatabase. Verrouille les
 * deux invariants de securite : (1) la dimension est l'utilisateur AGISSANT, et
 * (2) aucun ecrit ne touche les compteurs de connexion (user / login_throttle) —
 * un echec de PIN ne doit jamais verrouiller une CONNEXION (escalade de DoS).
 */
final class PinThrottleTest extends TestCase
{
    /** @var list<string> */
    private array $touchedKeys = [];

    private FakeDatabase $db;

    protected function setUp(): void
    {
        $this->setEnv('PIN_THROTTLE_THRESHOLD', '5');
        $this->setEnv('PIN_THROTTLE_BASE_SECONDS', '30');
        $this->setEnv('PIN_THROTTLE_MAX_SECONDS', '300');
        $this->setEnv('PIN_THROTTLE_WINDOW_SECONDS', '900');

        $this->db = new FakeDatabase();
    }

    protected function tearDown(): void
    {
        foreach ($this->touchedKeys as $key) {
            putenv($key);
        }
        $this->touchedKeys = [];
    }

    private function setEnv(string $key, string $value): void
    {
        $this->touchedKeys[] = $key;
        putenv($key . '=' . $value);
    }

    private function throttle(): PinThrottle
    {
        return new PinThrottle($this->db, new Config());
    }

    /**
     * @return array{sql: string, params: array<string|int, mixed>}|null
     */
    private function find(string $needle): ?array
    {
        foreach ($this->db->writes as $write) {
            if (str_contains($write['sql'], $needle)) {
                return $write;
            }
        }

        return null;
    }

    private function assertNoLoginCounterTouched(): void
    {
        // Invariant dur RG-T22 : un echec de PIN ne touche JAMAIS les compteurs de
        // connexion. Retirer cette separation ferait virer ce test au rouge.
        foreach ($this->db->writes as $write) {
            self::assertStringNotContainsString('failed_login_attempts', $write['sql']);
            self::assertStringNotContainsString('login_throttle', $write['sql']);
            self::assertStringNotContainsString('audit_log', $write['sql']);
        }
    }

    public function testIsLockedTrueWhenLockoutInFuture(): void
    {
        $now = 1_000_000;
        $this->db->pinThrottleLockoutUntil = date('Y-m-d H:i:s', $now + 60);

        self::assertTrue($this->throttle()->isLocked(9, $now));
    }

    public function testIsLockedFalseWhenNoLockOrPast(): void
    {
        $now = 1_000_000;

        $this->db->pinThrottleLockoutUntil = null;
        self::assertFalse($this->throttle()->isLocked(9, $now));

        $this->db->pinThrottleLockoutUntil = date('Y-m-d H:i:s', $now - 1);
        self::assertFalse($this->throttle()->isLocked(9, $now));
    }

    public function testIsLockedFalseWhenNoActor(): void
    {
        // actorUserId <= 0 (pas de session derriere guard()) : non verrouille, et
        // aucune lecture inutile (defensif).
        self::assertFalse($this->throttle()->isLocked(0));
        self::assertSame([], $this->db->reads);
    }

    public function testRecordFailureOneTransactionUpsertThenLockNoLoginState(): void
    {
        // Au seuil : le compteur relu vaut 5 -> backoff 30s -> verrou pose.
        $this->db->pinThrottleAttempts = 5;

        $this->throttle()->recordFailure(9, 1_000_000);

        // Une seule transaction (RG-T08).
        self::assertSame(['begin', 'commit'], $this->db->transactionEvents);

        $upsert = $this->find('INSERT INTO pin_throttle');
        self::assertNotNull($upsert);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $upsert['sql']);
        self::assertSame(9, $upsert['params']['uid'] ?? null);

        $lock = $this->find('UPDATE pin_throttle SET lockout_until');
        self::assertNotNull($lock);
        self::assertSame(date('Y-m-d H:i:s', 1_000_000 + 30), $lock['params']['lock'] ?? null);
        self::assertSame(9, $lock['params']['uid'] ?? null);

        $this->assertNoLoginCounterTouched();
    }

    public function testRecordFailureBelowThresholdSetsNoLock(): void
    {
        $this->db->pinThrottleAttempts = 1; // sous le seuil 5

        $this->throttle()->recordFailure(9, 1_000_000);

        $lock = $this->find('UPDATE pin_throttle SET lockout_until');
        self::assertNotNull($lock);
        self::assertArrayHasKey('lock', $lock['params']);
        self::assertNull($lock['params']['lock']); // verrou null sous le seuil
        $this->assertNoLoginCounterTouched();
    }

    public function testRecordFailureNoActorIsNoop(): void
    {
        $this->throttle()->recordFailure(0);

        self::assertSame([], $this->db->writes);
        self::assertSame([], $this->db->transactionEvents);
    }

    public function testResetClearsActorCounterNoLoginState(): void
    {
        $this->throttle()->reset(9, 1_000_000);

        $reset = $this->find('UPDATE pin_throttle SET failed_attempts = 0');
        self::assertNotNull($reset);
        self::assertStringContainsString('lockout_until = NULL', $reset['sql']);
        self::assertSame(9, $reset['params']['uid'] ?? null);
        // reset = UPDATE simple, hors transaction propre (inclus dans l'effet controleur).
        self::assertSame([], $this->db->transactionEvents);
        $this->assertNoLoginCounterTouched();
    }

    public function testResetNoActorIsNoop(): void
    {
        $this->throttle()->reset(0);

        self::assertSame([], $this->db->writes);
    }
}
