<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\SessionManager;
use App\Controllers\AdminController;
use App\Controllers\HomeController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Health\RouteSecurity;
use App\Tests\Unit\Admin\DashStubOrderQuery;
use App\Tests\Unit\Admin\DashStubStatsRepository;
use App\Tests\Unit\Admin\ImportFakeDatabase;
use App\Tests\Unit\Admin\TestDashboardController;
use App\Tests\Unit\Admin\TestIngredientController;
use App\Tests\Unit\Admin\TestProductImportController;
use App\Tests\Unit\Auth\TestPasswordResetController;
use App\Catalogue\ProductImportService;
use RuntimeException;

// Les doubles `Test<Nom>Controller` des pages HTML existent deja, un par fichier
// de test de controleur : on les reutilise tels quels (memes coutures session /
// garde / autorisation / base factice) plutot que d'en ecrire une seconde serie
// qui pourrait diverger. `require_once` explicite, comme RouteMatrixTest : la
// classe existe quelle que soit la facon dont la suite est lancee (--filter).
require_once __DIR__ . '/../Unit/Admin/CategoryControllerTest.php';
require_once __DIR__ . '/../Unit/Admin/CounterOrderControllerTest.php';
require_once __DIR__ . '/../Unit/Admin/DashboardControllerTest.php';
require_once __DIR__ . '/../Unit/Admin/HealthPageControllerTest.php';
require_once __DIR__ . '/../Unit/Admin/IngredientControllerTest.php';
require_once __DIR__ . '/../Unit/Admin/KitchenControllerTest.php';
require_once __DIR__ . '/../Unit/Admin/MenuControllerTest.php';
require_once __DIR__ . '/../Unit/Admin/OrderAdminControllerTest.php';
require_once __DIR__ . '/../Unit/Admin/PrivacyControllerTest.php';
require_once __DIR__ . '/../Unit/Admin/ProductControllerTest.php';
require_once __DIR__ . '/../Unit/Admin/ProductImportControllerTest.php';
require_once __DIR__ . '/../Unit/Admin/ProfileControllerTest.php';
require_once __DIR__ . '/../Unit/Admin/RoleControllerTest.php';
require_once __DIR__ . '/../Unit/Admin/StatsControllerTest.php';
require_once __DIR__ . '/../Unit/Admin/UserControllerTest.php';
require_once __DIR__ . '/../Unit/Auth/AuthControllerTest.php';
require_once __DIR__ . '/../Unit/Auth/MeControllerTest.php';
require_once __DIR__ . '/../Unit/Auth/PasswordResetControllerTest.php';

/**
 * Banc d'essai commun des tests `tests/Unit/Admin/Html*Test.php` : prouve, route
 * par route du back-office HTML, que chaque exigence annoncee par
 * `App\Health\RouteSecurity::ENTRIES` (la table que lit la page « Santé de
 * l'API ») est bien celle que le code applique.
 *
 * Les EXIGENCES viennent toujours de la table, jamais d'une copie ici : ce banc
 * ne sait que COMMENT atteindre chaque action (parametres de route, ligne a
 * preparer dans la base factice, formulaire valide). Le controleur et l'action
 * viennent du routeur reel (`src/app/Core/routes.php`), pas d'une liste ecrite
 * a la main : ajouter une route a la table et au routeur suffit a l'inclure
 * dans les fournisseurs de donnees.
 *
 * @phpstan-type Entry array{0: string, 1: string, 2: bool, 3: ?string, 4: ?string, 5: ?string, 6: ?string}
 */
final class HtmlRouteHarness
{
    /** Code personnel valide de l'equipier resolu (meme valeur que les tests de controleur). */
    public const PIN = '4729';

    public const PIN_EMAIL = 'sam@wakdo.local';

    /** Mot de passe courant attendu par la re-verification d'identite (POST /admin/profile/pin). */
    public const CURRENT_PASSWORD = 'S3cret-Wakdo!';

    private const SEED_RBAC = __DIR__ . '/../../db/seeds/0001_rbac_and_reference.sql';

    private const ROUTES_FILE = __DIR__ . '/../../src/app/Core/routes.php';

    /**
     * Variables d'environnement posees le temps d'un test : durees de session,
     * politique de PIN, cout argon2id reduit (le hachage de reference du PIN
     * resterait sinon a plusieurs dizaines de millisecondes par cas).
     */
    private const ENV = [
        'SESSION_LIFETIME_IDLE' => '14400',
        'SESSION_LIFETIME_ABSOLUTE' => '36000',
        'STAFF_PIN_MIN_LENGTH' => '4',
        'STAFF_PIN_MAX_LENGTH' => '12',
        'ARGON2_MEMORY_COST' => '1024',
        'ARGON2_TIME_COST' => '1',
        'ARGON2_THREADS' => '1',
        'PASSWORD_RESET_TTL' => '3600',
        'APP_URL_ADMIN' => 'https://admin.wakdo.test',
        'ACCOUNT_LOCKOUT_THRESHOLD' => '5',
        'ACCOUNT_LOCKOUT_BASE_SECONDS' => '60',
        'ACCOUNT_LOCKOUT_MAX_SECONDS' => '900',
        'IP_THROTTLE_MAX_ATTEMPTS' => '20',
        'IP_THROTTLE_WINDOW_SECONDS' => '900',
    ];

