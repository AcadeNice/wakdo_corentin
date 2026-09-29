<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use App\Auth\SessionGuard;
use App\Auth\SessionManager;
use App\Core\Config;
use App\Tests\Support\FakeDatabase;

/**
 * Garde de session (RG-6 + RG-T02) : presence d'identite, bornes idle/absolue,
 * re-verification is_active. Temps fige, session en mode test, is_active fake.
 */
final class SessionGuardTest extends TestCase
{
    private const NOW = 1_700_000_000;
    private const IDLE = 14400;     // 4h
    private const ABSOLUTE = 36000; // 10h

    /** @var list<string> */
    private array $touchedKeys = [];

    private FakeDatabase $db;
    private SessionManager $session;

    protected function setUp(): void
    {
        $this->setEnv('SESSION_LIFETIME_IDLE', (string) self::IDLE);
        $this->setEnv('SESSION_LIFETIME_ABSOLUTE', (string) self::ABSOLUTE);

        $this->db = new FakeDatabase();
        $this->session = new SessionManager(new Config(), true);
    }

    protected function tearDown(): void
    {
        foreach ($this->touchedKeys as $key) {
            putenv($key);
        }
        $this->touchedKeys = [];
    }

    private function setEnv(string $key, string $value): void
    {
        $this->touchedKeys[] = $key;
        putenv($key . '=' . $value);
    }

    private function guard(): SessionGuard
    {
        return new SessionGuard($this->session, $this->db, new Config());
    }

    private function seedSession(int $loggedInAt, int $lastActivity): void
    {
        $this->session->set('user_id', 7);
        $this->session->set('role_id', 3);
        $this->session->set('logged_in_at', $loggedInAt);
        $this->session->set('last_activity', $lastActivity);
    }

    public function testNoSessionIsRejected(): void
    {
        $result = $this->guard()->check(self::NOW);

        self::assertFalse($result->authenticated);
        self::assertSame('no_session', $result->reason);
    }

    public function testValidSessionWithinWindowsRefreshesActivity(): void
    {
        $this->seedSession(self::NOW - 100, self::NOW - 50);
        $this->db->guardUserRow = ['is_active' => 1, 'role_id' => 3, 'session_epoch' => 0];

        $result = $this->guard()->check(self::NOW);

        self::assertTrue($result->authenticated);
        self::assertSame(7, $result->userId);
        self::assertSame(3, $result->roleId);
        self::assertNull($result->reason);
        // Fenetre idle glissante : last_activity rafraichi a now.
        self::assertSame(self::NOW, $this->session->getInt('last_activity'));
    }

    /**
     * RG-T02 (relecture adverse) : un changement de role d'un compte connecte
     * doit s'appliquer DES LA PROCHAINE REQUETE, pas seulement a la prochaine
     * connexion. La session porte encore l'ancien role_id (pose une fois par
     * AuthService a la connexion) ; check() doit ignorer cette valeur et
     * renvoyer celle lue EN BASE dans la meme requete que is_active.
     */
    public function testRoleChangeAppliesImmediatelyFromDatabaseNotSession(): void
    {
        $this->seedSession(self::NOW - 100, self::NOW - 50); // pose role_id = 3 en session
        $this->db->guardUserRow = ['is_active' => 1, 'role_id' => 9, 'session_epoch' => 0];

        $result = $this->guard()->check(self::NOW);

        self::assertTrue($result->authenticated);
        self::assertSame(9, $result->roleId, 'le role doit venir de la base, pas de la session (retrograde/promu)');
    }

    /**
     * RG-T02 : une session ouverte AVANT une reinitialisation de mot de passe
     * doit etre invalidee. session_epoch est pose en session a la connexion
     * (valeur lue a cet instant) et compare a la valeur courante en base ; une
     * reinitialisation incremente cette derniere (PasswordResetService), ce qui
     * doit desormais rejeter toute session anterieure.
     */
    public function testSessionEpochMismatchInvalidatesSession(): void
    {
        $this->seedSession(self::NOW - 100, self::NOW - 50);
        $this->session->set('session_epoch', 0); // valeur lue a la connexion
        $this->db->guardUserRow = ['is_active' => 1, 'role_id' => 3, 'session_epoch' => 1]; // mot de passe reinitialise depuis

        $result = $this->guard()->check(self::NOW);

        self::assertFalse($result->authenticated);
        self::assertSame('password_changed', $result->reason);
    }

    /**
     * Une session ouverte AVANT que cette colonne existe (ou avant la toute
     * premiere connexion posterieure a ce lot) ne porte pas 'session_epoch' :
     * absence en session == epoch 0 (valeur par defaut en base), pas un rejet.
     */
    public function testMissingSessionEpochDefaultsToZeroAndIsNotRejected(): void
    {
        $this->seedSession(self::NOW - 100, self::NOW - 50); // ne pose pas 'session_epoch'
        $this->db->guardUserRow = ['is_active' => 1, 'role_id' => 3, 'session_epoch' => 0];

        $result = $this->guard()->check(self::NOW);

        self::assertTrue($result->authenticated);
    }

    public function testIdleTimeoutIsRejected(): void
    {
        $this->seedSession(self::NOW - 200, self::NOW - (self::IDLE + 1));
        $this->db->guardUserRow = ['is_active' => 1];

        $result = $this->guard()->check(self::NOW);

        self::assertFalse($result->authenticated);
        self::assertSame('idle_timeout', $result->reason);
    }

    public function testAbsoluteTimeoutIsRejected(): void
    {
        // Activite recente (idle OK) mais session ouverte depuis plus de 10h.
        $this->seedSession(self::NOW - (self::ABSOLUTE + 1), self::NOW - 10);
        $this->db->guardUserRow = ['is_active' => 1];

        $result = $this->guard()->check(self::NOW);

        self::assertFalse($result->authenticated);
        self::assertSame('absolute_timeout', $result->reason);
    }

    public function testInactiveUserIsRejected(): void
    {
        $this->seedSession(self::NOW - 100, self::NOW - 50);
        $this->db->guardUserRow = ['is_active' => 0];

        $result = $this->guard()->check(self::NOW);

        self::assertFalse($result->authenticated);
        self::assertSame('inactive', $result->reason);
    }
}
