<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin\Api;

use PHPUnit\Framework\TestCase;
use App\Auth\Authorizer;
use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\SessionGuard;
use App\Auth\SessionManager;
use App\Controllers\Admin\Api\OrderApiController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\Request;
use App\Order\OrderQueryRepository;
use App\Tests\Support\FakeDatabase;

/**
 * Stub d'OrderQueryRepository (meme convention que StubRecentOrders /
 * StubChannelOrders des tests HTML) : liste canned AVEC source, non couverte par
 * FakeDatabase (recent() n'a pas de dispatch generique).
 */
final class StubApiOrders extends OrderQueryRepository
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public function recent(int $limit = 50): array
    {
        return $this->rows;
    }
}

final class TestOrderApiController extends OrderApiController
{
    public StubApiOrders $stubOrderQuery;

    public function __construct(
        Request $request,
        Config $config,
        Database $database,
        private readonly SessionManager $testSession,
        private readonly FakeDatabase $fakeDb,
    ) {
        parent::__construct($request, $config, $database);
        $this->stubOrderQuery = new StubApiOrders($fakeDb);
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

    protected function orderQuery(): OrderQueryRepository
    {
        return $this->stubOrderQuery;
    }
}

final class OrderApiControllerTest extends TestCase
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
        $this->session->set('role_id', 2);
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
        $db->permissionCodes = ['order.read', 'order.create', 'order.deliver', 'order.cancel'];
        $db->grantedCodes = $db->permissionCodes;
        $db->roleSources = []; // vue globale par defaut (admin/manager)

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

    private function controller(Request $request, FakeDatabase $db): TestOrderApiController
    {
        return new TestOrderApiController($request, new Config(), new Database(new Config()), $this->session, $db);
    }

    // --- index (RG-T12) ---

    public function testIndexFiltersOutOrdersFromInvisibleSources(): void
    {
        $db = $this->permittedDb();
        $db->roleSources = [['source' => 'counter']]; // le role ne voit que counter
        $controller = $this->controller($this->get('/admin/api/orders'), $db);
        $controller->stubOrderQuery->rows = [
            ['order_number' => 'C1', 'source' => 'counter', 'service_mode' => 'dine_in', 'service_tag' => null, 'status' => 'paid', 'total_ttc_cents' => 100, 'created_at' => null, 'paid_at' => null],
            ['order_number' => 'D1', 'source' => 'drive', 'service_mode' => 'drive', 'service_tag' => null, 'status' => 'paid', 'total_ttc_cents' => 200, 'created_at' => null, 'paid_at' => null],
        ];

        $response = $controller->apiIndex();
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertSame(1, $body['total']);
        self::assertSame('C1', $body['data'][0]['order_number']);
    }

    public function testIndexWithoutPermissionReturns403(): void
    {
        $db = $this->permittedDb();
        $db->grantedCodes = []; // aucune permission accordee

        $response = $this->controller($this->get('/admin/api/orders'), $db)->apiIndex();

        self::assertSame(403, $response->status());
    }

    // --- show ---

    public function testShowForbiddenWhenSourceNotVisible(): void
    {
        $db = $this->permittedDb();
        $db->roleSources = [['source' => 'drive']];
        $db->orderByNumberRow = ['order_number' => 'C1', 'source' => 'counter', 'service_mode' => 'dine_in', 'service_tag' => null, 'status' => 'paid', 'total_ttc_cents' => 100, 'created_at' => null, 'paid_at' => null];

        $response = $this->controller($this->get('/admin/api/orders/C1'), $db)->apiShow(['number' => 'C1']);

        self::assertSame(403, $response->status());
    }

    public function testShowUnknownNumberReturns403NotFoundToAvoidEnumeration(): void
    {
        // Anti-enumeration : un numero inconnu se comporte comme un canal non
        // visible (403), jamais 404, pour ne pas reveler qu'une commande d'un
        // autre canal existe (meme principe que OrderAdminController::deliver()).
        $db = $this->permittedDb();
        $db->orderByNumberRow = null;

        $response = $this->controller($this->get('/admin/api/orders/K99'), $db)->apiShow(['number' => 'K99']);
        $body = json_decode($response->body(), true);

        self::assertSame(403, $response->status());
        self::assertSame('FORBIDDEN', $body['error']['code'] ?? null);
    }

