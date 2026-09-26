<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin\Api;

use PHPUnit\Framework\TestCase;
use App\Auth\Authorizer;
use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\SessionGuard;
use App\Auth\SessionManager;
use App\Controllers\Admin\Api\IngredientApiController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\Request;
use App\Tests\Support\FakeDatabase;

final class TestIngredientApiController extends IngredientApiController
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

final class IngredientApiControllerTest extends TestCase
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
        $db->permissionCodes = ['stock.read', 'ingredient.manage', 'stock.manage', 'stock.count'];
        $db->grantedCodes = $db->permissionCodes;

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
            'name'               => 'Pain burger',
            'unit'               => 'unite',
            'pack_size'          => 50,
            'pack_label'         => 'carton de 50',
            'stock_capacity'     => 500,
            'low_stock_pct'      => 20,
            'critical_stock_pct' => 5,
        ], $overrides);
    }

    private function controller(Request $request, FakeDatabase $db): TestIngredientApiController
    {
        return new TestIngredientApiController($request, new Config(), new Database(new Config()), $this->session, $db);
    }

    public function testStoreRejectsMissingCsrf(): void
    {
        $db = $this->permittedDb();
        $request = $this->jsonRequest('POST', '/admin/api/ingredients', $this->validBody(), false);

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(403, $response->status());
        self::assertSame('CSRF_INVALID', $body['error']['code'] ?? null);
        self::assertFalse($db->wrote('INSERT INTO ingredient'));
    }

    public function testStoreRejectsWrongCsrfToken(): void
    {
        $db = $this->permittedDb();
        $request = new Request(
            'POST',
            '/admin/api/ingredients',
            [],
            ['content-type' => 'application/json', 'x-csrf-token' => 'un-jeton-invente'],
            (string) json_encode($this->validBody()),
            '203.0.113.5',
        );

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(403, $response->status());
        self::assertSame('CSRF_INVALID', $body['error']['code'] ?? null);
        self::assertFalse($db->wrote('INSERT INTO ingredient'));
    }

    public function testStoreValidCreatesIngredientWithoutPin(): void
    {
        $db = $this->permittedDb();
        $db->lastInsertId = 21;
        $db->ingredientRow = ['id' => 21, 'name' => 'Pain burger', 'unit' => 'unite', 'stock_quantity' => 0, 'stock_capacity' => 500, 'pack_size' => 50, 'pack_label' => 'carton de 50', 'low_stock_pct' => 20, 'critical_stock_pct' => 5, 'is_active' => 1];
        $request = $this->jsonRequest('POST', '/admin/api/ingredients', $this->validBody());

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(201, $response->status());
        self::assertSame(0, $body['data']['stock_quantity']);
        self::assertTrue($db->wrote('INSERT INTO ingredient'));
    }

    public function testStoreDuplicateNameReturns409(): void
    {
        $db = $this->permittedDb();
        $db->failOnExecute = new \PDOException('dup', 23000);
        $request = $this->jsonRequest('POST', '/admin/api/ingredients', $this->validBody());

        $response = $this->controller($request, $db)->apiStore();

        self::assertSame(409, $response->status());
    }

    public function testUpdateInvalidThresholdsReturns422(): void
    {
        $db = $this->permittedDb();
        $db->ingredientRow = ['id' => 3, 'name' => 'Pain burger', 'stock_quantity' => 10, 'unit' => 'unite', 'stock_capacity' => 500, 'pack_size' => 50, 'pack_label' => null, 'low_stock_pct' => 20, 'critical_stock_pct' => 5, 'is_active' => 1];
        $request = $this->jsonRequest('PUT', '/admin/api/ingredients/3', $this->validBody(['critical_stock_pct' => 30]));

        $response = $this->controller($request, $db)->apiUpdate(['id' => '3']);

        self::assertSame(422, $response->status());
    }

    public function testDestroyReferencedIngredientReturns409(): void
    {
        $db = $this->permittedDb();
        $db->ingredientRow = ['id' => 3, 'name' => 'Pain burger'];
        $db->failOnExecute = new \PDOException('fk', 23000);
        $request = new Request('DELETE', '/admin/api/ingredients/3', [], ['x-csrf-token' => $this->csrf], '', '203.0.113.5');

        $response = $this->controller($request, $db)->apiDestroy(['id' => '3']);

        self::assertSame(409, $response->status());
    }

    public function testRestockInactiveIngredientReturns422(): void
    {
        $db = $this->permittedDb();
        $db->ingredientRow = ['id' => 3, 'name' => 'Pain burger', 'is_active' => 0];
        $request = $this->jsonRequest('POST', '/admin/api/ingredients/3/restock', ['packs' => 5]);

        $response = $this->controller($request, $db)->apiRestock(['id' => '3']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('UPDATE ingredient SET stock_quantity'));
    }

    public function testRestockRejectsDecimalPacks(): void
    {
        $db = $this->permittedDb();
        $db->ingredientRow = ['id' => 3, 'name' => 'Pain burger', 'is_active' => 1, 'stock_quantity' => 10, 'stock_capacity' => 500];
        // 2.9 doit etre refuse comme les autres formes non-entieres (RG stock, point 1) : ni tronque, ni relu comme "2".
        $request = $this->jsonRequest('POST', '/admin/api/ingredients/3/restock', ['packs' => 2.9]);

        $response = $this->controller($request, $db)->apiRestock(['id' => '3']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('UPDATE ingredient SET stock_quantity'));
    }

    public function testRestockValidWritesMovement(): void
    {
        $db = $this->permittedDb();
        $db->ingredientRow = ['id' => 3, 'name' => 'Pain burger', 'is_active' => 1, 'stock_quantity' => 10, 'stock_capacity' => 500, 'unit' => 'unite', 'pack_size' => 50, 'pack_label' => null, 'low_stock_pct' => 20, 'critical_stock_pct' => 5];
        $request = $this->jsonRequest('POST', '/admin/api/ingredients/3/restock', ['packs' => 5, 'note' => 'reappro']);

        $response = $this->controller($request, $db)->apiRestock(['id' => '3']);

        self::assertSame(200, $response->status());
        self::assertTrue($db->wrote('INSERT INTO stock_movement'));
    }

    public function testIndexWithoutPermissionReturns403(): void
    {
        $db = $this->permittedDb();
        $db->grantedCodes = []; // aucune permission accordee

        $response = $this->controller($this->get('/admin/api/ingredients'), $db)->apiIndex();

        self::assertSame(403, $response->status());
    }

    public function testShowNotFoundReturns404(): void
    {
        $db = $this->permittedDb();
        $db->ingredientRow = null;

        $response = $this->controller($this->get('/admin/api/ingredients/9'), $db)->apiShow(['id' => '9']);

        self::assertSame(404, $response->status());
    }

    // --- toggle ---

    public function testToggleFlipsActive(): void
    {
        $db = $this->permittedDb();
        $db->ingredientRow = ['id' => 3, 'name' => 'Pain burger', 'is_active' => 1];
        $request = new Request('POST', '/admin/api/ingredients/3/toggle', [], ['x-csrf-token' => $this->csrf], '', '203.0.113.5');

        $response = $this->controller($request, $db)->apiToggle(['id' => '3']);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertFalse($body['data']['is_active']);
        self::assertTrue($db->wrote('UPDATE ingredient SET is_active'));
    }

    // --- thresholds ---

    public function testThresholdsUpdatesCapacity(): void
    {
        $db = $this->permittedDb();
        $db->ingredientRow = ['id' => 3, 'name' => 'Pain burger', 'stock_quantity' => 10, 'unit' => 'unite', 'stock_capacity' => 500, 'pack_size' => 50, 'pack_label' => null, 'low_stock_pct' => 20, 'critical_stock_pct' => 5, 'is_active' => 1];
        $request = $this->jsonRequest('PUT', '/admin/api/ingredients/3/thresholds', ['stock_capacity' => 600, 'low_stock_pct' => 20, 'critical_stock_pct' => 5]);

        $response = $this->controller($request, $db)->apiThresholds(['id' => '3']);

        self::assertSame(200, $response->status());
        self::assertTrue($db->wrote('UPDATE ingredient SET stock_capacity'));
    }

    public function testThresholdsRejectsCriticalAboveLow(): void
    {
        $db = $this->permittedDb();
        $db->ingredientRow = ['id' => 3, 'stock_quantity' => 10];
        $request = $this->jsonRequest('PUT', '/admin/api/ingredients/3/thresholds', ['stock_capacity' => 600, 'low_stock_pct' => 5, 'critical_stock_pct' => 20]);

        $response = $this->controller($request, $db)->apiThresholds(['id' => '3']);

        self::assertSame(422, $response->status());
    }

    // --- inventory (stock.count + PIN) ---

    public function testInventoryRequiresPin(): void
    {
        $db = $this->permittedDb();
        $db->ingredientRow = ['id' => 3, 'name' => 'Pain burger'];
        $request = $this->jsonRequest('POST', '/admin/api/ingredients/3/inventory', ['actual_quantity' => 42]);

        $response = $this->controller($request, $db)->apiInventory(['id' => '3']);
        $body = json_decode($response->body(), true);

        self::assertSame(422, $response->status());
        self::assertSame('PIN_INVALID', $body['error']['code'] ?? null);
        self::assertFalse($db->wrote('INSERT INTO stock_movement'));
    }

    public function testInventoryRejectsDecimalActualQuantity(): void
    {
        $db = $this->permittedDb();
        $db->ingredientRow = ['id' => 3, 'name' => 'Pain burger'];
        $this->actingPin($db);
        $request = $this->jsonRequest('POST', '/admin/api/ingredients/3/inventory', ['actual_quantity' => 2.9, 'pin_email' => 'e@e.fr', 'pin' => '4729']);

        $response = $this->controller($request, $db)->apiInventory(['id' => '3']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO stock_movement'));
    }

    public function testInventoryWithValidPinWritesMovement(): void
    {
        $db = $this->permittedDb();
        $db->ingredientRow = ['id' => 3, 'name' => 'Pain burger', 'stock_capacity' => 500];
        $this->actingPin($db);
        $request = $this->jsonRequest('POST', '/admin/api/ingredients/3/inventory', ['actual_quantity' => 42, 'pin_email' => 'e@e.fr', 'pin' => '4729']);

        $response = $this->controller($request, $db)->apiInventory(['id' => '3']);

        self::assertSame(200, $response->status());
        self::assertTrue($db->wrote('INSERT INTO stock_movement'));
        // RG-T14 : pas d'audit_log au succes de l'inventaire (stock_movement suffit).
        self::assertSame([], $db->auditActions());
    }

    // --- adjust (stock.count + PIN) ---

    public function testAdjustRejectsZeroDelta(): void
    {
        $db = $this->permittedDb();
        $db->ingredientRow = ['id' => 3, 'name' => 'Pain burger'];
        $request = $this->jsonRequest('POST', '/admin/api/ingredients/3/adjust', ['delta' => 0, 'pin_email' => 'e@e.fr', 'pin' => '4729']);

        $response = $this->controller($request, $db)->apiAdjust(['id' => '3']);

        self::assertSame(422, $response->status());
    }

    public function testAdjustWithValidPinWritesMovement(): void
    {
        $db = $this->permittedDb();
        $db->ingredientRow = ['id' => 3, 'name' => 'Pain burger', 'stock_capacity' => 500];
        $this->actingPin($db);
        $request = $this->jsonRequest('POST', '/admin/api/ingredients/3/adjust', ['delta' => -5, 'pin_email' => 'e@e.fr', 'pin' => '4729']);

        $response = $this->controller($request, $db)->apiAdjust(['id' => '3']);

        self::assertSame(200, $response->status());
        self::assertTrue($db->wrote('INSERT INTO stock_movement'));
    }

    // --- allergens ---

    public function testAllergensRequiresSource(): void
    {
        $db = $this->permittedDb();
        $db->ingredientRow = ['id' => 3, 'name' => 'Pain burger'];
        $db->allergensRows = [['id' => 1, 'name' => 'Gluten']];
        $request = $this->jsonRequest('PUT', '/admin/api/ingredients/3/allergens', ['allergen_ids' => [1], 'source' => '']);

        $response = $this->controller($request, $db)->apiAllergens(['id' => '3']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('DELETE FROM ingredient_allergen'));
    }

    public function testAllergensRejectsDecimalAllergenId(): void
    {
        // fieldIntList() : entiers STRICTS (relecture point 6) -- "1.9" doit etre
        // refuse (422), jamais tronque en silence a 1.
        $db = $this->permittedDb();
        $db->ingredientRow = ['id' => 3, 'name' => 'Pain burger'];
        $db->allergensRows = [['id' => 1, 'name' => 'Gluten']];
        $request = $this->jsonRequest('PUT', '/admin/api/ingredients/3/allergens', ['allergen_ids' => ['1.9'], 'source' => 'Fiche fournisseur']);

        $response = $this->controller($request, $db)->apiAllergens(['id' => '3']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('DELETE FROM ingredient_allergen'));
    }

    public function testAllergensWithSourceUpdatesReview(): void
    {
        $db = $this->permittedDb();
        $db->ingredientRow = ['id' => 3, 'name' => 'Pain burger'];
        $db->allergensRows = [['id' => 1, 'name' => 'Gluten'], ['id' => 2, 'name' => 'Lait']];
        $request = $this->jsonRequest('PUT', '/admin/api/ingredients/3/allergens', ['allergen_ids' => [1], 'source' => 'Fiche fournisseur']);

        $response = $this->controller($request, $db)->apiAllergens(['id' => '3']);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertCount(1, $body['data']['allergens']);
        self::assertTrue($db->wrote('DELETE FROM ingredient_allergen'));
        self::assertTrue($db->wrote('INSERT INTO ingredient_allergen'));
        self::assertSame(['ingredient.allergens'], $db->auditActions());
    }
}
