<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PDOException;
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
    private int $counterRoleId = 0;
    private int $adminRoleId = 0;
    /** @var list<int> ids des comptes crees par les tests CRUD (nettoyes par id). */
    private array $createdIds = [];

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

        $this->counterRoleId = (int) ($this->db->fetch("SELECT id FROM role WHERE code = 'counter'")['id'] ?? 0);
        $this->adminRoleId = (int) ($this->db->fetch("SELECT id FROM role WHERE code = 'admin'")['id'] ?? 0);
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
        foreach ($this->createdIds as $id) {
            $this->db->execute('DELETE FROM user WHERE id = :id', ['id' => $id]);
        }
        $this->createdIds = [];
    }

    private function makeUser(UserRepository $repo, string $tag, int $roleId): int
    {
        $id = $repo->create([
            'email'         => 'it-user-' . $tag . '-' . bin2hex(random_bytes(3)) . '@wakdo.test',
            'password_hash' => '$argon2id$placeholder',
            'first_name'    => 'Test',
            'last_name'     => 'User' . $tag,
            'role_id'       => $roleId,
        ]);
        $this->createdIds[] = $id;

        return $id;
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

    public function testCreateFindUpdate(): void
    {
        $repo = new UserRepository($this->db);
        self::assertTrue($repo->activeRoleExists($this->counterRoleId));
        self::assertFalse($repo->activeRoleExists(0));

        $id = $this->makeUser($repo, 'a', $this->counterRoleId);
        self::assertGreaterThan(0, $id);

        $found = $repo->find($id);
        self::assertNotNull($found);
        self::assertSame($this->counterRoleId, (int) $found['role_id']);
        self::assertSame(1, (int) $found['is_active']);
        self::assertTrue($repo->emailExists((string) $found['email']));
        self::assertFalse($repo->emailExists((string) $found['email'], $id)); // s'exclut lui-meme

        $repo->update($id, [
            'email'      => (string) $found['email'],
            'first_name' => 'Renamed',
            'last_name'  => 'Person',
            'role_id'    => $this->adminRoleId,
            'is_active'  => 0,
        ]);
        $updated = $repo->find($id);
        self::assertNotNull($updated);
        self::assertSame('Renamed', (string) $updated['first_name']);
        self::assertSame($this->adminRoleId, (int) $updated['role_id']);
        self::assertSame(0, (int) $updated['is_active']);

        $emails = array_map(static fn (array $r): string => (string) ($r['email'] ?? ''), $repo->all());
        self::assertContains((string) $found['email'], $emails); // all() joint le libelle de role
    }

    public function testDuplicateEmailViolatesUnique(): void
    {
        $repo = new UserRepository($this->db);
        $id = $this->makeUser($repo, 'dup', $this->counterRoleId);
        $email = (string) ($repo->find($id)['email'] ?? '');

        $violated = false;
        try {
            $newId = $repo->create(['email' => $email, 'password_hash' => 'x', 'first_name' => 'D', 'last_name' => 'U', 'role_id' => $this->counterRoleId]);
            $this->createdIds[] = $newId;
        } catch (PDOException $exception) {
            $violated = (string) $exception->getCode() === '23000';
        }
        self::assertTrue($violated, 'uk_user_email doit rejeter un doublon (SQLSTATE 23000).');
    }

    public function testDeactivateThenAnonymiseIsIdempotent(): void
    {
        $repo = new UserRepository($this->db);
        $id = $this->makeUser($repo, 'rgpd', $this->counterRoleId);

        self::assertSame(1, $repo->deactivate($id));
        self::assertSame(0, (int) ($repo->find($id)['is_active'] ?? -1));

        self::assertSame(1, $repo->anonymise($id)); // vide la PII, garde la ligne (tombstone)
        $anon = $repo->find($id);
        self::assertNotNull($anon);
        self::assertSame('', (string) $anon['first_name']);
        self::assertSame('', (string) $anon['last_name']);
        self::assertSame('anon-' . $id . '@wakdo.invalid', (string) $anon['email']);
        self::assertNotNull($anon['anonymized_at']);

        self::assertSame(0, $repo->anonymise($id)); // idempotent : deja anonymise
    }

    public function testActiveAdminCountAndIsAdmin(): void
    {
        $repo = new UserRepository($this->db);
        $before = $repo->activeAdminCount();
        self::assertGreaterThanOrEqual(1, $before); // le seed pose un admin actif

        $adminId = $this->makeUser($repo, 'adm', $this->adminRoleId);
        self::assertSame($before + 1, $repo->activeAdminCount());
        self::assertTrue($repo->isAdmin($adminId));

        $counterId = $this->makeUser($repo, 'cnt', $this->counterRoleId);
        self::assertFalse($repo->isAdmin($counterId));

        $repo->deactivate($adminId);
        self::assertSame($before, $repo->activeAdminCount()); // redescend
    }
}
