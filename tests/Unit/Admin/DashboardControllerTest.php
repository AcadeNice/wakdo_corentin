<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use App\Auth\Authorizer;
use App\Auth\GuardResult;
use App\Auth\SessionGuard;
use App\Auth\SessionManager;
use App\Auth\UserDirectory;
use App\Catalogue\StatsRepository;
use App\Controllers\DashboardController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Tests\Support\FakeDatabase;

/**
 * Stub de StatsRepository : KPIs canned, sans base (les agregats reels sont
 * couverts par StatsRepositoryDbTest).
 *
 * stockHealth() porte ici un etat de stock NON SAIN (une bande low + une
 * critical, avec la liste alerts correspondante) : le dashboard exerce ainsi le
 * chemin "stock critique > 0" du fragment admin/dashboard.php. La forme de
 * 'alerts' suit le contrat de StatsRepository::stockHealth (name/stock_pct/
 * stock_band), meme si la tuile du dashboard ne consomme que bands.critical
 * (le rendu detaille de la liste vit dans admin/stats/index.php, couvert par
 * StatsControllerTest).
 */
final class DashStubStatsRepository extends StatsRepository
{
    public function counts(): array
    {
        return [
            'products'    => ['total' => 53, 'available' => 50],
            'categories'  => ['total' => 9, 'active' => 9],
            'menus'       => ['total' => 13, 'available' => 12],
            'ingredients' => ['total' => 7, 'active' => 6],
        ];
    }

    public function stockHealth(): array
    {
        return [
            'active_total' => 6,
            'bands'        => ['normal' => 4, 'low' => 1, 'critical' => 1],
            'alerts'       => [
                ['name' => 'Cheddar', 'stock_pct' => 3, 'stock_band' => 'critical'],
                ['name' => 'Cornichon', 'stock_pct' => 8, 'stock_band' => 'low'],
            ],
        ];
    }
}

/**
 * Stub a stock SAIN : aucune bande low/critical, liste d'alerte vide. Sert a
 * verifier le pendant negatif de la tuile stock (0 critique -> tag "OK", pas
 * "A recommander", classe d'alerte absente).
 */
final class DashHealthyStatsRepository extends StatsRepository
{
    public function counts(): array
    {
        return [
            'products'    => ['total' => 53, 'available' => 50],
            'categories'  => ['total' => 9, 'active' => 9],
            'menus'       => ['total' => 13, 'available' => 12],
            'ingredients' => ['total' => 7, 'active' => 7],
        ];
    }

    public function stockHealth(): array
    {
        return [
            'active_total' => 7,
            'bands'        => ['normal' => 7, 'low' => 0, 'critical' => 0],
            'alerts'       => [],
        ];
    }
}

/**
 * Sous-classe de test : injecte session test + FakeDatabase dans la garde,
 * l'autorisation et l'annuaire, sans base reelle.
 */
final class TestDashboardController extends DashboardController
{
    public function __construct(
        Request $request,
        Config $config,
        Database $database,
        private readonly SessionManager $testSession,
        private readonly FakeDatabase $fakeDb,
        // Stub de stats injectable : par defaut l'etat NON SAIN (low+critical),
        // surchargeable pour exercer aussi le pendant SAIN de la tuile stock.
        private readonly ?StatsRepository $statsStub = null,
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

    protected function statsRepository(): StatsRepository
    {
        return $this->statsStub ?? new DashStubStatsRepository($this->fakeDb);
    }

    /**
     * Expose le chemin garde par permission d'AdminController::guard() (RG-T03),
     * que le dashboard (auth seule) n'exerce pas.
     */
    public function gated(): GuardResult|Response
    {
        $guard = $this->guard('user.read');
        if ($guard instanceof Response) {
            return $guard;
        }

        return $this->adminView('admin/dashboard', ['title' => 't', 'activeNav' => ''], $guard);
    }
}

final class DashboardControllerTest extends TestCase
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

    private function controller(SessionManager $session, FakeDatabase $db, ?StatsRepository $statsStub = null): TestDashboardController
    {
        $request = new Request('GET', '/admin/dashboard', [], [], '', '203.0.113.5');

        return new TestDashboardController($request, new Config(), new Database(new Config()), $session, $db, $statsStub);
    }

