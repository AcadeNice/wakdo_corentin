<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use App\Auth\PasswordHasher;
use App\Auth\PasswordResetService;
use App\Core\Config;
use App\Tests\Support\FakeDatabase;
use App\Tests\Support\SpyMailer;

/**
 * RESET_PASSWORD (mlt.md 12.3) : neutralite anti-enumeration, token CSPRNG hashe
 * au repos, usage unique, confirmation transactionnelle. FakeDatabase + SpyMailer.
 */
final class PasswordResetServiceTest extends TestCase
{
    private const NOW = 1_700_000_000;

    /** @var list<string> */
    private array $touchedKeys = [];

    private FakeDatabase $db;
    private SpyMailer $mailer;
    private PasswordHasher $hasher;

    protected function setUp(): void
    {
        $this->setEnv('PASSWORD_RESET_TTL', '3600');
        $this->setEnv('ARGON2_MEMORY_COST', '1024');
        $this->setEnv('ARGON2_TIME_COST', '1');
        $this->setEnv('ARGON2_THREADS', '1');

        $this->db = new FakeDatabase();
        $this->mailer = new SpyMailer();
        $this->hasher = new PasswordHasher(new Config());
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

    private function service(): PasswordResetService
    {
        return new PasswordResetService($this->db, new Config(), $this->hasher, $this->mailer);
    }

    public function testRequestUnknownEmailWritesNothingAndSendsNoMail(): void
    {
        $this->db->emailLookupRow = null;

        $this->service()->requestReset('ghost@wakdo.local', 'https://admin.wakdo.test', self::NOW);

        self::assertSame([], $this->db->writes);
        self::assertSame([], $this->mailer->sent);
    }

    public function testRequestActiveUserStoresHashAndMailsRawTokenOnce(): void
    {
        $this->db->emailLookupRow = ['id' => 7];

        $this->service()->requestReset('admin@wakdo.local', 'https://admin.wakdo.test', self::NOW);

        self::assertCount(1, $this->mailer->sent);
        $url = $this->mailer->sent[0]['resetUrl'];
        self::assertStringStartsWith('https://admin.wakdo.test/reset_password?token=', $url);

        $query = (string) parse_url($url, PHP_URL_QUERY);
        parse_str($query, $parsed);
        $rawToken = is_string($parsed['token'] ?? null) ? $parsed['token'] : '';
        self::assertSame(64, strlen($rawToken));

        $write = $this->firstWrite('password_reset_token_hash = :hash');
        $storedHash = $write['params']['hash'] ?? null;
        // Le brut n'est jamais persiste : ce qui est stocke est son SHA-256.
        self::assertSame(hash('sha256', $rawToken), $storedHash);
        self::assertNotSame($rawToken, $storedHash);
        self::assertSame(date('Y-m-d H:i:s', self::NOW + 3600), $write['params']['exp'] ?? null);
    }

    public function testConfirmShortPasswordIsRejectedBeforeAnyWrite(): void
    {
        $this->db->resetUserRow = ['id' => 7, 'role_id' => 3, 'password_reset_token_hash' => hash('sha256', 'tok')];

        $result = $this->service()->confirmReset('tok', 'short', self::NOW);

        self::assertFalse($result->success);
        self::assertSame([], $this->db->writes);
    }

    public function testConfirmUnknownOrExpiredTokenFails(): void
    {
        // resetUserRow null = aucune ligne (token inconnu, expire, ou deja consomme).
        $this->db->resetUserRow = null;

        $result = $this->service()->confirmReset('whatever', 'newpassword123', self::NOW);

        self::assertFalse($result->success);
        self::assertSame('Lien invalide ou expire.', $result->error);
        self::assertSame([], $this->db->writes);
    }

    public function testConfirmValidTokenResetsPassword(): void
    {
        $raw = 'a-valid-raw-token';
        $this->db->resetUserRow = [
            'id' => 7,
            'role_id' => 3,
            'password_reset_token_hash' => hash('sha256', $raw),
        ];

        $result = $this->service()->confirmReset($raw, 'brandnewpassword', self::NOW);

        self::assertTrue($result->success);
        self::assertSame(7, $result->userId);
        self::assertSame('/login?reset=ok', $result->redirectTo);

        // Nouveau mot de passe argon2id verifiable + token efface (usage unique).
        $write = $this->firstWrite('SET password_hash = :hash');
        $newHash = $write['params']['hash'] ?? '';
        self::assertIsString($newHash);
        self::assertTrue($this->hasher->verify('brandnewpassword', $newHash));
        self::assertStringContainsString('password_reset_token_hash = NULL', $write['sql']);

        self::assertSame(['auth.password_reset'], $this->db->auditActions());
        self::assertSame(['begin', 'commit'], $this->db->transactionEvents);
    }

    /**
     * @return array{sql: string, params: array<string|int, mixed>}
     */
    private function firstWrite(string $needle): array
    {
        foreach ($this->db->writes as $write) {
            if (str_contains($write['sql'], $needle)) {
                return $write;
            }
        }

        self::fail('aucune ecriture ne contient: ' . $needle);
    }
}
