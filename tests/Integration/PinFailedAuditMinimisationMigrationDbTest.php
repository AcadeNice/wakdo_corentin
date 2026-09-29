<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Core\Config;
use App\Core\Database;

/**
 * Verifie contre une vraie MariaDB (schema migre + seede) la migration 0019
 * (ecart de minimisation RGPD art. 5.1.c releve en audit) : les lignes
 * `pin.failed` DEJA ECRITES par l'ancien code (adresse tentee en clair dans
 * `summary`) sont nettoyees, rejouer la migration ne touche plus rien une fois
 * fait (idempotence), et aucune AUTRE ligne (autre `action_code`, ou `pin.failed`
 * deja propre) n'est modifiee.
 *
 * Auto-skip si WAKDO_DB_TESTS != 1 ou base injoignable (meme garde que les
 * autres tests de tests/Integration/).
 */
final class PinFailedAuditMinimisationMigrationDbTest extends TestCase
{
    private Database $db;

    /** @var list<int> ids inseres par le test courant, nettoyes en tearDown() meme sur echec. */
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
    }

    protected function tearDown(): void
    {
        foreach ($this->insertedIds as $id) {
            $this->db->execute('DELETE FROM audit_log WHERE id = :id', ['id' => $id]);
        }
        $this->insertedIds = [];
    }

    private function insertAuditRow(string $actionCode, string $summary, ?string $entityType = 'product'): int
    {
        $this->db->execute(
            'INSERT INTO audit_log (actor_user_id, actor_role_id, action_code, entity_type, entity_id, summary) '
            . 'VALUES (NULL, NULL, :code, :etype, NULL, :summary)',
            ['code' => $actionCode, 'etype' => $entityType, 'summary' => $summary],
        );
        $id = (int) ($this->db->fetch('SELECT LAST_INSERT_ID() AS id')['id'] ?? 0);
        self::assertGreaterThan(0, $id, 'precondition : la ligne audit_log jetable doit etre inseree');
        $this->insertedIds[] = $id;

        return $id;
    }

    private function summaryOf(int $id): ?string
    {
        $row = $this->db->fetch('SELECT summary FROM audit_log WHERE id = :id', ['id' => $id]);

        return $row !== null ? (string) ($row['summary'] ?? '') : null;
    }

    public function testMigrationStripsTheAttemptedEmailFromExistingPinFailedRows(): void
    {
        $id = $this->insertAuditRow('pin.failed', 'Échec PIN action sensible (email tenté: cible@wakdo.local)');

        $affected = $this->executeSqlStatements($this->readMigrationSql('0019_pin_failed_audit_minimisation.sql'));

        self::assertGreaterThanOrEqual(1, $affected, 'la migration doit nettoyer au moins la ligne jetable ci-dessus');
        self::assertSame('Échec PIN action sensible', $this->summaryOf($id));
    }

    public function testMigrationReplayIsANoOpOnceCleaned(): void
    {
        $id = $this->insertAuditRow('pin.failed', 'Échec PIN gestion RBAC (email tenté: autre@wakdo.local)');

        $this->executeSqlStatements($this->readMigrationSql('0019_pin_failed_audit_minimisation.sql'));
        self::assertSame('Échec PIN gestion RBAC', $this->summaryOf($id), 'precondition : la premiere passe doit avoir nettoye la ligne');

        $affected = $this->executeSqlStatements($this->readMigrationSql('0019_pin_failed_audit_minimisation.sql'));

        self::assertSame(0, $affected, 'rejouee sur une base deja nettoyee, la migration ne doit toucher aucune ligne');
        self::assertSame('Échec PIN gestion RBAC', $this->summaryOf($id), 'un second rejeu ne doit pas re-alterer la ligne');
    }

    public function testMigrationLeavesAnAlreadyCleanPinFailedRowIntact(): void
    {
        // Ligne au format DEJA CORRIGE (celle qu'ecrit desormais
        // PinGate::auditFailedPin() -- aucun suffixe "(email tenté:" a retirer).
        $id = $this->insertAuditRow('pin.failed', 'Échec PIN annulation (adresse inconnue)');

        $this->executeSqlStatements($this->readMigrationSql('0019_pin_failed_audit_minimisation.sql'));

        self::assertSame('Échec PIN annulation (adresse inconnue)', $this->summaryOf($id));
    }

    public function testMigrationDoesNotTouchAnotherActionCodeEvenWithTheSamePattern(): void
    {
        // Meme motif textuel, mais un action_code DIFFERENT de 'pin.failed' : le
        // garde de la migration filtre sur action_code, pas seulement sur le motif
        // du summary -- cette ligne ne doit jamais etre touchee.
        $id = $this->insertAuditRow('role.manage', 'Note libre (email tenté: peu-importe@wakdo.local)');

        $this->executeSqlStatements($this->readMigrationSql('0019_pin_failed_audit_minimisation.sql'));

        self::assertSame('Note libre (email tenté: peu-importe@wakdo.local)', $this->summaryOf($id));
    }

    private function readMigrationSql(string $filename): string
    {
        $path = __DIR__ . '/../../db/migrations/' . $filename;
        $sql = file_get_contents($path);
        self::assertNotFalse($sql, 'migration ' . $filename . ' introuvable a ' . $path);

        return $sql;
    }

    /**
     * Meme decoupage/execution que ManagerOrderCancelMigrationDbTest /
     * IngredientFamilyMigrationDbTest (retire les commentaires -- ';' de
     * ponctuation francaise -- puis coupe sur ';' HORS chaine SQL). Duplique
     * volontairement (pas de trait partage pour ce petit runner de migration,
     * coherent avec le reste du projet).
     */
    private function executeSqlStatements(string $sql): int
    {
        $withoutComments = (string) preg_replace('/^[ \t]*--.*$/m', '', $sql);

        $affected = 0;
        foreach ($this->splitSqlStatements($withoutComments) as $statement) {
            $statement = trim($statement);
            if ($statement === '' || stripos($statement, 'SET NAMES') === 0) {
                continue;
            }
            $affected += $this->db->execute($statement);
        }

        return $affected;
    }

    /**
     * @return list<string>
     */
    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inString = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($char === "'") {
                if ($inString && ($sql[$i + 1] ?? '') === "'") {
                    $current .= "''";
                    $i++;
                    continue;
                }
                $inString = !$inString;
                $current .= $char;
                continue;
            }

            if ($char === ';' && !$inString) {
                $statements[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }
        if (trim($current) !== '') {
            $statements[] = $current;
        }

        return $statements;
    }
}
