<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;
use App\Auth\Authorizer;
use App\Core\Config;
use App\Core\Database;

/**
 * Preuve RBAC "role reel x route reelle" (docs/demo/matrice-rbac.md), contre une
 * vraie MariaDB migree + seedee (seed 0001 : roles/permissions/role_permission ;
 * seed 0009 : les 5 comptes de demonstration). Reutilise la table de routes de
 * `App\Tests\Unit\Admin\Api\RouteMatrixTest::ROUTES` par reflexion (constante
 * privee, lisible via ReflectionClass::getConstant() depuis PHP 7.1 quelle que
 * soit sa visibilite) -- PAS de duplication de cette table : un changement de
 * route/permission dans RouteMatrixTest est repercute ici automatiquement.
 *
 * Ce que ce test prouve : pour chacun des 5 comptes de demonstration REELS
 * (resolus par email depuis `user`, donc depend du seed 0009), et pour CHAQUE
 * route du catalogue /admin/api/*, le resultat du controle d'autorisation
 * (`Authorizer::can`, la meme classe que `AdminController::guard()` invoque en
 * production) correspond exactement a la grille documentee dans
 * docs/demo/matrice-rbac.md -- c'est-a-dire au contenu reel de
 * `role_permission` issu du seed 0001. RouteMatrixTest prouve deja que
 * `guard()` applique CSRF + la permission EXACTE avec un jeu de permissions
 * SYNTHETIQUE (FakeDatabase) ; ce test ferme la boucle en verifiant que les
 * jeux de permissions REELS des 5 roles de production produisent bien le
 * "403 (refuse) / autorise" attendu -- sans reinventer la table de routes.
 *
 * Auto-skip si WAKDO_DB_TESTS != 1 ou base injoignable (meme garde que les
 * autres tests de tests/Integration/).
 */
final class RouteMatrixRoleDbTest extends TestCase
{
    /**
     * Grille de permissions attendue par role (docs/demo/matrice-rbac.md,
     * section 1 ; role_permission du seed 0001). Seule source de verite
     * DUPLIQUEE ICI (volontairement, en donnee brute a comparer) : la table de
     * ROUTES elle reste reprise par reflexion depuis RouteMatrixTest, jamais
     * recopiee.
     *
     * @var array<string, list<string>>
     */
    private const ROLE_PERMISSIONS = [
        'admin' => [
            'product.create', 'product.read', 'product.update', 'product.delete',
            'menu.create', 'menu.read', 'menu.update', 'menu.delete',
            'category.manage', 'ingredient.manage',
            'stock.read', 'stock.count', 'stock.manage',
            'order.read', 'order.create', 'order.deliver', 'order.cancel',
            'user.create', 'user.read', 'user.update', 'user.deactivate',
            'role.manage', 'stats.read',
        ],
        'manager' => [
            'product.create', 'product.read', 'product.update',
            'menu.create', 'menu.read', 'menu.update',
            'category.manage', 'ingredient.manage',
            'stock.read', 'stock.count', 'stock.manage',
            'user.read',
            'stats.read',
        ],
        'kitchen' => [
            'product.read', 'menu.read',
            'stock.read', 'stock.count',
            'order.read',
        ],
        'counter' => [
            'product.read', 'menu.read',
            'stock.read', 'stock.count',
            'order.read', 'order.create', 'order.deliver', 'order.cancel',
        ],
        'drive' => [
            'product.read', 'menu.read',
            'stock.read', 'stock.count',
            'order.read', 'order.create', 'order.deliver', 'order.cancel',
        ],
    ];

    /**
     * Comptes de demonstration (seed 0009) par email -> code de role attendu.
     *
     * @var array<string, string>
     */
    private const DEMO_ACCOUNTS = [
        'manager@wakdo.local'   => 'manager',
        'cuisine@wakdo.local'   => 'kitchen',
        'comptoir@wakdo.local'  => 'counter',
        'comptoir2@wakdo.local' => 'counter',
        'drive@wakdo.local'     => 'drive',
    ];

    private static Database $db;
    private static Authorizer $authorizer;

    /**
     * @var array<string, int> email -> role_id reel resolu en base
     */
    private static array $roleIdByEmail = [];

    public static function setUpBeforeClass(): void
    {
        if (getenv('WAKDO_DB_TESTS') !== '1') {
            return;
        }

        self::$db = new Database(new Config());

        try {
            self::$db->fetch('SELECT 1');
        } catch (Throwable) {
            return;
        }

        self::$authorizer = new Authorizer(self::$db);

        foreach (self::DEMO_ACCOUNTS as $email => $expectedRoleCode) {
            $row = self::$db->fetch(
                'SELECT u.role_id, r.code FROM user u JOIN role r ON r.id = u.role_id WHERE u.email = :email AND u.is_active = 1',
                ['email' => $email],
            );
            if ($row === null) {
                continue;
            }
            if ((string) ($row['code'] ?? '') === $expectedRoleCode) {
                self::$roleIdByEmail[$email] = (int) $row['role_id'];
            }
        }
    }

