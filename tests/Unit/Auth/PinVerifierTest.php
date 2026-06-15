<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use App\Auth\PasswordHasher;
use App\Auth\PinVerifier;
use App\Core\Config;
use App\Tests\Support\FakeDatabase;

/**
 * Verification du PIN d'action sensible (RG-T13) avec un FakeDatabase et un vrai
 * PasswordHasher a cout reduit.
 */
final class PinVerifierTest extends TestCase
{
    /** @var list<string> */
    private array $touchedKeys = [];

    private FakeDatabase $db;
    private PasswordHasher $hasher;

    protected function setUp(): void
    {
        $this->setEnv('STAFF_PIN_MIN_LENGTH', '4');
        $this->setEnv('STAFF_PIN_MAX_LENGTH', '12');
        $this->setEnv('ARGON2_MEMORY_COST', '1024');
        $this->setEnv('ARGON2_TIME_COST', '1');
        $this->setEnv('ARGON2_THREADS', '1');

        $this->db = new FakeDatabase();
        $this->hasher = new PasswordHasher(new Config());
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

    private function verifier(): PinVerifier
    {
        return new PinVerifier($this->db, new Config(), $this->hasher);
    }

    public function testVerifyTrueWhenPinMatches(): void
    {
        $this->db->pinUserRow = ['pin_hash' => $this->hasher->hash('4729')];

        self::assertTrue($this->verifier()->verify(7, '4729'));
        // Garde RG-T13 : la lecture filtre bien is_active = 1 (retirer le predicat
        // ferait echouer ce cas via le routage durci du FakeDatabase).
        self::assertStringContainsString('is_active = 1', $this->db->reads[0]['sql']);
    }

    public function testVerifyFalseWhenPinWrong(): void
    {
        $this->db->pinUserRow = ['pin_hash' => $this->hasher->hash('4729')];

        self::assertFalse($this->verifier()->verify(7, '0000'));
    }

    public function testVerifyFalseWhenPinHashNull(): void
    {
        // PIN non defini sur le compte.
        $this->db->pinUserRow = ['pin_hash' => null];

        self::assertFalse($this->verifier()->verify(7, '4729'));
    }

    public function testVerifyFalseWhenUserAbsentOrInactive(): void
    {
        // La requete filtre is_active = 1 : un compte inactif/absent ne renvoie rien.
        $this->db->pinUserRow = null;

        self::assertFalse($this->verifier()->verify(7, '4729'));
    }

    public function testVerifyFalseWhenPinEmpty(): void
    {
        $this->db->pinUserRow = ['pin_hash' => $this->hasher->hash('4729')];

        self::assertFalse($this->verifier()->verify(7, ''));
    }

    public function testResolveActingUserReturnsIdentityWhenPinMatches(): void
    {
        $this->db->actingUserRow = ['id' => 7, 'role_id' => 4, 'pin_hash' => $this->hasher->hash('4729')];

        self::assertSame(['id' => 7, 'role_id' => 4], $this->verifier()->resolveActingUser('staff@wakdo.local', '4729'));
        // Garde RG-T13 : la resolution filtre is_active = 1 (retirer le predicat
        // ferait echouer ce cas, comme pour verify()).
        self::assertStringContainsString('is_active = 1', $this->db->reads[0]['sql']);
    }

    public function testResolveActingUserNullWhenPinWrong(): void
    {
        $this->db->actingUserRow = ['id' => 7, 'role_id' => 4, 'pin_hash' => $this->hasher->hash('4729')];

        self::assertNull($this->verifier()->resolveActingUser('staff@wakdo.local', '0000'));
    }

    public function testResolveActingUserNullWhenEmailUnknown(): void
    {
        $this->db->actingUserRow = null;

        self::assertNull($this->verifier()->resolveActingUser('ghost@wakdo.local', '4729'));
    }

    public function testResolveActingUserNullWhenInputEmpty(): void
    {
        self::assertNull($this->verifier()->resolveActingUser('', '4729'));
        self::assertNull($this->verifier()->resolveActingUser('staff@wakdo.local', ''));
    }

    public function testMeetsLengthPolicy(): void
    {
        $verifier = $this->verifier();

        // Sous le minimum / au minimum / dans les bornes.
        self::assertFalse($verifier->meetsLengthPolicy('123'));
        self::assertTrue($verifier->meetsLengthPolicy('1234'));
        self::assertTrue($verifier->meetsLengthPolicy('123456'));
        // Au max (12) accepte, au-dela refuse (RG-T18 borne haute).
        self::assertTrue($verifier->meetsLengthPolicy('123456789012'));
        self::assertFalse($verifier->meetsLengthPolicy('1234567890123'));
        // Charset : chiffres uniquement ; vide refuse.
        self::assertFalse($verifier->meetsLengthPolicy('abcd'));
        self::assertFalse($verifier->meetsLengthPolicy('12ab'));
        self::assertFalse($verifier->meetsLengthPolicy(''));
    }
}
