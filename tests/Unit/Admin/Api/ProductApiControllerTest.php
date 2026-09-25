<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin\Api;

use PHPUnit\Framework\TestCase;
use App\Auth\Authorizer;
use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\SessionGuard;
use App\Auth\SessionManager;
use App\Controllers\Admin\Api\ProductApiController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\ImageUploader;
use App\Core\Request;
use App\Tests\Support\FakeDatabase;
use App\Tests\Support\TestableImageUploader;

final class TestProductApiController extends ProductApiController
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

    protected function db(): DatabaseInterface
    {
        return $this->fakeDb;
    }

    protected function imageUploader(): ImageUploader
    {
        return new TestableImageUploader($this->config, $this->uploadBaseDir);
    }
}

final class ProductApiControllerTest extends TestCase
{
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

        $this->uploadBaseDir = sys_get_temp_dir() . '/wakdo_api_uploads_test_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ($this->touchedKeys as $key) {
            putenv($key);
        }
        $this->touchedKeys = [];
        $this->removeDirectory($this->uploadBaseDir);
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
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Plante un fichier image dans le dossier d'upload de test et renvoie son
     * chemin relatif (meme convention que `image_path` en base).
     */
    private function plantUploadedImage(string $subdir): string
    {
        $name = bin2hex(random_bytes(16)) . '.png';
        $directory = $this->uploadBaseDir . '/' . $subdir;
        mkdir($directory, 0755, true);
        // PNG 1x1 valide et complet (signature + IHDR + IDAT + IEND).
        file_put_contents($directory . '/' . $name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));

        return 'uploads/' . $subdir . '/' . $name;
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
        // grantedCodes (allowlist EXACTE, pas canResult=true) : un test casse si le
        // controleur se met a demander un autre code de permission que celui documente.
        $db->permissionCodes = ['product.read', 'product.create', 'product.update', 'product.delete', 'ingredient.manage'];
        $db->grantedCodes = $db->permissionCodes;
        $db->categoryRow = ['id' => 3, 'name' => 'Burgers']; // categoryExists -> true

        return $db;
    }

    private function actingPin(FakeDatabase $db): void
    {
        $db->actingUserRow = ['id' => 9, 'role_id' => 4, 'pin_hash' => (new PasswordHasher(new Config()))->hash('4729')];
    }

    private function get(string $path): Request
    {
        return new Request('GET', $path, [], [], '', '203.0.113.5');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(string $method, string $path, array $body, bool $withCsrf = true): Request
    {
        $headers = ['content-type' => 'application/json'];
        if ($withCsrf) {
            $headers['x-csrf-token'] = $this->csrf;
        }

        return new Request($method, $path, [], $headers, ($body === [] ? '{}' : (string) json_encode($body)), '203.0.113.5');
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validBody(array $overrides = []): array
    {
        return array_merge([
            'category_id'   => 3,
            'name'          => 'Big Mac',
            'price_cents'   => 590,
            'vat_rate'      => 100,
            'display_order' => 1,
            'is_available'  => true,
        ], $overrides);
    }

    private function controller(Request $request, FakeDatabase $db): TestProductApiController
    {
        $controller = new TestProductApiController($request, new Config(), new Database(new Config()), $this->session, $db);
        $controller->uploadBaseDir = $this->uploadBaseDir;

        return $controller;
    }

    public function testIndexWithoutPermissionReturns403(): void
    {
        $db = $this->permittedDb();
        $db->grantedCodes = []; // aucune permission accordee

        $response = $this->controller($this->get('/admin/api/products'), $db)->apiIndex();

        self::assertSame(403, $response->status());
    }

    public function testStoreRejectsMissingCsrf(): void
    {
        $db = $this->permittedDb();
        $request = $this->jsonRequest('POST', '/admin/api/products', $this->validBody(), false);

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(403, $response->status());
        self::assertSame('CSRF_INVALID', $body['error']['code'] ?? null);
        self::assertFalse($db->wrote('INSERT INTO product'));
    }

    public function testStoreRejectsWrongCsrfToken(): void
    {
        $db = $this->permittedDb();
        $request = new Request(
            'POST',
            '/admin/api/products',
            [],
            ['content-type' => 'application/json', 'x-csrf-token' => 'un-jeton-invente'],
            (string) json_encode($this->validBody()),
            '203.0.113.5',
        );

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(403, $response->status());
        self::assertSame('CSRF_INVALID', $body['error']['code'] ?? null);
        self::assertFalse($db->wrote('INSERT INTO product'));
    }

    public function testStoreValidCreatesWithoutPin(): void
    {
        $db = $this->permittedDb();
        $db->lastInsertId = 11;
        $db->productRow = ['id' => 11, 'category_id' => 3, 'name' => 'Big Mac', 'description' => null, 'price_cents' => 590, 'size_cl' => null, 'base_product_id' => null, 'maxi_variant_product_id' => null, 'vat_rate' => 100, 'image_path' => null, 'is_available' => 1, 'display_order' => 1];
        $request = $this->jsonRequest('POST', '/admin/api/products', $this->validBody());

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(201, $response->status());
        self::assertSame(590, $body['data']['price_cents']);
        self::assertTrue($db->wrote('INSERT INTO product'));
    }

    public function testStoreInvalidReturns422(): void
    {
        $db = $this->permittedDb();
        $request = $this->jsonRequest('POST', '/admin/api/products', $this->validBody(['name' => '']));

        $response = $this->controller($request, $db)->apiStore();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO product'));
    }

    public function testStoreRejectsFloatPriceCents(): void
    {
        // 590.9 : un entier JSON est exige, jamais tronque en 590.
        $db = $this->permittedDb();
        $request = $this->jsonRequest('POST', '/admin/api/products', $this->validBody(['price_cents' => 590.9]));

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(422, $response->status());
        self::assertArrayHasKey('price_cents', $body['error']['fields']);
        self::assertFalse($db->wrote('INSERT INTO product'));
    }

    public function testStoreRejectsEuroStringPriceCents(): void
    {
        // "12,50" : une chaine en euros n'est jamais relue comme des centimes.
        $db = $this->permittedDb();
        $request = $this->jsonRequest('POST', '/admin/api/products', $this->validBody(['price_cents' => '12,50']));

        $response = $this->controller($request, $db)->apiStore();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO product'));
    }

    public function testStoreRejectsNonScalarName(): void
    {
        $db = $this->permittedDb();
        $request = $this->jsonRequest('POST', '/admin/api/products', $this->validBody(['name' => ['Array', 'injection']]));

        $response = $this->controller($request, $db)->apiStore();
        $body = json_decode($response->body(), true);

        self::assertSame(422, $response->status());
        self::assertArrayHasKey('name', $body['error']['fields']);
        self::assertFalse($db->wrote('INSERT INTO product'));
    }

    public function testUpdateWithoutPriceOrVatChangeSkipsPin(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'category_id' => 3, 'name' => 'Big Mac', 'description' => null, 'price_cents' => 590, 'size_cl' => null, 'base_product_id' => null, 'maxi_variant_product_id' => null, 'vat_rate' => 100, 'image_path' => null, 'is_available' => 1, 'display_order' => 1];
        $request = $this->jsonRequest('PUT', '/admin/api/products/5', $this->validBody(['name' => 'Renamed']));

        $response = $this->controller($request, $db)->apiUpdate(['id' => '5']);

        self::assertSame(200, $response->status());
        self::assertTrue($db->wrote('UPDATE product SET'));
        self::assertSame([], $db->auditActions());
    }

    public function testUpdatePriceChangeRequiresPin(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'category_id' => 3, 'name' => 'Big Mac', 'description' => null, 'price_cents' => 590, 'size_cl' => null, 'base_product_id' => null, 'maxi_variant_product_id' => null, 'vat_rate' => 100, 'image_path' => null, 'is_available' => 1, 'display_order' => 1];
        $request = $this->jsonRequest('PUT', '/admin/api/products/5', $this->validBody(['price_cents' => 620]));

        $response = $this->controller($request, $db)->apiUpdate(['id' => '5']);
        $body = json_decode($response->body(), true);

        self::assertSame(422, $response->status());
        self::assertSame('PIN_INVALID', $body['error']['code'] ?? null);
        self::assertFalse($db->wrote('UPDATE product SET'));
        self::assertSame(['pin.failed'], $db->auditActions());
    }

    public function testUpdatePriceChangeWithValidPinWritesAudit(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'category_id' => 3, 'name' => 'Big Mac', 'description' => null, 'price_cents' => 590, 'size_cl' => null, 'base_product_id' => null, 'maxi_variant_product_id' => null, 'vat_rate' => 100, 'image_path' => null, 'is_available' => 1, 'display_order' => 1];
        $this->actingPin($db);
        $request = $this->jsonRequest('PUT', '/admin/api/products/5', $this->validBody(['price_cents' => 620, 'pin_email' => 'e@e.fr', 'pin' => '4729']));

        $response = $this->controller($request, $db)->apiUpdate(['id' => '5']);

        self::assertSame(200, $response->status());
        self::assertTrue($db->wrote('UPDATE product SET'));
        self::assertSame(['product.update'], $db->auditActions());
    }

    public function testUpdateWithoutIsAvailableKeepsCurrentValue(): void
    {
        // PUT partiel (relecture point 6), meme regle que is_active sur
        // users/roles : is_available absent du corps NE rend PAS le produit
        // indisponible par omission.
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'category_id' => 3, 'name' => 'Big Mac', 'description' => null, 'price_cents' => 590, 'size_cl' => null, 'base_product_id' => null, 'maxi_variant_product_id' => null, 'vat_rate' => 100, 'image_path' => null, 'is_available' => 1, 'display_order' => 1];
        $body = $this->validBody(['name' => 'Renamed']);
        unset($body['is_available']);
        $request = $this->jsonRequest('PUT', '/admin/api/products/5', $body);

        $response = $this->controller($request, $db)->apiUpdate(['id' => '5']);

        self::assertSame(200, $response->status());
        $update = null;
        foreach ($db->writes as $write) {
            if (str_contains($write['sql'], 'UPDATE product SET category_id')) {
                $update = $write;
            }
        }
        self::assertNotNull($update);
        self::assertSame(1, $update['params']['available'] ?? null);
    }

    public function testUpdateRejectsStringFalseAsIsAvailable(): void
    {
        // Booleen JSON STRICT (relecture point 6) : la chaine "false" doit etre
        // refusee (422), jamais lue comme un booleen "truthy".
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'category_id' => 3, 'name' => 'Big Mac', 'description' => null, 'price_cents' => 590, 'size_cl' => null, 'base_product_id' => null, 'maxi_variant_product_id' => null, 'vat_rate' => 100, 'image_path' => null, 'is_available' => 1, 'display_order' => 1];
        $request = $this->jsonRequest('PUT', '/admin/api/products/5', $this->validBody(['is_available' => 'false']));

        $response = $this->controller($request, $db)->apiUpdate(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('UPDATE product SET'));
    }

    public function testDestroyRequiresPin(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'name' => 'Big Mac'];
        $request = $this->jsonRequest('DELETE', '/admin/api/products/5', []);

        $response = $this->controller($request, $db)->apiDestroy(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('DELETE FROM product'));
    }

    public function testDestroyWithValidPinDeletes(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'name' => 'Big Mac'];
        $this->actingPin($db);
        $request = $this->jsonRequest('DELETE', '/admin/api/products/5', ['pin_email' => 'e@e.fr', 'pin' => '4729']);

        $response = $this->controller($request, $db)->apiDestroy(['id' => '5']);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertSame('deleted', $body['data']['status']);
        self::assertSame(['product.delete'], $db->auditActions());
    }

    public function testDestroyReferencedProductReturns409(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'name' => 'Big Mac'];
        $this->actingPin($db);
        $db->failOnExecute = new \PDOException('fk', 23000);
        $request = $this->jsonRequest('DELETE', '/admin/api/products/5', ['pin_email' => 'e@e.fr', 'pin' => '4729']);

        $response = $this->controller($request, $db)->apiDestroy(['id' => '5']);

        self::assertSame(409, $response->status());
    }

    public function testDestroyRemovesTheProductImage(): void
    {
        $db = $this->permittedDb();
        $relative = $this->plantUploadedImage('products');
        $db->productRow = ['id' => 5, 'name' => 'Big Mac', 'image_path' => $relative];
        $this->actingPin($db);
        $request = $this->jsonRequest('DELETE', '/admin/api/products/5', ['pin_email' => 'e@e.fr', 'pin' => '4729']);

        $response = $this->controller($request, $db)->apiDestroy(['id' => '5']);

        self::assertSame(200, $response->status());
        self::assertFileDoesNotExist($this->uploadBaseDir . '/' . substr($relative, strlen('uploads/')));
    }

    public function testShowNotFoundReturns404(): void
    {
        $db = $this->permittedDb();
        $db->productRow = null;

        $response = $this->controller($this->get('/admin/api/products/9'), $db)->apiShow(['id' => '9']);

        self::assertSame(404, $response->status());
    }

    // --- move / recipe ---

    public function testMoveUpReordersWithinCategory(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 20, 'category_id' => 3, 'name' => 'Big Mac'];
        $db->reorderProductRow = ['category_id' => 3];
        $db->reorderCategoryIdsRows = [['id' => 10], ['id' => 20]];
        $request = $this->jsonRequest('POST', '/admin/api/products/20/move', ['direction' => 'up']);

        $response = $this->controller($request, $db)->apiMove(['id' => '20']);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertTrue($body['data']['moved']);
        self::assertTrue($db->wrote('UPDATE product SET display_order'));
    }

    public function testMoveNotFoundReturns404(): void
    {
        $db = $this->permittedDb();
        $db->productRow = null;
        $request = $this->jsonRequest('POST', '/admin/api/products/999/move', ['direction' => 'up']);

        $response = $this->controller($request, $db)->apiMove(['id' => '999']);

        self::assertSame(404, $response->status());
    }

    public function testMoveRejectsInvalidDirection(): void
    {
        $db = $this->permittedDb();
        $request = $this->jsonRequest('POST', '/admin/api/products/20/move', ['direction' => 'sideways']);

        $response = $this->controller($request, $db)->apiMove(['id' => '20']);

        self::assertSame(422, $response->status());
    }

    public function testRecipeShowReturnsComposition(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'name' => 'Big Mac'];
        $db->compositionRows = [
            ['ingredient_id' => 1, 'name' => 'Pain', 'quantity_normal' => 1, 'quantity_maxi' => 1, 'is_removable' => 1, 'is_addable' => 0, 'extra_price_cents' => 0],
        ];

        $response = $this->controller($this->get('/admin/api/products/5/recipe'), $db)->apiRecipeShow(['id' => '5']);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertCount(1, $body['data']['composition']);
    }

    public function testRecipeShowNotFoundReturns404(): void
    {
        $db = $this->permittedDb();
        $db->productRow = null;

        $response = $this->controller($this->get('/admin/api/products/9/recipe'), $db)->apiRecipeShow(['id' => '9']);

        self::assertSame(404, $response->status());
    }

    public function testRecipeSaveReplacesComposition(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'name' => 'Big Mac'];
        $db->ingredientRow = ['id' => 1, 'name' => 'Pain'];
        $request = $this->jsonRequest('PUT', '/admin/api/products/5/recipe', [
            'composition' => [
                ['ingredient_id' => 1, 'quantity_normal' => 1, 'quantity_maxi' => 1, 'extra_price_cents' => 0],
            ],
        ]);

        $response = $this->controller($request, $db)->apiRecipeSave(['id' => '5']);

        self::assertSame(200, $response->status());
        self::assertTrue($db->wrote('DELETE FROM product_ingredient') || $db->wrote('INSERT INTO product_ingredient'));
    }
}
