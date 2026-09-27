<?php

declare(strict_types=1);

namespace App\Health;

use DateTimeImmutable;
use Throwable;
use App\Controllers\HealthController;
use App\Core\Config;
use App\Core\DatabaseInterface;

/**
 * Rapport d'etat de la page `/admin/health` (contrat section 3) : version
 * deployee, configuration effective, sante de la base, migrations/seeds
 * appliques et activite des dernieres 24 heures -- des COMPTES seulement,
 * jamais une ligne nominative (mlt confidentialite : la page est un tableau de
 * bord d'exploitation, pas un journal d'audit consultable).
 *
 * Toutes les mesures sont REELLES : la latence base est chronometree cote PHP
 * (pas une constante), la version du serveur vient de `SELECT VERSION()`, les
 * compteurs de migrations/seeds lisent les tables de suivi ecrites par
 * `db/migrate-container.sh` (`schema_migrations`, `seeds_applied`), et le
 * nombre de fichiers `.sql` verifie si `db/` est monte dans le conteneur
 * applicatif plutot que de le supposer (il ne l'est pas en production : seul
 * `./src` est monte dans `wakdo-app`, cf. docker-compose.yml).
 *
 * Non `final` : `versionFilePath()` et `dbRootPath()` sont des seams de test
 * (meme convention que `HealthController::versionFilePath()`), qui pointent une
 * fixture plutot que le vrai `src/VERSION` / la vraie arborescence `db/`.
 */
