<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin\Api;

use PHPUnit\Framework\TestCase;
use App\Auth\Authorizer;
use App\Auth\SessionGuard;
use App\Auth\SessionManager;
use App\Auth\UserDirectory;
use App\Controllers\Admin\Api\HealthApiController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\Request;
use App\Health\HealthReport;
use App\Tests\Support\FakeDatabase;
use App\Tests\Unit\Admin\StubHealthReport;

require_once __DIR__ . '/../HealthPageControllerTest.php';

/**
 * Sous-classe de test : injecte session test + FakeDatabase + un rapport de
 * sante canne, memes seams que StatsApiControllerTest vis-a-vis de
 * StatsApiController.
 */
final class TestHealthApiController extends HealthApiController
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

    protected function db(): DatabaseInterface
    {
        return $this->fakeDb;
    }

    protected function healthReport(): HealthReport
    {
        return new StubHealthReport($this->fakeDb, $this->config);
    }
}

final class HealthApiControllerTest extends TestCase
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

    private function controller(SessionManager $session, FakeDatabase $db): TestHealthApiController
    {
        $request = new Request('GET', '/admin/api/health', [], [], '', '203.0.113.5');

        return new TestHealthApiController($request, new Config(), new Database(new Config()), $session, $db);
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

    public function testReturns401WithoutSession(): void
    {
        $response = $this->controller(new SessionManager(new Config(), true), new FakeDatabase())->apiIndex();

        self::assertSame(401, $response->status());
        $payload = json_decode($response->body(), true);
        self::assertSame('AUTH_REQUIRED', $payload['error']['code']);
    }

    public function testReturns403WithoutRoleManagePermission(): void
    {
        $db = new FakeDatabase();
        $db->guardUserRow = ['is_active' => 1];
        $db->canResult = false;

        $response = $this->controller($this->authedSession(), $db)->apiIndex();

        self::assertSame(403, $response->status());
        $payload = json_decode($response->body(), true);
        self::assertSame('FORBIDDEN', $payload['error']['code']);
    }

    public function testReturnsReportEnvelopeForRoleManage(): void
    {
        $db = new FakeDatabase();
        $db->guardUserRow = ['is_active' => 1];
        $db->canResult = true;

        $response = $this->controller($this->authedSession(), $db)->apiIndex();

        self::assertSame(200, $response->status());
        self::assertSame('application/json; charset=utf-8', $response->header('Content-Type'));

        $payload = json_decode($response->body(), true);
        self::assertArrayHasKey('data', $payload);
        self::assertSame('ab0c553', $payload['data']['version']);
        self::assertTrue($payload['data']['db']['ok']);
        self::assertSame(16, $payload['data']['migrations']['applied']);
        self::assertSame(1, $payload['data']['activity_24h']['pin_failures']);
    }
}