    protected function setUp(): void
    {
        if (getenv('WAKDO_DB_TESTS') !== '1') {
            self::markTestSkipped('Tests DB desactives (definir WAKDO_DB_TESTS=1 + DB_*).');
        }
        if (!isset(self::$authorizer)) {
            self::markTestSkipped('Base injoignable.');
        }
    }

    /**
     * Reprend, par reflexion, la table ROUTES de RouteMatrixTest (private const) :
     * [methode, chemin, ressource, action, permission, ecriture]. Le require_once
     * garantit que la classe existe meme si ce fichier est lance seul via
     * --filter (meme precaution que RouteMatrixTest pour ses propres dependances).
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private static function routes(): array
    {
        require_once __DIR__ . '/../Unit/Admin/Api/RouteMatrixTest.php';

        $reflection = new ReflectionClass(\App\Tests\Unit\Admin\Api\RouteMatrixTest::class);

        /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}> $routes */
        $routes = $reflection->getConstant('ROUTES');

        return $routes;
    }

    /**
     * Un cas par (compte de demonstration reel x route). "critique" = les 23
     * permissions du catalogue, chacune couvrant au moins une route parmi les
     * routes /admin/api/* de RouteMatrixTest (dont /admin/api/roles et
     * /admin/api/users, jamais touchees par un test cote pages) -- le nombre
     * exact suit RouteMatrixTest::ROUTES, jamais recopie en dur ici.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function demoAccountRouteProvider(): array
    {
        $cases = [];
        foreach (self::DEMO_ACCOUNTS as $email => $roleCode) {
            foreach (self::routes() as [$method, $path, , , $permission]) {
                $cases["$email $method $path"] = [$email, $roleCode, $permission];
            }
        }

        return $cases;
    }

    #[DataProvider('demoAccountRouteProvider')]
    public function testDemoAccountPermissionMatchesDocumentedMatrix(string $email, string $roleCode, string $permission): void
    {
        self::assertArrayHasKey(
            $email,
            self::$roleIdByEmail,
            "Le compte de demonstration $email est introuvable ou son role ne correspond pas a '$roleCode' (seed 0009 joue ?).",
        );

        $roleId = self::$roleIdByEmail[$email];
        $expectedGranted = in_array($permission, self::ROLE_PERMISSIONS[$roleCode], true);
        $actualGranted = self::$authorizer->can($roleId, $permission);

        self::assertSame(
            $expectedGranted,
            $actualGranted,
            "$email (role $roleCode) : la permission '$permission' devrait etre "
            . ($expectedGranted ? 'ACCORDEE (autorise)' : 'REFUSEE (403)')
            . ' selon docs/demo/matrice-rbac.md, mais role_permission en base dit le contraire.',
        );
    }

    /**
     * Contre-preuve globale : le total de permissions par role en base (COUNT sur
     * role_permission) correspond au total documente (matrice-rbac.md : admin 23,
     * manager 13, kitchen 5, counter 8, drive 8). Detecte une permission
     * supplementaire non couverte par ROUTES (donc invisible au test route-par-
     * route ci-dessus, qui ne peut echouer que sur les permissions QU'IL CONNAIT).
     */
    public function testPermissionCountPerRoleMatchesDocumentedTotal(): void
    {
        foreach (self::DEMO_ACCOUNTS as $email => $roleCode) {
            if (!isset(self::$roleIdByEmail[$email])) {
                continue;
            }
            $count = (int) (self::$db->fetch(
                'SELECT COUNT(*) AS n FROM role_permission WHERE role_id = :r',
                ['r' => self::$roleIdByEmail[$email]],
            )['n'] ?? -1);

            self::assertSame(
                count(self::ROLE_PERMISSIONS[$roleCode]),
                $count,
                "role '$roleCode' : nombre de permissions en base different du total documente dans matrice-rbac.md.",
            );
        }

        $adminRow = self::$db->fetch("SELECT id FROM role WHERE code = 'admin'");
        self::assertNotNull($adminRow, "role 'admin' introuvable (seed 0001 joue ?).");
        $adminCount = (int) (self::$db->fetch(
            'SELECT COUNT(*) AS n FROM role_permission WHERE role_id = :r',
            ['r' => (int) $adminRow['id']],
        )['n'] ?? -1);
        self::assertSame(23, $adminCount, "role 'admin' : attendu les 23 permissions du catalogue (croisement complet).");
    }
}
