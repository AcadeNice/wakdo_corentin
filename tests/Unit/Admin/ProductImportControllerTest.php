<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use App\Auth\Authorizer;
use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\SessionGuard;
use App\Auth\SessionManager;
use App\Catalogue\ProductImportService;
use App\Controllers\ProductController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\Request;
use App\Tests\Support\FakeDatabase;

/**
 * Double DatabaseInterface qui DELEGUE a un FakeDatabase (final, non
 * sous-classable) tout en interceptant les requetes SPECIFIQUES a l'import CSV
 * (resolution par NOM de categorie/ingredient/produit) que FakeDatabase ne
 * modelise pas -- composition plutot qu'heritage, aucune modification du
 * fixture partage tests/Support/FakeDatabase.php.
 */
final class ImportFakeDatabase implements DatabaseInterface
{
    /** @var array<string, mixed>|null */
    public ?array $categoryByName = null;
    /** @var array<string, mixed>|null */
    public ?array $productMatch = null;
    /** @var array<string, mixed>|null */
    public ?array $productCurrentFields = null;
    /** @var array<string, mixed>|null */
    public ?array $ingredientByName = null;

    public function __construct(public FakeDatabase $inner)
    {
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        // Substring insensible au NOM des placeholders (:n vs :name_match/:slug_match) :
        // un branchement qui matche un texte SQL exact plutot qu'un motif stable
        // cesse silencieusement de se declencher au moindre renommage de
        // placeholder cote service, sans qu'aucun test n'echoue pour le dire
        // (releve par relecture adverse, 2026-09-26 -- ce fichier ne prouvait
        // alors plus la resolution de categorie PAR NOM du tout).
        if (str_contains($sql, 'FROM category') && str_contains($sql, 'LOWER(name)')) {
            return $this->categoryByName;
        }
        if (str_contains($sql, 'FROM ingredient WHERE LOWER(name) = LOWER(:name)')) {
            return $this->ingredientByName;
        }
        if (str_contains($sql, 'FROM product WHERE category_id = :cat AND LOWER(name)')) {
            return $this->productMatch;
        }
        if (str_contains($sql, 'description, price_cents, vat_rate, size_cl, is_available, display_order, image_path')) {
            return $this->productCurrentFields;
        }

        return $this->inner->fetch($sql, $params);
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->inner->fetchAll($sql, $params);
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->inner->execute($sql, $params);
    }

    public function transaction(callable $fn): void
    {
        $this->inner->transaction($fn);
    }
}

final class TestProductImportController extends ProductController
{
    public function __construct(
        Request $request,
        Config $config,
        Database $database,
        private readonly SessionManager $testSession,
        private readonly ImportFakeDatabase $fakeDb,
    ) {
        parent::__construct($request, $config, $database);
    }

    protected function sessionManager(): SessionManager
    {
        return $this->testSession;
    }

    protected function sessionGuard(): SessionGuard
    {
        return new SessionGuard($this->testSession, $this->fakeDb->inner, $this->config);
    }

    protected function authorizer(): Authorizer
    {
        return new Authorizer($this->fakeDb->inner);
    }

    protected function db(): DatabaseInterface
    {
        return $this->fakeDb;
    }

    protected function isUploadedFile(string $path): bool
    {
        return is_file($path);
    }
}

/**
 * Import CSV (2 temps : importPreview aucune ecriture, importConfirm rejoue et
 * ecrit tout ou rien). Meme reconciliation reelle que le double FakeDatabase ne
 * peut pas prouver seul (voir ProductImportServiceDbTest, WAKDO_DB_TESTS=1) ;
 * ce fichier couvre les gardes du CONTROLEUR (upload, permission
 * ingredient.manage, jeton de session, PIN sur changement de prix).
 */