    public function testShowReturnsOrderWhenVisible(): void
    {
        $db = $this->permittedDb();
        $db->orderByNumberRow = ['order_number' => 'C1', 'source' => 'counter', 'service_mode' => 'dine_in', 'service_tag' => '5', 'status' => 'paid', 'total_ttc_cents' => 100, 'created_at' => null, 'paid_at' => null];

        $response = $this->controller($this->get('/admin/api/orders/C1'), $db)->apiShow(['number' => 'C1']);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertSame('C1', $body['data']['order_number']);
    }

    // --- store (comptoir/drive, source deduite du role) ---

    public function testStoreRejectsMissingCsrf(): void
    {
        $db = $this->permittedDb();
        $db->roleManageRow = ['order_source' => 'counter'];
        $request = $this->jsonRequest('POST', '/admin/api/orders', ['service_mode' => 'dine_in', 'items' => []], false);

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(403, $response->status());
        self::assertSame('CSRF_INVALID', $body['error']['code'] ?? null);
        self::assertFalse($db->wrote('INSERT INTO customer_order'));
    }

    public function testStoreRejectsWrongCsrfToken(): void
    {
        $db = $this->permittedDb();
        $db->roleManageRow = ['order_source' => 'counter'];
        $request = new Request(
            'POST',
            '/admin/api/orders',
            [],
            ['content-type' => 'application/json', 'x-csrf-token' => 'un-jeton-invente'],
            (string) json_encode(['service_mode' => 'dine_in', 'items' => []]),
            '203.0.113.5',
        );

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(403, $response->status());
        self::assertSame('CSRF_INVALID', $body['error']['code'] ?? null);
        self::assertFalse($db->wrote('INSERT INTO customer_order'));
    }

    public function testStoreCreatesOrderWithSourceFromRole(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'is_available' => 1];
        $db->lastInsertId = 100;
        $db->orderByNumberRow = ['id' => 100, 'order_number' => 'C100', 'total_ttc_cents' => 890, 'status' => 'pending_payment'];
        $db->roleManageRow = ['order_source' => 'counter']; // SELECT order_source FROM role

