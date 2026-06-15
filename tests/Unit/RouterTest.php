<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

/**
 * Controleur sonde : capture les parametres de route recus pour prouver
 * l'extraction du segment {id} par le Router. Etend le vrai Controller du Core
 * pour traverser le meme chemin d'instanciation que la production.
 *
 * @param array<string, string> $params
 */
final class RouteProbeController extends Controller
{
    /** @var array<string, string> */
    public static array $capturedParams = [];

    /**
     * @param array<string, string> $params
     */
    public function show(array $params = []): Response
    {
        self::$capturedParams = $params;

        return (new Response())->json(['data' => $params], 200);
    }
}

final class RouterTest extends TestCase
{
    private Config $config;
    private Database $database;

    protected function setUp(): void
    {
        $this->config = new Config();
        // Le PDO est paresseux (ouvert au premier acces), donc construire la
        // Database ne tente aucune connexion : aucune BDD requise pour ces tests.
        $this->database = new Database($this->config);
        RouteProbeController::$capturedParams = [];
    }

    /**
     * Fabrique une Request sans toucher aux super-globales : le constructeur
     * de Request est public, on injecte directement methode et chemin.
     */
    private function request(string $method, string $path): Request
    {
        return new Request($method, $path, [], [], '');
    }

    private function router(): Router
    {
        return new Router($this->config, $this->database);
    }

    public function testMatchedRouteExtractsNamedParam(): void
    {
        $router = $this->router();
        $router->add('GET', '/api/orders/{id}', [RouteProbeController::class, 'show']);

        $response = $router->dispatch($this->request('GET', '/api/orders/42'));

        self::assertSame(200, $response->status());
        self::assertSame(['id' => '42'], RouteProbeController::$capturedParams);
    }

    public function testMultipleParamsAreAllExtracted(): void
    {
        $router = $this->router();
        $router->add('GET', '/api/menus/{menu}/options/{option}', [RouteProbeController::class, 'show']);

        $response = $router->dispatch($this->request('GET', '/api/menus/7/options/maxi'));

        self::assertSame(200, $response->status());
        self::assertSame(['menu' => '7', 'option' => 'maxi'], RouteProbeController::$capturedParams);
    }

    public function testUnknownPathReturns404(): void
    {
        $router = $this->router();
        $router->add('GET', '/api/orders/{id}', [RouteProbeController::class, 'show']);

        $response = $router->dispatch($this->request('GET', '/api/nope'));

        self::assertSame(404, $response->status());
        self::assertSame([], RouteProbeController::$capturedParams);
    }

    public function testKnownPathWrongMethodReturns405(): void
    {
        $router = $this->router();
        // Seul GET est enregistre sur ce chemin ; un POST matche le chemin mais
        // pas la methode, ce qui doit produire 405 et non 404.
        $router->add('GET', '/api/orders/{id}', [RouteProbeController::class, 'show']);

        $response = $router->dispatch($this->request('POST', '/api/orders/42'));

        self::assertSame(405, $response->status());
        self::assertSame([], RouteProbeController::$capturedParams);
    }

    public function testMethodMatchingIsCaseInsensitiveOnRegistration(): void
    {
        $router = $this->router();
        // add() normalise la methode en majuscules ; une route "get" doit donc
        // repondre a une requete GET.
        $router->add('get', '/api/health', [RouteProbeController::class, 'show']);

        $response = $router->dispatch($this->request('GET', '/api/health'));

        self::assertSame(200, $response->status());
    }
}
