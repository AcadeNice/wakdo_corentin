<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use App\Auth\Csrf;
use App\Core\Response;
use App\Tests\Support\FakeDatabase;
use App\Tests\Support\HtmlRouteHarness;

/**
 * Badge « permission » de la page « Santé de l'API », back-office HTML. Meme
 * construction que RouteMatrixTest (API JSON), appliquee aux pages :
 *
 *  - toutes les permissions du catalogue SAUF celle annoncee : 403 ;
 *  - SEULEMENT celle annoncee (+ jeton valide + code personnel valide pour une
 *    ecriture) : ni 403 (garde qui bloquerait a tort) ni 404 (la ligne {id} /
 *    {number} est preparee : l'action est reellement atteinte).
 *
 * Les deux cas ensemble montrent que la permission annoncee est la SEULE qui
 * ouvre la route. Pour une route connectee SANS permission annoncee, le sens
 * inverse : aucune permission detenue, la route s'ouvre quand meme (sinon la
 * page tairait une exigence reelle).
 */
final class HtmlRoutePermissionTest extends TestCase
{
    protected function setUp(): void
    {
        HtmlRouteHarness::applyEnv();
    }

    protected function tearDown(): void
    {
        HtmlRouteHarness::clearEnv();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function permissionRoutes(): array
    {
        $cases = [];
        foreach (HtmlRouteHarness::boEntries() as $entry) {
            if ($entry[3] !== null) {
                $cases[HtmlRouteHarness::key($entry)] = [$entry[0], $entry[1], $entry[3]];
            }
        }

        return $cases;
    }

    /**
     * Routes qui exigent un compte mais aucune permission.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function accountOnlyRoutes(): array
    {
        $cases = [];
        foreach (HtmlRouteHarness::boEntries() as $entry) {
            if (!$entry[2] && $entry[3] === null) {
                $cases[HtmlRouteHarness::key($entry)] = [$entry[0], $entry[1]];
            }
        }

        return $cases;
    }

    #[DataProvider('permissionRoutes')]
    public function testEveryPermissionExceptTheAnnouncedOneIsForbidden(string $method, string $path, string $permission): void
    {
        $catalogue = HtmlRouteHarness::permissionCatalogue();
        self::assertContains($permission, $catalogue, "$method $path annonce '$permission', absente du catalogue du seed RBAC.");
        $others = array_values(array_diff($catalogue, [$permission]));

        $db = HtmlRouteHarness::grantedDb($others);
        $response = $this->call($method, $path, $db);

        self::assertSame(403, $response->status(), "$method $path avec toutes les permissions SAUF '$permission' devrait renvoyer 403 (obtenu {$response->status()}).");
        self::assertSame([], HtmlRouteHarness::writes($db), "$method $path refusee faute de '$permission' ne devrait rien ecrire.");
    }

    #[DataProvider('permissionRoutes')]
    public function testTheAnnouncedPermissionAloneOpensTheRoute(string $method, string $path, string $permission): void
    {
        $response = $this->call($method, $path, HtmlRouteHarness::grantedDb([$permission]));

        self::assertNotSame(403, $response->status(), "$method $path avec SEULEMENT '$permission' (+ jeton et code valides) ne devrait pas renvoyer 403.");
        self::assertNotSame(404, $response->status(), "$method $path avec SEULEMENT '$permission' devrait atteindre l'action (ligne preparee), pas 404.");
        self::assertNotSame('/login', $response->header('Location'), "$method $path avec une session valide ne devrait pas renvoyer vers /login.");
    }

    #[DataProvider('accountOnlyRoutes')]
    public function testRouteWithoutAnnouncedPermissionOpensWithNoPermissionAtAll(string $method, string $path): void
    {
        $response = $this->call($method, $path, HtmlRouteHarness::grantedDb([]));

        self::assertNotSame(403, $response->status(), "$method $path n'annonce aucune permission mais refuse (403) une session qui n'en detient aucune.");
        self::assertNotSame(401, $response->status(), "$method $path avec une session valide ne devrait pas renvoyer 401.");
        // Sauf pour une route dont la REUSSITE mene a /login (deconnexion).
        $scenario = HtmlRouteHarness::scenario($method . ' ' . $path) ?? [];
        if (($scenario['location'] ?? null) !== '/login') {
            self::assertNotSame('/login', $response->header('Location'), "$method $path avec une session valide ne devrait pas renvoyer vers /login.");
        }
    }

    /**
     * Session connectee, monde prepare, scenario de reussite ; pour une ecriture,
     * jeton valide + code personnel valide : seule la permission varie d'un cas
     * a l'autre.
     */
    private function call(string $method, string $path, FakeDatabase $db): Response
    {
        $session = HtmlRouteHarness::authenticatedSession();
        HtmlRouteHarness::primeWorld($db, $method, $path);
        $scenario = HtmlRouteHarness::scenario($method . ' ' . $path) ?? [];
        $form = null;
        if ($method !== 'GET') {
            $form = HtmlRouteHarness::form($scenario) + ['_csrf' => Csrf::token($session)] + HtmlRouteHarness::pinFields();
        }

        return HtmlRouteHarness::exercise($method, $path, $session, $db, $form, $scenario);
    }
}
