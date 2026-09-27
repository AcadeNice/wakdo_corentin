<?php

declare(strict_types=1);

namespace App\Tests\Unit\Health;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use App\Core\Config;
use App\Core\DatabaseInterface;
use App\Health\HealthReport;

/**
 * Double minimal de DatabaseInterface : route par sous-chaine du SQL, comme le
 * reste des tests de ce depot (App\Tests\Support\FakeDatabase), mais garde ici
 * en prive (pas dans le support partage) pour ne rien toucher hors perimetre
 * pendant que trois chantiers ecrivent en parallele dans la meme copie.
 */
final class FakeHealthDatabase implements DatabaseInterface
{
    public bool $unreachable = false;

    /** @var array<string, array<string, mixed>> sous-chaine du SQL => ligne renvoyee */
    public array $rows = [
        'VERSION() AS server_version'                    => ['server_version' => '11.4.2-MariaDB'],
        'COUNT(*) AS n FROM schema_migrations'             => ['n' => 16],
        'FROM schema_migrations ORDER BY'                  => ['filename' => '0017_ingredient_family.sql'],
        'COUNT(*) AS n FROM seeds_applied'                 => ['n' => 10],
        'FROM seeds_applied ORDER BY'                      => ['filename' => '0010_ingredient_families.sql'],
        'FROM customer_order WHERE created_at >= :since'   => ['n' => 12],
        'FROM customer_order WHERE paid_at >= :since'      => ['n' => 10],
        "action_code = 'pin.failed'"                       => ['n' => 1],
        'FROM audit_log WHERE created_at >= :since'        => ['n' => 4],
    ];

    /** @var list<array{sql: string, params: array<string, mixed>}> */
    public array $reads = [];

    public function fetch(string $sql, array $params = []): ?array
    {
        $this->reads[] = ['sql' => $sql, 'params' => $params];

        if ($this->unreachable) {
            throw new RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
        }

        if ($sql === 'SELECT 1') {
            // Cle de chaine explicitement non-numerique : une cle "1" serait
            // recastee par PHP en entier (array<int, int>), incompatible avec le
            // contrat de retour array<string, mixed> de DatabaseInterface::fetch().
            return ['probe' => 1];
        }

        foreach ($this->rows as $needle => $row) {
            if (str_contains($sql, $needle)) {
                return $row;
            }
        }

        return null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return [];
    }

    public function execute(string $sql, array $params = []): int
    {
        throw new RuntimeException('HealthReport ne doit jamais ecrire : ' . $sql);
    }

    public function transaction(callable $fn): void
    {
        throw new RuntimeException('HealthReport ne doit jamais ouvrir de transaction.');
    }
}

/**
 * Sous-classe de test : pointe le fichier VERSION et la racine db/ sur des
 * fixtures temporaires, memes seams que HealthController::versionFilePath().
 */
final class TestHealthReport extends HealthReport
{
    public string $versionPath = '';
    public string $dbRoot = '';

    protected function versionFilePath(): string
    {
        return $this->versionPath;
    }

    protected function dbRootPath(): string
    {
        return $this->dbRoot;
    }
}

final class HealthReportTest extends TestCase
{
    private string $tmpDir = '';
    private false|string $originalDisplayErrors = '';

