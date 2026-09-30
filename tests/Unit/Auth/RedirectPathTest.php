<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use App\Auth\RedirectPath;

/**
 * D-6 : `role.default_route` ne doit jamais permettre une redirection ouverte
 * apres connexion. RedirectPath::isLocal() est le seul point de verite, reutilise
 * a la fois a la SAISIE (RoleController) et a la REDIRECTION (AuthService).
 */
final class RedirectPathTest extends TestCase
{
    public function testAcceptsOrdinaryLocalPaths(): void
    {
        self::assertTrue(RedirectPath::isLocal('/'));
        self::assertTrue(RedirectPath::isLocal('/admin/dashboard'));
        self::assertTrue(RedirectPath::isLocal('/kitchen/display'));
    }

    public function testRejectsProtocolRelativeUrl(): void
    {
        // `//evil.example` est une URL relative au protocole : le navigateur la
        // resout vers un AUTRE hote, meme sans schema explicite.
        self::assertFalse(RedirectPath::isLocal('//evil.example'));
        self::assertFalse(RedirectPath::isLocal('//evil.example/phish'));
    }

    public function testRejectsAbsoluteUrlWithScheme(): void
    {
        self::assertFalse(RedirectPath::isLocal('https://evil.example'));
        self::assertFalse(RedirectPath::isLocal('http://evil.example/x'));
        self::assertFalse(RedirectPath::isLocal('javascript:alert(1)'));
    }

    public function testRejectsBackslashVariant(): void
    {
        // Certains navigateurs normalisent `\` en `/` : `/\evil.example` peut
        // devenir `//evil.example` cote client.
        self::assertFalse(RedirectPath::isLocal('/\\evil.example'));
    }

    public function testRejectsControlCharacters(): void
    {
        self::assertFalse(RedirectPath::isLocal("/\tevil.example"));
        self::assertFalse(RedirectPath::isLocal("/admin\r\nSet-Cookie: x"));
    }

    public function testRejectsEmptyOrRelativeStrings(): void
    {
        self::assertFalse(RedirectPath::isLocal(''));
        self::assertFalse(RedirectPath::isLocal('admin/dashboard'));
    }

    public function testSanitizeReturnsPathWhenLocalElseFallback(): void
    {
        self::assertSame('/admin/dashboard', RedirectPath::sanitize('/admin/dashboard', '/'));
        self::assertSame('/', RedirectPath::sanitize('https://evil.example', '/'));
        self::assertSame('/', RedirectPath::sanitize(null, '/'));
        self::assertSame('/', RedirectPath::sanitize('//evil.example', '/'));
    }
}