    private static ?string $pinHashCache = null;

    /** @var array<string, array{0: class-string, 1: string}>|null */
    private static ?array $handlersCache = null;

    public static function applyEnv(): void
    {
        foreach (self::ENV as $key => $value) {
            putenv($key . '=' . $value);
        }
    }

    public static function clearEnv(): void
    {
        foreach (array_keys(self::ENV) as $key) {
            putenv($key);
        }
    }

    // --- Source des exigences : la table, filtree sur la surface back-office HTML ---

    /**
     * Lignes de `RouteSecurity::ENTRIES` du back-office HTML : ni la borne
     * (`/api/*`) ni l'API d'administration JSON (`/admin/api/*`, deja couverte
     * par RouteMatrixTest).
     *
     * @return list<Entry>
     */
    public static function boEntries(): array
    {
        $rows = [];
        foreach (RouteSecurity::ENTRIES as $entry) {
            if (self::isBoPath($entry[1])) {
                $rows[] = $entry;
            }
        }

        return $rows;
    }

    public static function isBoPath(string $path): bool
    {
        return !($path === '/api' || str_starts_with($path, '/api/') || str_starts_with($path, '/admin/api/') || $path === '/admin/api');
    }

    /**
     * @param Entry $entry
     */
    public static function key(array $entry): string
    {
        return $entry[0] . ' ' . $entry[1];
    }

    /**
     * @param Entry $entry
     */
    public static function isWrite(array $entry): bool
    {
        return $entry[0] !== 'GET';
    }

    /**
     * Catalogue complet des permissions, relu dans le seed RBAC (source de la
     * base de production) plutot que recopie : un code ajoute au seed entre de
     * lui-meme dans le jeu « toutes sauf celle-la ».
     *
     * @return list<string>
     */
    public static function permissionCatalogue(): array
    {
        $lines = file(self::SEED_RBAC, FILE_IGNORE_NEW_LINES);
        $codes = [];
        $inBlock = false;
        foreach ($lines === false ? [] : $lines as $line) {
            if (str_starts_with(trim($line), 'INSERT INTO permission (')) {
                $inBlock = true;

                continue;
            }
            // Les commentaires SQL du bloc contiennent eux-memes des « ; » : seule
            // une ligne de VALEURS terminee par « ); » ferme l'insertion.
            if (!$inBlock || str_starts_with(trim($line), '--')) {
                continue;
            }
            if (preg_match("/^\\s*\\('([a-z_]+\\.[a-z_]+)'\\s*,/", $line, $m) === 1) {
                $codes[] = $m[1];
            }
            if (str_ends_with(rtrim($line), ');')) {
                break;
            }
        }
        if ($codes === []) {
            throw new RuntimeException('Bloc INSERT INTO permission introuvable dans le seed RBAC.');
        }

        return array_values(array_unique($codes));
    }

    /**
     * Controleur et action de chaque route, lus dans le routeur REEL charge
     * depuis `routes.php` (meme fichier que le front controller de production).
     *
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function handlers(): array
    {
        if (self::$handlersCache !== null) {
            return self::$handlersCache;
        }
        $router = new Router(new Config(), new Database(new Config()));
        /** @var callable(Router): void $register */
        $register = require self::ROUTES_FILE;
        $register($router);

        $map = [];
        foreach ($router->routes() as $route) {
            $map[$route['method'] . ' ' . $route['pattern']] = $route['handler'];
        }

