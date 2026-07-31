<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use App\Catalogue\MenuRepository;
use App\Catalogue\ProductRepository;
use App\Order\OrderRepository;
use App\Tests\Support\FakeOrderDatabase;

/**
 * Expiration des commandes restees en attente de paiement (F10). Le flux borne fait
 * DEUX appels (creation puis encaissement) : l'echec du second laisse une commande
 * inerte mais visible, qui salit le compteur "en attente" du tableau de bord.
 *
 * L'invariant central que ces tests verrouillent : l'expiration n'ecrit RIEN sur le
 * stock. Une commande en attente n'a jamais ete debitee (le debit vit dans la seule
 * transaction de pay), donc la re-crediter serait creer du stock a partir de rien.
 */
final class OrderRepositoryExpireTest extends TestCase
{
    private function repo(FakeOrderDatabase $db): OrderRepository
    {
        return new OrderRepository($db, new ProductRepository($db), new MenuRepository($db));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function candidates(): array
    {
        return [
            ['id' => 41, 'order_number' => 'K41', 'source' => 'kiosk', 'created_at' => '2026-07-31 08:00:00'],
        ];
    }

    /** @return list<string> */
    private function sqls(FakeOrderDatabase $db): array
    {
        return array_map(static fn (array $w): string => $w['sql'], $db->writes);
    }

    public function testExpiresAStalePendingOrderToCancelled(): void
    {
        $db = new FakeOrderDatabase();
        $db->stalePendingRows = $this->candidates();

        $report = $this->repo($db)->expireStalePending(60);

        self::assertSame(1, $report['examined']);
        self::assertSame(1, $report['expired']);
        self::assertSame(0, $report['skipped']);
        self::assertSame(['K41'], $report['order_numbers']);

        $update = null;
        foreach ($db->writes as $write) {
            if (str_contains($write['sql'], 'UPDATE customer_order')) {
                $update = $write;
            }
        }
        self::assertNotNull($update);
        self::assertStringContainsString("SET status = 'cancelled'", $update['sql']);
        self::assertStringContainsString('cancelled_at = NOW()', $update['sql']);
        // La garde de statut dans le WHERE est la protection de concurrence : si un
        // encaissement gagne la course, 0 ligne affectee et on ne touche a rien.
        self::assertStringContainsString("AND status = 'pending_payment'", $update['sql']);
        self::assertSame(41, $update['params']['id']);
    }

    public function testNeverWritesToStock(): void
    {
        // LE test du lot. Une commande en attente n'a jamais rien debite : aucun
        // re-credit ne doit pouvoir se produire. La preuve est mecanique -- aucune
        // ecriture enregistree ne touche ingredient ni stock_movement.
        $db = new FakeOrderDatabase();
        $db->stalePendingRows = [
            ['id' => 41, 'order_number' => 'K41', 'source' => 'kiosk', 'created_at' => '2026-07-31 08:00:00'],
            ['id' => 42, 'order_number' => 'C42', 'source' => 'counter', 'created_at' => '2026-07-31 08:05:00'],
            ['id' => 43, 'order_number' => 'D43', 'source' => 'drive', 'created_at' => '2026-07-31 08:10:00'],
        ];

        $report = $this->repo($db)->expireStalePending(60);

        self::assertSame(3, $report['expired']);
        foreach ($this->sqls($db) as $sql) {
            self::assertStringNotContainsString('UPDATE ingredient', $sql);
            self::assertStringNotContainsString('INSERT INTO stock_movement', $sql);
        }
    }

    public function testWritesOneAuditTracePerExpiredOrder(): void
    {
        $db = new FakeOrderDatabase();
        $db->stalePendingRows = $this->candidates();

        $this->repo($db)->expireStalePending(60);

        $audits = array_values(array_filter(
            $db->writes,
            static fn (array $w): bool => str_contains($w['sql'], 'INSERT INTO audit_log'),
        ));
        self::assertCount(1, $audits);
        $params = $audits[0]['params'];
        self::assertSame('order.expire', $params['code']);
        self::assertSame('customer_order', $params['etype']);
        self::assertSame(41, $params['eid']);
        // Acteur NULL = systeme. Personne n'a annule : la machine a nettoye. Le dire
        // par un identifiant d'equipier serait un mensonge dans un registre d'audit.
        self::assertNull($params['uid']);
        self::assertNull($params['rid']);
        self::assertNotSame('', $params['summary']);
        self::assertLessThanOrEqual(255, strlen((string) $params['summary']));
    }

    public function testLostRaceSkipsWithoutAudit(): void
    {
        // Un encaissement concurrent a gagne : l'UPDATE garde n'affecte aucune ligne.
        // On compte la commande en ignoree et on n'ecrit AUCUNE trace -- sinon le
        // journal affirmerait une expiration qui n'a pas eu lieu.
        $db = new FakeOrderDatabase();
        $db->stalePendingRows = $this->candidates();
        $db->expireUpdateAffected = 0;

        $report = $this->repo($db)->expireStalePending(60);

        self::assertSame(1, $report['examined']);
        self::assertSame(0, $report['expired']);
        self::assertSame(1, $report['skipped']);
        self::assertSame([], $report['order_numbers']);
        foreach ($this->sqls($db) as $sql) {
            self::assertStringNotContainsString('INSERT INTO audit_log', $sql);
        }
    }

    public function testSkipsAnOrderThatAlreadyCarriesASaleMovement(): void
    {
        // Cas theoriquement impossible (le debit et le passage en preparation committent
        // ensemble) : la garde le rend lisible depuis le code seul plutot que deductible.
        $db = new FakeOrderDatabase();
        $db->stalePendingRows = $this->candidates();
        $db->saleMovementsByOrder = [41 => true];

        $report = $this->repo($db)->expireStalePending(60);

        self::assertSame(0, $report['expired']);
        self::assertSame(1, $report['skipped']);
        foreach ($this->sqls($db) as $sql) {
            self::assertStringNotContainsString('UPDATE customer_order', $sql);
            self::assertStringNotContainsString('INSERT INTO audit_log', $sql);
        }
    }

    public function testMixedBatchExpiresOnlyTheCleanOrder(): void
    {
        $db = new FakeOrderDatabase();
        $db->stalePendingRows = [
            ['id' => 41, 'order_number' => 'K41', 'source' => 'kiosk', 'created_at' => '2026-07-31 08:00:00'],
            ['id' => 42, 'order_number' => 'K42', 'source' => 'kiosk', 'created_at' => '2026-07-31 08:05:00'],
        ];
        $db->saleMovementsByOrder = [41 => false, 42 => true];

        $report = $this->repo($db)->expireStalePending(60);

        self::assertSame(2, $report['examined']);
        self::assertSame(1, $report['expired']);
        self::assertSame(1, $report['skipped']);
        self::assertSame(['K41'], $report['order_numbers']);
        // Une ligne empoisonnee ne fait pas perdre le balayage : chaque commande a sa
        // propre transaction.
        self::assertCount(1, array_filter($this->sqls($db), static fn (string $s): bool => str_contains($s, 'INSERT INTO audit_log')));
    }

    public function testNoCandidateWritesNothing(): void
    {
        // L'etat reel du jour : aucune commande en attente en base. Le filet est
        // preventif, il doit rester silencieux quand il n'y a rien a nettoyer.
        $db = new FakeOrderDatabase();
        $db->stalePendingRows = [];

        $report = $this->repo($db)->expireStalePending(60);

        self::assertSame(['examined' => 0, 'expired' => 0, 'skipped' => 0, 'order_numbers' => []], $report);
        self::assertSame([], $db->writes);
    }

    public function testBoundsTheInterpolatedParameters(): void
    {
        // INTERVAL et LIMIT n'acceptent pas de parametre lie en prepare native
        // (EMULATE_PREPARES=false) : les valeurs sont interpolees, donc elles doivent
        // etre bornees en entier avant de toucher le SQL.
        $db = new FakeOrderDatabase();
        $db->stalePendingRows = [];

        $this->repo($db)->expireStalePending(0, 999999);
        $sql = $db->reads[0]['sql'] ?? '';

        self::assertStringContainsString('INTERVAL 1 MINUTE', $sql);
        self::assertStringContainsString('LIMIT 2000', $sql);
        self::assertStringNotContainsString('999999', $sql);
    }

    public function testClampsAnAbsurdlyLargeDelay(): void
    {
        $db = new FakeOrderDatabase();
        $db->stalePendingRows = [];

        $this->repo($db)->expireStalePending(99999, 10);
        $sql = $db->reads[0]['sql'] ?? '';

        self::assertStringContainsString('INTERVAL 1440 MINUTE', $sql);
        self::assertStringContainsString('LIMIT 10', $sql);
    }
}
