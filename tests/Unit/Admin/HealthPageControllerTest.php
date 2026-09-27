<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use App\Auth\Authorizer;
use App\Auth\SessionGuard;
use App\Auth\SessionManager;
use App\Auth\UserDirectory;
use App\Controllers\HealthPageController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Health\HealthReport;
use App\Tests\Support\FakeDatabase;

/**
 * Stub de HealthReport : rapport canné, pour tester le controleur (garde +
 * variables passees a la vue) independamment des requetes d'agregation
 * (couvertes par HealthReportTest et les tests d'integration).
 */
final class StubHealthReport extends HealthReport
{
    public function build(): array
    {
        return [
            'generated_at'       => '2026-09-27T14:02:11+02:00',
            'version'            => 'ab0c553',
            'deployed_at'        => '2026-09-27T09:40:00+02:00',
            'app_env'            => 'production',
            'debug'              => false,
            'display_errors_off' => true,
            'php_version'        => '8.3.1',
            'db'                 => ['ok' => true, 'latency_ms' => 1.8, 'server_version' => '11.4.2-MariaDB'],
            'migrations'         => ['applied' => 16, 'files' => 16, 'last' => '0017_ingredient_family.sql'],
            'seeds'              => ['applied' => 10, 'files' => 10, 'last' => '0010_ingredient_families.sql'],
            'activity_24h'       => ['orders_created' => 12, 'orders_paid' => 10, 'audit_lines' => 4, 'pin_failures' => 1],
        ];
    }
}

/**
 * Sous-classe de test : injecte session test + FakeDatabase, meme convention
 * que PrivacyControllerTest/StatsControllerTest.
 */
final class TestHealthPageController extends HealthPageController
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

    protected function userDirectory(): UserDirectory
    {
        return new UserDirectory($this->fakeDb);
    }

    protected function healthReport(): HealthReport
    {
        return new StubHealthReport($this->fakeDb, $this->config);
    }
}

final class HealthPageControllerTest extends TestCase
{
    /** @var list<string> */
    private array $touchedKeys = [];

    protected function setUp(): void
    {
        $this->setEnv('SESSION_LIFETIME_IDLE', '14400');
        $this->setEnv('SESSION_LIFETIME_ABSOLUTE', '36000');
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

    private function controller(SessionManager $session, FakeDatabase $db): TestHealthPageController
    {
        $request = new Request('GET', '/admin/health', [], [], '', '203.0.113.5');

        return new TestHealthPageController($request, new Config(), new Database(new Config()), $session, $db);
    }

    private function authedSession(): SessionManager
    {
        $session = new SessionManager(new Config(), true);
        $now = time();
        $session->set('user_id', 1);
        $session->set('role_id', 1);
        $session->set('logged_in_at', $now - 100);
        $session->set('last_activity', $now - 50);

        return $session;
    }

    public function testRedirectsToLoginWithoutSession(): void
    {
        $response = $this->controller(new SessionManager(new Config(), true), new FakeDatabase())->index();

        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('Location'));
    }

    public function testForbiddenWithoutRoleManagePermission(): void
    {
        $db = new FakeDatabase();
        $db->guardUserRow = ['is_active' => 1];
        $db->canResult = false;

        $response = $this->controller($this->authedSession(), $db)->index();

        self::assertSame(403, $response->status());
    }

    public function testRendersHealthPageForRoleManage(): void
    {
        $db = new FakeDatabase();
        $db->guardUserRow = ['is_active' => 1];
        $db->userDisplayRow = ['first_name' => 'Ada', 'last_name' => 'L', 'role_label' => 'Administrateur'];
        $db->permissionCodes = ['role.manage'];
        $db->canResult = true;

        $response = $this->controller($this->authedSession(), $db)->index();

        self::assertSame(200, $response->status());
        // Rendu dans le shell admin (meme marqueur que les autres pages admin) :
        // le contenu detaille (report/routes/probes) est ecrit par le chantier B,
        // couvert par ses propres tests de vue.
        self::assertStringContainsString('admin-layout', $response->body());
    }
}
