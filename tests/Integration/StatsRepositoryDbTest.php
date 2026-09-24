<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Catalogue\IngredientRepository;
use App\Catalogue\ProductRepository;
use App\Catalogue\StatsRepository;
use App\Core\Config;
use App\Core\Database;

/**
 * StatsRepository contre une vraie MariaDB (schema migre + seede). Auto-skip si
 * WAKDO_DB_TESTS != 1. Verifie les compteurs catalogue et la sante stock (bandes
 * RG-T21) sur des donnees reelles. Ingredient jetable (it-stat-*) nettoye en
 * tearDown (mouvements d'abord, FK RESTRICT).
 */
final class StatsRepositoryDbTest extends TestCase
{
    private Database $db;
    private string $ing = '';
    private string $product = '';
    private int $categoryId = 0;

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
        $suffix = bin2hex(random_bytes(4));
        $this->ing = 'it-stat-' . $suffix;
        $this->product = 'it-stat-prod-' . $suffix;
        $this->categoryId = (int) ($this->db->fetch('SELECT id FROM category ORDER BY id LIMIT 1')['id'] ?? 0);
    }

    protected function tearDown(): void
    {
        if ($this->product !== '') {
            $pid = (int) ($this->db->fetch('SELECT id FROM product WHERE name = :n', ['n' => $this->product])['id'] ?? 0);
            if ($pid > 0) {
                $this->db->execute('DELETE FROM product WHERE id = :id', ['id' => $pid]); // CASCADE product_ingredient
            }
        }
        if ($this->ing === '') {
            return;
        }
        $id = (int) ($this->db->fetch('SELECT id FROM ingredient WHERE name = :n', ['n' => $this->ing])['id'] ?? 0);
        if ($id > 0) {
            $this->db->execute('DELETE FROM stock_movement WHERE ingredient_id = :id', ['id' => $id]);
            $this->db->execute('DELETE FROM ingredient WHERE id = :id', ['id' => $id]);
        }
    }

    public function testCountsReflectSeededCatalogue(): void
    {
        $stats = new StatsRepository($this->db);
        $counts = $stats->counts();

        // Le seed pose un catalogue non vide (9 categories, 53 produits, 13 menus).
        self::assertGreaterThan(0, $counts['categories']['total']);
        self::assertGreaterThan(0, $counts['products']['total']);
        self::assertGreaterThan(0, $counts['menus']['total']);
        // 'available'/'active' borne par 'total'.
        self::assertLessThanOrEqual($counts['products']['total'], $counts['products']['available']);
        self::assertLessThanOrEqual($counts['categories']['total'], $counts['categories']['active']);
    }

    public function testProductsAvailableCountExcludesAutomaticRupture(): void
    {
        // Regression F40 (defauts-visibles.md, point 6 "Statistiques", capture 36) :
        // "58 produits, 58 disponibles" alors
        // que des produits etaient en rupture automatique (RG-T21, ingredient requis
        // sous la bande critique) -- le KPI ignorait la rupture calculee et ne
        // regardait que le flag is_available brut. Doit concorder avec la vue
        // produits par categorie (ProductController::byCategory), qui EXCLUT ces
        // produits du compte "commandables".
        $stats = new StatsRepository($this->db);
        $before = $stats->counts()['products'];

        $products = new ProductRepository($this->db);
        $ingredients = new IngredientRepository($this->db);
        $products->create([
            'category_id' => $this->categoryId, 'name' => $this->product, 'description' => null,
            'price_cents' => 590, 'size_cl' => null, 'base_product_id' => null,
            'maxi_variant_product_id' => null, 'vat_rate' => 100, 'image_path' => null,
            'is_available' => 1, 'display_order' => 99,
        ]);
        $pid = (int) ($this->db->fetch('SELECT id FROM product WHERE name = :n', ['n' => $this->product])['id'] ?? 0);
        // Ingredient requis (non retirable) SOUS la bande critique (3/100 <= 5%).
        $ingredients->create([
            'name' => $this->ing, 'unit' => 'portion', 'stock_quantity' => 3, 'stock_capacity' => 100,
            'pack_size' => 1, 'pack_label' => null, 'low_stock_pct' => 10, 'critical_stock_pct' => 5, 'is_active' => 1,
        ]);
        $iid = (int) ($this->db->fetch('SELECT id FROM ingredient WHERE name = :n', ['n' => $this->ing])['id'] ?? 0);
        $products->setComposition($pid, [[
            'ingredient_id' => $iid, 'quantity_normal' => 1, 'quantity_maxi' => 1,
            'is_removable' => 0, 'is_addable' => 0, 'extra_price_cents' => 0,
        ]]);

        $after = $stats->counts()['products'];

        // is_available=1 en base : le total et le flag brut montent tous les deux,
        // mais le produit est en rupture calculee -> 'available' ne doit PAS suivre.
        self::assertSame($before['total'] + 1, $after['total']);
        self::assertSame($before['available'], $after['available']);
        self::assertContains($pid, $products->autoUnavailableIds());
    }

    public function testStockHealthClassifiesCriticalIngredient(): void
    {
        $ingredients = new IngredientRepository($this->db);
        // Ingredient actif sous le seuil critique (2/100 <= 5%).
        $ingredients->create([
            'name' => $this->ing, 'unit' => 'portion', 'stock_quantity' => 2, 'stock_capacity' => 100,
            'pack_size' => 1, 'pack_label' => null, 'low_stock_pct' => 10, 'critical_stock_pct' => 5, 'is_active' => 1,
        ]);

        $health = (new StatsRepository($this->db))->stockHealth();

        self::assertGreaterThanOrEqual(1, $health['bands']['critical']);
        // L'ingredient apparait dans la liste d'alerte (bas/critique).
        $names = array_map(static fn (array $a): string => (string) $a['name'], $health['alerts']);
        self::assertContains($this->ing, $names);
        // Le total des bandes = nb d'ingredients actifs.
        $sum = $health['bands']['normal'] + $health['bands']['low'] + $health['bands']['critical'];
        self::assertSame($health['active_total'], $sum);
    }
}
