<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Core\Config;
use App\Core\Database;

/**
 * Verifie contre une vraie MariaDB (schema migre) la migration 0021 :
 *  - une ligne 'email' deja ecrite EN CLAIR par l'ancien code est convertie en
 *    empreinte SHA-256(adresse normalisee) ;
 *  - une ligne 'ip' n'est jamais touchee (l'audit porte sur l'adresse, pas
 *    l'IP source) ;
 *  - rejouee sur une base deja convertie, la migration ne touche aucune ligne
 *    (idempotence : plus aucune ligne 'email' ne matche le WHERE).
 *
 * Auto-skip si WAKDO_DB_TESTS != 1 ou base injoignable (meme garde que les
 * autres tests de tests/Integration/).
 */
final class PasswordResetThrottleHashMigrationDbTest extends TestCase
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
        $this->db->execute(
            "DELETE FROM password_reset_throttle WHERE identifier LIKE :p OR identifier = :hash",
            ['p' => "it-hashmig-{$this->suffix}%", 'hash' => hash('sha256', "it-hashmig-{$this->suffix}@wakdo.invalid")],
        );
    }

    public function testEmailRowWrittenInClearIsConvertedToItsSha256Digest(): void
    {
        $plainEmail = "IT-HashMig-{$this->suffix}@Wakdo.Invalid ";
        $normalized = strtolower(trim($plainEmail));
        $expectedHash = hash('sha256', $normalized);

        $this->db->execute(
            "INSERT INTO password_reset_throttle (throttle_kind, identifier, failed_attempts) "
            . "VALUES ('email', :id, 3)",
            ['id' => $plainEmail],
        );

        $affected = $this->executeSqlStatements($this->readMigrationSql('0021_password_reset_throttle_hash_identifier.sql'));
        self::assertSame(1, $affected, 'la ligne en clair doit etre convertie (1 ligne affectee)');

        $row = $this->db->fetch(
            "SELECT identifier FROM password_reset_throttle WHERE throttle_kind = 'email' AND identifier = :hash",
            ['hash' => $expectedHash],
        );
        self::assertNotNull($row, "la ligne doit desormais etre indexee par l'empreinte SHA-256");
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $row['identifier']);

        $plain = $this->db->fetch(
            "SELECT id FROM password_reset_throttle WHERE throttle_kind = 'email' AND identifier = :id",
            ['id' => $plainEmail],
        );
        self::assertNull($plain, "l'adresse en clair ne doit plus etre lisible dans la table");
    }

    public function testIpRowIsNeverTouchedByTheMigration(): void
    {
        $ip = 'it-hashmig-' . $this->suffix . '-198.51.100.7';

        $this->db->execute(
            "INSERT INTO password_reset_throttle (throttle_kind, identifier, failed_attempts) "
            . "VALUES ('ip', :id, 3)",
            ['id' => $ip],
        );

        $this->executeSqlStatements($this->readMigrationSql('0021_password_reset_throttle_hash_identifier.sql'));

        $row = $this->db->fetch(
            "SELECT identifier FROM password_reset_throttle WHERE throttle_kind = 'ip' AND identifier = :id",
            ['id' => $ip],
        );
        self::assertNotNull($row, "la dimension IP ne doit jamais etre convertie (l'audit porte sur l'adresse)");
        self::assertSame($ip, (string) $row['identifier']);
    }

    public function testMigrationReplayOnAnAlreadyConvertedRowIsANoOp(): void
    {
        $plainEmail = "it-hashmig-replay-{$this->suffix}@wakdo.invalid";
        $this->db->execute(
            "INSERT INTO password_reset_throttle (throttle_kind, identifier, failed_attempts) "
            . "VALUES ('email', :id, 1)",
            ['id' => $plainEmail],
        );

        $sql = $this->readMigrationSql('0021_password_reset_throttle_hash_identifier.sql');
        $first = $this->executeSqlStatements($sql);
        self::assertSame(1, $first, 'premiere execution : la ligne en clair est convertie');

        $second = $this->executeSqlStatements($sql);
        self::assertSame(0, $second, 'rejouee sur une ligne deja convertie, la migration ne doit toucher aucune ligne');
    }

    private function readMigrationSql(string $filename): string
    {
        $path = __DIR__ . '/../../db/migrations/' . $filename;
        $sql = file_get_contents($path);
        self::assertNotFalse($sql, 'migration ' . $filename . ' introuvable a ' . $path);

        return $sql;
    }

    /**
     * Meme decoupage/execution que les autres *MigrationDbTest (retire les
     * commentaires -- ';' de ponctuation francaise -- puis coupe sur ';' HORS
     * chaine SQL). Duplique volontairement (pas de trait partage entre ces
     * fichiers, coherent avec le reste du projet).
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
