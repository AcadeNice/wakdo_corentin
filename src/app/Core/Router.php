<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Routeur a base d'expressions regulieres compilees.
 *
 * Les patterns acceptent des segments dynamiques {param} compiles en groupes
 * nommes. Le dispatch distingue 404 (aucun chemin ne correspond) de 405
 * (le chemin correspond mais pas la methode).
 */
final class Router
{
    /**
     * @var array<int, array{method: string, pattern: string, regex: string, handler: array{0: class-string, 1: string}}>
     */
    private array $routes = [];

    public function __construct(
        private readonly Config $config,
        private readonly Database $database,
    ) {
    }

    /**
     * @param array{0: class-string, 1: string} $handler [ControllerClass::class, 'action']
     */
    public function add(string $method, string $pattern, array $handler): self
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            // Motif brut CONSERVE en plus de la regex compilee (ci-dessous) :
            // App\Health\RouteMap le relit pour construire la carte des routes de
            // la page "Sante de l'API" sans avoir a decompiler la regex.
            'pattern' => $pattern,
            'regex'   => $this->compile($pattern),
            'handler' => $handler,
        ];

        return $this;
    }

    /**
     * Releve EN DIRECT des routes enregistrees, dans l'ordre de declaration
     * (App\Health\RouteMap, page "Sante de l'API"). Ne modifie jamais l'etat du
     * routeur ; n'affecte pas dispatch().
     *
     * @return list<array{method: string, pattern: string, handler: array{0: class-string, 1: string}}>
     */
    public function routes(): array
    {
        return array_map(
            static fn (array $route): array => [
                'method'  => $route['method'],
                'pattern' => $route['pattern'],
                'handler' => $route['handler'],
            ],
            $this->routes,
        );
    }

    /**
     * Traduit "/api/orders/{number}" en une regex ancree avec groupes nommes.
     * Les segments litteraux sont echappes pour neutraliser tout metacaractere.
     */
    private function compile(string $pattern): string
    {
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)',
            $pattern,
        );

        // preg_quote n'est pas applicable globalement (il echapperait les groupes
        // generes) ; les patterns sont des litteraux de route controles, donc on
        // se contente de figer les delimiteurs avec un delimiteur improbable.
        return '#^' . $regex . '$#';
    }

    /**
     * Resout la requete : instancie le controleur et appelle l'action avec les
     * parametres de route extraits, ou renvoie une reponse 404 / 405.
     */
    public function dispatch(Request $request): Response
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path(), $matches) !== 1) {
                continue;
            }

            $pathMatched = true;

            if ($route['method'] !== $request->method()) {
                continue;
            }

            $params = array_filter(
                $matches,
                static fn (int|string $key): bool => is_string($key),
                ARRAY_FILTER_USE_KEY,
            );

            [$controllerClass, $action] = $route['handler'];

            /** @var Controller $controller */
            $controller = new $controllerClass($request, $this->config, $this->database);

            return $controller->$action($params);
        }

        if ($pathMatched) {
            return ErrorResponse::methodNotAllowed($request->path(), $request->method());
        }

        return ErrorResponse::notFound($request->path());
    }
}
