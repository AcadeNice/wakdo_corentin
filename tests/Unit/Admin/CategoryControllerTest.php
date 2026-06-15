<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use App\Auth\Authorizer;
use App\Auth\Csrf;
use App\Auth\SessionGuard;
use App\Auth\SessionManager;
use App\Auth\UserDirectory;
use App\Catalogue\CategoryRepository;
use App\Controllers\CategoryController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Tests\Support\FakeDatabase;

/**
 * Sous-classe de test : injecte session test + FakeDatabase dans la garde,
 * l'autorisation, l'annuaire et le repository, sans base reelle.
 */
final class TestCategoryController extends CategoryController
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

    protected function categoryRepository(): CategoryRepository
    {
        return new CategoryRepository($this->fakeDb);
    }
}

final class CategoryControllerTest extends TestCase
{
    /** @var list<string> */
    private array $touchedKeys = [];

    private SessionManager $session;
    private string $csrf = '';

    protected function setUp(): void
    {
        $this->setEnv('SESSION_LIFETIME_IDLE', '14400');
        $this->setEnv('SESSION_LIFETIME_ABSOLUTE', '36000');

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

    private function get(string $path): Request
    {
        return new Request('GET', $path, [], [], '', '203.0.113.5');
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

    private function controller(Request $request, FakeDatabase $db): TestCategoryController
    {
        return new TestCategoryController($request, new Config(), new Database(new Config()), $this->session, $db);
    }

    private function wroteContaining(FakeDatabase $db, string $needle): bool
    {
        return $db->wrote($needle);
    }

    public function testGuardDeniesWithoutPermission(): void
    {
        $db = $this->permittedDb();
        $db->canResult = false;

        $response = $this->controller($this->get('/admin/categories'), $db)->index();

        self::assertSame(403, $response->status());
        self::assertStringContainsString('Acces refuse', $response->body());
    }

    public function testIndexListsCategories(): void
    {
        $db = $this->permittedDb();
        $db->categoriesRows = [
            ['id' => 1, 'name' => 'Burgers', 'slug' => 'burgers', 'image_path' => null, 'display_order' => 2, 'is_active' => 1],
            ['id' => 2, 'name' => 'Sauces', 'slug' => 'sauces', 'image_path' => null, 'display_order' => 9, 'is_active' => 0],
        ];

        $response = $this->controller($this->get('/admin/categories'), $db)->index();
        $body = $response->body();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Nouvelle categorie', $body);
        self::assertStringContainsString('Burgers', $body);
        self::assertStringContainsString('Visible', $body);   // is_active = 1
        self::assertStringContainsString('Masquee', $body);   // is_active = 0
    }

    public function testCreateShowsForm(): void
    {
        $response = $this->controller($this->get('/admin/categories/new'), $this->permittedDb())->create();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('name="slug"', $response->body());
        self::assertStringContainsString('action="/admin/categories"', $response->body());
    }

    public function testStoreValidCreatesAndRedirects(): void
    {
        $db = $this->permittedDb();
        $request = $this->post(
            ['_csrf' => $this->csrf, 'name' => 'Desserts', 'slug' => 'desserts', 'display_order' => '7'],
            '/admin/categories',
        );

        $response = $this->controller($request, $db)->store();

        self::assertSame(302, $response->status());
        self::assertSame('/admin/categories', $response->header('Location'));
        self::assertTrue($this->wroteContaining($db, 'INSERT INTO category'));
        self::assertSame('Categorie creee.', $this->session->get('_flash'));
    }

    public function testStoreInvalidRerendersWithErrorsAndNoWrite(): void
    {
        $db = $this->permittedDb();
        $request = $this->post(
            ['_csrf' => $this->csrf, 'name' => '', 'slug' => 'INVALID SLUG', 'display_order' => '7'],
            '/admin/categories',
        );

        $response = $this->controller($request, $db)->store();

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Le libelle est requis', $response->body());
        self::assertStringContainsString('Slug requis', $response->body());
        self::assertFalse($this->wroteContaining($db, 'INSERT INTO category'));
    }

    public function testStoreRejectsDuplicateName(): void
    {
        $db = $this->permittedDb();
        $db->categoryNameTaken = true;
        $request = $this->post(
            ['_csrf' => $this->csrf, 'name' => 'Desserts', 'slug' => 'desserts', 'display_order' => '7'],
            '/admin/categories',
        );

        $response = $this->controller($request, $db)->store();

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Ce libelle existe deja', $response->body());
        self::assertFalse($this->wroteContaining($db, 'INSERT INTO category'));
    }

    public function testStoreRejectsOverRangeDisplayOrder(): void
    {
        $db = $this->permittedDb();
        $request = $this->post(
            ['_csrf' => $this->csrf, 'name' => 'Desserts', 'slug' => 'desserts', 'display_order' => '70000'],
            '/admin/categories',
        );

        $response = $this->controller($request, $db)->store();

        self::assertSame(422, $response->status());
        self::assertStringContainsString('entre 0 et 65535', $response->body());
        self::assertFalse($this->wroteContaining($db, 'INSERT INTO category'));
    }

    public function testStoreTranslatesUniqueViolationTo422(): void
    {
        // Fenetre de concurrence : la base leve une violation 23000 a l'insertion ;
        // le controleur doit re-afficher le formulaire (422), pas remonter un 500.
        $db = $this->permittedDb();
        $db->failOnExecute = new \PDOException('duplicate', 23000);
        $request = $this->post(
            ['_csrf' => $this->csrf, 'name' => 'Desserts', 'slug' => 'desserts', 'display_order' => '7'],
            '/admin/categories',
        );

        $response = $this->controller($request, $db)->store();

        self::assertSame(422, $response->status());
        self::assertStringContainsString('existe deja', $response->body());
    }

    public function testStoreRejectsDuplicateSlug(): void
    {
        $db = $this->permittedDb();
        $db->categorySlugTaken = true;
        $request = $this->post(
            ['_csrf' => $this->csrf, 'name' => 'Desserts', 'slug' => 'desserts', 'display_order' => '7'],
            '/admin/categories',
        );

        $response = $this->controller($request, $db)->store();

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Ce slug existe deja', $response->body());
        self::assertFalse($this->wroteContaining($db, 'INSERT INTO category'));
    }

    public function testStoreRejectsInvalidCsrf(): void
    {
        $db = $this->permittedDb();
        $request = $this->post(
            ['_csrf' => 'wrong', 'name' => 'Desserts', 'slug' => 'desserts', 'display_order' => '7'],
            '/admin/categories',
        );

        $response = $this->controller($request, $db)->store();

        self::assertSame(403, $response->status());
        self::assertFalse($this->wroteContaining($db, 'INSERT INTO category'));
    }

    public function testEditNotFoundReturns404(): void
    {
        $db = $this->permittedDb();
        $db->categoryRow = null;

        $response = $this->controller($this->get('/admin/categories/999/edit'), $db)->edit(['id' => '999']);

        self::assertSame(404, $response->status());
        self::assertStringContainsString('Introuvable', $response->body());
    }

    public function testUpdateValidRedirects(): void
    {
        $db = $this->permittedDb();
        $db->categoryRow = ['id' => 5, 'name' => 'Wraps', 'slug' => 'wraps', 'image_path' => null, 'display_order' => 3, 'is_active' => 1];
        $request = $this->post(
            ['_csrf' => $this->csrf, 'name' => 'Wraps & Co', 'slug' => 'wraps', 'display_order' => '3'],
            '/admin/categories/5',
        );

        $response = $this->controller($request, $db)->update(['id' => '5']);

        self::assertSame(302, $response->status());
        self::assertTrue($this->wroteContaining($db, 'UPDATE category SET name'));
    }

    public function testToggleFlipsActiveAndRedirects(): void
    {
        $db = $this->permittedDb();
        $db->categoryRow = ['id' => 5, 'name' => 'Wraps', 'slug' => 'wraps', 'image_path' => null, 'display_order' => 3, 'is_active' => 1];
        $request = $this->post(['_csrf' => $this->csrf], '/admin/categories/5/toggle');

        $response = $this->controller($request, $db)->toggle(['id' => '5']);

        self::assertSame(302, $response->status());
        self::assertTrue($this->wroteContaining($db, 'UPDATE category SET is_active'));
        // Etait visible (1) -> on masque (0).
        $write = null;
        foreach ($db->writes as $w) {
            if (str_contains($w['sql'], 'UPDATE category SET is_active')) {
                $write = $w;
            }
        }
        self::assertNotNull($write);
        self::assertSame(0, $write['params']['active'] ?? null);
        self::assertSame('Categorie masquee.', $this->session->get('_flash'));
    }

    public function testToggleFromMaskedMakesVisible(): void
    {
        $db = $this->permittedDb();
        $db->categoryRow = ['id' => 5, 'name' => 'Wraps', 'slug' => 'wraps', 'image_path' => null, 'display_order' => 3, 'is_active' => 0];
        $request = $this->post(['_csrf' => $this->csrf], '/admin/categories/5/toggle');

        $response = $this->controller($request, $db)->toggle(['id' => '5']);

        self::assertSame(302, $response->status());
        $write = null;
        foreach ($db->writes as $w) {
            if (str_contains($w['sql'], 'UPDATE category SET is_active')) {
                $write = $w;
            }
        }
        self::assertNotNull($write);
        self::assertSame(1, $write['params']['active'] ?? null);
        self::assertSame('Categorie affichee.', $this->session->get('_flash'));
    }

    public function testUpdateNotFoundReturns404(): void
    {
        $db = $this->permittedDb();
        $db->categoryRow = null;
        $request = $this->post(
            ['_csrf' => $this->csrf, 'name' => 'Wraps', 'slug' => 'wraps', 'display_order' => '3'],
            '/admin/categories/999',
        );

        $response = $this->controller($request, $db)->update(['id' => '999']);

        self::assertSame(404, $response->status());
        self::assertStringContainsString('Introuvable', $response->body());
        self::assertFalse($this->wroteContaining($db, 'UPDATE category SET name'));
    }

    public function testToggleNotFoundReturns404(): void
    {
        $db = $this->permittedDb();
        $db->categoryRow = null;
        $request = $this->post(['_csrf' => $this->csrf], '/admin/categories/999/toggle');

        $response = $this->controller($request, $db)->toggle(['id' => '999']);

        self::assertSame(404, $response->status());
        self::assertStringContainsString('Introuvable', $response->body());
        self::assertFalse($this->wroteContaining($db, 'UPDATE category SET is_active'));
    }
}
