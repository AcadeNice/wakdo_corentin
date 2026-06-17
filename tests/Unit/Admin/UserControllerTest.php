<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PDOException;
use PHPUnit\Framework\TestCase;
use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\SessionManager;
use App\Controllers\UserController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\Request;
use App\Tests\Support\FakeDatabase;

final class TestUserController extends UserController
{
    public function __construct(
        Request $request,
        Config $config,
        Database $database,
        private readonly SessionManager $testSession,
        private readonly FakeDatabase $fakeDb,
    ) {
        parent::__construct($request, $config, $database);
    }

    protected function sessionManager(): SessionManager
    {
        return $this->testSession;
    }

    protected function db(): DatabaseInterface
    {
        return $this->fakeDb;
    }
}

final class UserControllerTest extends TestCase
{
    /** @var list<string> */
    private array $touchedKeys = [];
    private SessionManager $session;
    private string $csrf = '';

    protected function setUp(): void
    {
        $this->setEnv('SESSION_LIFETIME_IDLE', '14400');
        $this->setEnv('SESSION_LIFETIME_ABSOLUTE', '36000');
        $this->setEnv('STAFF_PIN_MIN_LENGTH', '4');
        $this->setEnv('STAFF_PIN_MAX_LENGTH', '12');
        $this->setEnv('ARGON2_MEMORY_COST', '1024');
        $this->setEnv('ARGON2_TIME_COST', '1');
        $this->setEnv('ARGON2_THREADS', '1');

        $this->session = new SessionManager(new Config(), true);
        $now = time();
        $this->session->set('user_id', 1);   // acteur de session = id 1 (admin)
        $this->session->set('role_id', 1);
        $this->session->set('logged_in_at', $now - 100);
        $this->session->set('last_activity', $now - 50);
        $this->csrf = Csrf::token($this->session);
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

    private function permittedDb(): FakeDatabase
    {
        $db = new FakeDatabase();
        $db->guardUserRow = ['is_active' => 1];
        $db->userDisplayRow = ['first_name' => 'Cor', 'last_name' => 'J', 'role_label' => 'Administrateur'];
        $db->canResult = true;
        $db->permissionCodes = ['user.read', 'user.create', 'user.update', 'user.deactivate'];
        $db->roleActiveExists = true;
        $db->rolesRows = [['id' => 4, 'label' => 'Counter Staff']];

        return $db;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function target(array $overrides = []): array
    {
        return array_merge([
            'id' => 5, 'email' => 'staff@wakdo.local', 'first_name' => 'Sam', 'last_name' => 'Staff',
            'role_id' => 4, 'is_active' => 1, 'anonymized_at' => null,
        ], $overrides);
    }

    private function actingPin(FakeDatabase $db): void
    {
        $db->actingUserRow = ['id' => 9, 'role_id' => 4, 'pin_hash' => (new PasswordHasher(new Config()))->hash('4729')];
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function createForm(array $overrides = []): array
    {
        return array_merge([
            '_csrf' => $this->csrf,
            'email' => 'new@wakdo.local',
            'first_name' => 'New',
            'last_name' => 'Hire',
            'role_id' => '4',
            'password' => 'motdepasse8',
            'pin_email' => 'sam@wakdo.local',
            'pin' => '4729',
        ], $overrides);
    }

    private function get(string $path): Request
    {
        return new Request('GET', $path, [], [], '', '203.0.113.5');
    }

    /**
     * @param array<string, string> $form
     */
    private function post(array $form, string $path): Request
    {
        return new Request('POST', $path, [], ['content-type' => 'application/x-www-form-urlencoded'], http_build_query($form), '203.0.113.5');
    }

    private function controller(Request $request, FakeDatabase $db): TestUserController
    {
        return new TestUserController($request, new Config(), new Database(new Config()), $this->session, $db);
    }

    /**
     * @return array{sql: string, params: array<string|int, mixed>}|null
     */
    private function findWrite(FakeDatabase $db, string $needle): ?array
    {
        foreach ($db->writes as $write) {
            if (str_contains($write['sql'], $needle)) {
                return $write;
            }
        }

        return null;
    }

    // --- Lecture (user.read) ---

    public function testIndexRequiresUserRead(): void
    {
        $db = $this->permittedDb();
        $db->canResult = false;

        self::assertSame(403, $this->controller($this->get('/admin/users'), $db)->index()->status());
    }

    public function testIndexListsUsers(): void
    {
        $db = $this->permittedDb();
        $db->usersRows = [$this->target(['email' => 'sam@wakdo.local']) + ['role_label' => 'Counter Staff']];

        $response = $this->controller($this->get('/admin/users'), $db)->index();
        self::assertSame(200, $response->status());
        self::assertStringContainsString('sam@wakdo.local', $response->body());
    }

    // --- Creation (user.create) : PIN + audit ---

    public function testStoreCreatesWithValidPinAndAudits(): void
    {
        $db = $this->permittedDb();
        $this->actingPin($db);
        $db->lastInsertId = 42;

        $response = $this->controller($this->post($this->createForm(), '/admin/users'), $db)->store();

        self::assertSame(302, $response->status());
        self::assertSame(['begin', 'commit'], $db->transactionEvents);
        self::assertTrue($db->wrote('INSERT INTO user'));
        $audit = $this->findWrite($db, 'INSERT INTO audit_log');
        self::assertNotNull($audit);
        self::assertSame('user.create', $audit['params']['code'] ?? null);
        self::assertSame(9, $audit['params']['uid'] ?? null);   // acteur resolu par PIN, pas la session
    }

    public function testStoreWithoutValidPinLogsFailedAndDoesNotCreate(): void
    {
        $db = $this->permittedDb();
        $db->actingUserRow = null; // PIN non resolu

        $response = $this->controller($this->post($this->createForm(['pin' => '0000']), '/admin/users'), $db)->store();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO user'));
        self::assertSame(['pin.failed'], $db->auditActions());
    }

    public function testStoreRejectsDuplicateEmailWith409(): void
    {
        $db = $this->permittedDb();
        $this->actingPin($db);
        $db->userEmailTaken = true;

        $response = $this->controller($this->post($this->createForm(), '/admin/users'), $db)->store();

        self::assertSame(409, $response->status());
        self::assertFalse($db->wrote('INSERT INTO user'));
    }

    public function testStoreValidationRejectsShortPasswordAndBadEmail(): void
    {
        $db = $this->permittedDb();
        $response = $this->controller($this->post($this->createForm(['email' => 'nope', 'password' => 'short']), '/admin/users'), $db)->store();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO user'));
    }

    public function testStoreRejectsInvalidCsrf(): void
    {
        $db = $this->permittedDb();
        $response = $this->controller($this->post($this->createForm(['_csrf' => 'bad']), '/admin/users'), $db)->store();

        self::assertSame(403, $response->status());
        self::assertFalse($db->wrote('INSERT INTO user'));
    }

    public function testStoreTranslatesUniqueRaceTo409(): void
    {
        $db = $this->permittedDb();
        $this->actingPin($db);
        $db->failOnExecute = new PDOException('dup', 23000);

        $response = $this->controller($this->post($this->createForm(), '/admin/users'), $db)->store();

        self::assertSame(409, $response->status());
    }

    // --- Mise a jour (user.update) ---

    public function testUpdateNotFound(): void
    {
        $db = $this->permittedDb();
        $db->userManageRow = null;

        self::assertSame(404, $this->controller($this->post($this->createForm(), '/admin/users/9'), $db)->update(['id' => '9'])->status());
    }

    public function testUpdateAppliesWithPinAndAudits(): void
    {
        $db = $this->permittedDb();
        $db->userManageRow = $this->target();
        $this->actingPin($db);

        $form = $this->createForm(['email' => 'staff@wakdo.local', 'first_name' => 'Renamed', 'is_active' => '1']);
        $response = $this->controller($this->post($form, '/admin/users/5'), $db)->update(['id' => '5']);

        self::assertSame(302, $response->status());
        self::assertTrue($db->wrote('UPDATE user SET email'));
        $audit = $this->findWrite($db, 'INSERT INTO audit_log');
        self::assertNotNull($audit);
        self::assertSame('user.update', $audit['params']['code'] ?? null);
    }

    public function testUpdateBlocksRemovingLastActiveAdmin(): void
    {
        $db = $this->permittedDb();
        $db->userManageRow = $this->target(['is_active' => 1]); // cible admin actif
        $db->userIsAdmin = true;
        $db->activeAdminCount = 1;                              // dernier admin actif

        // is_active absent du form -> desactivation tentee -> bloquee.
        $form = $this->createForm(['email' => 'staff@wakdo.local']);
        unset($form['pin_email'], $form['pin']);
        $response = $this->controller($this->post($form, '/admin/users/5'), $db)->update(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('dernier administrateur', $response->body());
        self::assertFalse($db->wrote('UPDATE user SET email'));
    }

    // --- Desactivation (user.deactivate) ---

    public function testDeactivateSelfForbidden(): void
    {
        $db = $this->permittedDb();
        $db->userManageRow = $this->target(['id' => 1]); // cible = acteur de session
        $this->actingPin($db);

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'pin_email' => 'sam@wakdo.local', 'pin' => '4729'], '/admin/users/1/deactivate'), $db)->deactivate(['id' => '1']);

        self::assertSame(403, $response->status());
        self::assertFalse($db->wrote('SET is_active = 0'));
    }

    public function testDeactivateBlocksLastActiveAdmin(): void
    {
        $db = $this->permittedDb();
        $db->userManageRow = $this->target(['id' => 5]);
        $db->userIsAdmin = true;
        $db->activeAdminCount = 1;

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'pin_email' => 'sam@wakdo.local', 'pin' => '4729'], '/admin/users/5/deactivate'), $db)->deactivate(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('SET is_active = 0'));
    }

    public function testDeactivateWithPinAndAudit(): void
    {
        $db = $this->permittedDb();
        $db->userManageRow = $this->target(['id' => 5]);
        $db->userIsAdmin = false; // pas admin -> garde dernier-admin non declenchee
        $this->actingPin($db);

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'pin_email' => 'sam@wakdo.local', 'pin' => '4729'], '/admin/users/5/deactivate'), $db)->deactivate(['id' => '5']);

        self::assertSame(302, $response->status());
        self::assertTrue($db->wrote('SET is_active = 0'));
        self::assertSame('user.deactivate', ($this->findWrite($db, 'INSERT INTO audit_log')['params']['code'] ?? null));
    }

    public function testDeactivateLockedActorReturns422WithoutEffect(): void
    {
        $db = $this->permittedDb();
        $db->userManageRow = $this->target(['id' => 5]);
        $this->actingPin($db);
        $db->pinThrottleLockoutUntil = date('Y-m-d H:i:s', time() + 300);

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'pin_email' => 'sam@wakdo.local', 'pin' => '4729'], '/admin/users/5/deactivate'), $db)->deactivate(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertSame([], $db->auditActions());        // pas de pin.failed sous verrou (RG-T22)
        self::assertFalse($db->wrote('SET is_active = 0'));
    }

    // --- Reset PIN (user.update) ---

    public function testResetPinClearsPin(): void
    {
        $db = $this->permittedDb();
        $db->userManageRow = $this->target(['id' => 5]);
        $this->actingPin($db);

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'pin_email' => 'sam@wakdo.local', 'pin' => '4729'], '/admin/users/5/reset-pin'), $db)->resetPin(['id' => '5']);

        self::assertSame(302, $response->status());
        self::assertTrue($db->wrote('UPDATE user SET pin_hash = NULL'));
    }

    // --- Anonymisation RGPD (user.update) ---

    public function testEraseRejectsAlreadyAnonymisedWith409(): void
    {
        $db = $this->permittedDb();
        $db->userManageRow = $this->target(['id' => 5, 'anonymized_at' => '2026-01-01 00:00:00']);
        $this->actingPin($db);

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'pin_email' => 'sam@wakdo.local', 'pin' => '4729'], '/admin/users/5/erase'), $db)->erase(['id' => '5']);

        self::assertSame(409, $response->status());
        self::assertFalse($db->wrote('anonymized_at = NOW()'));
    }

    public function testEraseAnonymisesWithPinAndAudit(): void
    {
        $db = $this->permittedDb();
        $db->userManageRow = $this->target(['id' => 5, 'anonymized_at' => null]);
        $db->userIsAdmin = false;
        $this->actingPin($db);

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'pin_email' => 'sam@wakdo.local', 'pin' => '4729'], '/admin/users/5/erase'), $db)->erase(['id' => '5']);

        self::assertSame(302, $response->status());
        self::assertTrue($db->wrote('anonymized_at = NOW()'));
        self::assertSame('user.erase_pii', ($this->findWrite($db, 'INSERT INTO audit_log')['params']['code'] ?? null));
    }
}
