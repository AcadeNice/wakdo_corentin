<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\SessionManager;
use App\Catalogue\IngredientRepository;
use App\Catalogue\ProductRepository;
use App\Controllers\ProductController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;

/**
 * Reproduit CONTRE UNE VRAIE MARIADB (role, permissions, utilisateur, produit et
 * recette reels) le scenario de la relecture adverse n°2 (2026-09-26, verdict
 * "changes", point obligatoire) : la section Composition du FORMULAIRE PRODUIT
 * (pas l'import CSV, deja couvert par ProductImportAuthorizationDbTest) permettait
 * a un role avec `product.create` + `product.read` + `product.update` -- SANS
 * `ingredient.manage` -- de VIDER la recette d'un produit existant ("Big Tasty",
 * 7 lignes -> 0) via `ProductController::update()`, alors que la page Recette
 * dediee lui renvoie 403 pour le meme effet.
 *
 * Auto-skip : ne s'execute que si WAKDO_DB_TESTS=1 ET base joignable.
 */
final class TestRestrictedProductControllerForm extends ProductController
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
}

final class ProductFormRecipeAuthorizationDbTest extends TestCase
{
    private Database $db;
    private int $categoryId = 0;
    private int $roleId = 0;
    private int $userId = 0;
    private int $productId = 0;
    /** @var list<int> */
    private array $ingredientIds = [];
    private string $productName = '';
    private SessionManager $session;
    private string $csrfToken = '';

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

        $this->db->execute(
            'INSERT INTO category (name, slug, display_order, is_active) VALUES (:n, :s, 999, 1)',
            ['n' => 'it-formrecipe-cat-' . $suffix, 's' => 'it-formrecipe-cat-' . $suffix],
        );
        $this->categoryId = (int) ($this->db->fetch('SELECT id FROM category WHERE name = :n', ['n' => 'it-formrecipe-cat-' . $suffix])['id'] ?? 0);

        // Role JETABLE : EXACTEMENT product.create + product.read + product.update.
        // PAS ingredient.manage -- c'est precisement le role du scenario rejoue.
        $this->db->execute(
            'INSERT INTO role (code, label, is_active) VALUES (:code, :label, 1)',
            ['code' => 'it-formrecipe-role-' . $suffix, 'label' => 'IT Form Recipe Restreint'],
        );
        $this->roleId = (int) ($this->db->fetch('SELECT LAST_INSERT_ID() AS id')['id'] ?? 0);
        foreach (['product.create', 'product.read', 'product.update'] as $code) {
            $this->db->execute(
                'INSERT INTO role_permission (role_id, permission_id) SELECT :rid, id FROM permission WHERE code = :pc',
                ['rid' => $this->roleId, 'pc' => $code],
            );
        }

        $hasher = new PasswordHasher(new Config());
        $email = 'it-formrecipe-' . $suffix . '@wakdo.invalid';
        $this->db->execute(
            'INSERT INTO user (email, password_hash, first_name, last_name, role_id, is_active) '
            . 'VALUES (:email, :pwd, :fn, :ln, :role, 1)',
            ['email' => $email, 'pwd' => $hasher->hash('ItFormRecipe1'), 'fn' => 'IT', 'ln' => 'FormRecipe', 'role' => $this->roleId],
        );
        $this->userId = (int) ($this->db->fetch('SELECT id FROM user WHERE email = :e', ['e' => $email])['id'] ?? 0);

        // Produit EXISTANT ("Big Tasty") avec une recette de 7 lignes, comme
        // dans le scenario rejoue par la relecture.
        $this->productName = 'it-formrecipe-prod-' . $suffix;
        $products = new ProductRepository($this->db);
        $ingredients = new IngredientRepository($this->db);
        $products->create([
            'category_id' => $this->categoryId, 'name' => $this->productName,
            'description' => 'Description originale', 'price_cents' => 590, 'size_cl' => null,
            'base_product_id' => null, 'maxi_variant_product_id' => null, 'vat_rate' => 100,
            'image_path' => null, 'is_available' => 1, 'display_order' => 5,
        ]);
        $this->productId = (int) ($this->db->fetch('SELECT id FROM product WHERE name = :n AND category_id = :c', ['n' => $this->productName, 'c' => $this->categoryId])['id'] ?? 0);

        $lines = [];
        for ($i = 1; $i <= 7; $i++) {
            $name = 'it-formrecipe-ing-' . $i . '-' . $suffix;
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

    /**
     * @param array<string, string> $form
     */
    private function postForm(array $form): Request
    {
        return new Request(
            'POST',
            '/admin/products/' . $this->productId,
            [],
            ['content-type' => 'application/x-www-form-urlencoded'],
            http_build_query($form),
            '203.0.113.5',
        );
    }

    public function testRoleWithProductUpdateButNotIngredientManageCannotEmptyExistingRecipeViaForm(): void
    {
        $form = [
            '_csrf' => $this->csrfToken,
            'category_id' => (string) $this->categoryId,
            'name' => $this->productName,
            'price_cents' => '5,90', // identique au produit existant : isole de la garde PIN
            'vat_rate' => '100',
            'display_order' => '5',
            'is_available' => '1',
            // Recette VIDEE (le scenario exact rejoue : "Big Tasty", 7 lignes -> 0).
            'composition_json' => '[]',
        ];

        $controller = new TestRestrictedProductControllerForm($this->postForm($form), new Config(), $this->db, $this->session);
        $response = $controller->update(['id' => (string) $this->productId]);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('droit de gérer les ingrédients', $response->body());

        $compositionAfter = $this->db->fetchAll('SELECT ingredient_id FROM product_ingredient WHERE product_id = :id', ['id' => $this->productId]);
        self::assertCount(7, $compositionAfter, 'La recette (7 lignes) ne doit pas avoir été vidée.');
    }

    public function testDedicatedRecipePageStillRefusesTheSameRoleWith403(): void
    {
        // Non-regression : la page Recette dediee, elle, refusait deja (guard
        // ingredient.manage au niveau de la route). Toujours vrai apres le
        // correctif -- les deux chemins convergent vers le meme refus.
        $controller = new TestRestrictedProductControllerForm(
            new Request('GET', '/admin/products/' . $this->productId . '/recipe', [], [], '', '203.0.113.5'),
            new Config(),
            $this->db,
            $this->session,
        );

        $response = $controller->recipeForm(['id' => (string) $this->productId]);

        self::assertSame(403, $response->status());
    }
}
