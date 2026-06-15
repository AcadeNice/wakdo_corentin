<?php

declare(strict_types=1);

/**
 * Front controller du vhost admin (back-office + API sous /api).
 *
 * Apache reecrit toute requete non-fichier vers ce fichier (RewriteRule ^ index.php).
 * Le REQUEST_URI arrive intact (pas de prefixe strippe), donc le routeur voit
 * "/", "/api/health", etc.
 */

use App\Auth\SessionManager;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\MeController;
use App\Controllers\PasswordResetController;
use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

// src/public/admin/index.php : __DIR__ = src/public/admin ; remonter de deux
// niveaux (admin -> public -> src) pour atteindre la racine src/.
require dirname(__DIR__, 2) . '/app/Core/Autoloader.php';
Autoloader::register();

// En-tetes de securite poses tot, valables sur toute reponse y compris une 500.
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

$config = new Config();
date_default_timezone_set($config->timezone());

try {
    // Acces BDD paresseux : la connexion n'est ouverte qu'au premier query(),
    // donc la home back-office reste servie meme base indisponible.
    $database = new Database($config);

    // Demarre la session du vhost admin avant le dispatch (effet de bord global,
    // hors du Core stateless). Les controleurs y rattachent leur SessionManager.
    (new SessionManager($config))->start();

    $router = new Router($config, $database);
    $router->add('GET', '/', [HomeController::class, 'index']);
    $router->add('GET', '/api/health', [HealthController::class, 'index']);

    // Authentification back-office (mlt.md section 12). Le docroot du vhost admin
    // etant src/public/admin, le Router voit "/login" (pas de prefixe "/admin").
    $router->add('GET', '/login', [AuthController::class, 'showLogin']);
    $router->add('POST', '/login', [AuthController::class, 'login']);
    $router->add('POST', '/logout', [AuthController::class, 'logout']);
    $router->add('GET', '/forgot_password', [PasswordResetController::class, 'showRequest']);
    $router->add('POST', '/forgot_password', [PasswordResetController::class, 'submitRequest']);
    $router->add('GET', '/reset_password', [PasswordResetController::class, 'showConfirm']);
    $router->add('POST', '/reset_password', [PasswordResetController::class, 'submitConfirm']);

    // RBAC : identite + permissions de la session courante (gardee par SessionGuard).
    $router->add('GET', '/api/me', [MeController::class, 'show']);

    // Back-office (P3) : pages rendues serveur sous /admin, gardees par SessionGuard.
    $router->add('GET', '/admin/dashboard', [DashboardController::class, 'index']);

    $response = $router->dispatch(Request::fromGlobals());
    $response->send();
} catch (Throwable $exception) {
    // En debug on remonte le message pour iterer ; en prod, reponse generique
    // pour ne rien divulguer de la pile interne (information disclosure).
    $payload = $config->isDebug()
        ? ['data' => null, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => $exception->getMessage()]]
        : ['data' => null, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Internal server error']];

    (new Response())->json($payload, 500)->send();
}