    private function authedAdminDb(): FakeDatabase
    {
        $db = new FakeDatabase();
        $db->guardUserRow = ['is_active' => 1];
        $db->userDisplayRow = ['first_name' => 'Corentin', 'last_name' => 'J', 'role_label' => 'Administrateur'];
        $db->permissionCodes = ['product.read', 'user.read'];

        return $db;
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

    public function testRendersShellWhenAuthenticated(): void
    {
        $db = new FakeDatabase();
        $db->guardUserRow = ['is_active' => 1];
        $db->userDisplayRow = ['first_name' => 'Corentin', 'last_name' => 'J', 'role_label' => 'Administrateur'];
        $db->permissionCodes = ['product.read', 'user.read'];

        $response = $this->controller($this->authedSession(), $db)->index();

        self::assertSame(200, $response->status());
        $body = $response->body();
        // Shell rendu (topbar/sidebar) + identite + page.
        self::assertStringContainsString('admin-layout', $body);
        self::assertStringContainsString('Tableau de bord', $body);
        self::assertStringContainsString('Corentin J', $body);
        self::assertStringContainsString('Administrateur', $body);
        // Marqueur present UNIQUEMENT dans le fragment dashboard (absent du layout) :
        // verifie que le contenu est bien compose DANS le shell (pas un $content vide).
        self::assertStringContainsString('Bienvenue, Corentin J', $body);
        // Navigation conditionnee aux permissions : un lien n'apparait que si la
        // permission est presente ET la page existe.
        self::assertStringContainsString('/admin/products', $body);      // product.read present + page existante
        // user.read present + la page /admin/users existe desormais (lot Users) :
        // le lien de nav Administration apparait.
        self::assertStringContainsString('/admin/users', $body);
        self::assertStringNotContainsString('/admin/roles', $body);      // pas de page + role.manage absent
        // Deconnexion = formulaire POST avec CSRF.
        self::assertStringContainsString('action="/logout"', $body);
        self::assertStringContainsString('name="_csrf"', $body);
        // Le menu utilisateur rend la page self-service du PIN (decouvrable, pas
        // seulement par URL directe).
        self::assertStringContainsString('/admin/profile/pin', $body);
    }

    public function testRendersCriticalStockTileWhenStockNotHealthy(): void
    {
        // CIBLE F6 : exerce le chemin "stock NON sain" du fragment dashboard.
        // Le stub par defaut (DashStubStatsRepository) renvoie bands.critical = 1
        // et une liste alerts NON VIDE. Le fragment admin/dashboard.php derive
        // $nCritical de bands.critical et bascule la tuile en mode alerte des que
        // > 0 (classe "alert", tag "A recommander", valeur affichee).
        $response = $this->controller($this->authedSession(), $this->authedAdminDb())->index();

        self::assertSame(200, $response->status());
        $body = $response->body();
        // La tuile "Stock critique" affiche le compteur de la bande critical.
        self::assertStringContainsString('Stock critique', $body);
        // Branche $nCritical > 0 : tag d'action + classe d'alerte sur la tuile.
        self::assertStringContainsString('A recommander', $body);
        self::assertStringContainsString('tile alert', $body);
        // Pendant negatif : l'etat sain ("OK") ne doit pas etre rendu ici.
        self::assertStringNotContainsString('>OK<', $body);
    }

    public function testRendersHealthyStockTileWhenNoCriticalIngredient(): void
    {
        // Pendant SAIN : 0 critique -> branche $nCritical === 0 (tag "OK", pas de
        // classe d'alerte). Verrouille les deux cotes de la condition de la tuile.
        $stub = new DashHealthyStatsRepository(new FakeDatabase());
        $body = $this->controller($this->authedSession(), $this->authedAdminDb(), $stub)->index()->body();

        self::assertStringContainsString('Stock critique', $body);
        // Tag exact (>OK<) plutot que 'OK' nu, qui pourrait matcher du contenu
        // sans rapport (cookie, lookup, etc.).
        self::assertStringContainsString('>OK<', $body);
        self::assertStringNotContainsString('A recommander', $body);
        self::assertStringNotContainsString('tile alert', $body);
    }

    public function testForbiddenWhenPermissionDenied(): void
    {
        // Authentifie mais sans la permission requise (RG-T03) -> 403 + page forbidden.
        $db = new FakeDatabase();
        $db->guardUserRow = ['is_active' => 1];
        $db->userDisplayRow = ['first_name' => 'Corentin', 'last_name' => 'J', 'role_label' => 'Equipier'];
        $db->canResult = false;

        $response = $this->controller($this->authedSession(), $db)->gated();

        self::assertSame(403, $response->status());
        self::assertStringContainsString('Acces refuse', $response->body());
    }

    public function testGatedPageRendersWhenPermitted(): void
    {
        $db = new FakeDatabase();
        $db->guardUserRow = ['is_active' => 1];
        $db->userDisplayRow = ['first_name' => 'Corentin', 'last_name' => 'J', 'role_label' => 'Administrateur'];
        $db->canResult = true;
        $db->permissionCodes = ['user.read'];

        $response = $this->controller($this->authedSession(), $db)->gated();

        self::assertSame(200, $response->status());
    }

    public function testEscapesUserIdentity(): void
    {
        // Donnees user-editables (nom/role) : doivent etre echappees (RG-T15).
        $db = new FakeDatabase();
        $db->guardUserRow = ['is_active' => 1];
        $db->userDisplayRow = [
            'first_name' => '<script>alert(1)</script>',
            'last_name'  => 'x',
            'role_label' => 'Admin <b>& co</b>',
        ];
        $db->permissionCodes = ['user.read'];

        $body = $this->controller($this->authedSession(), $db)->index()->body();

        self::assertStringContainsString('&lt;script&gt;', $body);
        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringContainsString('&amp; co', $body);
        self::assertStringNotContainsString('Admin <b>', $body);
    }
}
