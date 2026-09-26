<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Auth\PasswordHasher;
use App\Auth\PasswordHasherInterface;
use App\Core\Config;

/**
 * Double de PasswordHasher : compte les appels a verifyDecoy() plutot que de
 * mesurer son cout CPU (instable en test unitaire). `PasswordHasher` est
 * `final` (ne peut pas etre sous-classe) : ce double DELEGUE a une instance
 * reelle pour hash()/verify() (comportement identique), et compte seulement
 * verifyDecoy().
 */
final class SpyPasswordHasher implements PasswordHasherInterface
{
    public int $verifyDecoyCalls = 0;

    /** @var list<string|null> */
    public array $verifyDecoyReferenceHashes = [];

    private readonly PasswordHasher $real;

    public function __construct(Config $config)
    {
        $this->real = new PasswordHasher($config);
    }

    public function hash(string $plain): string
    {
        return $this->real->hash($plain);
    }

    public function verify(string $plain, string $hash): bool
    {
        return $this->real->verify($plain, $hash);
    }

    public function verifyDecoy(string $plain, ?string $referenceHash = null): void
    {
        $this->verifyDecoyCalls++;
        $this->verifyDecoyReferenceHashes[] = $referenceHash;
        $this->real->verifyDecoy($plain, $referenceHash);
    }

    public function needsRehash(string $hash): bool
    {
        return $this->real->needsRehash($hash);
    }
}
