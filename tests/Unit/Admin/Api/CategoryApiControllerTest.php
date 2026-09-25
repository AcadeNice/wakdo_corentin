<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin\Api;

use PHPUnit\Framework\TestCase;
use App\Auth\Authorizer;
use App\Auth\Csrf;
use App\Auth\SessionGuard;
use App\Auth\SessionManager;
use App\Catalogue\CategoryRepository;
use App\Controllers\Admin\Api\CategoryApiController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\Request;
use App\Tests\Support\FakeDatabase;

/**
 * Sous-classe de test : meme seam que TestCategoryController (CategoryControllerTest),
 * sans le double d'upload d'image (l'API JSON n'accepte pas de multipart).
 */
final class TestCategoryApiController extends CategoryApiController
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

    protected function categoryRepository(): CategoryRepository
    {
        return new CategoryRepository($this->fakeDb);
    }

    protected function db(): DatabaseInterface
    {
        return $this->fakeDb;
    }
}

final class CategoryApiControllerTest extends TestCase
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
        $db->permissionCodes = ['category.manage'];
        $db->grantedCodes = $db->permissionCodes;

        return $db;
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

    private function controller(Request $request, FakeDatabase $db): TestCategoryApiController
    {
        return new TestCategoryApiController($request, new Config(), new Database(new Config()), $this->session, $db);
    }

    // --- Authentification / autorisation ---

    public function testIndexWithoutSessionReturns401(): void
    {
        $db = $this->permittedDb();
        $db->guardUserRow = null;
        $session = new SessionManager(new Config(), true); // aucune identite posee

        $response = (new TestCategoryApiController($this->get('/admin/api/categories'), new Config(), new Database(new Config()), $session, $db))->apiIndex();

        self::assertSame(401, $response->status());
        $body = json_decode($response->body(), true);
        self::assertSame('AUTH_REQUIRED', $body['error']['code'] ?? null);
    }

    public function testIndexWithoutPermissionReturns403(): void
    {
        $db = $this->permittedDb();
        $db->grantedCodes = []; // aucune permission accordee

        $response = $this->controller($this->get('/admin/api/categories'), $db)->apiIndex();

        self::assertSame(403, $response->status());
        $body = json_decode($response->body(), true);
        self::assertSame('FORBIDDEN', $body['error']['code'] ?? null);
    }

    // --- index / show ---

    public function testIndexListsCategories(): void
    {
        $db = $this->permittedDb();
        $db->categoriesRows = [
            ['id' => 1, 'name' => 'Burgers', 'slug' => 'burgers', 'image_path' => null, 'display_order' => 2, 'is_active' => 1],
        ];

        $response = $this->controller($this->get('/admin/api/categories'), $db)->apiIndex();
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertSame(1, $body['total']);
        self::assertSame('Burgers', $body['data'][0]['name']);
        self::assertTrue($body['data'][0]['is_active']);
    }

    public function testShowNotFoundReturns404(): void
    {
        $db = $this->permittedDb();
        $db->categoryRow = null;

        $response = $this->controller($this->get('/admin/api/categories/999'), $db)->apiShow(['id' => '999']);

        self::assertSame(404, $response->status());
        $body = json_decode($response->body(), true);
        self::assertSame('NOT_FOUND', $body['error']['code'] ?? null);
    }

    public function testShowReturnsCategory(): void
    {
        $db = $this->permittedDb();
        $db->categoryRow = ['id' => 5, 'name' => 'Wraps', 'slug' => 'wraps', 'image_path' => null, 'display_order' => 3, 'is_active' => 1];

        $response = $this->controller($this->get('/admin/api/categories/5'), $db)->apiShow(['id' => '5']);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertSame(5, $body['data']['id']);
        self::assertSame('Wraps', $body['data']['name']);
    }

    // --- store ---

    public function testStoreRejectsMissingCsrf(): void
    {
        $db = $this->permittedDb();
        $request = $this->jsonRequest('POST', '/admin/api/categories', ['name' => 'Desserts', 'slug' => 'desserts'], false);

        $response = $this->controller($request, $db)->apiStore();

        self::assertSame(403, $response->status());
        $body = json_decode($response->body(), true);
        self::assertSame('CSRF_INVALID', $body['error']['code'] ?? null);
        self::assertFalse($db->wrote('INSERT INTO category'));
    }

    public function testStoreRejectsWrongCsrfToken(): void
    {
        $db = $this->permittedDb();
        $request = new Request(
            'POST',
            '/admin/api/categories',
            [],
            ['content-type' => 'application/json', 'x-csrf-token' => 'un-jeton-invente'],
            (string) json_encode(['name' => 'Desserts', 'slug' => 'desserts']),
            '203.0.113.5',
        );

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(403, $response->status());
        self::assertSame('CSRF_INVALID', $body['error']['code'] ?? null);
        self::assertFalse($db->wrote('INSERT INTO category'));
    }

    public function testStoreRejectsInvalidJson(): void
    {
        $db = $this->permittedDb();
        $request = new Request('POST', '/admin/api/categories', [], ['x-csrf-token' => $this->csrf, 'content-type' => 'application/json'], '{not-json', '203.0.113.5');

        $response = $this->controller($request, $db)->apiStore();

        self::assertSame(400, $response->status());
        $body = json_decode($response->body(), true);
        self::assertSame('INVALID_JSON', $body['error']['code'] ?? null);
    }

    public function testStoreRejectsNonJsonContentType(): void
    {
        $db = $this->permittedDb();
        // Corps non vide sans Content-Type application/json (point 3) : 415, pas un
        // essai de parsing degrade en 400/422.
        $request = new Request('POST', '/admin/api/categories', [], ['x-csrf-token' => $this->csrf, 'content-type' => 'text/plain'], '{"name":"Boissons","slug":"boissons"}', '203.0.113.5');

        $response = $this->controller($request, $db)->apiStore();

        self::assertSame(415, $response->status());
        $body = json_decode($response->body(), true);
        self::assertSame('UNSUPPORTED_MEDIA_TYPE', $body['error']['code'] ?? null);
        self::assertFalse($db->wrote('INSERT INTO category'));
    }

    public function testStoreRejectsJsonListAsRootReturns400(): void
    {
        $db = $this->permittedDb();
        // Racine JSON = liste (pas un objet) : 400, meme regle que pour un objet malforme.
        $request = new Request('POST', '/admin/api/categories', [], ['x-csrf-token' => $this->csrf, 'content-type' => 'application/json'], '["Boissons","boissons"]', '203.0.113.5');

        $response = $this->controller($request, $db)->apiStore();

        self::assertSame(400, $response->status());
        $body = json_decode($response->body(), true);
        self::assertSame('INVALID_JSON', $body['error']['code'] ?? null);
    }

    public function testStoreRejectsNonScalarName(): void
    {
        $db = $this->permittedDb();
        // Champ non scalaire (tableau) : 422 avec detail par champ, jamais un cast
        // silencieux en "Array" ni un warning PHP (point 2, centralise dans JsonApiTrait).
        $request = $this->jsonRequest('POST', '/admin/api/categories', ['name' => ['Boissons'], 'slug' => 'boissons']);

        $response = $this->controller($request, $db)->apiStore();

        self::assertSame(422, $response->status());
        $body = json_decode($response->body(), true);
        self::assertArrayHasKey('name', $body['error']['fields'] ?? []);
        self::assertFalse($db->wrote('INSERT INTO category'));
    }

    public function testStoreValidCreatesAndReturns201(): void
    {
        $db = $this->permittedDb();
        $db->lastInsertId = 42;
        $db->categoryRow = ['id' => 42, 'name' => 'Desserts', 'slug' => 'desserts', 'image_path' => null, 'display_order' => 7, 'is_active' => 1];
        $request = $this->jsonRequest('POST', '/admin/api/categories', ['name' => 'Desserts', 'slug' => 'desserts', 'display_order' => 7]);

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(201, $response->status());
        self::assertSame('/admin/api/categories/42', $response->header('Location'));
        self::assertSame('Desserts', $body['data']['name']);
        self::assertTrue($db->wrote('INSERT INTO category'));
    }

    public function testStoreInvalidReturns422WithFieldErrors(): void
    {
        $db = $this->permittedDb();
        $request = $this->jsonRequest('POST', '/admin/api/categories', ['name' => '', 'slug' => 'INVALID SLUG']);

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(422, $response->status());
        self::assertSame('VALIDATION_ERROR', $body['error']['code'] ?? null);
        self::assertArrayHasKey('name', $body['error']['fields']);
        self::assertArrayHasKey('slug', $body['error']['fields']);
        self::assertFalse($db->wrote('INSERT INTO category'));
    }

    public function testStoreDuplicateSlugReturns422(): void
    {
        $db = $this->permittedDb();
        $db->categorySlugTaken = true;
        $request = $this->jsonRequest('POST', '/admin/api/categories', ['name' => 'Desserts', 'slug' => 'desserts']);

        $response = $this->controller($request, $db)->apiStore();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO category'));
    }

    public function testStoreUniqueViolationTranslatesTo409(): void
    {
        $db = $this->permittedDb();
        $db->failOnExecute = new \PDOException('duplicate', 23000);
        $request = $this->jsonRequest('POST', '/admin/api/categories', ['name' => 'Desserts', 'slug' => 'desserts']);

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(409, $response->status());
        self::assertSame('CONFLICT', $body['error']['code'] ?? null);
    }

    // --- update ---

    public function testUpdateNotFoundReturns404(): void
    {
        $db = $this->permittedDb();
        $db->categoryRow = null;
        $request = $this->jsonRequest('PUT', '/admin/api/categories/999', ['name' => 'X', 'slug' => 'x']);

        $response = $this->controller($request, $db)->apiUpdate(['id' => '999']);

        self::assertSame(404, $response->status());
    }

    public function testUpdateValidReturns200(): void
    {
        $db = $this->permittedDb();
        $db->categoryRow = ['id' => 5, 'name' => 'Wraps', 'slug' => 'wraps', 'image_path' => null, 'display_order' => 3, 'is_active' => 1];
        $request = $this->jsonRequest('PUT', '/admin/api/categories/5', ['name' => 'Wraps & Co', 'slug' => 'wraps', 'display_order' => 3]);

        $response = $this->controller($request, $db)->apiUpdate(['id' => '5']);

        self::assertSame(200, $response->status());
        self::assertTrue($db->wrote('UPDATE category SET name'));
    }

    // --- destroy (= desactivation, pas de suppression dure) ---

    public function testDestroyDeactivatesCategory(): void
    {
        $db = $this->permittedDb();
        $db->categoryRow = ['id' => 5, 'name' => 'Wraps', 'slug' => 'wraps', 'image_path' => null, 'display_order' => 3, 'is_active' => 1];
        $request = new Request('DELETE', '/admin/api/categories/5', [], ['x-csrf-token' => $this->csrf], '', '203.0.113.5');

        $response = $this->controller($request, $db)->apiDestroy(['id' => '5']);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertSame('deactivated', $body['data']['status']);
        self::assertTrue($db->wrote('UPDATE category SET is_active'));
    }

    public function testDestroyNotFoundReturns404(): void
    {
        $db = $this->permittedDb();
        $db->categoryRow = null;
        $request = new Request('DELETE', '/admin/api/categories/999', [], ['x-csrf-token' => $this->csrf], '', '203.0.113.5');

        $response = $this->controller($request, $db)->apiDestroy(['id' => '999']);

        self::assertSame(404, $response->status());
    }

    public function testDestroyRejectsMissingCsrf(): void
    {
        $db = $this->permittedDb();
        $db->categoryRow = ['id' => 5, 'name' => 'Wraps', 'slug' => 'wraps', 'image_path' => null, 'display_order' => 3, 'is_active' => 1];
        $request = new Request('DELETE', '/admin/api/categories/5', [], [], '', '203.0.113.5');

        $response = $this->controller($request, $db)->apiDestroy(['id' => '5']);

        self::assertSame(403, $response->status());
        self::assertFalse($db->wrote('UPDATE category SET is_active'));
    }

    // --- toggle / move ---

    public function testToggleFlipsFromVisibleToMasked(): void
    {
        $db = $this->permittedDb();
        $db->categoryRow = ['id' => 5, 'name' => 'Wraps', 'slug' => 'wraps', 'image_path' => null, 'display_order' => 3, 'is_active' => 1];
        $request = new Request('POST', '/admin/api/categories/5/toggle', [], ['x-csrf-token' => $this->csrf], '', '203.0.113.5');

        $response = $this->controller($request, $db)->apiToggle(['id' => '5']);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertFalse($body['data']['is_active']);
        self::assertTrue($db->wrote('UPDATE category SET is_active'));
    }

    public function testToggleNotFoundReturns404(): void
    {
        $db = $this->permittedDb();
        $db->categoryRow = null;
        $request = new Request('POST', '/admin/api/categories/999/toggle', [], ['x-csrf-token' => $this->csrf], '', '203.0.113.5');

        $response = $this->controller($request, $db)->apiToggle(['id' => '999']);

        self::assertSame(404, $response->status());
    }

    public function testMoveRejectsInvalidDirection(): void
    {
        $db = $this->permittedDb();
        $request = $this->jsonRequest('POST', '/admin/api/categories/20/move', ['direction' => 'sideways']);

        $response = $this->controller($request, $db)->apiMove(['id' => '20']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('UPDATE category SET display_order'));
    }

    public function testMoveUpReordersCategories(): void
    {
        $db = $this->permittedDb();
        $db->categoryRow = ['id' => 20, 'name' => 'Wraps', 'slug' => 'wraps', 'image_path' => null, 'display_order' => 3, 'is_active' => 1];
        $db->categoriesRows = [['id' => 10], ['id' => 20]];
        $request = $this->jsonRequest('POST', '/admin/api/categories/20/move', ['direction' => 'up']);

        $response = $this->controller($request, $db)->apiMove(['id' => '20']);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertTrue($body['data']['moved']);
        self::assertTrue($db->wrote('UPDATE category SET display_order'));
    }

    public function testMoveNotFoundReturns404(): void
    {
        $db = $this->permittedDb();
        $db->categoryRow = null;
        $request = $this->jsonRequest('POST', '/admin/api/categories/999/move', ['direction' => 'up']);

        $response = $this->controller($request, $db)->apiMove(['id' => '999']);

        self::assertSame(404, $response->status());
    }
}
