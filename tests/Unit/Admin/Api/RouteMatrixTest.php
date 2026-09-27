<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\SessionManager;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Health\RouteSecurity;
use App\Tests\Support\FakeDatabase;

// Les 8 doubles `Test<Ressource>ApiController` (+ leurs stubs de repository)
// existent deja, un par fichier de test de ressource -- ecrits pour reutiliser
// exactement les memes seams (sessionManager/sessionGuard/authorizer/db, et les
// stubs Order/Stats deja necessaires pour eviter une vraie connexion DB). Un
// `require_once` explicite (plutot que de compter sur l'ordre de decouverte de
// PHPUnit, qui charge bien tous les fichiers de test AVANT d'en executer aucun,
// mais ne le garantit pas si ce fichier est lance seul via --filter) : la classe
// existe alors QUELLE QUE SOIT la facon dont ce fichier est invoque.
require_once __DIR__ . '/CategoryApiControllerTest.php';
require_once __DIR__ . '/ProductApiControllerTest.php';
require_once __DIR__ . '/MenuApiControllerTest.php';
require_once __DIR__ . '/IngredientApiControllerTest.php';
require_once __DIR__ . '/UserApiControllerTest.php';
require_once __DIR__ . '/RoleApiControllerTest.php';
require_once __DIR__ . '/OrderApiControllerTest.php';
require_once __DIR__ . '/StatsApiControllerTest.php';
// 9e double, ajoute par le chantier "Sante de l'API" : TestHealthApiController,
// meme convention de nom et de constructeur que les 8 precedents (verifie ici,
// pas suppose), pour GET /admin/api/health.
require_once __DIR__ . '/HealthApiControllerTest.php';

/**
 * Relecture adverse (2e passe, point 2) : table de donnees couvrant TOUTES les
 * routes `/admin/api/*` (hors `/admin/api/auth/*`, cf. plus bas), verifiees
 * route par route plutot que par quelques exemples eparpilles. Pour chaque
 * route d'ECRITURE (POST/PUT/DELETE) : (a) sans `X-CSRF-Token` -> 403 ; (b) avec
 * un jeton FAUX -> 403 ; (c) avec TOUTES les permissions du catalogue SAUF la
 * permission exacte -> 403 ; (d) avec SEULEMENT la permission exacte (+ CSRF
 * valide + PIN valide si l'action en a besoin) -> jamais 403. Pour les routes de
 * LECTURE (GET), seul (c) s'applique (elles n'exigent pas de CSRF).
 *
 * Depuis le chantier "Sante de l'API" : la table `ROUTES` codee en dur a
 * disparu. La LISTE des routes vient desormais de `App\Core\Router::routes()`
 * (charge depuis `src/app/Core/routes.php` sur un Router neuf, jamais un texte
 * relu par expression reguliere) et la PERMISSION exacte de chaque route vient
 * de `App\Health\RouteSecurity::ENTRIES` -- la meme source unique que la carte
 * des routes de la page "Sante de l'API". Le test qui echoue quand une route est
 * ajoutee sans etre securisee vit maintenant dans
 * tests/Unit/Health/RouteSecurityCoverageTest.php (bijection Router <->
 * RouteSecurity sur TOUT le routeur, pas seulement /admin/api/*) ;
 * derivedRoutes() ci-dessous ne garde que le nom court de ressource necessaire
 * pour retrouver le double de test `Test<Ressource>ApiController` (une
 * information de test, pas une exigence de securite -- elle peut donc rester
 * ici sans dupliquer RouteSecurity).
 *
 * `GET /admin/api/health` (nouvelle route du chantier "Sante de l'API") EST
 * incluse dans cette matrice comme la 9e ressource ('Health' ->
 * `TestHealthApiController`, fourni par `HealthApiControllerTest.php`, meme
 * convention que les 8 autres) : requiere_once ci-dessus, aucune fixture
 * supplementaire necessaire (pas de {id}/{number} dans son chemin, pas
 * d'ecriture).
 */
