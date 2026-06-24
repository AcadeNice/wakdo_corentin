<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use App\Auth\Csrf;
use App\Auth\SessionManager;
use App\Controllers\CounterOrderController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\Request;
use App\Order\OrderQueryRepository;
use App\Tests\Support\FakeDatabase;

/**
 * Stub OrderQueryRepository : liste canned multi-source (rendu de la liste teste sans
 * base). recent() ramene tous canaux ; le controleur filtre par source derivee du chemin.
 * paidQueue() ramene la file "En cours" canned, deja filtree par source par l'appelant.
 */
final class StubChannelOrders extends OrderQueryRepository
{
    public function recent(int $limit = 50): array
    {
        return [
            ['order_number' => 'C100', 'source' => 'counter', 'service_mode' => 'dine_in', 'service_tag' => null, 'status' => 'paid', 'total_ttc_cents' => 890, 'created_at' => '2026-06-22 10:00:00', 'paid_at' => '2026-06-22 10:00:01'],
            ['order_number' => 'D200', 'source' => 'drive', 'service_mode' => 'drive', 'service_tag' => null, 'status' => 'paid', 'total_ttc_cents' => 990, 'created_at' => '2026-06-22 10:05:00', 'paid_at' => '2026-06-22 10:05:01'],
            ['order_number' => 'K9', 'source' => 'kiosk', 'service_mode' => 'takeaway', 'service_tag' => null, 'status' => 'paid', 'total_ttc_cents' => 500, 'created_at' => '2026-06-22 10:06:00', 'paid_at' => '2026-06-22 10:06:01'],
        ];
    }

    public function paidQueue(array $sources): array
    {
        // File "En cours" du canal : ne ramene que des commandes dont la source est
        // dans $sources (le controleur passe la SEULE source du canal courant). C100 est
        // sur place avec un numero de table (12) ; D200 est un drive sans table.
        $all = [
            ['order_number' => 'C100', 'source' => 'counter', 'service_mode' => 'dine_in', 'service_tag' => '12', 'total_ttc_cents' => 890, 'paid_at' => '2026-06-22 10:00:01'],
            ['order_number' => 'D200', 'source' => 'drive', 'service_mode' => 'drive', 'service_tag' => null, 'total_ttc_cents' => 990, 'paid_at' => '2026-06-22 10:05:01'],
        ];

        return array_values(array_filter(
            $all,
            static fn (array $o): bool => in_array($o['source'], $sources, true),
        ));
    }
}

final class TestCounterOrderController extends CounterOrderController
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

    protected function orderQuery(): OrderQueryRepository
    {
        return new StubChannelOrders($this->fakeDb);
    }
}

