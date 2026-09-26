<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\SessionManager;
use App\Catalogue\IngredientRepository;
use App\Catalogue\ProductImportService;
use App\Catalogue\ProductRepository;
use App\Controllers\ProductController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;

/**
 * Reproduit CONTRE UNE VRAIE MARIADB (role, permissions, utilisateur, produit et
 * recette reels -- rien de simule) le scenario de la relecture adverse
 * (2026-09-26, verdict "block", point 1 "le plus grave") : un role qui ne detient
 * QUE `product.create` (+ `product.read`) reussissait a reecrire un produit deja
 * existant et a vider sa recette via l'import CSV, sans jamais recevoir de refus.
 *
 * Ce test cree un role et un utilisateur JETABLES avec exactement ces deux
 * permissions (aucune autre), un produit existant avec une recette de 3 lignes,
 * puis rejoue l'exploit tel quel : un CSV qui renomme la description du produit
 * ET ne porte aucune ligne ingredient (viderait la recette). Avant le correctif,
 * ceci ecrivait en base sans le moindre refus. Assertion : `importPreview()`
 * bloque AVANT toute ecriture, le produit et sa recette restent inchanges.
 *
 * Auto-skip : ne s'execute que si WAKDO_DB_TESTS=1 ET base joignable (meme garde
 * que les autres tests Integration/*DbTest.php).
 */
final class TestRestrictedProductController extends ProductController
{
    public function __construct(
        Request $request,
        Config $config,
        Database $database,
        private readonly SessionManager $testSession,
    ) {
        parent::__construct($request, $config, $database);
    }

    protected function sessionManager(): SessionManager
    {
        return $this->testSession;
    }

    protected function isUploadedFile(string $path): bool
    {
        return is_file($path);
    }
}

final class ProductImportAuthorizationDbTest extends TestCase
{
    private Database $db;
    private int $categoryId = 0;
    private int $roleId = 0;
    private int $userId = 0;
    private int $productId = 0;
    /** @var list<int> */
    private array $ingredientIds = [];
    private string $productName = '';
    private string $csrfToken = '';
    private SessionManager $session;
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        if (getenv('WAKDO_DB_TESTS') !== '1') {
            self::markTestSkipped('Tests DB desactives (definir WAKDO_DB_TESTS=1 + DB_*).');
        }

        $this->db = new Database(new Config());

        try {
            $this->db->fetch('SELECT 1');
        } catch (Throwable $exception) {
            self::markTestSkipped('Base injoignable: ' . $exception->getMessage());
        }

        putenv('SESSION_LIFETIME_IDLE=14400');
        putenv('SESSION_LIFETIME_ABSOLUTE=36000');

        $suffix = bin2hex(random_bytes(4));

        // Categorie jetable (meme convention que ProductImportServiceDbTest).
        $this->db->execute(
            'INSERT INTO category (name, slug, display_order, is_active) VALUES (:n, :s, 999, 1)',
            ['n' => 'it-auth-cat-' . $suffix, 's' => 'it-auth-cat-' . $suffix],
        );
        $this->categoryId = (int) ($this->db->fetch('SELECT id FROM category WHERE name = :n', ['n' => 'it-auth-cat-' . $suffix])['id'] ?? 0);

        // Role JETABLE avec EXACTEMENT product.create + product.read -- pas
        // product.update, pas ingredient.manage. Actif des la creation (on
        // veut atteindre la logique de l'import, pas la garde de session).
        $this->db->execute(
            'INSERT INTO role (code, label, is_active) VALUES (:code, :label, 1)',
            ['code' => 'it-auth-role-' . $suffix, 'label' => 'IT Import Restreint'],
        );
        $this->roleId = (int) ($this->db->fetch('SELECT LAST_INSERT_ID() AS id')['id'] ?? 0);
        foreach (['product.create', 'product.read'] as $code) {
            $this->db->execute(
                'INSERT INTO role_permission (role_id, permission_id) SELECT :rid, id FROM permission WHERE code = :pc',
                ['rid' => $this->roleId, 'pc' => $code],
            );
        }

        // Utilisateur JETABLE porteur de ce role.
        $hasher = new PasswordHasher(new Config());
        $this->db->execute(
            'INSERT INTO user (email, password_hash, first_name, last_name, role_id, is_active) '
            . 'VALUES (:email, :pwd, :fn, :ln, :role, 1)',
            [
                'email' => 'it-auth-' . $suffix . '@wakdo.invalid',
                'pwd' => $hasher->hash('ItAuthPass1'),
                'fn' => 'IT', 'ln' => 'Auth',
                'role' => $this->roleId,
            ],
        );
        $this->userId = (int) ($this->db->fetch('SELECT id FROM user WHERE email = :e', ['e' => 'it-auth-' . $suffix . '@wakdo.invalid'])['id'] ?? 0);

