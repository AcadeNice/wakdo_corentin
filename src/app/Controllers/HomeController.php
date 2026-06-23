<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;

/**
 * Racine du FQDN admin. GET /.
 *
 * Le back-office n'expose pas de page d'accueil publique : la racine renvoie
 * vers la connexion (RG-T02). Une fois authentifie, /login mene l'equipier
 * vers role.default_route. La sonde de sante reste sur GET /api/health.
 */
final class HomeController extends Controller
{
    /**
     * @param array<string, string> $params
     */
    public function index(array $params = []): Response
    {
        return Response::make('', 302, ['Location' => '/login']);
    }
}
