<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\PasswordResetService;
use App\Auth\SessionManager;
use App\Controllers\PasswordResetController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Tests\Support\FakeDatabase;
use App\Tests\Support\SpyMailer;

/**
 * Sous-classe de test : injecte session test, FakeDatabase et SpyMailer dans le
 * controleur de reinitialisation.
 */
final class TestPasswordResetController extends PasswordResetController
{
    public function __construct(
        Request $request,
        Config $config,
        Database $database,
        private readonly SessionManager $testSession,
        private readonly FakeDatabase $fakeDb,
        private readonly SpyMailer $spyMailer,
    ) {
        parent::__construct($request, $config, $database);
    }

    protected function sessionManager(): SessionManager
    {
        return $this->testSession;
    }

    protected function resetService(): PasswordResetService
    {
        return new PasswordResetService($this->fakeDb, $this->config, new PasswordHasher($this->config), $this->spyMailer);
    }
}

final class PasswordResetControllerTest extends TestCase
{
    /** @var list<string> */
    private array $touchedKeys = [];

    protected function setUp(): void
    {
        $this->setEnv('PASSWORD_RESET_TTL', '3600');
        $this->setEnv('APP_URL_ADMIN', 'https://admin.wakdo.test');
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
    private function post(array $form, string $path): Request
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

    private function controller(
        Request $request,
        SessionManager $session,
        FakeDatabase $db,
        SpyMailer $mailer,
    ): TestPasswordResetController {
        return new TestPasswordResetController($request, new Config(), new Database(new Config()), $session, $db, $mailer);
    }

    public function testShowRequestRendersCsrfField(): void
    {
        $session = new SessionManager(new Config(), true);
        $request = new Request('GET', '/forgot_password', [], [], '', '203.0.113.5');

        $response = $this->controller($request, $session, new FakeDatabase(), new SpyMailer())->showRequest();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('name="_csrf"', $response->body());
    }

    public function testSubmitRequestRejectsInvalidCsrf(): void
    {
        $session = new SessionManager(new Config(), true);
        Csrf::token($session);
        $mailer = new SpyMailer();

        $request = $this->post(['_csrf' => 'wrong', 'email' => 'admin@wakdo.local'], '/forgot_password');
        $response = $this->controller($request, $session, new FakeDatabase(), $mailer)->submitRequest();

        self::assertSame(403, $response->status());
        self::assertSame([], $mailer->sent);
    }

    public function testSubmitRequestUnknownEmailIsNeutralAndSilent(): void
    {
        $session = new SessionManager(new Config(), true);
        $token = Csrf::token($session);
        $db = new FakeDatabase();
        $db->emailLookupRow = null;
        $mailer = new SpyMailer();

        $request = $this->post(['_csrf' => $token, 'email' => 'ghost@wakdo.local'], '/forgot_password');
        $response = $this->controller($request, $session, $db, $mailer)->submitRequest();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Si un compte', $response->body());
        self::assertSame([], $mailer->sent);
        self::assertSame([], $db->writes);
    }

    public function testSubmitConfirmPasswordMismatchRendersError(): void
    {
        $session = new SessionManager(new Config(), true);
        $token = Csrf::token($session);

        $request = $this->post([
            '_csrf' => $token,
            'token' => 'raw-token',
            'password' => 'longenough1',
            'password_confirm' => 'different01',
        ], '/reset_password');
        $response = $this->controller($request, $session, new FakeDatabase(), new SpyMailer())->submitConfirm();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('ne correspondent pas', $response->body());
    }

    public function testSubmitConfirmValidTokenRedirectsToLogin(): void
    {
        $session = new SessionManager(new Config(), true);
        $token = Csrf::token($session);
        $db = new FakeDatabase();
        $db->resetUserRow = ['id' => 7, 'role_id' => 3, 'password_reset_token_hash' => hash('sha256', 'raw-token')];

        $request = $this->post([
            '_csrf' => $token,
            'token' => 'raw-token',
            'password' => 'brandnewpassword',
            'password_confirm' => 'brandnewpassword',
        ], '/reset_password');
        $response = $this->controller($request, $session, $db, new SpyMailer())->submitConfirm();

        self::assertSame(302, $response->status());
        self::assertSame('/login?reset=ok', $response->header('Location'));
    }
}
