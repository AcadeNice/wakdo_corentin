<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use App\Controllers\HomeController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;

/**
 * La racine du FQDN admin n'est pas une page vitrine : elle renvoie vers la
 * connexion (RG-T02). Le redirect ne touche ni la session ni la BDD.
 */
final class HomeControllerTest extends TestCase
{
    public function testRootRedirectsToLogin(): void
    {
        $request = new Request('GET', '/', [], [], '', '203.0.113.5');
        $controller = new HomeController($request, new Config(), new Database(new Config()));

        $response = $controller->index();

        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('Location'));
        self::assertSame('', $response->body());
    }
}
