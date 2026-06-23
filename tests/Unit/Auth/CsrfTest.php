<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use App\Auth\Csrf;
use App\Auth\SessionManager;
use App\Core\Config;

/**
 * CSRF synchroniseur teste sur un SessionManager en mode test (sac memoire),
 * donc sans session PHP reelle ni effet de bord d'en-tete.
 */
final class CsrfTest extends TestCase
{
    private function session(): SessionManager
    {
        return new SessionManager(new Config(), true);
    }

    public function testTokenIsHighEntropyHex(): void
    {
        $token = Csrf::token($this->session());

        // 32 octets CSPRNG en hexadecimal => 64 caracteres.
        self::assertSame(64, strlen($token));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testTokenIsStableAcrossCalls(): void
    {
        $session = $this->session();

        self::assertSame(Csrf::token($session), Csrf::token($session));
    }

    public function testValidateAcceptsCorrectToken(): void
    {
        $session = $this->session();
        $token = Csrf::token($session);

        self::assertTrue(Csrf::validate($session, $token));
    }

    public function testValidateRejectsWrongOrEmptyToken(): void
    {
        $session = $this->session();
        Csrf::token($session);

        self::assertFalse(Csrf::validate($session, 'wrong'));
        self::assertFalse(Csrf::validate($session, ''));
        self::assertFalse(Csrf::validate($session, null));
    }

    public function testValidateFalseWhenNoTokenYet(): void
    {
        // Aucun token genere en session : meme une soumission non vide echoue.
        self::assertFalse(Csrf::validate($this->session(), 'anything'));
    }

    public function testRotateChangesTokenAndInvalidatesOld(): void
    {
        $session = $this->session();
        $old = Csrf::token($session);

        $new = Csrf::rotate($session);

        self::assertNotSame($old, $new);
        self::assertFalse(Csrf::validate($session, $old));
        self::assertTrue(Csrf::validate($session, $new));
    }
}
