<?php

declare(strict_types=1);

namespace App\Health;

use RuntimeException;
use App\Core\Config;
use App\Core\Database;
use App\Core\Router;

/**
 * Carte des routes de la page "Sante de l'API" (bloc 4 du contrat) : lit le
 * routeur EN DIRECT (App\Core\Router::routes(), charge depuis
 * src/app/Core/routes.php sur un Router neuf, jamais celui de la requete
 * courante) et fusionne chaque route avec sa ligne App\Health\RouteSecurity.
 * Ni requete HTTP, ni acces BDD : le Router neuf n'est jamais dispatch(), et
 * Database reste dans son etat paresseux (connexion jamais ouverte).
 */
final class RouteMap
{
    /**
     * Libelle de groupe par nom court de controleur (sans le suffixe
     * `Controller`), identique aux libelles de scratchpad/routes-final.json.
     * Fonction pure du controleur (jamais du chemin) : une meme ressource garde
     * son groupe quelle que soit la surface (HTML back-office, JSON /admin/api,
     * JSON /api borne).
     *
     * @var array<string, string>
     */
    private const GROUPS = [
        'Auth'           => 'Connexion et compte',
        'AuthApi'        => 'Connexion et compte',
        'Catalogue'      => 'Catalogue',
        'Category'       => 'Catégories',
        'CategoryApi'    => 'Catégories',
        'CounterOrder'   => 'Commandes',
        'Dashboard'      => 'Pilotage',
        'Health'         => 'Système',
        'HealthApi'      => 'Système',
        'HealthPage'     => 'Système',
        'Home'           => 'Système',
        'Ingredient'     => 'Ingrédients et stock',
        'IngredientApi'  => 'Ingrédients et stock',
        'Kitchen'        => 'Commandes',
        'Me'             => 'Connexion et compte',
        'Menu'           => 'Menus',
        'MenuApi'        => 'Menus',
        'Order'          => 'Commandes',
        'OrderAdmin'     => 'Commandes',
        'OrderApi'       => 'Commandes',
        'PasswordReset'  => 'Connexion et compte',
        'Privacy'        => 'Connexion et compte',
        'Product'        => 'Produits et recettes',
        'ProductApi'     => 'Produits et recettes',
        'Profile'        => 'Connexion et compte',
        'Role'           => 'Rôles et permissions',
        'RoleApi'        => 'Rôles et permissions',
        'Stats'          => 'Pilotage',
        'StatsApi'       => 'Pilotage',
        'User'           => 'Comptes',
        'UserApi'        => 'Comptes',
    ];

    /**
     * Seul chemin (parmi les routes JSON, hors surface borne/api) qui repond en
     * JSON sans etre une ecriture : /admin/me. Toutes les autres routes `bo` en
     * lecture rendent du HTML ; toutes les routes `bo` en ecriture redirigent.
     */
    private const JSON_EXCEPTIONS_BO = ['/admin/me'];

    /**
     * @return list<array<string, mixed>>
     */
    public static function rows(): array
    {
        $router = new Router(new Config(), new Database(new Config()));
        (require dirname(__DIR__) . '/Core/routes.php')($router);

        $security = self::securityBySignature();

        $rows = [];
        foreach ($router->routes() as $route) {
            $signature = $route['method'] . ' ' . $route['pattern'];
            if (!isset($security[$signature])) {
                throw new RuntimeException(sprintf(
                    'Route sans entree RouteSecurity : %s (routes.php et RouteSecurity::ENTRIES ont diverge).',
                    $signature,
                ));
            }

            [$anon, $perm, $csrf, $pin, $reauth] = $security[$signature];
            unset($security[$signature]);

            [$controllerClass, $action] = $route['handler'];
            $short = self::shortControllerName($controllerClass);
            $surface = self::surfaceFor($route['pattern']);
            $write = $route['method'] !== 'GET';

            if (!isset(self::GROUPS[$short])) {
                throw new RuntimeException(sprintf(
                    'Aucun groupe connu pour le controleur "%s" (route %s) : ajouter une entree a RouteMap::GROUPS.',
                    $short,
                    $signature,
                ));
            }

            $row = [
                'm'    => $route['method'],
                'p'    => $route['pattern'],
                'c'    => $short,
                'a'    => $action,
                's'    => $surface,
                'anon' => $anon,
                'perm' => $perm,
                'w'    => $write,
                'csrf' => $csrf,
                'pin'  => $pin,
                'f'    => self::formatFor($surface, $route['pattern'], $write),
                'g'    => self::GROUPS[$short],
            ];
            if ($reauth !== null) {
                $row['re'] = $reauth;
            }

            $rows[] = $row;
        }

        if ($security !== []) {
            throw new RuntimeException(sprintf(
                'RouteSecurity::ENTRIES contient %d ligne(s) sans route correspondante dans le routeur : %s',
                count($security),
                implode(', ', array_keys($security)),
            ));
        }

        return $rows;
    }

    /**
     * @return array<string, array{0: bool, 1: ?string, 2: ?string, 3: ?string, 4: ?string}>
     */
    private static function securityBySignature(): array
    {
        $bySignature = [];
        foreach (RouteSecurity::ENTRIES as [$method, $path, $anon, $perm, $csrf, $pin, $reauth]) {
            $signature = $method . ' ' . $path;
            if (isset($bySignature[$signature])) {
                throw new RuntimeException(sprintf('Doublon dans RouteSecurity::ENTRIES pour %s.', $signature));
            }
            $bySignature[$signature] = [$anon, $perm, $csrf, $pin, $reauth];
        }

        return $bySignature;
    }

    /**
     * @param class-string $controllerClass
     */
    private static function shortControllerName(string $controllerClass): string
    {
        $slash = strrpos($controllerClass, '\\');
        $basename = $slash === false ? $controllerClass : substr($controllerClass, $slash + 1);

        return str_ends_with($basename, 'Controller') ? substr($basename, 0, -10) : $basename;
    }

    private static function surfaceFor(string $pattern): string
    {
        if (str_starts_with($pattern, '/admin/api/')) {
            return 'api';
        }
        if (str_starts_with($pattern, '/api/')) {
            return 'borne';
        }

        return 'bo';
    }

    private static function formatFor(string $surface, string $pattern, bool $write): string
    {
        if ($surface === 'api' || $surface === 'borne') {
            return 'json';
        }
        if (in_array($pattern, self::JSON_EXCEPTIONS_BO, true)) {
            return 'json';
        }

        return $write ? 'redirect' : 'html';
    }
}