class HealthReport
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly Config $config,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $version = HealthController::readVersionFile($this->versionFilePath());
        $probe = $this->probeDatabase();
        $files = $this->fileCounts();

        return [
            'generated_at'        => (new DateTimeImmutable())->format(DATE_ATOM),
            'version'             => $version['version'],
            'deployed_at'         => $version['deployed_at'],
            'app_env'             => $this->config->appEnv(),
            'debug'               => $this->config->isDebug(),
            'display_errors_off'  => $this->displayErrorsOff(),
            'php_version'         => PHP_VERSION,
            'db'                  => $probe['db'],
            'migrations'          => [
                'applied' => $probe['migrations']['applied'],
                'files'   => $files['migrations'],
                'last'    => $probe['migrations']['last'],
            ],
            'seeds'               => [
                'applied' => $probe['seeds']['applied'],
                'files'   => $files['seeds'],
                'last'    => $probe['seeds']['last'],
            ],
            'activity_24h'        => $probe['activity_24h'],
        ];
    }

    /**
     * Chemin du marqueur de version : meme fichier que `HealthController`,
     * calcule depuis l'emplacement de CE fichier (src/app/Health -> src/).
     */
    protected function versionFilePath(): string
    {
        return dirname(__DIR__, 2) . '/VERSION';
    }

    /**
     * Racine `db/` a verifier : sous le mount de production (`./src` seul est
     * monte dans wakdo-app), ce chemin n'existe pas -- `fileCounts()` le
     * detecte reellement plutot que de le supposer. Calcule depuis
     * l'emplacement de ce fichier (src/app/Health -> remonter jusqu'a la
     * racine du depot, ou `db/` est un dossier frere de `src/`).
     */
    protected function dbRootPath(): string
    {
        return dirname(__DIR__, 3) . '/db';
    }

    /**
     * Interroge la base : latence chronometree, version du serveur, comptes de
     * migrations/seeds appliques (tables de suivi) et activite des dernieres
     * 24 heures. Une seule frontiere try/catch : au premier Throwable (base
     * injoignable ou table de suivi absente), TOUT retombe a null sauf `db.ok`
     * (false) -- jamais de detail d'erreur expose (pas de message d'exception
     * dans la reponse).
     *
     * @return array{
     *     db: array{ok: bool, latency_ms: ?float, server_version: ?string},
     *     migrations: array{applied: ?int, last: ?string},
     *     seeds: array{applied: ?int, last: ?string},
     *     activity_24h: array{orders_created: ?int, orders_paid: ?int, audit_lines: ?int, pin_failures: ?int},
     * }
     */
    private function probeDatabase(): array
    {
        try {
            $start = microtime(true);
            $this->db->fetch('SELECT 1');
            $latencyMs = round((microtime(true) - $start) * 1000, 1);

            $versionRow = $this->db->fetch('SELECT VERSION() AS server_version');
            $serverVersion = $versionRow !== null ? (string) ($versionRow['server_version'] ?? '') : null;

            $since = (new DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s');

            return [
                'db' => [
                    'ok'             => true,
                    'latency_ms'     => $latencyMs,
                    'server_version' => $serverVersion,
                ],
                'migrations' => [
                    'applied' => $this->count('SELECT COUNT(*) AS n FROM schema_migrations'),
                    'last'    => $this->lastFilename('schema_migrations'),
                ],
                'seeds' => [
                    'applied' => $this->count('SELECT COUNT(*) AS n FROM seeds_applied'),
                    'last'    => $this->lastFilename('seeds_applied'),
                ],
                'activity_24h' => [
                    'orders_created' => $this->count(
                        'SELECT COUNT(*) AS n FROM customer_order WHERE created_at >= :since',
                        ['since' => $since],
                    ),
                    'orders_paid' => $this->count(
                        'SELECT COUNT(*) AS n FROM customer_order WHERE paid_at >= :since',
                        ['since' => $since],
                    ),
                    'audit_lines' => $this->count(
                        'SELECT COUNT(*) AS n FROM audit_log WHERE created_at >= :since',
                        ['since' => $since],
                    ),
                    // 'pin.failed' est le SEUL code d'action ecrit sur un echec de PIN
                    // (App\Auth\PinGate::resolveActor(), et les logFailedPin() prives de
                    // UserController/RoleController/ProductController/IngredientController/
                    // MenuController/OrderAdminController -- verifie sur les sept, tous
                    // identiques) : aucune enumeration de codes n'est necessaire ici.
                    'pin_failures' => $this->count(
                        "SELECT COUNT(*) AS n FROM audit_log WHERE action_code = 'pin.failed' AND created_at >= :since",
                        ['since' => $since],
                    ),
                ],
            ];
        } catch (Throwable) {
            return [
                'db' => ['ok' => false, 'latency_ms' => null, 'server_version' => null],
                'migrations' => ['applied' => null, 'last' => null],
                'seeds' => ['applied' => null, 'last' => null],
                'activity_24h' => [
                    'orders_created' => null,
                    'orders_paid'    => null,
                    'audit_lines'    => null,
                    'pin_failures'   => null,
                ],
            ];
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function count(string $sql, array $params = []): int
    {
        return (int) ($this->db->fetch($sql, $params)['n'] ?? 0);
    }

    /**
     * Nom du dernier fichier applique (table de suivi filename/applied_at,
     * `db/migrate-container.sh`) : le plus RECEMMENT applique, pas le plus
     * grand alphabetiquement (une migration hors-sequence resterait correcte).
     */
    private function lastFilename(string $table): ?string
    {
        $row = $this->db->fetch(
            "SELECT filename FROM {$table} ORDER BY applied_at DESC, filename DESC LIMIT 1",
        );

        return $row !== null ? (string) ($row['filename'] ?? '') : null;
    }

    /**
     * Nombre de fichiers .sql presents dans db/migrations et db/seeds, SI ce
     * dossier est lisible depuis ce processus -- sinon null (pas suppose).
     * Independant de la base : reste renseigne meme si `db.ok` est faux (une
     * base injoignable ne dit rien de l'etat du systeme de fichiers).
     *
     * @return array{migrations: ?int, seeds: ?int}
     */
    private function fileCounts(): array
    {
        $root = $this->dbRootPath();

        return [
            'migrations' => $this->sqlFileCount($root . '/migrations'),
            'seeds'      => $this->sqlFileCount($root . '/seeds'),
        ];
    }

    private function sqlFileCount(string $dir): ?int
    {
        if (!is_dir($dir) || !is_readable($dir)) {
            return null;
        }

        $files = glob($dir . '/*.sql');

        return $files === false ? null : count($files);
    }

    /**
     * Lu depuis la configuration PHP EFFECTIVE (`ini_get`), pas deduit de
     * `APP_DEBUG` : `App\Core\ErrorDisplay::apply()` pose `display_errors` au
     * demarrage a partir du debug, mais rien ne garantit qu'un autre reglage
     * (php.ini de l'image, `.htaccess`, `ini_set` ulterieur) ne l'ecrase pas --
     * la sonde doit refleter ce que PHP fera REELLEMENT, pas ce que la config
     * applicative visait.
     */
    private function displayErrorsOff(): bool
    {
        $value = ini_get('display_errors');
        if ($value === false) {
            return true;
        }

        return in_array(strtolower(trim($value)), ['', '0', 'off'], true);
    }
}
