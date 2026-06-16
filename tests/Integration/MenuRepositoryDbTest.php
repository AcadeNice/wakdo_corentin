<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Catalogue\MenuRepository;
use App\Core\Config;
use App\Core\Database;

/**
 * CRUD reel de MenuRepository contre une vraie MariaDB (schema migre + seede),
 * y compris la composition de slots et le delete-and-reinsert a l'update.
 * Auto-skip si WAKDO_DB_TESTS != 1. Menu jetable (nom it-menu-*), nettoyage en
 * tearDown (CASCADE retire slots + options).
 */
final class MenuRepositoryDbTest extends TestCase
{
    private Database $db;
    private string $name = '';
    private int $categoryId = 0;
    /** @var list<int> */
    private array $productIds = [];

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
        $this->productIds = array_map(
            static fn (array $r): int => (int) ($r['id'] ?? 0),
            $this->db->fetchAll('SELECT id FROM product ORDER BY id LIMIT 3'),
        );
        $this->name = 'it-menu-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->name !== '') {
            // CASCADE menu -> menu_slot -> menu_slot_option.
            $this->db->execute('DELETE FROM menu WHERE name = :name', ['name' => $this->name]);
        }
    }

    public function testCreateFindUpdateSlotsAndDelete(): void
    {
        self::assertGreaterThan(0, $this->categoryId);
        self::assertCount(3, $this->productIds);
        [$burger, $optA, $optB] = $this->productIds;

        $repo = new MenuRepository($this->db);
        self::assertTrue($repo->categoryExists($this->categoryId));
        self::assertTrue($repo->productExists($burger));
        self::assertFalse($repo->productExists(0));

        // --- create : menu + 2 slots (drink avec 2 options, side avec 1) ---
        $id = $repo->create(
            [
                'category_id' => $this->categoryId,
                'burger_product_id' => $burger,
                'name' => $this->name,
                'price_normal_cents' => 790,
                'price_maxi_cents' => 990,
                'is_available' => 1,
                'display_order' => 50,
            ],
            [
                ['name' => 'Boisson', 'slot_type' => 'drink', 'is_required' => 1, 'display_order' => 0, 'options' => [$optA, $optB]],
                ['name' => 'Accompagnement', 'slot_type' => 'side', 'is_required' => 1, 'display_order' => 1, 'options' => [$optA]],
            ],
        );
        self::assertGreaterThan(0, $id);

        $found = $repo->find($id);
        self::assertNotNull($found);
        self::assertSame(790, (int) ($found['price_normal_cents'] ?? 0));
        self::assertSame(990, (int) ($found['price_maxi_cents'] ?? 0));

        $slots = $repo->slotsWithOptions($id);
        self::assertCount(2, $slots);
        self::assertSame('drink', $slots[0]['slot_type']);
        self::assertEqualsCanonicalizing([$optA, $optB], $slots[0]['option_product_ids']);
        self::assertSame('side', $slots[1]['slot_type']);
        self::assertSame([$optA], $slots[1]['option_product_ids']);

        // all() porte categorie + burger joints.
        $names = array_map(static fn (array $r): string => (string) ($r['name'] ?? ''), $repo->all());
        self::assertContains($this->name, $names);

        self::assertFalse($repo->isReferencedByOrders($id));

        // --- update : change le prix maxi ET reconfigure en 1 SEUL slot ---
        // (verifie le delete-and-reinsert : les 2 anciens slots disparaissent).
        $repo->update(
            $id,
            [
                'category_id' => $this->categoryId,
                'burger_product_id' => $burger,
                'name' => $this->name,
                'price_normal_cents' => 790,
                'price_maxi_cents' => 1090,
                'is_available' => 0,
                'display_order' => 51,
            ],
            [
                ['name' => 'Sauce', 'slot_type' => 'sauce', 'is_required' => 0, 'display_order' => 0, 'options' => [$optB]],
            ],
        );

        $updated = $repo->find($id);
        self::assertNotNull($updated);
        self::assertSame(1090, (int) ($updated['price_maxi_cents'] ?? 0));
        self::assertSame(0, (int) ($updated['is_available'] ?? 1));

        $slotsAfter = $repo->slotsWithOptions($id);
        self::assertCount(1, $slotsAfter);                       // delete-and-reinsert : plus que 1 slot
        self::assertSame('sauce', $slotsAfter[0]['slot_type']);
        self::assertSame([$optB], $slotsAfter[0]['option_product_ids']);

        // --- delete : menu non reference -> suppression dure OK, slots cascade ---
        self::assertSame(1, $repo->delete($id));
        self::assertNull($repo->find($id));
        self::assertSame([], $repo->slotsWithOptions($id));
    }
}