        // Produit EXISTANT avec une recette de 3 lignes (mirroir du "Le 280" a
        // 6 lignes de la relecture -- le nombre exact importe peu, seul compte
        // que la recette ne soit pas vide avant l'exploit).
        $this->productName = 'it-auth-prod-' . $suffix;
        $products = new ProductRepository($this->db);
        $ingredients = new IngredientRepository($this->db);
        $products->create([
            'category_id' => $this->categoryId, 'name' => $this->productName,
            'description' => 'Description originale', 'price_cents' => 690, 'size_cl' => null,
            'base_product_id' => null, 'maxi_variant_product_id' => null, 'vat_rate' => 100,
            'image_path' => null, 'is_available' => 1, 'display_order' => 5,
        ]);
        $this->productId = (int) ($this->db->fetch('SELECT id FROM product WHERE name = :n AND category_id = :c', ['n' => $this->productName, 'c' => $this->categoryId])['id'] ?? 0);

        $lines = [];
        foreach (['pain', 'steak', 'cheddar'] as $ingSuffix) {
            $name = 'it-auth-ing-' . $ingSuffix . '-' . $suffix;
            $ingredients->create([
                'name' => $name, 'unit' => 'unite', 'stock_quantity' => 50, 'stock_capacity' => 100,
                'pack_size' => 1, 'pack_label' => null, 'low_stock_pct' => 10, 'critical_stock_pct' => 5, 'is_active' => 1,
            ]);
            $ingId = (int) ($this->db->fetch('SELECT id FROM ingredient WHERE name = :n', ['n' => $name])['id'] ?? 0);
            $this->ingredientIds[] = $ingId;
            $lines[] = ['ingredient_id' => $ingId, 'quantity_normal' => 1, 'quantity_maxi' => 1, 'is_removable' => 0, 'is_addable' => 0, 'extra_price_cents' => 0];
        }
        $products->setComposition($this->productId, $lines);

        $this->session = new SessionManager(new Config(), true);
        $now = time();
        $this->session->set('user_id', $this->userId);
        $this->session->set('role_id', $this->roleId);
        $this->session->set('logged_in_at', $now - 100);
        $this->session->set('last_activity', $now - 50);
        $this->csrfToken = Csrf::token($this->session);
    }

    protected function tearDown(): void
    {
        putenv('SESSION_LIFETIME_IDLE');
        putenv('SESSION_LIFETIME_ABSOLUTE');
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        if ($this->productId > 0) {
            $this->db->execute('DELETE FROM product WHERE id = :id', ['id' => $this->productId]); // CASCADE product_ingredient
        }
        foreach ($this->ingredientIds as $id) {
            $this->db->execute('DELETE FROM stock_movement WHERE ingredient_id = :id', ['id' => $id]);
            $this->db->execute('DELETE FROM ingredient WHERE id = :id', ['id' => $id]);
        }
        if ($this->userId > 0) {
            $this->db->execute('DELETE FROM user WHERE id = :id', ['id' => $this->userId]);
        }
        if ($this->roleId > 0) {
            $this->db->execute('DELETE FROM role_permission WHERE role_id = :id', ['id' => $this->roleId]);
            $this->db->execute('DELETE FROM role WHERE id = :id', ['id' => $this->roleId]);
        }
        if ($this->categoryId > 0) {
            $this->db->execute('DELETE FROM category WHERE id = :id', ['id' => $this->categoryId]);
        }
    }

    private function postWithCsvFile(string $csv): Request
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wakdo_it_auth_csv_');
        file_put_contents($tmp, $csv);
        $this->tempFiles[] = $tmp;

        return new Request(
            'POST',
            '/admin/products/import/preview',
            [],
            ['content-type' => 'multipart/form-data; boundary=----wakdoITBoundary'],
            '',
            '203.0.113.5',
            ['csv_file' => ['name' => 'produits.csv', 'type' => 'text/csv', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($csv)]],
            ['_csrf' => $this->csrfToken],
        );
    }

    public function testRoleWithOnlyProductCreateCannotUpdateExistingProductNorEmptyItsRecipe(): void
    {
        $header = implode(';', ProductImportService::COLUMNS);
        // Meme prix (690) que le produit existant -- isole le test de la garde
        // PIN (hors sujet ici) : le SEUL changement demande est la description,
        // et le fichier ne porte aucune ligne ingredient (viderait la recette).
        $csv = $header . "\r\n{$this->categoryId};{$this->productName};Description modifiée par l'exploit;6,90;10;;oui;;;;;\r\n";

        $controller = new TestRestrictedProductController(
            $this->postWithCsvFile($csv),
            new Config(),
            $this->db,
            $this->session,
        );

        $response = $controller->importPreview();

        // Le refus doit apparaitre a l'ecran (pas de 500, pas un 403 nu), et
        // ne JAMAIS avoir laisse passer le formulaire de confirmation.
        self::assertSame(200, $response->status());
        self::assertStringContainsString('droit de modifier les produits', $response->body());
        self::assertStringNotContainsString('Confirmer l\'import', $response->body());

        // Preuve la plus importante : en BASE REELLE, rien n'a bougé.
        $productAfter = $this->db->fetch('SELECT description, price_cents FROM product WHERE id = :id', ['id' => $this->productId]);
        self::assertNotNull($productAfter);
        self::assertSame('Description originale', $productAfter['description'], 'La description ne doit pas avoir été réécrite.');

        $compositionAfter = $this->db->fetchAll('SELECT ingredient_id FROM product_ingredient WHERE product_id = :id', ['id' => $this->productId]);
        self::assertCount(3, $compositionAfter, 'La recette (3 lignes) ne doit pas avoir été vidée.');
    }
}
