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
use App\Core\ImageUploader;
use App\Core\Request;
use App\Tests\Support\FakeDatabase;
use App\Tests\Support\TestableImageUploader;

/**
 * Sous-classe de test : injecte session test + FakeDatabase dans la garde,
 * l'autorisation, l'annuaire et le repository, sans base reelle. imageUploader()
 * pointe vers un dossier temporaire (uploadBaseDir) au lieu de src/public/uploads
 * (meme double TestableImageUploader que ProductControllerTest/ImageUploaderTest).
 */
final class TestCategoryController extends CategoryController
{
    public string $uploadBaseDir = '';

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

    protected function imageUploader(): ImageUploader
    {
        return new TestableImageUploader($this->config, $this->uploadBaseDir);
    }
}

final class CategoryControllerTest extends TestCase
{
    /** PNG 1x1 valide et complet (signature + IHDR + IDAT + IEND), 68 octets. */
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    /** @var list<string> */
    private array $touchedKeys = [];

    private SessionManager $session;
    private string $csrf = '';
    private string $uploadBaseDir = '';

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

        $this->uploadBaseDir = sys_get_temp_dir() . '/wakdo_uploads_test_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ($this->touchedKeys as $key) {
            putenv($key);
        }
        $this->touchedKeys = [];
        $this->removeDirectory($this->uploadBaseDir);
    }

    private function setEnv(string $key, string $value): void
    {
        $this->touchedKeys[] = $key;
        putenv($key . '=' . $value);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items === false ? [] : $items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function writeTemp(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wakdo_src_');
        self::assertNotFalse($path);
        file_put_contents($path, $bytes);

        return $path;
    }

    /**
     * @return array<string, mixed> une entree $_FILES pour une image valide reelle
     */
    private function uploadedImage(): array
    {
        $tmp = $this->writeTemp(base64_decode(self::PNG_1X1));

        return [
            'name'     => 'categorie.png',
            'type'     => 'image/png',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => (int) filesize($tmp),
        ];
    }

    private function plantUploadedImage(string $subdir): string
    {
        $name = bin2hex(random_bytes(16)) . '.png';
        $directory = $this->uploadBaseDir . '/' . $subdir;
        mkdir($directory, 0755, true);
        file_put_contents($directory . '/' . $name, base64_decode(self::PNG_1X1));

        return 'uploads/' . $subdir . '/' . $name;
    }

    /**
     * multipart/form-data, PAS urlencoded : le formulaire categorie porte
     * enctype="multipart/form-data" (upload d'image), meme sans fichier choisi.
     * Voir le docblock de Request::formBody() -- meme bug/correctif que le
     * formulaire produit.
     *
     * @param array<string, string> $form
     * @param array<string, mixed> $file une entree $_FILES sous la cle image_file
     */
    private function postWithFile(array $form, string $path, array $file): Request
    {
        return new Request(
            'POST',
            $path,
            [],
            ['content-type' => 'multipart/form-data; boundary=----wakdoTestBoundary'],
            '',
            '203.0.113.5',
            ['image_file' => $file],
            $form,
        );
    }

    /**
     * multipart/form-data SANS fichier choisi (cas le plus courant).
     *
     * @param array<string, string> $form
     */
    private function postMultipartNoFile(array $form, string $path): Request
    {
        return new Request(
            'POST',
            $path,
            [],
            ['content-type' => 'multipart/form-data; boundary=----wakdoTestBoundary'],
            '',
            '203.0.113.5',
            [],
            $form,
        );
    }

    /** Corps rejete par post_max_size : $_POST et $_FILES tous deux vides. */
    private function postOversized(string $path): Request
    {
        return new Request(
            'POST',
            $path,
            [],
            [
                'content-type'   => 'multipart/form-data; boundary=----wakdoTestBoundary',
                'content-length' => '9000000',
            ],
            '',
            '203.0.113.5',
            [],
            [],
        );
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
        $controller = new TestCategoryController($request, new Config(), new Database(new Config()), $this->session, $db);
        $controller->uploadBaseDir = $this->uploadBaseDir;

        return $controller;
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
        self::assertStringContainsString('Accès refusé', $response->body());
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
        self::assertStringContainsString('Nouvelle catégorie', $body);
        self::assertStringContainsString('Burgers', $body);
        self::assertStringContainsString('Visible', $body);   // is_active = 1
        self::assertStringContainsString('Masquée', $body);   // is_active = 0
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
        self::assertSame('Catégorie créée.', $this->session->get('_flash'));
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
        self::assertStringContainsString('Le libellé est requis', $response->body());
        self::assertStringContainsString('Référence requise', $response->body());
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
        self::assertStringContainsString('Ce libellé existe déjà', $response->body());
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

    public function testStoreTranslatesUniqueViolationTo409(): void
    {
        // Fenetre de concurrence : la base leve une violation 23000 a l'insertion.
        // Conflit remonte par la base -> 409 (re-affiche le formulaire), pas un 500.
        $db = $this->permittedDb();
        $db->failOnExecute = new \PDOException('duplicate', 23000);
        $request = $this->post(
            ['_csrf' => $this->csrf, 'name' => 'Desserts', 'slug' => 'desserts', 'display_order' => '7'],
            '/admin/categories',
        );

        $response = $this->controller($request, $db)->store();

        self::assertSame(409, $response->status());
        self::assertStringContainsString('existe déjà', $response->body());
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
        self::assertStringContainsString('Cette référence existe déjà', $response->body());
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

    /**
     * Bug corrige (2026-09-26) : formulaire categorie TOUJOURS en
     * multipart/form-data (upload d'image), meme sans fichier choisi. Avant le
     * correctif, Request::formBody() ne reconnaissait pas ce content-type et
     * renvoyait [] : _csrf etait donc absent et la creation echouait
     * systematiquement en 403 "Requête invalide.".
     */
    public function testStoreAcceptsRealMultipartFormWithoutAnyImage(): void
    {
        $db = $this->permittedDb();
        $request = $this->postMultipartNoFile(
            ['_csrf' => $this->csrf, 'name' => 'Desserts', 'slug' => 'desserts', 'display_order' => '7'],
            '/admin/categories',
        );

        $response = $this->controller($request, $db)->store();

        self::assertSame(302, $response->status());
        self::assertTrue($this->wroteContaining($db, 'INSERT INTO category'));
    }

    /** Corps rejete par post_max_size : message clair plutot que le 403 generique. */
    public function testStoreShowsFriendlyMessageWhenBodyExceedsPostMaxSize(): void
    {
        $db = $this->permittedDb();

        $response = $this->controller($this->postOversized('/admin/categories'), $db)->store();

        self::assertSame(422, $response->status());
        self::assertFalse($this->wroteContaining($db, 'INSERT INTO category'));
        self::assertStringContainsString('trop volumineux', $response->body());
        self::assertStringNotContainsString('Requête invalide', $response->body());
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
        self::assertSame('Catégorie masquée.', $this->session->get('_flash'));
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
        self::assertSame('Catégorie affichée.', $this->session->get('_flash'));
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

    /* --- Image de categorie (ImageUploader) ---------------------------------- */

    public function testStoreWithValidImageStoresFileAndPersistsPath(): void
    {
        $db = $this->permittedDb();
        $form = ['_csrf' => $this->csrf, 'name' => 'Desserts', 'slug' => 'desserts', 'display_order' => '7'];

        $response = $this->controller($this->postWithFile($form, '/admin/categories', $this->uploadedImage()), $db)->store();

        self::assertSame(302, $response->status());
        $insert = null;
        foreach ($db->writes as $write) {
            if (str_contains($write['sql'], 'INSERT INTO category')) {
                $insert = $write;
            }
        }
        self::assertNotNull($insert);
        $relative = (string) ($insert['params']['image'] ?? '');
        self::assertMatchesRegularExpression('#^uploads/categories/[a-f0-9]{32}\.png$#', $relative);
        self::assertFileExists($this->uploadBaseDir . '/' . substr($relative, strlen('uploads/')));
    }

    public function testStoreWithInvalidImageReturns422AndWritesNoFile(): void
    {
        $db = $this->permittedDb();
        $form = ['_csrf' => $this->csrf, 'name' => 'Desserts', 'slug' => 'desserts', 'display_order' => '7'];
        $badImage = [
            'name' => 'photo.png', 'type' => 'image/png',
            'tmp_name' => $this->writeTemp('ceci n est pas une image'),
            'error' => UPLOAD_ERR_OK, 'size' => 24,
        ];

        $response = $this->controller($this->postWithFile($form, '/admin/categories', $badImage), $db)->store();

        self::assertSame(422, $response->status());
        self::assertFalse($this->wroteContaining($db, 'INSERT INTO category'));
        self::assertStringContainsString('Format d&#039;image non accepté', $response->body());
        self::assertDirectoryDoesNotExist($this->uploadBaseDir . '/categories');
    }

    public function testStoreWithValidImageButAnotherInvalidFieldNeverWritesTheFile(): void
    {
        $db = $this->permittedDb();
        // slug invalide (majuscule+espace) : l'image, elle, est valide.
        $form = ['_csrf' => $this->csrf, 'name' => 'Desserts', 'slug' => 'INVALID SLUG', 'display_order' => '7'];

        $response = $this->controller($this->postWithFile($form, '/admin/categories', $this->uploadedImage()), $db)->store();

        self::assertSame(422, $response->status());
        self::assertFalse($this->wroteContaining($db, 'INSERT INTO category'));
        self::assertDirectoryDoesNotExist($this->uploadBaseDir . '/categories');
    }

    public function testUpdateReplacesImageAndRemovesTheOldOneOnlyAfterSuccess(): void
    {
        $db = $this->permittedDb();
        $oldRelative = $this->plantUploadedImage('categories');
        $db->categoryRow = ['id' => 5, 'name' => 'Wraps', 'slug' => 'wraps', 'image_path' => $oldRelative, 'display_order' => 3, 'is_active' => 1];
        $form = ['_csrf' => $this->csrf, 'name' => 'Wraps & Co', 'slug' => 'wraps', 'display_order' => '3'];

        $response = $this->controller($this->postWithFile($form, '/admin/categories/5', $this->uploadedImage()), $db)->update(['id' => '5']);

        self::assertSame(302, $response->status());
        self::assertFileDoesNotExist($this->uploadBaseDir . '/' . substr($oldRelative, strlen('uploads/')));
        $update = null;
        foreach ($db->writes as $write) {
            if (str_contains($write['sql'], 'UPDATE category SET name')) {
                $update = $write;
            }
        }
        self::assertNotNull($update);
        $newRelative = (string) ($update['params']['image'] ?? '');
        self::assertMatchesRegularExpression('#^uploads/categories/[a-f0-9]{32}\.png$#', $newRelative);
        self::assertFileExists($this->uploadBaseDir . '/' . substr($newRelative, strlen('uploads/')));
    }

    public function testUpdateKeepsTheOldImageWhenTheDatabaseWriteFails(): void
    {
        $db = $this->permittedDb();
        $oldRelative = $this->plantUploadedImage('categories');
        $db->categoryRow = ['id' => 5, 'name' => 'Wraps', 'slug' => 'wraps', 'image_path' => $oldRelative, 'display_order' => 3, 'is_active' => 1];
        $db->failOnExecute = new \PDOException('panne disque simulee');
        $form = ['_csrf' => $this->csrf, 'name' => 'Wraps & Co', 'slug' => 'wraps', 'display_order' => '3'];

        // PDOException hors 23000 : onWriteConflict() la repropage (cf. son
        // docblock), elle traverse donc le controleur sans etre avalee.
        $this->expectException(\PDOException::class);
        try {
            $this->controller($this->postWithFile($form, '/admin/categories/5', $this->uploadedImage()), $db)->update(['id' => '5']);
        } finally {
            self::assertFileExists($this->uploadBaseDir . '/' . substr($oldRelative, strlen('uploads/')));
        }
    }

    /* --- Rangement des categories (move) -------------------------------------- */

    public function testMoveRejectsInvalidCsrf(): void
    {
        $db = $this->permittedDb();

        $response = $this->controller($this->post(['_csrf' => 'wrong', 'direction' => 'up'], '/admin/categories/20/move'), $db)->move(['id' => '20']);

        self::assertSame(403, $response->status());
        self::assertFalse($this->wroteContaining($db, 'UPDATE category SET display_order'));
    }

    public function testMoveRequiresCategoryManagePermission(): void
    {
        $db = $this->permittedDb();
        $db->canResult = false;

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'direction' => 'up'], '/admin/categories/20/move'), $db)->move(['id' => '20']);

        self::assertSame(403, $response->status());
        self::assertFalse($this->wroteContaining($db, 'UPDATE category SET display_order'));
    }

    public function testMoveUpRedirectsAndSetsFlashOnSuccess(): void
    {
        $db = $this->permittedDb();
        $db->categoriesRows = [['id' => 10], ['id' => 20]];

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'direction' => 'up'], '/admin/categories/20/move'), $db)->move(['id' => '20']);

        self::assertSame(302, $response->status());
        self::assertSame('/admin/categories', $response->header('Location'));
        self::assertSame('Ordre des catégories mis à jour.', $this->session->get('_flash'));
        self::assertTrue($this->wroteContaining($db, 'UPDATE category SET display_order'));
    }

    public function testMoveAlreadyAtTopIsANoOpAndDoesNotSetFlash(): void
    {
        $db = $this->permittedDb();
        $db->categoriesRows = [['id' => 10], ['id' => 20]];

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'direction' => 'up'], '/admin/categories/10/move'), $db)->move(['id' => '10']);

        self::assertSame(302, $response->status());
        self::assertNull($this->session->get('_flash'));
        self::assertFalse($this->wroteContaining($db, 'UPDATE category SET display_order'));
    }

    public function testMoveWithInvalidDirectionRedirectsWithoutWriting(): void
    {
        $db = $this->permittedDb();
        $db->categoriesRows = [['id' => 10], ['id' => 20]];

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'direction' => 'sideways'], '/admin/categories/20/move'), $db)->move(['id' => '20']);

        self::assertSame(302, $response->status());
        self::assertSame('/admin/categories', $response->header('Location'));
        self::assertNull($this->session->get('_flash'));
        self::assertFalse($this->wroteContaining($db, 'UPDATE category SET display_order'));
    }
}
