<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin\Api;

use PHPUnit\Framework\TestCase;
use App\Auth\Authorizer;
use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\SessionGuard;
use App\Auth\SessionManager;
use App\Controllers\Admin\Api\RoleApiController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\Request;
use App\Tests\Support\FakeDatabase;

final class TestRoleApiController extends RoleApiController
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

final class RoleApiControllerTest extends TestCase
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
        $db->permissionCodes = ['role.manage'];
        $db->grantedCodes = $db->permissionCodes;
        $db->permissionsRows = [
            ['id' => 1, 'code' => 'role.manage'],
            ['id' => 2, 'code' => 'product.read'],
        ];

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
            'code'            => 'shift_lead',
            'label'           => 'Chef de faction',
            'permission_ids'  => [2],
            'visible_sources' => ['counter'],
            'pin_email'       => 'e@e.fr',
            'pin'             => '4729',
        ], $overrides);
    }

    private function controller(Request $request, FakeDatabase $db): TestRoleApiController
    {
        return new TestRoleApiController($request, new Config(), new Database(new Config()), $this->session, $db);
    }

    public function testStoreRejectsMissingCsrf(): void
    {
        $db = $this->permittedDb();
        $request = $this->jsonRequest('POST', '/admin/api/roles', $this->validBody(), false);

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(403, $response->status());
        self::assertSame('CSRF_INVALID', $body['error']['code'] ?? null);
        self::assertFalse($db->wrote('INSERT INTO role'));
    }

    public function testStoreRejectsWrongCsrfToken(): void
    {
        $db = $this->permittedDb();
        $request = new Request(
            'POST',
            '/admin/api/roles',
            [],
            ['content-type' => 'application/json', 'x-csrf-token' => 'un-jeton-invente'],
            (string) json_encode($this->validBody()),
            '203.0.113.5',
        );

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(403, $response->status());
        self::assertSame('CSRF_INVALID', $body['error']['code'] ?? null);
        self::assertFalse($db->wrote('INSERT INTO role'));
    }

    public function testStoreRequiresPin(): void
    {
        $db = $this->permittedDb();
        $request = $this->jsonRequest('POST', '/admin/api/roles', $this->validBody(['pin' => 'wrong']));

        $response = $this->controller($request, $db)->apiStore();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO role'));
    }

    public function testStoreValidCreatesRoleWithPermissions(): void
    {
        $db = $this->permittedDb();
        $this->actingPin($db);
        $db->lastInsertId = 8;
        $db->roleManageRow = ['id' => 8, 'code' => 'shift_lead', 'label' => 'Chef de faction', 'description' => null, 'default_route' => null, 'order_source' => null, 'is_active' => 1];
        $request = $this->jsonRequest('POST', '/admin/api/roles', $this->validBody());

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(201, $response->status());
        self::assertSame('shift_lead', $body['data']['code']);
        self::assertSame([2], $body['data']['permission_ids']);
        self::assertTrue($db->wrote('INSERT INTO role'));
        self::assertSame(['role.manage'], $db->auditActions());
    }

    public function testStoreRejectsDecimalPermissionId(): void
    {
        // fieldIntList() : entiers STRICTS (relecture point 6) -- "1.9" doit etre
        // refuse (422), jamais tronque en silence a 1 par un ancien
        // is_numeric()+(int).
        $db = $this->permittedDb();
        $request = $this->jsonRequest('POST', '/admin/api/roles', $this->validBody(['permission_ids' => ['1.9']]));

        $response = $this->controller($request, $db)->apiStore();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO role'));
    }

    public function testStoreDuplicateCodeReturns409(): void
    {
        $db = $this->permittedDb();
        $db->roleCodeTaken = true;
        $request = $this->jsonRequest('POST', '/admin/api/roles', $this->validBody());

        $response = $this->controller($request, $db)->apiStore();

        self::assertSame(409, $response->status());
    }

    public function testUpdateAdminRoleWithoutRoleManageReturns422(): void
    {
        $db = $this->permittedDb();
        $db->roleManageRow = ['id' => 1, 'code' => 'admin', 'label' => 'Administrateur', 'description' => null, 'default_route' => null, 'order_source' => null, 'is_active' => 1];
        $request = $this->jsonRequest('PUT', '/admin/api/roles/1', $this->validBody(['permission_ids' => [2]]));

        $response = $this->controller($request, $db)->apiUpdate(['id' => '1']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('DELETE FROM role_permission'));
    }

    public function testUpdateValidWritesAuditWithDiff(): void
    {
        $db = $this->permittedDb();
        $db->roleManageRow = ['id' => 3, 'code' => 'shift_lead', 'label' => 'Chef', 'description' => null, 'default_route' => null, 'order_source' => null, 'is_active' => 1];
        $this->actingPin($db);
        $request = $this->jsonRequest('PUT', '/admin/api/roles/3', $this->validBody(['label' => 'Chef de faction v2']));

        $response = $this->controller($request, $db)->apiUpdate(['id' => '3']);

        self::assertSame(200, $response->status());
        self::assertSame(['role.manage'], $db->auditActions());
    }

    public function testUpdateWithoutIsActiveKeepsCurrentValue(): void
    {
        // PUT partiel (section 5.3 de conventions.md), meme regle que pour les
        // utilisateurs (relecture point 4) : is_active absent du corps NE
        // desactive PAS le role.
        $db = $this->permittedDb();
        $db->roleManageRow = ['id' => 3, 'code' => 'shift_lead', 'label' => 'Chef', 'description' => null, 'default_route' => null, 'order_source' => null, 'is_active' => 1];
        $this->actingPin($db);
        $request = $this->jsonRequest('PUT', '/admin/api/roles/3', $this->validBody());

        $response = $this->controller($request, $db)->apiUpdate(['id' => '3']);

        self::assertSame(200, $response->status());
        $update = null;
        foreach ($db->writes as $write) {
            if (str_contains($write['sql'], 'UPDATE role SET label')) {
                $update = $write;
            }
        }
        self::assertNotNull($update);
        self::assertSame(1, $update['params']['active'] ?? null);
    }

    public function testUpdateRejectsStringFalseAsIsActive(): void
    {
        // Booleen JSON STRICT (relecture point 6), meme bug que pour les
        // utilisateurs : la chaine "false" doit etre refusee (422).
        $db = $this->permittedDb();
        $db->roleManageRow = ['id' => 3, 'code' => 'shift_lead', 'label' => 'Chef', 'description' => null, 'default_route' => null, 'order_source' => null, 'is_active' => 1];
        $this->actingPin($db);
        $request = $this->jsonRequest('PUT', '/admin/api/roles/3', $this->validBody(['is_active' => 'false']));

        $response = $this->controller($request, $db)->apiUpdate(['id' => '3']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('DELETE FROM role_permission'));
    }

    public function testShowNotFoundReturns404(): void
    {
        $db = $this->permittedDb();
        $db->roleManageRow = null;

        $response = $this->controller($this->get('/admin/api/roles/9'), $db)->apiShow(['id' => '9']);

        self::assertSame(404, $response->status());
    }

    public function testIndexWithoutPermissionReturns403(): void
    {
        $db = $this->permittedDb();
        $db->grantedCodes = []; // aucune permission accordee

        $response = $this->controller($this->get('/admin/api/roles'), $db)->apiIndex();

        self::assertSame(403, $response->status());
    }
}
