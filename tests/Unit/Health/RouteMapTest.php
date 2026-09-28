<?php

declare(strict_types=1);

namespace App\Tests\Unit\Health;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use App\Health\RouteMap;

/**
 * App\Health\RouteMap::rows() doit rendre EXACTEMENT les valeurs de
 * scratchpad/routes-final.json (contrat sante-back-office, bloc 4) pour les 155
 * routes existantes -- cle par cle, champ par champ -- plus les 2 nouvelles
 * routes de sante (/admin/health, /admin/api/health), groupe "Système".
 *
 * La reference ci-dessous (155 lignes) est une copie figee de
 * routes-final.json, deja verifiee (cf. rapport du chantier) : ce test la
 * compare a ce que produit RouteMap::rows() aujourd'hui, dans le MEME ORDRE que
 * le JSON de reference (qui suit l'ordre de declaration des routes).
 */
final class RouteMapTest extends TestCase
{
    /**
     * @var list<array<string, mixed>>
     */
    private const REFERENCE_ROWS = [
        ['m' => 'GET', 'p' => '/', 'c' => 'Home', 'a' => 'index', 's' => 'bo', 'anon' => true, 'perm' => null, 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Système'],
        ['m' => 'GET', 'p' => '/api/health', 'c' => 'Health', 'a' => 'index', 's' => 'borne', 'anon' => true, 'perm' => null, 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Système'],
        ['m' => 'GET', 'p' => '/login', 'c' => 'Auth', 'a' => 'showLogin', 's' => 'bo', 'anon' => true, 'perm' => null, 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Connexion et compte'],
        ['m' => 'POST', 'p' => '/login', 'c' => 'Auth', 'a' => 'login', 's' => 'bo', 'anon' => true, 'perm' => null, 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Connexion et compte'],
        // anon corrige a true (routes-final.json le donnait a tort a false) :
        // AuthController::logout() ne verifie que le jeton CSRF, jamais la
        // session -- ecart releve par le chantier D, verifie dans le controleur.
        ['m' => 'POST', 'p' => '/logout', 'c' => 'Auth', 'a' => 'logout', 's' => 'bo', 'anon' => true, 'perm' => null, 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Connexion et compte'],
        ['m' => 'GET', 'p' => '/forgot_password', 'c' => 'PasswordReset', 'a' => 'showRequest', 's' => 'bo', 'anon' => true, 'perm' => null, 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Connexion et compte'],
        ['m' => 'POST', 'p' => '/forgot_password', 'c' => 'PasswordReset', 'a' => 'submitRequest', 's' => 'bo', 'anon' => true, 'perm' => null, 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Connexion et compte'],
        ['m' => 'GET', 'p' => '/reset_password', 'c' => 'PasswordReset', 'a' => 'showConfirm', 's' => 'bo', 'anon' => true, 'perm' => null, 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Connexion et compte'],
        ['m' => 'POST', 'p' => '/reset_password', 'c' => 'PasswordReset', 'a' => 'submitConfirm', 's' => 'bo', 'anon' => true, 'perm' => null, 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Connexion et compte'],
        ['m' => 'POST', 'p' => '/api/orders', 'c' => 'Order', 'a' => 'create', 's' => 'borne', 'anon' => true, 'perm' => null, 'w' => true, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Commandes'],
        ['m' => 'POST', 'p' => '/api/orders/{number}/pay', 'c' => 'Order', 'a' => 'pay', 's' => 'borne', 'anon' => true, 'perm' => null, 'w' => true, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Commandes'],
        ['m' => 'GET', 'p' => '/api/orders/{number}', 'c' => 'Order', 'a' => 'show', 's' => 'borne', 'anon' => true, 'perm' => null, 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Commandes'],
        ['m' => 'GET', 'p' => '/api/categories', 'c' => 'Catalogue', 'a' => 'categories', 's' => 'borne', 'anon' => true, 'perm' => null, 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Catalogue'],
        ['m' => 'GET', 'p' => '/api/products', 'c' => 'Catalogue', 'a' => 'products', 's' => 'borne', 'anon' => true, 'perm' => null, 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Catalogue'],
        ['m' => 'GET', 'p' => '/api/products/{id}', 'c' => 'Catalogue', 'a' => 'product', 's' => 'borne', 'anon' => true, 'perm' => null, 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Catalogue'],
        ['m' => 'GET', 'p' => '/api/menus', 'c' => 'Catalogue', 'a' => 'menus', 's' => 'borne', 'anon' => true, 'perm' => null, 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Catalogue'],
        ['m' => 'GET', 'p' => '/api/menus/{id}', 'c' => 'Catalogue', 'a' => 'menu', 's' => 'borne', 'anon' => true, 'perm' => null, 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Catalogue'],
        ['m' => 'GET', 'p' => '/api/allergens', 'c' => 'Catalogue', 'a' => 'allergens', 's' => 'borne', 'anon' => true, 'perm' => null, 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Catalogue'],
        ['m' => 'GET', 'p' => '/admin/me', 'c' => 'Me', 'a' => 'show', 's' => 'bo', 'anon' => false, 'perm' => null, 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Connexion et compte'],
        ['m' => 'GET', 'p' => '/admin/dashboard', 'c' => 'Dashboard', 'a' => 'index', 's' => 'bo', 'anon' => false, 'perm' => null, 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Pilotage'],
        ['m' => 'GET', 'p' => '/admin/stats', 'c' => 'Stats', 'a' => 'index', 's' => 'bo', 'anon' => false, 'perm' => 'stats.read', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Pilotage'],
        ['m' => 'GET', 'p' => '/admin/orders', 'c' => 'OrderAdmin', 'a' => 'index', 's' => 'bo', 'anon' => false, 'perm' => 'order.read', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Commandes'],
        ['m' => 'POST', 'p' => '/admin/orders/{number}/deliver', 'c' => 'OrderAdmin', 'a' => 'deliver', 's' => 'bo', 'anon' => false, 'perm' => 'order.deliver', 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Commandes'],
        ['m' => 'POST', 'p' => '/admin/orders/{number}/ready', 'c' => 'OrderAdmin', 'a' => 'ready', 's' => 'bo', 'anon' => false, 'perm' => 'order.read', 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Commandes'],
        ['m' => 'GET', 'p' => '/admin/orders/{number}/cancel', 'c' => 'OrderAdmin', 'a' => 'confirmCancel', 's' => 'bo', 'anon' => false, 'perm' => 'order.cancel', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Commandes'],
        ['m' => 'POST', 'p' => '/admin/orders/{number}/cancel', 'c' => 'OrderAdmin', 'a' => 'cancel', 's' => 'bo', 'anon' => false, 'perm' => 'order.cancel', 'w' => true, 'csrf' => 'form', 'pin' => 'always', 'f' => 'redirect', 'g' => 'Commandes'],
        ['m' => 'GET', 'p' => '/kitchen/display', 'c' => 'Kitchen', 'a' => 'display', 's' => 'bo', 'anon' => false, 'perm' => 'order.read', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Commandes'],
        ['m' => 'GET', 'p' => '/counter/orders', 'c' => 'CounterOrder', 'a' => 'index', 's' => 'bo', 'anon' => false, 'perm' => 'order.create', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Commandes'],
        ['m' => 'GET', 'p' => '/counter/orders/new', 'c' => 'CounterOrder', 'a' => 'create', 's' => 'bo', 'anon' => false, 'perm' => 'order.create', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Commandes'],
        ['m' => 'POST', 'p' => '/counter/orders', 'c' => 'CounterOrder', 'a' => 'store', 's' => 'bo', 'anon' => false, 'perm' => 'order.create', 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Commandes'],
        ['m' => 'GET', 'p' => '/drive/orders', 'c' => 'CounterOrder', 'a' => 'index', 's' => 'bo', 'anon' => false, 'perm' => 'order.create', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Commandes'],
        ['m' => 'GET', 'p' => '/drive/orders/new', 'c' => 'CounterOrder', 'a' => 'create', 's' => 'bo', 'anon' => false, 'perm' => 'order.create', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Commandes'],
        ['m' => 'POST', 'p' => '/drive/orders', 'c' => 'CounterOrder', 'a' => 'store', 's' => 'bo', 'anon' => false, 'perm' => 'order.create', 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Commandes'],
        ['m' => 'GET', 'p' => '/admin/users', 'c' => 'User', 'a' => 'index', 's' => 'bo', 'anon' => false, 'perm' => 'user.read', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Comptes'],
        ['m' => 'GET', 'p' => '/admin/users/new', 'c' => 'User', 'a' => 'create', 's' => 'bo', 'anon' => false, 'perm' => 'user.create', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Comptes'],
        ['m' => 'POST', 'p' => '/admin/users', 'c' => 'User', 'a' => 'store', 's' => 'bo', 'anon' => false, 'perm' => 'user.create', 'w' => true, 'csrf' => 'form', 'pin' => 'always', 'f' => 'redirect', 'g' => 'Comptes'],
        ['m' => 'GET', 'p' => '/admin/users/{id}/edit', 'c' => 'User', 'a' => 'edit', 's' => 'bo', 'anon' => false, 'perm' => 'user.update', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Comptes'],
        ['m' => 'POST', 'p' => '/admin/users/{id}', 'c' => 'User', 'a' => 'update', 's' => 'bo', 'anon' => false, 'perm' => 'user.update', 'w' => true, 'csrf' => 'form', 'pin' => 'always', 'f' => 'redirect', 'g' => 'Comptes'],
        ['m' => 'GET', 'p' => '/admin/users/{id}/deactivate', 'c' => 'User', 'a' => 'confirmDeactivate', 's' => 'bo', 'anon' => false, 'perm' => 'user.deactivate', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Comptes'],
        ['m' => 'POST', 'p' => '/admin/users/{id}/deactivate', 'c' => 'User', 'a' => 'deactivate', 's' => 'bo', 'anon' => false, 'perm' => 'user.deactivate', 'w' => true, 'csrf' => 'form', 'pin' => 'always', 'f' => 'redirect', 'g' => 'Comptes'],
        ['m' => 'GET', 'p' => '/admin/users/{id}/reset-pin', 'c' => 'User', 'a' => 'confirmResetPin', 's' => 'bo', 'anon' => false, 'perm' => 'user.update', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Comptes'],
        ['m' => 'POST', 'p' => '/admin/users/{id}/reset-pin', 'c' => 'User', 'a' => 'resetPin', 's' => 'bo', 'anon' => false, 'perm' => 'user.update', 'w' => true, 'csrf' => 'form', 'pin' => 'always', 'f' => 'redirect', 'g' => 'Comptes'],
        ['m' => 'GET', 'p' => '/admin/users/{id}/erase', 'c' => 'User', 'a' => 'confirmErase', 's' => 'bo', 'anon' => false, 'perm' => 'user.update', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Comptes'],
        ['m' => 'POST', 'p' => '/admin/users/{id}/erase', 'c' => 'User', 'a' => 'erase', 's' => 'bo', 'anon' => false, 'perm' => 'user.update', 'w' => true, 'csrf' => 'form', 'pin' => 'always', 'f' => 'redirect', 'g' => 'Comptes'],
        ['m' => 'GET', 'p' => '/admin/roles', 'c' => 'Role', 'a' => 'index', 's' => 'bo', 'anon' => false, 'perm' => 'role.manage', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Rôles et permissions'],
        ['m' => 'GET', 'p' => '/admin/roles/new', 'c' => 'Role', 'a' => 'create', 's' => 'bo', 'anon' => false, 'perm' => 'role.manage', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Rôles et permissions'],
        ['m' => 'POST', 'p' => '/admin/roles', 'c' => 'Role', 'a' => 'store', 's' => 'bo', 'anon' => false, 'perm' => 'role.manage', 'w' => true, 'csrf' => 'form', 'pin' => 'always', 'f' => 'redirect', 'g' => 'Rôles et permissions'],
        ['m' => 'GET', 'p' => '/admin/roles/{id}/edit', 'c' => 'Role', 'a' => 'edit', 's' => 'bo', 'anon' => false, 'perm' => 'role.manage', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Rôles et permissions'],
        ['m' => 'POST', 'p' => '/admin/roles/{id}', 'c' => 'Role', 'a' => 'update', 's' => 'bo', 'anon' => false, 'perm' => 'role.manage', 'w' => true, 'csrf' => 'form', 'pin' => 'always', 'f' => 'redirect', 'g' => 'Rôles et permissions'],
        ['m' => 'GET', 'p' => '/admin/categories', 'c' => 'Category', 'a' => 'index', 's' => 'bo', 'anon' => false, 'perm' => 'category.manage', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Catégories'],
        ['m' => 'GET', 'p' => '/admin/categories/new', 'c' => 'Category', 'a' => 'create', 's' => 'bo', 'anon' => false, 'perm' => 'category.manage', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Catégories'],
        ['m' => 'POST', 'p' => '/admin/categories', 'c' => 'Category', 'a' => 'store', 's' => 'bo', 'anon' => false, 'perm' => 'category.manage', 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Catégories'],
        ['m' => 'GET', 'p' => '/admin/categories/{id}/edit', 'c' => 'Category', 'a' => 'edit', 's' => 'bo', 'anon' => false, 'perm' => 'category.manage', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Catégories'],
        ['m' => 'POST', 'p' => '/admin/categories/{id}', 'c' => 'Category', 'a' => 'update', 's' => 'bo', 'anon' => false, 'perm' => 'category.manage', 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Catégories'],
        ['m' => 'POST', 'p' => '/admin/categories/{id}/toggle', 'c' => 'Category', 'a' => 'toggle', 's' => 'bo', 'anon' => false, 'perm' => 'category.manage', 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Catégories'],
        ['m' => 'POST', 'p' => '/admin/categories/{id}/move', 'c' => 'Category', 'a' => 'move', 's' => 'bo', 'anon' => false, 'perm' => 'category.manage', 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Catégories'],
        ['m' => 'GET', 'p' => '/admin/profile/pin', 'c' => 'Profile', 'a' => 'showPin', 's' => 'bo', 'anon' => false, 'perm' => null, 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Connexion et compte'],
        ['m' => 'POST', 'p' => '/admin/profile/pin', 'c' => 'Profile', 'a' => 'updatePin', 's' => 'bo', 'anon' => false, 'perm' => null, 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Connexion et compte', 're' => 'password'],
        ['m' => 'GET', 'p' => '/admin/privacy', 'c' => 'Privacy', 'a' => 'index', 's' => 'bo', 'anon' => false, 'perm' => null, 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Connexion et compte'],
        ['m' => 'GET', 'p' => '/admin/products', 'c' => 'Product', 'a' => 'index', 's' => 'bo', 'anon' => false, 'perm' => 'product.read', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Produits et recettes'],
        ['m' => 'GET', 'p' => '/admin/products/by-category', 'c' => 'Product', 'a' => 'byCategory', 's' => 'bo', 'anon' => false, 'perm' => 'product.read', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Produits et recettes'],
        ['m' => 'GET', 'p' => '/admin/products/new', 'c' => 'Product', 'a' => 'create', 's' => 'bo', 'anon' => false, 'perm' => 'product.create', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Produits et recettes'],
        ['m' => 'POST', 'p' => '/admin/products', 'c' => 'Product', 'a' => 'store', 's' => 'bo', 'anon' => false, 'perm' => 'product.create', 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Produits et recettes'],
        ['m' => 'GET', 'p' => '/admin/products/{id}/edit', 'c' => 'Product', 'a' => 'edit', 's' => 'bo', 'anon' => false, 'perm' => 'product.update', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Produits et recettes'],
        ['m' => 'POST', 'p' => '/admin/products/{id}', 'c' => 'Product', 'a' => 'update', 's' => 'bo', 'anon' => false, 'perm' => 'product.update', 'w' => true, 'csrf' => 'form', 'pin' => 'price', 'f' => 'redirect', 'g' => 'Produits et recettes'],
        ['m' => 'GET', 'p' => '/admin/products/{id}/delete', 'c' => 'Product', 'a' => 'confirmDelete', 's' => 'bo', 'anon' => false, 'perm' => 'product.delete', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Produits et recettes'],
        ['m' => 'POST', 'p' => '/admin/products/{id}/delete', 'c' => 'Product', 'a' => 'destroy', 's' => 'bo', 'anon' => false, 'perm' => 'product.delete', 'w' => true, 'csrf' => 'form', 'pin' => 'always', 'f' => 'redirect', 'g' => 'Produits et recettes'],
        ['m' => 'POST', 'p' => '/admin/products/{id}/move', 'c' => 'Product', 'a' => 'move', 's' => 'bo', 'anon' => false, 'perm' => 'product.update', 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Produits et recettes'],
        ['m' => 'GET', 'p' => '/admin/products/{id}/recipe', 'c' => 'Product', 'a' => 'recipeForm', 's' => 'bo', 'anon' => false, 'perm' => 'ingredient.manage', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Produits et recettes'],
        ['m' => 'POST', 'p' => '/admin/products/{id}/recipe', 'c' => 'Product', 'a' => 'saveRecipe', 's' => 'bo', 'anon' => false, 'perm' => 'ingredient.manage', 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Produits et recettes'],
        ['m' => 'GET', 'p' => '/admin/products/import', 'c' => 'Product', 'a' => 'importForm', 's' => 'bo', 'anon' => false, 'perm' => 'product.create', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Produits et recettes'],
        ['m' => 'GET', 'p' => '/admin/products/import/template', 'c' => 'Product', 'a' => 'importTemplate', 's' => 'bo', 'anon' => false, 'perm' => 'product.create', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Produits et recettes'],
        ['m' => 'POST', 'p' => '/admin/products/import/preview', 'c' => 'Product', 'a' => 'importPreview', 's' => 'bo', 'anon' => false, 'perm' => 'product.create', 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Produits et recettes'],
        ['m' => 'POST', 'p' => '/admin/products/import/confirm', 'c' => 'Product', 'a' => 'importConfirm', 's' => 'bo', 'anon' => false, 'perm' => 'product.create', 'w' => true, 'csrf' => 'form', 'pin' => 'price', 'f' => 'redirect', 'g' => 'Produits et recettes'],
        ['m' => 'GET', 'p' => '/admin/menus', 'c' => 'Menu', 'a' => 'index', 's' => 'bo', 'anon' => false, 'perm' => 'menu.read', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Menus'],
        ['m' => 'GET', 'p' => '/admin/menus/new', 'c' => 'Menu', 'a' => 'create', 's' => 'bo', 'anon' => false, 'perm' => 'menu.create', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Menus'],
        ['m' => 'POST', 'p' => '/admin/menus', 'c' => 'Menu', 'a' => 'store', 's' => 'bo', 'anon' => false, 'perm' => 'menu.create', 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Menus'],
        ['m' => 'GET', 'p' => '/admin/menus/{id}/edit', 'c' => 'Menu', 'a' => 'edit', 's' => 'bo', 'anon' => false, 'perm' => 'menu.update', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Menus'],
        ['m' => 'POST', 'p' => '/admin/menus/{id}', 'c' => 'Menu', 'a' => 'update', 's' => 'bo', 'anon' => false, 'perm' => 'menu.update', 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Menus'],
        ['m' => 'POST', 'p' => '/admin/menus/{id}/toggle', 'c' => 'Menu', 'a' => 'toggle', 's' => 'bo', 'anon' => false, 'perm' => 'menu.update', 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Menus'],
        ['m' => 'GET', 'p' => '/admin/menus/{id}/delete', 'c' => 'Menu', 'a' => 'confirmDelete', 's' => 'bo', 'anon' => false, 'perm' => 'menu.delete', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Menus'],
        ['m' => 'POST', 'p' => '/admin/menus/{id}/delete', 'c' => 'Menu', 'a' => 'destroy', 's' => 'bo', 'anon' => false, 'perm' => 'menu.delete', 'w' => true, 'csrf' => 'form', 'pin' => 'always', 'f' => 'redirect', 'g' => 'Menus'],
        ['m' => 'GET', 'p' => '/admin/ingredients', 'c' => 'Ingredient', 'a' => 'index', 's' => 'bo', 'anon' => false, 'perm' => 'stock.read', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Ingrédients et stock'],
        ['m' => 'GET', 'p' => '/admin/ingredients/new', 'c' => 'Ingredient', 'a' => 'create', 's' => 'bo', 'anon' => false, 'perm' => 'ingredient.manage', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Ingrédients et stock'],
        ['m' => 'POST', 'p' => '/admin/ingredients', 'c' => 'Ingredient', 'a' => 'store', 's' => 'bo', 'anon' => false, 'perm' => 'ingredient.manage', 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Ingrédients et stock'],
        ['m' => 'GET', 'p' => '/admin/ingredients/{id}/edit', 'c' => 'Ingredient', 'a' => 'edit', 's' => 'bo', 'anon' => false, 'perm' => 'ingredient.manage', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Ingrédients et stock'],
        ['m' => 'POST', 'p' => '/admin/ingredients/{id}', 'c' => 'Ingredient', 'a' => 'update', 's' => 'bo', 'anon' => false, 'perm' => 'ingredient.manage', 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Ingrédients et stock'],
        ['m' => 'POST', 'p' => '/admin/ingredients/{id}/toggle', 'c' => 'Ingredient', 'a' => 'toggle', 's' => 'bo', 'anon' => false, 'perm' => 'ingredient.manage', 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Ingrédients et stock'],
        ['m' => 'GET', 'p' => '/admin/ingredients/{id}/delete', 'c' => 'Ingredient', 'a' => 'confirmDelete', 's' => 'bo', 'anon' => false, 'perm' => 'ingredient.manage', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Ingrédients et stock'],
        ['m' => 'POST', 'p' => '/admin/ingredients/{id}/delete', 'c' => 'Ingredient', 'a' => 'destroy', 's' => 'bo', 'anon' => false, 'perm' => 'ingredient.manage', 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Ingrédients et stock'],
        ['m' => 'GET', 'p' => '/admin/ingredients/{id}/restock', 'c' => 'Ingredient', 'a' => 'restockForm', 's' => 'bo', 'anon' => false, 'perm' => 'stock.manage', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Ingrédients et stock'],
        ['m' => 'POST', 'p' => '/admin/ingredients/{id}/restock', 'c' => 'Ingredient', 'a' => 'restock', 's' => 'bo', 'anon' => false, 'perm' => 'stock.manage', 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Ingrédients et stock'],
        ['m' => 'POST', 'p' => '/admin/ingredients/{id}/thresholds', 'c' => 'Ingredient', 'a' => 'updateThresholds', 's' => 'bo', 'anon' => false, 'perm' => 'stock.manage', 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Ingrédients et stock'],
        ['m' => 'GET', 'p' => '/admin/ingredients/{id}/inventory', 'c' => 'Ingredient', 'a' => 'inventoryForm', 's' => 'bo', 'anon' => false, 'perm' => 'stock.count', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Ingrédients et stock'],
        ['m' => 'POST', 'p' => '/admin/ingredients/{id}/inventory', 'c' => 'Ingredient', 'a' => 'inventory', 's' => 'bo', 'anon' => false, 'perm' => 'stock.count', 'w' => true, 'csrf' => 'form', 'pin' => 'always', 'f' => 'redirect', 'g' => 'Ingrédients et stock'],
        ['m' => 'GET', 'p' => '/admin/ingredients/{id}/adjust', 'c' => 'Ingredient', 'a' => 'adjustForm', 's' => 'bo', 'anon' => false, 'perm' => 'stock.count', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Ingrédients et stock'],
        ['m' => 'POST', 'p' => '/admin/ingredients/{id}/adjust', 'c' => 'Ingredient', 'a' => 'adjust', 's' => 'bo', 'anon' => false, 'perm' => 'stock.count', 'w' => true, 'csrf' => 'form', 'pin' => 'always', 'f' => 'redirect', 'g' => 'Ingrédients et stock'],
        ['m' => 'GET', 'p' => '/admin/ingredients/{id}/movements', 'c' => 'Ingredient', 'a' => 'movements', 's' => 'bo', 'anon' => false, 'perm' => 'stock.read', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'html', 'g' => 'Ingrédients et stock'],
        ['m' => 'POST', 'p' => '/admin/ingredients/{id}/enrich', 'c' => 'Ingredient', 'a' => 'enrich', 's' => 'bo', 'anon' => false, 'perm' => 'ingredient.manage', 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Ingrédients et stock'],
        ['m' => 'POST', 'p' => '/admin/ingredients/{id}/allergens', 'c' => 'Ingredient', 'a' => 'allergens', 's' => 'bo', 'anon' => false, 'perm' => 'ingredient.manage', 'w' => true, 'csrf' => 'form', 'pin' => null, 'f' => 'redirect', 'g' => 'Ingrédients et stock'],
        ['m' => 'POST', 'p' => '/admin/api/auth/login', 'c' => 'AuthApi', 'a' => 'apiLogin', 's' => 'api', 'anon' => true, 'perm' => null, 'w' => true, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Connexion et compte'],
        ['m' => 'POST', 'p' => '/admin/api/auth/logout', 'c' => 'AuthApi', 'a' => 'apiLogout', 's' => 'api', 'anon' => false, 'perm' => null, 'w' => true, 'csrf' => 'header', 'pin' => null, 'f' => 'json', 'g' => 'Connexion et compte'],
        ['m' => 'GET', 'p' => '/admin/api/auth/me', 'c' => 'AuthApi', 'a' => 'apiMe', 's' => 'api', 'anon' => false, 'perm' => null, 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Connexion et compte'],
        ['m' => 'GET', 'p' => '/admin/api/categories', 'c' => 'CategoryApi', 'a' => 'apiIndex', 's' => 'api', 'anon' => false, 'perm' => 'category.manage', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Catégories'],
        ['m' => 'GET', 'p' => '/admin/api/categories/{id}', 'c' => 'CategoryApi', 'a' => 'apiShow', 's' => 'api', 'anon' => false, 'perm' => 'category.manage', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Catégories'],
        ['m' => 'POST', 'p' => '/admin/api/categories', 'c' => 'CategoryApi', 'a' => 'apiStore', 's' => 'api', 'anon' => false, 'perm' => 'category.manage', 'w' => true, 'csrf' => 'header', 'pin' => null, 'f' => 'json', 'g' => 'Catégories'],
        ['m' => 'PUT', 'p' => '/admin/api/categories/{id}', 'c' => 'CategoryApi', 'a' => 'apiUpdate', 's' => 'api', 'anon' => false, 'perm' => 'category.manage', 'w' => true, 'csrf' => 'header', 'pin' => null, 'f' => 'json', 'g' => 'Catégories'],
        ['m' => 'DELETE', 'p' => '/admin/api/categories/{id}', 'c' => 'CategoryApi', 'a' => 'apiDestroy', 's' => 'api', 'anon' => false, 'perm' => 'category.manage', 'w' => true, 'csrf' => 'header', 'pin' => null, 'f' => 'json', 'g' => 'Catégories'],
        ['m' => 'POST', 'p' => '/admin/api/categories/{id}/toggle', 'c' => 'CategoryApi', 'a' => 'apiToggle', 's' => 'api', 'anon' => false, 'perm' => 'category.manage', 'w' => true, 'csrf' => 'header', 'pin' => null, 'f' => 'json', 'g' => 'Catégories'],
        ['m' => 'POST', 'p' => '/admin/api/categories/{id}/move', 'c' => 'CategoryApi', 'a' => 'apiMove', 's' => 'api', 'anon' => false, 'perm' => 'category.manage', 'w' => true, 'csrf' => 'header', 'pin' => null, 'f' => 'json', 'g' => 'Catégories'],
        ['m' => 'GET', 'p' => '/admin/api/products', 'c' => 'ProductApi', 'a' => 'apiIndex', 's' => 'api', 'anon' => false, 'perm' => 'product.read', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Produits et recettes'],
        ['m' => 'GET', 'p' => '/admin/api/products/{id}', 'c' => 'ProductApi', 'a' => 'apiShow', 's' => 'api', 'anon' => false, 'perm' => 'product.read', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Produits et recettes'],
        ['m' => 'POST', 'p' => '/admin/api/products', 'c' => 'ProductApi', 'a' => 'apiStore', 's' => 'api', 'anon' => false, 'perm' => 'product.create', 'w' => true, 'csrf' => 'header', 'pin' => null, 'f' => 'json', 'g' => 'Produits et recettes'],
        ['m' => 'PUT', 'p' => '/admin/api/products/{id}', 'c' => 'ProductApi', 'a' => 'apiUpdate', 's' => 'api', 'anon' => false, 'perm' => 'product.update', 'w' => true, 'csrf' => 'header', 'pin' => 'price', 'f' => 'json', 'g' => 'Produits et recettes'],
        ['m' => 'DELETE', 'p' => '/admin/api/products/{id}', 'c' => 'ProductApi', 'a' => 'apiDestroy', 's' => 'api', 'anon' => false, 'perm' => 'product.delete', 'w' => true, 'csrf' => 'header', 'pin' => 'always', 'f' => 'json', 'g' => 'Produits et recettes'],
        ['m' => 'POST', 'p' => '/admin/api/products/{id}/move', 'c' => 'ProductApi', 'a' => 'apiMove', 's' => 'api', 'anon' => false, 'perm' => 'product.update', 'w' => true, 'csrf' => 'header', 'pin' => null, 'f' => 'json', 'g' => 'Produits et recettes'],
        ['m' => 'GET', 'p' => '/admin/api/products/{id}/recipe', 'c' => 'ProductApi', 'a' => 'apiRecipeShow', 's' => 'api', 'anon' => false, 'perm' => 'ingredient.manage', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Produits et recettes'],
        ['m' => 'PUT', 'p' => '/admin/api/products/{id}/recipe', 'c' => 'ProductApi', 'a' => 'apiRecipeSave', 's' => 'api', 'anon' => false, 'perm' => 'ingredient.manage', 'w' => true, 'csrf' => 'header', 'pin' => null, 'f' => 'json', 'g' => 'Produits et recettes'],
        ['m' => 'GET', 'p' => '/admin/api/products/import/template', 'c' => 'ProductApi', 'a' => 'apiImportTemplate', 's' => 'api', 'anon' => false, 'perm' => 'product.create', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Produits et recettes'],
        ['m' => 'POST', 'p' => '/admin/api/products/import', 'c' => 'ProductApi', 'a' => 'apiImportRun', 's' => 'api', 'anon' => false, 'perm' => 'product.create', 'w' => true, 'csrf' => 'header', 'pin' => 'price', 'f' => 'json', 'g' => 'Produits et recettes'],
        ['m' => 'GET', 'p' => '/admin/api/menus', 'c' => 'MenuApi', 'a' => 'apiIndex', 's' => 'api', 'anon' => false, 'perm' => 'menu.read', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Menus'],
        ['m' => 'GET', 'p' => '/admin/api/menus/{id}', 'c' => 'MenuApi', 'a' => 'apiShow', 's' => 'api', 'anon' => false, 'perm' => 'menu.read', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Menus'],
        ['m' => 'POST', 'p' => '/admin/api/menus', 'c' => 'MenuApi', 'a' => 'apiStore', 's' => 'api', 'anon' => false, 'perm' => 'menu.create', 'w' => true, 'csrf' => 'header', 'pin' => null, 'f' => 'json', 'g' => 'Menus'],
        ['m' => 'PUT', 'p' => '/admin/api/menus/{id}', 'c' => 'MenuApi', 'a' => 'apiUpdate', 's' => 'api', 'anon' => false, 'perm' => 'menu.update', 'w' => true, 'csrf' => 'header', 'pin' => null, 'f' => 'json', 'g' => 'Menus'],
        ['m' => 'DELETE', 'p' => '/admin/api/menus/{id}', 'c' => 'MenuApi', 'a' => 'apiDestroy', 's' => 'api', 'anon' => false, 'perm' => 'menu.delete', 'w' => true, 'csrf' => 'header', 'pin' => 'always', 'f' => 'json', 'g' => 'Menus'],
        ['m' => 'POST', 'p' => '/admin/api/menus/{id}/toggle', 'c' => 'MenuApi', 'a' => 'apiToggle', 's' => 'api', 'anon' => false, 'perm' => 'menu.update', 'w' => true, 'csrf' => 'header', 'pin' => null, 'f' => 'json', 'g' => 'Menus'],
        ['m' => 'GET', 'p' => '/admin/api/ingredients', 'c' => 'IngredientApi', 'a' => 'apiIndex', 's' => 'api', 'anon' => false, 'perm' => 'stock.read', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Ingrédients et stock'],
        ['m' => 'GET', 'p' => '/admin/api/ingredients/{id}', 'c' => 'IngredientApi', 'a' => 'apiShow', 's' => 'api', 'anon' => false, 'perm' => 'stock.read', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Ingrédients et stock'],
        ['m' => 'POST', 'p' => '/admin/api/ingredients', 'c' => 'IngredientApi', 'a' => 'apiStore', 's' => 'api', 'anon' => false, 'perm' => 'ingredient.manage', 'w' => true, 'csrf' => 'header', 'pin' => null, 'f' => 'json', 'g' => 'Ingrédients et stock'],
        ['m' => 'PUT', 'p' => '/admin/api/ingredients/{id}', 'c' => 'IngredientApi', 'a' => 'apiUpdate', 's' => 'api', 'anon' => false, 'perm' => 'ingredient.manage', 'w' => true, 'csrf' => 'header', 'pin' => null, 'f' => 'json', 'g' => 'Ingrédients et stock'],
        ['m' => 'DELETE', 'p' => '/admin/api/ingredients/{id}', 'c' => 'IngredientApi', 'a' => 'apiDestroy', 's' => 'api', 'anon' => false, 'perm' => 'ingredient.manage', 'w' => true, 'csrf' => 'header', 'pin' => null, 'f' => 'json', 'g' => 'Ingrédients et stock'],
        ['m' => 'POST', 'p' => '/admin/api/ingredients/{id}/restock', 'c' => 'IngredientApi', 'a' => 'apiRestock', 's' => 'api', 'anon' => false, 'perm' => 'stock.manage', 'w' => true, 'csrf' => 'header', 'pin' => null, 'f' => 'json', 'g' => 'Ingrédients et stock'],
        ['m' => 'POST', 'p' => '/admin/api/ingredients/{id}/toggle', 'c' => 'IngredientApi', 'a' => 'apiToggle', 's' => 'api', 'anon' => false, 'perm' => 'ingredient.manage', 'w' => true, 'csrf' => 'header', 'pin' => null, 'f' => 'json', 'g' => 'Ingrédients et stock'],
        ['m' => 'PUT', 'p' => '/admin/api/ingredients/{id}/thresholds', 'c' => 'IngredientApi', 'a' => 'apiThresholds', 's' => 'api', 'anon' => false, 'perm' => 'stock.manage', 'w' => true, 'csrf' => 'header', 'pin' => null, 'f' => 'json', 'g' => 'Ingrédients et stock'],
        ['m' => 'POST', 'p' => '/admin/api/ingredients/{id}/inventory', 'c' => 'IngredientApi', 'a' => 'apiInventory', 's' => 'api', 'anon' => false, 'perm' => 'stock.count', 'w' => true, 'csrf' => 'header', 'pin' => 'always', 'f' => 'json', 'g' => 'Ingrédients et stock'],
        ['m' => 'POST', 'p' => '/admin/api/ingredients/{id}/adjust', 'c' => 'IngredientApi', 'a' => 'apiAdjust', 's' => 'api', 'anon' => false, 'perm' => 'stock.count', 'w' => true, 'csrf' => 'header', 'pin' => 'always', 'f' => 'json', 'g' => 'Ingrédients et stock'],
        ['m' => 'PUT', 'p' => '/admin/api/ingredients/{id}/allergens', 'c' => 'IngredientApi', 'a' => 'apiAllergens', 's' => 'api', 'anon' => false, 'perm' => 'ingredient.manage', 'w' => true, 'csrf' => 'header', 'pin' => null, 'f' => 'json', 'g' => 'Ingrédients et stock'],
        ['m' => 'GET', 'p' => '/admin/api/ingredients/{id}/movements', 'c' => 'IngredientApi', 'a' => 'apiMovements', 's' => 'api', 'anon' => false, 'perm' => 'stock.read', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Ingrédients et stock'],
        ['m' => 'GET', 'p' => '/admin/api/users', 'c' => 'UserApi', 'a' => 'apiIndex', 's' => 'api', 'anon' => false, 'perm' => 'user.read', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Comptes'],
        ['m' => 'GET', 'p' => '/admin/api/users/{id}', 'c' => 'UserApi', 'a' => 'apiShow', 's' => 'api', 'anon' => false, 'perm' => 'user.read', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Comptes'],
        ['m' => 'POST', 'p' => '/admin/api/users', 'c' => 'UserApi', 'a' => 'apiStore', 's' => 'api', 'anon' => false, 'perm' => 'user.create', 'w' => true, 'csrf' => 'header', 'pin' => 'always', 'f' => 'json', 'g' => 'Comptes'],
        ['m' => 'PUT', 'p' => '/admin/api/users/{id}', 'c' => 'UserApi', 'a' => 'apiUpdate', 's' => 'api', 'anon' => false, 'perm' => 'user.update', 'w' => true, 'csrf' => 'header', 'pin' => 'always', 'f' => 'json', 'g' => 'Comptes'],
        ['m' => 'DELETE', 'p' => '/admin/api/users/{id}', 'c' => 'UserApi', 'a' => 'apiDestroy', 's' => 'api', 'anon' => false, 'perm' => 'user.deactivate', 'w' => true, 'csrf' => 'header', 'pin' => 'always', 'f' => 'json', 'g' => 'Comptes'],
        ['m' => 'POST', 'p' => '/admin/api/users/{id}/reset-pin', 'c' => 'UserApi', 'a' => 'apiResetPin', 's' => 'api', 'anon' => false, 'perm' => 'user.update', 'w' => true, 'csrf' => 'header', 'pin' => 'always', 'f' => 'json', 'g' => 'Comptes'],
        ['m' => 'POST', 'p' => '/admin/api/users/{id}/erase', 'c' => 'UserApi', 'a' => 'apiErase', 's' => 'api', 'anon' => false, 'perm' => 'user.update', 'w' => true, 'csrf' => 'header', 'pin' => 'always', 'f' => 'json', 'g' => 'Comptes'],
        ['m' => 'GET', 'p' => '/admin/api/roles', 'c' => 'RoleApi', 'a' => 'apiIndex', 's' => 'api', 'anon' => false, 'perm' => 'role.manage', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Rôles et permissions'],
        ['m' => 'GET', 'p' => '/admin/api/roles/{id}', 'c' => 'RoleApi', 'a' => 'apiShow', 's' => 'api', 'anon' => false, 'perm' => 'role.manage', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Rôles et permissions'],
        ['m' => 'POST', 'p' => '/admin/api/roles', 'c' => 'RoleApi', 'a' => 'apiStore', 's' => 'api', 'anon' => false, 'perm' => 'role.manage', 'w' => true, 'csrf' => 'header', 'pin' => 'always', 'f' => 'json', 'g' => 'Rôles et permissions'],
        ['m' => 'PUT', 'p' => '/admin/api/roles/{id}', 'c' => 'RoleApi', 'a' => 'apiUpdate', 's' => 'api', 'anon' => false, 'perm' => 'role.manage', 'w' => true, 'csrf' => 'header', 'pin' => 'always', 'f' => 'json', 'g' => 'Rôles et permissions'],
        ['m' => 'GET', 'p' => '/admin/api/orders', 'c' => 'OrderApi', 'a' => 'apiIndex', 's' => 'api', 'anon' => false, 'perm' => 'order.read', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Commandes'],
        ['m' => 'GET', 'p' => '/admin/api/orders/{number}', 'c' => 'OrderApi', 'a' => 'apiShow', 's' => 'api', 'anon' => false, 'perm' => 'order.read', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Commandes'],
        ['m' => 'POST', 'p' => '/admin/api/orders', 'c' => 'OrderApi', 'a' => 'apiStore', 's' => 'api', 'anon' => false, 'perm' => 'order.create', 'w' => true, 'csrf' => 'header', 'pin' => null, 'f' => 'json', 'g' => 'Commandes'],
        ['m' => 'POST', 'p' => '/admin/api/orders/{number}/ready', 'c' => 'OrderApi', 'a' => 'apiReady', 's' => 'api', 'anon' => false, 'perm' => 'order.read', 'w' => true, 'csrf' => 'header', 'pin' => null, 'f' => 'json', 'g' => 'Commandes'],
        ['m' => 'POST', 'p' => '/admin/api/orders/{number}/deliver', 'c' => 'OrderApi', 'a' => 'apiDeliver', 's' => 'api', 'anon' => false, 'perm' => 'order.deliver', 'w' => true, 'csrf' => 'header', 'pin' => null, 'f' => 'json', 'g' => 'Commandes'],
        ['m' => 'POST', 'p' => '/admin/api/orders/{number}/cancel', 'c' => 'OrderApi', 'a' => 'apiCancel', 's' => 'api', 'anon' => false, 'perm' => 'order.cancel', 'w' => true, 'csrf' => 'header', 'pin' => 'always', 'f' => 'json', 'g' => 'Commandes'],
        ['m' => 'GET', 'p' => '/admin/api/stats', 'c' => 'StatsApi', 'a' => 'apiIndex', 's' => 'api', 'anon' => false, 'perm' => 'stats.read', 'w' => false, 'csrf' => null, 'pin' => null, 'f' => 'json', 'g' => 'Pilotage'],
    ];

    /**
     * @return array<string, array<string, mixed>> signature "METHODE chemin" => ligne
     */
    private function rowsBySignature(): array
    {
        $bySignature = [];
        foreach (RouteMap::rows() as $row) {
            $bySignature[$row['m'] . ' ' . $row['p']] = $row;
        }

        return $bySignature;
    }

    public function testProducesExactly158Rows(): void
    {
        self::assertCount(158, RouteMap::rows());
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function referenceRowProvider(): array
    {
        $cases = [];
        foreach (self::REFERENCE_ROWS as $row) {
            $cases[$row['m'] . ' ' . $row['p']] = [$row];
        }

        return $cases;
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('referenceRowProvider')]
    public function testExistingRouteMatchesReferenceExactly(array $expected): void
    {
        $signature = $expected['m'] . ' ' . $expected['p'];
        $bySignature = $this->rowsBySignature();

        self::assertArrayHasKey($signature, $bySignature, "RouteMap::rows() ne contient pas $signature.");
        self::assertSame($expected, $bySignature[$signature], "RouteMap::rows() differe de routes-final.json pour $signature.");
    }

    public function testExistingRoutesPreserveTheReferenceOrder(): void
    {
        $expectedOrder = array_map(
            static fn (array $row): string => $row['m'] . ' ' . $row['p'],
            self::REFERENCE_ROWS,
        );

        $actualOrder = array_values(array_filter(
            array_map(static fn (array $row): string => $row['m'] . ' ' . $row['p'], RouteMap::rows()),
            static fn (string $sig): bool => !in_array($sig, ['GET /admin/health', 'GET /admin/api/health'], true),
        ));

        self::assertSame($expectedOrder, $actualOrder);
    }

    public function testNewHealthPageRowIsWellFormed(): void
    {
        $row = $this->rowsBySignature()['GET /admin/health'] ?? null;
        self::assertNotNull($row, 'GET /admin/health absent de RouteMap::rows().');

        self::assertSame([
            'm'    => 'GET',
            'p'    => '/admin/health',
            'c'    => 'HealthPage',
            'a'    => 'index',
            's'    => 'bo',
            'anon' => false,
            'perm' => 'role.manage',
            'w'    => false,
            'csrf' => null,
            'pin'  => null,
            'f'    => 'html',
            'g'    => 'Système',
        ], $row);
    }

    public function testNewHealthApiRowIsWellFormed(): void
    {
        $row = $this->rowsBySignature()['GET /admin/api/health'] ?? null;
        self::assertNotNull($row, 'GET /admin/api/health absent de RouteMap::rows().');

        self::assertSame([
            'm'    => 'GET',
            'p'    => '/admin/api/health',
            'c'    => 'HealthApi',
            'a'    => 'apiIndex',
            's'    => 'api',
            'anon' => false,
            'perm' => 'role.manage',
            'w'    => false,
            'csrf' => null,
            'pin'  => null,
            'f'    => 'json',
            'g'    => 'Système',
        ], $row);
    }
}
