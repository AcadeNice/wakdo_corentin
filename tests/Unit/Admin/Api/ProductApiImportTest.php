<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin\Api;

use PHPUnit\Framework\TestCase;
use App\Auth\Authorizer;
use App\Auth\Csrf;
use App\Auth\SessionGuard;
use App\Auth\SessionManager;
use App\Controllers\Admin\Api\ProductApiController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\Request;
use App\Tests\Unit\Admin\ImportFakeDatabase;

// Reutilise le double ImportFakeDatabase (delegue a un FakeDatabase, intercepte
// les requetes de resolution par NOM/correspondance de l'import CSV) deja
// ecrit pour la route HTML -- meme double, meme comportement a verifier cote API.
require_once __DIR__ . '/../ProductImportControllerTest.php';

final class TestProductImportApiController extends ProductApiController
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
}

/**
 * Relecture adverse (2026-09-26) : `apiImportRun` n'exigeait que `product.create`,
 * alors que l'import peut mettre a jour un produit existant et remplacer sa
 * recette. Ces tests reproduisent le meme scenario que la version HTML
 * (tests/Unit/Admin/ProductImportControllerTest.php) mais via l'API JSON, pour
 * prouver que `guardImportAuthorizations()` (herite de ProductController) est
 * bien applique la aussi, pas seulement documente comme partage.
 */
final class ProductApiImportTest extends TestCase
{
    private SessionManager $session;
    private string $csrf = '';

    protected function setUp(): void
    {
        $this->session = new SessionManager(new Config(), true);
        $now = time();
        $this->session->set('user_id', 1);
        $this->session->set('role_id', 1);
        $this->session->set('logged_in_at', $now - 100);
        $this->session->set('last_activity', $now - 50);
        $this->csrf = Csrf::token($this->session);
    }

    private function db(): ImportFakeDatabase
    {
        $inner = new \App\Tests\Support\FakeDatabase();
        $inner->guardUserRow = ['is_active' => 1];

        return new ImportFakeDatabase($inner);
    }

    private function controller(Request $request, ImportFakeDatabase $db): TestProductImportApiController
    {
        return new TestProductImportApiController($request, new Config(), new Database(new Config()), $this->session, $db);
    }

    private function jsonRequest(string $csv): Request
    {
        $body = (string) json_encode(['csv' => $csv]);

        return new Request(
            'POST',
            '/admin/api/products/import',
            [],
            ['content-type' => 'application/json', 'x-csrf-token' => $this->csrf],
            $body,
            '203.0.113.5',
        );
    }

    private function header(): string
    {
        return implode(';', \App\Catalogue\ProductImportService::COLUMNS);
    }

    public function testApiImportRunBlocksUpdateOfExistingProductWithoutProductUpdatePermission(): void
    {
        $db = $this->db();
        $db->inner->grantedCodes = ['product.create', 'product.read']; // PAS product.update, PAS ingredient.manage
        $db->inner->categoryRow = ['id' => 3, 'name' => 'Burgers'];
        $db->productMatch = ['id' => 77, 'price_cents' => 690]; // meme prix : isole de la garde PIN
        $db->productCurrentFields = ['description' => 'Ancienne description', 'price_cents' => 690, 'vat_rate' => 100, 'size_cl' => null, 'is_available' => 1, 'display_order' => 3, 'image_path' => null];
        $csv = $this->header() . "\r\n3;Le 280;Nouvelle description;6,90;10;;oui;;;;;\r\n";

        $response = $this->controller($this->jsonRequest($csv), $db)->apiImportRun([]);
        $payload = json_decode($response->body(), true);

        self::assertSame(422, $response->status());
        self::assertSame('VALIDATION_ERROR', $payload['error']['code'] ?? null);
        self::assertStringContainsString('droit de modifier les produits', (string) json_encode($payload));
        self::assertFalse($db->inner->wrote('UPDATE product SET'));
        self::assertFalse($db->inner->wrote('DELETE FROM product_ingredient'));
    }

    public function testApiImportRunAppliesUpdateAndReplacesRecipeWithFullPermissions(): void
    {
        $db = $this->db();
        $db->inner->canResult = true; // toutes permissions accordees
        $db->inner->categoryRow = ['id' => 3, 'name' => 'Burgers'];
        $db->productMatch = ['id' => 77, 'price_cents' => 690];
        $db->productCurrentFields = ['description' => 'Ancienne description', 'price_cents' => 690, 'vat_rate' => 100, 'size_cl' => null, 'is_available' => 1, 'display_order' => 3, 'image_path' => null];
        $csv = $this->header() . "\r\n3;Le 280;Nouvelle description;6,90;10;;oui;;;;;\r\n";

        $response = $this->controller($this->jsonRequest($csv), $db)->apiImportRun([]);

        self::assertSame(200, $response->status());
        self::assertTrue($db->inner->wrote('UPDATE product SET'));
        self::assertTrue($db->inner->wrote('DELETE FROM product_ingredient'));
    }
}
