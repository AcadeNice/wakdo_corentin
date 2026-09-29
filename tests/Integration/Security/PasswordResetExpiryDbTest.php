<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Auth\PasswordHasher;
use App\Auth\PasswordResetService;
use App\Core\Config;
use App\Core\Database;
use App\Tests\Support\SpyMailer;

/**
 * Lien de reinitialisation du mot de passe contre une VRAIE MariaDB (OWASP ASVS 4.0 V2.5,
 * jeton a duree de vie courte et a usage unique).
 *
 * Complete tests/Unit/Auth/PasswordResetServiceTest.php, qui simule la base
 * (FakeDatabase) : ici, c'est la requete SQL reelle de confirmReset()
 * (`password_reset_expires_at > :now`) qui tranche, avec une horloge INJECTEE ($now) --
 * l'expiration se prouve sans attendre une heure. Le test e2e
 * tests/e2e/security-reset.spec.js prouve l'usage unique de bout en bout ; ce fichier
 * prouve l'expiration, que le e2e ne peut pas attendre en temps reel.
 *
 * Auto-skip hors base (WAKDO_DB_TESTS != 1), comme les autres tests d'integration.
 * Isolation : un utilisateur jetable par test (email .invalid unique), supprime en
 * tearDown avec ses lignes audit_log.
 */
final class PasswordResetExpiryDbTest extends TestCase
{
    private const TTL = 3600;
    private const OLD_PASSWORD = 'AncienAncienAncien1';
    private const NEW_PASSWORD = 'NouveauMotDePasse2';

    private Database $db;
    private Config $config;
    private SpyMailer $mailer;
    private int $userId = 0;
    private string $email = '';
    private ?string $previousTtl = null;

    protected function setUp(): void
    {
        if (getenv('WAKDO_DB_TESTS') !== '1') {
            self::markTestSkipped('Tests DB desactives (definir WAKDO_DB_TESTS=1 + DB_* pour les activer).');
        }

        $previous = getenv('PASSWORD_RESET_TTL');
        $this->previousTtl = $previous === false ? null : $previous;
        putenv('PASSWORD_RESET_TTL=' . self::TTL);

        $this->config = new Config();
        $this->db = new Database($this->config);
        $this->mailer = new SpyMailer();

        try {
            $this->db->fetch('SELECT 1');
        } catch (Throwable $exception) {
            self::markTestSkipped('Base de donnees injoignable: ' . $exception->getMessage());
        }

        $this->createDisposableUser();
    }

    protected function tearDown(): void
    {
        if ($this->previousTtl === null) {
            putenv('PASSWORD_RESET_TTL');
        } else {
            putenv('PASSWORD_RESET_TTL=' . $this->previousTtl);
        }

        if ($this->userId === 0) {
            return;
        }

        $this->db->execute('DELETE FROM audit_log WHERE actor_user_id = :a OR (entity_type = :t AND entity_id = :b)', ['a' => $this->userId, 'b' => $this->userId, 't' => 'user']);
        $this->db->execute('DELETE FROM user WHERE id = :id', ['id' => $this->userId]);
        $this->userId = 0;
    }

    public function testLinkOlderThanTtlIsRefusedAndPasswordIsUnchanged(): void
    {
        $issuedAt = time();
        $token = $this->requestToken($issuedAt);

        $result = $this->service()->confirmReset($token, self::NEW_PASSWORD, $issuedAt + self::TTL + 1);

        self::assertFalse($result->success);
        self::assertSame('Lien invalide ou expiré.', $result->error);
        self::assertTrue($this->passwordIs(self::OLD_PASSWORD), 'le mot de passe ne doit pas changer');
    }

    public function testLinkAtTheExactExpiryInstantIsRefused(): void
    {
        $issuedAt = time();
        $token = $this->requestToken($issuedAt);

        // Comparaison stricte en base (expires_at > now) : a l'instant exact, c'est fini.
        $result = $this->service()->confirmReset($token, self::NEW_PASSWORD, $issuedAt + self::TTL);

        self::assertFalse($result->success);
        self::assertTrue($this->passwordIs(self::OLD_PASSWORD));
    }

