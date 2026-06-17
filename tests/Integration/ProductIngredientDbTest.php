<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PDOException;
use PHPUnit\Framework\TestCase;
use Throwable;
use App\Catalogue\IngredientRepository;
use App\Catalogue\ProductRepository;
use App\Core\Config;
use App\Core\Database;

/**
 * Composition produit (product_ingredient) contre une vraie MariaDB (schema migre
 * + seede). Auto-skip si WAKDO_DB_TESTS != 1. Produit (it-prod-*) et ingredients
 * (it-ping-*) jetables. Couvre : persistance + delete-and-reinsert de la recette,
 * CASCADE a la suppression du produit (FK product_id), RESTRICT a la suppression
 * d'un ingredient reference (FK ingredient_id), et la disponibilite calculee RG-T21
 * (autoUnavailableIds + isOrderable) sur des donnees reelles.
 *
 * teardown FK-safe : on supprime le produit (CASCADE emporte sa composition, ce
 * qui libere les ingredients de la FK RESTRICT) PUIS les ingredients.
 */
final class ProductIngredientDbTest extends TestCase
{
    private Database $db;
    private string $product = '';
    private string $ingA = '';
    private string $ingB = '';
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

        $this->categoryId = (int) ($this->db->fetch('SELECT id FROM category ORDER BY id LIMIT 1')['id'] ?? 0);
        $suffix = bin2hex(random_bytes(4));
        $this->product = 'it-prod-' . $suffix;
        $this->ingA = 'it-ping-a-' . $suffix;
        $this->ingB = 'it-ping-b-' . $suffix;
    }

    protected function tearDown(): void
    {
        if ($this->product === '') {
            return;
        }
        $pid = (int) ($this->db->fetch('SELECT id FROM product WHERE name = :n', ['n' => $this->product])['id'] ?? 0);
        if ($pid > 0) {
            $this->db->execute('DELETE FROM product WHERE id = :id', ['id' => $pid]); // CASCADE product_ingredient
        }
        // Ordre FK-safe : retirer les mouvements de stock (FK RESTRICT) avant
        // l'ingredient (le test de dispo cree un restock -> stock_movement).
        foreach ([$this->ingA, $this->ingB] as $name) {
            $iid = (int) ($this->db->fetch('SELECT id FROM ingredient WHERE name = :n', ['n' => $name])['id'] ?? 0);
            if ($iid > 0) {
                $this->db->execute('DELETE FROM stock_movement WHERE ingredient_id = :id', ['id' => $iid]);
                $this->db->execute('DELETE FROM ingredient WHERE id = :id', ['id' => $iid]);
            }
        }
    }

    public function testSetCompositionPersistsAndReplaces(): void
    {
        $products = new ProductRepository($this->db);
        $ingredients = new IngredientRepository($this->db);
        $pid = $this->createProduct($products);
        $iaId = $this->createIngredient($ingredients, $this->ingA, 50);
        $ibId = $this->createIngredient($ingredients, $this->ingB, 50);

        self::assertTrue($products->ingredientExists($iaId));
        self::assertFalse($products->ingredientExists(0));

        $products->setComposition($pid, [
            $this->line($iaId, ['quantity_normal' => 2, 'quantity_maxi' => 3, 'is_removable' => 1, 'extra_price_cents' => 50]),
        ]);

        $composition = $products->composition($pid);
        self::assertCount(1, $composition);
        self::assertSame($iaId, (int) $composition[0]['ingredient_id']);
        self::assertSame($this->ingA, (string) $composition[0]['ingredient_name']); // JOIN ingredient
        self::assertSame(2, (int) $composition[0]['quantity_normal']);
        self::assertSame(3, (int) $composition[0]['quantity_maxi']);
        self::assertSame(1, (int) $composition[0]['is_removable']);
        self::assertSame(50, (int) $composition[0]['extra_price_cents']);
        self::assertSame(1, $products->compositionCount($pid));

        // Delete-and-reinsert : la nouvelle composition REMPLACE l'ancienne.
        $products->setComposition($pid, [$this->line($ibId)]);
        $replaced = $products->composition($pid);
        self::assertCount(1, $replaced);
        self::assertSame($ibId, (int) $replaced[0]['ingredient_id']);
    }

    public function testProductDeleteCascadesComposition(): void
    {
        $products = new ProductRepository($this->db);
        $ingredients = new IngredientRepository($this->db);
        $pid = $this->createProduct($products);
        $iaId = $this->createIngredient($ingredients, $this->ingA, 50);
        $products->setComposition($pid, [$this->line($iaId)]);

        self::assertSame(1, $products->compositionCount($pid));
        self::assertSame(1, $products->delete($pid)); // FK product_id CASCADE
        self::assertCount(0, $products->composition($pid)); // recette emportee

        // L'ingredient, lui, survit (la cascade ne remonte pas vers lui).
        self::assertNotNull($ingredients->find($iaId));
    }

    public function testIngredientReferencedByCompositionCannotBeHardDeleted(): void
    {
        $products = new ProductRepository($this->db);
        $ingredients = new IngredientRepository($this->db);
        $pid = $this->createProduct($products);
        $iaId = $this->createIngredient($ingredients, $this->ingA, 50);
        $products->setComposition($pid, [$this->line($iaId)]);

        // FK ingredient_id RESTRICT : un ingredient utilise dans une recette ne peut
        // pas etre supprime durement (il faut le desactiver).
        $blocked = false;
        try {
            $ingredients->delete($iaId);
        } catch (PDOException $exception) {
            $blocked = (string) $exception->getCode() === '23000';
        }
        self::assertTrue($blocked, 'product_ingredient.ingredient_id (RESTRICT) doit bloquer la suppression.');
        self::assertTrue($ingredients->isReferenced($iaId)); // pre-check FK-safe
    }

    public function testAvailabilityIsDerivedFromRequiredIngredientStock(): void
    {
        $products = new ProductRepository($this->db);
        $ingredients = new IngredientRepository($this->db);
        $pid = $this->createProduct($products);
        // Ingredient requis SOUS la bande critique (3/100 <= 5%).
        $critId = $this->createIngredient($ingredients, $this->ingA, 3);
        $products->setComposition($pid, [$this->line($critId, ['is_removable' => 0])]);

        $product = $products->find($pid);
        self::assertNotNull($product);
        $composition = $products->composition($pid);
        self::assertFalse(ProductRepository::isOrderable((int) $product['is_available'] === 1, $composition));
        self::assertContains($pid, $products->autoUnavailableIds()); // rupture auto (RG-T21)

        // Reapprovisionnement au-dessus du critique -> redevient commandable de lui-meme.
        $ingredients->restock($critId, 50, null, null); // 3 -> 53 (pack_size 1 * 50)
        $composition = $products->composition($pid);
        self::assertTrue(ProductRepository::isOrderable(true, $composition));
        self::assertNotContains($pid, $products->autoUnavailableIds());
    }

    private function createProduct(ProductRepository $repo): int
    {
        $repo->create([
            'category_id' => $this->categoryId,
            'name' => $this->product,
            'description' => null,
            'price_cents' => 590,
            'vat_rate' => 100,
            'image_path' => null,
            'is_available' => 1,
            'display_order' => 99,
        ]);

        return (int) ($this->db->fetch('SELECT id FROM product WHERE name = :n', ['n' => $this->product])['id'] ?? 0);
    }

    private function createIngredient(IngredientRepository $repo, string $name, int $stock): int
    {
        $repo->create([
            'name' => $name,
            'unit' => 'portion',
            'stock_quantity' => $stock,
            'stock_capacity' => 100,
            'pack_size' => 1,
            'pack_label' => null,
            'low_stock_pct' => 10,
            'critical_stock_pct' => 5,
            'is_active' => 1,
        ]);

        return (int) ($this->db->fetch('SELECT id FROM ingredient WHERE name = :n', ['n' => $name])['id'] ?? 0);
    }

    /**
     * @param array<string, int> $over
     * @return array{ingredient_id:int, quantity_normal:int, quantity_maxi:int, is_removable:int, is_addable:int, extra_price_cents:int}
     */
    private function line(int $ingredientId, array $over = []): array
    {
        return [
            'ingredient_id'     => $ingredientId,
            'quantity_normal'   => $over['quantity_normal'] ?? 1,
            'quantity_maxi'     => $over['quantity_maxi'] ?? 1,
            'is_removable'      => $over['is_removable'] ?? 0,
            'is_addable'        => $over['is_addable'] ?? 0,
            'extra_price_cents' => $over['extra_price_cents'] ?? 0,
        ];
    }
}
