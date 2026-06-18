<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use App\Catalogue\MenuRepository;
use App\Catalogue\ProductRepository;
use App\Core\DatabaseInterface;
use App\Order\OrderRepository;
use App\Order\OrderValidationException;

/**
 * Double DatabaseInterface dedie : catalogue canned + enregistrement des ecritures.
 * Permet de tester le calcul de prix (RG-4), le numero K+id, l'idempotence et la
 * validation de createPending sans base reelle.
 */
final class OrderFakeDb implements DatabaseInterface
{
    /** @var list<array{sql:string, params:array<string,mixed>}> */
    public array $writes = [];
    /** @var array<int, array<string,mixed>> */
    public array $products = [];
    /** @var array<int, array<string,mixed>> */
    public array $menus = [];
    /** @var array<int, list<array<string,mixed>>> */
    public array $slotRows = [];
    /** @var array<int, list<array<string,mixed>>> */
    public array $compositions = [];
    /** @var array<string,mixed>|null */
    public ?array $existingByKey = null;
    private int $autoId = 99;

    public function fetch(string $sql, array $params = []): ?array
    {
        if (str_contains($sql, 'LAST_INSERT_ID')) {
            return ['id' => $this->autoId];
        }
        if (str_contains($sql, 'FROM customer_order WHERE idempotency_key')) {
            return $this->existingByKey;
        }
        if (str_contains($sql, 'FROM product WHERE id = :id')) {
            return $this->products[(int) $params['id']] ?? null;
        }
        if (str_contains($sql, 'FROM menu WHERE id = :id')) {
            return $this->menus[(int) $params['id']] ?? null;
        }

        return null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        if (str_contains($sql, 'FROM menu_slot s')) {
            return $this->slotRows[(int) $params['id']] ?? [];
        }
        if (str_contains($sql, 'FROM product_ingredient pi')) {
            return $this->compositions[(int) $params['id']] ?? [];
        }

        return [];
    }

    public function execute(string $sql, array $params = []): int
    {
        if (str_contains($sql, 'INSERT INTO customer_order') || str_contains($sql, 'INSERT INTO order_item ')) {
            $this->autoId++;
        }
        $this->writes[] = ['sql' => $sql, 'params' => $params];

        return 1;
    }

    public function transaction(callable $fn): void
    {
        $fn($this);
    }

    /** @return array<string,mixed> */
    public function firstWrite(string $needle): array
    {
        foreach ($this->writes as $w) {
            if (str_contains($w['sql'], $needle)) {
                return $w['params'];
            }
        }

        return [];
    }

    public function countWrites(string $needle): int
    {
        return count(array_filter($this->writes, static fn (array $w): bool => str_contains($w['sql'], $needle)));
    }
}

final class OrderRepositoryTest extends TestCase
{
    private function repo(OrderFakeDb $db): OrderRepository
    {
        return new OrderRepository($db, new ProductRepository($db), new MenuRepository($db));
    }

    public function testProductOrderComputesLineVatAndKId(): void
    {
        $db = new OrderFakeDb();
        $db->products[12] = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'is_available' => 1];

        $res = $this->repo($db)->createPending([
            'idempotency_key' => 'abc',
            'service_mode' => 'takeaway',
            'items' => [['type' => 'product', 'product_id' => 12, 'quantity' => 1]],
        ]);

        // 890 TTC a 10% -> HT = round(890*1000/1100) = 809, TVA = 81.
        $order = $db->firstWrite('INSERT INTO customer_order');
        self::assertSame(890, $order['ttc']);
        self::assertSame(809, $order['ht']);
        self::assertSame(81, $order['vat']);
        self::assertSame('K100', $res['order_number']);
        self::assertSame('pending_payment', $res['status']);
        self::assertSame(890, $res['total_ttc_cents']);

