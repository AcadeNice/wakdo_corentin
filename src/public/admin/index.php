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
use App\Auth\SessionRoutePolicy;
use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Cors;
use App\Core\Database;
use App\Core\ErrorDisplay;
use App\Core\ErrorResponse;
use App\Core\Request;
use App\Core\Router;

// src/public/admin/index.php : __DIR__ = src/public/admin ; remonter de deux
// niveaux (admin -> public -> src) pour atteindre la racine src/.
require dirname(__DIR__, 2) . '/app/Core/Autoloader.php';
Autoloader::register();

// En-tetes de securite poses tot, valables sur toute reponse y compris une 500.
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

$config = new Config();

// Destination des erreurs PHP, posee AVANT tout code susceptible d'echouer : au
// journal seul quand APP_DEBUG est faux, a l'ecran en plus quand il est vrai. Le
// reglage vient du code, pas d'une surcharge de fichier de composition qu'un
// deploiement pourrait oublier (voir App\Core\ErrorDisplay).
ErrorDisplay::apply($config);

date_default_timezone_set($config->timezone());

// Requete + middleware CORS construits AVANT le try : ils ne dependent que de la
// config et des globales, et doivent rester accessibles dans le catch pour decorer
// la reponse 500 d'une requete /api/ cross-origin (sans quoi le navigateur de la
// borne ne peut pas lire le corps de l'erreur).
$request = Request::fromGlobals();
$cors = new Cors($config->get('CORS_ALLOWED_ORIGIN', '') ?? '');

try {
    // Acces BDD paresseux : la connexion n'est ouverte qu'au premier query(),
    // donc la home back-office reste servie meme base indisponible.
    $database = new Database($config);

    // Demarre la session du vhost admin avant le dispatch (effet de bord global,
    // hors du Core stateless), SEULEMENT pour les routes qui en ont besoin
    // (SessionRoutePolicy) : l'API kiosk publique sous /api/* (y compris la sonde
    // /api/health), relayee telle quelle a l'hote borne, reste entierement
    // anonyme -- aucun Set-Cookie, aucun fichier de session ouvert cote serveur
    // pour ce trafic. Les controleurs proteges y rattachent leur SessionManager.
    if (SessionRoutePolicy::needsSession($request->path())) {
        (new SessionManager($config))->start();
    }

    $router = new Router($config, $database);
    (require dirname(__DIR__, 2) . '/app/Core/routes.php')($router);

    // CORS (docs/api/conventions.md section 10) : preflight OPTIONS traite AVANT le
    // routeur (pas de route OPTIONS) ; sinon dispatch puis decoration de la reponse.
    // Scope /api/ + origine exacte geres par le middleware (fail-closed). $request et
    // $cors sont construits hors du try pour que le catch puisse decorer aussi le 500.
    $preflight = $cors->preflightResponse($request);
    if ($preflight !== null) {
        $preflight->send();
    } else {
        $response = $router->dispatch($request);
        $cors->applyTo($request, $response);
        $response->send();
    }
} catch (Throwable $exception) {
    // Trace serveur SYSTEMATIQUE (stderr conteneur) : independante d'APP_DEBUG.
    // La prod renvoie un message generique au client (information disclosure), mais
    // l'incident doit rester diagnosticable cote serveur -> on ne perd jamais la pile.
    error_log(sprintf(
        '[wakdo] Unhandled %s: %s @ %s:%d',
        get_class($exception),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
    ));
    // En debug on remonte le message pour iterer ; en prod, reponse generique
    // pour ne rien divulguer de la pile interne (information disclosure). JSON pour
    // l'API, page HTML pour le back-office (App\Core\ErrorResponse).
    $errorResponse = ErrorResponse::internal($request->path(), $config->isDebug(), $exception->getMessage());

    // Decore aussi la 500 : une requete /api/ cross-origin (ex. BDD indisponible)
    // doit rester lisible par le navigateur de la borne (RG enveloppe d'erreur).
    $cors->applyTo($request, $errorResponse);
    $errorResponse->send();
}