final class ProductImportControllerTest extends TestCase
{
    private SessionManager $session;
    private string $csrf = '';
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        putenv('SESSION_LIFETIME_IDLE=14400');
        putenv('SESSION_LIFETIME_ABSOLUTE=36000');
        putenv('STAFF_PIN_MIN_LENGTH=4');
        putenv('STAFF_PIN_MAX_LENGTH=12');
        putenv('ARGON2_MEMORY_COST=1024');
        putenv('ARGON2_TIME_COST=1');
        putenv('ARGON2_THREADS=1');

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
        foreach (['SESSION_LIFETIME_IDLE', 'SESSION_LIFETIME_ABSOLUTE', 'STAFF_PIN_MIN_LENGTH', 'STAFF_PIN_MAX_LENGTH', 'ARGON2_MEMORY_COST', 'ARGON2_TIME_COST', 'ARGON2_THREADS'] as $key) {
            putenv($key);
        }
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function db(): ImportFakeDatabase
    {
        $inner = new FakeDatabase();
        $inner->guardUserRow = ['is_active' => 1];
        $inner->userDisplayRow = ['first_name' => 'Corentin', 'last_name' => 'J', 'role_label' => 'Administrateur'];
        $inner->canResult = true;
        $inner->permissionCodes = ['product.read', 'product.create', 'product.update', 'ingredient.manage'];

        return new ImportFakeDatabase($inner);
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
        return new Request('POST', $path, [], ['content-type' => 'application/x-www-form-urlencoded'], http_build_query($form), '203.0.113.5');
    }

    /**
     * @param array<string, string> $form
     */
    private function postWithFile(array $form, string $path, string $csvContent, string $filename = 'produits.csv'): Request
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wakdo_csv_test_');
        file_put_contents($tmp, $csvContent);
        $this->tempFiles[] = $tmp;

