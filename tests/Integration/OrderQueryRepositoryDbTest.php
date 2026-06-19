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
}
