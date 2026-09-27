<?php

declare(strict_types=1);

namespace App\Tests\Unit\Health;

use PHPUnit\Framework\TestCase;
use App\Core\Config;
use App\Core\Database;
use App\Core\Router;

/**
 * Preuve que le deplacement des routes de src/public/admin/index.php vers
 * src/app/Core/routes.php n'a rien change au comportement de production
 * (contrat sante-back-office, section 1) : routes.php charge sur un Router neuf
 * doit produire EXACTEMENT les 155 routes de origin/dev (commit ab0c553),
 * DANS LE MEME ORDRE, plus les 2 nouvelles (/admin/health, /admin/api/health) --
 * 157 au total.
 *
 * Les 155 tuples [methode, chemin, classe, action] ci-dessous sont extraits
 * MOT POUR MOT de `git show origin/dev:src/public/admin/index.php` (commit
 * ab0c553, identique a HEAD au moment ou ce chantier a demarre) par un script
 * ponctuel (pas relu ici a l'execution : aucune dependance a `git`, absent des
 * conteneurs PHP de test) -- une preuve figee, independante de toute autre table
 * du projet (routes-final.json compris), pour qu'un desaccord entre les deux
 * soit repere plutot que masque.
 */
final class RoutesPhpTest extends TestCase
{
    /**
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private const ORIGIN_DEV_ROUTES = [
        ['GET', '/', 'App\Controllers\HomeController', 'index'],
        ['GET', '/api/health', 'App\Controllers\HealthController', 'index'],
        ['GET', '/login', 'App\Controllers\AuthController', 'showLogin'],
        ['POST', '/login', 'App\Controllers\AuthController', 'login'],
        ['POST', '/logout', 'App\Controllers\AuthController', 'logout'],
        ['GET', '/forgot_password', 'App\Controllers\PasswordResetController', 'showRequest'],
        ['POST', '/forgot_password', 'App\Controllers\PasswordResetController', 'submitRequest'],
        ['GET', '/reset_password', 'App\Controllers\PasswordResetController', 'showConfirm'],
        ['POST', '/reset_password', 'App\Controllers\PasswordResetController', 'submitConfirm'],
        ['POST', '/api/orders', 'App\Controllers\OrderController', 'create'],
        ['POST', '/api/orders/{number}/pay', 'App\Controllers\OrderController', 'pay'],
        ['GET', '/api/orders/{number}', 'App\Controllers\OrderController', 'show'],
        ['GET', '/api/categories', 'App\Controllers\CatalogueController', 'categories'],
        ['GET', '/api/products', 'App\Controllers\CatalogueController', 'products'],
        ['GET', '/api/products/{id}', 'App\Controllers\CatalogueController', 'product'],
        ['GET', '/api/menus', 'App\Controllers\CatalogueController', 'menus'],
        ['GET', '/api/menus/{id}', 'App\Controllers\CatalogueController', 'menu'],
        ['GET', '/api/allergens', 'App\Controllers\CatalogueController', 'allergens'],
        ['GET', '/admin/me', 'App\Controllers\MeController', 'show'],
        ['GET', '/admin/dashboard', 'App\Controllers\DashboardController', 'index'],
        ['GET', '/admin/stats', 'App\Controllers\StatsController', 'index'],
        ['GET', '/admin/orders', 'App\Controllers\OrderAdminController', 'index'],
        ['POST', '/admin/orders/{number}/deliver', 'App\Controllers\OrderAdminController', 'deliver'],
        ['POST', '/admin/orders/{number}/ready', 'App\Controllers\OrderAdminController', 'ready'],
        ['GET', '/admin/orders/{number}/cancel', 'App\Controllers\OrderAdminController', 'confirmCancel'],
        ['POST', '/admin/orders/{number}/cancel', 'App\Controllers\OrderAdminController', 'cancel'],
        ['GET', '/kitchen/display', 'App\Controllers\KitchenController', 'display'],
        ['GET', '/counter/orders', 'App\Controllers\CounterOrderController', 'index'],
        ['GET', '/counter/orders/new', 'App\Controllers\CounterOrderController', 'create'],
        ['POST', '/counter/orders', 'App\Controllers\CounterOrderController', 'store'],
        ['GET', '/drive/orders', 'App\Controllers\CounterOrderController', 'index'],
        ['GET', '/drive/orders/new', 'App\Controllers\CounterOrderController', 'create'],
        ['POST', '/drive/orders', 'App\Controllers\CounterOrderController', 'store'],
        ['GET', '/admin/users', 'App\Controllers\UserController', 'index'],
        ['GET', '/admin/users/new', 'App\Controllers\UserController', 'create'],
        ['POST', '/admin/users', 'App\Controllers\UserController', 'store'],
        ['GET', '/admin/users/{id}/edit', 'App\Controllers\UserController', 'edit'],
        ['POST', '/admin/users/{id}', 'App\Controllers\UserController', 'update'],
        ['GET', '/admin/users/{id}/deactivate', 'App\Controllers\UserController', 'confirmDeactivate'],
        ['POST', '/admin/users/{id}/deactivate', 'App\Controllers\UserController', 'deactivate'],
        ['GET', '/admin/users/{id}/reset-pin', 'App\Controllers\UserController', 'confirmResetPin'],
        ['POST', '/admin/users/{id}/reset-pin', 'App\Controllers\UserController', 'resetPin'],
        ['GET', '/admin/users/{id}/erase', 'App\Controllers\UserController', 'confirmErase'],
        ['POST', '/admin/users/{id}/erase', 'App\Controllers\UserController', 'erase'],
        ['GET', '/admin/roles', 'App\Controllers\RoleController', 'index'],
        ['GET', '/admin/roles/new', 'App\Controllers\RoleController', 'create'],
        ['POST', '/admin/roles', 'App\Controllers\RoleController', 'store'],
        ['GET', '/admin/roles/{id}/edit', 'App\Controllers\RoleController', 'edit'],
        ['POST', '/admin/roles/{id}', 'App\Controllers\RoleController', 'update'],
        ['GET', '/admin/categories', 'App\Controllers\CategoryController', 'index'],
        ['GET', '/admin/categories/new', 'App\Controllers\CategoryController', 'create'],
        ['POST', '/admin/categories', 'App\Controllers\CategoryController', 'store'],
        ['GET', '/admin/categories/{id}/edit', 'App\Controllers\CategoryController', 'edit'],
        ['POST', '/admin/categories/{id}', 'App\Controllers\CategoryController', 'update'],
        ['POST', '/admin/categories/{id}/toggle', 'App\Controllers\CategoryController', 'toggle'],
        ['POST', '/admin/categories/{id}/move', 'App\Controllers\CategoryController', 'move'],
        ['GET', '/admin/profile/pin', 'App\Controllers\ProfileController', 'showPin'],
        ['POST', '/admin/profile/pin', 'App\Controllers\ProfileController', 'updatePin'],
        ['GET', '/admin/privacy', 'App\Controllers\PrivacyController', 'index'],
        ['GET', '/admin/products', 'App\Controllers\ProductController', 'index'],
        ['GET', '/admin/products/by-category', 'App\Controllers\ProductController', 'byCategory'],
        ['GET', '/admin/products/new', 'App\Controllers\ProductController', 'create'],
        ['POST', '/admin/products', 'App\Controllers\ProductController', 'store'],
        ['GET', '/admin/products/{id}/edit', 'App\Controllers\ProductController', 'edit'],
        ['POST', '/admin/products/{id}', 'App\Controllers\ProductController', 'update'],
        ['GET', '/admin/products/{id}/delete', 'App\Controllers\ProductController', 'confirmDelete'],
        ['POST', '/admin/products/{id}/delete', 'App\Controllers\ProductController', 'destroy'],
        ['POST', '/admin/products/{id}/move', 'App\Controllers\ProductController', 'move'],
        ['GET', '/admin/products/{id}/recipe', 'App\Controllers\ProductController', 'recipeForm'],
        ['POST', '/admin/products/{id}/recipe', 'App\Controllers\ProductController', 'saveRecipe'],
        ['GET', '/admin/products/import', 'App\Controllers\ProductController', 'importForm'],
        ['GET', '/admin/products/import/template', 'App\Controllers\ProductController', 'importTemplate'],
        ['POST', '/admin/products/import/preview', 'App\Controllers\ProductController', 'importPreview'],
        ['POST', '/admin/products/import/confirm', 'App\Controllers\ProductController', 'importConfirm'],
        ['GET', '/admin/menus', 'App\Controllers\MenuController', 'index'],
        ['GET', '/admin/menus/new', 'App\Controllers\MenuController', 'create'],
        ['POST', '/admin/menus', 'App\Controllers\MenuController', 'store'],
        ['GET', '/admin/menus/{id}/edit', 'App\Controllers\MenuController', 'edit'],
        ['POST', '/admin/menus/{id}', 'App\Controllers\MenuController', 'update'],
        ['POST', '/admin/menus/{id}/toggle', 'App\Controllers\MenuController', 'toggle'],
        ['GET', '/admin/menus/{id}/delete', 'App\Controllers\MenuController', 'confirmDelete'],
        ['POST', '/admin/menus/{id}/delete', 'App\Controllers\MenuController', 'destroy'],
        ['GET', '/admin/ingredients', 'App\Controllers\IngredientController', 'index'],
        ['GET', '/admin/ingredients/new', 'App\Controllers\IngredientController', 'create'],
        ['POST', '/admin/ingredients', 'App\Controllers\IngredientController', 'store'],
        ['GET', '/admin/ingredients/{id}/edit', 'App\Controllers\IngredientController', 'edit'],
        ['POST', '/admin/ingredients/{id}', 'App\Controllers\IngredientController', 'update'],
        ['POST', '/admin/ingredients/{id}/toggle', 'App\Controllers\IngredientController', 'toggle'],
        ['GET', '/admin/ingredients/{id}/delete', 'App\Controllers\IngredientController', 'confirmDelete'],
        ['POST', '/admin/ingredients/{id}/delete', 'App\Controllers\IngredientController', 'destroy'],
        ['GET', '/admin/ingredients/{id}/restock', 'App\Controllers\IngredientController', 'restockForm'],
        ['POST', '/admin/ingredients/{id}/restock', 'App\Controllers\IngredientController', 'restock'],
        ['POST', '/admin/ingredients/{id}/thresholds', 'App\Controllers\IngredientController', 'updateThresholds'],
        ['GET', '/admin/ingredients/{id}/inventory', 'App\Controllers\IngredientController', 'inventoryForm'],
        ['POST', '/admin/ingredients/{id}/inventory', 'App\Controllers\IngredientController', 'inventory'],
        ['GET', '/admin/ingredients/{id}/adjust', 'App\Controllers\IngredientController', 'adjustForm'],
        ['POST', '/admin/ingredients/{id}/adjust', 'App\Controllers\IngredientController', 'adjust'],
        ['GET', '/admin/ingredients/{id}/movements', 'App\Controllers\IngredientController', 'movements'],
        ['POST', '/admin/ingredients/{id}/enrich', 'App\Controllers\IngredientController', 'enrich'],
        ['POST', '/admin/ingredients/{id}/allergens', 'App\Controllers\IngredientController', 'allergens'],
        ['POST', '/admin/api/auth/login', 'App\Controllers\Admin\Api\AuthApiController', 'apiLogin'],
        ['POST', '/admin/api/auth/logout', 'App\Controllers\Admin\Api\AuthApiController', 'apiLogout'],
        ['GET', '/admin/api/auth/me', 'App\Controllers\Admin\Api\AuthApiController', 'apiMe'],
        ['GET', '/admin/api/categories', 'App\Controllers\Admin\Api\CategoryApiController', 'apiIndex'],
        ['GET', '/admin/api/categories/{id}', 'App\Controllers\Admin\Api\CategoryApiController', 'apiShow'],
        ['POST', '/admin/api/categories', 'App\Controllers\Admin\Api\CategoryApiController', 'apiStore'],
        ['PUT', '/admin/api/categories/{id}', 'App\Controllers\Admin\Api\CategoryApiController', 'apiUpdate'],
        ['DELETE', '/admin/api/categories/{id}', 'App\Controllers\Admin\Api\CategoryApiController', 'apiDestroy'],
        ['POST', '/admin/api/categories/{id}/toggle', 'App\Controllers\Admin\Api\CategoryApiController', 'apiToggle'],
        ['POST', '/admin/api/categories/{id}/move', 'App\Controllers\Admin\Api\CategoryApiController', 'apiMove'],
        ['GET', '/admin/api/products', 'App\Controllers\Admin\Api\ProductApiController', 'apiIndex'],
        ['GET', '/admin/api/products/{id}', 'App\Controllers\Admin\Api\ProductApiController', 'apiShow'],
        ['POST', '/admin/api/products', 'App\Controllers\Admin\Api\ProductApiController', 'apiStore'],
        ['PUT', '/admin/api/products/{id}', 'App\Controllers\Admin\Api\ProductApiController', 'apiUpdate'],
        ['DELETE', '/admin/api/products/{id}', 'App\Controllers\Admin\Api\ProductApiController', 'apiDestroy'],
        ['POST', '/admin/api/products/{id}/move', 'App\Controllers\Admin\Api\ProductApiController', 'apiMove'],
        ['GET', '/admin/api/products/{id}/recipe', 'App\Controllers\Admin\Api\ProductApiController', 'apiRecipeShow'],
        ['PUT', '/admin/api/products/{id}/recipe', 'App\Controllers\Admin\Api\ProductApiController', 'apiRecipeSave'],
        ['GET', '/admin/api/products/import/template', 'App\Controllers\Admin\Api\ProductApiController', 'apiImportTemplate'],
        ['POST', '/admin/api/products/import', 'App\Controllers\Admin\Api\ProductApiController', 'apiImportRun'],
        ['GET', '/admin/api/menus', 'App\Controllers\Admin\Api\MenuApiController', 'apiIndex'],
        ['GET', '/admin/api/menus/{id}', 'App\Controllers\Admin\Api\MenuApiController', 'apiShow'],
        ['POST', '/admin/api/menus', 'App\Controllers\Admin\Api\MenuApiController', 'apiStore'],
        ['PUT', '/admin/api/menus/{id}', 'App\Controllers\Admin\Api\MenuApiController', 'apiUpdate'],
        ['DELETE', '/admin/api/menus/{id}', 'App\Controllers\Admin\Api\MenuApiController', 'apiDestroy'],
        ['POST', '/admin/api/menus/{id}/toggle', 'App\Controllers\Admin\Api\MenuApiController', 'apiToggle'],
        ['GET', '/admin/api/ingredients', 'App\Controllers\Admin\Api\IngredientApiController', 'apiIndex'],
        ['GET', '/admin/api/ingredients/{id}', 'App\Controllers\Admin\Api\IngredientApiController', 'apiShow'],
        ['POST', '/admin/api/ingredients', 'App\Controllers\Admin\Api\IngredientApiController', 'apiStore'],
        ['PUT', '/admin/api/ingredients/{id}', 'App\Controllers\Admin\Api\IngredientApiController', 'apiUpdate'],
        ['DELETE', '/admin/api/ingredients/{id}', 'App\Controllers\Admin\Api\IngredientApiController', 'apiDestroy'],
        ['POST', '/admin/api/ingredients/{id}/restock', 'App\Controllers\Admin\Api\IngredientApiController', 'apiRestock'],
        ['POST', '/admin/api/ingredients/{id}/toggle', 'App\Controllers\Admin\Api\IngredientApiController', 'apiToggle'],
        ['PUT', '/admin/api/ingredients/{id}/thresholds', 'App\Controllers\Admin\Api\IngredientApiController', 'apiThresholds'],
        ['POST', '/admin/api/ingredients/{id}/inventory', 'App\Controllers\Admin\Api\IngredientApiController', 'apiInventory'],
        ['POST', '/admin/api/ingredients/{id}/adjust', 'App\Controllers\Admin\Api\IngredientApiController', 'apiAdjust'],
        ['PUT', '/admin/api/ingredients/{id}/allergens', 'App\Controllers\Admin\Api\IngredientApiController', 'apiAllergens'],
        ['GET', '/admin/api/users', 'App\Controllers\Admin\Api\UserApiController', 'apiIndex'],
        ['GET', '/admin/api/users/{id}', 'App\Controllers\Admin\Api\UserApiController', 'apiShow'],
        ['POST', '/admin/api/users', 'App\Controllers\Admin\Api\UserApiController', 'apiStore'],
        ['PUT', '/admin/api/users/{id}', 'App\Controllers\Admin\Api\UserApiController', 'apiUpdate'],
        ['DELETE', '/admin/api/users/{id}', 'App\Controllers\Admin\Api\UserApiController', 'apiDestroy'],
        ['POST', '/admin/api/users/{id}/reset-pin', 'App\Controllers\Admin\Api\UserApiController', 'apiResetPin'],
        ['POST', '/admin/api/users/{id}/erase', 'App\Controllers\Admin\Api\UserApiController', 'apiErase'],
        ['GET', '/admin/api/roles', 'App\Controllers\Admin\Api\RoleApiController', 'apiIndex'],
        ['GET', '/admin/api/roles/{id}', 'App\Controllers\Admin\Api\RoleApiController', 'apiShow'],
        ['POST', '/admin/api/roles', 'App\Controllers\Admin\Api\RoleApiController', 'apiStore'],
        ['PUT', '/admin/api/roles/{id}', 'App\Controllers\Admin\Api\RoleApiController', 'apiUpdate'],
        ['GET', '/admin/api/orders', 'App\Controllers\Admin\Api\OrderApiController', 'apiIndex'],
        ['GET', '/admin/api/orders/{number}', 'App\Controllers\Admin\Api\OrderApiController', 'apiShow'],
        ['POST', '/admin/api/orders', 'App\Controllers\Admin\Api\OrderApiController', 'apiStore'],
        ['POST', '/admin/api/orders/{number}/ready', 'App\Controllers\Admin\Api\OrderApiController', 'apiReady'],
        ['POST', '/admin/api/orders/{number}/deliver', 'App\Controllers\Admin\Api\OrderApiController', 'apiDeliver'],
        ['POST', '/admin/api/orders/{number}/cancel', 'App\Controllers\Admin\Api\OrderApiController', 'apiCancel'],
        ['GET', '/admin/api/stats', 'App\Controllers\Admin\Api\StatsApiController', 'apiIndex'],
    ];

    /**
     * @return list<array{method: string, pattern: string, handler: array{0: class-string, 1: string}}>
     */
    private function loadRoutes(): array
    {
        $router = new Router(new Config(), new Database(new Config()));
        (require dirname(__DIR__, 3) . '/src/app/Core/routes.php')($router);

        return $router->routes();
    }

