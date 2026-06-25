<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Catalogue\CategoryRepository;
use App\Catalogue\MenuRepository;
use App\Catalogue\ProductRepository;
use App\Core\Config;
use App\Core\Database;

/**
 * Filtres de lecture catalogue borne contre une vraie MariaDB (schema migre).
 * Auto-skip si WAKDO_DB_TESTS != 1. Verifie que la borne ne voit que le
 * commandable : categorie active, produit disponible ET en categorie active.
 * Fixtures uniques (it-cat-<suffix>* / IT Prod <suffix>*), nettoyage FK-safe
 * (produits avant categories) en tearDown.
 */
final class CatalogueReadDbTest extends TestCase
{
    private Database $db;
    private string $suffix = '';

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

        $this->suffix = bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->suffix === '') {
            return;
        }

        // Ordre FK : menus (burger_product_id / category_id RESTRICT) d'abord -- le
        // delete cascade menu_slot + menu_slot_option ; puis produits, puis categories.
        $this->db->execute('DELETE FROM menu WHERE name LIKE :m', ['m' => 'IT Menu ' . $this->suffix . '%']);
        $this->db->execute('DELETE FROM product WHERE name LIKE :p', ['p' => 'IT Prod ' . $this->suffix . '%']);
        $this->db->execute('DELETE FROM category WHERE slug LIKE :s', ['s' => 'it-cat-' . $this->suffix . '%']);
    }

    public function testCatalogueReadFiltersToOrderable(): void
    {
        $categories = new CategoryRepository($this->db);
        $products = new ProductRepository($this->db);

        $activeSlug = 'it-cat-' . $this->suffix . '-a';
        $inactiveSlug = 'it-cat-' . $this->suffix . '-b';

        $categories->create(['name' => 'IT Cat A ' . $this->suffix, 'slug' => $activeSlug, 'image_path' => null, 'display_order' => 90, 'is_active' => 1]);
        $categories->create(['name' => 'IT Cat B ' . $this->suffix, 'slug' => $inactiveSlug, 'image_path' => null, 'display_order' => 91, 'is_active' => 0]);

        $activeCatId = $this->idOfCategory($activeSlug);
        $inactiveCatId = $this->idOfCategory($inactiveSlug);
        self::assertGreaterThan(0, $activeCatId);
        self::assertGreaterThan(0, $inactiveCatId);

        $availName = 'IT Prod ' . $this->suffix . ' avail';
        $hiddenName = 'IT Prod ' . $this->suffix . ' hidden';
        $inactCatName = 'IT Prod ' . $this->suffix . ' inactcat';

        $products->create($this->productData($availName, $activeCatId, 1));
        $products->create($this->productData($hiddenName, $activeCatId, 0));
        $products->create($this->productData($inactCatName, $inactiveCatId, 1));

        // Categories : la borne ne voit que l'active, sans le flag is_active.
        $catSlugs = array_map(static fn (array $r): string => (string) ($r['slug'] ?? ''), $categories->activeForCatalogue());
        self::assertContains($activeSlug, $catSlugs);
        self::assertNotContains($inactiveSlug, $catSlugs);

        $sample = $categories->activeForCatalogue()[0] ?? [];
        self::assertArrayNotHasKey('is_active', $sample);

        // Produits : seul le disponible-en-categorie-active remonte.
        $names = array_map(static fn (array $r): string => (string) ($r['name'] ?? ''), $products->availableForCatalogue());
        self::assertContains($availName, $names);
        self::assertNotContains($hiddenName, $names);   // is_available = 0
        self::assertNotContains($inactCatName, $names); // categorie inactive

        // availableForCatalogue n'expose pas vat_rate.
        $availRow = $this->rowByName($products->availableForCatalogue(), $availName);
        self::assertNotNull($availRow);
        self::assertArrayNotHasKey('vat_rate', $availRow);

        // findForCatalogue : le disponible OK ; indispo / categorie inactive / id 0 -> null.
        $availId = $this->idOfProduct($availName);
        $hiddenId = $this->idOfProduct($hiddenName);
        $inactCatProdId = $this->idOfProduct($inactCatName);

        self::assertNotNull($products->findForCatalogue($availId));
        self::assertNull($products->findForCatalogue($hiddenId));
        self::assertNull($products->findForCatalogue($inactCatProdId));
        self::assertNull($products->findForCatalogue(0));
    }

    public function testMenuReadFiltersAndSlots(): void
    {
        $categories = new CategoryRepository($this->db);
        $products = new ProductRepository($this->db);
        $menus = new MenuRepository($this->db);

        $activeSlug = 'it-cat-' . $this->suffix . '-ma';
        $inactiveSlug = 'it-cat-' . $this->suffix . '-mb';
        $categories->create(['name' => 'IT Cat MA ' . $this->suffix, 'slug' => $activeSlug, 'image_path' => null, 'display_order' => 92, 'is_active' => 1]);
        $categories->create(['name' => 'IT Cat MB ' . $this->suffix, 'slug' => $inactiveSlug, 'image_path' => null, 'display_order' => 93, 'is_active' => 0]);
        $activeCatId = $this->idOfCategory($activeSlug);
        $inactiveCatId = $this->idOfCategory($inactiveSlug);

        // Burger impose + un produit d'option (FK RESTRICT), tous deux disponibles.
        $products->create($this->productData('IT Prod ' . $this->suffix . ' burger', $activeCatId, 1));
        $products->create($this->productData('IT Prod ' . $this->suffix . ' opt', $activeCatId, 1));
        $burgerId = $this->idOfProduct('IT Prod ' . $this->suffix . ' burger');
        $optId = $this->idOfProduct('IT Prod ' . $this->suffix . ' opt');

        $slots = [
            ['name' => 'Boisson', 'slot_type' => 'drink', 'is_required' => 1, 'display_order' => 1, 'options' => [$optId]],
            // Slot sans option eligible : doit remonter (LEFT JOIN) avec une liste vide.
            ['name' => 'Extra', 'slot_type' => 'extra', 'is_required' => 0, 'display_order' => 2, 'options' => []],
        ];

        $availName = 'IT Menu ' . $this->suffix . ' avail';
        $hiddenName = 'IT Menu ' . $this->suffix . ' hidden';
        $inactCatName = 'IT Menu ' . $this->suffix . ' inactcat';

        $availMenuId = $menus->create($this->menuData($availName, $activeCatId, $burgerId, 1), $slots);
        $menus->create($this->menuData($hiddenName, $activeCatId, $burgerId, 0), []);
        $menus->create($this->menuData($inactCatName, $inactiveCatId, $burgerId, 1), []);

        // Liste : seul le disponible-en-categorie-active remonte ; projection enrichie
        // (description/image_path) ; sans le flag is_available.
        $names = array_map(static fn (array $r): string => (string) ($r['name'] ?? ''), $menus->availableForCatalogue());
        self::assertContains($availName, $names);
        self::assertNotContains($hiddenName, $names);   // is_available = 0
        self::assertNotContains($inactCatName, $names); // categorie inactive

        $availRow = $this->rowByName($menus->availableForCatalogue(), $availName);
        self::assertNotNull($availRow);
        self::assertArrayHasKey('description', $availRow);
        self::assertArrayHasKey('image_path', $availRow);
        self::assertArrayNotHasKey('is_available', $availRow);

        // findForCatalogue : disponible OK, indisponible -> null.
        self::assertNotNull($menus->findForCatalogue($availMenuId));
        self::assertNull($menus->findForCatalogue($this->idOfMenu($hiddenName)));

        // Slots groupes : le slot drink porte son option, le slot extra (sans option)
        // remonte avec une liste VIDE (et non [0]). Ordre par display_order.
        $slotsOut = $menus->slotsWithOptions($availMenuId);
        self::assertCount(2, $slotsOut);
        self::assertSame('drink', $slotsOut[0]['slot_type']);
        self::assertSame([$optId], $slotsOut[0]['option_product_ids']);
        self::assertSame('extra', $slotsOut[1]['slot_type']);
        self::assertSame([], $slotsOut[1]['option_product_ids']);
    }

    private function idOfCategory(string $slug): int
    {
        return (int) ($this->db->fetch('SELECT id FROM category WHERE slug = :s', ['s' => $slug])['id'] ?? 0);
    }

    private function idOfMenu(string $name): int
    {
        return (int) ($this->db->fetch('SELECT id FROM menu WHERE name = :n', ['n' => $name])['id'] ?? 0);
    }

    private function idOfProduct(string $name): int
    {
        return (int) ($this->db->fetch('SELECT id FROM product WHERE name = :n', ['n' => $name])['id'] ?? 0);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function rowByName(array $rows, string $name): ?array
    {
        foreach ($rows as $row) {
            if (($row['name'] ?? null) === $name) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array{category_id: int, name: string, description: ?string, price_cents: int, size_cl: ?int, base_product_id: ?int, maxi_variant_product_id: ?int, vat_rate: int, image_path: ?string, is_available: int, display_order: int}
     */
    private function productData(string $name, int $categoryId, int $available): array
    {
        return [
            'category_id' => $categoryId,
            'name' => $name,
            'description' => null,
            'price_cents' => 500,
            'size_cl' => null,
            'base_product_id' => null,
            'maxi_variant_product_id' => null,
            'vat_rate' => 100,
            'image_path' => null,
            'is_available' => $available,
            'display_order' => 10,
        ];
    }

    /**
     * @return array{category_id: int, burger_product_id: int, name: string, price_normal_cents: int, price_maxi_cents: int, is_available: int, display_order: int}
     */
    private function menuData(string $name, int $categoryId, int $burgerId, int $available): array
    {
        return [
            'category_id' => $categoryId,
            'burger_product_id' => $burgerId,
            'name' => $name,
            'price_normal_cents' => 990,
            'price_maxi_cents' => 1190,
            'is_available' => $available,
            'display_order' => 10,
        ];
    }
}