final class CounterOrderControllerTest extends TestCase
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
        $this->session->set('user_id', 7);
        $this->session->set('role_id', 4);
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
        $db->userDisplayRow = ['first_name' => 'Sam', 'last_name' => 'C', 'role_label' => 'Comptoir'];
        $db->canResult = true;
        $db->permissionCodes = ['order.read', 'order.create'];

        return $db;
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

    private function controller(Request $request, FakeDatabase $db): TestCounterOrderController
    {
        return new TestCounterOrderController($request, new Config(), new Database(new Config()), $this->session, $db);
    }

    public function testIndexRequiresOrderCreate(): void
    {
        $db = $this->permittedDb();
        $db->canResult = false;
        $db->permissionCodes = [];

        self::assertSame(403, $this->controller($this->get('/counter/orders'), $db)->index()->status());
    }

    public function testCounterIndexListsOnlyCounterOrders(): void
    {
        $response = $this->controller($this->get('/counter/orders'), $this->permittedDb())->index();

        self::assertSame(200, $response->status());
        $body = $response->body();
        self::assertStringContainsString('Commandes comptoir', $body);
        self::assertStringContainsString('C100', $body);   // canal counter present
        self::assertStringNotContainsString('D200', $body); // canal drive exclu
        self::assertStringNotContainsString('K9', $body);   // kiosk exclu
        self::assertStringContainsString('Nouvelle commande', $body);
    }

    public function testDriveIndexListsOnlyDriveOrders(): void
    {
        $response = $this->controller($this->get('/drive/orders'), $this->permittedDb())->index();

        self::assertSame(200, $response->status());
        $body = $response->body();
        self::assertStringContainsString('Commandes drive', $body);
        self::assertStringContainsString('D200', $body);
        self::assertStringNotContainsString('C100', $body);
    }

    public function testCreateRendersProductComposer(): void
    {
        $db = $this->permittedDb();
        $db->productsRows = [
            ['id' => 12, 'category_id' => 1, 'name' => 'Cheeseburger', 'description' => null, 'price_cents' => 890, 'image_path' => null, 'display_order' => 1],
        ];

        $response = $this->controller($this->get('/counter/orders/new'), $db)->create();

        self::assertSame(200, $response->status());
        $body = $response->body();
        self::assertStringContainsString('Cheeseburger', $body);
        // POS tactile : le catalogue est embarque dans un script JSON inerte (id/prix),
        // pas en champs qty_<id>. La grille de tuiles est rendue cote client.
        self::assertStringContainsString('id="pos-products"', $body);
        self::assertStringContainsString('"id":12', $body);
        self::assertStringContainsString('id="pos-grid"', $body);
        self::assertStringContainsString('service_mode', $body);
    }

    public function testStoreRejectsInvalidCsrf(): void
    {
        $db = $this->permittedDb();
        $request = $this->post(['service_mode' => 'dine_in', 'qty_12' => '1'], '/counter/orders');

        self::assertSame(403, $this->controller($request, $db)->store()->status());
    }

    public function testStoreEmptyCartReRendersWith422(): void
    {
        $db = $this->permittedDb();
        $db->productsRows = [
            ['id' => 12, 'category_id' => 1, 'name' => 'Cheeseburger', 'description' => null, 'price_cents' => 890, 'image_path' => null, 'display_order' => 1],
        ];
        $request = $this->post(['_csrf' => $this->csrf, 'service_mode' => 'dine_in', 'qty_12' => '0'], '/counter/orders');

        $response = $this->controller($request, $db)->store();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO customer_order'));
    }

    public function testStoreCreatesCounterOrderAndRedirects(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'is_available' => 1];
        $db->lastInsertId = 100;
        // pay() (encaissement immediat) relit la commande persistee par numero.
        $db->orderByNumberRow = ['id' => 100, 'order_number' => 'C100', 'total_ttc_cents' => 890, 'status' => 'pending_payment'];

        $request = $this->post(['_csrf' => $this->csrf, 'service_mode' => 'dine_in', 'qty_12' => '2'], '/counter/orders');

        $response = $this->controller($request, $db)->store();

        self::assertSame(302, $response->status());
        self::assertSame('/counter/orders', $response->header('Location'));
        self::assertTrue($db->wrote('INSERT INTO customer_order'));
        // Source auto-tagguee counter, acteur = equipier de session (id 7).
        $insert = $this->writeParams($db, 'INSERT INTO customer_order');
        self::assertSame('counter', $insert['source']);
        self::assertSame(7, $insert['acting']);
        // Encaissement immediat : transition paid emise.
        self::assertTrue($db->wrote('UPDATE customer_order SET status'));
    }

    public function testCreateRendersMenuComposer(): void
    {
        // create() expose les menus + leurs slots au composeur (data-* JSON).
        $db = $this->permittedDb();
        $db->menusRows = [
            ['id' => 5, 'category_id' => 1, 'burger_product_id' => 12, 'name' => 'Menu Cheeseburger', 'description' => null, 'price_normal_cents' => 990, 'price_maxi_cents' => 1190, 'image_path' => null, 'display_order' => 1],
        ];
        $db->menuSlotRows = [
            ['id' => 16, 'name' => 'Accompagnement', 'slot_type' => 'side', 'is_required' => 1, 'display_order' => 1, 'product_id' => 22],
        ];

        $response = $this->controller($this->get('/counter/orders/new'), $db)->create();

        self::assertSame(200, $response->status());
        $body = $response->body();
        self::assertStringContainsString('Menu Cheeseburger', $body);
        // POS tactile : les menus + slots sont embarques dans un script JSON inerte
        // (la tuile menu et la modale sont rendues cote client par counter-order.js).
        self::assertStringContainsString('id="pos-menus"', $body);
        self::assertStringContainsString('"id":5', $body);
        self::assertStringContainsString('items_json', $body);          // champ cache du panier
        self::assertStringContainsString('counter-order.js', $body);    // script du composeur
    }

    public function testStoreCreatesMenuOrderViaItemsJson(): void
    {
        // store() decode items_json, revalide la forme, et createStaffOrder persiste
        // l'item menu (order_item type menu + order_item_selection).
        $db = $this->permittedDb();
        $db->menuRow = ['id' => 5, 'name' => 'Menu Cheeseburger', 'burger_product_id' => 12, 'price_normal_cents' => 990, 'price_maxi_cents' => 1190, 'is_available' => 1];
        // productRow sert le burger (vat_rate) ET le produit selectionne (label snapshot).
        $db->productRow = ['id' => 22, 'name' => 'Frites', 'price_cents' => 250, 'vat_rate' => 100, 'maxi_variant_product_id' => null, 'is_available' => 1];
        // slotsWithOptions : slot 16 (side) propose le produit 22 -> selection valide.
        $db->menuSlotRows = [
            ['id' => 16, 'name' => 'Accompagnement', 'slot_type' => 'side', 'is_required' => 1, 'display_order' => 1, 'product_id' => 22],
        ];
        $db->lastInsertId = 100;
        $db->orderByNumberRow = ['id' => 100, 'order_number' => 'C100', 'total_ttc_cents' => 990, 'status' => 'pending_payment'];

        $items = json_encode([
            ['type' => 'menu', 'menu_id' => 5, 'quantity' => 1, 'format' => 'normal', 'selections' => [['menu_slot_id' => 16, 'product_id' => 22]]],
        ]);
        $request = $this->post(['_csrf' => $this->csrf, 'service_mode' => 'dine_in', 'items_json' => (string) $items], '/counter/orders');

        $response = $this->controller($request, $db)->store();

        self::assertSame(302, $response->status());
        self::assertSame('/counter/orders', $response->header('Location'));
        // Ligne menu persistee.
        $itemInsert = $this->writeParams($db, 'INSERT INTO order_item ');
        self::assertSame('menu', $itemInsert['type']);
        self::assertSame(5, $itemInsert['mid']);
        self::assertSame('normal', $itemInsert['fmt']);
        // Selection persistee (slot 16 -> produit 22).
        self::assertTrue($db->wrote('INSERT INTO order_item_selection'));
        $selInsert = $this->writeParams($db, 'INSERT INTO order_item_selection');
        self::assertSame(16, $selInsert['slot']);
        self::assertSame(22, $selInsert['pid']);
    }

    public function testStoreCreatesMenuOrderWithQuantityTwo(): void
    {
        // G : un item menu via items_json avec quantity:2 persiste qty=2 sur order_item ;
        // les selections de slot ne sont pas dupliquees par la quantite (un seul INSERT).
        $db = $this->permittedDb();
        $db->menuRow = ['id' => 5, 'name' => 'Menu Cheeseburger', 'burger_product_id' => 12, 'price_normal_cents' => 990, 'price_maxi_cents' => 1190, 'is_available' => 1];
        $db->productRow = ['id' => 22, 'name' => 'Frites', 'price_cents' => 250, 'vat_rate' => 100, 'maxi_variant_product_id' => null, 'is_available' => 1];
        $db->menuSlotRows = [
            ['id' => 16, 'name' => 'Accompagnement', 'slot_type' => 'side', 'is_required' => 1, 'display_order' => 1, 'product_id' => 22],
        ];
        $db->lastInsertId = 100;
        $db->orderByNumberRow = ['id' => 100, 'order_number' => 'C100', 'total_ttc_cents' => 1980, 'status' => 'pending_payment'];

        $items = json_encode([
            ['type' => 'menu', 'menu_id' => 5, 'quantity' => 2, 'format' => 'normal', 'selections' => [['menu_slot_id' => 16, 'product_id' => 22]]],
        ]);
        $request = $this->post(['_csrf' => $this->csrf, 'service_mode' => 'dine_in', 'items_json' => (string) $items], '/counter/orders');

        $response = $this->controller($request, $db)->store();

        self::assertSame(302, $response->status());
        $itemInsert = $this->writeParams($db, 'INSERT INTO order_item ');
        self::assertSame('menu', $itemInsert['type']);
        self::assertSame(5, $itemInsert['mid']);
        self::assertSame(2, $itemInsert['qty']);
        // La selection de slot est persistee UNE fois (independante de la quantite).
        $selectionWrites = array_values(array_filter(
            $db->writes,
            static fn (array $w): bool => str_contains($w['sql'], 'INSERT INTO order_item_selection'),
        ));
        self::assertCount(1, $selectionWrites);
    }

    public function testStoreCreatesProductOrderViaItemsJson(): void
    {
        // Chemin unifie : items_json est prefere a qty_<id>, et un produit y passe aussi.
        $db = $this->permittedDb();
        $db->productRow = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'maxi_variant_product_id' => null, 'is_available' => 1];
        $db->lastInsertId = 100;
        $db->orderByNumberRow = ['id' => 100, 'order_number' => 'C100', 'total_ttc_cents' => 890, 'status' => 'pending_payment'];

        $items = json_encode([['type' => 'product', 'product_id' => 12, 'quantity' => 3]]);
        $request = $this->post(['_csrf' => $this->csrf, 'service_mode' => 'dine_in', 'items_json' => (string) $items], '/counter/orders');

        $response = $this->controller($request, $db)->store();

        self::assertSame(302, $response->status());
        $itemInsert = $this->writeParams($db, 'INSERT INTO order_item ');
        self::assertSame('product', $itemInsert['type']);
        self::assertSame(12, $itemInsert['pid']);
        self::assertSame(3, $itemInsert['qty']);
    }

    public function testStoreRejectsMalformedItemsJson(): void
    {
        // items_json non-JSON / sans item valide -> panier vide -> 422, aucun INSERT.
        $db = $this->permittedDb();
        $request = $this->post(['_csrf' => $this->csrf, 'service_mode' => 'dine_in', 'items_json' => 'not-json'], '/counter/orders');

        $response = $this->controller($request, $db)->store();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO customer_order'));
    }

    public function testStoreRejectsItemsJsonWithOnlyInvalidEntries(): void
    {
        // Entrees mal formees (type inconnu, ids non positifs) ecartees -> panier vide -> 422.
        $db = $this->permittedDb();
        $items = json_encode([
            ['type' => 'unknown', 'product_id' => 12],
            ['type' => 'product', 'product_id' => 0],
            ['type' => 'menu', 'menu_id' => -1],
        ]);
        $request = $this->post(['_csrf' => $this->csrf, 'service_mode' => 'dine_in', 'items_json' => (string) $items], '/counter/orders');

        $response = $this->controller($request, $db)->store();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO customer_order'));
    }

    public function testCreateExposesProductComposition(): void
    {
        // create() joint la composition PROPOSABLE (modificateurs) de chaque produit au
        // composeur : embarquee en data-* JSON (htmlspecialchars(json_encode), RG-T15).
        $db = $this->permittedDb();
        $db->productsRows = [
            ['id' => 12, 'category_id' => 1, 'name' => 'Cheeseburger', 'description' => null, 'price_cents' => 890, 'image_path' => null, 'display_order' => 1],
        ];
        // composition() : un ingredient retirable (Oignon) + un ajoutable (Bacon, +50c).
        $db->compositionRows = [
            ['product_id' => 12, 'ingredient_id' => 3, 'ingredient_name' => 'Oignon', 'is_removable' => 1, 'is_addable' => 0, 'extra_price_cents' => 0, 'quantity_normal' => 1, 'quantity_maxi' => 1],
            ['product_id' => 12, 'ingredient_id' => 8, 'ingredient_name' => 'Bacon', 'is_removable' => 0, 'is_addable' => 1, 'extra_price_cents' => 50, 'quantity_normal' => 1, 'quantity_maxi' => 1],
        ];

        $response = $this->controller($this->get('/counter/orders/new'), $db)->create();

        self::assertSame(200, $response->status());
        $body = $response->body();
        // POS tactile : la composition PROPOSABLE est embarquee dans le script JSON inerte
        // #pos-products (type="application/json"). json_encode avec JSON_HEX_TAG protege
        // l'insertion dans un <script> (un '<' deviendrait < ; pas de </script>
        // injectable). On cherche les fragments JSON reellement rendus (guillemets bruts,
        // surs dans un script). La tuile "A composer" et la modale sont rendues client-side.
        self::assertStringContainsString('id="pos-products"', $body);
        self::assertStringContainsString('"ingredient_id":3', $body);
        self::assertStringContainsString('"ingredient_id":8', $body);
        self::assertStringContainsString('Oignon', $body);
        self::assertStringContainsString('Bacon', $body);
    }

    public function testStoreCreatesProductOrderWithModifiers(): void
    {
        // store() decode items_json portant des modifiers, resolveModifiers les valide
        // contre la recette du produit, et persiste les lignes order_item_modifier.
        $db = $this->permittedDb();
        $db->productRow = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'maxi_variant_product_id' => null, 'is_available' => 1];
        // Recette : Oignon retirable, Bacon ajoutable (+50c). resolveModifiers lit ces lignes.
        $db->compositionRows = [
            ['ingredient_id' => 3, 'is_removable' => 1, 'is_addable' => 0, 'extra_price_cents' => 0, 'quantity_normal' => 1, 'quantity_maxi' => 1],
            ['ingredient_id' => 8, 'is_removable' => 0, 'is_addable' => 1, 'extra_price_cents' => 50, 'quantity_normal' => 1, 'quantity_maxi' => 1],
        ];
        $db->lastInsertId = 100;
        $db->orderByNumberRow = ['id' => 100, 'order_number' => 'C100', 'total_ttc_cents' => 940, 'status' => 'pending_payment'];

        $items = json_encode([
            ['type' => 'product', 'product_id' => 12, 'quantity' => 1, 'modifiers' => [
                ['ingredient_id' => 3, 'action' => 'remove'],
                ['ingredient_id' => 8, 'action' => 'add'],
            ]],
        ]);
        $request = $this->post(['_csrf' => $this->csrf, 'service_mode' => 'dine_in', 'items_json' => (string) $items], '/counter/orders');

        $response = $this->controller($request, $db)->store();

        self::assertSame(302, $response->status());
        self::assertSame('/counter/orders', $response->header('Location'));
        // Deux lignes order_item_modifier persistees (remove Oignon + add Bacon).
        self::assertTrue($db->wrote('INSERT INTO order_item_modifier'));
        $modifierWrites = array_values(array_filter(
            $db->writes,
            static fn (array $w): bool => str_contains($w['sql'], 'INSERT INTO order_item_modifier'),
        ));
        self::assertCount(2, $modifierWrites);
        // L'ajout fige extra_price_cents=50 (snapshot recette, RG-T16) ; le retrait 0.
        $byAction = [];
        foreach ($modifierWrites as $w) {
            $byAction[(string) $w['params']['act']] = $w['params'];
        }
        self::assertSame(3, $byAction['remove']['ing']);
        self::assertSame(0, $byAction['remove']['extra']);
        self::assertSame(8, $byAction['add']['ing']);
        self::assertSame(50, $byAction['add']['extra']);
    }

    public function testStoreRejectsModifierOnNonAddableIngredient(): void
    {
        // RG-T18 / INGREDIENT_NOT_ADDABLE : un 'add' sur un ingredient is_addable=0 est
        // rejete par resolveModifiers -> re-rendu 422, rien de persiste.
        $db = $this->permittedDb();
        $db->productRow = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'maxi_variant_product_id' => null, 'is_available' => 1];
        // Oignon est retirable mais PAS ajoutable : un 'add' doit etre refuse.
        $db->compositionRows = [
            ['ingredient_id' => 3, 'is_removable' => 1, 'is_addable' => 0, 'extra_price_cents' => 0, 'quantity_normal' => 1, 'quantity_maxi' => 1],
        ];

        $items = json_encode([
            ['type' => 'product', 'product_id' => 12, 'quantity' => 1, 'modifiers' => [
                ['ingredient_id' => 3, 'action' => 'add'],
            ]],
        ]);
        $request = $this->post(['_csrf' => $this->csrf, 'service_mode' => 'dine_in', 'items_json' => (string) $items], '/counter/orders');

        $response = $this->controller($request, $db)->store();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO customer_order'));
        self::assertFalse($db->wrote('INSERT INTO order_item_modifier'));
    }

    public function testStoreRejectsMenuSelectionOutsideSlotOptions(): void
    {
        // RG-T18/INVALID_SELECTION : une selection hors des options du slot est rejetee
        // par resolveSelections -> re-rendu 422, rien de persiste.
        $db = $this->permittedDb();
        $db->menuRow = ['id' => 5, 'name' => 'Menu Cheeseburger', 'burger_product_id' => 12, 'price_normal_cents' => 990, 'price_maxi_cents' => 1190, 'is_available' => 1];
        $db->productRow = ['id' => 22, 'name' => 'Frites', 'price_cents' => 250, 'vat_rate' => 100, 'maxi_variant_product_id' => null, 'is_available' => 1];
        $db->menuSlotRows = [
            ['id' => 16, 'name' => 'Accompagnement', 'slot_type' => 'side', 'is_required' => 1, 'display_order' => 1, 'product_id' => 22],
        ];

        $items = json_encode([
            ['type' => 'menu', 'menu_id' => 5, 'quantity' => 1, 'format' => 'normal', 'selections' => [['menu_slot_id' => 16, 'product_id' => 999]]],
        ]);
        $request = $this->post(['_csrf' => $this->csrf, 'service_mode' => 'dine_in', 'items_json' => (string) $items], '/counter/orders');

        $response = $this->controller($request, $db)->store();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO order_item_selection'));
    }

    public function testStoreDriveRejectsNonDriveServiceMode(): void
    {
        // RG-T09 : au drive, service_mode doit etre drive ; sinon re-rendu 422, pas d'INSERT.
        $db = $this->permittedDb();
        $db->productRow = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'is_available' => 1];

        $request = $this->post(['_csrf' => $this->csrf, 'service_mode' => 'takeaway', 'qty_12' => '1'], '/drive/orders');

        $response = $this->controller($request, $db)->store();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO customer_order'));
    }

    public function testCounterIndexShowsInProgressQueueSection(): void
    {
        // 5 : la file "En cours" du canal (paid non livre) apparait en haut, filtree
        // a la source counter (C100 present, D200 du drive absent).
        $response = $this->controller($this->get('/counter/orders'), $this->permittedDb())->index();

        self::assertSame(200, $response->status());
        $body = $response->body();
        self::assertStringContainsString('En cours', $body);
        self::assertStringContainsString('Historique recent', $body);
        self::assertStringContainsString('C100', $body);
        self::assertStringNotContainsString('D200', $body);
        // 4 : la file porte une colonne "Table" et affiche le numero de la commande
        // sur place (C100 -> table 12).
        self::assertStringContainsString('<th>Table</th>', $body);
        self::assertStringContainsString('>12</td>', $body);
    }

    public function testDriveCreateFreezesServiceModeToDrive(): void
    {
        // 2 : au drive, service_mode n'est PAS un select editable. Il est fige a 'Drive'
        // (affichage) + transmis par un champ cache (un select readonly resterait
        // editable, donc on ne s'y fie pas).
        $response = $this->controller($this->get('/drive/orders/new'), $this->permittedDb())->create();

        self::assertSame(200, $response->status());
        $body = $response->body();
        // Champ cache porteur de la valeur drive (soumis).
        self::assertStringContainsString('type="hidden" name="service_mode" id="service_mode" value="drive"', $body);
        // Aucun select de mode au drive (l'affichage est fige).
        self::assertStringNotContainsString('<select class="form-input" id="service_mode"', $body);
    }

    public function testCounterCreateKeepsEditableServiceModeSelect(): void
    {
        // 2 (contre-exemple) : au comptoir, le select dine_in/takeaway reste editable.
        $response = $this->controller($this->get('/counter/orders/new'), $this->permittedDb())->create();

        self::assertSame(200, $response->status());
        $body = $response->body();
        self::assertStringContainsString('<select class="form-input" id="service_mode"', $body);
        self::assertStringContainsString('Sur place', $body);
        self::assertStringContainsString('A emporter', $body);
    }

    public function testCreateExposesConfigurableProductModifiersInJson(): void
    {
        // POS tactile : un produit a modificateurs est expose dans #pos-products avec sa
        // composition proposable. Le client en rend une tuile "A composer" qui ouvre la
        // modale au tap (la saisie de la quantite et des modificateurs se fait en modale).
        $db = $this->permittedDb();
        $db->productsRows = [
            ['id' => 12, 'category_id' => 1, 'category_name' => 'Burgers', 'name' => 'Cheeseburger', 'description' => null, 'price_cents' => 890, 'image_path' => null, 'display_order' => 1],
        ];
        $db->compositionRows = [
            ['product_id' => 12, 'ingredient_id' => 3, 'ingredient_name' => 'Oignon', 'is_removable' => 1, 'is_addable' => 0, 'extra_price_cents' => 0, 'quantity_normal' => 1, 'quantity_maxi' => 1],
        ];

        $response = $this->controller($this->get('/counter/orders/new'), $db)->create();

        self::assertSame(200, $response->status());
        $body = $response->body();
        // Le produit et sa composition sont dans le JSON inerte (pas de champ qty_<id>).
        self::assertStringContainsString('id="pos-products"', $body);
        self::assertStringContainsString('"id":12', $body);
        self::assertStringContainsString('"ingredient_id":3', $body);
        self::assertStringContainsString('Oignon', $body);
        // Plus de champ quantite par produit (la saisie passe par les tuiles + modale).
        self::assertStringNotContainsString('name="qty_12"', $body);
    }

    public function testCreateExposesCategoryNamesForTabs(): void
    {
        // POS tactile : les onglets de categories sont construits cote client a partir
        // de category_name embarque dans le JSON de chaque produit/menu.
        $db = $this->permittedDb();
        $db->productsRows = [
            ['id' => 12, 'category_id' => 1, 'category_name' => 'Burgers', 'name' => 'Cheeseburger', 'description' => null, 'price_cents' => 890, 'image_path' => null, 'display_order' => 1],
            ['id' => 22, 'category_id' => 2, 'category_name' => 'Accompagnements', 'name' => 'Frites', 'description' => null, 'price_cents' => 250, 'image_path' => null, 'display_order' => 1],
        ];

        $response = $this->controller($this->get('/counter/orders/new'), $db)->create();

        self::assertSame(200, $response->status());
        $body = $response->body();
        self::assertStringContainsString('id="pos-tabs"', $body);
        self::assertStringContainsString('"category_name":"Burgers"', $body);
        self::assertStringContainsString('"category_name":"Accompagnements"', $body);
    }

    public function testCreateExposesBothMenuPrices(): void
    {
        // 6 : les deux prix d'un menu (Normal / Maxi, en centimes) sont exposes dans le
        // JSON inerte ; le client affiche "Normal X / Maxi Y" sur la tuile et la modale.
        $db = $this->permittedDb();
        $db->menusRows = [
            ['id' => 5, 'category_id' => 1, 'burger_product_id' => 12, 'name' => 'Menu Cheeseburger', 'description' => null, 'price_normal_cents' => 990, 'price_maxi_cents' => 1190, 'image_path' => null, 'display_order' => 1],
        ];

        $response = $this->controller($this->get('/counter/orders/new'), $db)->create();

        self::assertSame(200, $response->status());
        $body = $response->body();
        self::assertStringContainsString('id="pos-menus"', $body);
        self::assertStringContainsString('"price_normal":990', $body);
        self::assertStringContainsString('"price_maxi":1190', $body);
    }

    public function testStorePassesServiceTagInDineIn(): void
    {
        // 7a : un numero de table saisi en sur place est transmis a createStaffOrder et
        // persiste (service_tag) sur la commande.
        $db = $this->permittedDb();
        $db->productRow = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'maxi_variant_product_id' => null, 'is_available' => 1];
        $db->lastInsertId = 100;
        $db->orderByNumberRow = ['id' => 100, 'order_number' => 'C100', 'total_ttc_cents' => 890, 'status' => 'pending_payment'];

        $items = json_encode([['type' => 'product', 'product_id' => 12, 'quantity' => 1]]);
        $request = $this->post(['_csrf' => $this->csrf, 'service_mode' => 'dine_in', 'service_tag' => '12', 'items_json' => (string) $items], '/counter/orders');

        $response = $this->controller($request, $db)->store();

        self::assertSame(302, $response->status());
        $insert = $this->writeParams($db, 'INSERT INTO customer_order');
        self::assertSame('12', $insert['tag']);
    }

    public function testStoreDropsServiceTagWhenNotDineIn(): void
    {
        // 7a : un numero de table soumis hors sur place (takeaway) n'est pas transmis ;
        // service_tag persiste NULL (la table n'a de sens qu'en sur place).
        $db = $this->permittedDb();
        $db->productRow = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'maxi_variant_product_id' => null, 'is_available' => 1];
        $db->lastInsertId = 100;
        $db->orderByNumberRow = ['id' => 100, 'order_number' => 'C100', 'total_ttc_cents' => 890, 'status' => 'pending_payment'];

        $items = json_encode([['type' => 'product', 'product_id' => 12, 'quantity' => 1]]);
        $request = $this->post(['_csrf' => $this->csrf, 'service_mode' => 'takeaway', 'service_tag' => '12', 'items_json' => (string) $items], '/counter/orders');

        $response = $this->controller($request, $db)->store();

        self::assertSame(302, $response->status());
        $insert = $this->writeParams($db, 'INSERT INTO customer_order');
        self::assertNull($insert['tag']);
    }

    public function testNavRoutesDriveRoleToDriveLanding(): void
    {
        // 3 : le lien "Saisie commande" du layout pointe vers le canal du role courant.
        // Un equipier drive (role.order_source = drive, remonte par displayInfo) est
        // route vers /drive/orders.
        $db = $this->permittedDb();
        $db->userDisplayRow = ['first_name' => 'Dana', 'last_name' => 'D', 'role_label' => 'Drive', 'order_source' => 'drive'];

        $response = $this->controller($this->get('/drive/orders'), $db)->index();

        self::assertSame(200, $response->status());
        $body = $response->body();
        self::assertStringContainsString('href="/drive/orders" class="sidebar-item active">Saisie commande', $body);
    }

    public function testNavRoutesCounterRoleToCounterLanding(): void
    {
        // 3 (contre-exemple) : un role comptoir (order_source counter / NULL) est route
        // vers /counter/orders.
        $db = $this->permittedDb();
        $db->userDisplayRow = ['first_name' => 'Sam', 'last_name' => 'C', 'role_label' => 'Comptoir', 'order_source' => 'counter'];

        $response = $this->controller($this->get('/counter/orders'), $db)->index();

        self::assertSame(200, $response->status());
        $body = $response->body();
        self::assertStringContainsString('href="/counter/orders" class="sidebar-item active">Saisie commande', $body);
    }

    /**
     * Parametres lies de la premiere ecriture dont le SQL contient $needle.
     *
     * @return array<string|int, mixed>
     */
    private function writeParams(FakeDatabase $db, string $needle): array
    {
        foreach ($db->writes as $write) {
            if (str_contains($write['sql'], $needle)) {
                return $write['params'];
            }
        }

        return [];
    }
}
