<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Core\Config;
use App\Core\Database;
use App\Health\HealthReport;

/**
 * HealthReport contre une vraie MariaDB (schema migre + seede par
 * php-tests.sh). Auto-skip si WAKDO_DB_TESTS != 1 ou base injoignable, meme
 * garde que le reste de tests/Integration/.
 *
 * Contrairement aux tests unitaires (FakeHealthDatabase), ce test utilise
 * `App\Health\HealthReport` SANS sous-classe : dans ce conteneur de test, tout
 * le depot (db/ compris) est monte sous /app -- contrairement au conteneur
 * applicatif de production, qui ne monte que ./src (docker-compose.yml). Le
 * comptage de fichiers .sql y est donc REELLEMENT non-null ici, ce qui est la
 * divergence attendue (et non un bug) entre ce test et la production.
 *
 * ECART D'ENVIRONNEMENT (a signaler, pas a corriger ici) : la base jetable de
 * `_byan-output/outils/php-tests.sh` rejoue les fichiers `db/migrations/*.sql`
 * et `db/seeds/*.sql` en DIRECT (boucle `for f in ... ; do mariadb < "$f"; done`),
 * SANS passer par `db/migrate-container.sh` -- le seul endroit qui cree et
 * remplit `schema_migrations`/`seeds_applied`. Ces deux tables de suivi
 * n'existent donc PAS dans cette base jetable alors qu'elles existent en
 * production (service `wakdo-migrate`). Ce test recree ici, a l'identique de
 * `migrate-container.sh`, ces tables et leur contenu (idempotent, memes noms
 * de colonnes) : sans ce rattrapage, `HealthReport::build()` degraderait
 * `db.ok` a `false` (la table manquante fait echouer TOUTE la requete) pour une
 * raison etrangere au code teste. Hors perimetre de ce chantier : ni
 * `php-tests.sh` ni `db/migrate-container.sh` ne sont a modifier ici.
 */