        $request = $this->jsonRequest('POST', '/admin/api/orders', [
            'service_mode' => 'dine_in',
            'items' => [['type' => 'product', 'product_id' => 12, 'quantity' => 1]],
        ]);

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(201, $response->status());
        self::assertSame('counter', $body['data']['source']);
        self::assertTrue($db->wrote('INSERT INTO customer_order'));
        $insert = null;
        foreach ($db->writes as $write) {
            if (str_contains($write['sql'], 'INSERT INTO customer_order')) {
                $insert = $write;
            }
        }
        self::assertNotNull($insert);
        self::assertSame('counter', $insert['params']['source'] ?? null);
    }

    public function testStoreManagerWithoutFixedSourceCanChooseDrive(): void
    {
        // Un role SANS canal fixe (order_source NULL, ex. manager) doit choisir
        // explicitement le canal dans le corps -- comme le HTML le permet via la
        // page /drive/orders dediee.
        $db = $this->permittedDb();
        $db->productRow = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'is_available' => 1];
        $db->lastInsertId = 100;
        $db->orderByNumberRow = ['id' => 100, 'order_number' => 'D100', 'total_ttc_cents' => 890, 'status' => 'pending_payment'];
        $db->roleManageRow = ['order_source' => null];

        $request = $this->jsonRequest('POST', '/admin/api/orders', [
            'service_mode' => 'drive',
            'source'       => 'drive',
            'items'        => [['type' => 'product', 'product_id' => 12, 'quantity' => 1]],
        ]);

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(201, $response->status());
        self::assertSame('drive', $body['data']['source']);
    }

    public function testStoreWithoutFixedSourceAndNoSourceChoiceReturns422(): void
    {
        $db = $this->permittedDb();
        $db->roleManageRow = ['order_source' => null];
        $request = $this->jsonRequest('POST', '/admin/api/orders', [
            'service_mode' => 'dine_in',
            'items'        => [['type' => 'product', 'product_id' => 12, 'quantity' => 1]],
        ]);

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(422, $response->status());
        self::assertArrayHasKey('source', $body['error']['fields']);
        self::assertFalse($db->wrote('INSERT INTO customer_order'));
    }

    public function testStoreRejectsBodySourceNotVisibleToRole(): void
    {
        // Relecture adverse (1er tour, point 2 ; 2e tour, point 4) : un role SANS
        // canal fixe mais dont role_visible_source restreint le canal doit voir son
        // choix de corps refuse s'il sort de ses sources visibles -- avant le 1er
        // correctif, seule la forme ('counter'|'drive') etait verifiee, jamais la
        // visibilite reelle. 403 FORBIDDEN (pas 422) : c'est un droit absent, pas
        // une saisie mal formee.
        $db = $this->permittedDb();
        $db->roleManageRow = ['order_source' => null];
        $db->roleSources = [['source' => 'drive']];
        $request = $this->jsonRequest('POST', '/admin/api/orders', [
            'service_mode' => 'dine_in',
            'source'       => 'counter',
            'items'        => [['type' => 'product', 'product_id' => 12, 'quantity' => 1]],
        ]);

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(403, $response->status());
        self::assertSame('FORBIDDEN', $body['error']['code'] ?? null);
        self::assertFalse($db->wrote('INSERT INTO customer_order'));
    }

    public function testStoreKioskFixedRoleGetsAnExplicitChannelMessageNotAGenericItemsError(): void
    {
        // Relecture adverse (2e tour), point 5 : un role a canal fixe 'kiosk'
        // (reconnu comme canal fixe depuis le 1er tour, point 1) doit recevoir un
        // message EXPLICITE ("votre canal ne permet pas la creation de commande par
        // un equipier"), pas tomber plus loin sur un rejet generique de
        // service_mode/items qui ne dirait pas pourquoi une saisie bien formee
        // echoue. Verifie aussi qu'aucune ecriture n'a lieu, quel que soit le corps
        // envoye (ici volontairement bien forme, pour prouver que le rejet est sur
        // le CANAL, pas sur la forme du panier).
        $db = $this->permittedDb();
        $db->roleManageRow = ['order_source' => 'kiosk'];
        $db->roleSources = [['source' => 'kiosk']];
        $request = $this->jsonRequest('POST', '/admin/api/orders', [
            'service_mode' => 'takeaway',
            'items'        => [['type' => 'product', 'product_id' => 12, 'quantity' => 1]],
        ]);

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(403, $response->status());
        self::assertSame('FORBIDDEN', $body['error']['code'] ?? null);
        self::assertStringContainsString('kiosk', $body['error']['message'] ?? '');
        self::assertStringContainsString('ne permet pas la création de commande par un équipier', $body['error']['message'] ?? '');
        self::assertFalse($db->wrote('INSERT INTO customer_order'));
    }

    public function testStoreFixedSourceRoleIgnoresBodySourceOverride(): void
    {
        // Un role a canal FIXE (counter) ne peut pas se faire passer pour le
        // drive en injectant "source":"drive" dans le corps : le champ est ignore.
        $db = $this->permittedDb();
        $db->productRow = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'is_available' => 1];
        $db->lastInsertId = 100;
        $db->orderByNumberRow = ['id' => 100, 'order_number' => 'C100', 'total_ttc_cents' => 890, 'status' => 'pending_payment'];
        $db->roleManageRow = ['order_source' => 'counter'];

        $request = $this->jsonRequest('POST', '/admin/api/orders', [
            'service_mode' => 'dine_in',
            'source'       => 'drive',
            'items'        => [['type' => 'product', 'product_id' => 12, 'quantity' => 1]],
        ]);

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(201, $response->status());
        self::assertSame('counter', $body['data']['source']);
    }

    public function testStoreEmptyItemsReturns422(): void
    {
        $db = $this->permittedDb();
        $request = $this->jsonRequest('POST', '/admin/api/orders', ['service_mode' => 'dine_in', 'items' => []]);

        $response = $this->controller($request, $db)->apiStore();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO customer_order'));
    }

    /**
     * REGLE : la garde de permission passe AVANT la validation du corps. Sans
     * elle, un corps volontairement invalide ({"items": []}) pourrait servir a
     * SONDER si order.create est accorde par la difference entre 403 (refuse
     * avant meme de lire le corps) et 422 (lu, donc la permission a ete
     * accordee) -- ce qui est exactement ce que le dossier RBAC de la
     * collection Postman/Bruno exploite DELIBEREMENT pour un role QUI A la
     * permission (docs/api/demo-api.md, section 5) ; ce test verifie l'autre
     * sens, qu'un role SANS la permission ne peut pas se faire reveler une
     * validation reussie par erreur.
     */
    public function testStoreWithoutPermissionReturns403EvenWithEmptyItems(): void
    {
        $db = $this->permittedDb();
        $db->grantedCodes = ['order.read']; // order.create explicitement absent
        $request = $this->jsonRequest('POST', '/admin/api/orders', ['items' => []]);

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(403, $response->status());
        self::assertSame('FORBIDDEN', $body['error']['code'] ?? null);
        self::assertFalse($db->wrote('INSERT INTO customer_order'));
    }

    // --- ready / deliver ---

    public function testReadyForbiddenWhenSourceNotVisible(): void
    {
        $db = $this->permittedDb();
        $db->roleSources = [['source' => 'drive']];
        $db->orderByNumberRow = ['order_number' => 'C1', 'source' => 'counter', 'status' => 'paid'];
        $request = new Request('POST', '/admin/api/orders/C1/ready', [], ['x-csrf-token' => $this->csrf], '', '203.0.113.5');

        $response = $this->controller($request, $db)->apiReady(['number' => 'C1']);

        self::assertSame(403, $response->status());
        self::assertFalse($db->wrote('UPDATE customer_order SET status'));
    }

    public function testReadyTransitionsWhenVisible(): void
    {
        $db = $this->permittedDb();
        $db->orderByNumberRow = ['id' => 100, 'order_number' => 'C1', 'source' => 'counter', 'status' => 'paid', 'total_ttc_cents' => 100];
        $request = new Request('POST', '/admin/api/orders/C1/ready', [], ['x-csrf-token' => $this->csrf], '', '203.0.113.5');

        $response = $this->controller($request, $db)->apiReady(['number' => 'C1']);

        self::assertSame(200, $response->status());
        self::assertTrue($db->wrote('UPDATE customer_order SET status'));
    }

    public function testDeliverTransitionsWhenVisible(): void
    {
        $db = $this->permittedDb();
        $db->orderByNumberRow = ['id' => 100, 'order_number' => 'C1', 'source' => 'counter', 'status' => 'paid', 'total_ttc_cents' => 100];
        $request = new Request('POST', '/admin/api/orders/C1/deliver', [], ['x-csrf-token' => $this->csrf], '', '203.0.113.5');

        $response = $this->controller($request, $db)->apiDeliver(['number' => 'C1']);

        self::assertSame(200, $response->status());
        self::assertTrue($db->wrote('UPDATE customer_order SET status'));
    }

    public function testDeliverForbiddenWhenSourceNotVisible(): void
    {
        // Anti-enumeration (relecture point 2/3) : deliver() partage transition()
        // avec ready(), meme garde que testReadyForbiddenWhenSourceNotVisible.
        $db = $this->permittedDb();
        $db->roleSources = [['source' => 'drive']];
        $db->orderByNumberRow = ['order_number' => 'C1', 'source' => 'counter', 'status' => 'paid'];
        $request = new Request('POST', '/admin/api/orders/C1/deliver', [], ['x-csrf-token' => $this->csrf], '', '203.0.113.5');

        $response = $this->controller($request, $db)->apiDeliver(['number' => 'C1']);

        self::assertSame(403, $response->status());
        self::assertFalse($db->wrote('UPDATE customer_order SET status'));
    }

    public function testDeliverUnknownNumberReturns403NotFoundToAvoidEnumeration(): void
    {
        $db = $this->permittedDb();
        $db->orderByNumberRow = null;
        $request = new Request('POST', '/admin/api/orders/K99/deliver', [], ['x-csrf-token' => $this->csrf], '', '203.0.113.5');

        $response = $this->controller($request, $db)->apiDeliver(['number' => 'K99']);
        $body = json_decode($response->body(), true);

        self::assertSame(403, $response->status());
        self::assertSame('FORBIDDEN', $body['error']['code'] ?? null);
    }

    // --- cancel (PIN) ---

    public function testCancelForbiddenWhenSourceNotVisible(): void
    {
        // Anti-enumeration (relecture point 3) : meme garde que show/ready/deliver,
        // desormais AVANT la verification de PIN (un canal non visible ne doit pas
        // etre distinguable d'un numero inconnu via le PIN).
        $db = $this->permittedDb();
        $db->roleSources = [['source' => 'drive']];
        $db->orderByNumberRow = ['id' => 100, 'order_number' => 'C1', 'source' => 'counter', 'status' => 'paid', 'total_ttc_cents' => 100];
        $request = $this->jsonRequest('POST', '/admin/api/orders/C1/cancel', ['pin_email' => 'e@e.fr', 'pin' => '4729']);

        $response = $this->controller($request, $db)->apiCancel(['number' => 'C1']);
        $body = json_decode($response->body(), true);

        self::assertSame(403, $response->status());
        self::assertSame('FORBIDDEN', $body['error']['code'] ?? null);
        self::assertFalse($db->wrote('UPDATE customer_order SET status'));
    }

    public function testCancelUnknownNumberReturns403NotFoundToAvoidEnumeration(): void
    {
        // Point 3 : un numero inconnu renvoyait encore 404 (apres franchissement du
        // garde CSRF, avant tout PIN) -- corrige en 403, meme code qu'un canal non
        // visible, pour qu'un acteur ne puisse jamais distinguer "n'existe pas" de
        // "existe mais mon role ne le voit pas" via le code HTTP.
        $db = $this->permittedDb();
        $db->orderByNumberRow = null;
        $request = $this->jsonRequest('POST', '/admin/api/orders/K99/cancel', ['pin_email' => 'e@e.fr', 'pin' => '4729']);

        $response = $this->controller($request, $db)->apiCancel(['number' => 'K99']);
        $body = json_decode($response->body(), true);

        self::assertSame(403, $response->status());
        self::assertSame('FORBIDDEN', $body['error']['code'] ?? null);
        self::assertFalse($db->wrote('UPDATE customer_order SET status'));
    }

    public function testCancelRequiresPin(): void
    {
        $db = $this->permittedDb();
        $db->orderByNumberRow = ['id' => 100, 'order_number' => 'C1', 'source' => 'counter', 'total_ttc_cents' => 100, 'status' => 'paid'];
        $request = $this->jsonRequest('POST', '/admin/api/orders/C1/cancel', ['pin_email' => 'e@e.fr', 'pin' => 'wrong']);

        $response = $this->controller($request, $db)->apiCancel(['number' => 'C1']);
        $body = json_decode($response->body(), true);

        self::assertSame(422, $response->status());
        self::assertSame('PIN_INVALID', $body['error']['code'] ?? null);
        self::assertFalse($db->wrote('UPDATE customer_order SET status'));
    }

    public function testCancelWithValidPinSucceeds(): void
    {
        $db = $this->permittedDb();
        $db->orderByNumberRow = ['id' => 100, 'order_number' => 'C1', 'source' => 'counter', 'total_ttc_cents' => 100, 'status' => 'paid'];
        $this->actingPin($db);
        $request = $this->jsonRequest('POST', '/admin/api/orders/C1/cancel', ['pin_email' => 'e@e.fr', 'pin' => '4729']);

        $response = $this->controller($request, $db)->apiCancel(['number' => 'C1']);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertSame('cancelled', $body['data']['status']);
        self::assertTrue($db->wrote('UPDATE customer_order SET status'));
    }

    public function testCancelTerminalStateReturns422(): void
    {
        $db = $this->permittedDb();
        $db->orderByNumberRow = ['id' => 100, 'order_number' => 'C1', 'source' => 'counter', 'total_ttc_cents' => 100, 'status' => 'delivered'];
        $this->actingPin($db);
        $request = $this->jsonRequest('POST', '/admin/api/orders/C1/cancel', ['pin_email' => 'e@e.fr', 'pin' => '4729']);

        $response = $this->controller($request, $db)->apiCancel(['number' => 'C1']);
        $body = json_decode($response->body(), true);

        self::assertSame(422, $response->status());
        self::assertSame('CANNOT_CANCEL_IN_STATE', $body['error']['code'] ?? null);
    }
}
