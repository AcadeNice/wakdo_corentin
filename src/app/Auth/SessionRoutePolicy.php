<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Decide si une requete a besoin d'une session PHP (donc d'un cookie de
 * session) AVANT le dispatch (src/public/admin/index.php). Jusqu'a ce lot, le
 * front controller demarrait une session pour TOUTE requete, y compris l'API
 * kiosk publique et anonyme sous /api/* et la sonde /api/health : chaque appel
 * de la borne posait un Set-Cookie inutile et ouvrait un fichier de session
 * cote serveur, sans aucune elevation de droit (la session restait vide) mais
 * avec un stockage serveur qui grossit avec le trafic anonyme.
 *
 * Une session n'est desormais demarree que pour les routes qui LISENT ou
 * ECRIVENT la session : App\Auth\SessionGuard::check() (identite/permissions,
 * back-office HTML et JSON sous /admin), App\Auth\Csrf::token()/validate()
 * (jeton CSRF, y compris sur les routes d'authentification qui n'exigent
 * aucune permission -- /login, /logout, /forgot_password, /reset_password), ou
 * App\Auth\AuthService::authenticate() (RG-3/RG-4 : regeneration d'identifiant
 * + $_SESSION['user_id']/['role_id']/... a la connexion). CE DERNIER POINT
 * couvre AUSSI POST /admin/api/auth/login : le docblock de App\Core\routes.php
 * dit "connexion JSON SANS session prealable", ce qui signifie SANS controle
 * d'acces prealable (pas de guardApi(), pas de jeton CSRF a valider en entree,
 * cf. ADR-0017) -- PAS "sans session du tout". Une connexion reussie est
 * precisement l'operation qui CREE la session : sans session PHP active au
 * moment du dispatch, AuthService::authenticate() ecrirait dans un $_SESSION
 * jamais persiste (aucun Set-Cookie, aucun fichier de session), et la
 * connexion JSON semblerait reussir (200 + csrf_token) sans qu'aucun appel
 * suivant ne soit jamais authentifie -- ecart releve en relisant contre la
 * pile jetable (tests/e2e/run-security.sh) apres une premiere version de cette
 * classe qui excluait ce chemin par erreur.
 *
 * Prefixes plutot qu'une recopie exhaustive des 158 routes de
 * App\Core\routes.php : un nouveau chemin ajoute sous un prefixe deja garde
 * herite automatiquement de la bonne decision. Source de verite croisee avec
 * App\Health\RouteSecurity::ENTRIES (colonne sans_compte) : toute route dont
 * sans_compte vaut false a besoin d'une session, et les routes sans_compte
 * restantes (les 4 routes d'authentification HTML + la connexion JSON) en ont
 * besoin quand meme, pour porter le CSRF ou creer la session.
 */
final class SessionRoutePolicy
{
    /**
     * Chemins litteraux d'authentification HTML : aucune permission requise
     * (App\Health\RouteSecurity::ENTRIES, colonne sans_compte = true) mais
     * PORTENT le jeton CSRF (Csrf::token()/Csrf::validate()), donc lisent ou
     * ecrivent la session.
     *
     * @var list<string>
     */
    private const AUTH_PATHS = ['/login', '/logout', '/forgot_password', '/reset_password'];

    /**
     * Prefixes de pages back-office gardees par SessionGuard, ou de l'API JSON
     * d'administration (/admin/api/*, y compris /admin/api/auth/login : voir le
     * docblock de classe). /kitchen, /counter et /drive n'ont pas de prefixe
     * /admin mais sont tout de meme des pages back-office authentifiees
     * (App\Health\RouteSecurity::ENTRIES, sans_compte = false).
     *
     * @var list<string>
     */
    private const GUARDED_PREFIXES = ['/admin/', '/kitchen/', '/counter/', '/drive/'];

    public static function needsSession(string $path): bool
    {
        // API kiosk publique et anonyme (/api/orders, /api/products, /api/health...) :
        // ni identite ni CSRF, la creation de commande borne s'appuie sur
        // idempotency_key (RG-T19), pas sur une session.
        if (str_starts_with($path, '/api/')) {
            return false;
        }

        if (in_array($path, self::AUTH_PATHS, true)) {
            return true;
        }

        foreach (self::GUARDED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        // Racine ('/') et toute route non enregistree (404) : anonyme, pas de session.
        return false;
    }
}
