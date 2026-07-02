<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use App\Auth\Authorizer;
use App\Auth\SessionGuard;
use App\Auth\SessionManager;
use App\Controllers\MeController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Tests\Support\FakeDatabase;

/**
 * Sous-classe de test : injecte une session en mode test et un FakeDatabase dans
 * la garde et l'autorisation, sans base reelle.
 */
final class TestMeController extends MeController
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
}

final class MeControllerTest extends TestCase
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

    private function controller(SessionManager $session, FakeDatabase $db): TestMeController
    {
        $request = new Request('GET', '/admin/me', [], [], '', '203.0.113.5');

        return new TestMeController($request, new Config(), new Database(new Config()), $session, $db);
    }

    public function testNoSessionReturns401(): void
    {
        $response = $this->controller(new SessionManager(new Config(), true), new FakeDatabase())->show();

        self::assertSame(401, $response->status());

        $body = json_decode($response->body(), true);
        self::assertIsArray($body);
        self::assertSame('AUTH_REQUIRED', $body['error']['code'] ?? null);
    }

    public function testAuthenticatedReturnsIdentityAndPermissions(): void
    {
        $session = new SessionManager(new Config(), true);
        // Horodatages relatifs a l'instant reel : MeController appelle check() sans
        // injecter de temps (now = time()).
        $now = time();
        $session->set('user_id', 7);
        $session->set('role_id', 3);
        $session->set('logged_in_at', $now - 100);
        $session->set('last_activity', $now - 50);

        $db = new FakeDatabase();
        $db->guardUserRow = ['is_active' => 1];
        $db->roleRow = ['code' => 'manager'];
        $db->permissionCodes = ['product.read', 'stats.read'];

        $response = $this->controller($session, $db)->show();

        self::assertSame(200, $response->status());

        $body = json_decode($response->body(), true);
        self::assertIsArray($body);
        self::assertSame(7, $body['data']['user_id'] ?? null);
        self::assertSame(3, $body['data']['role_id'] ?? null);
        self::assertSame('manager', $body['data']['role_code'] ?? null);
        self::assertSame(['product.read', 'stats.read'], $body['data']['permissions'] ?? null);
    }

    public function testInactiveUserSessionReturns401(): void
    {
        $session = new SessionManager(new Config(), true);
        $now = time();
        $session->set('user_id', 7);
        $session->set('role_id', 3);
        $session->set('logged_in_at', $now - 100);
        $session->set('last_activity', $now - 50);

        $db = new FakeDatabase();
        $db->guardUserRow = ['is_active' => 0];

        $response = $this->controller($session, $db)->show();

        self::assertSame(401, $response->status());
    }
}
