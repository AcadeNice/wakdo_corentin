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
use App\Tests\Support\SpyPasswordHasher;

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

    /**
     * Coeur du correctif de calibrage (relecture adverse) : sur email inconnu,
     * le leurre doit etre verifie contre un hash REELLEMENT STOCKE (peu
     * importe lequel), PAS contre un hash calibre sur options() (la
     * configuration) -- c'est le hash stocke qui dicte le cout REEL d'un
     * password_verify(), pas la configuration courante (mesure : 256 ms reel
     * contre 99 ms leurre calibre sur l'environnement, cache par ailleurs
     * sain, sans aucune panne).
     */
    public function testUnknownEmailCalibratesDecoyOnAStoredReferenceHash(): void
    {
        $this->db->userRow = null;
        $this->db->referenceUserPasswordHash = $this->hasher->hash('some other account password');
        $spy = new SpyPasswordHasher(new Config());
        $service = new AuthService($this->db, new Config(), $this->session, $spy);

        $service->authenticate('ghost@wakdo.local', 'whatever', '203.0.113.1', self::NOW);

        self::assertSame(1, $spy->verifyDecoyCalls);
        self::assertSame([$this->db->referenceUserPasswordHash], $spy->verifyDecoyReferenceHashes);
    }

    /**
     * Base fraiche sans aucun utilisateur (borne de demarrage, pas un cas
     * operationnel normal) : aucun hash de reference disponible ->
     * verifyDecoy() recoit explicitement null, et se rabat alors sur son
     * propre mecanisme (PasswordHasher::decoyHash()).
     */
    public function testUnknownEmailPassesNullReferenceWhenNoUserExistsAtAll(): void
    {
        $this->db->userRow = null;
        $this->db->referenceUserPasswordHash = null;
        $spy = new SpyPasswordHasher(new Config());
        $service = new AuthService($this->db, new Config(), $this->session, $spy);

        $service->authenticate('ghost@wakdo.local', 'whatever', '203.0.113.1', self::NOW);

        self::assertSame([null], $spy->verifyDecoyReferenceHashes);
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

    /**
     * REGLE : sur un compte verrouille, ce chemin fait EXACTEMENT le meme
     * travail que "email inconnu" (testUnknownEmailFailsAndRecordsIpFailure
     * ci-dessus) -- meme appel a verifyDecoy(), meme ecriture IP, compteur DU
     * COMPTE inchange (UPDATE no-op sur id=0). Sans cette regle, le compteur IP
     * n'avance pas sur un compte deja verrouille alors qu'il continue d'avancer
     * pour un email inconnu : un compte existant devient distinguable d'un
     * email inconnu par le nombre de requetes avant le premier 429, et par le
     * temps de reponse (verifyDecoy() non appele = reponse quasi immediate).
     */
    public function testAccountLockedCallsDecoyAndRecordsIpFailureLikeUnknownEmail(): void
    {
        $this->db->userRow = $this->userRow([
            'lockout_until' => date('Y-m-d H:i:s', self::NOW + 120),
        ]);
        $spy = new SpyPasswordHasher(new Config());
        $service = new AuthService($this->db, new Config(), $this->session, $spy);

        $result = $service->authenticate('admin@wakdo.local', 'whatever', '203.0.113.1', self::NOW);

        self::assertFalse($result->success);
        self::assertNull($this->session->getInt('user_id'));
        // Anti-enumeration (RG-2/ERR-3) : le verrou COMPTE reste indiscernable
        // d'un mot de passe faux -- aucun retryAfterSeconds, donc un consommateur
        // JSON le mappe en 401 INVALID_CREDENTIALS, pas en 429.
        self::assertNull($result->retryAfterSeconds);

        // Le leurre est bien appele (pas de verification chronometree instable
        // ici : compte des appels via l'espion, voir PasswordHasher::decoyHash()
        // pour le mecanisme qui rend ce leurre reellement du meme cout qu'une
        // verification reelle).
        self::assertSame(1, $spy->verifyDecoyCalls);

        // Le compteur IP progresse EXACTEMENT comme pour un email inconnu.
        self::assertTrue($this->db->wrote('INSERT INTO login_throttle'));
        self::assertSame(['auth.login_failed'], $this->db->auditActions());
        self::assertSame(['begin', 'commit'], $this->db->transactionEvents);
        // Le compteur DU COMPTE reste un UPDATE no-op sur id=0 (pas l'id reel
        // du compte verrouille) : aucune trace nominative de cette tentative.
        self::assertTrue($this->db->wrote('UPDATE user SET failed_login_attempts'));
        self::assertSame(0, $this->firstWrite('UPDATE user SET failed_login_attempts')['params']['id'] ?? null);
    }

    /**
     * Coeur du correctif de calibrage (relecture adverse), pour le chemin
     * "compte verrouille" : le leurre est calibre sur le hash STOCKE de CE
     * compte precis (deja en main via le SELECT RG-1, aucune requete de plus)
     * -- pas sur un compte quelconque, pas sur options(). C'est le hash de CE
     * compte qui dicte le cout REEL d'une verification contre lui une fois
     * deverrouille.
     */
    public function testAccountLockedCalibratesDecoyOnItsOwnStoredHash(): void
    {
        $lockedHash = $this->hasher->hash('the real locked account password');
        $this->db->userRow = $this->userRow([
            'lockout_until' => date('Y-m-d H:i:s', self::NOW + 120),
            'password_hash' => $lockedHash,
        ]);
        $spy = new SpyPasswordHasher(new Config());
        $service = new AuthService($this->db, new Config(), $this->session, $spy);

        $service->authenticate('admin@wakdo.local', 'whatever', '203.0.113.1', self::NOW);

        self::assertSame([$lockedHash], $spy->verifyDecoyReferenceHashes);
    }

    /**
     * Preuve structurelle de l'equivalence : "compte verrouille" et "email
     * inconnu" produisent le MEME nombre d'ecritures, avec le MEME gabarit SQL
     * a chaque rang (les parametres peuvent differer, ex. l'IP si elle differe,
     * mais pas la requete elle-meme) -- donc rejouer N fois l'un ou l'autre fait
     * progresser le compteur IP IDENTIQUEMENT, jusqu'au meme seuil de 429
     * (verifie en conditions reelles par la sonde rejouee, cf. rapport E2E du
     * commit).
     */
    public function testAccountLockedProducesSameWriteShapeAsUnknownEmail(): void
    {
        $lockedDb = new FakeDatabase();
        $lockedDb->userRow = $this->userRow([
            'lockout_until' => date('Y-m-d H:i:s', self::NOW + 120),
        ]);
        $unknownDb = new FakeDatabase();
        $unknownDb->userRow = null;

        $lockedService = new AuthService($lockedDb, new Config(), new SessionManager(new Config(), true), $this->hasher);
        $unknownService = new AuthService($unknownDb, new Config(), new SessionManager(new Config(), true), $this->hasher);

        $lockedService->authenticate('admin@wakdo.local', 'whatever', '203.0.113.9', self::NOW);
        $unknownService->authenticate('ghost@wakdo.local', 'whatever', '203.0.113.9', self::NOW);

        self::assertSame(count($unknownDb->writes), count($lockedDb->writes), 'meme nombre d ecritures sur les deux chemins');
        foreach ($unknownDb->writes as $i => $write) {
            self::assertSame($write['sql'], $lockedDb->writes[$i]['sql'], "ecriture #$i : meme gabarit SQL sur les deux chemins");
        }
        self::assertSame($unknownDb->transactionEvents, $lockedDb->transactionEvents);
    }

    public function testIpLockedIsRejectedBeforeAnyWrite(): void
    {
        $this->db->userRow = $this->userRow();
        $this->db->ipLockoutUntil = date('Y-m-d H:i:s', self::NOW + 300);

        $result = $this->service()->authenticate('admin@wakdo.local', 'correct horse', '203.0.113.1', self::NOW);

        self::assertFalse($result->success);
        self::assertSame([], $this->db->writes);
        self::assertNull($this->session->getInt('user_id'));
        // Le verrou IP, lui, ne depend pas de l'email tente : l'exposer via
        // retryAfterSeconds ne revele rien sur un compte precis (cf. AuthResult).
        self::assertSame(300, $result->retryAfterSeconds);
    }

    public function testIpLockedTakesPriorityOverAccountLockForRetryAfter(): void
    {
        // Les deux verrous sont actifs a la fois : le verrou IP (sur qui l'expose
        // sans risque) doit l'emporter, pas le verrou compte (silencieux).
        $this->db->userRow = $this->userRow([
            'lockout_until' => date('Y-m-d H:i:s', self::NOW + 120),
        ]);
        $this->db->ipLockoutUntil = date('Y-m-d H:i:s', self::NOW + 300);

        $result = $this->service()->authenticate('admin@wakdo.local', 'correct horse', '203.0.113.1', self::NOW);

        self::assertFalse($result->success);
        self::assertSame(300, $result->retryAfterSeconds);
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

    /**
     * Espion : SessionManager::regenerate() ne fait RIEN d'observable en mode test (pas
     * de session PHP reelle), donc rien ne cassait auparavant si l'appel a
     * regenerate() etait retire d'authenticate() -- perte silencieuse de RG-3
     * (anti-fixation de session). regenerateCallCount() est un compteur pose
     * SANS EFFET sur le comportement de production (cf. SessionManager), le
     * seul espion possible sans sous-classer cette classe `final`.
     */
    public function testSuccessCallsSessionRegenerateForAntiFixation(): void
    {
        $this->db->userRow = $this->userRow();
        self::assertSame(0, $this->session->regenerateCallCount());

        $this->service()->authenticate('admin@wakdo.local', 'correct horse', '203.0.113.1', self::NOW);

        self::assertSame(1, $this->session->regenerateCallCount());
    }

    /**
     * (b) Convergence du parc : un succes de connexion dont le hash stocke ne
     * porte PLUS les options() courantes (ici : cree a un cout different de
     * celui de ce test, 1024/1/1) doit rehacher ce mot de passe -- seul moment
     * ou le mot de passe clair, deja verifie, est disponible. Sans ca, un
     * changement de ARGON2_* laisse les hashes existants a leur ancien cout
     * indefiniment (ce qui est precisement ce qui rouvre l'ecart de calibrage
     * du leurre pour CE compte, tant qu'il n'est pas rehache).
     */
    public function testSuccessRehashesPasswordWhenStoredCostDiffersFromCurrentConfig(): void
    {
        $oldCostHash = password_hash('correct horse', PASSWORD_ARGON2ID, ['memory_cost' => 2048, 'time_cost' => 2, 'threads' => 1]);
        $this->db->userRow = $this->userRow(['password_hash' => $oldCostHash]);

        $this->service()->authenticate('admin@wakdo.local', 'correct horse', '203.0.113.1', self::NOW);

        $write = $this->firstWrite('UPDATE user SET password_hash');
        $newHash = $write['params']['hash'] ?? null;
        self::assertIsString($newHash);
        self::assertNotSame($oldCostHash, $newHash);
        self::assertTrue($this->hasher->verify('correct horse', $newHash), 'le nouveau hash doit rester verifiable avec le MEME mot de passe');
        $info = password_get_info($newHash);
        self::assertSame(1024, $info['options']['memory_cost'] ?? null, 'le nouveau hash doit porter les options() COURANTES (1024/1/1 dans ce test)');
    }

    /**
     * Un hash deja au bon cout (le cas courant) ne doit PAS etre reecrit a
     * chaque connexion reussie -- sans ca, chaque succes couterait un
     * password_hash() de plus (le meme cout coupable que le "recalcul
     * complet" evite ailleurs dans ce fichier) pour un gain nul.
     */
    public function testSuccessDoesNotRehashPasswordWhenStoredCostAlreadyMatches(): void
    {
        $this->db->userRow = $this->userRow();

        $this->service()->authenticate('admin@wakdo.local', 'correct horse', '203.0.113.1', self::NOW);

        self::assertFalse($this->db->wrote('UPDATE user SET password_hash'));
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
