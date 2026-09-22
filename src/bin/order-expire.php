<?php

declare(strict_types=1);

/**
 * Wakdo - expiration des commandes restees en attente de paiement (mlt.md 13.6).
 *
 * Appele par le planificateur (docker/cron/crontab, 02h00). Le flux de commande fait
 * DEUX appels : creation puis encaissement. L'echec du second (reseau coupe, borne
 * redemarree, onglet ferme) laisse une commande inerte mais visible, comptee "en
 * attente" sur le tableau de bord. Ce balayage la ferme.
 *
 * POURQUOI du PHP et pas du SQL dans un script bash : c'est une transition de la machine
 * a etats de la commande, et les cinq autres vivent dans OrderRepository. L'ecrire en SQL
 * ici donnerait deux proprietaires a la meme machine a etats, et il faut aussi ecrire une
 * ligne d'audit avec un resume -- de la logique metier, pas une purge de retention comme
 * les deux autres taches planifiees. En prime, l'integration continue couvre le PHP
 * (PHPStan niveau 6 + PHPUnit sur base reelle) et ne couvre pas le bash.
 *
 * Hors des deux racines web (les hotes virtuels servent src/public/borne et
 * src/public/admin) : ce fichier n'est pas atteignable par HTTP.
 *
 * Variables d'env : DB_* (Config) + ORDER_PENDING_EXPIRY_MINUTES (defaut 60).
 * Codes de sortie : 0 = OK | 1 = echec (crond le signale).
 */

use App\Catalogue\MenuRepository;
use App\Catalogue\ProductRepository;
use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Database;
use App\Order\OrderRepository;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "order-expire: reserve a la ligne de commande.\n");
    exit(1);
}

// src/bin/order-expire.php : __DIR__ = src/bin ; remonter d'un niveau pour atteindre src/.
require dirname(__DIR__) . '/app/Core/Autoloader.php';
Autoloader::register();

/** Journalise sur la sortie d'erreur : docker logs remonte le flux du conteneur. */
$log = static function (string $message): void {
    fwrite(STDERR, '[order-expire ' . date('c') . '] ' . $message . "\n");
};

try {
    $config = new Config();
    date_default_timezone_set($config->timezone());

    $minutes = $config->int('ORDER_PENDING_EXPIRY_MINUTES', 60);

    $db = new Database($config);
    // Meme fabrique que OrderController::orders() : les deux collaborateurs ne servent
    // pas a l'expiration, mais le constructeur du depot les exige.
    $orders = new OrderRepository($db, new ProductRepository($db), new MenuRepository($db));

    $report = $orders->expireStalePending($minutes);

    $log(sprintf(
        'delai=%dmin examinees=%d expirees=%d ignorees=%d%s',
        $minutes,
        $report['examined'],
        $report['expired'],
        $report['skipped'],
        $report['order_numbers'] === [] ? '' : ' [' . implode(', ', $report['order_numbers']) . ']',
    ));

    exit(0);
} catch (Throwable $exception) {
    $log('ERROR: ' . $exception->getMessage());
    exit(1);
}
