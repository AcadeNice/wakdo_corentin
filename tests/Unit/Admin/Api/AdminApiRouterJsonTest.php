<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin\Api;

use PHPUnit\Framework\TestCase;
use App\Controllers\Admin\Api\StatsApiController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Router;

/**
 * Point 4 du chantier login JSON (docs/api/conventions.md section 5.3bis) : un
 * chemin INCONNU sous `/admin/api/*` renvoie du JSON 404, une METHODE non
 * enregistree sur un chemin connu renvoie du JSON 405 -- jamais une redirection
 * ou une page HTML. Ce comportement vit dans `Router::dispatch()` (generique,
 * pas specifique a `/admin/api/*`) : verifie ici DIRECTEMENT contre un vrai
 * `Router`, sans passer par un controleur (404/405 sont rendus AVANT toute
 * instanciation de controleur -- cf. Router::dispatch(), donc aucun risque de
 * toucher une session PHP reelle en environnement CLI).
 */
final class AdminApiRouterJsonTest extends TestCase
{
    private function router(): Router
    {
        $config = new Config();
        $router = new Router($config, new Database($config));
        // Une seule route enregistree suffit : assez pour distinguer "chemin
        // inconnu" (404) de "chemin connu, methode inconnue" (405).
        $router->add('GET', '/admin/api/stats', [StatsApiController::class, 'apiIndex']);

        return $router;
    }

    public function testUnknownAdminApiPathReturnsJson404(): void
    {
        $request = new Request('GET', '/admin/api/does-not-exist', [], [], '', '203.0.113.5');
        $response = $this->router()->dispatch($request);
        $body = json_decode($response->body(), true);

        self::assertSame(404, $response->status());
        self::assertSame('NOT_FOUND', $body['error']['code'] ?? null);
        self::assertSame('application/json; charset=utf-8', $response->header('Content-Type'));
    }

    public function testDisallowedMethodOnKnownAdminApiPathReturnsJson405(): void
    {
        $request = new Request('DELETE', '/admin/api/stats', [], [], '', '203.0.113.5');
        $response = $this->router()->dispatch($request);
        $body = json_decode($response->body(), true);

        self::assertSame(405, $response->status());
        self::assertSame('METHOD_NOT_ALLOWED', $body['error']['code'] ?? null);
        self::assertSame('application/json; charset=utf-8', $response->header('Content-Type'));
    }

    public function testUnknownAdminApiAuthPathReturnsJson404(): void
    {
        // Meme garantie sous le sous-prefixe /admin/api/auth/* (login/logout/me,
        // AuthApiController) : un typo de chemin reste du JSON, jamais du HTML.
        $request = new Request('GET', '/admin/api/auth/does-not-exist', [], [], '', '203.0.113.5');
        $response = $this->router()->dispatch($request);
        $body = json_decode($response->body(), true);

        self::assertSame(404, $response->status());
        self::assertSame('NOT_FOUND', $body['error']['code'] ?? null);
    }
}
