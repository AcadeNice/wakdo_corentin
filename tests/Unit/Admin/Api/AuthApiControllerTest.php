<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin\Api;

use PHPUnit\Framework\TestCase;
use App\Auth\Authorizer;
use App\Auth\AuthService;
use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\SessionGuard;
use App\Auth\SessionManager;
use App\Controllers\Admin\Api\AuthApiController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\Request;
use App\Tests\Support\FakeDatabase;

/**
 * Sous-classe de test : meme seam que TestCategoryApiController/TestAuthController
 * (CategoryApiControllerTest / AuthControllerTest) -- session en mode test,
 * FakeDatabase, et un authService() reconstruit sur le meme FakeDatabase (pas
 * une vraie connexion), pour exercer AuthService::authenticate() sans base.
 */
final class TestAuthApiController extends AuthApiController
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

    protected function authService(): AuthService
    {
        return new AuthService($this->fakeDb, $this->config, $this->testSession, new PasswordHasher($this->config));
    }
}

final class AuthApiControllerTest extends TestCase
{
    /** @var list<string> */
    private array $touchedKeys = [];

    protected function setUp(): void
    {
        $this->setEnv('SESSION_LIFETIME_IDLE', '14400');
        $this->setEnv('SESSION_LIFETIME_ABSOLUTE', '36000');
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
     * @param array<string, mixed>|null $jsonBody null => corps vide (GET, logout)
     */
    private function request(string $method, string $path, ?array $jsonBody = null, ?string $csrf = null, ?string $contentType = 'application/json'): Request
    {
        $headers = [];
        $raw = '';
        if ($jsonBody !== null) {
            $raw = (string) json_encode($jsonBody);
            if ($contentType !== null) {
                $headers['content-type'] = $contentType;
            }
        }
        if ($csrf !== null) {
            $headers['x-csrf-token'] = $csrf;
        }

        return new Request($method, $path, [], $headers, $raw, '203.0.113.7');
    }

    private function controller(Request $request, SessionManager $session, FakeDatabase $db): TestAuthApiController
    {
        return new TestAuthApiController($request, new Config(), new Database(new Config()), $session, $db);
    }

    private function authenticatedSession(): SessionManager
    {
        $session = new SessionManager(new Config(), true);
        $now = time();
        $session->set('user_id', 7);
        $session->set('role_id', 3);
        $session->set('logged_in_at', $now - 100);
        $session->set('last_activity', $now - 50);

        return $session;
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

    // --- POST /admin/api/auth/login ---

    public function testLoginSuccessReturnsUserPermissionsAndCsrfToken(): void
    {
        $db = new FakeDatabase();
        $db->userRow = $this->userRow('correct-password');
        $db->userDisplayRow = ['first_name' => 'Ada', 'last_name' => 'Admin', 'email' => 'admin@wakdo.local', 'role_label' => 'Administrateur', 'order_source' => null];
        $db->roleRow = ['code' => 'admin'];
        $db->permissionCodes = ['product.read', 'stats.read'];

        $session = new SessionManager(new Config(), true);
        $request = $this->request('POST', '/admin/api/auth/login', ['email' => 'admin@wakdo.local', 'password' => 'correct-password']);
        $response = $this->controller($request, $session, $db)->apiLogin();

        self::assertSame(200, $response->status());
        $body = json_decode($response->body(), true);
        self::assertSame(7, $body['data']['user']['id'] ?? null);
        self::assertSame('admin@wakdo.local', $body['data']['user']['email'] ?? null);
        self::assertSame('Ada Admin', $body['data']['user']['display_name'] ?? null);
        self::assertSame('admin', $body['data']['user']['role'] ?? null);
        self::assertSame(['product.read', 'stats.read'], $body['data']['permissions'] ?? null);
        self::assertNotSame('', $body['data']['csrf_token'] ?? '');
        // Session serveur bien posee (cookie HttpOnly, cf. SessionManager::start()).
        self::assertSame(7, $session->getInt('user_id'));
        self::assertSame($body['data']['csrf_token'], Csrf::token($session));
    }

    public function testLoginNeverLeaksPasswordOrHash(): void
    {
        $hash = (new PasswordHasher(new Config()))->hash('correct-password');
        $db = new FakeDatabase();
        $db->userRow = $this->userRow('correct-password');
        $db->userDisplayRow = ['first_name' => 'Ada', 'last_name' => 'Admin', 'email' => 'admin@wakdo.local', 'role_label' => '', 'order_source' => null];
        $db->roleRow = ['code' => 'admin'];

        $request = $this->request('POST', '/admin/api/auth/login', ['email' => 'admin@wakdo.local', 'password' => 'correct-password']);
        $response = $this->controller($request, new SessionManager(new Config(), true), $db)->apiLogin();

        self::assertStringNotContainsString($hash, $response->body());
        self::assertStringNotContainsString('password_hash', $response->body());
        self::assertStringNotContainsString('correct-password', $response->body());
    }

    public function testLoginWrongPasswordReturns401InvalidCredentials(): void
    {
        $db = new FakeDatabase();
        $db->userRow = $this->userRow('right-password');

        $request = $this->request('POST', '/admin/api/auth/login', ['email' => 'admin@wakdo.local', 'password' => 'WRONG']);
        $response = $this->controller($request, new SessionManager(new Config(), true), $db)->apiLogin();

        self::assertSame(401, $response->status());
        $body = json_decode($response->body(), true);
        self::assertSame('INVALID_CREDENTIALS', $body['error']['code'] ?? null);
        // Message GENERIQUE (anti-enumeration, RG-2/ERR-3) : identique quel que
        // soit le motif reel de l'echec (mot de passe faux, email inconnu, compte
        // verrouille) -- verifie ici pour que le message ne devienne pas, par
        // inadvertance, un canal de fuite distinct du code.
        self::assertSame('Email ou mot de passe incorrect', $body['error']['message'] ?? null);
        self::assertNull($response->header('Retry-After'));
    }

    public function testLoginUnknownEmailReturns401InvalidCredentials(): void
    {
        $db = new FakeDatabase();
        $db->userRow = null;

        $request = $this->request('POST', '/admin/api/auth/login', ['email' => 'ghost@wakdo.local', 'password' => 'whatever']);
        $response = $this->controller($request, new SessionManager(new Config(), true), $db)->apiLogin();

        self::assertSame(401, $response->status());
        $body = json_decode($response->body(), true);
        self::assertSame('INVALID_CREDENTIALS', $body['error']['code'] ?? null);
        self::assertSame('Email ou mot de passe incorrect', $body['error']['message'] ?? null);
    }

    /**
     * LIMITE ASSUMEE de ce test unitaire : FakeDatabase ne rejoue pas le SQL,
     * donc ne peut QUE simuler "userRow = null" -- il ne charge PAS un vrai compte
     * inactif, il verifie seulement que la reponse HTTP est la MEME que pour un
     * email inconnu (le comportement attendu, PUISQUE findActiveUserByEmail()
     * filtre `is_active = 1` en SQL). La preuve avec un VRAI compte, desactive
     * puis interroge contre une vraie base, est
     * AuthServiceDbTest::testInactiveAccountBehavesLikeUnknownEmail()
     * (tests/Integration/, WAKDO_DB_TESTS=1).
     */
    public function testLoginInactiveAccountReturns401InvalidCredentials(): void
    {
        // findActiveUserByEmail() filtre is_active = 1 en SQL : un compte desactive
        // se comporte, cote FakeDatabase, comme un email inconnu (userRow = null).
        $db = new FakeDatabase();
        $db->userRow = null;

        $request = $this->request('POST', '/admin/api/auth/login', ['email' => 'disabled@wakdo.local', 'password' => 'whatever']);
        $response = $this->controller($request, new SessionManager(new Config(), true), $db)->apiLogin();

        self::assertSame(401, $response->status());
        $body = json_decode($response->body(), true);
        self::assertSame('INVALID_CREDENTIALS', $body['error']['code'] ?? null);
    }

    public function testLoginIpLockedReturns429WithRetryAfter(): void
    {
        $db = new FakeDatabase();
        $db->userRow = $this->userRow('correct-password');
        $db->ipLockoutUntil = date('Y-m-d H:i:s', time() + 300);

        $request = $this->request('POST', '/admin/api/auth/login', ['email' => 'admin@wakdo.local', 'password' => 'correct-password']);
        $response = $this->controller($request, new SessionManager(new Config(), true), $db)->apiLogin();

        self::assertSame(429, $response->status());
        $body = json_decode($response->body(), true);
        self::assertSame('TOO_MANY_ATTEMPTS', $body['error']['code'] ?? null);
        self::assertSame('Email ou mot de passe incorrect', $body['error']['message'] ?? null);
        self::assertNotNull($response->header('Retry-After'));
        self::assertGreaterThan(0, (int) $response->header('Retry-After'));
    }

    /**
     * Anti-enumeration (RG-2/ERR-3, cf. AuthResult::throttled()) : un verrou de
     * COMPTE (contrairement au verrou IP ci-dessus) reste un simple 401, jamais un
     * 429 -- sinon le code HTTP revelerait qu'un compte existe et est verrouille.
     */
    public function testLoginAccountLockedReturns401NotRateLimited(): void
    {
        $db = new FakeDatabase();
        $db->userRow = $this->userRow('correct-password', ['lockout_until' => date('Y-m-d H:i:s', time() + 120)]);

        $request = $this->request('POST', '/admin/api/auth/login', ['email' => 'admin@wakdo.local', 'password' => 'correct-password']);
        $response = $this->controller($request, new SessionManager(new Config(), true), $db)->apiLogin();

        self::assertSame(401, $response->status());
        $body = json_decode($response->body(), true);
        self::assertSame('INVALID_CREDENTIALS', $body['error']['code'] ?? null);
        self::assertSame('Email ou mot de passe incorrect', $body['error']['message'] ?? null);
        self::assertNull($response->header('Retry-After'));
    }

    public function testLoginRejectsInvalidJsonSyntaxWith400(): void
    {
        $request = new Request('POST', '/admin/api/auth/login', [], ['content-type' => 'application/json'], '{not-json', '203.0.113.7');
        $response = $this->controller($request, new SessionManager(new Config(), true), new FakeDatabase())->apiLogin();

        self::assertSame(400, $response->status());
        $body = json_decode($response->body(), true);
        self::assertSame('INVALID_JSON', $body['error']['code'] ?? null);
    }

    public function testLoginRejectsWrongContentTypeWith415(): void
    {
        $request = new Request('POST', '/admin/api/auth/login', [], ['content-type' => 'text/plain'], '{"email":"a@b.fr","password":"x"}', '203.0.113.7');
        $response = $this->controller($request, new SessionManager(new Config(), true), new FakeDatabase())->apiLogin();

        self::assertSame(415, $response->status());
        $body = json_decode($response->body(), true);
        self::assertSame('UNSUPPORTED_MEDIA_TYPE', $body['error']['code'] ?? null);
    }

    /**
     * `str_starts_with($contentType, 'application/json')` acceptait a tort ce
     * type, puisqu'il COMMENCE PAR la chaine attendue.
     * `JsonApiTrait::requireJsonBody()` compare desormais le type de media
     * strictement (avant tout `;` de parametres).
     */
    public function testLoginRejectsJsonpContentTypeWith415(): void
    {
        $request = new Request('POST', '/admin/api/auth/login', [], ['content-type' => 'application/jsonp'], '{"email":"a@b.fr","password":"x"}', '203.0.113.7');
        $response = $this->controller($request, new SessionManager(new Config(), true), new FakeDatabase())->apiLogin();

        self::assertSame(415, $response->status());
        $body = json_decode($response->body(), true);
        self::assertSame('UNSUPPORTED_MEDIA_TYPE', $body['error']['code'] ?? null);
    }

    public function testLoginRejectsNonScalarFieldWith422(): void
    {
        $request = $this->request('POST', '/admin/api/auth/login', ['email' => ['a@b.fr'], 'password' => 'x']);
        $response = $this->controller($request, new SessionManager(new Config(), true), new FakeDatabase())->apiLogin();

        self::assertSame(422, $response->status());
        $body = json_decode($response->body(), true);
        self::assertSame('VALIDATION_ERROR', $body['error']['code'] ?? null);
        self::assertArrayHasKey('email', $body['error']['fields'] ?? []);
    }

    public function testLoginRejectsMissingFieldsWith422(): void
    {
        // Corps `{}` litteral (pas json_encode([]), qui produirait `[]` -- une
        // LISTE vide, rejetee en amont par requireJsonBody() en 400 INVALID_JSON,
        // ce qui masquerait la garde testee ici).
        $request = new Request('POST', '/admin/api/auth/login', [], ['content-type' => 'application/json'], '{}', '203.0.113.7');
        $response = $this->controller($request, new SessionManager(new Config(), true), new FakeDatabase())->apiLogin();

        self::assertSame(422, $response->status());
        $body = json_decode($response->body(), true);
        self::assertSame('VALIDATION_ERROR', $body['error']['code'] ?? null);
        self::assertArrayHasKey('email', $body['error']['fields'] ?? []);
        self::assertArrayHasKey('password', $body['error']['fields'] ?? []);
    }

    public function testLoginDoesNotRequireCsrfOrPriorSession(): void
    {
        // Aucun en-tete X-CSRF-Token, aucune session prealable : apiLogin() n'a ni
        // guardApi() ni requireCsrf() -- la protection est Content-Type/CORS/SameSite
        // (docblock d'AuthApiController), pas le jeton synchroniseur (qui n'existe
        // pas encore avant une authentification reussie).
        $db = new FakeDatabase();
        $db->userRow = $this->userRow('correct-password');
        $db->userDisplayRow = ['first_name' => '', 'last_name' => '', 'email' => 'admin@wakdo.local', 'role_label' => '', 'order_source' => null];
        $db->roleRow = ['code' => 'admin'];

        $request = $this->request('POST', '/admin/api/auth/login', ['email' => 'admin@wakdo.local', 'password' => 'correct-password'], null);
        $response = $this->controller($request, new SessionManager(new Config(), true), $db)->apiLogin();

        self::assertSame(200, $response->status());
    }

    // --- POST /admin/api/auth/logout ---

    public function testLogoutWithoutSessionReturns401(): void
    {
        $request = $this->request('POST', '/admin/api/auth/logout', null);
        $response = $this->controller($request, new SessionManager(new Config(), true), new FakeDatabase())->apiLogout();

        self::assertSame(401, $response->status());
        $body = json_decode($response->body(), true);
        self::assertSame('AUTH_REQUIRED', $body['error']['code'] ?? null);
    }

    public function testLogoutWithoutCsrfReturns403AndKeepsSession(): void
    {
        $session = $this->authenticatedSession();
        Csrf::token($session);
        $db = new FakeDatabase();
        $db->guardUserRow = ['is_active' => 1];

        $request = $this->request('POST', '/admin/api/auth/logout', null);
        $response = $this->controller($request, $session, $db)->apiLogout();

        self::assertSame(403, $response->status());
        $body = json_decode($response->body(), true);
        self::assertSame('CSRF_INVALID', $body['error']['code'] ?? null);
        self::assertSame(7, $session->getInt('user_id'));
    }

    public function testLogoutWithValidCsrfClearsSessionAndReturns204(): void
    {
        $session = $this->authenticatedSession();
        $token = Csrf::token($session);
        $db = new FakeDatabase();
        $db->guardUserRow = ['is_active' => 1];

        $request = $this->request('POST', '/admin/api/auth/logout', null, $token);
        $response = $this->controller($request, $session, $db)->apiLogout();

        self::assertSame(204, $response->status());
        self::assertSame('', $response->body());
        self::assertNull($session->getInt('user_id'));
    }

    // --- GET /admin/api/auth/me (alias de GET /admin/me) ---

    public function testMeWithoutSessionReturns401(): void
    {
        $request = $this->request('GET', '/admin/api/auth/me', null);
        $response = $this->controller($request, new SessionManager(new Config(), true), new FakeDatabase())->apiMe();

        self::assertSame(401, $response->status());
        $body = json_decode($response->body(), true);
        self::assertSame('AUTH_REQUIRED', $body['error']['code'] ?? null);
    }

    public function testMeWithSessionReturnsSameShapeAsAdminMe(): void
    {
        $session = $this->authenticatedSession();
        $db = new FakeDatabase();
        $db->guardUserRow = ['is_active' => 1];
        $db->roleRow = ['code' => 'admin'];
        $db->permissionCodes = ['product.read', 'stats.read'];

        $request = $this->request('GET', '/admin/api/auth/me', null);
        $response = $this->controller($request, $session, $db)->apiMe();

        self::assertSame(200, $response->status());
        $body = json_decode($response->body(), true);
        self::assertSame(7, $body['data']['user_id'] ?? null);
        self::assertSame(3, $body['data']['role_id'] ?? null);
        self::assertSame('admin', $body['data']['role_code'] ?? null);
        self::assertSame(['product.read', 'stats.read'], $body['data']['permissions'] ?? null);
        self::assertNotSame('', $body['data']['csrf_token'] ?? '');
    }
}
