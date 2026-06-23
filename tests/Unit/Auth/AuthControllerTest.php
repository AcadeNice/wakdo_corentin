<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use App\Auth\AuthService;
use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\SessionManager;
use App\Controllers\AuthController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Tests\Support\FakeDatabase;

/**
 * Sous-classe de test : surcharge les hooks de fabrication pour injecter une
 * session en mode test et un FakeDatabase, sans toucher le Router ni la base.
 */
final class TestAuthController extends AuthController
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

    protected function authService(): AuthService
    {
        return new AuthService($this->fakeDb, $this->config, $this->testSession, new PasswordHasher($this->config));
    }
}

final class AuthControllerTest extends TestCase
{
    /** @var list<string> */
    private array $touchedKeys = [];

    protected function setUp(): void
    {
        $this->setEnv('ACCOUNT_LOCKOUT_THRESHOLD', '5');
        $this->setEnv('ACCOUNT_LOCKOUT_BASE_SECONDS', '60');
        $this->setEnv('ACCOUNT_LOCKOUT_MAX_SECONDS', '900');
        $this->setEnv('IP_THROTTLE_MAX_ATTEMPTS', '20');
        $this->setEnv('IP_THROTTLE_WINDOW_SECONDS', '900');
        $this->setEnv('ARGON2_MEMORY_COST', '1024');
        $this->setEnv('ARGON2_TIME_COST', '1');
        $this->setEnv('ARGON2_THREADS', '1');
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

    /**
     * @param array<string, string> $form
     */
    private function postRequest(array $form, string $path = '/login'): Request
    {
        return new Request(
            'POST',
            $path,
            [],
            ['content-type' => 'application/x-www-form-urlencoded'],
            http_build_query($form),
            '203.0.113.5',
        );
    }

    private function getRequest(string $path = '/login'): Request
    {
        return new Request('GET', $path, [], [], '', '203.0.113.5');
    }

    private function controller(Request $request, SessionManager $session, FakeDatabase $db): TestAuthController
    {
        return new TestAuthController($request, new Config(), new Database(new Config()), $session, $db);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function userRow(string $password, array $overrides = []): array
    {
        return array_merge([
            'id' => 7,
            'role_id' => 3,
            'password_hash' => (new PasswordHasher(new Config()))->hash($password),
            'failed_login_attempts' => 0,
            'lockout_until' => null,
            'default_route' => '/admin/dashboard',
        ], $overrides);
    }

    public function testShowLoginRendersCsrfField(): void
    {
        $session = new SessionManager(new Config(), true);
        $response = $this->controller($this->getRequest(), $session, new FakeDatabase())->showLogin();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('name="_csrf"', $response->body());
    }

    public function testLoginRejectsInvalidCsrfWith403(): void
    {
        $session = new SessionManager(new Config(), true);
        Csrf::token($session);
        $db = new FakeDatabase();

        $request = $this->postRequest(['_csrf' => 'wrong', 'email' => 'admin@wakdo.local', 'password' => 'x']);
        $response = $this->controller($request, $session, $db)->login();

        self::assertSame(403, $response->status());
        // L'authentification n'a pas tourne : aucune ecriture base.
        self::assertSame([], $db->writes);
    }

    public function testLoginBadCredentialsRendersGenericErrorWithoutRedirect(): void
    {
        $session = new SessionManager(new Config(), true);
        $token = Csrf::token($session);
        $db = new FakeDatabase();
        $db->userRow = $this->userRow('right-password');

        $request = $this->postRequest(['_csrf' => $token, 'email' => 'admin@wakdo.local', 'password' => 'WRONG']);
        $response = $this->controller($request, $session, $db)->login();

        self::assertSame(200, $response->status());
        self::assertNull($response->header('Location'));
        self::assertStringContainsString('Email ou mot de passe incorrect', $response->body());
    }

    public function testLoginSuccessRedirectsToDefaultRoute(): void
    {
        $session = new SessionManager(new Config(), true);
        $token = Csrf::token($session);
        $db = new FakeDatabase();
        $db->userRow = $this->userRow('correct-password');

        $request = $this->postRequest(['_csrf' => $token, 'email' => 'admin@wakdo.local', 'password' => 'correct-password']);
        $response = $this->controller($request, $session, $db)->login();

        self::assertSame(302, $response->status());
        self::assertSame('/admin/dashboard', $response->header('Location'));
        self::assertSame(7, $session->getInt('user_id'));
    }

    public function testLogoutRequiresValidCsrf(): void
    {
        $session = new SessionManager(new Config(), true);
        Csrf::token($session);
        $session->set('user_id', 7);

        $request = $this->postRequest(['_csrf' => 'wrong'], '/logout');
        $response = $this->controller($request, $session, new FakeDatabase())->logout();

        self::assertSame(403, $response->status());
        // Session intacte : la deconnexion forgee est refusee.
        self::assertSame(7, $session->getInt('user_id'));
    }

    public function testLogoutWithValidCsrfClearsSessionAndRedirects(): void
    {
        $session = new SessionManager(new Config(), true);
        $token = Csrf::token($session);
        $session->set('user_id', 7);

        $request = $this->postRequest(['_csrf' => $token], '/logout');
        $response = $this->controller($request, $session, new FakeDatabase())->logout();

        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('Location'));
        self::assertNull($session->getInt('user_id'));
    }
}
