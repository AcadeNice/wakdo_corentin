<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Core\Config;
use App\Core\Database;
use App\Order\OrderQueryRepository;

/**
 * OrderQueryRepository (read-side admin) contre une vraie MariaDB (schema migre).
 * Auto-skip si WAKDO_DB_TESTS != 1. Insere des commandes connues (order_number
 * prefixe IT-<suffix>) et verifie les KPIs de vente (delta vs baseline) + la liste
 * recente. Nettoyage par prefixe en tearDown.
 */
final class OrderQueryRepositoryDbTest extends TestCase
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
        $this->db->execute(
            'DELETE FROM customer_order WHERE order_number LIKE :p',
            ['p' => 'IT-' . $this->suffix . '%'],
        );
    }

    private function insertOrder(string $number, string $status, int $ttc): void
    {
        $ht = (int) round($ttc / 1.1);
        $this->db->execute(
            "INSERT INTO customer_order (order_number, idempotency_key, source, service_mode, status, "
            . 'total_ht_cents, total_vat_cents, total_ttc_cents) '
            . "VALUES (:num, :key, 'kiosk', 'takeaway', :st, :ht, :vat, :ttc)",
            ['num' => $number, 'key' => $number . '-k', 'st' => $status, 'ht' => $ht, 'vat' => $ttc - $ht, 'ttc' => $ttc],
        );
    }

    public function testSalesKpisCountsPaidRevenueOnly(): void
    {
        $repo = new OrderQueryRepository($this->db);
        $before = $repo->salesKpis();

        $this->insertOrder('IT-' . $this->suffix . '-P', 'paid', 1000);
        $this->insertOrder('IT-' . $this->suffix . '-N', 'pending_payment', 500);

        $after = $repo->salesKpis();
        // Le CA ne compte QUE le paid (pas le pending_payment).
        self::assertSame($before['revenue_cents'] + 1000, $after['revenue_cents']);
        self::assertSame($before['paid_count'] + 1, $after['paid_count']);
        self::assertSame($before['total_orders'] + 2, $after['total_orders']);
        self::assertGreaterThanOrEqual(1, $after['by_status']['paid'] ?? 0);
        self::assertGreaterThanOrEqual(1, $after['by_status']['pending_payment'] ?? 0);
        self::assertSame(intdiv($after['revenue_cents'], max(1, $after['paid_count'])), $after['avg_basket_cents']);
    }

    public function testRecentListsInsertedOrdersWithExpectedColumns(): void
    {
        $repo = new OrderQueryRepository($this->db);
        $num = 'IT-' . $this->suffix . '-P';
        $this->insertOrder($num, 'paid', 1000);

        $recent = $repo->recent(200);
        $numbers = array_column($recent, 'order_number');
        self::assertContains($num, $numbers, 'la commande inseree doit apparaitre dans recent()');

        $row = $recent[(int) array_search($num, $numbers, true)];
        self::assertSame('paid', (string) $row['status']);
        self::assertSame(1000, (int) $row['total_ttc_cents']);
        self::assertSame('takeaway', (string) $row['service_mode']);
        self::assertArrayHasKey('created_at', $row);
    }

    public function testRecentRespectsLimit(): void
    {
        $repo = new OrderQueryRepository($this->db);
        self::assertLessThanOrEqual(3, count($repo->recent(3)));
    }

    /**
     * paidQueueWithDetail (LIST_ORDERS_DISPLAY) contre le schema reel : insere une
     * commande `paid` avec une ligne produit + un modificateur, et verifie que la file
     * porte l'article (label_snapshot, format) et le modificateur (ingredient_name via
     * la jointure ingredient + action). Les FK sont resolues par nom (convention des
     * seeds : produit 'Le 280', ingredient 'Oignon'). Auto-skip si seeds absents.
     */
    public function testPaidQueueWithDetailReturnsItemsAndModifiers(): void
    {
        $product = $this->db->fetch("SELECT id FROM product WHERE name = 'Le 280'");
        $ingredient = $this->db->fetch("SELECT id FROM ingredient WHERE name = 'Oignon'");
        if ($product === null || $ingredient === null) {
            self::markTestSkipped('Seeds catalogue/ingredients absents (produit/ingredient introuvable).');
        }

        $num = 'IT-' . $this->suffix . '-KDS';
        $this->insertOrder($num, 'paid', 1090);
        // paid_at explicite : la file trie sur paid_at, et la bande SLA en derive.
        $orderId = $this->orderIdByNumber($num);
        $this->db->execute('UPDATE customer_order SET paid_at = NOW() WHERE id = :id', ['id' => $orderId]);

        $this->db->execute(
            'INSERT INTO order_item (order_id, item_type, product_id, format, label_snapshot, '
            . 'unit_price_cents_snapshot, vat_rate_snapshot, quantity) '
            . "VALUES (:oid, 'product', :pid, 'normal', 'Le 280', 1090, 100, 1)",
            ['oid' => $orderId, 'pid' => (int) $product['id']],
        );
        $itemId = (int) ($this->db->fetch('SELECT LAST_INSERT_ID() AS id')['id'] ?? 0);
        $this->db->execute(
            'INSERT INTO order_item_modifier (order_item_id, ingredient_id, action, extra_price_cents) '
            . "VALUES (:iid, :ing, 'remove', 0)",
            ['iid' => $itemId, 'ing' => (int) $ingredient['id']],
        );

        $queue = (new OrderQueryRepository($this->db))->paidQueueWithDetail(['kiosk', 'counter', 'drive']);
        $mine = array_values(array_filter(
            $queue,
            static fn (array $o): bool => ($o['order_number'] ?? '') === $num,
        ));
        self::assertCount(1, $mine, 'la commande inseree doit apparaitre dans la file KDS');

        $order = $mine[0];
        self::assertArrayNotHasKey('id', $order, 'l\'id technique ne doit pas etre expose');
        self::assertContains($order['sla_band'], ['fresh', 'warn', 'late']);

        self::assertCount(1, $order['items']);
        $item = $order['items'][0];
        self::assertSame('Le 280', (string) $item['label_snapshot']);
        self::assertSame('normal', (string) $item['format']);
        self::assertCount(1, $item['modifiers']);
        self::assertSame('remove', (string) $item['modifiers'][0]['action']);
        self::assertSame('Oignon', (string) $item['modifiers'][0]['ingredient_name']);
    }

    private function orderIdByNumber(string $number): int
    {
        return (int) ($this->db->fetch(
            'SELECT id FROM customer_order WHERE order_number = :n',
            ['n' => $number],
        )['id'] ?? 0);
    }
}
