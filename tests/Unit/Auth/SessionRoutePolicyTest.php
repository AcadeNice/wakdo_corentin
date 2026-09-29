<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use App\Auth\SessionRoutePolicy;
use App\Health\RouteSecurity;

/**
 * Decision "faut-il une session" avant le dispatch (defaut releve : une session
 * etait ouverte pour TOUTE requete, y compris /api/* anonyme -- Set-Cookie
 * inutile sur l'hote borne). Verrouille deux choses : (1) les cas explicites
 * (kiosk anonyme, connexion JSON sans session prealable, authentification HTML
 * qui porte le CSRF) et (2) la COHERENCE avec App\Health\RouteSecurity::ENTRIES,
 * la table de reference des 158 routes -- toute route sans_compte = false doit
 * avoir besoin d'une session, sans qu'il faille recopier la table ici a la main.
 */
final class SessionRoutePolicyTest extends TestCase
{
    public function testRootDoesNotNeedSession(): void
    {
        self::assertFalse(SessionRoutePolicy::needsSession('/'));
    }

    public function testPublicKioskApiDoesNotNeedSession(): void
    {
        foreach (['/api/health', '/api/orders', '/api/orders/K1/pay', '/api/orders/K1', '/api/categories',
            '/api/products', '/api/products/1', '/api/menus', '/api/menus/1', '/api/allergens'] as $path) {
            self::assertFalse(SessionRoutePolicy::needsSession($path), $path);
        }
    }

    /**
     * "SANS session prealable" (ADR-0017, docblock de App\Core\routes.php) veut
     * dire SANS CONTROLE D'ACCES prealable (pas de guardApi(), pas de CSRF en
     * entree) -- PAS sans session du tout. Une connexion reussie est l'operation
     * qui CREE la session (AuthService::authenticate() : regeneration
     * d'identifiant + $_SESSION[...]) ; sans session PHP active au moment du
     * dispatch, rien de tout cela ne serait persiste (regression reelle,
     * relevee contre la pile jetable et corrigee dans ce lot).
     */
    public function testJsonLoginNeedsSessionToPersistTheNewlyCreatedIdentity(): void
    {
        self::assertTrue(SessionRoutePolicy::needsSession('/admin/api/auth/login'));
    }

    public function testJsonLogoutAndMeNeedSession(): void
    {
        self::assertTrue(SessionRoutePolicy::needsSession('/admin/api/auth/logout'));
        self::assertTrue(SessionRoutePolicy::needsSession('/admin/api/auth/me'));
    }

    public function testHtmlAuthRoutesNeedSessionForCsrf(): void
    {
        foreach (['/login', '/logout', '/forgot_password', '/reset_password'] as $path) {
            self::assertTrue(SessionRoutePolicy::needsSession($path), $path);
        }
    }

    public function testBackOfficePagesNeedSession(): void
    {
        foreach (['/admin/dashboard', '/admin/me', '/admin/health', '/admin/orders',
            '/kitchen/display', '/counter/orders', '/counter/orders/new',
            '/drive/orders', '/drive/orders/new'] as $path) {
            self::assertTrue(SessionRoutePolicy::needsSession($path), $path);
        }
    }

    public function testUnregisteredPathDoesNotNeedSession(): void
    {
        self::assertFalse(SessionRoutePolicy::needsSession('/does/not/exist'));
    }

    /**
     * Verrou anti-derive : chaque route de App\Health\RouteSecurity::ENTRIES
     * (colonne sans_compte, index 2) dont sans_compte = false DOIT avoir besoin
     * d'une session -- sinon une nouvelle route protegee ajoutee a routes.php
     * pourrait se retrouver, par oubli, sans session (donc sans identite
     * possible) sans qu'aucun test ne le releve.
     */
    public function testEveryAccountGatedRouteNeedsSession(): void
    {
        foreach (RouteSecurity::ENTRIES as [$method, $path, $sansCompte]) {
            if ($sansCompte === false) {
                self::assertTrue(
                    SessionRoutePolicy::needsSession($this->literalPath($path)),
                    sprintf('%s %s (sans_compte=false) doit avoir besoin d\'une session', $method, $path),
                );
            }
        }
    }

    /**
     * Remplace les segments {xxx} par une valeur litterale : SessionRoutePolicy
     * decide par prefixe/chemin, jamais par un segment variable, donc la valeur
     * exacte n'a pas d'importance pour ce test.
     */
    private function literalPath(string $path): string
    {
        return (string) preg_replace('/\{[^}]+\}/', '1', $path);
    }
}
