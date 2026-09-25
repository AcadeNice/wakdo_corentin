<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin\Api;

use PHPUnit\Framework\TestCase;
use App\Auth\Authorizer;
use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\SessionGuard;
use App\Auth\SessionManager;
use App\Controllers\Admin\Api\UserApiController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\Request;
use App\Tests\Support\FakeDatabase;

final class TestUserApiController extends UserApiController
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

    protected function sessionGuard(): SessionGuard
    {
        return new SessionGuard($this->testSession, $this->fakeDb, $this->config);
    }

    protected function authorizer(): Authorizer
    {
        return new Authorizer($this->fakeDb);
    }

    protected function db(): DatabaseInterface
    {
        return $this->fakeDb;
    }
}

final class UserApiControllerTest extends TestCase
{
    /** @var list<string> */
    private array $touchedKeys = [];

    private SessionManager $session;
    private string $csrf = '';

    protected function setUp(): void
    {
        $this->setEnv('SESSION_LIFETIME_IDLE', '14400');
        $this->setEnv('SESSION_LIFETIME_ABSOLUTE', '36000');

        $this->session = new SessionManager(new Config(), true);
        $now = time();
        $this->session->set('user_id', 1);
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
        // grantedCodes (allowlist EXACTE, pas canResult=true) : un test casse si le
        // controleur se met a demander un autre code de permission que celui documente.
        $db->permissionCodes = ['user.read', 'user.create', 'user.update', 'user.deactivate'];
        $db->grantedCodes = $db->permissionCodes;
        $db->roleActiveExists = true;

        return $db;
    }

    private function actingPin(FakeDatabase $db): void
    {
        $db->actingUserRow = ['id' => 9, 'role_id' => 4, 'pin_hash' => (new PasswordHasher(new Config()))->hash('4729')];
    }

