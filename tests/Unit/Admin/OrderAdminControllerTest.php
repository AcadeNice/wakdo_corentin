<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\SessionManager;
use App\Controllers\OrderAdminController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\Request;
use App\Order\OrderQueryRepository;
use App\Tests\Support\FakeDatabase;

/**
 * Stub d'OrderQueryRepository : liste canned (rendu de la table teste sans base ;
 * les requetes sont couvertes par OrderQueryRepositoryDbTest).
 */
final class StubRecentOrders extends OrderQueryRepository
{
    public function recent(int $limit = 50): array
    {
        return [
            ['order_number' => 'K42', 'service_mode' => 'dine_in', 'service_tag' => '261', 'status' => 'paid', 'total_ttc_cents' => 1990, 'created_at' => '2026-06-19 12:00:00', 'paid_at' => '2026-06-19 12:01:00'],
            ['order_number' => 'K43', 'service_mode' => 'takeaway', 'service_tag' => null, 'status' => 'pending_payment', 'total_ttc_cents' => 800, 'created_at' => '2026-06-19 12:05:00', 'paid_at' => null],
        ];
    }
}

final class TestOrderAdminController extends OrderAdminController
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
        return new StubRecentOrders($this->fakeDb);
    }
}

final class OrderAdminControllerTest extends TestCase
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
        $db->userDisplayRow = ['first_name' => 'Manon', 'last_name' => 'G', 'role_label' => 'Manager'];
        $db->canResult = true;
        $db->permissionCodes = ['order.read'];

        return $db;
    }

    private function controller(FakeDatabase $db): TestOrderAdminController
    {
        $request = new Request('GET', '/admin/orders', [], [], '', '203.0.113.5');

        return new TestOrderAdminController($request, new Config(), new Database(new Config()), $this->session, $db);
    }

    private function controllerWith(Request $request, FakeDatabase $db): TestOrderAdminController
    {
        return new TestOrderAdminController($request, new Config(), new Database(new Config()), $this->session, $db);
    }

    /**
     * @param array<string, string> $form
     */
    private function post(array $form, string $path): Request
    {
        return new Request('POST', $path, [], ['content-type' => 'application/x-www-form-urlencoded'], http_build_query($form), '203.0.113.5');
    }

    private function cancelDb(): FakeDatabase
    {
        $db = $this->permittedDb();
        $db->permissionCodes = ['order.read', 'order.cancel'];
        $db->orderByNumberRow = ['id' => 100, 'order_number' => 'K42', 'total_ttc_cents' => 1990, 'status' => 'paid'];

        return $db;
    }

    private function actingPin(FakeDatabase $db): void
    {
        $db->actingUserRow = ['id' => 9, 'role_id' => 4, 'pin_hash' => (new PasswordHasher(new Config()))->hash('4729')];
    }

    public function testRequiresOrderRead(): void
    {
        $db = $this->permittedDb();
        $db->canResult = false;

        self::assertSame(403, $this->controller($db)->index()->status());
    }

    public function testRendersOrdersList(): void
    {
        $response = $this->controller($this->permittedDb())->index();

        self::assertSame(200, $response->status());
        $body = $response->body();
        self::assertStringContainsString('Commandes', $body);
        self::assertStringContainsString('K42', $body);
        self::assertStringContainsString('Sur place', $body);   // dine_in -> libelle
        self::assertStringContainsString('261', $body);         // chevalet
        self::assertStringContainsString('19,90 EUR', $body);   // total 1990c formate
        self::assertStringContainsString('Payee', $body);       // statut paid
        self::assertStringContainsString('A emporter', $body);  // takeaway -> libelle
    }

    public function testDeliverRequiresOrderDeliverPermission(): void
    {
        $db = $this->permittedDb();
        $db->canResult = false; // pas de order.deliver -> 403 avant toute action

        self::assertSame(403, $this->controller($db)->deliver(['number' => 'K42'])->status());
    }

    public function testDeliverRejectsInvalidCsrf(): void
    {
        // order.deliver accorde (canResult=true) mais aucun jeton CSRF dans la requete
        // -> la garde CSRF refuse (403) avant toute transition.
        $response = $this->controller($this->permittedDb())->deliver(['number' => 'K42']);

        self::assertSame(403, $response->status());
    }

    // --- CANCEL_ORDER (7.1, order.cancel + PIN equipier RG-T13) ---

    public function testCancelRequiresOrderCancelPermission(): void
    {
        $db = $this->cancelDb();
        $db->canResult = false; // pas de order.cancel -> 403 avant toute action

        $request = $this->post([
            '_csrf' => $this->csrf, 'pin_email' => 'sam@wakdo.local', 'pin' => '4729',
        ], '/admin/orders/K42/cancel');

        self::assertSame(403, $this->controllerWith($request, $db)->cancel(['number' => 'K42'])->status());
    }

    public function testCancelRejectsInvalidCsrf(): void
    {
        // order.cancel accorde mais jeton CSRF absent -> 403 avant toute transition.
        $db = $this->cancelDb();
        $request = $this->post(['pin_email' => 'sam@wakdo.local', 'pin' => '4729'], '/admin/orders/K42/cancel');

        self::assertSame(403, $this->controllerWith($request, $db)->cancel(['number' => 'K42'])->status());
    }

    public function testCancelWithBadPinLogsFailedAndDoesNotCancel(): void
    {
        $db = $this->cancelDb();
        $db->actingUserRow = null; // email/PIN non resolu

        $request = $this->post([
            '_csrf' => $this->csrf, 'pin_email' => 'ghost@wakdo.local', 'pin' => '0000',
        ], '/admin/orders/K42/cancel');

        $response = $this->controllerWith($request, $db)->cancel(['number' => 'K42']);

        self::assertSame(422, $response->status());
        self::assertSame(['pin.failed'], $db->auditActions());     // trace detective (RG-T22)
        self::assertFalse($db->wrote('UPDATE customer_order SET status')); // aucune transition
    }

    public function testCancelWithValidPinTransitionsToCancelled(): void
    {
        $db = $this->cancelDb();
        $this->actingPin($db); // equipier id 9, PIN 4729

        $request = $this->post([
            '_csrf' => $this->csrf, 'pin_email' => 'sam@wakdo.local', 'pin' => '4729',
        ], '/admin/orders/K42/cancel');

        $response = $this->controllerWith($request, $db)->cancel(['number' => 'K42']);

        self::assertSame(302, $response->status());
        self::assertSame('/admin/orders', $response->header('Location'));
        self::assertTrue($db->wrote('UPDATE customer_order SET status'));
        // L'annulation est tracee avec l'acteur resolu par PIN (RG-T14).
        self::assertSame(['order.cancel'], $db->auditActions());
    }

    public function testCancelUnknownOrderReturns404(): void
    {
        $db = $this->cancelDb();
        $db->orderByNumberRow = null; // numero inconnu

        $request = $this->post([
            '_csrf' => $this->csrf, 'pin_email' => 'sam@wakdo.local', 'pin' => '4729',
        ], '/admin/orders/K99/cancel');

        self::assertSame(404, $this->controllerWith($request, $db)->cancel(['number' => 'K99'])->status());
    }

    public function testConfirmCancelRendersPinForm(): void
    {
        $db = $this->cancelDb();
        $request = new Request('GET', '/admin/orders/K42/cancel', [], [], '', '203.0.113.5');

        $response = $this->controllerWith($request, $db)->confirmCancel(['number' => 'K42']);

        self::assertSame(200, $response->status());
        $body = $response->body();
        self::assertStringContainsString('K42', $body);
        self::assertStringContainsString('PIN', $body);
    }
}
