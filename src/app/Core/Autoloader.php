<?php

declare(strict_types=1);

namespace App\Core;

/**
 * PSR-4 autoloader manuel, sans Composer (exigence "from scratch" Cr 4.c.3).
 *
 * Mappe le prefixe de namespace racine "App\" sur le dossier src/app/.
 * Exemple : App\Core\Router -> {src/app}/Core/Router.php
 */
final class Autoloader
{
    private const PREFIX = 'App\\';

    /**
     * Enregistre l'autoloader aupres de la pile SPL.
     *
     * La racine src/app/ est calculee depuis l'emplacement de ce fichier
     * (src/app/Core/Autoloader.php) : dirname(__DIR__) remonte de Core/ a src/app/.
     * Aucun chemin code en dur, donc portable host/conteneur.
     */
    public static function register(): void
    {
        $root = dirname(__DIR__);

        spl_autoload_register(static function (string $class) use ($root): void {
            if (!str_starts_with($class, self::PREFIX)) {
                return;
            }

            $relative = substr($class, strlen(self::PREFIX));
            $path = $root . DIRECTORY_SEPARATOR
                . str_replace('\\', DIRECTORY_SEPARATOR, $relative)
                . '.php';

            if (is_file($path)) {
                require $path;
            }
        });
    }
}
