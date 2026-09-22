<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Auth\SmtpTransport;
use RuntimeException;

/**
 * Transport SMTP double : rejoue des reponses serveur scriptees et enregistre les
 * ecritures du client, pour tester la logique du protocole sans reseau.
 */
final class FakeSmtpTransport implements SmtpTransport
{
    /** @var list<string> ce que le client a ecrit, dans l'ordre */
    public array $writes = [];

    public bool $cryptoEnabled = false;
    public bool $closed = false;
    public bool $opened = false;

    /** @var list<string> reponses a rendre, dans l'ordre des readReply() */
    private array $replies;

    /** @param list<string> $replies */
    public function __construct(array $replies)
    {
        $this->replies = $replies;
    }

    public function open(string $host, int $port, int $timeoutSeconds): void
    {
        $this->opened = true;
    }

    public function write(string $raw): void
    {
        $this->writes[] = $raw;
    }

    public function readReply(): string
    {
        if ($this->replies === []) {
            throw new RuntimeException('FakeSmtpTransport : plus de reponse scriptee');
        }

        return array_shift($this->replies);
    }

    public function enableCrypto(): void
    {
        $this->cryptoEnabled = true;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /** Concatene toutes les ecritures (pratique pour assertions sur le message). */
    public function written(): string
    {
        return implode('', $this->writes);
    }
}
