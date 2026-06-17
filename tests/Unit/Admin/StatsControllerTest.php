<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use App\Auth\SessionManager;
use App\Catalogue\StatsRepository;
use App\Controllers\StatsController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\Request;
use App\Tests\Support\FakeDatabase;

/**
 * Stub de StatsRepository : KPIs canned, sans base. Permet de tester le rendu du
 * controleur independamment des requetes d'agregation (couvertes par le DbTest).
 */
final class StubStatsRepository extends StatsRepository
{
    public function counts(): array
    {
        return [
            'products'   => ['total' => 53, 'available' => 50],
            'categories' => ['total' => 9, 'active' => 9],
            'menus'      => ['total' => 13, 'available' => 12],
            'ingredients' => ['total' => 7, 'active' => 6],
        ];
    }

    public function stockHealth(): array
    {
        return [
            'active_total' => 6,
            'bands' => ['normal' => 4, 'low' => 1, 'critical' => 1],
            'alerts' => [
                ['name' => 'Cheddar', 'stock_pct' => 3, 'stock_band' => 'critical'],
                ['name' => 'Cornichon', 'stock_pct' => 8, 'stock_band' => 'low'],
            ],
        ];
    }
}

final class TestStatsController extends StatsController
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

    protected function statsRepository(): StatsRepository
    {
        return new StubStatsRepository($this->fakeDb);
    }
}

final class StatsControllerTest extends TestCase
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
        $db->permissionCodes = ['stats.read'];

        return $db;
    }

    private function controller(FakeDatabase $db): TestStatsController
    {
        $request = new Request('GET', '/admin/stats', [], [], '', '203.0.113.5');

        return new TestStatsController($request, new Config(), new Database(new Config()), $this->session, $db);
    }

    public function testRequiresStatsRead(): void
    {
        $db = $this->permittedDb();
        $db->canResult = false;

        self::assertSame(403, $this->controller($db)->index()->status());
    }

    public function testRendersCatalogueCountsAndStockAlerts(): void
    {
        $db = $this->permittedDb();
        $response = $this->controller($db)->index();

        self::assertSame(200, $response->status());
        $body = $response->body();
        self::assertStringContainsString('Statistiques', $body);
        self::assertStringContainsString('53', $body);        // compteur produits
        self::assertStringContainsString('Cheddar', $body);   // alerte stock critique
        self::assertStringContainsString('critical', $body);  // bande
    }
}
