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

/**
 * Relecture adverse (2e passe, point 2) : table de donnees couvrant TOUTES les
 * routes `/admin/api/*` (index.php), verifiees route par route plutot que par
 * quelques exemples eparpilles. Pour chaque route d'ECRITURE (POST/PUT/DELETE) :
 * (a) sans `X-CSRF-Token` -> 403 ; (b) avec un jeton FAUX -> 403 ; (c) avec TOUTES
 * les permissions du catalogue SAUF la permission exacte -> 403 ; (d) avec
 * SEULEMENT la permission exacte (+ CSRF valide + PIN valide si l'action en a
 * besoin) -> jamais 403. Pour les routes de LECTURE (GET), seul (c) s'applique
 * (elles n'exigent pas de CSRF). `testRouteTableCoversEveryRegisteredRoute()`
 * relit `index.php` et echoue si une route y est ajoutee/retiree sans que cette
 * table soit mise a jour en meme temps.
 */
final class RouteMatrixTest extends TestCase
{
    private const INDEX_PHP = __DIR__ . '/../../../../src/public/admin/index.php';

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
     * Chaque ligne : [methode, chemin (gabarit brut d'index.php), ressource
     * (nom court -> `Test<Ressource>ApiController`), action, permission EXACTE
     * attendue, ecriture (bool, determine si CSRF/PIN s'appliquent).
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private const ROUTES = [
        ['GET', '/admin/api/categories', 'Category', 'apiIndex', 'category.manage', false],
        ['GET', '/admin/api/categories/{id}', 'Category', 'apiShow', 'category.manage', false],
        ['POST', '/admin/api/categories', 'Category', 'apiStore', 'category.manage', true],
        ['PUT', '/admin/api/categories/{id}', 'Category', 'apiUpdate', 'category.manage', true],
        ['DELETE', '/admin/api/categories/{id}', 'Category', 'apiDestroy', 'category.manage', true],
        ['POST', '/admin/api/categories/{id}/toggle', 'Category', 'apiToggle', 'category.manage', true],
        ['POST', '/admin/api/categories/{id}/move', 'Category', 'apiMove', 'category.manage', true],

        ['GET', '/admin/api/products', 'Product', 'apiIndex', 'product.read', false],
        ['GET', '/admin/api/products/{id}', 'Product', 'apiShow', 'product.read', false],
        ['POST', '/admin/api/products', 'Product', 'apiStore', 'product.create', true],
        ['PUT', '/admin/api/products/{id}', 'Product', 'apiUpdate', 'product.update', true],
        ['DELETE', '/admin/api/products/{id}', 'Product', 'apiDestroy', 'product.delete', true],
        ['POST', '/admin/api/products/{id}/move', 'Product', 'apiMove', 'product.update', true],
        ['GET', '/admin/api/products/{id}/recipe', 'Product', 'apiRecipeShow', 'ingredient.manage', false],
        ['PUT', '/admin/api/products/{id}/recipe', 'Product', 'apiRecipeSave', 'ingredient.manage', true],

        ['GET', '/admin/api/menus', 'Menu', 'apiIndex', 'menu.read', false],
        ['GET', '/admin/api/menus/{id}', 'Menu', 'apiShow', 'menu.read', false],
        ['POST', '/admin/api/menus', 'Menu', 'apiStore', 'menu.create', true],
        ['PUT', '/admin/api/menus/{id}', 'Menu', 'apiUpdate', 'menu.update', true],
        ['DELETE', '/admin/api/menus/{id}', 'Menu', 'apiDestroy', 'menu.delete', true],
        ['POST', '/admin/api/menus/{id}/toggle', 'Menu', 'apiToggle', 'menu.update', true],

        ['GET', '/admin/api/ingredients', 'Ingredient', 'apiIndex', 'stock.read', false],
        ['GET', '/admin/api/ingredients/{id}', 'Ingredient', 'apiShow', 'stock.read', false],
        ['POST', '/admin/api/ingredients', 'Ingredient', 'apiStore', 'ingredient.manage', true],
        ['PUT', '/admin/api/ingredients/{id}', 'Ingredient', 'apiUpdate', 'ingredient.manage', true],
        ['DELETE', '/admin/api/ingredients/{id}', 'Ingredient', 'apiDestroy', 'ingredient.manage', true],
        ['POST', '/admin/api/ingredients/{id}/restock', 'Ingredient', 'apiRestock', 'stock.manage', true],
        ['POST', '/admin/api/ingredients/{id}/toggle', 'Ingredient', 'apiToggle', 'ingredient.manage', true],
        ['PUT', '/admin/api/ingredients/{id}/thresholds', 'Ingredient', 'apiThresholds', 'stock.manage', true],
        ['POST', '/admin/api/ingredients/{id}/inventory', 'Ingredient', 'apiInventory', 'stock.count', true],
        ['POST', '/admin/api/ingredients/{id}/adjust', 'Ingredient', 'apiAdjust', 'stock.count', true],
        ['PUT', '/admin/api/ingredients/{id}/allergens', 'Ingredient', 'apiAllergens', 'ingredient.manage', true],

        ['GET', '/admin/api/users', 'User', 'apiIndex', 'user.read', false],
        ['GET', '/admin/api/users/{id}', 'User', 'apiShow', 'user.read', false],
        ['POST', '/admin/api/users', 'User', 'apiStore', 'user.create', true],
        ['PUT', '/admin/api/users/{id}', 'User', 'apiUpdate', 'user.update', true],
        ['DELETE', '/admin/api/users/{id}', 'User', 'apiDestroy', 'user.deactivate', true],
        ['POST', '/admin/api/users/{id}/reset-pin', 'User', 'apiResetPin', 'user.update', true],
        ['POST', '/admin/api/users/{id}/erase', 'User', 'apiErase', 'user.update', true],

        ['GET', '/admin/api/roles', 'Role', 'apiIndex', 'role.manage', false],
        ['GET', '/admin/api/roles/{id}', 'Role', 'apiShow', 'role.manage', false],
        ['POST', '/admin/api/roles', 'Role', 'apiStore', 'role.manage', true],
        ['PUT', '/admin/api/roles/{id}', 'Role', 'apiUpdate', 'role.manage', true],

        ['GET', '/admin/api/orders', 'Order', 'apiIndex', 'order.read', false],
        ['GET', '/admin/api/orders/{number}', 'Order', 'apiShow', 'order.read', false],
        ['POST', '/admin/api/orders', 'Order', 'apiStore', 'order.create', true],
        ['POST', '/admin/api/orders/{number}/ready', 'Order', 'apiReady', 'order.read', true],
        ['POST', '/admin/api/orders/{number}/deliver', 'Order', 'apiDeliver', 'order.deliver', true],
        ['POST', '/admin/api/orders/{number}/cancel', 'Order', 'apiCancel', 'order.cancel', true],

        ['GET', '/admin/api/stats', 'Stats', 'apiIndex', 'stats.read', false],
    ];

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

    public function testRouteTableCoversEveryRegisteredRoute(): void
    {
        $source = (string) file_get_contents(self::INDEX_PHP);
        // `(?!auth/)` : exclut expressement `/admin/api/auth/*` (login/logout/me,
        // AuthApiController) de cette matrice CSRF/permission. Ces trois routes ne
        // partagent PAS son modele : `apiLogin` n'a ni permission (accessible SANS
        // session) ni jeton CSRF synchroniseur a comparer (aucune session avant
        // authentification reussie -- sa protection est le Content-Type impose (force un
        // preflight CORS ferme sur ce prefixe), documentee dans le docblock
        // d'AuthApiController et docs/adr/0017-api-admin-json.md).
        // Les inclure ici forcerait soit un `TestAuthApiController` factice pour un modele
        // de securite qui ne s'applique pas, soit des lignes ROUTES trompeuses (une
        // "permission exacte" qui n'existe pas pour un login public). Testees a part,
        // au comportement reel, dans AuthApiControllerTest (+ la garde 401-sans-session
        // ci-dessous, testRouteRejectsNoSessionWithJsonAuthRequired, qui elle NE les
        // exclut PAS : logout/me restent proteges par la meme garde que tout /admin/api/*).
        preg_match_all(
            '/\$router->add\(\'([A-Z]+)\',\s*\'(\/admin\/api\/(?!auth\/)[^\']*)\',\s*\[(\w+)ApiController::class,\s*\'(\w+)\'\]\)/',
            $source,
            $matches,
            PREG_SET_ORDER,
        );
        self::assertNotSame([], $matches, 'Aucune route /admin/api/* trouvee dans index.php : le regex a-t-il divergé du code ?');

        $registered = [];
        foreach ($matches as $m) {
            $registered[] = $m[1] . ' ' . $m[2] . ' -> ' . $m[3] . '::' . $m[4];
        }
        sort($registered);

        $tabled = [];
        foreach (self::ROUTES as [$method, $path, $resource, $action]) {
            $tabled[] = $method . ' ' . $path . ' -> ' . $resource . '::' . $action;
        }
        sort($tabled);

        // Echec ici = une route a ete ajoutee/retiree/renommee dans index.php SANS
        // repercuter le changement dans self::ROUTES -- donc sans que les 4 gardes
        // (CSRF absent/faux, permission exacte, acces avec la bonne permission)
        // n'aient ete verifiees pour elle.
        self::assertSame($tabled, $registered, 'self::ROUTES ne correspond plus exactement aux routes /admin/api/* de index.php.');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function writeRoutesProvider(): array
    {
        $cases = [];
        foreach (self::ROUTES as [$method, $path, $resource, $action, $permission, $isWrite]) {
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
        foreach (self::ROUTES as [$method, $path, $resource, $action, $permission, $isWrite]) {
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
     * Couvre les 49 routes de self::ROUTES ; les 3 routes `/admin/api/auth/*`
     * (hors de cette table, cf. testRouteTableCoversEveryRegisteredRoute) sont
     * couvertes a part dans AuthApiControllerTest (logout/me exigent une session,
     * login n'en exige aucune par construction).
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
