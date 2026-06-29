<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use App\Core\DatabaseInterface;
use App\Order\OrderQueryRepository;

/**
 * Double de lecture minimal pour OrderQueryRepository::paidQueueWithDetail : route
 * les quatre SELECT (file paid -> order_item -> selections -> modifiers) sur des
 * jeux de lignes scriptes. Les requetes detail utilisent IN (...) sans parametre lie
 * (ids casts en entier dans le repo) : on desambiguise donc uniquement sur le texte
 * SQL. Aucune ecriture (l'operation est lecture seule, RG-5 de 5.1).
 */
final class FakeKdsDatabase implements DatabaseInterface
{
    /** @var list<array<string, mixed>> commandes actives (paid|preparing|ready) renvoyees par la file. */
    public array $orders = [];
    /** @var list<array<string, mixed>> lignes order_item (tous order_id confondus). */
    public array $items = [];
    /** @var list<array<string, mixed>> lignes order_item_selection. */
    public array $selections = [];
    /** @var list<array<string, mixed>> lignes order_item_modifier (avec ingredient_name). */
    public array $modifiers = [];

    public function fetch(string $sql, array $params = []): ?array
    {
        return null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        if (str_contains($sql, "WHERE status IN ('paid', 'preparing', 'ready')")) {
            return $this->orders;
        }
        if (str_contains($sql, 'FROM order_item WHERE order_id IN')) {
            return $this->items;
        }
        if (str_contains($sql, 'FROM order_item_selection')) {
            return $this->selections;
        }
        if (str_contains($sql, 'FROM order_item_modifier')) {
            return $this->modifiers;
        }

        return [];
    }

    public function execute(string $sql, array $params = []): int
    {
        return 0;
    }

    public function transaction(callable $fn): void
    {
        $fn($this);
    }
}

/**
 * Couvre la derivation de la bande SLA (slaBand, RG-4 de 5.1 / Note 6 : vert < 5 min,
 * ambre 5-10 min, rouge > 10 min, calcul depuis now - paid_at avec horloge injectee)
 * et l'assemblage du payload enrichi (paidQueueWithDetail : items imbriquant
 * selections + modifiers, tri preserve, id technique non expose).
 */
final class OrderQueryRepositoryTest extends TestCase
{
    private const NOW = 1_700_000_000; // epoch de reference fixe (deterministe).

    /** paid_at calibre a $secondsAgo secondes avant NOW. */
    private function paidAtSecondsAgo(int $secondsAgo): string
    {
        return date('Y-m-d H:i:s', self::NOW - $secondsAgo);
    }

    public function testSlaBandFreshBelowFiveMinutes(): void
    {
        $repo = new OrderQueryRepository(new FakeKdsDatabase());
        // 4 min ecoulees -> sous le seuil ambre (300 s) -> vert.
        self::assertSame('fresh', $repo->slaBand($this->paidAtSecondsAgo(240), self::NOW));
    }

    public function testSlaBandWarnBetweenFiveAndTenMinutes(): void
    {
        $repo = new OrderQueryRepository(new FakeKdsDatabase());
        // 7 min ecoulees -> >= 300 s et < 600 s -> ambre.
        self::assertSame('warn', $repo->slaBand($this->paidAtSecondsAgo(420), self::NOW));
    }

    public function testSlaBandLateBeyondTenMinutes(): void
    {
        $repo = new OrderQueryRepository(new FakeKdsDatabase());
        // 12 min ecoulees -> >= 600 s (seuil cible) -> rouge.
        self::assertSame('late', $repo->slaBand($this->paidAtSecondsAgo(720), self::NOW));
    }

    public function testSlaBandFallsBackToFreshOnEmptyPaidAt(): void
    {
        $repo = new OrderQueryRepository(new FakeKdsDatabase());
        // Donnee absente : pas d'alerte sur une valeur manquante.
        self::assertSame('fresh', $repo->slaBand('', self::NOW));
    }

    public function testPaidQueueWithDetailNestsItemsSelectionsAndModifiers(): void
    {
        $db = new FakeKdsDatabase();
        $db->orders = [
            ['id' => 7, 'order_number' => 'K7', 'source' => 'kiosk', 'service_mode' => 'dine_in', 'service_tag' => '3', 'total_ttc_cents' => 990, 'paid_at' => $this->paidAtSecondsAgo(120)],
        ];
        $db->items = [
            ['id' => 50, 'order_id' => 7, 'item_type' => 'menu', 'format' => 'maxi', 'label_snapshot' => 'Menu Le 280', 'quantity' => 1],
        ];
        $db->selections = [
            ['order_item_id' => 50, 'label_snapshot' => 'Coca 50cl'],
            ['order_item_id' => 50, 'label_snapshot' => 'Grande Frite'],
        ];
        $db->modifiers = [
            ['order_item_id' => 50, 'action' => 'remove', 'ingredient_name' => 'oignon'],
            ['order_item_id' => 50, 'action' => 'add', 'ingredient_name' => 'bacon'],
        ];

        $queue = (new OrderQueryRepository($db))->paidQueueWithDetail(['kiosk', 'counter', 'drive'], self::NOW);

        self::assertCount(1, $queue);
        $order = $queue[0];
        // L'id technique (cle de jointure) n'est pas expose a la vue.
        self::assertArrayNotHasKey('id', $order);
        self::assertSame('K7', $order['order_number']);
        self::assertSame('fresh', $order['sla_band']); // 2 min ecoulees.

        self::assertCount(1, $order['items']);
        $item = $order['items'][0];
        self::assertSame('Menu Le 280', $item['label_snapshot']);
        self::assertSame('maxi', $item['format']);
        self::assertSame(['Coca 50cl', 'Grande Frite'], array_column($item['selections'], 'label_snapshot'));
        self::assertSame(['oignon', 'bacon'], array_column($item['modifiers'], 'ingredient_name'));
        self::assertSame(['remove', 'add'], array_column($item['modifiers'], 'action'));
    }

    public function testPaidQueueWithDetailEmptyWhenNoVisibleSource(): void
    {
        // Aucune source visible -> file vide (coherent avec paidQueue).
        $queue = (new OrderQueryRepository(new FakeKdsDatabase()))->paidQueueWithDetail([], self::NOW);
        self::assertSame([], $queue);
    }

    public function testPaidQueueWithDetailHandlesOrderWithoutItems(): void
    {
        // Commande sans ligne (cas degrade) : items vide, pas d'erreur.
        $db = new FakeKdsDatabase();
        $db->orders = [
            ['id' => 9, 'order_number' => 'K9', 'source' => 'kiosk', 'service_mode' => 'takeaway', 'service_tag' => null, 'total_ttc_cents' => 500, 'paid_at' => $this->paidAtSecondsAgo(60)],
        ];

        $queue = (new OrderQueryRepository($db))->paidQueueWithDetail(['kiosk'], self::NOW);

        self::assertCount(1, $queue);
        self::assertSame([], $queue[0]['items']);
    }
}
