<?php

declare(strict_types=1);

/**
 * Front controller du vhost admin (back-office + API sous /api).
 *
 * Apache reecrit toute requete non-fichier vers ce fichier (RewriteRule ^ index.php).
 * Le REQUEST_URI arrive intact (pas de prefixe strippe), donc le routeur voit
 * "/", "/api/health", etc.
 */

use App\Auth\SessionManager;
use App\Controllers\AuthController;
use App\Controllers\CategoryController;
use App\Controllers\DashboardController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\IngredientController;
use App\Controllers\MeController;
use App\Controllers\MenuController;
use App\Controllers\PasswordResetController;
use App\Controllers\ProductController;
use App\Controllers\ProfileController;
use App\Controllers\StatsController;
use App\Controllers\RoleController;
use App\Controllers\UserController;
use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

// src/public/admin/index.php : __DIR__ = src/public/admin ; remonter de deux
// niveaux (admin -> public -> src) pour atteindre la racine src/.
require dirname(__DIR__, 2) . '/app/Core/Autoloader.php';
Autoloader::register();

// En-tetes de securite poses tot, valables sur toute reponse y compris une 500.
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

$config = new Config();
date_default_timezone_set($config->timezone());

try {
    // Acces BDD paresseux : la connexion n'est ouverte qu'au premier query(),
    // donc la home back-office reste servie meme base indisponible.
    $database = new Database($config);

    // Demarre la session du vhost admin avant le dispatch (effet de bord global,
    // hors du Core stateless). Les controleurs y rattachent leur SessionManager.
    (new SessionManager($config))->start();

    $router = new Router($config, $database);
    $router->add('GET', '/', [HomeController::class, 'index']);
    $router->add('GET', '/api/health', [HealthController::class, 'index']);

    // Authentification back-office (mlt.md section 12). Le docroot du vhost admin
    // etant src/public/admin, le Router voit "/login" (pas de prefixe "/admin").
    $router->add('GET', '/login', [AuthController::class, 'showLogin']);
    $router->add('POST', '/login', [AuthController::class, 'login']);
    $router->add('POST', '/logout', [AuthController::class, 'logout']);
    $router->add('GET', '/forgot_password', [PasswordResetController::class, 'showRequest']);
    $router->add('POST', '/forgot_password', [PasswordResetController::class, 'submitRequest']);
    $router->add('GET', '/reset_password', [PasswordResetController::class, 'showConfirm']);
    $router->add('POST', '/reset_password', [PasswordResetController::class, 'submitConfirm']);

    // RBAC : identite + permissions de la session courante (gardee par SessionGuard).
    $router->add('GET', '/api/me', [MeController::class, 'show']);

    // Back-office (P3) : pages rendues serveur sous /admin, gardees par SessionGuard.
    $router->add('GET', '/admin/dashboard', [DashboardController::class, 'index']);
    // Tableau de bord statistiques (stats.read) : landing du role manager. KPIs
    // catalogue + sante stock (RG-T21) ; KPIs de vente avec les commandes (P4).
    $router->add('GET', '/admin/stats', [StatsController::class, 'index']);

    // Gestion des comptes (mlt domaine 10). user.read (liste) ; user.create/update/
    // deactivate. TOUTES les mutations = PIN equipier + audit (RG-T13/14). {id} = un
    // seul segment (pas de collision avec /edit, /deactivate, /reset-pin, /erase).
    $router->add('GET', '/admin/users', [UserController::class, 'index']);
    $router->add('GET', '/admin/users/new', [UserController::class, 'create']);
    $router->add('POST', '/admin/users', [UserController::class, 'store']);
    $router->add('GET', '/admin/users/{id}/edit', [UserController::class, 'edit']);
    $router->add('POST', '/admin/users/{id}', [UserController::class, 'update']);
    $router->add('GET', '/admin/users/{id}/deactivate', [UserController::class, 'confirmDeactivate']);
    $router->add('POST', '/admin/users/{id}/deactivate', [UserController::class, 'deactivate']);
    $router->add('GET', '/admin/users/{id}/reset-pin', [UserController::class, 'confirmResetPin']);
    $router->add('POST', '/admin/users/{id}/reset-pin', [UserController::class, 'resetPin']);
    $router->add('GET', '/admin/users/{id}/erase', [UserController::class, 'confirmErase']);
    $router->add('POST', '/admin/users/{id}/erase', [UserController::class, 'erase']);

    // RBAC (mlt 10.4, role.manage) : matrice roles x permissions + roles custom.
    // Toute mutation = PIN equipier + audit (details = diff de permissions, RG-6).
    $router->add('GET', '/admin/roles', [RoleController::class, 'index']);
    $router->add('GET', '/admin/roles/new', [RoleController::class, 'create']);
    $router->add('POST', '/admin/roles', [RoleController::class, 'store']);
    $router->add('GET', '/admin/roles/{id}/edit', [RoleController::class, 'edit']);
    $router->add('POST', '/admin/roles/{id}', [RoleController::class, 'update']);

    // CRUD Categories (permission category.manage). Pas de suppression dure : toggle is_active.
    $router->add('GET', '/admin/categories', [CategoryController::class, 'index']);
    $router->add('GET', '/admin/categories/new', [CategoryController::class, 'create']);
    $router->add('POST', '/admin/categories', [CategoryController::class, 'store']);
    $router->add('GET', '/admin/categories/{id}/edit', [CategoryController::class, 'edit']);
    $router->add('POST', '/admin/categories/{id}', [CategoryController::class, 'update']);
    $router->add('POST', '/admin/categories/{id}/toggle', [CategoryController::class, 'toggle']);

    // Profil self-service : definition du PIN d'action sensible (RG-T13).
    $router->add('GET', '/admin/profile/pin', [ProfileController::class, 'showPin']);
    $router->add('POST', '/admin/profile/pin', [ProfileController::class, 'updatePin']);

    // CRUD Produits (product.read/create/update/delete). PIN equipier + audit sur
    // changement prix/TVA (update) et suppression (delete).
    $router->add('GET', '/admin/products', [ProductController::class, 'index']);
    $router->add('GET', '/admin/products/new', [ProductController::class, 'create']);
    $router->add('POST', '/admin/products', [ProductController::class, 'store']);
    $router->add('GET', '/admin/products/{id}/edit', [ProductController::class, 'edit']);
    $router->add('POST', '/admin/products/{id}', [ProductController::class, 'update']);
    $router->add('GET', '/admin/products/{id}/delete', [ProductController::class, 'confirmDelete']);
    $router->add('POST', '/admin/products/{id}/delete', [ProductController::class, 'destroy']);
    // Editeur de recette (composition product_ingredient). Permission ingredient.manage
    // (composition), distincte du CRUD produit ; sans PIN. Debloque la dispo calculee
    // RG-T21 et ferme la dette #27 (trace cascade a la suppression).
    $router->add('GET', '/admin/products/{id}/recipe', [ProductController::class, 'recipeForm']);
    $router->add('POST', '/admin/products/{id}/recipe', [ProductController::class, 'saveRecipe']);

    // CRUD Menus (menu.read/create/update/delete). Menu compose = burger de base +
    // slots (menu_slot / menu_slot_option). PIN equipier + audit sur suppression
    // (mlt 8.6) ; create/update sans PIN. {id} = un seul segment, pas de collision
    // avec /toggle ni /delete.
    $router->add('GET', '/admin/menus', [MenuController::class, 'index']);
    $router->add('GET', '/admin/menus/new', [MenuController::class, 'create']);
    $router->add('POST', '/admin/menus', [MenuController::class, 'store']);
    $router->add('GET', '/admin/menus/{id}/edit', [MenuController::class, 'edit']);
    $router->add('POST', '/admin/menus/{id}', [MenuController::class, 'update']);
    $router->add('POST', '/admin/menus/{id}/toggle', [MenuController::class, 'toggle']);
    $router->add('GET', '/admin/menus/{id}/delete', [MenuController::class, 'confirmDelete']);
    $router->add('POST', '/admin/menus/{id}/delete', [MenuController::class, 'destroy']);

    // Stock / Ingredients (P3, mlt 8.8 + domaine 9). Permissions par operation :
    // stock.read (liste/mouvements, tous roles) ; ingredient.manage (CRUD, sans PIN) ;
    // stock.manage (reappro, sans PIN) ; stock.count (inventaire, + PIN). Pas d'audit_log
    // (RG-T14) : l'attribution passe par stock_movement.user_id.
    $router->add('GET', '/admin/ingredients', [IngredientController::class, 'index']);
    $router->add('GET', '/admin/ingredients/new', [IngredientController::class, 'create']);
    $router->add('POST', '/admin/ingredients', [IngredientController::class, 'store']);
    $router->add('GET', '/admin/ingredients/{id}/edit', [IngredientController::class, 'edit']);
    $router->add('POST', '/admin/ingredients/{id}', [IngredientController::class, 'update']);
    $router->add('POST', '/admin/ingredients/{id}/toggle', [IngredientController::class, 'toggle']);
    $router->add('GET', '/admin/ingredients/{id}/delete', [IngredientController::class, 'confirmDelete']);
    $router->add('POST', '/admin/ingredients/{id}/delete', [IngredientController::class, 'destroy']);
    $router->add('GET', '/admin/ingredients/{id}/restock', [IngredientController::class, 'restockForm']);
    $router->add('POST', '/admin/ingredients/{id}/restock', [IngredientController::class, 'restock']);
    $router->add('GET', '/admin/ingredients/{id}/inventory', [IngredientController::class, 'inventoryForm']);
    $router->add('POST', '/admin/ingredients/{id}/inventory', [IngredientController::class, 'inventory']);
    $router->add('GET', '/admin/ingredients/{id}/movements', [IngredientController::class, 'movements']);

    $response = $router->dispatch(Request::fromGlobals());
    $response->send();
} catch (Throwable $exception) {
    // En debug on remonte le message pour iterer ; en prod, reponse generique
    // pour ne rien divulguer de la pile interne (information disclosure).
    $payload = $config->isDebug()
        ? ['data' => null, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => $exception->getMessage()]]
        : ['data' => null, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Internal server error']];

    (new Response())->json($payload, 500)->send();
}
