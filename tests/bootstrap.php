<?php

declare(strict_types=1);

/**
 * Amorce PHPUnit sans Composer : on charge l'autoloader manuel du Core puis on
 * l'enregistre, exactement comme le fait le front controller en production
 * (src/public/admin/index.php). Les tests resolvent ainsi App\... via PSR-4.
 */

require __DIR__ . '/../src/app/Core/Autoloader.php';

App\Core\Autoloader::register();
