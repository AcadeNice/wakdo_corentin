<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use App\Auth\Csrf;
use App\Auth\SessionManager;
use App\Controllers\ProductController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\ImageUploader;
use App\Core\Request;
use App\Tests\Support\FakeDatabase;
use App\Tests\Support\TestableImageUploader;

/**
 * Sous-classe de test : grace au seam db(), une seule surcharge DB suffit ;
 * sessionManager() injecte la session test. imageUploader() pointe vers un
 * dossier temporaire (uploadBaseDir) au lieu de src/public/uploads, via le meme
 * double TestableImageUploader que ImageUploaderTest (is_uploaded_file()/
 * move_uploaded_file() n'ont de sens qu'apres un vrai envoi HTTP).
 */
final class TestProductController extends ProductController
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

    protected function db(): DatabaseInterface
    {
        return $this->fakeDb;
    }

    protected function imageUploader(): ImageUploader
    {
        return new TestableImageUploader($this->config, $this->uploadBaseDir);
    }
}

final class ProductControllerTest extends TestCase
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
            'name'     => 'produit.png',
            'type'     => 'image/png',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => (int) filesize($tmp),
        ];
    }

    /**
     * Depose une "ancienne" image deja stockee (comme le ferait un envoi
     * precedent) directement sous uploadBaseDir, et renvoie son chemin relatif
     * tel qu'il serait lu depuis product.image_path.
     */
    private function plantUploadedImage(string $subdir): string
    {
        $name = bin2hex(random_bytes(16)) . '.png';
        $directory = $this->uploadBaseDir . '/' . $subdir;
        mkdir($directory, 0755, true);
        file_put_contents($directory . '/' . $name, base64_decode(self::PNG_1X1));

        return 'uploads/' . $subdir . '/' . $name;
    }

    /**
     * @param array<string, string> $form
     * @param array<string, mixed> $file une entree $_FILES sous la cle image_file
     */
    /**
     * multipart/form-data, PAS urlencoded : c'est le CONTENU REEL envoye par le
     * formulaire produit (enctype="multipart/form-data", pour l'upload d'image),
     * y compris quand aucun fichier n'est choisi. $post simule $_POST tel que PHP
     * l'a nativement parse (voir le docblock de Request::formBody()) ; le corps
     * brut n'a pas besoin d'etre un vrai multipart encode ici, seul $post compte.
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
     * multipart/form-data SANS fichier choisi : le cas le plus courant en
     * pratique (ex-bug #3, "Requête invalide" systematique sur la creation de
     * produit, image ou non -- voir Request::formBody()).
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

    /**
     * Simule un corps rejete par la limite post_max_size de PHP : $_POST et
     * $_FILES sont TOUS DEUX vides (comme le fait reellement PHP dans ce cas),
     * avec un Content-Length annonce non nul.
     */
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
        $db->permissionCodes = ['product.read', 'product.create', 'product.update', 'product.delete'];
        $db->categoryRow = ['id' => 3, 'name' => 'Burgers'];   // categoryExists -> true
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
        return new Request('POST', $path, [], ['content-type' => 'application/x-www-form-urlencoded'], http_build_query($form), '203.0.113.5');
    }

    private function controller(Request $request, FakeDatabase $db): TestProductController
    {
        $controller = new TestProductController($request, new Config(), new Database(new Config()), $this->session, $db);
        $controller->uploadBaseDir = $this->uploadBaseDir;

        return $controller;
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function validForm(array $overrides = []): array
    {
        return array_merge([
            '_csrf' => $this->csrf,
            'category_id' => '3',
            'name' => 'Big Mac',
            'price_cents' => '5,90',
            'vat_rate' => '100',
            'display_order' => '1',
            'is_available' => '1',
        ], $overrides);
    }

    private function actingPin(FakeDatabase $db): void
    {
        // Equipier dont le PIN '4729' est valide (modele identifiant + PIN).
        $db->actingUserRow = ['id' => 9, 'role_id' => 4, 'pin_hash' => (new \App\Auth\PasswordHasher(new Config()))->hash('4729')];
    }

    public function testIndexRequiresProductRead(): void
    {
        $db = $this->permittedDb();
        $db->canResult = false;

        self::assertSame(403, $this->controller($this->get('/admin/products'), $db)->index()->status());
    }

    public function testIndexListsProducts(): void
    {
        $db = $this->permittedDb();
        $db->productsRows = [
            ['id' => 1, 'category_id' => 3, 'name' => 'Big Mac', 'price_cents' => 590, 'vat_rate' => 100, 'is_available' => 1, 'category_name' => 'Burgers'],
        ];

        $response = $this->controller($this->get('/admin/products'), $db)->index();
        self::assertSame(200, $response->status());
        self::assertStringContainsString('Big Mac', $response->body());
        self::assertStringContainsString('Nouveau produit', $response->body());
    }

    public function testStoreCreatesWithoutPin(): void
    {
        $db = $this->permittedDb();
        $response = $this->controller($this->post($this->validForm(), '/admin/products'), $db)->store();

        self::assertSame(302, $response->status());
        self::assertTrue($db->wrote('INSERT INTO product'));
        self::assertFalse($db->wrote('INSERT INTO audit_log')); // create = pas d'action sensible
        self::assertSame('Produit créé.', $this->session->get('_flash'));
    }

    // --- Champs de variante (F9-3) : size_cl, base_product_id, maxi_variant_product_id ---

    public function testStorePersistsVariantFields(): void
    {
        // Une variante de taille : base_product_id pointe une base, size_cl=50.
        $db = $this->permittedDb();
        $db->productIsBase = true;  // la base designee EST une base (eligible)
        $db->productRow = ['id' => 7, 'name' => 'Coca Cola']; // productExists -> true

        $form = $this->validForm(['name' => 'Coca Cola 50cl', 'size_cl' => '50', 'base_product_id' => '7', 'maxi_variant_product_id' => '8']);
        $response = $this->controller($this->post($form, '/admin/products'), $db)->store();

        self::assertSame(302, $response->status());
        $insert = $this->findWrite($db, 'INSERT INTO product');
        self::assertNotNull($insert);
        self::assertSame(50, $insert['params']['size'] ?? null);
        self::assertSame(7, $insert['params']['base'] ?? null);
        self::assertSame(8, $insert['params']['maxi'] ?? null);
    }

    public function testStoreEmptyVariantFieldsBindNull(): void
    {
        // Produit ordinaire : aucun champ de variante -> NULL en base (pas 0).
        $db = $this->permittedDb();
        $response = $this->controller($this->post($this->validForm(), '/admin/products'), $db)->store();

        self::assertSame(302, $response->status());
        $insert = $this->findWrite($db, 'INSERT INTO product');
        self::assertNotNull($insert);
        // Cles bien liees (allowlist bind()) ET valeur NULL. Pas de `?? 'x'` ici :
        // `null ?? 'x'` vaudrait 'x' et ferait echouer l'assertion sur un null legitime.
        self::assertArrayHasKey('size', $insert['params']);
        self::assertNull($insert['params']['size']);
        self::assertArrayHasKey('base', $insert['params']);
        self::assertNull($insert['params']['base']);
        self::assertArrayHasKey('maxi', $insert['params']);
        self::assertNull($insert['params']['maxi']);
    }

    public function testUpdatePersistsVariantFields(): void
    {
        // Edition sans changement prix/TVA -> pas de PIN ; les colonnes de variante
        // sont bien dans l'UPDATE.
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'category_id' => 3, 'name' => 'Coca Cola', 'description' => null, 'price_cents' => 190, 'size_cl' => 30, 'base_product_id' => null, 'maxi_variant_product_id' => null, 'vat_rate' => 100, 'image_path' => null, 'is_available' => 1, 'display_order' => 1];
        $db->productIsBase = true;

        $form = $this->validForm(['name' => 'Coca Cola', 'price_cents' => '1,90', 'base_product_id' => '7']);
        $response = $this->controller($this->post($form, '/admin/products/5'), $db)->update(['id' => '5']);

        self::assertSame(302, $response->status());
        $update = $this->findWrite($db, 'UPDATE product SET');
        self::assertNotNull($update);
        self::assertSame(7, $update['params']['base'] ?? null);
    }

    public function testStoreRejectsBaseReferencingAVariant(): void
    {
        // Anti-chaine de variantes (F9-3) : la base designee est elle-meme une
        // variante (productIsBase=false) -> 422, aucun ecrit.
        $db = $this->permittedDb();
        $db->productRow = ['id' => 99, 'name' => 'Coca 50cl']; // existe
        $db->productIsBase = false;                            // mais c'est une variante

        $form = $this->validForm(['base_product_id' => '99']);
        $response = $this->controller($this->post($form, '/admin/products'), $db)->store();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO product'));
        self::assertStringContainsString('produit de base', $response->body());
    }

    public function testUpdateRejectsSelfAsBase(): void
    {
        // Anti auto-reference (F9-3) : base_product_id = soi-meme -> 422.
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'category_id' => 3, 'name' => 'X', 'description' => null, 'price_cents' => 190, 'vat_rate' => 100, 'image_path' => null, 'is_available' => 1, 'display_order' => 1];

        $form = $this->validForm(['name' => 'X', 'price_cents' => '1,90', 'base_product_id' => '5']);
        $response = $this->controller($this->post($form, '/admin/products/5'), $db)->update(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('UPDATE product SET'));
        self::assertStringContainsString('sa propre base', $response->body());
    }

    public function testUpdateRejectsSelfAsMaxiVariant(): void
    {
        // Anti auto-reference (F9-3) : maxi_variant_product_id = soi-meme -> 422.
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'category_id' => 3, 'name' => 'X', 'description' => null, 'price_cents' => 190, 'vat_rate' => 100, 'image_path' => null, 'is_available' => 1, 'display_order' => 1];

        $form = $this->validForm(['name' => 'X', 'price_cents' => '1,90', 'maxi_variant_product_id' => '5']);
        $response = $this->controller($this->post($form, '/admin/products/5'), $db)->update(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('UPDATE product SET'));
        self::assertStringContainsString('sa propre variante Maxi', $response->body());
    }

    public function testStoreRejectsNegativeSize(): void
    {
        // size_cl non entier (ici une valeur non numerique) -> 422.
        $db = $this->permittedDb();

        $form = $this->validForm(['size_cl' => '-5']);
        $response = $this->controller($this->post($form, '/admin/products'), $db)->store();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO product'));
    }

    public function testStoreRejectsUnknownBaseProduct(): void
    {
        // base_product_id reference un produit inexistant -> 422.
        $db = $this->permittedDb();
        $db->productRow = null; // productExists -> false

        $form = $this->validForm(['base_product_id' => '404']);
        $response = $this->controller($this->post($form, '/admin/products'), $db)->store();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO product'));
    }

    public function testFormOffersBaseCandidatesExcludingSelf(): void
    {
        // Le select base_product_id n'expose que des bases (basesOnly) et exclut le
        // produit edite (pas d'auto-reference dans l'UI).
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'category_id' => 3, 'name' => 'Coca Cola', 'description' => null, 'price_cents' => 190, 'vat_rate' => 100, 'image_path' => null, 'is_available' => 1, 'display_order' => 1];
        $db->baseProductsRows = [
            ['id' => 5, 'name' => 'Coca Cola'],   // soi-meme : exclu
            ['id' => 7, 'name' => 'Fanta'],       // autre base : propose
        ];

        $response = $this->controller($this->get('/admin/products/5/edit'), $db)->edit(['id' => '5']);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Fanta', $response->body());
        self::assertStringContainsString('base_product_id', $response->body());
    }

    public function testIndexMarksVariantRows(): void
    {
        // F9-4 : une variante de taille (base_product_id non nul) est marquee
        // "Variante de X" dans la liste admin, pas affichee comme produit autonome.
        $db = $this->permittedDb();
        $db->productsRows = [
            ['id' => 99, 'category_id' => 2, 'name' => 'Coca Cola 50cl', 'price_cents' => 240, 'vat_rate' => 100, 'is_available' => 1, 'category_name' => 'Boissons', 'base_product_id' => 14, 'base_name' => 'Coca Cola'],
        ];

        $response = $this->controller($this->get('/admin/products'), $db)->index();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Variante de Coca Cola', $response->body());
    }

    public function testStoreValidationErrorNoWrite(): void
    {
        $db = $this->permittedDb();
        $response = $this->controller($this->post($this->validForm(['name' => '', 'price_cents' => '0']), '/admin/products'), $db)->store();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO product'));
    }

    public function testUpdateWithoutPriceChangeNeedsNoPin(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'category_id' => 3, 'name' => 'Old', 'description' => null, 'price_cents' => 590, 'vat_rate' => 100, 'image_path' => null, 'is_available' => 1, 'display_order' => 1];

        // Nom change, prix/TVA inchanges -> pas de PIN, pas d'audit.
        $response = $this->controller($this->post($this->validForm(['name' => 'Renamed']), '/admin/products/5'), $db)->update(['id' => '5']);

        self::assertSame(302, $response->status());
        self::assertTrue($db->wrote('UPDATE product SET'));
        self::assertFalse($db->wrote('INSERT INTO audit_log'));
        self::assertSame([], $db->transactionEvents);
    }

    public function testUpdatePriceChangeRequiresPin(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'category_id' => 3, 'name' => 'Big Mac', 'description' => null, 'price_cents' => 590, 'vat_rate' => 100, 'image_path' => null, 'is_available' => 1, 'display_order' => 1];

        // Prix change sans email/PIN -> 422, pas de mise a jour.
        $response = $this->controller($this->post($this->validForm(['price_cents' => '6,20']), '/admin/products/5'), $db)->update(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('PIN', $response->body());
        self::assertFalse($db->wrote('UPDATE product SET'));
        // PIN echoue trace (detectabilite du brute-force, RG-T14).
        self::assertSame(['pin.failed'], $db->auditActions());
    }

    public function testUpdateVatChangeRequiresPin(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'category_id' => 3, 'name' => 'Big Mac', 'description' => null, 'price_cents' => 590, 'vat_rate' => 100, 'image_path' => null, 'is_available' => 1, 'display_order' => 1];

        // Prix inchange (590), TVA 100 -> 55 : sensible -> PIN requis.
        $response = $this->controller($this->post($this->validForm(['vat_rate' => '55']), '/admin/products/5'), $db)->update(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('UPDATE product SET'));
    }

    public function testUpdateVatChangeWithValidPinAudits(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'category_id' => 3, 'name' => 'Big Mac', 'description' => null, 'price_cents' => 590, 'vat_rate' => 100, 'image_path' => null, 'is_available' => 1, 'display_order' => 1];
        $this->actingPin($db);

        $form = $this->validForm(['vat_rate' => '55', 'pin_email' => 'staff@wakdo.local', 'pin' => '4729']);
        $response = $this->controller($this->post($form, '/admin/products/5'), $db)->update(['id' => '5']);

        self::assertSame(302, $response->status());
        self::assertSame(['begin', 'commit'], $db->transactionEvents);
        $audit = $this->firstAudit($db);
        self::assertNotNull($audit);
        self::assertSame('product.update', $audit['params']['code'] ?? null);
        self::assertStringContainsString('vat_rate 100 -> 55', (string) ($audit['params']['summary'] ?? ''));
    }

    public function testUpdatePriceChangeWithValidPinAuditsInTransaction(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'category_id' => 3, 'name' => 'Big Mac', 'description' => null, 'price_cents' => 590, 'vat_rate' => 100, 'image_path' => null, 'is_available' => 1, 'display_order' => 1];
        $this->actingPin($db);

        $form = $this->validForm(['price_cents' => '6,20', 'pin_email' => 'staff@wakdo.local', 'pin' => '4729']);
        $response = $this->controller($this->post($form, '/admin/products/5'), $db)->update(['id' => '5']);

        self::assertSame(302, $response->status());
        self::assertSame(['begin', 'commit'], $db->transactionEvents);
        self::assertTrue($db->wrote('UPDATE product SET'));
        // Acteur = utilisateur RESOLU PAR PIN (id 9, role 4), pas la session (id 1).
        $audit = $this->firstAudit($db);
        self::assertNotNull($audit);
        self::assertSame('product.update', $audit['params']['code'] ?? null);
        self::assertSame(9, $audit['params']['uid'] ?? null);
        self::assertSame(4, $audit['params']['rid'] ?? null);
        // Audit ecrit DANS la transaction (RG-T08), entre begin et commit.
        $this->assertAuditWithinTransaction($db);
    }

    public function testEditNotFoundReturns404(): void
    {
        $db = $this->permittedDb();
        $db->productRow = null;

        self::assertSame(404, $this->controller($this->get('/admin/products/999/edit'), $db)->edit(['id' => '999'])->status());
    }

    public function testEditPrefillsThePriceFieldInEuros(): void
    {
        // F40 (section "Textes techniques ou en anglais" de defauts-visibles.md,
        // prix saisis en centimes) : la base garde price_cents en centimes (590)
        // mais le champ se relit en euros ("5,90"), pas l'entier brut.
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'category_id' => 3, 'name' => 'Big Mac', 'description' => null, 'price_cents' => 590, 'vat_rate' => 100, 'image_path' => null, 'is_available' => 1, 'display_order' => 1];

        $body = $this->controller($this->get('/admin/products/5/edit'), $db)->edit(['id' => '5'])->body();

        self::assertStringContainsString('value="5,90"', $body);
        self::assertStringNotContainsString('value="590"', $body);
    }

    public function testStoreAcceptsDotDecimalSeparatorAndStoresCents(): void
    {
        // « 1,90 » ou « 1.90 » : les deux doivent etre acceptes en saisie (F40).
        $db = $this->permittedDb();

        $response = $this->controller($this->post($this->validForm(['price_cents' => '1.90']), '/admin/products'), $db)->store();

        self::assertSame(302, $response->status());
        $insert = $this->findWrite($db, 'INSERT INTO product');
        self::assertNotNull($insert);
        self::assertSame(190, $insert['params']['price'] ?? null);
    }

    public function testStoreRejectsAnAmountWithMoreThanTwoDecimals(): void
    {
        $db = $this->permittedDb();

        $response = $this->controller($this->post($this->validForm(['price_cents' => '1,900']), '/admin/products'), $db)->store();

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Le prix doit être un montant en euros', $response->body());
        self::assertFalse($db->wrote('INSERT INTO product'));
    }

    public function testConfirmDeleteShowsPinForm(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'name' => 'Big Mac'];

        $response = $this->controller($this->get('/admin/products/5/delete'), $db)->confirmDelete(['id' => '5']);
        self::assertSame(200, $response->status());
        self::assertStringContainsString('name="pin"', $response->body());
    }

    public function testDestroyRequiresValidPin(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'name' => 'Big Mac'];
        $db->actingUserRow = null; // email/PIN invalide

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'pin_email' => 'x@y.z', 'pin' => '0000'], '/admin/products/5/delete'), $db)->destroy(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('DELETE FROM product'));
        self::assertSame(['pin.failed'], $db->auditActions());
    }

    public function testDestroyWithValidPinDeletesAndAudits(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'name' => 'Big Mac'];
        $this->actingPin($db);

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'pin_email' => 'staff@wakdo.local', 'pin' => '4729'], '/admin/products/5/delete'), $db)->destroy(['id' => '5']);

        self::assertSame(302, $response->status());
        self::assertTrue($db->wrote('DELETE FROM product'));
        $audit = $this->firstAudit($db);
        self::assertNotNull($audit);
        self::assertSame('product.delete', $audit['params']['code'] ?? null);
        self::assertSame(9, $audit['params']['uid'] ?? null);   // acteur = PIN, pas la session (1)
        self::assertSame(4, $audit['params']['rid'] ?? null);
        $this->assertAuditWithinTransaction($db);
    }

    public function testDestroyReferencedReturns409(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'name' => 'Big Mac'];
        $this->actingPin($db);
        $db->failOnExecute = new \PDOException('fk', 23000); // FK RESTRICT a la suppression

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'pin_email' => 'staff@wakdo.local', 'pin' => '4729'], '/admin/products/5/delete'), $db)->destroy(['id' => '5']);

        self::assertSame(409, $response->status());
        self::assertStringContainsString('référencé', $response->body());
    }

    public function testStoreRejectsInvalidCsrf(): void
    {
        $db = $this->permittedDb();
        $response = $this->controller($this->post($this->validForm(['_csrf' => 'wrong']), '/admin/products'), $db)->store();

        self::assertSame(403, $response->status());
        self::assertFalse($db->wrote('INSERT INTO product'));
    }

    /**
     * Bug corrige (2026-09-26) : le formulaire produit est TOUJOURS en
     * multipart/form-data (enctype impose par l'upload d'image), meme quand
     * aucune image n'est choisie. Avant le correctif, Request::formBody() ne
     * reconnaissait que l'urlencode et renvoyait [] pour tout multipart : _csrf
     * etait donc TOUJOURS absent et Csrf::validate() echouait systematiquement,
     * "Requête invalide." (403) sur CHAQUE creation de produit, image ou non.
     */
    public function testStoreAcceptsRealMultipartFormWithoutAnyImage(): void
    {
        $db = $this->permittedDb();

        $response = $this->controller($this->postMultipartNoFile($this->validForm(), '/admin/products'), $db)->store();

        self::assertSame(302, $response->status());
        self::assertNotNull($this->findWrite($db, 'INSERT INTO product'));
    }

    /** Meme bug, chemin edition (formulaire identique, meme enctype). */
    public function testUpdateAcceptsRealMultipartFormWithoutAnyImage(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'category_id' => 3, 'name' => 'Big Mac', 'description' => null, 'price_cents' => 590, 'vat_rate' => 100, 'image_path' => null, 'is_available' => 1, 'display_order' => 1];

        $response = $this->controller($this->postMultipartNoFile($this->validForm(), '/admin/products/5'), $db)->update(['id' => '5']);

        self::assertSame(302, $response->status());
    }

    /**
     * Quand le corps DEPASSE post_max_size (image trop lourde), PHP vide $_POST
     * ET $_FILES sans exception applicative : sans detection explicite, ce cas se
     * confond avec un CSRF invalide et remonte le 403 generique, qui ne dit rien
     * a l'equipier sur la vraie cause. Le formulaire doit plutot etre re-affiche
     * avec un message clair, en francais, sans jargon technique.
     */
    public function testStoreShowsFriendlyMessageWhenBodyExceedsPostMaxSize(): void
    {
        $db = $this->permittedDb();

        $response = $this->controller($this->postOversized('/admin/products'), $db)->store();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO product'));
        self::assertStringContainsString('trop volumineux', $response->body());
        self::assertStringNotContainsString('Requête invalide', $response->body());
    }

    public function testUpdateLockedActorReturnsGeneric422WithoutVerifyingOrAuditing(): void
    {
        // RG-T22 : acteur de session verrouille. Le verrou est evalue AVANT la
        // verification ; meme un PIN valide est bloque, le 422 reste generique, et
        // AUCUNE nouvelle ligne pin.failed n'est ecrite (borne anti-flood).
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'category_id' => 3, 'name' => 'Big Mac', 'description' => null, 'price_cents' => 590, 'vat_rate' => 100, 'image_path' => null, 'is_available' => 1, 'display_order' => 1];
        $this->actingPin($db);                                  // PIN '4729' valide en base
        $db->pinThrottleLockoutUntil = '2099-01-01 00:00:00';   // acteur verrouille

        $form = $this->validForm(['price_cents' => '6,20', 'pin_email' => 'staff@wakdo.local', 'pin' => '4729']);
        $response = $this->controller($this->post($form, '/admin/products/5'), $db)->update(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('PIN', $response->body());
        self::assertFalse($db->wrote('UPDATE product SET'));    // PIN valide mais verrou prioritaire
        self::assertSame([], $db->auditActions());              // pas de pin.failed sous verrou
    }

    public function testUpdateWrongPinRecordsFailureOnSessionActor(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'category_id' => 3, 'name' => 'Big Mac', 'description' => null, 'price_cents' => 590, 'vat_rate' => 100, 'image_path' => null, 'is_available' => 1, 'display_order' => 1];
        $db->actingUserRow = null;                              // email/PIN invalide

        $form = $this->validForm(['price_cents' => '6,20', 'pin_email' => 'ghost@wakdo.local', 'pin' => '0000']);
        $response = $this->controller($this->post($form, '/admin/products/5'), $db)->update(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertSame(['pin.failed'], $db->auditActions());  // detectabilite preservee
        // RG-T22 : le compteur est incremente sur l'AGISSANT (session id 1), pas sur
        // l'email cible tente (qui serait contournable par rotation).
        $upsert = $this->findWrite($db, 'INSERT INTO pin_throttle');
        self::assertNotNull($upsert);
        self::assertSame(1, $upsert['params']['uid'] ?? null);
    }

    public function testUpdateValidPinResetsThrottleOnSessionActorNotResolvedUser(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'category_id' => 3, 'name' => 'Big Mac', 'description' => null, 'price_cents' => 590, 'vat_rate' => 100, 'image_path' => null, 'is_available' => 1, 'display_order' => 1];
        $this->actingPin($db);

        $form = $this->validForm(['price_cents' => '6,20', 'pin_email' => 'staff@wakdo.local', 'pin' => '4729']);
        $response = $this->controller($this->post($form, '/admin/products/5'), $db)->update(['id' => '5']);

        self::assertSame(302, $response->status());
        // L'audit porte l'acteur RESOLU PAR PIN (id 9)...
        $audit = $this->firstAudit($db);
        self::assertSame(9, $audit['params']['uid'] ?? null);
        // ...mais le reset du throttle porte l'acteur de SESSION (id 1), le seul qui
        // a ete incremente. Confondre les deux laisserait le compteur de l'agissant
        // jamais purge (must-fix de revue).
        $reset = $this->findWrite($db, 'UPDATE pin_throttle SET failed_attempts = 0');
        self::assertNotNull($reset);
        self::assertSame(1, $reset['params']['uid'] ?? null);
    }

    public function testDestroyLockedActorReturnsGeneric422(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'name' => 'Big Mac'];
        $this->actingPin($db);
        $db->pinThrottleLockoutUntil = '2099-01-01 00:00:00';

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'pin_email' => 'staff@wakdo.local', 'pin' => '4729'], '/admin/products/5/delete'), $db)->destroy(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('DELETE FROM product'));
        self::assertSame([], $db->auditActions());
    }

    // --- Editeur de recette (PR-B, product_ingredient, permission ingredient.manage) ---

    public function testRecipeFormRequiresIngredientManage(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'name' => 'Big Mac'];
        $db->canResult = false; // ni ingredient.manage ni rien

        self::assertSame(403, $this->controller($this->get('/admin/products/5/recipe'), $db)->recipeForm(['id' => '5'])->status());
    }

    public function testRecipeFormNotFound(): void
    {
        $db = $this->permittedDb();
        $db->productRow = null;

        self::assertSame(404, $this->controller($this->get('/admin/products/9/recipe'), $db)->recipeForm(['id' => '9'])->status());
    }

    public function testRecipeFormShowsCompositionAndPicker(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'name' => 'Big Mac'];
        $db->ingredientsRows = [$this->ingredientPick(7, 'Cheddar'), $this->ingredientPick(8, 'Cornichon')];
        $db->compositionRows = [[
            'product_id' => 5, 'ingredient_id' => 7, 'quantity_normal' => 2, 'quantity_maxi' => 3,
            'is_removable' => 1, 'is_addable' => 0, 'extra_price_cents' => 0,
            'ingredient_name' => 'Cheddar', 'ingredient_unit' => 'tranche',
            'stock_quantity' => 50, 'stock_capacity' => 100, 'low_stock_pct' => 10, 'critical_stock_pct' => 5,
        ]];

        $response = $this->controller($this->get('/admin/products/5/recipe'), $db)->recipeForm(['id' => '5']);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Big Mac', $response->body());
        self::assertStringContainsString('Cheddar', $response->body());   // picker + composition existante
        self::assertStringContainsString('composition_json', $response->body());
        // F40 (textes techniques) : le code de regle interne ne doit pas fuiter a l'ecran.
        self::assertStringNotContainsString('RG-T21', $response->body());
    }

    public function testSaveRecipeReplacesCompositionInTransaction(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'name' => 'Big Mac'];
        $db->ingredientRow = ['id' => 7, 'name' => 'Cheddar']; // ingredientExists -> true
        $json = (string) json_encode([[
            'ingredient_id' => 7, 'quantity_normal' => 2, 'quantity_maxi' => 3,
            'is_removable' => 1, 'is_addable' => 0, 'extra_price_cents' => 50,
        ]]);

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'composition_json' => $json], '/admin/products/5/recipe'), $db)->saveRecipe(['id' => '5']);

        self::assertSame(302, $response->status());
        self::assertSame(['begin', 'commit'], $db->transactionEvents);
        self::assertTrue($db->wrote('DELETE FROM product_ingredient')); // delete-and-reinsert (RG-2)
        $insert = $this->findWrite($db, 'INSERT INTO product_ingredient');
        self::assertNotNull($insert);
        self::assertSame(5, $insert['params']['product'] ?? null);
        self::assertSame(7, $insert['params']['ingredient'] ?? null);
        self::assertSame(2, $insert['params']['qn'] ?? null);
        self::assertSame(3, $insert['params']['qm'] ?? null);
        self::assertSame(50, $insert['params']['extra'] ?? null);
    }

    public function testSaveRecipeEmptyClearsComposition(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'name' => 'Big Mac'];

        // Composition vide : un produit peut n'avoir aucune recette definie -> on
        // purge sans erreur (DELETE seul, aucun INSERT).
        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'composition_json' => '[]'], '/admin/products/5/recipe'), $db)->saveRecipe(['id' => '5']);

        self::assertSame(302, $response->status());
        self::assertTrue($db->wrote('DELETE FROM product_ingredient'));
        self::assertFalse($db->wrote('INSERT INTO product_ingredient'));
    }

    public function testSaveRecipeRejectsMaxiBelowNormal(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'name' => 'Big Mac'];
        $db->ingredientRow = ['id' => 7, 'name' => 'Cheddar'];
        $json = (string) json_encode([[
            'ingredient_id' => 7, 'quantity_normal' => 3, 'quantity_maxi' => 1, // viole quantity_maxi >= quantity_normal
            'is_removable' => 0, 'is_addable' => 0, 'extra_price_cents' => 0,
        ]]);

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'composition_json' => $json], '/admin/products/5/recipe'), $db)->saveRecipe(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('product_ingredient')); // aucun ecrit (validation RG-T18)
    }

    public function testSaveRecipeDropsUnknownIngredient(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'name' => 'Big Mac'];
        $db->ingredientRow = null; // ingredientExists -> false : ligne ignoree (allowlist)
        $json = (string) json_encode([[
            'ingredient_id' => 999, 'quantity_normal' => 1, 'quantity_maxi' => 1,
            'is_removable' => 0, 'is_addable' => 0, 'extra_price_cents' => 0,
        ]]);

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'composition_json' => $json], '/admin/products/5/recipe'), $db)->saveRecipe(['id' => '5']);

        self::assertSame(302, $response->status());
        self::assertTrue($db->wrote('DELETE FROM product_ingredient'));
        self::assertFalse($db->wrote('INSERT INTO product_ingredient')); // l'ingredient inconnu est filtre
    }

    public function testSaveRecipeRejectsInvalidCsrf(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'name' => 'Big Mac'];

        $response = $this->controller($this->post(['_csrf' => 'bad', 'composition_json' => '[]'], '/admin/products/5/recipe'), $db)->saveRecipe(['id' => '5']);

        self::assertSame(403, $response->status());
        self::assertFalse($db->wrote('product_ingredient'));
    }

    public function testIndexFlagsStockDrivenRupture(): void
    {
        $db = $this->permittedDb();
        $db->productsRows = [
            ['id' => 1, 'category_id' => 3, 'name' => 'Big Mac', 'price_cents' => 590, 'vat_rate' => 100, 'is_available' => 1, 'category_name' => 'Burgers'],
        ];
        $db->autoUnavailableRows = [['product_id' => 1]]; // un ingredient requis en bande critique (RG-T21)

        $response = $this->controller($this->get('/admin/products'), $db)->index();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Rupture auto', $response->body()); // distinct du retrait manuel
        // F40 (textes techniques) : le code de regle interne ne doit pas fuiter a l'ecran.
        self::assertStringNotContainsString('RG-T21', $response->body());
    }

    public function testDestroyTracesCascadedCompositionCount(): void
    {
        // Dette #27 : la suppression dure cascade product_ingredient (FK CASCADE) ;
        // on trace combien de lignes de recette ont ete emportees, pour ne laisser
        // aucune perte hors-trace dans l'audit_log.
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'name' => 'Big Mac'];
        $db->productCompositionCount = 3;
        $this->actingPin($db);

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'pin_email' => 'staff@wakdo.local', 'pin' => '4729'], '/admin/products/5/delete'), $db)->destroy(['id' => '5']);

        self::assertSame(302, $response->status());
        $audit = $this->firstAudit($db);
        self::assertNotNull($audit);
        self::assertSame('product.delete', $audit['params']['code'] ?? null);
        self::assertStringContainsString('3', (string) ($audit['params']['summary'] ?? '')); // nb de lignes cascade tracees
    }

    /**
     * @return array<string, mixed>
     */
    private function ingredientPick(int $id, string $name): array
    {
        return ['id' => $id, 'name' => $name, 'unit' => 'tranche', 'stock_quantity' => 50, 'stock_capacity' => 100, 'pack_size' => 1, 'pack_label' => null, 'low_stock_pct' => 10, 'critical_stock_pct' => 5, 'is_active' => 1];
    }

    /**
     * @return array{sql: string, params: array<string|int, mixed>}|null
     */
    private function firstAudit(FakeDatabase $db): ?array
    {
        return $this->findWrite($db, 'INSERT INTO audit_log');
    }

    /**
     * @return array{sql: string, params: array<string|int, mixed>}|null
     */
    private function findWrite(FakeDatabase $db, string $needle): ?array
    {
        foreach ($db->writes as $write) {
            if (str_contains($write['sql'], $needle)) {
                return $write;
            }
        }

        return null;
    }

    private function assertAuditWithinTransaction(FakeDatabase $db): void
    {
        $log = $db->eventLog;
        $begin = array_search('begin', $log, true);
        $commit = array_search('commit', $log, true);
        $auditAt = null;
        foreach ($log as $i => $event) {
            if (str_contains($event, 'INSERT INTO audit_log')) {
                $auditAt = $i;
            }
        }

        self::assertIsInt($begin);
        self::assertIsInt($commit);
        self::assertNotNull($auditAt);
        self::assertTrue($begin < $auditAt && $auditAt < $commit, 'audit_log doit etre ecrit entre begin et commit');
    }

    /* --- F20 : vue produits par categorie ---------------------------------- */

    /**
     * Jeu de donnees commun : 3 categories (dont une masquee), 2 produits a la carte,
     * 1 menu. Les lignes sont dans la forme que renvoie chaque requete.
     */
    private function byCategoryDb(): FakeDatabase
    {
        $db = $this->permittedDb();
        $db->categoriesRows = [
            ['id' => 1, 'name' => 'menus', 'slug' => 'menus', 'image_path' => 'm.png', 'display_order' => 1, 'is_active' => 1],
            ['id' => 2, 'name' => 'boissons', 'slug' => 'boissons', 'image_path' => 'b.png', 'display_order' => 2, 'is_active' => 1],
            ['id' => 9, 'name' => 'sauces', 'slug' => 'sauces', 'image_path' => 's.png', 'display_order' => 9, 'is_active' => 0],
        ];
        $db->basesByCategoryRows = [
            ['id' => 14, 'category_id' => 2, 'name' => 'Coca Cola', 'price_cents' => 190, 'vat_rate' => 100, 'is_available' => 1, 'display_order' => 1, 'variant_count' => 1],
            ['id' => 15, 'category_id' => 2, 'name' => 'Eau', 'price_cents' => 100, 'vat_rate' => 55, 'is_available' => 1, 'display_order' => 3, 'variant_count' => 0],
        ];
        $db->menusRows = [
            ['id' => 4, 'category_id' => 1, 'burger_product_id' => 10, 'name' => 'Menu Big Mac', 'price_normal_cents' => 800, 'price_maxi_cents' => 950, 'is_available' => 1, 'display_order' => 1, 'category_name' => 'menus', 'burger_name' => 'Big Mac'],
        ];
        return $db;
    }

    public function testByCategoryRequiresProductRead(): void
    {
        $db = $this->byCategoryDb();
        $db->canResult = false;

        self::assertSame(403, $this->controller($this->get('/admin/products/by-category'), $db)->byCategory()->status());
    }

    public function testByCategoryGroupsProductsUnderCategoryHeadingsInBorneOrder(): void
    {
        $response = $this->controller($this->get('/admin/products/by-category'), $this->byCategoryDb())->byCategory();

        self::assertSame(200, $response->status());
        $body = $response->body();
        // L'ordre des groupes est celui du display_order de categorie, donc celui des
        // onglets de la borne : menus (1) avant boissons (2) avant sauces (9). On
        // s'ancre sur l'identifiant de section, pas sur le libelle : "Menus" apparait
        // aussi dans la barre laterale et rendrait l'assertion vraie par accident.
        $posMenus = strpos($body, 'catalogue-cat-1');
        $posBoissons = strpos($body, 'catalogue-cat-2');
        $posSauces = strpos($body, 'catalogue-cat-9');
        self::assertIsInt($posMenus);
        self::assertIsInt($posBoissons);
        self::assertIsInt($posSauces);
        self::assertTrue($posMenus < $posBoissons && $posBoissons < $posSauces);
        self::assertStringContainsString('Coca Cola', $body);
        self::assertStringContainsString('Eau', $body);
    }

    public function testByCategoryShowsMenusInTheirCategory(): void
    {
        // Un menu n'est pas une ligne de la table product : sans lecture dediee, la
        // section Menus afficherait "aucun produit" alors que la borne y montre les
        // menus. La vue doit donc porter les deux natures d'article.
        $response = $this->controller($this->get('/admin/products/by-category'), $this->byCategoryDb())->byCategory();

        self::assertStringContainsString('Menu Big Mac', $response->body());
    }

    public function testByCategoryFoldsSizeVariantsOnTheirBase(): void
    {
        // Coca Cola porte 1 variante de taille : elle est comptee sur la base, pas
        // affichee comme un article autonome (R4).
        $response = $this->controller($this->get('/admin/products/by-category'), $this->byCategoryDb())->byCategory();

        $body = $response->body();
        self::assertStringContainsString('1 taille', $body);
        self::assertStringNotContainsString('Variante de', $body);
    }

    public function testByCategoryShowsTheThreeAvailabilityStates(): void
    {
        $db = $this->byCategoryDb();
        $db->basesByCategoryRows[] = ['id' => 16, 'category_id' => 2, 'name' => 'Fanta', 'price_cents' => 190, 'vat_rate' => 100, 'is_available' => 0, 'display_order' => 4, 'variant_count' => 0];
        // RG-T21 : Eau en rupture calculee par le stock, distincte du retrait manuel.
        $db->autoUnavailableRows = [['product_id' => 15]];

        $body = $this->controller($this->get('/admin/products/by-category'), $db)->byCategory()->body();

        self::assertStringContainsString('Disponible', $body);
        self::assertStringContainsString('Rupture auto', $body);
        self::assertStringContainsString('Indisponible', $body);
        // F40 (textes techniques) : le code de regle interne ne doit pas fuiter a l'ecran.
        self::assertStringNotContainsString('RG-T21', $body);
    }

    public function testByCategoryMarksACategoryHiddenFromTheBorne(): void
    {
        // Une categorie inactive n'apparait pas sur la borne, meme si ses produits sont
        // marques disponibles : l'equipier doit le lire en clair, sinon il cherchera en
        // vain pourquoi un produit "disponible" reste introuvable a la commande.
        $body = $this->controller($this->get('/admin/products/by-category'), $this->byCategoryDb())->byCategory()->body();

        self::assertStringContainsString('Masquée sur la borne', $body);
    }

    public function testByCategoryComputesCountersServerSide(): void
    {
        $db = $this->byCategoryDb();
        $db->basesByCategoryRows[] = ['id' => 16, 'category_id' => 2, 'name' => 'Fanta', 'price_cents' => 190, 'vat_rate' => 100, 'is_available' => 0, 'display_order' => 4, 'variant_count' => 0];

        $body = $this->controller($this->get('/admin/products/by-category'), $db)->byCategory()->body();

        // 3 produits de base + 1 menu = 4 articles ; 3 commandables, 1 non commandable.
        self::assertMatchesRegularExpression('/catalogue-summary__count">\s*4\s*</', $body);
        self::assertMatchesRegularExpression('/catalogue-summary__count">\s*3\s*</', $body);
        self::assertMatchesRegularExpression('/catalogue-summary__count">\s*1\s*</', $body);
    }

    public function testByCategoryHidesEditLinkWithoutProductUpdate(): void
    {
        // Un role de lecture seule (ex. cuisine) ne doit pas voir un lien qui repondrait
        // 403 : la garde reste par-route, l'affichage s'y adapte.
        $db = $this->byCategoryDb();
        $db->grantedCodes = ['product.read'];

        $body = $this->controller($this->get('/admin/products/by-category'), $db)->byCategory()->body();

        self::assertStringContainsString('Coca Cola', $body);
        self::assertStringNotContainsString('/edit', $body);
    }

    public function testByCategoryShowsEditLinkWithProductUpdate(): void
    {
        $db = $this->byCategoryDb();
        $db->grantedCodes = ['product.read', 'product.update'];

        $body = $this->controller($this->get('/admin/products/by-category'), $db)->byCategory()->body();

        self::assertStringContainsString('/admin/products/14/edit', $body);
    }

    public function testByCategoryReportsAnEmptyCategory(): void
    {
        $db = $this->byCategoryDb();
        $db->basesByCategoryRows = [];
        $db->menusRows = [];

        $body = $this->controller($this->get('/admin/products/by-category'), $db)->byCategory()->body();

        self::assertStringContainsString('Aucun article dans cette catégorie.', $body);
    }

    public function testByCategoryReadsAFixedNumberOfQueries(): void
    {
        // Quatre lectures a nombre fixe (categories, bases groupees, rupture auto,
        // menus) : la page ne doit pas partir en N+1 quand le catalogue grossit.
        $db = $this->byCategoryDb();
        $this->controller($this->get('/admin/products/by-category'), $db)->byCategory();

        $catalogueReads = array_filter(
            $db->reads,
            static fn (array $r): bool => str_contains($r['sql'], 'AS variant_count')
                || str_contains($r['sql'], 'FROM category ORDER BY')
                || str_contains($r['sql'], 'SELECT DISTINCT pi.product_id')
                || str_contains($r['sql'], 'FROM menu m JOIN category'),
        );

        self::assertCount(4, $catalogueReads);
    }

    /* --- Image produit (ImageUploader) -------------------------------------- */

    public function testStoreWithValidImageStoresFileAndPersistsPath(): void
    {
        $db = $this->permittedDb();
        $image = $this->uploadedImage();

        $response = $this->controller($this->postWithFile($this->validForm(), '/admin/products', $image), $db)->store();

        self::assertSame(302, $response->status());
        $insert = $this->findWrite($db, 'INSERT INTO product');
        self::assertNotNull($insert);
        $relative = (string) ($insert['params']['image'] ?? '');
        self::assertMatchesRegularExpression('#^uploads/products/[a-f0-9]{32}\.png$#', $relative);
        self::assertFileExists($this->uploadBaseDir . '/' . substr($relative, strlen('uploads/')));
    }

    public function testStoreWithInvalidImageReturns422AndWritesNoFile(): void
    {
        $db = $this->permittedDb();
        $badImage = [
            'name' => 'photo.png', 'type' => 'image/png',
            'tmp_name' => $this->writeTemp('ceci n est pas une image'),
            'error' => UPLOAD_ERR_OK, 'size' => 24,
        ];

        $response = $this->controller($this->postWithFile($this->validForm(), '/admin/products', $badImage), $db)->store();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO product'));
        self::assertStringContainsString('Format d&#039;image non accepté', $response->body());
        self::assertDirectoryDoesNotExist($this->uploadBaseDir . '/products');
    }

    public function testStoreWithValidImageButAnotherInvalidFieldNeverWritesTheFile(): void
    {
        // L'image est valide, mais un autre champ ne l'est pas : verifier tot,
        // ecrire tard (docblock ImageUploader::validate()) doit tenir meme quand
        // l'image elle-meme n'est pas en cause.
        $db = $this->permittedDb();
        $image = $this->uploadedImage();

        $response = $this->controller(
            $this->postWithFile($this->validForm(['name' => '']), '/admin/products', $image),
            $db,
        )->store();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO product'));
        self::assertDirectoryDoesNotExist($this->uploadBaseDir . '/products');
    }

    public function testUpdateReplacesImageAndRemovesTheOldOneOnlyAfterSuccess(): void
    {
        $db = $this->permittedDb();
        $oldRelative = $this->plantUploadedImage('products');
        $db->productRow = ['id' => 5, 'category_id' => 3, 'name' => 'Coca Cola', 'description' => null, 'price_cents' => 190, 'vat_rate' => 100, 'image_path' => $oldRelative, 'is_available' => 1, 'display_order' => 1];

        $response = $this->controller(
            $this->postWithFile($this->validForm(['name' => 'Coca Cola', 'price_cents' => '1,90']), '/admin/products/5', $this->uploadedImage()),
            $db,
        )->update(['id' => '5']);

        self::assertSame(302, $response->status());
        self::assertFileDoesNotExist($this->uploadBaseDir . '/' . substr($oldRelative, strlen('uploads/')));
        $update = $this->findWrite($db, 'UPDATE product SET');
        self::assertNotNull($update);
        $newRelative = (string) ($update['params']['image'] ?? '');
        self::assertMatchesRegularExpression('#^uploads/products/[a-f0-9]{32}\.png$#', $newRelative);
        self::assertFileExists($this->uploadBaseDir . '/' . substr($newRelative, strlen('uploads/')));
    }

    public function testUpdateKeepsTheOldImageWhenTheDatabaseWriteFails(): void
    {
        // Exigence centrale (docblock du controleur) : l'ancienne image n'est
        // effacee qu'UNE FOIS la base a jour. Simule une panne d'ecriture pour le
        // prouver, plutot que de le supposer de la lecture du code.
        $db = $this->permittedDb();
        $oldRelative = $this->plantUploadedImage('products');
        $db->productRow = ['id' => 5, 'category_id' => 3, 'name' => 'Coca Cola', 'description' => null, 'price_cents' => 190, 'vat_rate' => 100, 'image_path' => $oldRelative, 'is_available' => 1, 'display_order' => 1];
        $db->failOnExecute = new \RuntimeException('panne disque simulee');

        try {
            $this->controller(
                $this->postWithFile($this->validForm(['name' => 'Coca Cola', 'price_cents' => '1,90']), '/admin/products/5', $this->uploadedImage()),
                $db,
            )->update(['id' => '5']);
            self::fail('une exception etait attendue (panne DB simulee)');
        } catch (\RuntimeException $exception) {
            self::assertSame('panne disque simulee', $exception->getMessage());
        }

        self::assertFileExists($this->uploadBaseDir . '/' . substr($oldRelative, strlen('uploads/')));
    }

    public function testDestroyRemovesTheUploadedProductImage(): void
    {
        $db = $this->permittedDb();
        $relative = $this->plantUploadedImage('products');
        $db->productRow = ['id' => 5, 'name' => 'Big Mac', 'image_path' => $relative];
        $this->actingPin($db);

        $response = $this->controller(
            $this->post(['_csrf' => $this->csrf, 'pin_email' => 'staff@wakdo.local', 'pin' => '4729'], '/admin/products/5/delete'),
            $db,
        )->destroy(['id' => '5']);

        self::assertSame(302, $response->status());
        self::assertFileDoesNotExist($this->uploadBaseDir . '/' . substr($relative, strlen('uploads/')));
    }

    public function testDestroyNeverTouchesAShippedCatalogueImage(): void
    {
        $db = $this->permittedDb();
        $catalogueRelative = 'assets/images/produits/burgers/big-mac.png';
        $catalogueAbsolute = $this->uploadBaseDir . '/' . $catalogueRelative;
        mkdir(dirname($catalogueAbsolute), 0755, true);
        file_put_contents($catalogueAbsolute, 'image du catalogue livre avec le projet');

        $db->productRow = ['id' => 5, 'name' => 'Big Mac', 'image_path' => $catalogueRelative];
        $this->actingPin($db);

        $response = $this->controller(
            $this->post(['_csrf' => $this->csrf, 'pin_email' => 'staff@wakdo.local', 'pin' => '4729'], '/admin/products/5/delete'),
            $db,
        )->destroy(['id' => '5']);

        self::assertSame(302, $response->status());
        self::assertFileExists($catalogueAbsolute);
    }

    /* --- Rangement du catalogue (move) --------------------------------------- */

    public function testMoveRejectsInvalidCsrf(): void
    {
        $db = $this->permittedDb();

        $response = $this->controller($this->post(['_csrf' => 'wrong', 'direction' => 'up'], '/admin/products/20/move'), $db)->move(['id' => '20']);

        self::assertSame(403, $response->status());
        self::assertFalse($db->wrote('UPDATE product SET display_order'));
    }

    public function testMoveRequiresProductUpdatePermission(): void
    {
        $db = $this->permittedDb();
        $db->canResult = false;

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'direction' => 'up'], '/admin/products/20/move'), $db)->move(['id' => '20']);

        self::assertSame(403, $response->status());
        self::assertFalse($db->wrote('UPDATE product SET display_order'));
    }

    public function testMoveUpRedirectsAndSetsFlashOnSuccess(): void
    {
        $db = $this->permittedDb();
        $db->reorderProductRow = ['category_id' => 3];
        $db->reorderCategoryIdsRows = [['id' => 10], ['id' => 20]];

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'direction' => 'up'], '/admin/products/20/move'), $db)->move(['id' => '20']);

        self::assertSame(302, $response->status());
        self::assertSame('/admin/products/by-category', $response->header('Location'));
        self::assertSame('Ordre du catalogue mis à jour.', $this->session->get('_flash'));
        self::assertTrue($db->wrote('UPDATE product SET display_order'));
    }

    public function testMoveAlreadyAtTopIsANoOpAndDoesNotSetFlash(): void
    {
        $db = $this->permittedDb();
        $db->reorderProductRow = ['category_id' => 3];
        $db->reorderCategoryIdsRows = [['id' => 10], ['id' => 20]];

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'direction' => 'up'], '/admin/products/10/move'), $db)->move(['id' => '10']);

        self::assertSame(302, $response->status());
        self::assertNull($this->session->get('_flash'));
        self::assertFalse($db->wrote('UPDATE product SET display_order'));
    }

    public function testMoveWithInvalidDirectionRedirectsWithoutWriting(): void
    {
        $db = $this->permittedDb();
        $db->reorderProductRow = ['category_id' => 3];
        $db->reorderCategoryIdsRows = [['id' => 10], ['id' => 20]];

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'direction' => 'sideways'], '/admin/products/20/move'), $db)->move(['id' => '20']);

        self::assertSame(302, $response->status());
        self::assertSame('/admin/products/by-category', $response->header('Location'));
        self::assertNull($this->session->get('_flash'));
        self::assertFalse($db->wrote('UPDATE product SET display_order'));
    }
}
