<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
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

    protected function setUp(): void
    {
        $this->setEnv('SESSION_LIFETIME_IDLE', '14400');
        $this->setEnv('SESSION_LIFETIME_ABSOLUTE', '36000');
        $this->session = new SessionManager(new Config(), true);
        $now = time();
        $this->session->set('user_id', 1);
        $this->session->set('role_id', 2);
        $this->session->set('logged_in_at', $now - 100);
        $this->session->set('last_activity', $now - 50);
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
}
