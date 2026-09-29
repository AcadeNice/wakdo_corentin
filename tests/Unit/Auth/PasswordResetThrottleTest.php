<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use App\Auth\PasswordResetThrottle;
use App\Core\Config;
use App\Tests\Support\FakeDatabase;

/**
 * Throttle de POST /forgot_password (defaut releve : 30 demandes consecutives
 * pour la meme adresse passaient toutes) avec un FakeDatabase. Verrouille les
 * deux invariants : (1) les DEUX dimensions (adresse ET IP) sont orthogonales --
 * verrouiller l'une ne verrouille pas l'autre -- et (2) une demande est
 * enregistree pour les deux, dans UNE transaction, que l'adresse existe ou non
 * (anti-enumeration : PasswordResetThrottle ne sait meme pas si l'adresse
 * resout vers un compte, seul PasswordResetService le sait).
 */
final class PasswordResetThrottleTest extends TestCase
{
    /** @var list<string> */
    private array $touchedKeys = [];

    private FakeDatabase $db;

    protected function setUp(): void
    {
        $this->setEnv('PASSWORD_RESET_EMAIL_THROTTLE_THRESHOLD', '5');
        $this->setEnv('PASSWORD_RESET_IP_THROTTLE_THRESHOLD', '15');
        $this->setEnv('PASSWORD_RESET_THROTTLE_BASE_SECONDS', '60');
        $this->setEnv('PASSWORD_RESET_THROTTLE_MAX_SECONDS', '3600');
        $this->setEnv('PASSWORD_RESET_THROTTLE_WINDOW_SECONDS', '3600');

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

    private function throttle(): PasswordResetThrottle
    {
        return new PasswordResetThrottle($this->db, new Config());
    }

    /**
     * @return list<array{sql: string, params: array<string|int, mixed>}>
     */
    private function findAll(string $needle): array
    {
        $matches = [];
        foreach ($this->db->writes as $write) {
            if (str_contains($write['sql'], $needle)) {
                $matches[] = $write;
            }
        }

        return $matches;
    }

    public function testIsBlockedFalseWhenNeitherDimensionIsLocked(): void
    {
        $this->db->passwordResetEmailLockoutUntil = null;
        $this->db->passwordResetIpLockoutUntil = null;

        self::assertFalse($this->throttle()->isBlocked('a@wakdo.local', '203.0.113.1', 1_000_000));
    }

    public function testIsBlockedTrueWhenEmailDimensionIsLocked(): void
    {
        $this->db->passwordResetEmailLockoutUntil = date('Y-m-d H:i:s', 1_000_000 + 60);
        $this->db->passwordResetIpLockoutUntil = null;

        self::assertTrue($this->throttle()->isBlocked('a@wakdo.local', '203.0.113.1', 1_000_000));
    }

    public function testIsBlockedTrueWhenIpDimensionIsLocked(): void
    {
        $this->db->passwordResetEmailLockoutUntil = null;
        $this->db->passwordResetIpLockoutUntil = date('Y-m-d H:i:s', 1_000_000 + 60);

        self::assertTrue($this->throttle()->isBlocked('a@wakdo.local', '203.0.113.1', 1_000_000));
    }

    public function testIsBlockedFalseWhenLockoutIsInThePast(): void
    {
        $this->db->passwordResetEmailLockoutUntil = date('Y-m-d H:i:s', 1_000_000 - 1);
        $this->db->passwordResetIpLockoutUntil = date('Y-m-d H:i:s', 1_000_000 - 1);

        self::assertFalse($this->throttle()->isBlocked('a@wakdo.local', '203.0.113.1', 1_000_000));
    }

    public function testEmailIdentifierIsCaseAndSpaceNormalized(): void
    {
        $this->db->passwordResetEmailLockoutUntil = date('Y-m-d H:i:s', 1_000_000 + 60);

        // La lecture du verrou email doit porter sur l'adresse normalisee : le
        // FakeDatabase route par 'kind' (pas par l'identifiant precis), donc ce
        // test verifie que la lecture ARRIVE bien (kind = email), pas la valeur
        // exacte lue -- la normalisation elle-meme est verifiee par l'ecriture
        // ci-dessous (testRecordAttemptNormalizesEmailIdentifierBeforeHashing).
        self::assertTrue($this->throttle()->isBlocked(' Manager@Wakdo.Local ', '203.0.113.1', 1_000_000));
    }

    /**
     * Minimisation (RGPD art. 5.1.c) : l'identifiant ecrit pour la dimension
     * email n'est plus l'adresse en clair mais son empreinte SHA-256, calculee
     * sur l'adresse NORMALISEE (casse/espaces) -- deux ecritures de la meme
     * adresse sous des formes differentes partagent donc la meme empreinte.
     */
    public function testRecordAttemptNormalizesEmailIdentifierBeforeHashing(): void
    {
        $this->throttle()->recordAttempt(' Manager@Wakdo.Local ', '203.0.113.1', 1_000_000);

        $upsert = $this->findAll('INSERT INTO password_reset_throttle');
        $emailUpsert = null;
        foreach ($upsert as $write) {
            if (($write['params']['kind'] ?? null) === 'email') {
                $emailUpsert = $write;
            }
        }
        self::assertNotNull($emailUpsert);
        $expectedHash = hash('sha256', 'manager@wakdo.local');
        self::assertSame($expectedHash, $emailUpsert['params']['id'] ?? null);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) ($emailUpsert['params']['id'] ?? ''));
        self::assertStringNotContainsStringIgnoringCase('wakdo.local', (string) ($emailUpsert['params']['id'] ?? ''));
    }

    public function testDifferentEmailCasingHashesToTheSameIdentifier(): void
    {
        $this->throttle()->recordAttempt('Manager@Wakdo.Local', '203.0.113.1', 1_000_000);
        $this->throttle()->recordAttempt(' manager@wakdo.local ', '203.0.113.1', 1_000_000);

        $upserts = array_values(array_filter(
            $this->findAll('INSERT INTO password_reset_throttle'),
            static fn (array $w): bool => ($w['params']['kind'] ?? null) === 'email',
        ));
        self::assertCount(2, $upserts);
        self::assertSame($upserts[0]['params']['id'], $upserts[1]['params']['id']);
    }

    public function testRecordAttemptWritesBothDimensionsInOneTransaction(): void
    {
        $this->db->passwordResetEmailAttempts = 5;  // au seuil email -> verrou
        $this->db->passwordResetIpAttempts = 1;     // sous le seuil IP -> pas de verrou

        $this->throttle()->recordAttempt('a@wakdo.local', '203.0.113.1', 1_000_000);

        self::assertSame(['begin', 'commit'], $this->db->transactionEvents);

        $upserts = $this->findAll('INSERT INTO password_reset_throttle');
        self::assertCount(2, $upserts, 'les deux dimensions doivent etre enregistrees');
        $kinds = array_map(static fn (array $w): mixed => $w['params']['kind'] ?? null, $upserts);
        sort($kinds);
        self::assertSame(['email', 'ip'], $kinds);

        $locks = $this->findAll('UPDATE password_reset_throttle SET lockout_until');
        self::assertCount(2, $locks);

        $emailLock = null;
        $ipLock = null;
        foreach ($locks as $lock) {
            if (($lock['params']['kind'] ?? null) === 'email') {
                $emailLock = $lock;
            }
            if (($lock['params']['kind'] ?? null) === 'ip') {
                $ipLock = $lock;
            }
        }
        self::assertNotNull($emailLock);
        self::assertNotNull($ipLock);
        self::assertSame(date('Y-m-d H:i:s', 1_000_000 + 60), $emailLock['params']['lock'] ?? null, 'email au seuil (5) -> verrou pose');
        self::assertArrayHasKey('lock', $ipLock['params']);
        self::assertNull($ipLock['params']['lock'], 'IP sous son seuil (15) -> pas de verrou');
    }

    public function testRecordAttemptDoesNotTouchLoginOrPinCounters(): void
    {
        $this->throttle()->recordAttempt('a@wakdo.local', '203.0.113.1', 1_000_000);

        foreach ($this->db->writes as $write) {
            self::assertStringNotContainsString('login_throttle', $write['sql']);
            self::assertStringNotContainsString('pin_throttle', $write['sql']);
            self::assertStringNotContainsString('failed_login_attempts', $write['sql']);
            self::assertStringNotContainsString('audit_log', $write['sql']);
        }
    }
}