    protected function setUp(): void
    {
        $this->originalDisplayErrors = ini_get('display_errors');
        $this->tmpDir = sys_get_temp_dir() . '/wakdo_health_report_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/migrations', 0777, true);
        mkdir($this->tmpDir . '/seeds', 0777, true);
        foreach (['0001', '0002', '0003'] as $n) {
            file_put_contents($this->tmpDir . '/migrations/' . $n . '_x.sql', '-- x');
        }
        foreach (['0001', '0002'] as $n) {
            file_put_contents($this->tmpDir . '/seeds/' . $n . '_x.sql', '-- x');
        }
    }

    protected function tearDown(): void
    {
        if ($this->originalDisplayErrors !== false) {
            ini_set('display_errors', $this->originalDisplayErrors);
        }
        foreach (glob($this->tmpDir . '/migrations/*.sql') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->tmpDir . '/seeds/*.sql') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir . '/migrations');
        @rmdir($this->tmpDir . '/seeds');
        @rmdir($this->tmpDir);
    }

    private function versionFixture(string $content): string
    {
        $path = $this->tmpDir . '/VERSION';
        file_put_contents($path, $content);

        return $path;
    }

    private function report(FakeHealthDatabase $db, string $versionContent = "ab0c553 2026-09-27T09:40:00+02:00\n"): TestHealthReport
    {
        $report = new TestHealthReport($db, new Config());
        $report->versionPath = $this->versionFixture($versionContent);
        $report->dbRoot = $this->tmpDir;

        return $report;
    }

    public function testShapeMatchesContractWhenDatabaseAvailable(): void
    {
        putenv('APP_ENV=production');
        putenv('APP_DEBUG=false');

        $data = $this->report(new FakeHealthDatabase())->build();

        self::assertSame('ab0c553', $data['version']);
        self::assertSame('2026-09-27T09:40:00+02:00', $data['deployed_at']);
        self::assertSame('production', $data['app_env']);
        self::assertFalse($data['debug']);
        self::assertSame(PHP_VERSION, $data['php_version']);

        self::assertTrue($data['db']['ok']);
        self::assertIsFloat($data['db']['latency_ms']);
        self::assertGreaterThanOrEqual(0.0, $data['db']['latency_ms']);
        self::assertSame('11.4.2-MariaDB', $data['db']['server_version']);

        self::assertSame(16, $data['migrations']['applied']);
        self::assertSame(3, $data['migrations']['files']);
        self::assertSame('0017_ingredient_family.sql', $data['migrations']['last']);

        self::assertSame(10, $data['seeds']['applied']);
        self::assertSame(2, $data['seeds']['files']);
        self::assertSame('0010_ingredient_families.sql', $data['seeds']['last']);

        self::assertSame(12, $data['activity_24h']['orders_created']);
        self::assertSame(10, $data['activity_24h']['orders_paid']);
        self::assertSame(4, $data['activity_24h']['audit_lines']);
        self::assertSame(1, $data['activity_24h']['pin_failures']);

        putenv('APP_ENV');
        putenv('APP_DEBUG');
    }

    public function testLatencyIsRoundedToOneDecimal(): void
    {
        $data = $this->report(new FakeHealthDatabase())->build();

        $latency = (string) $data['db']['latency_ms'];
        // Au plus une decimale (round(.., 1) peut aussi produire un entier "0" ou "1.2").
        self::assertMatchesRegularExpression('/^\d+(\.\d)?$/', $latency);
    }

    public function testGeneratedAtIsIso8601WithOffset(): void
    {
        $data = $this->report(new FakeHealthDatabase())->build();

        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $data['generated_at'],
        );
    }

    public function testEverythingDbDependentIsNullWhenUnreachableButFilesStayReal(): void
    {
        $db = new FakeHealthDatabase();
        $db->unreachable = true;

        $data = $this->report($db)->build();

        self::assertFalse($data['db']['ok']);
        self::assertNull($data['db']['latency_ms']);
        self::assertNull($data['db']['server_version']);

        self::assertNull($data['migrations']['applied']);
        self::assertNull($data['migrations']['last']);
        self::assertNull($data['seeds']['applied']);
        self::assertNull($data['seeds']['last']);

        self::assertNull($data['activity_24h']['orders_created']);
        self::assertNull($data['activity_24h']['orders_paid']);
        self::assertNull($data['activity_24h']['audit_lines']);
        self::assertNull($data['activity_24h']['pin_failures']);

        // Le comptage de fichiers .sql est un check de systeme de fichiers, pas
        // une requete DB : il reste renseigne meme base injoignable.
        self::assertSame(3, $data['migrations']['files']);
        self::assertSame(2, $data['seeds']['files']);

        // Version/deployed_at restent aussi disponibles (lecture de fichier, pas de DB).
        self::assertSame('ab0c553', $data['version']);
    }

    public function testNoExceptionEscapesWhenDatabaseThrows(): void
    {
        $db = new FakeHealthDatabase();
        $db->unreachable = true;

        // build() ne doit jamais laisser fuir le Throwable de connexion : le
        // simple fait d'arriver ici (sans exception) est l'assertion.
        $this->report($db)->build();
        $this->addToAssertionCount(1);
    }

    public function testFilesNullWhenDbDirectoryNotMounted(): void
    {
        $report = new TestHealthReport(new FakeHealthDatabase(), new Config());
        $report->versionPath = $this->versionFixture("abc 2026-01-01T00:00:00+00:00\n");
        $report->dbRoot = $this->tmpDir . '/does-not-exist-in-container';

        $data = $report->build();

        self::assertNull($data['migrations']['files']);
        self::assertNull($data['seeds']['files']);
    }

    public function testVersionNullWhenFileAbsent(): void
    {
        $report = new TestHealthReport(new FakeHealthDatabase(), new Config());
        $missing = $this->tmpDir . '/VERSION_missing_' . getmypid();
        @unlink($missing);
        $report->versionPath = $missing;
        $report->dbRoot = $this->tmpDir;

        $data = $report->build();

        self::assertNull($data['version']);
        self::assertNull($data['deployed_at']);
    }

    public function testDisplayErrorsOffReflectsEffectivePhpConfigNotAppDebug(): void
    {
        putenv('APP_DEBUG=true'); // debug=true, mais display_errors force a Off cote PHP.
        ini_set('display_errors', '0');

        $data = $this->report(new FakeHealthDatabase())->build();

        self::assertTrue($data['debug']);
        self::assertTrue($data['display_errors_off']);

        putenv('APP_DEBUG');
    }

    public function testDisplayErrorsOffIsFalseWhenPhpConfigHasItOn(): void
    {
        ini_set('display_errors', '1');

        $data = $this->report(new FakeHealthDatabase())->build();

        self::assertFalse($data['display_errors_off']);
    }

    public function testNeverWrites(): void
    {
        $db = new FakeHealthDatabase();
        $this->report($db)->build();

        // FakeHealthDatabase::execute()/transaction() font echouer le test s'ils
        // sont appeles ; ce test documente explicitement l'attente.
        $this->addToAssertionCount(1);
    }
}