        $item = $db->firstWrite('INSERT INTO order_item ');
        self::assertSame('Cheeseburger', $item['label']);
        self::assertSame(890, $item['price']);
        self::assertSame(100, $item['vat']);
    }

    public function testMenuMaxiUsesBurgerVatAndMaxiPrice(): void
    {
        $db = new OrderFakeDb();
        $db->menus[5] = ['id' => 5, 'burger_product_id' => 12, 'name' => 'Menu Best Of', 'price_normal_cents' => 990, 'price_maxi_cents' => 1200, 'is_available' => 1];
        $db->products[12] = ['id' => 12, 'name' => 'Burger', 'price_cents' => 600, 'vat_rate' => 100, 'is_available' => 1];
        $db->products[20] = ['id' => 20, 'name' => 'Coca', 'price_cents' => 250, 'vat_rate' => 100, 'is_available' => 1];
        $db->slotRows[5] = [['id' => 7, 'name' => 'Boisson', 'slot_type' => 'drink', 'is_required' => 1, 'display_order' => 0, 'product_id' => 20]];

        $res = $this->repo($db)->createPending([
            'service_mode' => 'dine_in',
            'service_tag' => '42',
            'items' => [['type' => 'menu', 'menu_id' => 5, 'quantity' => 1, 'format' => 'maxi',
                'selections' => [['menu_slot_id' => 7, 'product_id' => 20]]]],
        ]);

        // 1200 TTC a 10% -> HT = round(1200*1000/1100) = 1091, TVA = 109.
        $order = $db->firstWrite('INSERT INTO customer_order');
        self::assertSame(1200, $order['ttc']);
        self::assertSame(1091, $order['ht']);
        self::assertSame('42', $order['tag']);
        $item = $db->firstWrite('INSERT INTO order_item ');
        self::assertSame('maxi', $item['fmt']);
        self::assertSame(1200, $item['price']);
        self::assertSame(1, $db->countWrites('INSERT INTO order_item_selection'));
    }

    public function testAddModifierAddsExtraToLine(): void
    {
        $db = new OrderFakeDb();
        $db->products[12] = ['id' => 12, 'name' => 'Burger', 'price_cents' => 890, 'vat_rate' => 100, 'is_available' => 1];
        $db->compositions[12] = [['ingredient_id' => 3, 'is_removable' => 1, 'is_addable' => 1, 'extra_price_cents' => 50, 'quantity_normal' => 1, 'quantity_maxi' => 1]];

        $res = $this->repo($db)->createPending([
            'service_mode' => 'takeaway',
            'items' => [['type' => 'product', 'product_id' => 12, 'quantity' => 1,
                'modifiers' => [['ingredient_id' => 3, 'action' => 'add']]]],
        ]);

        self::assertSame(940, $res['total_ttc_cents']); // 890 + 50
        self::assertSame(1, $db->countWrites('INSERT INTO order_item_modifier'));
    }

    public function testIdempotentReturnsExistingWithoutInsert(): void
    {
        $db = new OrderFakeDb();
        $db->existingByKey = ['id' => 7, 'order_number' => 'K7', 'total_ttc_cents' => 500, 'status' => 'pending_payment'];

        $res = $this->repo($db)->createPending([
            'idempotency_key' => 'dup',
            'service_mode' => 'takeaway',
            'items' => [['type' => 'product', 'product_id' => 12, 'quantity' => 1]],
        ]);

        self::assertSame('K7', $res['order_number']);
        self::assertSame(0, $db->countWrites('INSERT INTO customer_order'));
    }

    public function testRejectsUnknownProduct(): void
    {
        $db = new OrderFakeDb();
        $this->expectException(OrderValidationException::class);
        $this->repo($db)->createPending([
            'service_mode' => 'takeaway',
            'items' => [['type' => 'product', 'product_id' => 999, 'quantity' => 1]],
        ]);
    }

    public function testRejectsSelectionOutsideSlotOptions(): void
    {
        $db = new OrderFakeDb();
        $db->menus[5] = ['id' => 5, 'burger_product_id' => 12, 'name' => 'Menu', 'price_normal_cents' => 990, 'price_maxi_cents' => 1200, 'is_available' => 1];
        $db->products[12] = ['id' => 12, 'name' => 'Burger', 'price_cents' => 600, 'vat_rate' => 100, 'is_available' => 1];
        $db->slotRows[5] = [['id' => 7, 'name' => 'Boisson', 'slot_type' => 'drink', 'is_required' => 1, 'display_order' => 0, 'product_id' => 20]];

        $this->expectException(OrderValidationException::class);
        $this->repo($db)->createPending([
            'service_mode' => 'takeaway',
            'items' => [['type' => 'menu', 'menu_id' => 5, 'quantity' => 1, 'format' => 'normal',
                'selections' => [['menu_slot_id' => 7, 'product_id' => 999]]]], // 999 hors options
        ]);
    }
}
