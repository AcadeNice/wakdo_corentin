<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use App\Core\Cors;
use App\Core\Request;
use App\Core\Response;

/**
 * Middleware CORS de l'API kiosk (docs/api/conventions.md section 10). Politique :
 * origine UNIQUE et EXACTE, scope /api/, methodes GET/POST/OPTIONS, sans credentials.
 * Fail-closed : pas d'origine configuree ou origine non concordante -> aucun en-tete.
 */
final class CorsTest extends TestCase
{
    private const ORIGIN = 'http://kiosk.localhost:8080';

    private function request(string $method, string $path, ?string $origin): Request
    {
        $headers = $origin === null ? [] : ['origin' => $origin];

        return new Request($method, $path, [], $headers, '');
    }

    private function cors(string $allowed = self::ORIGIN): Cors
    {
        return new Cors($allowed);
    }

    public function testPreflightFromAllowedOriginReturns204WithCorsHeaders(): void
    {
        $response = $this->cors()->preflightResponse($this->request('OPTIONS', '/api/categories', self::ORIGIN));

        self::assertNotNull($response);
        self::assertSame(204, $response->status());
        self::assertSame(self::ORIGIN, $response->header('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->header('Vary'));
        $methods = (string) $response->header('Access-Control-Allow-Methods');
        self::assertStringContainsString('GET', $methods);
        self::assertStringContainsString('POST', $methods);
        self::assertStringContainsString('OPTIONS', $methods);
        self::assertSame('Content-Type', $response->header('Access-Control-Allow-Headers'));
        self::assertSame('', $response->body());
    }

    public function testPreflightFromUnknownOriginIsNotHandled(): void
    {
        // Pas de court-circuit : le routeur gerera (405), sans en-tete CORS -> bloque navigateur.
        $response = $this->cors()->preflightResponse($this->request('OPTIONS', '/api/categories', 'http://evil.example'));

        self::assertNull($response);
    }

    public function testPreflightWithoutOriginIsNotHandled(): void
    {
        $response = $this->cors()->preflightResponse($this->request('OPTIONS', '/api/categories', null));

        self::assertNull($response);
    }

    public function testPreflightOutsideApiIsNotHandled(): void
    {
        $response = $this->cors()->preflightResponse($this->request('OPTIONS', '/login', self::ORIGIN));

        self::assertNull($response);
    }

    public function testApplyToAddsOriginHeaderForAllowedApiRequest(): void
    {
        $response = (new Response())->json(['data' => []], 200);
        $this->cors()->applyTo($this->request('GET', '/api/products', self::ORIGIN), $response);

        self::assertSame(self::ORIGIN, $response->header('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->header('Vary'));
        // Une reponse effective ne porte pas les en-tetes de preflight.
        self::assertNull($response->header('Access-Control-Allow-Methods'));
    }

    public function testApplyToDecoratesErrorResponsesToo(): void
    {
        // Le navigateur a besoin de l'en-tete CORS meme sur une 404 pour lire le corps.
        $response = (new Response())->json(['data' => null, 'error' => ['code' => 'NOT_FOUND']], 404);
        $this->cors()->applyTo($this->request('GET', '/api/products/999', self::ORIGIN), $response);

        self::assertSame(self::ORIGIN, $response->header('Access-Control-Allow-Origin'));
    }

    public function testApplyToIgnoresUnknownOrigin(): void
    {
        $response = (new Response())->json(['data' => []], 200);
        $this->cors()->applyTo($this->request('GET', '/api/products', 'http://evil.example'), $response);

        self::assertNull($response->header('Access-Control-Allow-Origin'));
    }

    public function testApplyToIgnoresNonApiPath(): void
    {
        $response = (new Response())->html('<x>', 200);
        $this->cors()->applyTo($this->request('GET', '/login', self::ORIGIN), $response);

        self::assertNull($response->header('Access-Control-Allow-Origin'));
    }

    public function testDisabledWhenNoAllowedOriginConfigured(): void
    {
        $cors = $this->cors('');

        self::assertNull($cors->preflightResponse($this->request('OPTIONS', '/api/categories', self::ORIGIN)));

        $response = (new Response())->json(['data' => []], 200);
        $cors->applyTo($this->request('GET', '/api/products', self::ORIGIN), $response);
        self::assertNull($response->header('Access-Control-Allow-Origin'));
    }

    public function testOriginMatchIsExactNotSubstring(): void
    {
        // Pas de joker ni de prefixe : une origine voisine ne doit jamais matcher.
        $response = (new Response())->json(['data' => []], 200);
        $this->cors()->applyTo($this->request('GET', '/api/products', self::ORIGIN . '.evil.com'), $response);

        self::assertNull($response->header('Access-Control-Allow-Origin'));
    }
}
