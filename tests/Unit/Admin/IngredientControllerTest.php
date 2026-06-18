<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PDOException;
use PHPUnit\Framework\TestCase;
use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\SessionManager;
use App\Controllers\IngredientController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\Request;
use App\Tests\Support\FakeDatabase;

/**
 * Sous-classe de test : le seam db() injecte le double, sessionManager() la session.
 */
final class TestIngredientController extends IngredientController
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

final class IngredientControllerTest extends TestCase
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
        $db->userDisplayRow = ['first_name' => 'Sam', 'last_name' => 'K', 'role_label' => 'Manager'];
        $db->canResult = true;
        $db->permissionCodes = ['stock.read', 'ingredient.manage', 'stock.manage', 'stock.count'];
        $db->ingredientRow = $this->ingredient();

        return $db;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function ingredient(array $overrides = []): array
    {
        return array_merge([
            'id' => 5, 'name' => 'Cheddar', 'unit' => 'tranche',
            'stock_quantity' => 40, 'stock_capacity' => 100, 'pack_size' => 10,
            'pack_label' => 'Sachet 10', 'low_stock_pct' => 10, 'critical_stock_pct' => 5,
            'is_active' => 1,
        ], $overrides);
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function validForm(array $overrides = []): array
    {
        return array_merge([
            '_csrf' => $this->csrf,
            'name' => 'Cheddar',
            'unit' => 'tranche',
            'stock_capacity' => '100',
            'pack_size' => '10',
            'pack_label' => 'Sachet 10',
            'low_stock_pct' => '10',
            'critical_stock_pct' => '5',
        ], $overrides);
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
     * @param array<string, string> $form
     */
    private function post(array $form, string $path): Request
    {
        return new Request('POST', $path, [], ['content-type' => 'application/x-www-form-urlencoded'], http_build_query($form), '203.0.113.5');
    }

    private function controller(Request $request, FakeDatabase $db): TestIngredientController
    {
        return new TestIngredientController($request, new Config(), new Database(new Config()), $this->session, $db);
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

    private function writeSql(FakeDatabase $db, string $needle): string
    {
        foreach ($db->writes as $write) {
            if (str_contains($write['sql'], $needle)) {
                return $write['sql'];
            }
        }

        return '';
    }

    // --- Lecture (READ_STOCK 9.3) ---

    public function testIndexListsStockForStockReader(): void
    {
        $db = $this->permittedDb();
        $db->ingredientsRows = [$this->ingredient(['stock_quantity' => 8])]; // 8% -> bande alerte

        $response = $this->controller($this->get('/admin/ingredients'), $db)->index();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Cheddar', $response->body());
        self::assertStringContainsString('Alerte', $response->body());
    }

    public function testIndexForbiddenWithoutStockRead(): void
    {
        $db = $this->permittedDb();
        $db->canResult = false;

        self::assertSame(403, $this->controller($this->get('/admin/ingredients'), $db)->index()->status());
    }

    // --- CRUD ingredient (8.8, ingredient.manage, SANS PIN) ---

    public function testStoreCreatesWithZeroStockAndActiveServerSet(): void
    {
        $db = $this->permittedDb();
        $response = $this->controller($this->post($this->validForm(), '/admin/ingredients'), $db)->store();

        self::assertSame(302, $response->status());
        $params = $this->writeParams($db, 'INSERT INTO ingredient');
        self::assertNotNull($params);
        self::assertSame(0, $params['qty']);    // stock_quantity initial = 0 (RG-CREATE-ING)
        self::assertSame(1, $params['active']); // is_active pose cote serveur (RG-T16)
    }

    public function testStoreRejectsInvalidInput(): void
    {
        $db = $this->permittedDb();
        $response = $this->controller($this->post($this->validForm(['name' => '', 'stock_capacity' => '0']), '/admin/ingredients'), $db)->store();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO ingredient'));
    }

    public function testStoreRejectsCriticalNotStrictlyBelowLow(): void
    {
        $db = $this->permittedDb();
        $response = $this->controller($this->post($this->validForm(['low_stock_pct' => '5', 'critical_stock_pct' => '5']), '/admin/ingredients'), $db)->store();

        self::assertSame(422, $response->status());
        self::assertStringContainsString('strictement inferieur', $response->body());
    }

    public function testStoreRejectsDuplicateName(): void
    {
        $db = $this->permittedDb();
        $db->ingredientNameTaken = true;

        $response = $this->controller($this->post($this->validForm(), '/admin/ingredients'), $db)->store();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO ingredient'));
    }

    public function testStoreTranslatesUniqueRaceTo409(): void
    {
        $db = $this->permittedDb();
        $db->failOnExecute = new PDOException('duplicate', 23000);

        $response = $this->controller($this->post($this->validForm(), '/admin/ingredients'), $db)->store();

        self::assertSame(409, $response->status());
    }

    public function testStoreRejectsInvalidCsrf(): void
    {
        $db = $this->permittedDb();
        $response = $this->controller($this->post($this->validForm(['_csrf' => 'bad']), '/admin/ingredients'), $db)->store();

        self::assertSame(403, $response->status());
    }

    public function testUpdateDoesNotBindStockOrActive(): void
    {
        $db = $this->permittedDb();
        $response = $this->controller($this->post($this->validForm(), '/admin/ingredients/5'), $db)->update(['id' => '5']);

        self::assertSame(302, $response->status());
        $sql = $this->writeSql($db, 'UPDATE ingredient');
        self::assertNotSame('', $sql);
        self::assertStringNotContainsString('stock_quantity', $sql); // RG-T16
        self::assertStringNotContainsString('is_active', $sql);      // RG-T16 (bascule via toggle)
    }

    public function testUpdateNotFound(): void
    {
        $db = $this->permittedDb();
        $db->ingredientRow = null;

        self::assertSame(404, $this->controller($this->post($this->validForm(), '/admin/ingredients/9'), $db)->update(['id' => '9'])->status());
    }

    public function testToggleFlipsActive(): void
    {
        $db = $this->permittedDb(); // is_active = 1 -> doit basculer a 0
        $response = $this->controller($this->post(['_csrf' => $this->csrf], '/admin/ingredients/5/toggle'), $db)->toggle(['id' => '5']);

        self::assertSame(302, $response->status());
        $params = $this->writeParams($db, 'UPDATE ingredient SET is_active');
        self::assertNotNull($params);
        self::assertSame(0, $params['a']);
    }

    public function testDestroyUnreferencedDeletesWithoutPin(): void
    {
        $db = $this->permittedDb();
        // Aucun champ PIN dans le form : 8.8 n'est pas une action sensible.
        $response = $this->controller($this->post(['_csrf' => $this->csrf], '/admin/ingredients/5/delete'), $db)->destroy(['id' => '5']);

        self::assertSame(302, $response->status());
        self::assertTrue($db->wrote('DELETE FROM ingredient'));
    }

    public function testDestroyReferencedReturns409(): void
    {
        $db = $this->permittedDb();
        $db->failOnExecute = new PDOException('fk', 23000); // FK RESTRICT (recette / mouvement)

        $response = $this->controller($this->post(['_csrf' => $this->csrf], '/admin/ingredients/5/delete'), $db)->destroy(['id' => '5']);

        self::assertSame(409, $response->status());
        self::assertStringContainsString('reference', $response->body());
    }

    // --- RESTOCK (9.1, stock.manage, SANS PIN) ---

    public function testRestockAddsPacksAndRecordsMovementUnderSessionActor(): void
    {
        $db = $this->permittedDb(); // pack_size 10
        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'packs' => '2', 'note' => 'Livraison A'], '/admin/ingredients/5/restock'), $db)->restock(['id' => '5']);

        self::assertSame(302, $response->status());
        self::assertSame(['begin', 'commit'], $db->transactionEvents);
        self::assertTrue($db->wrote('SET stock_quantity = stock_quantity +'));
        $movement = $this->writeParams($db, 'INSERT INTO stock_movement');
        self::assertNotNull($movement);
        self::assertSame('restock', $movement['type']);
        self::assertSame(20, $movement['delta']);  // 2 packs x pack_size 10
        self::assertSame(1, $movement['user']);    // acteur de SESSION (RG-4), pas un PIN
        self::assertSame([], $db->auditActions()); // pas d'audit_log (RG-T14)
    }

    public function testRestockRejectedWhenInactive(): void
    {
        $db = $this->permittedDb();
        $db->ingredientRow = $this->ingredient(['is_active' => 0]); // PRE-2

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'packs' => '2'], '/admin/ingredients/5/restock'), $db)->restock(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('stock_movement'));
    }

    public function testRestockRejectsPacksBelowOne(): void
    {
        $db = $this->permittedDb();
        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'packs' => '0'], '/admin/ingredients/5/restock'), $db)->restock(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('stock_movement'));
    }

    // --- INVENTORY_COUNT (9.2, stock.count + PIN) ---

    public function testInventoryWithValidPinRecordsCorrectionUnderPinActorWithoutAudit(): void
    {
        $db = $this->permittedDb();
        $this->actingPin($db); // equipier id 9, PIN 4729

        $response = $this->controller($this->post([
            '_csrf' => $this->csrf, 'actual_quantity' => '30', 'note' => 'mensuel',
            'pin_email' => 'sam@wakdo.local', 'pin' => '4729',
        ], '/admin/ingredients/5/inventory'), $db)->inventory(['id' => '5']);

        self::assertSame(302, $response->status());
        $movement = $this->writeParams($db, 'INSERT INTO stock_movement');
        self::assertNotNull($movement);
        self::assertSame('inventory_correction', $movement['type']);
        self::assertSame(-10, $movement['delta']);  // 30 compte - 40 theorique
        self::assertSame(9, $movement['user']);     // acteur resolu par PIN (RG-4)
        self::assertSame([], $db->auditActions());  // RG-T14 : pas de double-journal
    }

    public function testInventoryWithBadPinLogsFailedAndChangesNoStock(): void
    {
        $db = $this->permittedDb();
        $db->actingUserRow = null; // email/PIN non resolu

        $response = $this->controller($this->post([
            '_csrf' => $this->csrf, 'actual_quantity' => '30',
            'pin_email' => 'ghost@wakdo.local', 'pin' => '0000',
        ], '/admin/ingredients/5/inventory'), $db)->inventory(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertSame(['pin.failed'], $db->auditActions()); // trace detective (RG-T22)
        self::assertFalse($db->wrote('stock_movement'));        // aucun effet sur le stock
    }

    public function testInventoryLockedActorReturns422WithoutEffect(): void
    {
        $db = $this->permittedDb();
        $this->actingPin($db);
        $db->pinThrottleLockoutUntil = date('Y-m-d H:i:s', time() + 300); // verrou actif

        $response = $this->controller($this->post([
            '_csrf' => $this->csrf, 'actual_quantity' => '30',
            'pin_email' => 'sam@wakdo.local', 'pin' => '4729',
        ], '/admin/ingredients/5/inventory'), $db)->inventory(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertSame([], $db->auditActions());       // pas de pin.failed sous verrou (RG-T22)
        self::assertFalse($db->wrote('stock_movement'));
    }

    public function testInventoryRejectsNegativeCount(): void
    {
        $db = $this->permittedDb();
        $this->actingPin($db);

        $response = $this->controller($this->post([
            '_csrf' => $this->csrf, 'actual_quantity' => '-5',
            'pin_email' => 'sam@wakdo.local', 'pin' => '4729',
        ], '/admin/ingredients/5/inventory'), $db)->inventory(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('stock_movement'));
    }

    // --- Visibilite de l'acteur (RG-4) ---

    public function testMovementsShowActorForManager(): void
    {
        $db = $this->permittedDb();
        $db->grantedCodes = ['stock.read', 'stock.manage']; // manager
        $db->movementsRows = [['id' => 1, 'ingredient_id' => 5, 'movement_type' => 'restock', 'delta' => 20, 'order_id' => null, 'user_id' => 9, 'note' => null, 'created_at' => '2026-06-17 09:00:00']];

        $response = $this->controller($this->get('/admin/ingredients/5/movements'), $db)->movements(['id' => '5']);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Auteur', $response->body());
        self::assertStringContainsString('Sam K', $response->body()); // nom resolu
    }

    public function testMovementsHideActorForLineStaff(): void
    {
        $db = $this->permittedDb();
        $db->grantedCodes = ['stock.read']; // ligne : stock.read sans stock.manage
        $db->movementsRows = [['id' => 1, 'ingredient_id' => 5, 'movement_type' => 'restock', 'delta' => 20, 'order_id' => null, 'user_id' => 9, 'note' => null, 'created_at' => '2026-06-17 09:00:00']];

        $response = $this->controller($this->get('/admin/ingredients/5/movements'), $db)->movements(['id' => '5']);

        self::assertSame(200, $response->status());
        self::assertStringNotContainsString('Auteur', $response->body()); // colonne masquee (RG-4)
    }
}
