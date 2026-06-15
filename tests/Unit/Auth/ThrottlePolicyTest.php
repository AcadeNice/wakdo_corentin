<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use App\Auth\ThrottlePolicy;
use App\Core\Config;

/**
 * Le backoff degressif est le calcul de securite le plus delicat de l'auth :
 * on le verrouille par des cas explicites (seuil, doublement, plafond, debordement).
 */
final class ThrottlePolicyTest extends TestCase
{
    /** @var list<string> */
    private array $touchedKeys = [];

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

    private function policy(int $threshold = 5, int $base = 60, int $max = 900): ThrottlePolicy
    {
        return new ThrottlePolicy($threshold, $base, $max);
    }

    public function testNoLockoutBelowThreshold(): void
    {
        $policy = $this->policy();

        self::assertSame(0, $policy->lockoutSeconds(0));
        self::assertSame(0, $policy->lockoutSeconds(4));
    }

    public function testBaseDelayAtThreshold(): void
    {
        self::assertSame(60, $this->policy()->lockoutSeconds(5));
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    public static function degressiveCurveProvider(): array
    {
        // threshold=5, base=60, max=900 : 60, 120, 240, 480, puis plafond 900.
        return [
            [5, 60],
            [6, 120],
            [7, 240],
            [8, 480],
            [9, 900],   // 60*2^4 = 960 -> plafonne a 900
            [10, 900],  // au-dela : reste plafonne
            [20, 900],
        ];
    }

    #[DataProvider('degressiveCurveProvider')]
    public function testDegressiveCurveIsCappedAtMax(int $attempts, int $expected): void
    {
        self::assertSame($expected, $this->policy()->lockoutSeconds($attempts));
    }

    public function testNoIntegerOverflowForHugeAttemptCount(): void
    {
        // Un compteur enorme ne doit jamais deborder en negatif ni lever : on
        // reste plafonne au maximum configure.
        self::assertSame(900, $this->policy()->lockoutSeconds(1000));
        self::assertSame(900, $this->policy()->lockoutSeconds(PHP_INT_MAX));
    }

    public function testIsLockedUntilFutureIsTrue(): void
    {
        $now = 1_000_000;
        $future = date('Y-m-d H:i:s', $now + 120);

        self::assertTrue($this->policy()->isLockedUntil($future, $now));
    }

    public function testIsLockedUntilPastOrNullIsFalse(): void
    {
        $now = 1_000_000;
        $past = date('Y-m-d H:i:s', $now - 1);

        self::assertFalse($this->policy()->isLockedUntil($past, $now));
        self::assertFalse($this->policy()->isLockedUntil(null, $now));
        self::assertFalse($this->policy()->isLockedUntil('', $now));
    }

    public function testIsLockedUntilUnparseableIsFalse(): void
    {
        self::assertFalse($this->policy()->isLockedUntil('not-a-date', 1_000_000));
    }

    public function testFromConfigAccountReadsAccountKeys(): void
    {
        $this->setEnv('ACCOUNT_LOCKOUT_THRESHOLD', '3');
        $this->setEnv('ACCOUNT_LOCKOUT_BASE_SECONDS', '30');
        $this->setEnv('ACCOUNT_LOCKOUT_MAX_SECONDS', '600');

        $policy = ThrottlePolicy::fromConfig(new Config(), 'account');

        self::assertSame(0, $policy->lockoutSeconds(2));
        self::assertSame(30, $policy->lockoutSeconds(3));
        self::assertSame(60, $policy->lockoutSeconds(4));
        self::assertSame(600, $policy->lockoutSeconds(99));
    }

    public function testFromConfigIpUsesIpThresholdWithSharedCurve(): void
    {
        $this->setEnv('IP_THROTTLE_MAX_ATTEMPTS', '20');
        $this->setEnv('ACCOUNT_LOCKOUT_BASE_SECONDS', '60');
        $this->setEnv('ACCOUNT_LOCKOUT_MAX_SECONDS', '900');

        $policy = ThrottlePolicy::fromConfig(new Config(), 'ip');

        self::assertSame(0, $policy->lockoutSeconds(19));
        self::assertSame(60, $policy->lockoutSeconds(20));
        self::assertSame(120, $policy->lockoutSeconds(21));
    }
}
