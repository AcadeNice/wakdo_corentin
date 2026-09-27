<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use App\Auth\Csrf;
use App\Auth\SessionManager;
use App\Controllers\AuthenticatedController;
use App\Core\Response;
use App\Tests\Support\FakeDatabase;
use App\Tests\Support\HtmlRouteHarness;

/**
 * Badge « sans compte » de la page « Santé de l'API », back-office HTML : pour
 * CHAQUE ligne de `RouteSecurity::ENTRIES` hors `/api/*` et `/admin/api/*`.
 *
 *  - route SANS la mention « sans compte », appelee sans aucune session : la
 *    garde renvoie vers /login (302), ou 401 JSON AUTH_REQUIRED pour un
 *    controleur qui ne passe pas par la garde de page (GET /admin/me) ;
 *  - route « sans compte » : sa reponse sans session est EXACTEMENT celle
 *    qu'elle donne a un equipier connecte (meme statut, meme redirection) --
 *    la session n'y est donc pas consultee. Comparer aux deux sessions, plutot
 *    que d'interdire toute redirection vers /login, garde vrai le cas de GET /,
 *    qui renvoie vers /login pour TOUT LE MONDE (HomeController).
 *
 * @phpstan-import-type Entry from HtmlRouteHarness
 */
final class HtmlRouteSessionTest extends TestCase
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
     * @return array<string, array{0: string, 1: string}>
     */
    public static function accountRoutes(): array
    {
        $cases = [];
        foreach (HtmlRouteHarness::boEntries() as $entry) {
            if (!$entry[2]) {
                $cases[HtmlRouteHarness::key($entry)] = [$entry[0], $entry[1]];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function anonymousRoutes(): array
    {
        $cases = [];
        foreach (HtmlRouteHarness::boEntries() as $entry) {
            if ($entry[2]) {
                $cases[HtmlRouteHarness::key($entry)] = [$entry[0], $entry[1]];
            }
        }

        return $cases;
    }

    #[DataProvider('accountRoutes')]
    public function testRouteRequiringAnAccountTurnsAwayACallWithoutSession(string $method, string $path): void
    {
        // Aucune permission accordee, aucun jeton : seule la session manque, et
        // c'est elle que la reponse doit refuser AVANT tout le reste.
        $db = HtmlRouteHarness::grantedDb([]);
        HtmlRouteHarness::primeWorld($db, $method, $path);
        $form = $method === 'GET' ? null : HtmlRouteHarness::form(HtmlRouteHarness::scenario($method . ' ' . $path) ?? []);

        $response = HtmlRouteHarness::invoke($method, $path, HtmlRouteHarness::blankSession(), $db, $form);

        [$class] = HtmlRouteHarness::handler($method, $path);
        if (HtmlRouteHarness::usesHtmlLoginRedirect($class)) {
            self::assertSame(302, $response->status(), "$method $path sans session : la table annonce un compte requis, la garde devrait rediriger (302). Obtenu {$response->status()}.");
            self::assertSame('/login', $response->header('Location'), "$method $path sans session devrait rediriger vers /login.");
        } elseif (is_subclass_of($class, AuthenticatedController::class)) {
            $body = json_decode($response->body(), true);
            self::assertSame(401, $response->status(), "$method $path sans session : la table annonce un compte requis, reponse JSON 401 attendue. Obtenu {$response->status()}.");
            self::assertSame('AUTH_REQUIRED', is_array($body) ? ($body['error']['code'] ?? null) : null, "$method $path sans session devrait porter le code AUTH_REQUIRED.");
        } else {
            // Controleur sans AUCUNE garde de session (ni AdminController ni
            // AuthenticatedController) : il ne peut pas « exiger un compte ».
            self::fail(sprintf(
                'ECART TABLE/CODE : %s %s est annoncee « compte requis » (sans_compte = false), mais %s ne consulte pas la session ; sans session elle repond %d%s au lieu d\'un refus de la garde.',
                $method,
                $path,
                $class,
                $response->status(),
                $response->header('Location') !== null ? ' vers ' . $response->header('Location') : '',
            ));
        }
        self::assertSame([], HtmlRouteHarness::writes($db), "$method $path sans session ne devrait rien ecrire.");
    }

    #[DataProvider('anonymousRoutes')]
    public function testRouteWithoutAccountAnswersTheSameWithOrWithoutSession(string $method, string $path): void
    {
        // Visiteur sans compte : session porteuse du seul jeton anti-rejeu (celui
        // que /login a pose), formulaire valide -- l'action s'execute vraiment,
        // on ne compare pas deux refus du jeton.
        $anonymousSession = HtmlRouteHarness::anonymousSession();
        $loggedInSession = HtmlRouteHarness::authenticatedSession();

        $anonymous = $this->call($method, $path, $anonymousSession, HtmlRouteHarness::grantedDb([]));
        $loggedIn = $this->call($method, $path, $loggedInSession, HtmlRouteHarness::grantedDb(HtmlRouteHarness::permissionCatalogue()));

        self::assertNotSame(401, $anonymous->status(), "$method $path est annoncee « sans compte » mais refuse un appel sans session (401).");
        self::assertSame($loggedIn->status(), $anonymous->status(), "$method $path est annoncee « sans compte » : sans session elle devrait repondre comme a un equipier connecte.");
        self::assertSame($loggedIn->header('Location'), $anonymous->header('Location'), "$method $path est annoncee « sans compte » : sa redirection ne devrait pas dependre de la session.");
    }

    private function call(string $method, string $path, SessionManager $session, FakeDatabase $db): Response
    {
        $scenario = HtmlRouteHarness::scenario($method . ' ' . $path) ?? [];
        $form = null;
        if ($method !== 'GET') {
            $form = HtmlRouteHarness::form($scenario) + ['_csrf' => Csrf::token($session)];
        }

        return HtmlRouteHarness::exercise($method, $path, $session, $db, $form, $scenario);
    }
}
