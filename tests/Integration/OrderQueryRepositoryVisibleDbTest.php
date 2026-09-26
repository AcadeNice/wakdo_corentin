<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Core\Config;
use App\Core\Database;
use App\Order\OrderQueryRepository;

/**
 * OrderQueryRepository::recentVisible() (RG-T12, relecture adverse point 6)
 * contre une vraie MariaDB (schema migre) : sa seule couverture jusque-la etait
 * un double PHP qui REIMPLEMENTE la semantique SQL (tests/Unit/Order/
 * OrderQueryRepositoryTest.php) -- rien ne prouvait que le VRAI moteur applique
 * le filtre AVANT le LIMIT, lie correctement plusieurs sources, ou resiste a une
 * chaine hostile en parametre. Quatre cas : filtre-avant-limite, plusieurs
 * sources liees, chaine hostile non interpolee, bornage de la limite.
 *
 * customer_order est videe en setUp/tearDown (pas un nettoyage par prefixe
 * IT-<suffix> comme OrderQueryRepositoryDbTest voisin) : ce fichier a besoin de
 * comptages EXACTS (assertCount) pour prouver le filtre-avant-limite, ce qu'un
 * prefixe ne garantit pas si une ligne d'une source recherchee traine d'un run
 * precedent. Sans danger ici : PHPUnit execute setUp/tearDown PAR METHODE, dans
 * l'ordre, et chaque test de ce fichier ET des fichiers voisins (OrderExpireDbTest,
 * OrderReplaceDbTest...) nettoie ses propres lignes dans son propre tearDown avant
 * qu'une autre methode ne demarre -- aucun seed n'insere dans customer_order
 * (verifie : `grep customer_order db/seeds/*.sql` ne matche rien). Un tearDown
 * voisin qui n'aurait pas pu s'executer (exception avant coup) est nettoye ici
 * plutot que de fausser un comptage suivant.
 */
final class OrderQueryRepositoryVisibleDbTest extends TestCase
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
        $this->db->execute('DELETE FROM customer_order');
    }

    protected function tearDown(): void
    {
        $this->db->execute('DELETE FROM customer_order');
    }

    private function insert(string $number, string $source, string $createdAt): void
    {
        $mode = $source === 'drive' ? 'drive' : 'takeaway';
        $this->db->execute(
            'INSERT INTO customer_order (order_number, idempotency_key, source, service_mode, status, '
            . 'total_ht_cents, total_vat_cents, total_ttc_cents, created_at) '
            . "VALUES (:num, :key, :src, :mode, 'paid', 100, 10, 110, :cat)",
            ['num' => $number, 'key' => $number . '-k', 'src' => $source, 'mode' => $mode, 'cat' => $createdAt],
        );
    }

    public function testFilterIsAppliedBeforeTheLimitOnRealSql(): void
    {
        // 1 commande drive ANCIENNE, noyee sous 60 commandes counter plus recentes.
        $this->insert('IT-' . $this->suffix . '-D1', 'drive', '2026-01-01 08:00:00');
        for ($i = 0; $i < 60; $i++) {
            $this->insert('IT-' . $this->suffix . '-C' . $i, 'counter', '2026-02-01 08:00:' . str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $repo = new OrderQueryRepository($this->db);

        // recent(50) (non filtree) ne contient AUCUNE commande drive : c'est
        // exactement le piege que le filtre-apres-LIMIT produisait.
        $recent = $repo->recent(50);
        self::assertCount(50, $recent);
        self::assertSame([], array_values(array_filter(
            $recent,
            static fn (array $r): bool => (string) $r['source'] === 'drive',
        )));

        // recentVisible(['drive'], 50) la retrouve : WHERE avant LIMIT.
        $visible = $repo->recentVisible(['drive'], 50);
        self::assertCount(1, $visible);
        self::assertSame('IT-' . $this->suffix . '-D1', $visible[0]['order_number']);
    }

    public function testMultipleSourcesAreAllBoundAndNoOtherSourceLeaks(): void
    {
        $this->insert('IT-' . $this->suffix . '-K1', 'kiosk', '2026-03-01 10:00:00');
        $this->insert('IT-' . $this->suffix . '-C1', 'counter', '2026-03-01 10:00:01');
        $this->insert('IT-' . $this->suffix . '-D1', 'drive', '2026-03-01 10:00:02');

        $repo = new OrderQueryRepository($this->db);

        $twoSources = $repo->recentVisible(['kiosk', 'counter'], 50);
        $sources = array_map(static fn (array $r): string => (string) $r['source'], $twoSources);
        sort($sources);
        self::assertSame(['counter', 'kiosk'], $sources);

        self::assertCount(3, $repo->recentVisible(['kiosk', 'counter', 'drive'], 50));
        self::assertCount(1, $repo->recentVisible(['drive'], 50));
        self::assertSame([], $repo->recentVisible([], 50));
    }

    public function testHostileSourceStringIsBoundNotInterpolated(): void
    {
        $this->insert('IT-' . $this->suffix . '-C1', 'counter', '2026-03-01 10:00:00');

        $repo = new OrderQueryRepository($this->db);

        // Si la liste de canaux etait concatenee dans le SQL, cette valeur ouvrirait
        // le filtre (ou casserait la requete). Liee en parametre : 0 ligne, pas d'erreur.
        $rows = $repo->recentVisible(["counter') OR '1'='1"], 50);
        self::assertSame([], $rows);

        // La table est intacte (pas de DROP execute).
        self::assertCount(1, $repo->recentVisible(['counter'], 50));
    }

    public function testLimitIsClampedAndAppliedWithinTheFilteredSubset(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->insert('IT-' . $this->suffix . '-C' . $i, 'counter', '2026-04-01 10:00:0' . $i);
        }

        $repo = new OrderQueryRepository($this->db);

        self::assertCount(2, $repo->recentVisible(['counter'], 2));
        // Le plus recent d'abord (created_at DESC).
        self::assertSame('IT-' . $this->suffix . '-C4', $repo->recentVisible(['counter'], 2)[0]['order_number']);
        // Limite negative : bornee a 1 (max(1, min(200, n))), comme recent().
        self::assertCount(1, $repo->recentVisible(['counter'], -10));
    }
}
