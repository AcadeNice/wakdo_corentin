<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Catalogue\MenuRepository;
use App\Catalogue\ProductRepository;
use App\Core\Config;
use App\Core\Database;
use App\Order\OrderRepository;

/**
 * Expiration des commandes en attente de paiement (F10) contre une vraie MariaDB.
 * Auto-skip si WAKDO_DB_TESTS != 1. Insere des commandes connues (order_number prefixe
 * IT-<suffixe>) avec des created_at controles, puis verifie le predicat de delai, les
 * statuts intouchables, la trace d'audit -- et surtout que le STOCK ne bouge pas.
 * Nettoyage par prefixe en tearDown, journal d'audit inclus.
 */
final class OrderExpireDbTest extends TestCase
{
    private Database $db;
    private string $suffix = '';
    /** @var list<int> */
    private array $insertedIds = [];

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
        $this->insertedIds = [];
    }

    protected function tearDown(): void
    {
        if ($this->suffix === '') {
            return;
        }
        foreach ($this->insertedIds as $id) {
            $this->db->execute(
                "DELETE FROM audit_log WHERE entity_type = 'customer_order' AND entity_id = :id "
                . "AND action_code = 'order.expire'",
                ['id' => $id],
            );
        }
        $this->db->execute(
            'DELETE FROM customer_order WHERE order_number LIKE :p',
            ['p' => 'IT-' . $this->suffix . '%'],
        );
    }

    private function repo(): OrderRepository
    {
        return new OrderRepository($this->db, new ProductRepository($this->db), new MenuRepository($this->db));
    }

    /** Insere une commande avec un age controle (en minutes) et renvoie son id. */
    private function insertOrder(string $tag, string $status, int $ageMinutes): int
    {
        $number = 'IT-' . $this->suffix . '-' . $tag;
        $this->db->execute(
            'INSERT INTO customer_order (order_number, idempotency_key, source, service_mode, status, '
            . 'total_ht_cents, total_vat_cents, total_ttc_cents, created_at) '
            . "VALUES (:num, :key, 'kiosk', 'takeaway', :st, 900, 100, 1000, "
            . 'NOW() - INTERVAL ' . max(0, $ageMinutes) . ' MINUTE)',
            ['num' => $number, 'key' => $number . '-k', 'st' => $status],
        );
        $id = (int) ($this->db->fetch(
            'SELECT id FROM customer_order WHERE order_number = :n',
            ['n' => $number],
        )['id'] ?? 0);
        self::assertGreaterThan(0, $id);
        $this->insertedIds[] = $id;

        return $id;
    }

    private function statusOf(int $id): string
    {
        return (string) ($this->db->fetch('SELECT status FROM customer_order WHERE id = :id', ['id' => $id])['status'] ?? '');
    }

    public function testExpiresAPendingOrderOlderThanTheDelay(): void
    {
        $id = $this->insertOrder('OLD', 'pending_payment', 120);

        $report = $this->repo()->expireStalePending(60);

        self::assertGreaterThanOrEqual(1, $report['expired']);
        self::assertSame('cancelled', $this->statusOf($id));
        $row = $this->db->fetch('SELECT cancelled_at FROM customer_order WHERE id = :id', ['id' => $id]);
        self::assertNotNull($row['cancelled_at'] ?? null, 'cancelled_at doit etre horodate');
    }

    public function testLeavesAFreshPendingOrderAlone(): void
    {
        // Le predicat de delai doit vraiment agir cote SQL, pas seulement dans le double.
        $fresh = $this->insertOrder('FRESH', 'pending_payment', 0);

        $this->repo()->expireStalePending(60);

        self::assertSame('pending_payment', $this->statusOf($fresh));
    }

    public function testNeverTouchesOtherStatusesHoweverOldTheyAre(): void
    {
        $ids = [];
        foreach (['paid', 'preparing', 'ready', 'delivered', 'cancelled'] as $status) {
            $ids[$status] = $this->insertOrder(strtoupper(substr($status, 0, 4)), $status, 5000);
        }

        $this->repo()->expireStalePending(60);

        foreach ($ids as $status => $id) {
            self::assertSame($status, $this->statusOf($id), "le statut {$status} ne doit pas bouger");
        }
    }

    public function testStockIsUntouched(): void
    {
        // L'invariant du lot, verifie contre la vraie base : ni les quantites
        // d'ingredients ni le nombre de mouvements ne changent.
        $this->insertOrder('STOCK', 'pending_payment', 120);

        $sumBefore = (int) ($this->db->fetch('SELECT COALESCE(SUM(stock_quantity), 0) AS s FROM ingredient')['s'] ?? -1);
        $movesBefore = (int) ($this->db->fetch('SELECT COUNT(*) AS n FROM stock_movement')['n'] ?? -1);

        $this->repo()->expireStalePending(60);

        $sumAfter = (int) ($this->db->fetch('SELECT COALESCE(SUM(stock_quantity), 0) AS s FROM ingredient')['s'] ?? -2);
        $movesAfter = (int) ($this->db->fetch('SELECT COUNT(*) AS n FROM stock_movement')['n'] ?? -2);

        self::assertSame($sumBefore, $sumAfter, 'aucune quantite de stock ne doit changer');
        self::assertSame($movesBefore, $movesAfter, 'aucun mouvement de stock ne doit etre cree');
    }

    public function testWritesAnAuditTraceWithoutActor(): void
    {
        $id = $this->insertOrder('AUDIT', 'pending_payment', 120);

        $this->repo()->expireStalePending(60);

        $row = $this->db->fetch(
            'SELECT actor_user_id, actor_role_id, summary FROM audit_log '
            . "WHERE entity_type = 'customer_order' AND entity_id = :id AND action_code = 'order.expire'",
            ['id' => $id],
        );
        self::assertNotNull($row, 'une trace order.expire doit exister');
        // Acces direct, pas d'operateur ?? : il collapse un null legitime sur son defaut
        // et l'assertion passerait pour la mauvaise raison (ou echouerait a tort).
        self::assertArrayHasKey('actor_user_id', $row);
        self::assertArrayHasKey('actor_role_id', $row);
        self::assertNull($row['actor_user_id'], 'acteur systeme : pas d equipier');
        self::assertNull($row['actor_role_id']);
        self::assertNotSame('', (string) ($row['summary'] ?? ''));
    }

    public function testSecondSweepIsIdempotent(): void
    {
        $this->insertOrder('IDEM', 'pending_payment', 120);

        $first = $this->repo()->expireStalePending(60);
        $second = $this->repo()->expireStalePending(60);

        self::assertGreaterThanOrEqual(1, $first['expired']);
        // La commande est passee dans un statut terminal : elle ne ressort plus des
        // candidates, donc rien a faire au second passage.
        self::assertSame(0, $second['expired']);
        self::assertSame(0, $second['examined']);
    }

    public function testExpiredOrderKeepsItsIdempotencyKeyAndRows(): void
    {
        // Difference concrete avec une purge : on ferme la commande, on ne la detruit pas.
        // La cle d'idempotence reste posee, donc un essai tardif retombe sur une commande
        // annulee et l'encaissement repond en conflit -- le client repart d'une neuve.
        $id = $this->insertOrder('KEEP', 'pending_payment', 120);

        $this->repo()->expireStalePending(60);

        $row = $this->db->fetch(
            'SELECT idempotency_key, order_number FROM customer_order WHERE id = :id',
            ['id' => $id],
        );
        self::assertNotNull($row);
        self::assertNotSame('', (string) ($row['idempotency_key'] ?? ''));
        self::assertStringStartsWith('IT-' . $this->suffix, (string) ($row['order_number'] ?? ''));
    }
}