        return new Request(
            'POST',
            $path,
            [],
            ['content-type' => 'multipart/form-data; boundary=----wakdoTestBoundary'],
            '',
            '203.0.113.5',
            ['csv_file' => ['name' => $filename, 'type' => 'text/csv', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($csvContent)]],
            $form,
        );
    }

    private function controller(Request $request, ImportFakeDatabase $db): TestProductImportController
    {
        return new TestProductImportController($request, new Config(), new Database(new Config()), $this->session, $db);
    }

    private function header(): string
    {
        return implode(';', ProductImportService::COLUMNS);
    }

    public function testImportFormRequiresProductCreate(): void
    {
        $db = $this->db();
        $db->inner->canResult = false;

        $response = $this->controller($this->get('/admin/products/import'), $db)->importForm();

        self::assertSame(403, $response->status());
    }

    public function testImportFormShowsUploadPageWithTemplateLink(): void
    {
        $response = $this->controller($this->get('/admin/products/import'), $this->db())->importForm();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Télécharger le modèle CSV', $response->body());
        self::assertStringContainsString('/admin/products/import/template', $response->body());
    }

    public function testImportTemplateReturnsCsvAttachment(): void
    {
        $response = $this->controller($this->get('/admin/products/import/template'), $this->db())->importTemplate();

        self::assertSame(200, $response->status());
        self::assertSame('text/csv; charset=utf-8', $response->header('Content-Type'));
        self::assertStringContainsString('attachment', (string) $response->header('Content-Disposition'));
        self::assertStringContainsString($this->header(), $response->body());
    }

    public function testImportPreviewWithoutFileShowsError(): void
    {
        $response = $this->controller($this->post(['_csrf' => $this->csrf], '/admin/products/import/preview'), $this->db())->importPreview();

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Choisissez un fichier CSV', $response->body());
    }

    public function testImportPreviewRejectsInvalidCsrf(): void
    {
        $csv = $this->header() . "\r\n3;Cheeseburger;;6,90;10;;oui;;;;;\r\n";
        $response = $this->controller($this->postWithFile(['_csrf' => 'bad'], '/admin/products/import/preview', $csv), $this->db())->importPreview();

        self::assertSame(403, $response->status());
    }

    public function testImportPreviewRejectsWrongExtension(): void
    {
        $csv = $this->header() . "\r\n3;Cheeseburger;;6,90;10;;oui;;;;;\r\n";
        $response = $this->controller($this->postWithFile(['_csrf' => $this->csrf], '/admin/products/import/preview', $csv, 'produits.txt'), $this->db())->importPreview();

        self::assertSame(422, $response->status());
        self::assertStringContainsString('.csv', $response->body());
    }

    public function testImportPreviewOfValidFileShowsCreateAndNoWrite(): void
    {
        $db = $this->db();
        $db->inner->categoryRow = ['id' => 3, 'name' => 'Burgers'];
        $csv = $this->header() . "\r\n3;Cheeseburger;;6,90;10;;oui;;;;;\r\n";

        $response = $this->controller($this->postWithFile(['_csrf' => $this->csrf], '/admin/products/import/preview', $csv), $db)->importPreview();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Cheeseburger', $response->body());
        self::assertStringContainsString('Confirmer l\'import', $response->body());
        self::assertSame([], $db->inner->writes, 'Un apercu ne doit ecrire nulle part.');
    }

    public function testImportPreviewResolvesCategoryByName(): void
    {
        // Categorie donnee par NOM dans le CSV (pas par id numerique) : preuve
        // que la resolution "LOWER(name) = LOWER(...)" fonctionne reellement
        // (et pas seulement la resolution par id, deja couverte ailleurs).
        $db = $this->db();
        $db->categoryByName = ['id' => 3, 'name' => 'Burgers'];
        $csv = $this->header() . "\r\nBurgers;Cheeseburger;;6,90;10;;oui;;;;;\r\n";

        $response = $this->controller($this->postWithFile(['_csrf' => $this->csrf], '/admin/products/import/preview', $csv), $db)->importPreview();

        self::assertSame(200, $response->status());
        self::assertStringNotContainsString('Catégorie inconnue', $response->body());
        self::assertStringContainsString('Cheeseburger', $response->body());
        self::assertStringContainsString('Confirmer l\'import', $response->body());
    }

    public function testImportPreviewBlocksNewIngredientWithoutIngredientManage(): void
    {
        $db = $this->db();
        $db->inner->categoryRow = ['id' => 3, 'name' => 'Burgers'];
        $db->inner->permissionCodes = ['product.create']; // pas ingredient.manage
        $db->inner->grantedCodes = ['product.create'];
        $csv = $this->header() . "\r\n3;Cheeseburger;;6,90;10;;oui;Pain;unite;1;non;non\r\n";

        $response = $this->controller($this->postWithFile(['_csrf' => $this->csrf], '/admin/products/import/preview', $csv), $db)->importPreview();

        self::assertSame(200, $response->status());
        // F40 : message en langage clair, jamais le code brut de permission.
        self::assertStringContainsString('droit de gérer les ingrédients', $response->body());
        self::assertStringNotContainsString('Confirmer l\'import', $response->body());
    }

    // --- Relecture adverse (2026-09-26) : contournement d'autorisation. Un role
    //     qui ne detient que product.create/product.read ne doit PLUS pouvoir
    //     reecrire un produit existant ni vider sa recette via l'import. ---

    public function testImportPreviewBlocksUpdateOfExistingProductWithoutProductUpdatePermission(): void
    {
        $db = $this->db();
        $db->inner->categoryRow = ['id' => 3, 'name' => 'Burgers'];
        $db->inner->permissionCodes = ['product.create', 'product.read'];
        $db->inner->grantedCodes = ['product.create', 'product.read']; // PAS product.update, PAS ingredient.manage
        $db->productMatch = ['id' => 77, 'price_cents' => 690]; // meme prix : isole de la garde PIN
        $db->productCurrentFields = ['description' => 'Ancienne description', 'price_cents' => 690, 'vat_rate' => 100, 'size_cl' => null, 'is_available' => 1, 'display_order' => 3, 'image_path' => null];
        // Nouvelle description ET aucune ligne ingredient : reecrirait la
        // description ET viderait la recette du produit 77 -- exactement le
        // scenario rejoue par la relecture adverse ("Le 280"). Le blocage doit
        // apparaitre DES L'APERCU (guardImportAuthorizations tourne aussi dans
        // importPreview()) : aucun formulaire de confirmation n'est meme
        // propose a un role qui n'a que product.create/product.read.
        $csv = $this->header() . "\r\n3;Le 280;Nouvelle description;6,90;10;;oui;;;;;\r\n";

        $response = $this->controller($this->postWithFile(['_csrf' => $this->csrf], '/admin/products/import/preview', $csv), $db)->importPreview();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('droit de modifier les produits', $response->body());
        self::assertStringNotContainsString('Confirmer l\'import', $response->body());
        self::assertFalse($db->inner->wrote('UPDATE product SET'));
        self::assertFalse($db->inner->wrote('DELETE FROM product_ingredient'));
    }

    public function testImportPreviewBlocksRecipeReplacementOfExistingProductWithoutIngredientManage(): void
    {
        $db = $this->db();
        $db->inner->categoryRow = ['id' => 3, 'name' => 'Burgers'];
        $db->inner->permissionCodes = ['product.create', 'product.update'];
        $db->inner->grantedCodes = ['product.create', 'product.update']; // PAS ingredient.manage
        $db->productMatch = ['id' => 77, 'price_cents' => 690];
        $db->productCurrentFields = ['description' => null, 'price_cents' => 690, 'vat_rate' => 100, 'size_cl' => null, 'is_available' => 1, 'display_order' => 3, 'image_path' => null];
        // Rien ne change au niveau produit (classe "inchangé") : seule la
        // recette serait remplacée -- product.update seul ne suffit pas.
        $csv = $this->header() . "\r\n3;Le 280;;6,90;10;;oui;;;;;\r\n";

        $response = $this->controller($this->postWithFile(['_csrf' => $this->csrf], '/admin/products/import/preview', $csv), $db)->importPreview();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('droit de gérer les ingrédients', $response->body());
        self::assertStringNotContainsString('Confirmer l\'import', $response->body());
        self::assertFalse($db->inner->wrote('DELETE FROM product_ingredient'));
    }

    /**
     * Defense en profondeur : meme si un apercu ANTERIEUR (sous une session ou
     * des permissions differentes) avait laisse un jeton valide en session,
     * `importConfirm()` rejoue integralement `guardImportAuthorizations()` sur
     * SA PROPRE analyse et refuse quand meme -- il ne fait pas confiance a
     * l'apercu passe. Le jeton/CSV en attente est semé DIRECTEMENT en session
     * (plutot que via un premier appel a importPreview()) pour isoler ce cas :
     * un attaquant qui obtiendrait un jeton par un autre moyen ne doit pas
     * pouvoir confirmer avec des permissions insuffisantes.
     */
    public function testImportConfirmIndependentlyBlocksUpdateWithoutPermission(): void
    {
        $db = $this->db();
        $db->inner->categoryRow = ['id' => 3, 'name' => 'Burgers'];
        $db->inner->grantedCodes = ['product.create', 'product.read']; // PAS product.update, PAS ingredient.manage
        $db->productMatch = ['id' => 77, 'price_cents' => 690];
        $db->productCurrentFields = ['description' => 'Ancienne description', 'price_cents' => 690, 'vat_rate' => 100, 'size_cl' => null, 'is_available' => 1, 'display_order' => 3, 'image_path' => null];
        $csv = $this->header() . "\r\n3;Le 280;Nouvelle description;6,90;10;;oui;;;;;\r\n";

        $token = bin2hex(random_bytes(16));
        $this->session->set('_product_import_pending', ['token' => $token, 'csv' => $csv, 'created_at' => time()]);

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'import_token' => $token], '/admin/products/import/confirm'), $db)->importConfirm();

        self::assertSame(422, $response->status());
        self::assertStringContainsString('droit de modifier les produits', $response->body());
        self::assertFalse($db->inner->wrote('UPDATE product SET'));
        self::assertFalse($db->inner->wrote('DELETE FROM product_ingredient'));
    }

    public function testImportConfirmUpdatesExistingProductAndReplacesRecipeWithFullPermissions(): void
    {
        $db = $this->db(); // canResult=true : toutes permissions accordees
        $db->inner->categoryRow = ['id' => 3, 'name' => 'Burgers'];
        $db->productMatch = ['id' => 77, 'price_cents' => 690];
        $db->productCurrentFields = ['description' => 'Ancienne description', 'price_cents' => 690, 'vat_rate' => 100, 'size_cl' => null, 'is_available' => 1, 'display_order' => 3, 'image_path' => null];
        $csv = $this->header() . "\r\n3;Le 280;Nouvelle description;6,90;10;;oui;;;;;\r\n";

        $token = $this->previewThenConfirmToken($db, $csv);
        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'import_token' => $token], '/admin/products/import/confirm'), $db)->importConfirm();

        self::assertSame(302, $response->status());
        self::assertTrue($db->inner->wrote('UPDATE product SET'));
        self::assertTrue($db->inner->wrote('DELETE FROM product_ingredient'));
    }

    public function testImportConfirmWithoutValidTokenRedirectsToUploadForm(): void
    {
        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'import_token' => 'inconnu'], '/admin/products/import/confirm'), $this->db())->importConfirm();

        self::assertSame(302, $response->status());
        self::assertSame('/admin/products/import', $response->header('Location'));
    }

    /**
     * Rejoue le flux complet preview() -> confirm() via LA MEME session (le
     * jeton + CSV pending vivent en session, jamais dans un champ cache).
     */
    private function previewThenConfirmToken(ImportFakeDatabase $db, string $csv): string
    {
        $preview = $this->controller($this->postWithFile(['_csrf' => $this->csrf], '/admin/products/import/preview', $csv), $db)->importPreview();
        self::assertSame(200, $preview->status());
        preg_match('/name="import_token" value="([a-f0-9]+)"/', $preview->body(), $matches);
        self::assertNotEmpty($matches, 'jeton d\'import introuvable dans l\'aperçu');

        return $matches[1];
    }

    public function testImportConfirmAppliesAndRedirectsWithSummary(): void
    {
        $db = $this->db();
        $db->categoryByName = null;
        $db->inner->categoryRow = ['id' => 3, 'name' => 'Burgers'];
        $csv = $this->header() . "\r\n3;Cheeseburger;;6,90;10;;oui;;;;;\r\n";

        $token = $this->previewThenConfirmToken($db, $csv);

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'import_token' => $token], '/admin/products/import/confirm'), $db)->importConfirm();

        self::assertSame(302, $response->status());
        self::assertSame('/admin/products', $response->header('Location'));
        self::assertTrue($db->inner->wrote('INSERT INTO product'));
        self::assertContains('product.import', $db->inner->auditActions());
        self::assertStringContainsString('produit(s) créé(s)', (string) $this->session->get('_flash'));
    }

    public function testImportConfirmRequiresPinWhenPriceChanges(): void
    {
        $db = $this->db();
        $db->inner->categoryRow = ['id' => 3, 'name' => 'Burgers'];
        $db->productMatch = ['id' => 42, 'price_cents' => 590]; // produit existant, prix different du CSV
        $db->productCurrentFields = ['description' => null, 'price_cents' => 590, 'vat_rate' => 100, 'size_cl' => null, 'is_available' => 1, 'display_order' => 3, 'image_path' => null];
        $csv = $this->header() . "\r\n3;Cheeseburger;;6,90;10;;oui;;;;;\r\n"; // 690 != 590

        $token = $this->previewThenConfirmToken($db, $csv);

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'import_token' => $token], '/admin/products/import/confirm'), $db)->importConfirm();

        self::assertSame(422, $response->status());
        self::assertStringContainsString('PIN', $response->body());
        self::assertFalse($db->inner->wrote('UPDATE product SET'));
    }

    public function testImportConfirmWithValidPinAppliesPriceChange(): void
    {
        $db = $this->db();
        $db->inner->categoryRow = ['id' => 3, 'name' => 'Burgers'];
        $db->productMatch = ['id' => 42, 'price_cents' => 590];
        $db->productCurrentFields = ['description' => null, 'price_cents' => 590, 'vat_rate' => 100, 'size_cl' => null, 'is_available' => 1, 'display_order' => 3, 'image_path' => null];
        $db->inner->actingUserRow = ['id' => 9, 'role_id' => 4, 'pin_hash' => (new PasswordHasher(new Config()))->hash('4729')];
        $csv = $this->header() . "\r\n3;Cheeseburger;;6,90;10;;oui;;;;;\r\n";

        $token = $this->previewThenConfirmToken($db, $csv);

        $response = $this->controller($this->post([
            '_csrf' => $this->csrf, 'import_token' => $token,
            'pin_email' => 'staff@wakdo.local', 'pin' => '4729',
        ], '/admin/products/import/confirm'), $db)->importConfirm();

        self::assertSame(302, $response->status());
        self::assertTrue($db->inner->wrote('UPDATE product SET'));
        self::assertContains('product.import', $db->inner->auditActions());
    }
}