        return self::$handlersCache = $map;
    }

    /**
     * @return array{0: class-string, 1: string}
     */
    public static function handler(string $method, string $path): array
    {
        $handlers = self::handlers();
        $key = $method . ' ' . $path;
        if (!isset($handlers[$key])) {
            throw new RuntimeException("Route $key presente dans RouteSecurity::ENTRIES mais absente du routeur.");
        }

        return $handlers[$key];
    }

    /**
     * La garde de page d'AdminController redirige vers /login ; un controleur qui
     * ne passe PAS par elle (MeController, AuthenticatedController direct) repond
     * en JSON 401. Deduit de la hierarchie de classes, pas d'une liste de chemins.
     *
     * @param class-string $class
     */
    public static function usesHtmlLoginRedirect(string $class): bool
    {
        return is_subclass_of($class, AdminController::class);
    }

    // --- Session, base factice, requete ---

    public static function authenticatedSession(): SessionManager
    {
        $session = new SessionManager(new Config(), true);
        $now = time();
        $session->set('user_id', 1);
        $session->set('role_id', 1);
        $session->set('logged_in_at', $now - 100);
        $session->set('last_activity', $now - 50);
        Csrf::token($session);

        return $session;
    }

    /**
     * Session SANS compte (aucune cle d'authentification) mais porteuse d'un
     * jeton anti-rejeu, comme le navigateur d'un visiteur qui a affiche /login.
     */
    public static function anonymousSession(): SessionManager
    {
        $session = new SessionManager(new Config(), true);
        Csrf::token($session);

        return $session;
    }

    /**
     * Session VIDE : ni compte ni jeton, un appel sans cookie.
     */
    public static function blankSession(): SessionManager
    {
        return new SessionManager(new Config(), true);
    }

    /**
     * Base factice ou le role de session detient EXACTEMENT $granted (garde
     * `can()` par appartenance a la liste, pas le bouton global canResult).
     *
     * @param list<string> $granted
     */
    public static function grantedDb(array $granted): FakeDatabase
    {
        $db = new FakeDatabase();
        $db->guardUserRow = ['is_active' => 1];
        $db->userDisplayRow = ['first_name' => 'Cor', 'last_name' => 'J', 'role_label' => 'Administrateur', 'email' => 'cor@wakdo.local', 'order_source' => null];
        $db->grantedCodes = $granted;
        $db->permissionCodes = $granted;
        $db->canResult = false;
        $db->actingUserRow = ['id' => 9, 'role_id' => 4, 'pin_hash' => self::pinHash()];

        return $db;
    }

    public static function pinHash(): string
    {
        // Calcule une fois par processus : argon2id encode ses parametres dans le
        // hash, la verification reste donc exacte quel que soit le cout courant.
        if (self::$pinHashCache === null) {
            self::$pinHashCache = (new PasswordHasher(new Config()))->hash(self::PIN);
        }

        return self::$pinHashCache;
    }

    /**
     * @return array<string, string>
     */
    public static function pinFields(): array
    {
        return ['pin_email' => self::PIN_EMAIL, 'pin' => self::PIN];
    }

    public static function concretePath(string $path): string
    {
        return str_replace(['{id}', '{number}'], ['5', 'K42'], $path);
    }

    /**
     * @return array<string, string>
     */
    public static function routeParams(string $path): array
    {
        $params = [];
        if (str_contains($path, '{id}')) {
            $params['id'] = '5';
        }
        if (str_contains($path, '{number}')) {
            $params['number'] = 'K42';
        }

        return $params;
    }

    /**
     * @param array<string, string>|null $form  null = GET sans corps
     * @param array<string, array<string, mixed>> $files
     */
    public static function request(string $method, string $path, ?array $form, array $files = []): Request
    {
        $concrete = self::concretePath($path);
        if ($form === null) {
            return new Request($method, $concrete, [], [], '', '203.0.113.5');
        }
        if ($files !== []) {
            // Envoi de fichier : multipart, les champs arrivent comme $_POST les a
            // parses (voir Request::formBody()).
            return new Request($method, $concrete, [], ['content-type' => 'multipart/form-data; boundary=----wakdoHtmlRoute'], '', '203.0.113.5', $files, $form);
        }

        return new Request($method, $concrete, [], ['content-type' => 'application/x-www-form-urlencoded'], http_build_query($form), '203.0.113.5');
    }

    // --- Appel de l'action via le double de test existant ---

    /**
     * @param array<string, mixed> $scenario
     * @param array<string, string>|null $form
     */
    public static function invoke(string $method, string $path, SessionManager $session, FakeDatabase $db, ?array $form, array $scenario = []): Response
    {
        [$class, $action] = self::handler($method, $path);
        $filesFactory = $scenario['files'] ?? null;
        /** @var array<string, array<string, mixed>> $files */
        $files = $filesFactory instanceof \Closure ? $filesFactory() : [];
        $request = self::request($method, $path, $form, $files);
        $import = $scenario['import'] ?? new ImportFakeDatabase($db);
        $controller = self::controller($class, $action, $request, $session, $db, $import instanceof ImportFakeDatabase ? $import : new ImportFakeDatabase($db));

        $configure = $scenario['configure'] ?? null;
        if ($configure instanceof \Closure) {
            $configure($controller);
        }

        $params = self::routeParams($path);
        try {
            $response = $controller->$action($params);
        } finally {
            foreach ($files as $file) {
                $tmp = $file['tmp_name'] ?? null;
                if (is_string($tmp) && is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }
        if (!$response instanceof Response) {
            throw new RuntimeException("$method $path : l'action $action n'a pas renvoye de Response.");
        }

        return $response;
    }

    /**
     * Prepare le scenario (base, session, double d'import) PUIS appelle l'action :
     * le double d'import prepare est celui que recoit le controleur.
     *
     * @param array<string, string>|null $form
     * @param array<string, mixed> $scenario
     */
    public static function exercise(string $method, string $path, SessionManager $session, FakeDatabase $db, ?array $form, array $scenario): Response
    {
        $import = new ImportFakeDatabase($db);
        self::applyScenario($scenario, $db, $session, $import);

        return self::invoke($method, $path, $session, $db, $form, ['import' => $import] + $scenario);
    }

    /**
     * @param class-string $class
     */
    private static function controller(string $class, string $action, Request $request, SessionManager $session, FakeDatabase $db, ImportFakeDatabase $import): object
    {
        $short = self::shortName($class);
        $config = new Config();
        $database = new Database($config);

        if ($class === HomeController::class) {
            return new HomeController($request, $config, $database);
        }

        if ($short === 'Product' && str_starts_with($action, 'import')) {
            return new TestProductImportController($request, $config, $database, $session, $import);
        }

        if ($short === 'Dashboard') {
            return new TestDashboardController($request, $config, $database, $session, $db, new DashStubStatsRepository($db), new DashStubOrderQuery($db));
        }

        if ($short === 'PasswordReset') {
            return new TestPasswordResetController($request, $config, $database, $session, $db, new SpyMailer());
        }

        $double = self::doubleClass($short);
        if ($double === null) {
            throw new RuntimeException("Aucun double de test connu pour $class : ajoutez-le a HtmlRouteHarness::doubleClass().");
        }

        return new $double($request, $config, $database, $session, $db);
    }

    public static function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');
        $base = $pos === false ? $class : substr($class, $pos + 1);

        return str_ends_with($base, 'Controller') ? substr($base, 0, -strlen('Controller')) : $base;
    }

    /**
     * @return class-string|null
     */
    private static function doubleClass(string $short): ?string
    {
        foreach (['App\\Tests\\Unit\\Admin\\Test' . $short . 'Controller', 'App\\Tests\\Unit\\Auth\\Test' . $short . 'Controller'] as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    // --- Lecture des ecritures enregistrees par la base factice ---

    /**
     * Toutes les requetes d'ecriture (INSERT, UPDATE, DELETE, REPLACE).
     *
     * @return list<string>
     */
    public static function writes(FakeDatabase $db): array
    {
        $out = [];
        foreach ($db->writes as $write) {
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $write['sql']) === 1) {
                $out[] = $write['sql'];
            }
        }

        return $out;
    }

    /**
     * Ecritures METIER : toutes sauf la trace d'un echec de code personnel
     * (ligne `pin.failed` d'audit_log, RG-T14), l'echec de re-verification du
     * mot de passe courant (ligne `auth.reauth_failed`, D-1) et le compteur
     * anti-essais (`pin_throttle`, RG-T22, reutilise par D-1 avec l'utilisateur
     * de session comme cle) -- que ces deux refus ecrivent volontairement.
     *
     * @return list<string>
     */
    public static function businessWrites(FakeDatabase $db): array
    {
        $out = [];
        foreach ($db->writes as $write) {
            $sql = $write['sql'];
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql) !== 1) {
                continue;
            }
            if (str_contains($sql, 'pin_throttle')) {
                continue;
            }
            // D-1.a (contre-audit 30/09) : la re-verification du mot de passe
            // (ProfileController::updatePin()) compte desormais son echec sur la
            // dimension COMPTE (App\Auth\AccountLockout, user.failed_login_attempts/
            // lockout_until) -- plus jamais pin_throttle. Exclue au meme titre :
            // c'est la trace VOLONTAIRE d'un refus de code/mot de passe, jamais
            // une ecriture metier.
            if (str_contains($sql, 'UPDATE user SET failed_login_attempts = failed_login_attempts + 1')) {
                continue;
            }
            if (str_contains($sql, 'UPDATE user SET lockout_until = :lock WHERE id = :id')) {
                continue;
            }
            if (str_contains($sql, 'INSERT INTO audit_log') && in_array($write['params']['code'] ?? null, ['pin.failed', 'auth.reauth_failed'], true)) {
                continue;
            }
            $out[] = $sql;
        }

        return $out;
    }

    public static function pinFailures(FakeDatabase $db): int
    {
        return count(array_filter($db->auditActions(), static fn (string $code): bool => $code === 'pin.failed'));
    }

    /** D-1 : echecs de re-verification du mot de passe courant (`auth.reauth_failed`). */
    public static function reauthFailures(FakeDatabase $db): int
    {
        return count(array_filter($db->auditActions(), static fn (string $code): bool => $code === 'auth.reauth_failed'));
    }

    // --- Comment atteindre chaque action ---

    /**
     * Rend la route atteignable jusqu'a son action : la ligne {id}/{number}
     * existe, la source de la commande est visible, etc. Par controleur (et non
     * un « monde » unique) : la ligne role lue par la garde de canal de
     * CounterOrderController est la meme requete que RoleRepository::findRole(),
     * un role a canal fixe pose pour les pages de roles fermerait /drive/orders.
     */
    public static function primeWorld(FakeDatabase $db, string $method, string $path): void
    {
        [$class] = self::handler($method, $path);
        match (self::shortName($class)) {
            'Category' => (static function () use ($db): void {
                $db->categoryRow = ['id' => 5, 'name' => 'Wraps', 'slug' => 'wraps', 'image_path' => null, 'display_order' => 3, 'is_active' => 1];
                $db->categoriesRows = [['id' => 10, 'name' => 'Burgers', 'slug' => 'burgers', 'image_path' => null, 'display_order' => 1, 'is_active' => 1], ['id' => 5, 'name' => 'Wraps', 'slug' => 'wraps', 'image_path' => null, 'display_order' => 3, 'is_active' => 1]];
            })(),
            'Product' => (static function () use ($db): void {
                $db->productRow = ['id' => 5, 'category_id' => 3, 'name' => 'Big Mac', 'description' => null, 'price_cents' => 590, 'size_cl' => null, 'base_product_id' => null, 'maxi_variant_product_id' => null, 'vat_rate' => 100, 'image_path' => null, 'is_available' => 1, 'display_order' => 1];
                $db->categoryRow = ['id' => 3, 'name' => 'Burgers'];
                $db->reorderProductRow = ['category_id' => 3];
                $db->reorderCategoryIdsRows = [['id' => 10], ['id' => 5]];
            })(),
            'Menu' => (static function () use ($db): void {
                $db->menuRow = ['id' => 5, 'category_id' => 1, 'burger_product_id' => 1, 'name' => 'Best Of', 'price_normal_cents' => 790, 'price_maxi_cents' => 990, 'is_available' => 1, 'display_order' => 0];
                $db->categoryRow = ['id' => 1, 'name' => 'Menus'];
                $db->productRow = ['id' => 1, 'name' => 'Big Mac'];
            })(),
            'Ingredient' => (static function () use ($db): void {
                $db->ingredientRow = ['id' => 5, 'name' => 'Cheddar', 'unit' => 'tranche', 'stock_quantity' => 40, 'stock_capacity' => 100, 'pack_size' => 10, 'pack_label' => 'Sachet 10', 'low_stock_pct' => 10, 'critical_stock_pct' => 5, 'is_active' => 1];
                $db->allergensRows = [
                    ['id' => 1, 'code' => 'gluten', 'name' => 'Gluten', 'description' => 'Cereales.'],
                    ['id' => 7, 'code' => 'milk', 'name' => 'Lait', 'description' => 'Lait.'],
                ];
            })(),
            'User' => (static function () use ($db): void {
                // id 5, distinct du user_id=1 de la session : agir sur son propre
                // compte est refuse a raison (403) et masquerait la garde testee.
                $db->userManageRow = ['id' => 5, 'email' => 'staff@wakdo.local', 'first_name' => 'Sam', 'last_name' => 'Staff', 'role_id' => 4, 'is_active' => 1, 'anonymized_at' => null];
                $db->rolesRows = [['id' => 4, 'label' => 'Counter Staff']];
                $db->roleActiveExists = true;
                $db->lastInsertId = 42;
            })(),
            'Role' => (static function () use ($db): void {
                $db->roleManageRow = ['id' => 5, 'code' => 'counter', 'label' => 'Counter', 'description' => null, 'default_route' => '/counter/orders', 'order_source' => 'counter', 'is_active' => 1];
                $db->permissionsRows = [
                    ['id' => 1, 'code' => 'role.manage', 'label' => 'Manage RBAC'],
                    ['id' => 2, 'code' => 'stats.read', 'label' => 'Stats'],
                ];
                $db->lastInsertId = 10;
            })(),
            'OrderAdmin' => (static function () use ($db): void {
                $db->orderByNumberRow = ['id' => 100, 'order_number' => 'K42', 'source' => 'counter', 'total_ttc_cents' => 1990, 'status' => 'paid'];
                $db->orderSourceRow = ['source' => 'counter'];
            })(),
            'CounterOrder' => (static function () use ($db): void {
                $db->productRow = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'is_available' => 1];
                $db->lastInsertId = 100;
                $db->orderByNumberRow = ['id' => 100, 'order_number' => 'C100', 'total_ttc_cents' => 890, 'status' => 'pending_payment'];
            })(),
            'Profile' => (static function () use ($db): void {
                $db->currentPasswordRow = ['password_hash' => (new PasswordHasher(new Config()))->hash(self::CURRENT_PASSWORD)];
            })(),
            default => null,
        };
    }

    /**
     * Chemin de REUSSITE de chaque route d'ecriture du back-office HTML : le
     * formulaire valide (sans `_csrf` ni champs de code personnel, que chaque
     * test ajoute ou retire selon ce qu'il prouve), ce qu'il faut preparer en
     * plus du monde de primeWorld(), le statut de reussite et la requete qui
     * prouve que l'action a bien ecrit. Ce sont des donnees d'EXERCICE, pas des
     * exigences : aucune permission, aucun jeton, aucun code n'y figure.
     *
     * Cles facultatives : `location` (redirection attendue en cas de reussite,
     * quand elle est legitimement /login), `prime` (Closure(FakeDatabase, ImportFakeDatabase)),
     * `session` (Closure(SessionManager)), `files`, `configure`
     * (Closure(object) sur le controleur), `extraPerms` (permissions requises par
     * le scenario EN PLUS de celle de la route, ex. mettre a jour un produit
     * existant par import), et pour les routes `pin = price` la cle `price` :
     * un second scenario qui, lui, touche un prix ou une TVA.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function scenarios(): array
    {
        $importCsv = static fn (string $line): string => implode(';', ProductImportService::COLUMNS) . "\r\n" . $line . "\r\n";
        $pending = static fn (string $csv): \Closure => static function (SessionManager $session) use ($csv): void {
            $session->set('_product_import_pending', ['token' => 'jeton-import-fictif', 'csv' => $csv, 'created_at' => time()]);
        };
        $menuForm = [
            'category_id' => '1',
            'burger_product_id' => '1',
            'name' => 'Best Of',
            'price_normal_cents' => '7,90',
            'price_maxi_cents' => '9,90',
            'display_order' => '1',
            'is_available' => '1',
            'slots_json' => (string) json_encode([['name' => 'Boisson', 'slot_type' => 'drink', 'is_required' => 1, 'options' => [1]]]),
        ];
        $ingredientForm = [
            'name' => 'Cheddar',
            'unit' => 'tranche',
            'stock_capacity' => '100',
            'pack_size' => '10',
            'pack_label' => 'Sachet 10',
            'low_stock_pct' => '10',
            'critical_stock_pct' => '5',
        ];
        $productForm = [
            'category_id' => '3',
            'name' => 'Big Mac',
            'price_cents' => '5,90',
            'vat_rate' => '100',
            'display_order' => '1',
            'is_available' => '1',
        ];
        // Fabrique (et non fichier cree ici) : scenarios() est relu a chaque cas,
        // le fichier temporaire n'existe que le temps de l'appel (voir invoke()).
        $csvTmp = static fn (string $content): \Closure => static function () use ($content): array {
            $tmp = (string) tempnam(sys_get_temp_dir(), 'wakdo_htmlroute_csv_');
            file_put_contents($tmp, $content);

            return ['csv_file' => ['name' => 'produits.csv', 'type' => 'text/csv', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)]];
        };

        return [
            // --- Authentification (sans compte) ---
            'POST /login' => [
                'form' => ['email' => 'admin@wakdo.local', 'password' => 'correct-password'],
                'prime' => static function (FakeDatabase $db): void {
                    $db->userRow = ['id' => 7, 'role_id' => 3, 'password_hash' => (new PasswordHasher(new Config()))->hash('correct-password'), 'failed_login_attempts' => 0, 'lockout_until' => null, 'default_route' => '/admin/dashboard'];
                },
                'success' => 302,
                'write' => null,
            ],
            // La deconnexion REUSSIE renvoie vers /login : c'est sa destination
            // normale, pas un refus de la garde.
            'POST /logout' => ['form' => [], 'success' => 302, 'location' => '/login', 'write' => null],
            'POST /forgot_password' => ['form' => ['email' => 'ghost@wakdo.local'], 'success' => 200, 'write' => null],
            'POST /reset_password' => [
                'form' => ['token' => 'raw-token', 'password' => 'brandnewpassword', 'password_confirm' => 'brandnewpassword'],
                'prime' => static function (FakeDatabase $db): void {
                    $db->resetUserRow = ['id' => 7, 'role_id' => 3, 'password_reset_token_hash' => hash('sha256', 'raw-token')];
                },
                'success' => 302,
                'write' => 'UPDATE user',
            ],

            // --- Commandes ---
            'POST /admin/orders/{number}/deliver' => ['form' => [], 'success' => 302, 'write' => 'UPDATE customer_order SET status'],
            'POST /admin/orders/{number}/ready' => [
                'form' => [],
                'prime' => static function (FakeDatabase $db): void {
                    $db->orderByNumberRow = ['id' => 100, 'order_number' => 'K42', 'source' => 'counter', 'total_ttc_cents' => 1990, 'status' => 'preparing'];
                },
                'success' => 302,
                'write' => 'UPDATE customer_order SET status',
            ],
            'POST /admin/orders/{number}/cancel' => ['form' => [], 'success' => 302, 'write' => 'UPDATE customer_order SET status'],
            'POST /counter/orders' => ['form' => ['service_mode' => 'dine_in', 'qty_12' => '2'], 'success' => 302, 'write' => 'INSERT INTO customer_order'],
            'POST /drive/orders' => ['form' => ['service_mode' => 'drive', 'qty_12' => '1'], 'success' => 302, 'write' => 'INSERT INTO customer_order'],

            // --- Comptes et roles ---
            'POST /admin/users' => [
                'form' => ['email' => 'new@wakdo.local', 'first_name' => 'New', 'last_name' => 'Hire', 'role_id' => '4', 'password' => 'motdepasse8'],
                'success' => 302,
                'write' => 'INSERT INTO user',
            ],
            'POST /admin/users/{id}' => [
                'form' => ['email' => 'staff@wakdo.local', 'first_name' => 'Renamed', 'last_name' => 'Staff', 'role_id' => '4', 'is_active' => '1'],
                'success' => 302,
                'write' => 'UPDATE user SET email',
            ],
            'POST /admin/users/{id}/deactivate' => ['form' => [], 'success' => 302, 'write' => 'SET is_active = 0'],
            'POST /admin/users/{id}/reset-pin' => ['form' => [], 'success' => 302, 'write' => 'UPDATE user SET pin_hash = NULL'],
            'POST /admin/users/{id}/erase' => ['form' => [], 'success' => 302, 'write' => 'anonymized_at = NOW()'],
            'POST /admin/roles' => [
                'form' => ['code' => 'kitchen_kds', 'label' => 'Kitchen KDS', 'default_route' => '/kitchen/display', 'order_source' => '', 'perm_1' => '1', 'source_counter' => '1'],
                'success' => 302,
                'write' => 'INSERT INTO role ',
            ],
            'POST /admin/roles/{id}' => [
                'form' => ['label' => 'Counter', 'default_route' => '/counter/orders', 'order_source' => 'counter', 'perm_1' => '1', 'is_active' => '1'],
                'success' => 302,
                'write' => 'UPDATE role SET',
            ],

            // --- Categories ---
            'POST /admin/categories' => ['form' => ['name' => 'Desserts', 'slug' => 'desserts', 'display_order' => '7'], 'success' => 302, 'write' => 'INSERT INTO category'],
            'POST /admin/categories/{id}' => ['form' => ['name' => 'Wraps & Co', 'slug' => 'wraps', 'display_order' => '3'], 'success' => 302, 'write' => 'UPDATE category SET name'],
            'POST /admin/categories/{id}/toggle' => ['form' => [], 'success' => 302, 'write' => 'UPDATE category SET is_active'],
            'POST /admin/categories/{id}/move' => ['form' => ['direction' => 'up'], 'success' => 302, 'write' => 'UPDATE category SET display_order'],

            // --- Profil ---
            // `pin` est ici la NOUVELLE valeur du code, pas le code d'autorisation.
            'POST /admin/profile/pin' => [
                'form' => ['pin' => '4729', 'pin_confirm' => '4729', 'current_password' => self::CURRENT_PASSWORD],
                'success' => 302,
                'write' => 'UPDATE user SET pin_hash',
                'reauthField' => 'current_password',
            ],

            // --- Produits ---
            'POST /admin/products' => ['form' => $productForm, 'success' => 302, 'write' => 'INSERT INTO product'],
            'POST /admin/products/{id}' => [
                // Nom seul modifie, prix 5,90 et TVA 100 identiques a la ligne en base.
                'form' => ['name' => 'Renamed'] + $productForm,
                'success' => 302,
                'write' => 'UPDATE product SET',
                'price' => [
                    'variants' => [
                        'prix' => ['price_cents' => '6,20'],
                        'TVA' => ['vat_rate' => '55'],
                    ],
                    'success' => 302,
                    'write' => 'UPDATE product SET',
                ],
            ],
            'POST /admin/products/{id}/delete' => ['form' => [], 'success' => 302, 'write' => 'DELETE FROM product'],
            'POST /admin/products/{id}/move' => ['form' => ['direction' => 'up'], 'success' => 302, 'write' => 'UPDATE product SET display_order'],
            'POST /admin/products/{id}/recipe' => [
                'form' => ['composition_json' => (string) json_encode([['ingredient_id' => 7, 'quantity_normal' => 2, 'quantity_maxi' => 3, 'is_removable' => 1, 'is_addable' => 0, 'extra_price_cents' => 50]])],
                'prime' => static function (FakeDatabase $db): void {
                    $db->ingredientRow = ['id' => 7, 'name' => 'Cheddar'];
                },
                'success' => 302,
                'write' => 'INSERT INTO product_ingredient',
            ],
            'POST /admin/products/import/preview' => [
                'form' => [],
                'files' => $csvTmp($importCsv('3;Cheeseburger;;6,90;10;;oui;;;;;')),
                'success' => 200,
                // Un apercu n'ecrit rien : le fichier est garde en session.
                'write' => null,
            ],
            'POST /admin/products/import/confirm' => [
                // Creation d'un produit nouveau : aucun prix existant ne change.
                'form' => ['import_token' => 'jeton-import-fictif'],
                'session' => $pending($importCsv('3;Cheeseburger;;6,90;10;;oui;;;;;')),
                'success' => 302,
                'write' => 'INSERT INTO product',
                'price' => [
                    'variants' => ['prix' => []],
                    // Produit existant a 5,90, le fichier le passe a 6,90. Mettre a
                    // jour un produit existant exige aussi product.update, et
                    // remplacer sa recette (ligne sans ingredient = recette videe)
                    // ingredient.manage : gardes de l'import, independantes du code
                    // personnel, qui refuseraient AVANT lui sinon.
                    'extraPerms' => ['product.update', 'ingredient.manage'],
                    'prime' => static function (FakeDatabase $db, ImportFakeDatabase $import): void {
                        $import->productMatch = ['id' => 42, 'price_cents' => 590];
                        $import->productCurrentFields = ['description' => null, 'price_cents' => 590, 'vat_rate' => 100, 'size_cl' => null, 'is_available' => 1, 'display_order' => 3, 'image_path' => null];
                    },
                    'session' => $pending($importCsv('3;Cheeseburger;;6,90;10;;oui;;;;;')),
                    'success' => 302,
                    'write' => 'UPDATE product SET',
                ],
            ],

            // --- Menus ---
            'POST /admin/menus' => ['form' => $menuForm, 'success' => 302, 'write' => 'INSERT INTO menu'],
            'POST /admin/menus/{id}' => ['form' => $menuForm, 'success' => 302, 'write' => 'UPDATE menu SET'],
            'POST /admin/menus/{id}/toggle' => ['form' => [], 'success' => 302, 'write' => 'UPDATE menu SET is_available'],
            'POST /admin/menus/{id}/delete' => ['form' => [], 'success' => 302, 'write' => 'DELETE FROM menu'],

            // --- Ingredients et stock ---
            'POST /admin/ingredients' => ['form' => $ingredientForm, 'success' => 302, 'write' => 'INSERT INTO ingredient'],
            'POST /admin/ingredients/{id}' => ['form' => $ingredientForm, 'success' => 302, 'write' => 'UPDATE ingredient'],
            'POST /admin/ingredients/{id}/toggle' => ['form' => [], 'success' => 302, 'write' => 'UPDATE ingredient SET is_active'],
            'POST /admin/ingredients/{id}/delete' => ['form' => [], 'success' => 302, 'write' => 'DELETE FROM ingredient'],
            'POST /admin/ingredients/{id}/restock' => ['form' => ['packs' => '2', 'note' => 'Livraison A'], 'success' => 302, 'write' => 'INSERT INTO stock_movement'],
            'POST /admin/ingredients/{id}/thresholds' => ['form' => ['stock_capacity' => '200', 'low_stock_pct' => '15', 'critical_stock_pct' => '5'], 'success' => 302, 'write' => 'UPDATE ingredient SET stock_capacity'],
            'POST /admin/ingredients/{id}/inventory' => ['form' => ['actual_quantity' => '30', 'note' => 'mensuel'], 'success' => 302, 'write' => 'INSERT INTO stock_movement'],
            'POST /admin/ingredients/{id}/adjust' => ['form' => ['delta' => '10', 'note' => 'casse compensee'], 'success' => 302, 'write' => 'INSERT INTO stock_movement'],
            'POST /admin/ingredients/{id}/enrich' => [
                'form' => [],
                'configure' => static function (object $controller): void {
                    if ($controller instanceof TestIngredientController) {
                        $controller->fakeGateway = new FakeNutritionGateway();
                        $controller->fakeGateway->result = ['energy_kcal_100g' => 402, 'source' => 'OpenFoodFacts'];
                    }
                },
                'success' => 302,
                'write' => 'UPDATE ingredient SET energy_kcal_100g',
            ],
            'POST /admin/ingredients/{id}/allergens' => ['form' => ['allergen_1' => '1', 'allergen_7' => '1', 'source' => 'Fiche fournisseur 2026'], 'success' => 302, 'write' => 'INSERT INTO ingredient_allergen'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function scenario(string $key): ?array
    {
        return self::scenarios()[$key] ?? null;
    }

    /**
     * Prepare la base puis la session d'un scenario (ou de sa variante `price`).
     *
     * @param array<string, mixed> $scenario
     */
    public static function applyScenario(array $scenario, FakeDatabase $db, SessionManager $session, ImportFakeDatabase $import): void
    {
        $prime = $scenario['prime'] ?? null;
        if ($prime instanceof \Closure) {
            $prime($db, $import);
        }
        $sessionHook = $scenario['session'] ?? null;
        if ($sessionHook instanceof \Closure) {
            $sessionHook($session);
        }
    }

    /**
     * @param array<string, mixed> $scenario
     * @return array<string, string>
     */
    public static function form(array $scenario): array
    {
        $form = $scenario['form'] ?? [];
        if (!is_array($form)) {
            return [];
        }
        $out = [];
        foreach ($form as $key => $value) {
            $out[(string) $key] = (string) $value;
        }

        return $out;
    }
}
