<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use App\Auth\AuthService;
use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\SessionManager;
use App\Core\Config;
use App\Tests\Support\FakeDatabase;

/**
 * Branches de securite d'AUTHENTICATE_USER (mlt.md 12.1) testees avec un
 * FakeDatabase (aucune base), un vrai PasswordHasher a cout reduit et une
 * session en mode test. Le temps est fige via le parametre $now.
 */
final class AuthServiceTest extends TestCase
{
    private const NOW = 1_700_000_000;

    /** @var list<string> */
    private array $touchedKeys = [];

    private FakeDatabase $db;
    private SessionManager $session;
    private PasswordHasher $hasher;

    protected function setUp(): void
    {
        // Politique de throttling deterministe + argon2id a cout reduit.
        $this->setEnv('ACCOUNT_LOCKOUT_THRESHOLD', '5');
        $this->setEnv('ACCOUNT_LOCKOUT_BASE_SECONDS', '60');
        $this->setEnv('ACCOUNT_LOCKOUT_MAX_SECONDS', '900');
        $this->setEnv('IP_THROTTLE_MAX_ATTEMPTS', '20');
        $this->setEnv('IP_THROTTLE_WINDOW_SECONDS', '900');
        $this->setEnv('ARGON2_MEMORY_COST', '1024');
        $this->setEnv('ARGON2_TIME_COST', '1');
        $this->setEnv('ARGON2_THREADS', '1');

        $this->db = new FakeDatabase();
        $this->session = new SessionManager(new Config(), true);
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

    private function service(): AuthService
    {
        return new AuthService($this->db, new Config(), $this->session, $this->hasher);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function userRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 7,
            'password_hash' => $this->hasher->hash('correct horse'),
            'role_id' => 3,
            'failed_login_attempts' => 0,
            'lockout_until' => null,
            'default_route' => '/admin/dashboard',
        ], $overrides);
    }

    public function testUnknownEmailFailsAndRecordsIpFailure(): void
    {
        $this->db->userRow = null;

        $result = $this->service()->authenticate('ghost@wakdo.local', 'whatever', '203.0.113.1', self::NOW);

        self::assertFalse($result->success);
        self::assertSame('Email ou mot de passe incorrect', $result->error);
        self::assertNull($this->session->getInt('user_id'));
        self::assertTrue($this->db->wrote('INSERT INTO login_throttle'));
        self::assertSame(['auth.login_failed'], $this->db->auditActions());
        self::assertSame(['begin', 'commit'], $this->db->transactionEvents);
        // Anti-enumeration : meme profil d'I/O que le chemin email connu, via un
        // UPDATE user no-op sur id = 0 (ne touche aucune ligne, ne revele rien).
        self::assertTrue($this->db->wrote('UPDATE user SET failed_login_attempts'));
        self::assertSame(0, $this->firstWrite('UPDATE user SET failed_login_attempts')['params']['id'] ?? null);
    }

    public function testFailureWriteProfileIsIdenticalForKnownAndUnknownEmail(): void
    {
        // Email inconnu.
        $this->db->userRow = null;
        $this->service()->authenticate('ghost@wakdo.local', 'whatever', '203.0.113.9', self::NOW);
        $unknownWrites = count($this->db->writes);

        // Email connu, mauvais mot de passe (instances neuves pour isoler le compteur).
        $db2 = new FakeDatabase();
        $db2->userRow = $this->userRow();
        $service2 = new AuthService($db2, new Config(), new SessionManager(new Config(), true), $this->hasher);
        $service2->authenticate('admin@wakdo.local', 'WRONG', '203.0.113.9', self::NOW);
        $knownWrites = count($db2->writes);

        self::assertSame($knownWrites, $unknownWrites, 'meme nombre d ecritures (anti-enumeration)');
    }

    public function testAccountLockedIsRejectedBeforeAnyWrite(): void
    {
        // lockout_until dans le futur : porte PRE-3, aucun increment ni ecriture.
        $this->db->userRow = $this->userRow([
            'lockout_until' => date('Y-m-d H:i:s', self::NOW + 120),
        ]);

        $result = $this->service()->authenticate('admin@wakdo.local', 'correct horse', '203.0.113.1', self::NOW);

        self::assertFalse($result->success);
        self::assertSame([], $this->db->writes);
        self::assertSame([], $this->db->transactionEvents);
        self::assertNull($this->session->getInt('user_id'));
    }

    public function testIpLockedIsRejectedBeforeAnyWrite(): void
    {
        $this->db->userRow = $this->userRow();
        $this->db->ipLockoutUntil = date('Y-m-d H:i:s', self::NOW + 300);

        $result = $this->service()->authenticate('admin@wakdo.local', 'correct horse', '203.0.113.1', self::NOW);

        self::assertFalse($result->success);
        self::assertSame([], $this->db->writes);
        self::assertNull($this->session->getInt('user_id'));
    }

    public function testWrongPasswordRecordsAccountAndIpFailure(): void
    {
        $this->db->userRow = $this->userRow(['failed_login_attempts' => 0]);

        $result = $this->service()->authenticate('admin@wakdo.local', 'WRONG', '203.0.113.1', self::NOW);

        self::assertFalse($result->success);
        self::assertTrue($this->db->wrote('UPDATE user SET failed_login_attempts'));
        self::assertTrue($this->db->wrote('INSERT INTO login_throttle'));
        self::assertSame(['auth.login_failed'], $this->db->auditActions());
        self::assertSame(['begin', 'commit'], $this->db->transactionEvents);
        self::assertNull($this->session->getInt('user_id'));
    }

    public function testWrongPasswordSetsLockoutOnceThresholdReached(): void
    {
        // 4 echecs deja enregistres : le 5e (= seuil) doit poser un lockout_until.
        $this->db->userRow = $this->userRow(['failed_login_attempts' => 4]);

        $this->service()->authenticate('admin@wakdo.local', 'WRONG', '203.0.113.1', self::NOW);

        $userUpdate = $this->firstWrite('UPDATE user SET failed_login_attempts');
        self::assertSame(5, $userUpdate['params']['attempts'] ?? null);
        self::assertSame(date('Y-m-d H:i:s', self::NOW + 60), $userUpdate['params']['lock'] ?? null);
    }

    public function testWrongPasswordBelowThresholdLeavesLockoutNull(): void
    {
        $this->db->userRow = $this->userRow(['failed_login_attempts' => 0]);

        $this->service()->authenticate('admin@wakdo.local', 'WRONG', '203.0.113.1', self::NOW);

        $userUpdate = $this->firstWrite('UPDATE user SET failed_login_attempts');
        self::assertSame(1, $userUpdate['params']['attempts'] ?? null);
        self::assertArrayHasKey('lock', $userUpdate['params']);
        self::assertNull($userUpdate['params']['lock']);
    }

    public function testIpUpsertUsesAtomicIncrementAndSqlWindowReset(): void
    {
        $this->db->userRow = $this->userRow(['failed_login_attempts' => 0]);

        $this->service()->authenticate('admin@wakdo.local', 'WRONG', '203.0.113.1', self::NOW);

        $upsert = $this->firstWrite('INSERT INTO login_throttle');
        // Increment atomique cote SQL (pas un literal PHP) -> immunise au lost-update.
        self::assertStringContainsString('failed_attempts + 1', $upsert['sql']);
        // Reset de fenetre decide en SQL, borne stricte sur window_started_at.
        self::assertStringContainsString('IF(window_started_at < :cutoff', $upsert['sql']);
    }

    public function testIpThrottleSetsLockWhenThresholdReached(): void
    {
        // La relecture post-upsert renvoie 20 (= IP_THROTTLE_MAX_ATTEMPTS) : verrou pose.
        $this->db->userRow = $this->userRow(['failed_login_attempts' => 0]);
        $this->db->throttleRow = ['failed_attempts' => 20];

        $this->service()->authenticate('admin@wakdo.local', 'WRONG', '203.0.113.1', self::NOW);

        $lockWrite = $this->firstWrite('UPDATE login_throttle SET lockout_until = :lock');
        self::assertSame(date('Y-m-d H:i:s', self::NOW + 60), $lockWrite['params']['lock'] ?? null);
    }

    public function testIpThrottleLeavesLockNullBelowThreshold(): void
    {
        $this->db->userRow = $this->userRow(['failed_login_attempts' => 0]);
        $this->db->throttleRow = ['failed_attempts' => 3];

        $this->service()->authenticate('admin@wakdo.local', 'WRONG', '203.0.113.1', self::NOW);

        $lockWrite = $this->firstWrite('UPDATE login_throttle SET lockout_until = :lock');
        self::assertArrayHasKey('lock', $lockWrite['params']);
        self::assertNull($lockWrite['params']['lock']);
    }

    public function testCorrectCredentialsSucceedAndOpenSession(): void
    {
        $this->db->userRow = $this->userRow();

        $result = $this->service()->authenticate('admin@wakdo.local', 'correct horse', '203.0.113.1', self::NOW);

        self::assertTrue($result->success);
        self::assertSame(7, $result->userId);
        self::assertSame(3, $result->roleId);
        self::assertSame('/admin/dashboard', $result->redirectTo);

        self::assertSame(7, $this->session->getInt('user_id'));
        self::assertSame(3, $this->session->getInt('role_id'));
        self::assertSame(self::NOW, $this->session->getInt('logged_in_at'));
        self::assertSame(self::NOW, $this->session->getInt('last_activity'));

        // RG-5/RG-9 : reset compteur + clear throttle + audit succes, 1 transaction.
        self::assertTrue($this->db->wrote('UPDATE user SET failed_login_attempts = 0'));
        self::assertTrue($this->db->wrote('UPDATE login_throttle SET failed_attempts = 0'));
        self::assertSame(['auth.login_success'], $this->db->auditActions());
        self::assertSame(['begin', 'commit'], $this->db->transactionEvents);

        // RG-5 : last_login_at pose a l'instant fige (assertion explicite, pas
        // seulement le prefixe de la requete).
        self::assertSame(date('Y-m-d H:i:s', self::NOW), $this->firstWrite('last_login_at')['params']['now'] ?? null);
    }

    public function testSuccessRotatesCsrfToken(): void
    {
        $this->db->userRow = $this->userRow();
        $before = Csrf::token($this->session);

        $this->service()->authenticate('admin@wakdo.local', 'correct horse', '203.0.113.1', self::NOW);

        self::assertFalse(Csrf::validate($this->session, $before));
    }

    public function testFailClosedWhenDatabaseThrowsOnFailurePath(): void
    {
        $this->db->userRow = $this->userRow();
        $this->db->failOnExecute = new RuntimeException('db down');

        $threw = false;
        try {
            $this->service()->authenticate('admin@wakdo.local', 'WRONG', '203.0.113.1', self::NOW);
        } catch (RuntimeException) {
            $threw = true;
        }

        self::assertTrue($threw, 'une panne DB doit remonter, pas etre avalee');
        self::assertSame(['begin', 'rollback'], $this->db->transactionEvents);
        self::assertNull($this->session->getInt('user_id'));
    }

    public function testFailClosedOnSuccessPathDoesNotOpenSession(): void
    {
        // Mot de passe correct mais la base echoue pendant recordSuccess :
        // l'identite ne doit jamais etre posee en session (ecriture avant identite).
        $this->db->userRow = $this->userRow();
        $this->db->failOnExecute = new RuntimeException('db down');

        $threw = false;
        try {
            $this->service()->authenticate('admin@wakdo.local', 'correct horse', '203.0.113.1', self::NOW);
        } catch (RuntimeException) {
            $threw = true;
        }

        self::assertTrue($threw);
        self::assertNull($this->session->getInt('user_id'));
    }

    public function testLogoutClearsSession(): void
    {
        $this->session->set('user_id', 7);

        $this->service()->logout();

        self::assertNull($this->session->getInt('user_id'));
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