    public function testRoutesPhpProducesExactly157Routes(): void
    {
        self::assertCount(157, $this->loadRoutes());
    }

    public function testRoutesPhpAddsExactlyTheTwoHealthRoutes(): void
    {
        $signatures = array_map(
            static fn (array $r): string => $r['method'] . ' ' . $r['pattern'],
            $this->loadRoutes(),
        );
        $newOnes = array_values(array_diff($signatures, array_map(
            static fn (array $r): string => $r[0] . ' ' . $r[1],
            self::ORIGIN_DEV_ROUTES,
        )));

        sort($newOnes);
        self::assertSame(['GET /admin/api/health', 'GET /admin/health'], $newOnes);
    }

    /**
     * Le coeur de la preuve : en retirant les 2 nouvelles routes, ce qui reste
     * doit etre EXACTEMENT les 155 routes d'origin/dev, DANS LE MEME ORDRE.
     */
    public function testExistingRoutesAreUnchangedAndInTheSameOrderAsOriginDev(): void
    {
        $withoutNewOnes = array_values(array_filter(
            $this->loadRoutes(),
            static fn (array $r): bool => !in_array(
                $r['method'] . ' ' . $r['pattern'],
                ['GET /admin/health', 'GET /admin/api/health'],
                true,
            ),
        ));

        self::assertCount(155, $withoutNewOnes);

        foreach (self::ORIGIN_DEV_ROUTES as $i => [$method, $pattern, $class, $action]) {
            $actual = $withoutNewOnes[$i];
            self::assertSame(
                $method,
                $actual['method'],
                "Route #$i : methode differente d'origin/dev ($method attendu, chemin $pattern).",
            );
            self::assertSame(
                $pattern,
                $actual['pattern'],
                "Route #$i : motif different d'origin/dev (methode $method).",
            );
            self::assertSame(
                [$class, $action],
                $actual['handler'],
                "Route #$i ($method $pattern) : gestionnaire different d'origin/dev.",
            );
        }
    }
}
