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
 * `Response::send()` : `fastcgi_finish_request()` d'abord quand la SAPI la
 * fournit (le client recoit sa reponse et la connexion se ferme AVANT que la
 * file ne s'execute), sinon la file s'execute simplement en fin de requete
 * (repli le plus simple, cf. la note "sinon en fin de requete" du correctif).
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
}
