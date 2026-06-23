<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use App\Auth\Authorizer;
use App\Auth\Csrf;
use App\Auth\SessionGuard;
use App\Auth\SessionManager;
use App\Auth\UserDirectory;
use App\Auth\UserRepository;
use App\Controllers\ProfileController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Tests\Support\FakeDatabase;

final class TestProfileController extends ProfileController
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

    protected function userRepository(): UserRepository
    {
        return new UserRepository($this->fakeDb);
    }
}

final class ProfileControllerTest extends TestCase
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
        $this->session->set('role_id', 1);
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
        $db->userDisplayRow = ['first_name' => 'Corentin', 'last_name' => 'J', 'role_label' => 'Administrateur'];
        $db->canResult = true;
        $db->permissionCodes = ['category.manage'];

        return $db;
    }

    /**
     * @param array<string, string> $form
     */
    private function post(array $form): Request
    {
        return new Request(
            'POST',
            '/admin/profile/pin',
            [],
            ['content-type' => 'application/x-www-form-urlencoded'],
            http_build_query($form),
            '203.0.113.5',
        );
    }

    private function controller(Request $request, FakeDatabase $db): TestProfileController
    {
        return new TestProfileController($request, new Config(), new Database(new Config()), $this->session, $db);
    }

    public function testRedirectsToLoginWithoutSession(): void
    {
        $request = new Request('GET', '/admin/profile/pin', [], [], '', '203.0.113.5');
        $response = $this->controller($request, new FakeDatabase())->showPin();

        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('Location'));
    }

    public function testShowPinReflectsStatus(): void
    {
        $request = new Request('GET', '/admin/profile/pin', [], [], '', '203.0.113.5');

        $db = $this->permittedDb();
        $db->userPinSet = false;
        $response = $this->controller($request, $db)->showPin();
        self::assertSame(200, $response->status());
        self::assertStringContainsString('name="pin"', $response->body());
        self::assertStringContainsString('aucun PIN defini', $response->body());

        $db2 = $this->permittedDb();
        $db2->userPinSet = true;
        self::assertStringContainsString('un PIN est defini', $this->controller($request, $db2)->showPin()->body());
    }

    public function testUpdatePinValidStoresHashAndRedirects(): void
    {
        $db = $this->permittedDb();
        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'pin' => '4729', 'pin_confirm' => '4729']), $db)->updatePin();

        self::assertSame(302, $response->status());
        self::assertSame('/admin/profile/pin', $response->header('Location'));
        self::assertSame('PIN enregistre.', $this->session->get('_flash'));

        // Invariant central : la cible est l'utilisateur de la SESSION (1, pose en
        // setUp), jamais un champ de formulaire ; et c'est un hash, pas le PIN clair.
        $write = null;
        foreach ($db->writes as $w) {
            if (str_contains($w['sql'], 'UPDATE user SET pin_hash')) {
                $write = $w;
                break;
            }
        }
        self::assertNotNull($write);
        self::assertSame(1, $write['params']['id'] ?? null);
        self::assertNotSame('4729', $write['params']['hash'] ?? null);
    }

    public function testUpdatePinFailsWhenNoRowAffected(): void
    {
        // Cible inexistante (0 ligne affectee) : pas de faux succes, pas de flash.
        $db = $this->permittedDb();
        $db->executeRowCount = 0;

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'pin' => '4729', 'pin_confirm' => '4729']), $db)->updatePin();

        self::assertSame(500, $response->status());
        self::assertNull($this->session->get('_flash'));
    }

    public function testUpdatePinMismatchRerenders422(): void
    {
        $db = $this->permittedDb();
        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'pin' => '4729', 'pin_confirm' => '0000']), $db)->updatePin();

        self::assertSame(422, $response->status());
        self::assertStringContainsString('ne correspondent pas', $response->body());
        self::assertFalse($db->wrote('UPDATE user SET pin_hash'));
    }

    public function testUpdatePinTooShortRerenders422(): void
    {
        $db = $this->permittedDb();
        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'pin' => '12', 'pin_confirm' => '12']), $db)->updatePin();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('UPDATE user SET pin_hash'));
    }

    public function testUpdatePinRejectsInvalidCsrf(): void
    {
        $db = $this->permittedDb();
        $response = $this->controller($this->post(['_csrf' => 'wrong', 'pin' => '4729', 'pin_confirm' => '4729']), $db)->updatePin();

        self::assertSame(403, $response->status());
        self::assertFalse($db->wrote('UPDATE user SET pin_hash'));
    }
}
