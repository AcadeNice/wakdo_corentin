<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Garde-fou anti-redirection-ouverte (D-6) : `role.default_route` sert de
 * redirection AUTOMATIQUE apres connexion (AuthService::authenticate()), donc une
 * valeur non locale ("https://...", "//...") enverrait tout un role hors du site
 * des la connexion. Point de verite UNIQUE, reutilise a deux endroits :
 *  - a la SAISIE (RoleController::validate()), pour refuser une valeur non locale
 *    avant qu'elle n'atteigne la base ;
 *  - a la REDIRECTION (AuthService::authenticate()), en defense en profondeur pour
 *    toute valeur deja en base avant ce correctif (ou posee par un autre chemin).
 */
final class RedirectPath
{
    private function __construct()
    {
        // Classe utilitaire : uniquement des methodes statiques.
    }

    /**
     * Chemin local sur : commence par UN SEUL `/` (jamais `//`, une URL relative au
     * protocole que le navigateur resout vers un AUTRE hote), sans antislash
     * (`\`, que certains navigateurs normalisent en `/`), sans schema (un schema
     * ne commence jamais par `/`, donc deja exclu par la regle precedente, verifie
     * explicitement ici pour rester correct si cette regle change un jour), et sans
     * caractere de controle (repli d'en-tete HTTP ou construction cote client
     * d'une valeur qu'un navigateur ignorerait silencieusement).
     */
    public static function isLocal(string $path): bool
    {
        if ($path === '' || $path[0] !== '/') {
            return false;
        }
        if (str_starts_with($path, '//')) {
            return false;
        }
        if (str_contains($path, '\\')) {
            return false;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return false;
        }
        if (preg_match('#^/[a-zA-Z][a-zA-Z0-9+.-]*:#', $path) === 1) {
            return false;
        }

        return true;
    }

    /**
     * $path s'il est un chemin local, sinon $fallback (defense en profondeur).
     */
    public static function sanitize(?string $path, string $fallback): string
    {
        return $path !== null && self::isLocal($path) ? $path : $fallback;
    }
}
