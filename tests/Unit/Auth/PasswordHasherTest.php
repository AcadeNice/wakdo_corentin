<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use App\Auth\PasswordHasher;
use App\Core\Config;

/**
 * Verifie le hash argon2id (cout pilote par l'environnement) et le leurre de
 * timing. Les couts sont volontairement abaisses ici pour garder la suite rapide.
 */
final class PasswordHasherTest extends TestCase
{
    /** @var list<string> */
    private array $touchedKeys = [];

    protected function setUp(): void
    {
        // Cout reduit : les tests ne valident pas la robustesse cryptographique
        // (couverte par les valeurs de prod) mais la mecanique hash/verify/cout.
        $this->setEnv('ARGON2_MEMORY_COST', '1024');
        $this->setEnv('ARGON2_TIME_COST', '1');
        $this->setEnv('ARGON2_THREADS', '1');
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

    private function hasher(): PasswordHasher
    {
        return new PasswordHasher(new Config());
    }

    public function testHashIsVerifiable(): void
    {
        $hasher = $this->hasher();
        $hash = $hasher->hash('WakdoAdmin2026!');

        self::assertTrue($hasher->verify('WakdoAdmin2026!', $hash));
    }

    public function testWrongPasswordIsRejected(): void
    {
        $hasher = $this->hasher();
        $hash = $hasher->hash('correct horse');

        self::assertFalse($hasher->verify('battery staple', $hash));
    }

    public function testHashUsesArgon2idAlgorithm(): void
    {
        $info = password_get_info($this->hasher()->hash('x'));

        self::assertSame('argon2id', $info['algoName']);
    }

    public function testHashEmbedsConfiguredCost(): void
    {
        $info = password_get_info($this->hasher()->hash('x'));

        self::assertSame(1024, $info['options']['memory_cost'] ?? null);
        self::assertSame(1, $info['options']['time_cost'] ?? null);
        self::assertSame(1, $info['options']['threads'] ?? null);
    }

    public function testVerifyDecoyRunsWithoutThrowing(): void
    {
        // Le leurre ne doit jamais lever ni valider un mot de passe : il ne sert
        // qu'a consommer un temps CPU comparable au chemin nominal.
        $this->expectNotToPerformAssertions();
        $this->hasher()->verifyDecoy('any-submitted-password');
    }
}