final class HealthReportDbTest extends TestCase
{
    private Database $db;
    private int $insertedOrderId = 0;
    private int $insertedAuditId = 0;

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
        $this->ensureTrackingTables();
    }

    /**
     * Cree (si absentes) et remplit `schema_migrations`/`seeds_applied` a partir
     * des VRAIS fichiers `.sql` presents dans cette copie -- meme DDL que
     * `db/migrate-container.sh`. Idempotent (`INSERT IGNORE`) : sans effet sur
     * un rejeu, donc appelable depuis `setUp()` a chaque test.
     */
    private function ensureTrackingTables(): void
    {
        foreach (['schema_migrations', 'seeds_applied'] as $table) {
            $this->db->execute(
                "CREATE TABLE IF NOT EXISTS {$table} ("
                . 'filename VARCHAR(255) NOT NULL PRIMARY KEY, '
                . 'applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            );
        }

        $root = dirname(__DIR__, 2) . '/db';
        $this->seedTrackingTable('schema_migrations', $root . '/migrations');
        $this->seedTrackingTable('seeds_applied', $root . '/seeds');
    }

    private function seedTrackingTable(string $table, string $dir): void
    {
        $files = glob($dir . '/*.sql') ?: [];
        sort($files);

        foreach ($files as $offset => $file) {
            $filename = basename($file);
            $this->db->execute(
                "INSERT IGNORE INTO {$table} (filename, applied_at) VALUES (:filename, DATE_ADD('2020-01-01 00:00:00', INTERVAL :offset SECOND))",
                ['filename' => $filename, 'offset' => $offset],
            );
        }
    }

    protected function tearDown(): void
    {
        if ($this->insertedOrderId > 0) {
            $this->db->execute('DELETE FROM customer_order WHERE id = :id', ['id' => $this->insertedOrderId]);
        }
        if ($this->insertedAuditId > 0) {
            $this->db->execute('DELETE FROM audit_log WHERE id = :id', ['id' => $this->insertedAuditId]);
        }
    }

    private function report(): HealthReport
    {
        return new HealthReport($this->db, new Config());
    }

    public function testDbSectionReflectsRealServer(): void
    {
        $data = $this->report()->build();

        self::assertTrue($data['db']['ok']);
        self::assertIsFloat($data['db']['latency_ms']);
        self::assertGreaterThanOrEqual(0.0, $data['db']['latency_ms']);
        self::assertIsString($data['db']['server_version']);
        self::assertStringContainsStringIgnoringCase('mariadb', $data['db']['server_version']);
    }

    public function testMigrationsAndSeedsCountsMatchTrackingTables(): void
    {
        $expectedMigrations = (int) ($this->db->fetch('SELECT COUNT(*) AS n FROM schema_migrations')['n'] ?? -1);
        $expectedSeeds = (int) ($this->db->fetch('SELECT COUNT(*) AS n FROM seeds_applied')['n'] ?? -1);
        $expectedLastMigration = $this->db->fetch(
            'SELECT filename FROM schema_migrations ORDER BY applied_at DESC, filename DESC LIMIT 1',
        )['filename'] ?? null;
        $expectedLastSeed = $this->db->fetch(
            'SELECT filename FROM seeds_applied ORDER BY applied_at DESC, filename DESC LIMIT 1',
        )['filename'] ?? null;

        $data = $this->report()->build();

        self::assertGreaterThan(0, $expectedMigrations, 'schema_migrations doit contenir des lignes (php-tests.sh les applique avant les tests).');
        self::assertSame($expectedMigrations, $data['migrations']['applied']);
        self::assertSame($expectedSeeds, $data['seeds']['applied']);
        self::assertSame($expectedLastMigration, $data['migrations']['last']);
        self::assertSame($expectedLastSeed, $data['seeds']['last']);
    }

    /**
     * Preuve que le comptage de fichiers correspond au VRAI systeme de
     * fichiers de db/ (et non une valeur devinee) : compare au glob direct sur
     * les memes dossiers.
     */
    public function testFileCountsMatchRealDbDirectory(): void
    {
        $root = dirname(__DIR__, 2) . '/db';
        $expectedMigrationFiles = count(glob($root . '/migrations/*.sql') ?: []);
        $expectedSeedFiles = count(glob($root . '/seeds/*.sql') ?: []);

        self::assertGreaterThan(0, $expectedMigrationFiles, 'db/migrations doit contenir des fichiers .sql dans cette copie de travail.');

        $data = $this->report()->build();

        self::assertSame($expectedMigrationFiles, $data['migrations']['files']);
        self::assertSame($expectedSeedFiles, $data['seeds']['files']);
    }

    /**
     * Preuve la plus directe que les compteurs d'activite lisent les BONNES
     * colonnes/tables : insere une commande payee et une ligne d'audit
     * "pin.failed" fraiches, puis verifie que le rapport AVANT/APRES augmente
     * exactement de un sur chacun des quatre compteurs concernes.
     */
    public function testActivityCountersIncreaseWithFreshRows(): void
    {
        $before = $this->report()->build()['activity_24h'];

        $orderNumber = 'IT-HEALTH-' . bin2hex(random_bytes(4));
        $this->db->execute(
            'INSERT INTO customer_order '
            . '(order_number, source, service_mode, status, total_ht_cents, total_vat_cents, total_ttc_cents, paid_at, created_at) '
            . 'VALUES (:num, :source, :mode, :status, :ht, :vat, :ttc, NOW(), NOW())',
            [
                'num'    => $orderNumber,
                'source' => 'counter',
                'mode'   => 'dine_in',
                'status' => 'paid',
                'ht'     => 500,
                'vat'    => 50,
                'ttc'    => 550,
            ],
        );
        $this->insertedOrderId = (int) ($this->db->fetch('SELECT LAST_INSERT_ID() AS id')['id'] ?? 0);
        self::assertGreaterThan(0, $this->insertedOrderId, 'La commande de test doit avoir ete inseree.');

        $this->db->execute(
            "INSERT INTO audit_log (actor_user_id, actor_role_id, action_code, entity_type, entity_id, summary) "
            . "VALUES (NULL, NULL, 'pin.failed', 'integration_test', NULL, 'ligne de test HealthReportDbTest')",
        );
        $this->insertedAuditId = (int) ($this->db->fetch('SELECT LAST_INSERT_ID() AS id')['id'] ?? 0);
        self::assertGreaterThan(0, $this->insertedAuditId, "La ligne d'audit de test doit avoir ete inseree.");

        $after = $this->report()->build()['activity_24h'];

        self::assertSame($before['orders_created'] + 1, $after['orders_created']);
        self::assertSame($before['orders_paid'] + 1, $after['orders_paid']);
        self::assertSame($before['audit_lines'] + 1, $after['audit_lines']);
        self::assertSame($before['pin_failures'] + 1, $after['pin_failures']);
    }
}
