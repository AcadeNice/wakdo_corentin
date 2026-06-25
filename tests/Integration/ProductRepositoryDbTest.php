<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Catalogue\ProductRepository;
use App\Core\Config;
use App\Core\Database;

/**
 * CRUD reel de ProductRepository contre une vraie MariaDB (schema migre + seede).
 * Auto-skip si WAKDO_DB_TESTS != 1. Produit jetable (nom it-prod-*) dans une
 * categorie seedee ; nettoyage en tearDown.
 */
final class ProductRepositoryDbTest extends TestCase
{
    private Database $db;
    private string $name = '';
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
        $this->name = 'it-prod-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->name !== '') {
            $this->db->execute('DELETE FROM product WHERE name = :name', ['name' => $this->name]);
        }
    }

    public function testCreateFindUpdateDelete(): void
    {
        $repo = new ProductRepository($this->db);

        self::assertGreaterThan(0, $this->categoryId);
        self::assertTrue($repo->categoryExists($this->categoryId));
        self::assertFalse($repo->categoryExists(0));

        $repo->create([
            'category_id' => $this->categoryId,
            'name' => $this->name,
            'description' => null,
            'price_cents' => 999,
            'size_cl' => null,
            'base_product_id' => null,
            'maxi_variant_product_id' => null,
            'vat_rate' => 100,
            'image_path' => null,
            'is_available' => 1,
            'display_order' => 99,
        ]);

        $id = (int) ($this->db->fetch('SELECT id FROM product WHERE name = :name', ['name' => $this->name])['id'] ?? 0);
        self::assertGreaterThan(0, $id);

        $found = $repo->find($id);
        self::assertNotNull($found);
        self::assertSame(999, (int) ($found['price_cents'] ?? 0));

        $repo->update($id, [
            'category_id' => $this->categoryId,
            'name' => $this->name,
            'description' => 'maj',
            'price_cents' => 1099,
            'size_cl' => null,
            'base_product_id' => null,
            'maxi_variant_product_id' => null,
            'vat_rate' => 55,
            'image_path' => null,
            'is_available' => 0,
            'display_order' => 100,
        ]);
        $updated = $repo->find($id);
        self::assertNotNull($updated);
        self::assertSame(1099, (int) ($updated['price_cents'] ?? 0));
        self::assertSame(55, (int) ($updated['vat_rate'] ?? 0));

        // all() porte le libelle de categorie joint.
        $names = array_map(static fn (array $r): string => (string) ($r['name'] ?? ''), $repo->all());
        self::assertContains($this->name, $names);

        // Produit non reference : suppression dure OK.
        self::assertSame(1, $repo->delete($id));
        self::assertNull($repo->find($id));
    }
}
