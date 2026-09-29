<?php

declare(strict_types=1);

namespace App\Controllers;

use Throwable;
use App\Core\Controller;
use App\Core\Response;

/**
 * Sonde de sante. GET /api/health.
 *
 * Le comptage des categories prouve la chaine complete (autoloader -> routeur
 * -> controleur -> PDO -> BDD seedee), pas seulement que PHP repond. Expose aussi
 * la version deployee (SHA + date), ecrite par scripts/deploy.sh : c'est la preuve
 * cote app du CD (apres un deploiement, ce champ reflete le nouveau commit).
 *
 * Non-final : seam de test (la sous-classe redirige versionFilePath sur une fixture).
 */
class HealthController extends Controller
{
    /**
     * @param array<string, string> $params
     */
    public function index(array $params = []): Response
    {
        $dbStatus = 'ok';
        $categories = null;
        $httpStatus = 200;

        try {
            $row = $this->database->fetch('SELECT COUNT(*) AS total FROM category');
            $categories = (int) ($row['total'] ?? 0);
        } catch (Throwable) {
            // Detail de l'erreur volontairement non expose (information disclosure) ;
            // un statut degrade suffit a la sonde, les logs conteneur portent le reste.
            $dbStatus = 'error';
            $httpStatus = 503;
        }

        $version = $this->readVersion();

        return $this->json(
            [
                // Pas de champ php_version ici : cette sonde est PUBLIQUE et anonyme
                // (aucune authentification), la version du moteur PHP est une
                // information disclosure inutile (OWASP A05). La page /admin/health
                // (App\Health\HealthReport, derriere role.manage) garde ce champ pour
                // l'exploitant, via sa propre source authentifiee.
                'status'      => $dbStatus === 'ok' ? 'ok' : 'degraded',
                'app_env'     => $this->config->appEnv(),
                'db'          => $dbStatus,
                'categories'  => $categories,
                'version'     => $version['version'],
                'deployed_at' => $version['deployed_at'],
            ],
            $httpStatus,
        );
    }

    /**
     * Chemin du marqueur de version. Sous le mount du code (./src -> /var/www/html),
     * donc lisible a chaud par l'app sans rebuild.
     */
    protected function versionFilePath(): string
    {
        return dirname(__DIR__, 2) . '/VERSION';
    }

    /**
     * Lit "SHA<espace>date" ecrit par deploy.sh. Absence toleree (dev / avant 1er
     * deploiement) : les deux champs retombent a null.
     *
     * @return array{version: ?string, deployed_at: ?string}
     */
    private function readVersion(): array
    {
        return self::readVersionFile($this->versionFilePath());
    }

    /**
     * Partie PURE (aucun effet de bord, aucune dependance a $this) de la lecture du
     * marqueur de version : factorisee ici pour que `App\Health\HealthReport`
     * (page de sante, meme information) la reutilise sans dupliquer le format
     * "SHA<espace>date", plutot que de le reimplementer une seconde fois avec le
     * risque de deriver du premier (ex. un jour ou l'un des deux tolere une valeur
     * que l'autre rejette). Chemin toujours fourni par l'appelant : c'est lui qui
     * sait ou son propre fichier VERSION doit se trouver.
     *
     * @return array{version: ?string, deployed_at: ?string}
     */
    public static function readVersionFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return ['version' => null, 'deployed_at' => null];
        }

        $line = trim((string) @file_get_contents($path));
        if ($line === '') {
            return ['version' => null, 'deployed_at' => null];
        }

        $parts = explode(' ', $line, 2);

        return [
            'version'     => $parts[0] !== '' ? $parts[0] : null,
            'deployed_at' => isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null,
        ];
    }
}