    public function testLinkWithinTtlWorksOnceThenIsSpent(): void
    {
        $issuedAt = time();
        $token = $this->requestToken($issuedAt);

        $first = $this->service()->confirmReset($token, self::NEW_PASSWORD, $issuedAt + self::TTL - 1);
        self::assertTrue($first->success);
        self::assertTrue($this->passwordIs(self::NEW_PASSWORD));

        $row = $this->db->fetch('SELECT password_reset_token_hash, password_reset_expires_at FROM user WHERE id = :id', ['id' => $this->userId]);
        self::assertIsArray($row);
        self::assertArrayHasKey('password_reset_token_hash', $row);
        self::assertNull($row['password_reset_token_hash'], 'jeton efface apres usage');
        self::assertNull($row['password_reset_expires_at']);

        $second = $this->service()->confirmReset($token, 'EncoreAutreChose3', $issuedAt + 10);
        self::assertFalse($second->success);
        self::assertTrue($this->passwordIs(self::NEW_PASSWORD), 'un second usage ne change rien');
    }

    public function testNewRequestInvalidatesThePreviousLink(): void
    {
        $issuedAt = time();
        $old = $this->requestToken($issuedAt);
        $new = $this->requestToken($issuedAt + 5);
        self::assertNotSame($old, $new);

        self::assertFalse($this->service()->confirmReset($old, self::NEW_PASSWORD, $issuedAt + 10)->success);
        self::assertTrue($this->service()->confirmReset($new, self::NEW_PASSWORD, $issuedAt + 10)->success);
    }

    public function testOnlyTheHashOfTheTokenIsStored(): void
    {
        $token = $this->requestToken(time());

        $row = $this->db->fetch('SELECT password_reset_token_hash FROM user WHERE id = :id', ['id' => $this->userId]);
        $stored = (string) ($row['password_reset_token_hash'] ?? '');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        self::assertNotSame($token, $stored);
        self::assertSame(hash('sha256', $token), $stored);
    }

    private function service(): PasswordResetService
    {
        return new PasswordResetService($this->db, $this->config, new PasswordHasher($this->config), $this->mailer);
    }

    private function requestToken(int $now): string
    {
        $before = count($this->mailer->sent);
        $this->service()->requestReset($this->email, 'http://admin.wakdo.test', $now);
        self::assertCount($before + 1, $this->mailer->sent, 'un lien envoye');

        $url = $this->mailer->sent[$before]['resetUrl'];
        self::assertSame(1, preg_match('/token=([0-9a-f]+)$/', $url, $m));

        return $m[1];
    }

    private function passwordIs(string $candidate): bool
    {
        $row = $this->db->fetch('SELECT password_hash FROM user WHERE id = :id', ['id' => $this->userId]);

        return (new PasswordHasher($this->config))->verify($candidate, (string) ($row['password_hash'] ?? ''));
    }

    private function createDisposableUser(): void
    {
        $roleRow = $this->db->fetch('SELECT id FROM role ORDER BY id LIMIT 1');
        $roleId = (int) ($roleRow['id'] ?? 0);
        self::assertGreaterThan(0, $roleId, 'aucun role seede: migration/seed requis');

        $this->db->execute(
            'INSERT INTO user (email, password_hash, first_name, last_name, role_id, is_active) '
            . 'VALUES (:email, :hash, :fn, :ln, :role, 1)',
            [
                'email' => 'it-reset-pending-' . bin2hex(random_bytes(6)) . '@wakdo.invalid',
                'hash' => (new PasswordHasher($this->config))->hash(self::OLD_PASSWORD),
                'fn' => 'Integration',
                'ln' => 'Reset',
                'role' => $roleId,
            ],
        );

        $row = $this->db->fetch('SELECT LAST_INSERT_ID() AS id');
        $this->userId = (int) ($row['id'] ?? 0);
        $this->email = 'it-reset-' . $this->userId . '@wakdo.invalid';
        $this->db->execute('UPDATE user SET email = :email WHERE id = :id', ['email' => $this->email, 'id' => $this->userId]);
    }
}