final class RouteMatrixTest extends TestCase
{
    /**
     * Catalogue complet des 23 permissions du seed (db/seeds/0001_rbac_and_reference.sql).
     *
     * @var list<string>
     */
    private const ALL_PERMISSIONS = [
        'category.manage', 'ingredient.manage',
        'menu.create', 'menu.delete', 'menu.read', 'menu.update',
        'order.cancel', 'order.create', 'order.deliver', 'order.read',
        'product.create', 'product.delete', 'product.read', 'product.update',
        'role.manage', 'stats.read',
        'stock.count', 'stock.manage', 'stock.read',
        'user.create', 'user.deactivate', 'user.read', 'user.update',
    ];

    /**
     * Routes `/admin/api/*` (hors `/admin/api/auth/*`, cf. docblock de classe)
     * qui ont un `Test<Ressource>ApiController` dans ce fichier : lues EN DIRECT
     * dans le Router (routes.php), la permission EXACTE venant de
     * RouteSecurity::ENTRIES. Chaque ligne :
     * [methode, chemin, ressource (nom court -> `Test<Ressource>ApiController`),
     * action, permission EXACTE attendue, ecriture (determine si CSRF/PIN
     * s'appliquent -- toute route non-GET de cette surface est une ecriture)].
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private static function derivedRoutes(): array
    {
        $router = new Router(new Config(), new Database(new Config()));
        (require __DIR__ . '/../../../../src/app/Core/routes.php')($router);

        $permissionBySignature = [];
        foreach (RouteSecurity::ENTRIES as [$method, $path, , $perm]) {
            $permissionBySignature[$method . ' ' . $path] = $perm;
        }

        $rows = [];
        foreach ($router->routes() as $route) {
            $path = $route['pattern'];
            if (!str_starts_with($path, '/admin/api/') || str_starts_with($path, '/admin/api/auth/')) {
                continue;
            }

            $signature = $route['method'] . ' ' . $path;
            self::assertArrayHasKey($signature, $permissionBySignature, "Route $signature sans entree RouteSecurity : la table a-t-elle divergé du Router ?");

            [$controllerClass, $action] = $route['handler'];
            $basename = substr($controllerClass, strrpos($controllerClass, '\\') + 1);
            $resource = str_ends_with($basename, 'ApiController') ? substr($basename, 0, -13) : $basename;

            $rows[] = [
                $route['method'],
                $path,
                $resource,
                $action,
                $permissionBySignature[$signature],
                $route['method'] !== 'GET',
            ];
        }

        return $rows;
    }

    private SessionManager $session;
    private string $csrf = '';

    protected function setUp(): void
    {
        putenv('SESSION_LIFETIME_IDLE=14400');
        putenv('SESSION_LIFETIME_ABSOLUTE=36000');

        $this->session = new SessionManager(new Config(), true);
        $now = time();
        $this->session->set('user_id', 1);
        $this->session->set('role_id', 1);
        $this->session->set('logged_in_at', $now - 100);
        $this->session->set('last_activity', $now - 50);
        $this->csrf = Csrf::token($this->session);
    }

    protected function tearDown(): void
    {
        putenv('SESSION_LIFETIME_IDLE');
        putenv('SESSION_LIFETIME_ABSOLUTE');
    }

    /**
     * Canari de cette matrice : la bijection COMPLETE Router <-> RouteSecurity
     * (toutes surfaces confondues) est verifiee ailleurs
     * (tests/Unit/Health/RouteSecurityCoverageTest.php) ; celui-ci verifie
     * seulement que le sous-ensemble /admin/api/* (hors auth/*) exploite par
     * CETTE matrice comportementale garde le nombre attendu de routes -- un
     * changement de perimetre ici (ajout/retrait d'une ressource) doit se voir
     * immediatement, avant meme d'executer les data providers. 53 = les 52
     * routes historiques + GET /admin/api/health (chantier "Sante de l'API").
     */
    public function testDerivedRoutesMatchTheKnownAdminApiSurface(): void
    {
        $rows = self::derivedRoutes();

        self::assertCount(53, $rows, 'Nombre de routes /admin/api/* (hors auth/*) inattendu : perimetre de la matrice a verifier.');

        $writeCount = count(array_filter($rows, static fn (array $r): bool => $r[5]));
        self::assertSame(35, $writeCount, "Nombre de routes d'ecriture inattendu parmi les routes derivees.");
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function writeRoutesProvider(): array
    {
        $cases = [];
        foreach (self::derivedRoutes() as [$method, $path, $resource, $action, $permission, $isWrite]) {
            if (!$isWrite) {
                continue;
            }
            $cases[$method . ' ' . $path] = [$method, $path, $resource, $action, $permission];
        }

        return $cases;
    }

    #[DataProvider('writeRoutesProvider')]
    public function testWriteRouteRejectsMissingCsrf(string $method, string $path, string $resource, string $action, string $permission): void
    {
        // Donnees de reussite preparees ICI AUSSI (pas seulement au cas (d),
        // relecture n#3 point 1) : sans elles, les 4 routes de commande a
        // {number} (numero absent des fixtures) renvoient 403 par l'anti-
        // enumeration RG-T12 QUELLE QUE SOIT la garde CSRF -- un mutant qui
        // retire `requireCsrf()` passait alors inapercu (prouve par mutation).
        $db = $this->grantedDb([$permission]);
        $this->primeForSuccess($db, $resource);
        $request = $this->buildRequest($method, $path, null);

        $response = $this->invoke($resource, $action, $request, $db, $this->routeParams($path, $resource));
        $body = json_decode($response->body(), true);

        self::assertSame(403, $response->status(), "$method $path (sans X-CSRF-Token) devrait renvoyer 403");
        self::assertSame('CSRF_INVALID', $body['error']['code'] ?? null, "$method $path (sans X-CSRF-Token) devrait porter le code CSRF_INVALID, pas un 403 venu d'ailleurs (permission, anti-enumeration)");
    }

    #[DataProvider('writeRoutesProvider')]
    public function testWriteRouteRejectsWrongCsrfToken(string $method, string $path, string $resource, string $action, string $permission): void
    {
        $db = $this->grantedDb([$permission]);
        $this->primeForSuccess($db, $resource);
        $request = $this->buildRequest($method, $path, 'un-jeton-invente-qui-ne-matche-jamais');

        $response = $this->invoke($resource, $action, $request, $db, $this->routeParams($path, $resource));
        $body = json_decode($response->body(), true);

        self::assertSame(403, $response->status(), "$method $path (X-CSRF-Token invalide) devrait renvoyer 403");
        self::assertSame('CSRF_INVALID', $body['error']['code'] ?? null, "$method $path (X-CSRF-Token invalide) devrait porter le code CSRF_INVALID");
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    public static function allRoutesProvider(): array
    {
        $cases = [];
        foreach (self::derivedRoutes() as [$method, $path, $resource, $action, $permission, $isWrite]) {
            $cases[$method . ' ' . $path] = [$method, $path, $resource, $action, $permission, $isWrite];
        }

        return $cases;
    }

    #[DataProvider('allRoutesProvider')]
    public function testRouteRejectsEveryPermissionExceptTheExactOne(string $method, string $path, string $resource, string $action, string $permission, bool $isWrite): void
    {
        $others = array_values(array_diff(self::ALL_PERMISSIONS, [$permission]));
        $db = $this->grantedDb($others);
        $this->primeForSuccess($db, $resource);
        $request = $this->buildRequest($method, $path, $isWrite ? $this->csrf : null);

        $response = $this->invoke($resource, $action, $request, $db, $this->routeParams($path, $resource));
        $body = json_decode($response->body(), true);

        self::assertSame(403, $response->status(), "$method $path avec toutes les permissions SAUF '$permission' devrait renvoyer 403");
        self::assertSame('FORBIDDEN', $body['error']['code'] ?? null, "$method $path avec toutes les permissions SAUF '$permission' devrait porter le code FORBIDDEN de guardApi(), jamais un NOT_FOUND qui ne compterait pas comme un refus de permission");
    }

    #[DataProvider('allRoutesProvider')]
    public function testRouteWithExactPermissionPinAndCsrfIsNeverForbidden(string $method, string $path, string $resource, string $action, string $permission, bool $isWrite): void
    {
        $db = $this->grantedDb([$permission]);
        $this->primeForSuccess($db, $resource);
        $request = $this->buildRequest($method, $path, $isWrite ? $this->csrf : null, $this->bodyFor($resource));

        $response = $this->invoke($resource, $action, $request, $db, $this->routeParams($path, $resource));

        // Renforce (relecture n#3, point 1) : ni 403 (garde qui bloquerait a tort)
        // NI 404 (ligne manquante qui empecherait meme d'ATTEINDRE l'action) --
        // primeForSuccess() fournit la ligne {id}/{number} necessaire a chaque
        // ressource pour que l'action soit reellement exercee.
        self::assertNotSame(403, $response->status(), "$method $path avec la permission exacte '$permission' (+ CSRF/PIN valides) ne devrait jamais renvoyer 403 (obtenu {$response->status()})");
        self::assertNotSame(404, $response->status(), "$method $path avec la permission exacte '$permission' (+ CSRF/PIN valides) devrait ATTEINDRE l'action (ligne {id}/{number} preparee), pas 404");
    }

    // --- aides ---

    /**
     * @param list<string> $granted
     */
    private function grantedDb(array $granted): FakeDatabase
    {
        $db = new FakeDatabase();
        $db->guardUserRow = ['is_active' => 1];
        $db->grantedCodes = $granted;
        $db->actingUserRow = ['id' => 9, 'role_id' => 1, 'pin_hash' => (new PasswordHasher(new Config()))->hash('4729')];

        return $db;
    }

    /**
     * Fixture la ligne {id}/{number} de CHAQUE ressource, appelee dans les 4 cas
     * (a/b/c/d) -- pas seulement (d) (relecture n#3, point 1 : sans elle, les 4
     * routes de commande a {number} -- numero absent des fixtures par defaut --
     * renvoient 403 par l'anti-enumeration RG-T12 quelle que soit la garde
     * CSRF/permission testee, masquant un mutant qui la retirerait ; prouve par
     * mutation par le relecteur). Sans effet sur les routes sans {id}/{number}
     * (index/store), qui n'ont besoin d'aucune ligne prealable.
     */
    private function primeForSuccess(FakeDatabase $db, string $resource): void
    {
        match ($resource) {
            'Category' => $db->categoryRow = ['id' => 1, 'name' => 'Boissons', 'slug' => 'boissons', 'image_path' => null, 'display_order' => 1],
            'Product' => $db->productRow = ['id' => 1, 'category_id' => 3, 'name' => 'Big Mac', 'description' => null, 'price_cents' => 590, 'size_cl' => null, 'base_product_id' => null, 'maxi_variant_product_id' => null, 'vat_rate' => 100, 'image_path' => null, 'is_available' => 1, 'display_order' => 1],
            'Menu' => $db->menuRow = ['id' => 1, 'category_id' => 3, 'burger_product_id' => 7, 'name' => 'Menu Big Mac', 'price_normal_cents' => 890, 'price_maxi_cents' => 990, 'is_available' => 1, 'display_order' => 1],
            'Ingredient' => $db->ingredientRow = ['id' => 1, 'name' => 'Pain burger', 'unit' => 'unite', 'is_active' => 1, 'stock_quantity' => 10, 'stock_capacity' => 500, 'pack_size' => 50, 'pack_label' => null, 'low_stock_pct' => 20, 'critical_stock_pct' => 5],
            // id=5, distinct du user_id=1 de la session agissante (voir routeParams()).
            'User' => $db->userManageRow = ['id' => 5, 'email' => 'e@wakdo.fr', 'first_name' => 'E', 'last_name' => 'Q', 'role_id' => 2, 'is_active' => 1],
            'Role' => $db->roleManageRow = ['id' => 1, 'code' => 'shift_lead', 'label' => 'Chef', 'description' => null, 'default_route' => null, 'order_source' => null, 'is_active' => 1],
            'Order' => $db->orderByNumberRow = ['id' => 100, 'order_number' => 'C1', 'source' => 'counter', 'status' => 'paid', 'total_ttc_cents' => 100],
            default => null,
        };
    }

    /**
     * Corps JSON minimal pour chaque ressource, avec les champs PIN valides
     * (`pin_email`/`pin`) systematiquement inclus : ignores par les actions qui
     * n'en ont pas besoin, consommes par celles qui en ont besoin (RG-T13).
     *
     * @return array<string, mixed>
     */
    private function bodyFor(string $resource): array
    {
        $pin = ['pin_email' => 'e@e.fr', 'pin' => '4729'];

        return match ($resource) {
            'Order' => ['service_mode' => 'dine_in', 'source' => 'counter', 'items' => [], 'direction' => 'up'] + $pin,
            default => ['direction' => 'up'] + $pin,
        };
    }

    /**
     * @return array<string, string>
     */
    private function routeParams(string $path, string $resource): array
    {
        if (str_contains($path, '{id}')) {
            // User : id distinct du user_id=1 de la session agissante (setUp()) --
            // sinon apiDestroy()/apiErase() refusent a raison "vous ne pouvez pas
            // agir sur votre propre compte" (403 legitime, mais qui masquerait la
            // garde de permission testee ici). Neutre pour les autres ressources.
            return ['id' => $resource === 'User' ? '5' : '1'];
        }
        if (str_contains($path, '{number}')) {
            return ['number' => 'C1'];
        }

        return [];
    }

    private function concretePath(string $path): string
    {
        return str_replace(['{id}', '{number}'], ['1', 'C1'], $path);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function buildRequest(string $method, string $path, ?string $csrfToken, ?array $body = null): Request
    {
        $headers = [];
        if ($csrfToken !== null) {
            $headers['x-csrf-token'] = $csrfToken;
        }
        $raw = '';
        if ($body !== null) {
            $headers['content-type'] = 'application/json';
            $raw = (string) json_encode($body);
        }

        return new Request($method, $this->concretePath($path), [], $headers, $raw, '203.0.113.5');
    }

    /**
     * @param array<string, string> $params
     */
    private function invoke(string $resource, string $action, Request $request, FakeDatabase $db, array $params, ?SessionManager $session = null): Response
    {
        $class = __NAMESPACE__ . '\\Test' . $resource . 'ApiController';
        $controller = new $class($request, new Config(), new Database(new Config()), $session ?? $this->session, $db);

        /** @var Response $response */
        $response = $controller->$action($params);

        return $response;
    }

    /**
     * Point 4 du chantier login JSON (docs/api/conventions.md section 5.3bis) :
     * TOUTE route `/admin/api/*` protegee, sans session (absente -- pas juste
     * expiree ni compte desactive, deja couverts par les tests unitaires de
     * SessionGuard), renvoie du JSON `401 AUTH_REQUIRED` -- JAMAIS une redirection
     * HTML vers `/login` -- avant meme d'atteindre la verification CSRF/permission.
     * Couvre les 53 routes de derivedRoutes() ; les 3 routes `/admin/api/auth/*`
     * (hors de cette liste, cf. docblock de classe) sont couvertes a part dans
     * AuthApiControllerTest (logout/me exigent une session, login n'en exige
     * aucune par construction).
     */
    #[DataProvider('allRoutesProvider')]
    public function testRouteRejectsNoSessionWithJsonAuthRequired(string $method, string $path, string $resource, string $action, string $permission, bool $isWrite): void
    {
        // Session VIDE (aucune cle posee), distincte de $this->session (deja
        // authentifiee dans setUp()) : simule un appel Postman sans cookie de
        // session, pas une session expiree ou un compte desactive (deja testes
        // ailleurs, SessionGuardTest).
        $blankSession = new SessionManager(new Config(), true);
        $db = $this->grantedDb([$permission]);
        $this->primeForSuccess($db, $resource);
        // CSRF/PIN non pertinents ici : guardApi() renvoie 401 avant de les lire.
        $request = $this->buildRequest($method, $path, null);

        $response = $this->invoke($resource, $action, $request, $db, $this->routeParams($path, $resource), $blankSession);
        $body = json_decode($response->body(), true);

        self::assertSame(401, $response->status(), "$method $path sans session devrait renvoyer 401, pas une redirection HTML");
        self::assertSame('AUTH_REQUIRED', $body['error']['code'] ?? null, "$method $path sans session devrait porter le code AUTH_REQUIRED");
        self::assertSame('application/json; charset=utf-8', $response->header('Content-Type'), "$method $path sans session devrait rester du JSON, jamais du HTML");
    }
}
