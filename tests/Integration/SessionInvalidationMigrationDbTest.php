<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Core\Config;
use App\Core\Database;

/**
 * Verifie contre une vraie MariaDB (schema migre) la migration 0020 :
 *  - `user.session_epoch` existe, est un entier non signe, DEFAULT 0 (une
 *    installation en service n'a subi aucune reinitialisation depuis ce lot,
 *    ses sessions deja ouvertes -- implicitement a l'epoch 0 -- restent valides) ;
 *  - rejouee sur une base deja migree, la migration ne touche aucune ligne
 *    (idempotence : la colonne existe deja, l'ALTER devient un no-op) ;
 *  - `password_reset_throttle` existe avec ses deux dimensions ('email'/'ip')
 *    distinguees par `throttle_kind`, la contrainte d'unicite (throttle_kind,
 *    identifier) tient (deux lignes de MEME kind + MEME identifier sont
 *    rejetees, deux lignes de kind DIFFERENT avec le MEME identifiant
 *    litteral -- une adresse qui ressemblerait a une IP -- cohabitent) ;
 *  - `password_reset_throttle` n'a AUCUNE cle etrangere vers `user` (l'adresse
 *    throttlee peut ne resoudre vers aucun compte, RG-2 anti-enumeration).
 *
 * Auto-skip si WAKDO_DB_TESTS != 1 ou base injoignable (meme garde que les
 * autres tests de tests/Integration/).
 */
final class SessionInvalidationMigrationDbTest extends TestCase
{
    private Database $db;

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

    public function testUserSessionEpochColumnExistsAsUnsignedIntDefaultZero(): void
    {
        $row = $this->db->fetch(
            'SELECT column_type, is_nullable, column_default FROM information_schema.columns '
            . "WHERE table_schema = DATABASE() AND table_name = 'user' AND column_name = 'session_epoch'",
        );

        self::assertNotNull($row, "colonne user.session_epoch introuvable (migration 0020 jouee ?)");
        self::assertSame('int(10) unsigned', $row['column_type']);
        self::assertSame('NO', $row['is_nullable']);
        self::assertSame('0', (string) $row['column_default']);
    }

    public function testFreshUserRowDefaultsSessionEpochToZero(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $email = "it-session-epoch-{$suffix}@wakdo.invalid";
        $roleRow = $this->db->fetch("SELECT id FROM role WHERE code = 'admin'");
        self::assertNotNull($roleRow, "precondition : role 'admin' introuvable (seed 0001 joue ?)");

        $this->db->execute(
            'INSERT INTO user (email, password_hash, first_name, last_name, role_id, is_active) '
            . "VALUES (:email, 'x', 'IT', 'Session', :role, 1)",
            ['email' => $email, 'role' => $roleRow['id']],
        );

        try {
            $row = $this->db->fetch('SELECT session_epoch FROM user WHERE email = :email', ['email' => $email]);
            self::assertSame(0, (int) ($row['session_epoch'] ?? -1), "une ligne neuve doit demarrer a l'epoch 0");
        } finally {
            $this->db->execute('DELETE FROM user WHERE email = :email', ['email' => $email]);
        }
    }

    public function testMigrationReplayOnAnAlreadyMigratedSchemaIsANoOp(): void
    {
        $affected = $this->executeSqlStatements($this->readMigrationSql('0020_session_invalidation.sql'));

        self::assertSame(0, $affected, 'rejouee sur un schema deja migre, la migration ne doit toucher aucune ligne');
    }

    public function testPasswordResetThrottleTableHasNoForeignKeyToUser(): void
    {
        // RG-2 anti-enumeration : l'identifiant peut etre une adresse qui ne
        // resout vers AUCUN compte -- une FK interdirait d'y throttler une
        // adresse inconnue exactement comme une adresse connue.
        $fks = $this->db->fetchAll(
            'SELECT constraint_name FROM information_schema.table_constraints '
            . "WHERE table_schema = DATABASE() AND table_name = 'password_reset_throttle' "
            . "AND constraint_type = 'FOREIGN KEY'",
        );

        self::assertSame([], $fks);
    }

    public function testPasswordResetThrottleEnforcesUniqueKindAndIdentifierButNotAcrossKinds(): void
    {
        $identifier = 'it-' . bin2hex(random_bytes(4)) . '@wakdo.invalid';

        $this->db->execute(
            "INSERT INTO password_reset_throttle (throttle_kind, identifier) VALUES ('email', :id)",
            ['id' => $identifier],
        );

        try {
            $duplicate = null;
            try {
                $this->db->execute(
                    "INSERT INTO password_reset_throttle (throttle_kind, identifier) VALUES ('email', :id)",
                    ['id' => $identifier],
                );
            } catch (Throwable $exception) {
                $duplicate = $exception;
            }
            self::assertNotNull($duplicate, 'un doublon (meme kind, meme identifiant) doit etre rejete par la cle unique');

            // Meme identifiant litteral, dimension DIFFERENTE ('ip') : doit cohabiter
            // (les deux dimensions sont orthogonales, cf. PasswordResetThrottle).
            $this->db->execute(
                "INSERT INTO password_reset_throttle (throttle_kind, identifier) VALUES ('ip', :id)",
                ['id' => $identifier],
            );

            $rows = $this->db->fetchAll(
                'SELECT throttle_kind FROM password_reset_throttle WHERE identifier = :id ORDER BY throttle_kind',
                ['id' => $identifier],
            );
            self::assertSame(['email', 'ip'], array_map(static fn (array $r): string => (string) $r['throttle_kind'], $rows));
        } finally {
            $this->db->execute('DELETE FROM password_reset_throttle WHERE identifier = :id', ['id' => $identifier]);
        }
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
