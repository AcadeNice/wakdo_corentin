<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin\Api;

use PHPUnit\Framework\TestCase;
use App\Auth\Authorizer;
use App\Auth\SessionGuard;
use App\Auth\SessionManager;
use App\Catalogue\StatsRepository;
use App\Controllers\Admin\Api\StatsApiController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\Request;
use App\Order\OrderQueryRepository;
use App\Tests\Support\FakeDatabase;

/**
 * Meme convention de stub que StatsControllerTest (HTML) : les agregats SQL sont
 * couverts par les *DbTest dedies, pas retestes ici.
 */
final class StubApiStatsRepository extends StatsRepository
{
    public function counts(): array
    {
        return ['products' => ['total' => 53, 'available' => 50]];
    }

    public function stockHealth(): array
    {
        return ['active_total' => 6, 'bands' => ['normal' => 4, 'low' => 1, 'critical' => 1], 'alerts' => []];
    }
}

final class StubApiOrderQueryRepository extends OrderQueryRepository
{
    public function salesKpis(): array
    {
        return [
            'revenue_cents' => 20800, 'paid_count' => 8, 'avg_basket_cents' => 2600,
            'revenue_today_cents' => 5200, 'paid_count_today' => 2, 'total_orders' => 11,
            'by_status' => ['paid' => 8, 'pending_payment' => 2, 'cancelled' => 1],
        ];
    }
}

final class TestStatsApiController extends StatsApiController
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

    protected function statsRepository(): StatsRepository
    {
        return new StubApiStatsRepository($this->fakeDb);
    }

    protected function orderQuery(): OrderQueryRepository
    {
        return new StubApiOrderQueryRepository($this->fakeDb);
    }
}

final class StatsApiControllerTest extends TestCase
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
        $this->session->set('role_id', 1);
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
        // grantedCodes (allowlist EXACTE, pas canResult=true) : un test casse si le
        // controleur se met a demander un autre code de permission que celui documente.
        $db->permissionCodes = ['stats.read'];
        $db->grantedCodes = $db->permissionCodes;

        return $db;
    }

    private function get(string $path): Request
    {
        return new Request('GET', $path, [], [], '', '203.0.113.5');
    }

    private function controller(Request $request, FakeDatabase $db): TestStatsApiController
    {
        return new TestStatsApiController($request, new Config(), new Database(new Config()), $this->session, $db);
    }

    public function testIndexReturnsAggregatedStats(): void
    {
        $response = $this->controller($this->get('/admin/api/stats'), $this->permittedDb())->apiIndex();
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertSame(53, $body['data']['counts']['products']['total']);
        self::assertSame(20800, $body['data']['sales']['revenue_cents']);
        self::assertSame(6, $body['data']['stock']['active_total']);
    }

    public function testIndexWithoutPermissionReturns403(): void
    {
        $db = $this->permittedDb();
        $db->grantedCodes = []; // aucune permission accordee

        $response = $this->controller($this->get('/admin/api/stats'), $db)->apiIndex();

        self::assertSame(403, $response->status());
    }

    public function testIndexWithoutSessionReturns401(): void
    {
        $session = new SessionManager(new Config(), true);
        $response = (new TestStatsApiController($this->get('/admin/api/stats'), new Config(), new Database(new Config()), $session, new FakeDatabase()))->apiIndex();

        self::assertSame(401, $response->status());
    }
}
