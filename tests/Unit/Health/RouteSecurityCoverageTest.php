<?php

declare(strict_types=1);

namespace App\Tests\Unit\Health;

use PHPUnit\Framework\TestCase;
use App\Core\Config;
use App\Core\Database;
use App\Core\Router;
use App\Health\RouteSecurity;

/**
 * Le test qui doit echouer des qu'une route est ajoutee/retiree sans que
 * App\Health\RouteSecurity::ENTRIES ne soit mis a jour en meme temps (contrat
 * sante-back-office, bloc 5, 2e puce) : toute route enregistree dans le routeur
 * (charge depuis src/app/Core/routes.php) a EXACTEMENT une ligne dans
 * RouteSecurity, et reciproquement -- aucune route sans entree, aucune entree
 * sans route, aucun doublon des deux cotes.
 *
 * Remplace l'ancienne verification par expression reguliere sur index.php
 * (tests/Unit/Admin/Api/RouteMatrixTest.php, avant ce chantier) : la source des
 * routes est desormais le Router lui-meme, pas une relecture de texte.
 */
final class RouteSecurityCoverageTest extends TestCase
{
    /**
     * @return list<string> signatures "METHODE chemin"
     */
    private function routeSignatures(): array
    {
        $router = new Router(new Config(), new Database(new Config()));
        (require dirname(__DIR__, 3) . '/src/app/Core/routes.php')($router);

        return array_map(
            static fn (array $r): string => $r['method'] . ' ' . $r['pattern'],
            $router->routes(),
        );
    }

    /**
     * @return list<string> signatures "METHODE chemin"
     */
    private function securitySignatures(): array
    {
        return array_map(
            static fn (array $entry): string => $entry[0] . ' ' . $entry[1],
            RouteSecurity::ENTRIES,
        );
    }

    public function testEveryRegisteredRouteHasExactlyOneRouteSecurityEntry(): void
    {
        $routes = $this->routeSignatures();
        $security = $this->securitySignatures();

        $missing = array_values(array_diff($routes, $security));
        self::assertSame(
            [],
            $missing,
            'Route(s) enregistree(s) dans routes.php sans ligne RouteSecurity::ENTRIES correspondante : ' . implode(', ', $missing),
        );
    }

    public function testEveryRouteSecurityEntryMatchesARegisteredRoute(): void
    {
        $routes = $this->routeSignatures();
        $security = $this->securitySignatures();

        $orphaned = array_values(array_diff($security, $routes));
        self::assertSame(
            [],
            $orphaned,
            'Ligne(s) RouteSecurity::ENTRIES sans route correspondante dans routes.php (route supprimee, entree oubliee) : ' . implode(', ', $orphaned),
        );
    }

    public function testRouteSecurityEntriesHaveNoDuplicateSignature(): void
    {
        $security = $this->securitySignatures();
        $duplicates = array_values(array_diff_assoc($security, array_unique($security)));

        self::assertSame(
            [],
            $duplicates,
            'Signature(s) "METHODE chemin" dupliquee(s) dans RouteSecurity::ENTRIES : ' . implode(', ', $duplicates),
        );
    }

    public function testRouterHasNoDuplicateRouteSignature(): void
    {
        $routes = $this->routeSignatures();
        $duplicates = array_values(array_diff_assoc($routes, array_unique($routes)));

        self::assertSame(
            [],
            $duplicates,
            'Signature(s) "METHODE chemin" dupliquee(s) dans routes.php : ' . implode(', ', $duplicates),
        );
    }

    public function testTotalEntriesIs158(): void
    {
        self::assertCount(158, RouteSecurity::ENTRIES);
        self::assertCount(158, $this->routeSignatures());
    }

    /**
     * Exigences de securite des 2 nouvelles routes (contrat, bloc "Ce que fait
     * la page") : session requise, permission role.manage (aucune permission
     * nouvelle), lecture seule (pas de CSRF/PIN/reauth).
     */
    public function testHealthRoutesRequireSessionAndRoleManageReadOnly(): void
    {
        /** @var array<string, array{0: bool, 1: ?string, 2: ?string, 3: ?string, 4: ?string}> $byPath */
        $byPath = [];
        foreach (RouteSecurity::ENTRIES as [$method, $path, $anon, $perm, $csrf, $pin, $reauth]) {
            $byPath[$method . ' ' . $path] = [$anon, $perm, $csrf, $pin, $reauth];
        }

        foreach (['GET /admin/health', 'GET /admin/api/health'] as $signature) {
            $entry = array_key_exists($signature, $byPath) ? $byPath[$signature] : null;
            self::assertNotNull($entry, "$signature devrait avoir une ligne RouteSecurity.");

            [$anon, $perm, $csrf, $pin, $reauth] = $entry;

            self::assertFalse($anon, "$signature devrait exiger une session (sans_compte = false).");
            self::assertSame('role.manage', $perm, "$signature devrait exiger la permission role.manage.");
            self::assertNull($csrf, "$signature est une lecture (GET) : aucun CSRF attendu.");
            self::assertNull($pin, "$signature est une lecture seule : aucun PIN attendu.");
            self::assertNull($reauth, "$signature est une lecture seule : aucune reauthentification attendue.");
        }
    }
}
