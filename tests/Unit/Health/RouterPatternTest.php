<?php

declare(strict_types=1);

namespace App\Tests\Unit\Health;

use PHPUnit\Framework\TestCase;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

/**
 * Contreleur sonde minimal : seul le fait qu'il existe compte, aucune de ses
 * actions n'est appelee par ces tests (Router::routes() n'exerce jamais
 * dispatch()).
 */
final class RouterPatternProbeController extends Controller
{
    /**
     * @param array<string, string> $params
     */
    public function show(array $params = []): Response
    {
        return (new Response())->json(['data' => $params], 200);
    }
}

/**
 * App\Core\Router::routes() doit rendre le MOTIF BRUT ('/api/orders/{id}'),
 * jamais la regex compilee ('#^/api/orders/(?P<id>[^/]+)$#') -- contrat
 * sante-back-office, bloc 2. App\Health\RouteMap s'appuie sur ce motif brut
 * pour reconstruire la carte des routes affichee a la page "Sante de l'API".
 */
final class RouterPatternTest extends TestCase
{
    public function testRoutesReturnsTheRawPatternNotTheCompiledRegex(): void
    {
        $router = new Router(new Config(), new Database(new Config()));
        $router->add('GET', '/api/orders/{id}', [RouterPatternProbeController::class, 'show']);

        $routes = $router->routes();

        self::assertCount(1, $routes);
        self::assertSame('/api/orders/{id}', $routes[0]['pattern']);
        self::assertSame('GET', $routes[0]['method']);
        self::assertSame([RouterPatternProbeController::class, 'show'], $routes[0]['handler']);
    }

    public function testRoutesPreservesDeclarationOrder(): void
    {
        $router = new Router(new Config(), new Database(new Config()));
        $router->add('GET', '/a', [RouterPatternProbeController::class, 'show']);
        $router->add('POST', '/b', [RouterPatternProbeController::class, 'show']);
        $router->add('DELETE', '/c/{id}', [RouterPatternProbeController::class, 'show']);

        $patterns = array_map(static fn (array $r): string => $r['method'] . ' ' . $r['pattern'], $router->routes());

        self::assertSame(['GET /a', 'POST /b', 'DELETE /c/{id}'], $patterns);
    }

    public function testRoutesDoesNotAffectDispatch(): void
    {
        $router = new Router(new Config(), new Database(new Config()));
        $router->add('GET', '/api/orders/{id}', [RouterPatternProbeController::class, 'show']);

        // Appeler routes() avant dispatch() ne doit rien modifier au comportement
        // de dispatch() (aucun effet de bord partage entre les deux methodes).
        $router->routes();
        $response = $router->dispatch(new Request('GET', '/api/orders/42', [], [], ''));

        self::assertSame(200, $response->status());
        self::assertSame(['data' => ['id' => '42']], json_decode($response->body(), true));
    }
}
