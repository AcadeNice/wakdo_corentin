<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin\Api;

use PHPUnit\Framework\TestCase;
use App\Auth\Authorizer;
use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\SessionGuard;
use App\Auth\SessionManager;
use App\Controllers\Admin\Api\MenuApiController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\Request;
use App\Tests\Support\FakeDatabase;

final class TestMenuApiController extends MenuApiController
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

final class MenuApiControllerTest extends TestCase
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
        $db->permissionCodes = ['menu.read', 'menu.create', 'menu.update', 'menu.delete'];
        $db->grantedCodes = $db->permissionCodes;
        $db->categoryRow = ['id' => 3, 'name' => 'Burgers'];
        $db->productIsBase = true;
        $db->productRow = ['id' => 7, 'name' => 'Coca Cola'];
        $db->productCategorySlug = 'boissons';

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
            'category_id'        => 3,
            'burger_product_id'  => 7,
            'name'               => 'Menu Big Mac',
            'price_normal_cents' => 890,
            'price_maxi_cents'   => 990,
            'display_order'      => 1,
            'is_available'       => true,
            'slots'              => [
                ['name' => 'Boisson', 'slot_type' => 'drink', 'is_required' => true, 'options' => [7]],
            ],
        ], $overrides);
    }

    private function controller(Request $request, FakeDatabase $db): TestMenuApiController
    {
        return new TestMenuApiController($request, new Config(), new Database(new Config()), $this->session, $db);
    }

    public function testStoreRejectsMissingCsrf(): void
    {
        $db = $this->permittedDb();
        $request = $this->jsonRequest('POST', '/admin/api/menus', $this->validBody(), false);

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(403, $response->status());
        self::assertSame('CSRF_INVALID', $body['error']['code'] ?? null);
        self::assertFalse($db->wrote('INSERT INTO menu'));
    }

    public function testStoreRejectsWrongCsrfToken(): void
    {
        $db = $this->permittedDb();
        $request = new Request(
            'POST',
            '/admin/api/menus',
            [],
            ['content-type' => 'application/json', 'x-csrf-token' => 'un-jeton-invente'],
            (string) json_encode($this->validBody()),
            '203.0.113.5',
        );

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(403, $response->status());
        self::assertSame('CSRF_INVALID', $body['error']['code'] ?? null);
        self::assertFalse($db->wrote('INSERT INTO menu'));
    }

    public function testStoreValidCreatesMenu(): void
    {
        $db = $this->permittedDb();
        $db->lastInsertId = 15;
        $db->menuRow = ['id' => 15, 'category_id' => 3, 'burger_product_id' => 7, 'name' => 'Menu Big Mac', 'price_normal_cents' => 890, 'price_maxi_cents' => 990, 'is_available' => 1, 'display_order' => 1];
        $request = $this->jsonRequest('POST', '/admin/api/menus', $this->validBody());

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(201, $response->status());
        self::assertSame('Menu Big Mac', $body['data']['name']);
        self::assertTrue($db->wrote('INSERT INTO menu'));
    }

    public function testStoreWithoutSlotsReturns422(): void
    {
        $db = $this->permittedDb();
        $request = $this->jsonRequest('POST', '/admin/api/menus', $this->validBody(['slots' => []]));

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(422, $response->status());
        self::assertArrayHasKey('slots', $body['error']['fields']);
    }

    public function testStoreRejectsFloatPrice(): void
    {
        $db = $this->permittedDb();
        $request = $this->jsonRequest('POST', '/admin/api/menus', $this->validBody(['price_normal_cents' => 890.5]));

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(422, $response->status());
        self::assertArrayHasKey('price_normal_cents', $body['error']['fields']);
    }

    public function testShowIncludesSlots(): void
    {
        $db = $this->permittedDb();
        $db->menuRow = ['id' => 15, 'category_id' => 3, 'burger_product_id' => 7, 'name' => 'Menu Big Mac', 'price_normal_cents' => 890, 'price_maxi_cents' => 990, 'is_available' => 1, 'display_order' => 1];
        $db->menuSlotRows = [
            ['id' => 1, 'name' => 'Boisson', 'slot_type' => 'drink', 'is_required' => 1, 'display_order' => 0, 'option_product_ids' => [7]],
        ];

        $response = $this->controller($this->get('/admin/api/menus/15'), $db)->apiShow(['id' => '15']);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertCount(1, $body['data']['slots']);
        self::assertSame('Boisson', $body['data']['slots'][0]['name']);
    }

    public function testUpdateWithoutIsAvailableKeepsCurrentValue(): void
    {
        // PUT partiel (relecture point 6), meme regle que is_active sur
        // users/roles et is_available sur produits : absent du corps NE rend PAS
        // le menu indisponible par omission.
        $db = $this->permittedDb();
        $db->menuRow = ['id' => 15, 'category_id' => 3, 'burger_product_id' => 7, 'name' => 'Menu Big Mac', 'price_normal_cents' => 890, 'price_maxi_cents' => 990, 'is_available' => 1, 'display_order' => 1];
        $body = $this->validBody(['name' => 'Menu Big Mac v2']);
        unset($body['is_available']);
        $request = $this->jsonRequest('PUT', '/admin/api/menus/15', $body);

        $response = $this->controller($request, $db)->apiUpdate(['id' => '15']);

        self::assertSame(200, $response->status());
        $update = null;
        foreach ($db->writes as $write) {
            if (str_contains($write['sql'], 'UPDATE menu SET category_id')) {
                $update = $write;
            }
        }
        self::assertNotNull($update);
        self::assertSame(1, $update['params']['available'] ?? null);
    }

    public function testUpdateRejectsStringFalseAsIsAvailable(): void
    {
        $db = $this->permittedDb();
        $db->menuRow = ['id' => 15, 'category_id' => 3, 'burger_product_id' => 7, 'name' => 'Menu Big Mac', 'price_normal_cents' => 890, 'price_maxi_cents' => 990, 'is_available' => 1, 'display_order' => 1];
        $request = $this->jsonRequest('PUT', '/admin/api/menus/15', $this->validBody(['is_available' => 'false']));

        $response = $this->controller($request, $db)->apiUpdate(['id' => '15']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('UPDATE menu SET'));
    }

    public function testDestroyRequiresPin(): void
    {
        $db = $this->permittedDb();
        $db->menuRow = ['id' => 15, 'name' => 'Menu Big Mac'];
        $request = $this->jsonRequest('DELETE', '/admin/api/menus/15', []);

        $response = $this->controller($request, $db)->apiDestroy(['id' => '15']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('DELETE FROM menu'));
    }

    public function testDestroyWithValidPinDeletes(): void
    {
        $db = $this->permittedDb();
        $db->menuRow = ['id' => 15, 'name' => 'Menu Big Mac'];
        $this->actingPin($db);
        $request = $this->jsonRequest('DELETE', '/admin/api/menus/15', ['pin_email' => 'e@e.fr', 'pin' => '4729']);

        $response = $this->controller($request, $db)->apiDestroy(['id' => '15']);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertSame('deleted', $body['data']['status']);
        self::assertSame(['menu.delete'], $db->auditActions());
    }

    public function testDestroyReferencedMenuReturns409(): void
    {
        $db = $this->permittedDb();
        $db->menuRow = ['id' => 15, 'name' => 'Menu Big Mac'];
        $this->actingPin($db);
        $db->failOnExecute = new \PDOException('fk', 23000);
        $request = $this->jsonRequest('DELETE', '/admin/api/menus/15', ['pin_email' => 'e@e.fr', 'pin' => '4729']);

        $response = $this->controller($request, $db)->apiDestroy(['id' => '15']);

        self::assertSame(409, $response->status());
    }

    public function testIndexWithoutPermissionReturns403(): void
    {
        $db = $this->permittedDb();
        $db->grantedCodes = []; // aucune permission accordee

        $response = $this->controller($this->get('/admin/api/menus'), $db)->apiIndex();

        self::assertSame(403, $response->status());
    }

    public function testToggleFlipsAvailability(): void
    {
        $db = $this->permittedDb();
        $db->menuRow = ['id' => 15, 'name' => 'Menu Big Mac', 'is_available' => 1];
        $request = new Request('POST', '/admin/api/menus/15/toggle', [], ['x-csrf-token' => $this->csrf], '', '203.0.113.5');

        $response = $this->controller($request, $db)->apiToggle(['id' => '15']);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertFalse($body['data']['is_available']);
        self::assertTrue($db->wrote('UPDATE menu SET is_available'));
    }

    public function testToggleNotFoundReturns404(): void
    {
        $db = $this->permittedDb();
        $db->menuRow = null;
        $request = new Request('POST', '/admin/api/menus/999/toggle', [], ['x-csrf-token' => $this->csrf], '', '203.0.113.5');

        $response = $this->controller($request, $db)->apiToggle(['id' => '999']);

        self::assertSame(404, $response->status());
    }
}
