<?php

declare(strict_types=1);

/**
 * Amorce PHPUnit sans Composer : on charge l'autoloader manuel du Core puis on
 * l'enregistre, exactement comme le fait le front controller en production
 * (src/public/admin/index.php). Les tests resolvent ainsi App\... via PSR-4.
 */

require __DIR__ . '/../src/app/Core/Autoloader.php';

App\Core\Autoloader::register();

// Autoloader PSR-4 dedie aux classes de support de test (doubles, helpers) :
// App\Tests\... -> tests/... . Permet de partager un FakeDatabase entre suites
// sans le dupliquer dans chaque fichier de test.
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\Tests\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});
