<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use App\Auth\Authorizer;
use App\Auth\SessionGuard;
use App\Auth\SessionManager;
use App\Auth\UserDirectory;
use App\Controllers\PrivacyController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Tests\Support\FakeDatabase;

/**
 * Sous-classe de test : injecte session test + FakeDatabase dans la garde,
 * l'autorisation et l'annuaire, sans base reelle.
 */
final class TestPrivacyController extends PrivacyController
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
}

final class PrivacyControllerTest extends TestCase
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

    private function controller(SessionManager $session, FakeDatabase $db): TestPrivacyController
    {
        $request = new Request('GET', '/admin/privacy', [], [], '', '203.0.113.5');

        return new TestPrivacyController($request, new Config(), new Database(new Config()), $session, $db);
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

    public function testInactiveUserRedirectsToLogin(): void
    {
        $db = new FakeDatabase();
        $db->guardUserRow = ['is_active' => 0];

        $response = $this->controller($this->authedSession(), $db)->index();

        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('Location'));
    }

    public function testRendersRgpdNoticeForAnyAuthenticatedUser(): void
    {
        // Aucune permission specifique requise : tout utilisateur authentifie y accede,
        // meme un role sans permission de gestion (canResult = false).
        $db = new FakeDatabase();
        $db->guardUserRow = ['is_active' => 1];
        $db->userDisplayRow = ['first_name' => 'Sami', 'last_name' => 'K', 'role_label' => 'Equipier'];
        $db->permissionCodes = [];
        $db->canResult = false;

        $response = $this->controller($this->authedSession(), $db)->index();

        self::assertSame(200, $response->status());
        $body = $response->body();
        // Shell rendu + contenu RGPD attendu (Cr 3.d.2 : stockage / utilisation / partage / droits).
        self::assertStringContainsString('admin-layout', $body);
        self::assertStringContainsString('Traitement des donnees personnelles', $body);
        self::assertStringContainsString('Donnees traitees', $body);
        self::assertStringContainsString('partage', $body);
        self::assertStringContainsString('droit', $body);
        self::assertStringContainsString('effacement', $body);
        // La page rappelle l'anonymisation comme materialisation de l'effacement.
        self::assertStringContainsString('anonymis', $body);
        // Completude d'une notice RGPD (Cr 3.d.2) : base legale, responsable de
        // traitement + contact, et duree de conservation concrete (coherente MLD ~12 mois).
        self::assertStringContainsString('Base legale', $body);
        self::assertStringContainsString('Responsable du traitement', $body);
        self::assertStringContainsString('contact@wakdo.local', $body);
        self::assertStringContainsString('12 mois', $body);
    }
}
