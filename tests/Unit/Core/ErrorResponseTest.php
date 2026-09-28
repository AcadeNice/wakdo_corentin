<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use App\Core\ErrorResponse;

/**
 * Le format d'une erreur suit la surface : l'API (/api, /admin/api, /admin/me) repond en
 * JSON, qu'un client HTTP doit pouvoir lire ; le back-office repond par une page HTML,
 * qu'un equipier doit pouvoir lire. Avant, une adresse inconnue du back-office ou une
 * exception affichaient du JSON brut a l'equipier.
 */
final class ErrorResponseTest extends TestCase
{
    public function testAnUnknownBackOfficeAddressGetsAnHtmlPage(): void
    {
        $response = ErrorResponse::notFound('/admin/nope');

        self::assertSame(404, $response->status());
        self::assertStringStartsWith('text/html', (string) $response->header('Content-Type'));
        self::assertStringContainsString('Page introuvable', $response->body());
        self::assertStringContainsString('href="/admin/dashboard"', $response->body());
        self::assertStringNotContainsString('NOT_FOUND', $response->body());
    }

    public function testTheApiKeepsItsJsonEnvelope(): void
    {
        foreach (['/api/nope', '/admin/api/nope', '/admin/me'] as $path) {
            $response = ErrorResponse::notFound($path);
            self::assertSame(404, $response->status(), $path);
            self::assertStringStartsWith('application/json', (string) $response->header('Content-Type'), $path);
            self::assertSame(['data' => null, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Resource not found']], json_decode($response->body(), true), $path);
        }
    }

    public function testAWrongMethodOnABackOfficeAddressGetsAnHtmlPage(): void
    {
        $response = ErrorResponse::methodNotAllowed('/admin/products', 'POST');

        self::assertSame(405, $response->status());
        self::assertStringStartsWith('text/html', (string) $response->header('Content-Type'));
        self::assertStringContainsString('Action impossible', $response->body());

        // Un GET vers une adresse qui n'existe qu'en POST : pour l'equipier qui l'a tapee,
        // c'est une page introuvable (le code HTTP reste 405).
        $typed = ErrorResponse::methodNotAllowed('/admin/products/12', 'GET');
        self::assertSame(405, $typed->status());
        self::assertStringContainsString('Page introuvable', $typed->body());

        $api = ErrorResponse::methodNotAllowed('/api/orders');
        self::assertSame('METHOD_NOT_ALLOWED', json_decode($api->body(), true)['error']['code']);
    }

    public function testAServerErrorOnTheBackOfficeShowsAGenericPageWithoutInternals(): void
    {
        $response = ErrorResponse::internal('/admin/products', false, 'SQLSTATE[HY000] secret interne');

        self::assertSame(500, $response->status());
        self::assertStringStartsWith('text/html', (string) $response->header('Content-Type'));
        self::assertStringContainsString('Une erreur est survenue', $response->body());
        self::assertStringNotContainsString('SQLSTATE', $response->body());
    }

    public function testAServerErrorOnTheApiKeepsTheGenericJson(): void
    {
        $response = ErrorResponse::internal('/admin/api/products', false, 'SQLSTATE[HY000] secret interne');

        self::assertSame(['data' => null, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Internal server error']], json_decode($response->body(), true));
    }

    public function testDebugModeShowsTheMessageEscaped(): void
    {
        $html = ErrorResponse::internal('/admin/products', true, '<script>alert(1)</script>')->body();
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)', $html);

        $json = json_decode(ErrorResponse::internal('/api/products', true, 'detail')->body(), true);
        self::assertSame('detail', $json['error']['message']);
    }
}
