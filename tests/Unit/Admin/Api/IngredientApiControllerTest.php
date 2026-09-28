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

    /**
     * @return array<string|int, mixed>|null
     */
    private function writeParams(FakeDatabase $db, string $needle): ?array
    {
        foreach ($db->writes as $write) {
            if (str_contains($write['sql'], $needle)) {
                return $write['params'];
            }
        }

        return null;
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

    // --- Famille (migration 0017) : parite CRUD JSON / formulaire HTML ---

    public function testStorePersistsAndPresentsTheFamily(): void
    {
        $db = $this->permittedDb();
        $db->lastInsertId = 21;
        $db->ingredientRow = ['id' => 21, 'name' => 'Cheddar', 'unit' => 'unite', 'family' => 'fromage', 'stock_quantity' => 0, 'stock_capacity' => 500, 'pack_size' => 50, 'pack_label' => null, 'low_stock_pct' => 20, 'critical_stock_pct' => 5, 'is_active' => 1];
        $request = $this->jsonRequest('POST', '/admin/api/ingredients', $this->validBody(['family' => 'fromage']));

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(201, $response->status());
        self::assertSame('fromage', $this->writeParams($db, 'INSERT INTO ingredient')['family'] ?? 'missing');
        self::assertSame('fromage', $body['data']['family'] ?? 'missing');
    }

    public function testStoreOmittingFamilyPersistsAndPresentsNullNotEmptyString(): void
    {
        // Corps JSON qui n'envoie PAS `family` du tout (creation) : non classe,
        // ecrit NULL en base, et PRESENTE `null` -- jamais une chaine vide qui se
        // ferait passer pour l'un ou l'autre cote client (meme regle que pack_label).
        $db = $this->permittedDb();
        $db->lastInsertId = 22;
        $db->ingredientRow = ['id' => 22, 'name' => 'Cheddar', 'unit' => 'unite', 'family' => null, 'stock_quantity' => 0, 'stock_capacity' => 500, 'pack_size' => 50, 'pack_label' => null, 'low_stock_pct' => 20, 'critical_stock_pct' => 5, 'is_active' => 1];
        $request = $this->jsonRequest('POST', '/admin/api/ingredients', $this->validBody());

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(201, $response->status());
        $params = $this->writeParams($db, 'INSERT INTO ingredient');
        self::assertNotNull($params);
        self::assertArrayHasKey('family', $params);
        self::assertNull($params['family']);
        self::assertArrayHasKey('family', $body['data']);
        self::assertNull($body['data']['family']);
    }

    public function testStoreRejectsAFamilyOutsideTheCanonicalListWith422(): void
    {
        $db = $this->permittedDb();
        $request = $this->jsonRequest('POST', '/admin/api/ingredients', $this->validBody(['family' => 'boisson']));

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(422, $response->status());
        self::assertSame('VALIDATION_ERROR', $body['error']['code'] ?? null);
        self::assertArrayHasKey('family', $body['error']['fields'] ?? []);
        self::assertFalse($db->wrote('INSERT INTO ingredient'));
    }

    public function testUpdateOmittingFamilyResetsItToUnclassified(): void
    {
        // Documente le motif EXISTANT (meme que pack_label, deja ainsi avant ce
        // lot) : ce endpoint remplace la ressource ENTIERE (PUT), pas un PATCH
        // partiel. Un ingredient DEJA classe 'dessert', modifie par un corps JSON
        // qui n'envoie pas `family`, la perd -- assume et fige par ce test.
        $db = $this->permittedDb();
        $db->ingredientRow = ['id' => 3, 'name' => 'Brownie', 'unit' => 'unite', 'family' => 'dessert', 'stock_quantity' => 10, 'stock_capacity' => 500, 'pack_size' => 50, 'pack_label' => null, 'low_stock_pct' => 20, 'critical_stock_pct' => 5, 'is_active' => 1];
        $request = $this->jsonRequest('PUT', '/admin/api/ingredients/3', $this->validBody(['name' => 'Brownie']));

        $response = $this->controller($request, $db)->apiUpdate(['id' => '3']);

        self::assertSame(200, $response->status());
        $params = $this->writeParams($db, 'UPDATE ingredient SET name');
        self::assertNotNull($params);
        self::assertArrayHasKey('family', $params);
        self::assertNull($params['family']);
    }

    public function testUpdateRejectsAFamilyOutsideTheCanonicalListWith422(): void
    {
        $db = $this->permittedDb();
        $db->ingredientRow = ['id' => 3, 'name' => 'Pain burger', 'stock_quantity' => 10, 'unit' => 'unite', 'stock_capacity' => 500, 'pack_size' => 50, 'pack_label' => null, 'low_stock_pct' => 20, 'critical_stock_pct' => 5, 'is_active' => 1];
        $request = $this->jsonRequest('PUT', '/admin/api/ingredients/3', $this->validBody(['family' => 'boisson']));

        $response = $this->controller($request, $db)->apiUpdate(['id' => '3']);
        $body = json_decode($response->body(), true);

        self::assertSame(422, $response->status());
        self::assertArrayHasKey('family', $body['error']['fields'] ?? []);
    }

    public function testIndexPresentsTheFamilyOfEachIngredient(): void
    {
        $db = $this->permittedDb();
        $db->ingredientsRows = [
            ['id' => 1, 'name' => 'Cheddar', 'unit' => 'tranche', 'family' => 'fromage', 'stock_quantity' => 40, 'stock_capacity' => 100, 'is_active' => 1],
            ['id' => 2, 'name' => 'Gobelet', 'unit' => 'pièce', 'family' => null, 'stock_quantity' => 40, 'stock_capacity' => 100, 'is_active' => 1],
        ];

        $response = $this->controller($this->get('/admin/api/ingredients'), $db)->apiIndex();
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertSame('fromage', $body['data'][0]['family'] ?? 'missing');
        self::assertArrayHasKey('family', $body['data'][1]);
        self::assertNull($body['data'][1]['family']);
    }

    public function testShowPresentsNullNotEmptyStringForAnUnclassifiedIngredient(): void
    {
        $db = $this->permittedDb();
        $db->ingredientRow = ['id' => 2, 'name' => 'Gobelet', 'unit' => 'pièce', 'family' => null, 'stock_quantity' => 40, 'stock_capacity' => 100, 'pack_size' => 1, 'pack_label' => null, 'low_stock_pct' => 10, 'critical_stock_pct' => 5, 'is_active' => 1];

        $response = $this->controller($this->get('/admin/api/ingredients/2'), $db)->apiShow(['id' => '2']);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertArrayHasKey('family', $body['data']);
        self::assertNull($body['data']['family']);
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
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertTrue($db->wrote('INSERT INTO stock_movement'));
        // 5 packs x 50 = +250, sous la capacite (500) : applique integralement.
        self::assertSame(250, $body['data']['applied_delta'] ?? null);
        self::assertSame(250, $body['data']['requested_delta'] ?? null);
        self::assertFalse($body['data']['clamped'] ?? null);
    }

    /**
     * Traitement symetrique de l'API JSON (meme bug que le formulaire HTML,
     * 2026-09-26) : le depot plafonne deja le stock a la capacite
     * (IngredientRepository::restock()), mais sans ces champs le client API ne
     * pouvait pas distinguer un reappro a plein effet d'un reappro qui n'a
     * strictement rien change (ingredient deja plein).
     */
    public function testRestockClampedToCapacityReportsClampedTrue(): void
    {
        $db = $this->permittedDb();
        $db->ingredientRow = ['id' => 3, 'name' => 'Pain burger', 'is_active' => 1, 'stock_quantity' => 500, 'stock_capacity' => 500, 'unit' => 'unite', 'pack_size' => 10, 'pack_label' => null, 'low_stock_pct' => 20, 'critical_stock_pct' => 5];
        $request = $this->jsonRequest('POST', '/admin/api/ingredients/3/restock', ['packs' => 2]);

        $response = $this->controller($request, $db)->apiRestock(['id' => '3']);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertSame(0, $body['data']['applied_delta'] ?? null);
        self::assertSame(20, $body['data']['requested_delta'] ?? null); // 2 packs x 10
        self::assertTrue($body['data']['clamped'] ?? null);
    }

    public function testIndexWithoutPermissionReturns403(): void
    {
        $db = $this->permittedDb();
        $db->grantedCodes = []; // aucune permission accordee

        $response = $this->controller($this->get('/admin/api/ingredients'), $db)->apiIndex();

        self::assertSame(403, $response->status());
    }

    // --- historique des mouvements (lecture, meme regle que la page du back-office) ---

    private function withHistory(FakeDatabase $db): void
    {
        $db->ingredientRow = ['id' => 21, 'name' => 'Pain burger', 'unit' => 'unite', 'stock_quantity' => 40, 'stock_capacity' => 500, 'pack_size' => 50, 'pack_label' => 'carton de 50', 'low_stock_pct' => 20, 'critical_stock_pct' => 5, 'is_active' => 1];
        $db->movementsRows = [
            ['id' => 5, 'ingredient_id' => 21, 'movement_type' => 'restock', 'delta' => 50, 'order_id' => null, 'user_id' => 7, 'note' => 'livraison', 'created_at' => '2026-09-28 09:00:00'],
            ['id' => 4, 'ingredient_id' => 21, 'movement_type' => 'sale', 'delta' => -10, 'order_id' => 88, 'user_id' => null, 'note' => null, 'created_at' => '2026-09-27 12:00:00'],
        ];
        $db->userDisplayRow = ['first_name' => 'Rita', 'last_name' => 'Balayage', 'email' => 'rita@wakdo.local', 'role_label' => 'Responsable', 'order_source' => null];
    }

    public function testMovementsListTheHistoryWithTheActorForAHolderOfStockManage(): void
    {
        $db = $this->permittedDb();
        $this->withHistory($db);

        $response = $this->controller($this->get('/admin/api/ingredients/21/movements'), $db)->apiMovements(['id' => '21']);

        self::assertSame(200, $response->status());
        $data = json_decode($response->body(), true)['data'];
        self::assertSame(21, $data['ingredient']['id']);
        self::assertCount(2, $data['movements']);
        self::assertSame(['id' => 5, 'type' => 'restock', 'delta' => 50, 'order_id' => null, 'note' => 'livraison', 'created_at' => '2026-09-28 09:00:00', 'actor' => ['id' => 7, 'name' => 'Rita Balayage']], $data['movements'][0]);
        self::assertNull($data['movements'][1]['actor'], 'une vente n\'a pas d\'auteur : actor vaut null');
        self::assertTrue($data['actor_visible']);
    }

    public function testMovementsHideWhoActedFromARoleWithoutStockManage(): void
    {
        // RG-4 (9.3) : le personnel de ligne voit les quantites, pas l'auteur.
        $db = $this->permittedDb();
        $db->grantedCodes = ['stock.read'];
        $this->withHistory($db);

        $response = $this->controller($this->get('/admin/api/ingredients/21/movements'), $db)->apiMovements(['id' => '21']);

        self::assertSame(200, $response->status());
        $data = json_decode($response->body(), true)['data'];
        self::assertFalse($data['actor_visible']);
        foreach ($data['movements'] as $movement) {
            self::assertArrayNotHasKey('actor', $movement);
        }
        self::assertStringNotContainsString('Rita', $response->body());
    }

    public function testMovementsOfAnUnknownIngredientReturn404(): void
    {
        $db = $this->permittedDb();
        $db->ingredientRow = null;

        $response = $this->controller($this->get('/admin/api/ingredients/9/movements'), $db)->apiMovements(['id' => '9']);

        self::assertSame(404, $response->status());
    }

    public function testMovementsWithoutStockReadReturn403(): void
    {
        $db = $this->permittedDb();
        $db->grantedCodes = [];
        $this->withHistory($db);

        $response = $this->controller($this->get('/admin/api/ingredients/21/movements'), $db)->apiMovements(['id' => '21']);

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
        $db->ingredientRow = ['id' => 3, 'name' => 'Pain burger', 'stock_quantity' => 0, 'stock_capacity' => 500];
        $this->actingPin($db);
        $request = $this->jsonRequest('POST', '/admin/api/ingredients/3/inventory', ['actual_quantity' => 42, 'pin_email' => 'e@e.fr', 'pin' => '4729']);

        $response = $this->controller($request, $db)->apiInventory(['id' => '3']);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertTrue($db->wrote('INSERT INTO stock_movement'));
        // RG-T14 : pas d'audit_log au succes de l'inventaire (stock_movement suffit).
        self::assertSame([], $db->auditActions());
        // Compte sous la capacite : retenu integralement.
        self::assertSame(42, $body['data']['applied_delta'] ?? null);
        self::assertSame(42, $body['data']['requested_delta'] ?? null);
        self::assertFalse($body['data']['clamped'] ?? null);
    }

    /** Traitement symetrique de l'API JSON (meme bug que le formulaire HTML). */
    public function testInventoryAboveCapacityReportsClampedTrue(): void
    {
        $db = $this->permittedDb();
        $db->ingredientRow = ['id' => 3, 'name' => 'Pain burger', 'stock_quantity' => 100, 'stock_capacity' => 500];
        $this->actingPin($db);
        $request = $this->jsonRequest('POST', '/admin/api/ingredients/3/inventory', ['actual_quantity' => 5000, 'pin_email' => 'e@e.fr', 'pin' => '4729']);

        $response = $this->controller($request, $db)->apiInventory(['id' => '3']);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        // Compte plafonne a la capacite (500) : retenu = 500, pas 5000.
        self::assertSame(400, $body['data']['applied_delta'] ?? null);   // 500 - 100
        self::assertSame(4900, $body['data']['requested_delta'] ?? null); // 5000 - 100
        self::assertTrue($body['data']['clamped'] ?? null);
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
        $db->ingredientRow = ['id' => 3, 'name' => 'Pain burger', 'stock_quantity' => 50, 'stock_capacity' => 500];
        $this->actingPin($db);
        $request = $this->jsonRequest('POST', '/admin/api/ingredients/3/adjust', ['delta' => -5, 'pin_email' => 'e@e.fr', 'pin' => '4729']);

        $response = $this->controller($request, $db)->apiAdjust(['id' => '3']);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertTrue($db->wrote('INSERT INTO stock_movement'));
        self::assertSame(-5, $body['data']['applied_delta'] ?? null);
        self::assertSame(-5, $body['data']['requested_delta'] ?? null);
        self::assertFalse($body['data']['clamped'] ?? null);
    }

    /** Traitement symetrique de l'API JSON (meme bug que le formulaire HTML). */
    public function testAdjustClampedToCapacityReportsClampedTrue(): void
    {
        $db = $this->permittedDb();
        $db->ingredientRow = ['id' => 3, 'name' => 'Pain burger', 'stock_quantity' => 495, 'stock_capacity' => 500];
        $this->actingPin($db);
        $request = $this->jsonRequest('POST', '/admin/api/ingredients/3/adjust', ['delta' => 50, 'pin_email' => 'e@e.fr', 'pin' => '4729']);

        $response = $this->controller($request, $db)->apiAdjust(['id' => '3']);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertSame(5, $body['data']['applied_delta'] ?? null);   // 495 -> 500, plafonne
        self::assertSame(50, $body['data']['requested_delta'] ?? null);
        self::assertTrue($body['data']['clamped'] ?? null);
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
