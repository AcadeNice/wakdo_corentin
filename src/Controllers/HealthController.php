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
 * -> controleur -> PDO -> BDD seedee), pas seulement que PHP repond.
 */
final class HealthController extends Controller
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

        return $this->json(
            [
                'status'      => $dbStatus === 'ok' ? 'ok' : 'degraded',
                'app_env'     => $this->config->appEnv(),
                'php_version' => PHP_VERSION,
                'db'          => $dbStatus,
                'categories'  => $categories,
            ],
            $httpStatus,
        );
    }
}