    private function get(string $path): Request
    {
        return new Request('GET', $path, [], [], '', '203.0.113.5');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(string $method, string $path, array $body, bool $withCsrf = true): Request
    {
        $headers = ['content-type' => 'application/json'];
        if ($withCsrf) {
            $headers['x-csrf-token'] = $this->csrf;
        }

        return new Request($method, $path, [], $headers, ($body === [] ? '{}' : (string) json_encode($body)), '203.0.113.5');
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validBody(array $overrides = []): array
    {
        return array_merge([
            'email'      => 'nouvel.equipier@wakdo.fr',
            'first_name' => 'Alex',
            'last_name'  => 'Martin',
            'role_id'    => 2,
            'password'   => 'motdepasse123',
            'pin_email'  => 'e@e.fr',
            'pin'        => '4729',
        ], $overrides);
    }

    private function controller(Request $request, FakeDatabase $db): TestUserApiController
    {
        return new TestUserApiController($request, new Config(), new Database(new Config()), $this->session, $db);
    }

    public function testStoreRejectsMissingCsrf(): void
    {
        $db = $this->permittedDb();
        $request = $this->jsonRequest('POST', '/admin/api/users', $this->validBody(), false);

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(403, $response->status());
        self::assertSame('CSRF_INVALID', $body['error']['code'] ?? null);
        self::assertFalse($db->wrote('INSERT INTO user'));
    }

    public function testStoreRejectsWrongCsrfToken(): void
    {
        $db = $this->permittedDb();
        $request = new Request(
            'POST',
            '/admin/api/users',
            [],
            ['content-type' => 'application/json', 'x-csrf-token' => 'un-jeton-invente'],
            (string) json_encode($this->validBody()),
            '203.0.113.5',
        );

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(403, $response->status());
        self::assertSame('CSRF_INVALID', $body['error']['code'] ?? null);
        self::assertFalse($db->wrote('INSERT INTO user'));
    }

    public function testStoreRequiresPin(): void
    {
        $db = $this->permittedDb();
        $request = $this->jsonRequest('POST', '/admin/api/users', $this->validBody(['pin' => 'wrong']));

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(422, $response->status());
        self::assertSame('PIN_INVALID', $body['error']['code'] ?? null);
        self::assertFalse($db->wrote('INSERT INTO user'));
    }

    public function testStoreValidWithPinCreatesAndWritesAudit(): void
    {
        $db = $this->permittedDb();
        $this->actingPin($db);
        $db->lastInsertId = 30;
        $db->userManageRow = ['id' => 30, 'email' => 'nouvel.equipier@wakdo.fr', 'first_name' => 'Alex', 'last_name' => 'Martin', 'role_id' => 2, 'is_active' => 1, 'anonymized_at' => null];
        $request = $this->jsonRequest('POST', '/admin/api/users', $this->validBody());

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(201, $response->status());
        self::assertSame('nouvel.equipier@wakdo.fr', $body['data']['email']);
        self::assertTrue($db->wrote('INSERT INTO user'));
        self::assertSame(['user.create'], $db->auditActions());
    }

    public function testStoreDuplicateEmailReturns409(): void
    {
        $db = $this->permittedDb();
        $db->userEmailTaken = true;
        $request = $this->jsonRequest('POST', '/admin/api/users', $this->validBody());

        $response = $this->controller($request, $db)->apiStore();

        self::assertSame(409, $response->status());
        self::assertFalse($db->wrote('INSERT INTO user'));
    }

    public function testUpdateLastActiveAdminRoleChangeReturns422(): void
    {
        $db = $this->permittedDb();
        $db->userManageRow = ['id' => 5, 'email' => 'admin@wakdo.fr', 'first_name' => 'A', 'last_name' => 'B', 'role_id' => 1, 'is_active' => 1];
        $db->userIsAdmin = true;
        $db->activeAdminCount = 1;
        $request = $this->jsonRequest('PUT', '/admin/api/users/5', $this->validBody(['email' => 'admin@wakdo.fr', 'role_id' => 2]));

        $response = $this->controller($request, $db)->apiUpdate(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('UPDATE user SET'));
    }

    public function testUpdateWithoutIsActiveKeepsCurrentValue(): void
    {
        // PUT partiel (section 5.3 de conventions.md) : is_active absent du corps
        // NE desactive PAS le compte (regression testee : avant le correctif, une
        // omission valait `is_active=0`).
        $db = $this->permittedDb();
        $db->userManageRow = ['id' => 5, 'email' => 'e@wakdo.fr', 'first_name' => 'E', 'last_name' => 'Q', 'role_id' => 2, 'is_active' => 1];
        $this->actingPin($db);
        $request = $this->jsonRequest('PUT', '/admin/api/users/5', $this->validBody(['email' => 'e@wakdo.fr']));

        $response = $this->controller($request, $db)->apiUpdate(['id' => '5']);

        self::assertSame(200, $response->status());
        $update = null;
        foreach ($db->writes as $write) {
            if (str_contains($write['sql'], 'UPDATE user SET email')) {
                $update = $write;
            }
        }
        self::assertNotNull($update);
        self::assertSame(1, $update['params']['active'] ?? null);
    }

    public function testUpdateRejectsStringFalseAsIsActive(): void
    {
        // Booleen JSON STRICT (relecture point 6) : la chaine "false" est non vide,
        // donc "truthy" pour un `!empty()` naif -- le bug corrige ici. Doit etre
        // refusee (422), jamais lue comme un booleen valide.
        $db = $this->permittedDb();
        $db->userManageRow = ['id' => 5, 'email' => 'e@wakdo.fr', 'first_name' => 'E', 'last_name' => 'Q', 'role_id' => 2, 'is_active' => 1];
        $this->actingPin($db);
        $request = $this->jsonRequest('PUT', '/admin/api/users/5', $this->validBody(['email' => 'e@wakdo.fr', 'is_active' => 'false']));

        $response = $this->controller($request, $db)->apiUpdate(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('UPDATE user SET email'));
    }

    public function testDestroySelfReturns403(): void
    {
        $db = $this->permittedDb();
        $db->userManageRow = ['id' => 1, 'email' => 'me@wakdo.fr', 'first_name' => 'Me', 'last_name' => 'Self', 'role_id' => 2, 'is_active' => 1];
        $request = $this->jsonRequest('DELETE', '/admin/api/users/1', []);

        $response = $this->controller($request, $db)->apiDestroy(['id' => '1']);

        self::assertSame(403, $response->status());
    }

    public function testDestroyValidDeactivates(): void
    {
        $db = $this->permittedDb();
        $db->userManageRow = ['id' => 5, 'email' => 'e@wakdo.fr', 'first_name' => 'E', 'last_name' => 'Q', 'role_id' => 2, 'is_active' => 1];
        $this->actingPin($db);
        $request = $this->jsonRequest('DELETE', '/admin/api/users/5', ['pin_email' => 'e@e.fr', 'pin' => '4729']);

        $response = $this->controller($request, $db)->apiDestroy(['id' => '5']);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertSame('deactivated', $body['data']['status']);
        self::assertSame(['user.deactivate'], $db->auditActions());
    }

    public function testIndexWithoutSessionReturns401(): void
    {
        $db = $this->permittedDb();
        $session = new SessionManager(new Config(), true);

        $response = (new TestUserApiController($this->get('/admin/api/users'), new Config(), new Database(new Config()), $session, $db))->apiIndex();

        self::assertSame(401, $response->status());
    }

    // --- reset-pin ---

    public function testResetPinRequiresPin(): void
    {
        $db = $this->permittedDb();
        $db->userManageRow = ['id' => 5, 'email' => 'e@wakdo.fr', 'first_name' => 'E', 'last_name' => 'Q', 'role_id' => 2, 'is_active' => 1];
        $request = $this->jsonRequest('POST', '/admin/api/users/5/reset-pin', []);

        $response = $this->controller($request, $db)->apiResetPin(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('UPDATE user SET pin_hash'));
    }

    public function testResetPinWithValidPinClearsHash(): void
    {
        $db = $this->permittedDb();
        $db->userManageRow = ['id' => 5, 'email' => 'e@wakdo.fr', 'first_name' => 'E', 'last_name' => 'Q', 'role_id' => 2, 'is_active' => 1];
        $this->actingPin($db);
        $request = $this->jsonRequest('POST', '/admin/api/users/5/reset-pin', ['pin_email' => 'e@e.fr', 'pin' => '4729']);

        $response = $this->controller($request, $db)->apiResetPin(['id' => '5']);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertSame('pin_reset', $body['data']['status']);
        self::assertSame(['user.update'], $db->auditActions());
    }

    // --- erase (RGPD) ---

    public function testEraseSelfReturns403(): void
    {
        $db = $this->permittedDb();
        $db->userManageRow = ['id' => 1, 'email' => 'me@wakdo.fr', 'first_name' => 'Me', 'last_name' => 'Self', 'role_id' => 2, 'is_active' => 1, 'anonymized_at' => null];
        $request = $this->jsonRequest('POST', '/admin/api/users/1/erase', []);

        $response = $this->controller($request, $db)->apiErase(['id' => '1']);

        self::assertSame(403, $response->status());
    }

    public function testEraseAlreadyAnonymizedReturns409(): void
    {
        $db = $this->permittedDb();
        $db->userManageRow = ['id' => 5, 'email' => 'e@wakdo.fr', 'first_name' => 'E', 'last_name' => 'Q', 'role_id' => 2, 'is_active' => 1, 'anonymized_at' => '2026-01-01 00:00:00'];
        $request = $this->jsonRequest('POST', '/admin/api/users/5/erase', []);

        $response = $this->controller($request, $db)->apiErase(['id' => '5']);

        self::assertSame(409, $response->status());
    }

    public function testEraseWithValidPinAnonymizes(): void
    {
        $db = $this->permittedDb();
        $db->userManageRow = ['id' => 5, 'email' => 'e@wakdo.fr', 'first_name' => 'E', 'last_name' => 'Q', 'role_id' => 2, 'is_active' => 1, 'anonymized_at' => null];
        $db->executeRowCount = 1;
        $this->actingPin($db);
        $request = $this->jsonRequest('POST', '/admin/api/users/5/erase', ['pin_email' => 'e@e.fr', 'pin' => '4729']);

        $response = $this->controller($request, $db)->apiErase(['id' => '5']);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertSame('anonymized', $body['data']['status']);
        self::assertSame(['user.erase_pii'], $db->auditActions());
    }
}
