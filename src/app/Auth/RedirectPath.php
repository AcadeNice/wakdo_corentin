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
     * (`\`, que certains navigateurs normalisent en `/`), sans caractere de
     * controle (repli d'en-tete HTTP ou construction cote client d'une valeur
     * qu'un navigateur ignorerait silencieusement), et sans deux-points juste
     * apres le premier segment (par exemple `/javascript:alert(1)`). Ce n'est PAS
     * une detection de schema d'URL au sens strict -- un schema ne commence jamais
     * par `/`, donc la PREMIERE regle de cette methode (`$path[0] !== '/'`, ci-dessous)
     * l'a deja exclu avant que ce test ne s'execute (2e revue adverse, D-6 mineur :
     * les versions precedentes de ce commentaire disaient encore « avant d'arriver
     * ici », ambigu puisque « ici » designe cette meme methode). Ce test rejette
     * plutot un deux-points impose tot dans le chemin, qu'un consommateur en aval
     * (proxy, navigateur ancien, bibliotheque cliente) pourrait reinterpreter comme
     * un separateur de schema malgre le `/` de tete -- par prudence, sans qu'un tel
     * contournement soit documente pour ce depot precis ; aucun chemin legitime de
     * ce depot n'a cette forme.
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
