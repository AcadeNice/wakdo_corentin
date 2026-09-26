<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Decide ou partent les erreurs PHP : a l'ecran du visiteur, ou seulement dans le
 * journal du serveur.
 *
 * POURQUOI cette classe existe. L'image PHP-FPM embarque `display_errors = On`
 * (docker/php-fpm/php.ini), pratique en developpement. Le commentaire en tete de ce
 * fichier annoncait une surcharge par `docker-compose.prod.yml`, qui n'a jamais ete
 * ecrite : la pile de demonstration a donc tourne avec `display_errors=1`. Une erreur
 * PHP y affichait son texte au visiteur -- chemin de fichier, extrait de requete,
 * contexte d'appel -- c'est-a-dire de quoi cartographier l'application sans effort.
 *
 * Le reglage est desormais derive d'`APP_DEBUG` au demarrage, dans le code, donc il
 * suit le deploiement partout : aucun fichier de composition a ne pas oublier, aucun
 * reglage d'image a maintenir en double.
 *
 * Ce qui change entre les deux modes, c'est la DESTINATION, pas le perimetre : le
 * niveau de rapport reste E_ALL et le journal reste actif dans les deux cas. Cacher
 * une erreur au visiteur ne doit pas revenir a la perdre pour l'exploitant.
 *
 * `display_startup_errors` ne figure PAS dans cette politique, et ce n'est pas un
 * oubli : les erreurs de demarrage se produisent avant que PHP n'execute la moindre
 * ligne du point d'entree, donc aucun appel a `ini_set()` ne peut plus les masquer.
 * Le seul levier pour celles-la est le php.ini de l'image, ou il est desormais a
 * `Off` (docker/php-fpm/php.ini).
 */
final class ErrorDisplay
{
    /**
     * Les reglages PHP a poser pour un mode donne. Partie PURE : aucun effet de
     * bord, donc testable sans toucher aux reglages globaux du processus.
     *
     * @return array<string, string>
     */
    public static function policyFor(bool $debug): array
    {
        return [
            'display_errors'  => $debug ? '1' : '0',
            'log_errors'      => '1',
            'error_reporting' => (string) E_ALL,
        ];
    }

    /**
     * Applique la politique au processus courant. A appeler tot dans le point
     * d'entree, avant tout code susceptible d'echouer.
     */
    public static function apply(Config $config): void
    {
        foreach (self::policyFor($config->isDebug()) as $setting => $value) {
            ini_set($setting, $value);
        }
    }
}
