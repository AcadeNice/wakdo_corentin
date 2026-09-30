<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * File d'actions a executer APRES l'emission de la reponse HTTP (D-5,
 * contre-audit 30/09). Sert a fermer un canal par le temps : un travail
 * couteux et non necessaire au RENDU de la reponse (ex. l'envoi SMTP du lien de
 * reinitialisation de mot de passe, PasswordResetService::requestReset()) ne
 * doit jamais retarder cette reponse -- sans quoi la DUREE de la reponse
 * revele, par comparaison avec le chemin qui n'a rien a envoyer, l'existence
 * d'un compte.
 *
 * Le front controller (src/public/admin/index.php) vide la file JUSTE APRES
 * `Response::send()`, via `finishRequest()` -- voir cette methode pour l'ordre
 * exact (D-5.a : fermer la session AVANT de rendre la main au client, sans
 * quoi une 2e requete restait bloquee sur le verrou de fichier de la session
 * jusqu'a la fin du travail differe, rouvrant le canal par le temps que ce
 * fichier existe pour fermer).
 *
 * Etat STATIQUE PAR PROCESSUS : sous PHP-FPM, un processus ne traite qu'UNE
 * requete a la fois (pas de fuite entre requetes concurrentes) et repart d'une
 * file vide au tour suivant (`flush()` la vide inconditionnellement, qu'elle
 * ait ou non ete utilisee). Les tests appellent `reset()` en setUp/tearDown
 * pour la meme raison (isolation entre cas de test dans le meme process
 * PHPUnit).
 */
final class DeferredActions
{
    /** @var list<callable(): void> */
    private static array $queue = [];

    public static function push(callable $action): void
    {
        self::$queue[] = $action;
    }

    /**
     * Execute puis vide la file, dans l'ordre d'ajout. Une action en echec (ex.
     * SMTP injoignable) est journalisee mais n'empeche pas les suivantes de
     * s'executer -- la reponse HTTP est de toute facon deja partie, il n'y a
     * plus personne a qui remonter l'erreur.
     */
    public static function flush(): void
    {
        $actions = self::$queue;
        self::$queue = [];

        foreach ($actions as $action) {
            try {
                $action();
            } catch (Throwable $exception) {
                error_log('[wakdo][deferred] action failure: ' . $exception->getMessage());
            }
        }
    }

    /** Vide la file SANS l'executer (isolation entre tests). */
    public static function reset(): void
    {
        self::$queue = [];
    }

    /**
     * D-5.a (revue adverse, contre-audit 30/09) : orchestre la fin de requete
     * dans l'ORDRE qui ferme le canal par le temps. Avant ce correctif, le
     * front controller appelait `fastcgi_finish_request()` PUIS `flush()` avec
     * la session PHP encore ouverte : le gestionnaire de session par defaut
     * (fichiers, `docker/php-fpm/php.ini`) tient un verrou de fichier jusqu'a
     * `session_write_close()` -- jamais appele -- donc une SECONDE requete
     * portant le MEME cookie de session restait bloquee sur ce verrou jusqu'a
     * la fin de l'envoi SMTP differe (mesure : 2,31 s contre 0,00 s apres
     * `session_write_close()`). L'attaquant a deja le cookie (il en a besoin
     * pour le jeton CSRF du formulaire), donc cette 2e requete rouvrait le
     * canal par le temps que D-5 devait fermer.
     *
     * Ordre correct : (1) fermer la session -- SEULEMENT si une session est
     * active, l'appeler sans session emettrait un avertissement PHP pour rien
     * -- (2) rendre la main au client (`fastcgi_finish_request()`, si la SAPI
     * la fournit), (3) vider la file (le travail differe, ex. l'envoi SMTP).
     * Rien de ce qui suit ne doit reecrire en session : aucune des actions
     * differees enregistrees dans ce depot n'accede a `$_SESSION` (seule
     * PasswordResetService::requestReset() y met un mailer, qui ne touche
     * jamais la session).
     *
     * Les trois etapes sont injectables (seams) pour que l'ORDRE soit
     * verifiable en test sans dependre d'un vrai serveur PHP-FPM (la SAPI CLI
     * de PHPUnit ne fournit ni session active par defaut ni
     * `fastcgi_finish_request()`) ; par defaut, les vraies fonctions PHP.
     *
     * @param null|callable(): bool $sessionActive Vrai si une session PHP est active.
     * @param null|callable(): void $closeSession  Ferme la session (libere son verrou).
     * @param null|callable(): void $finishRequest Rend la main au client si la SAPI le permet.
     */
    public static function finishRequest(
        ?callable $sessionActive = null,
        ?callable $closeSession = null,
        ?callable $finishRequest = null,
    ): void {
        $sessionActive ??= static fn (): bool => session_status() === PHP_SESSION_ACTIVE;
        $closeSession ??= static function (): void {
            session_write_close();
        };
        $finishRequest ??= static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
        };

        if ($sessionActive()) {
            $closeSession();
        }

        $finishRequest();
        self::flush();
    }
}
