<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Auth\Mailer;

/**
 * Double de Mailer : capture les appels au lieu d'envoyer. Permet d'asserter
 * qu'un lien de reinitialisation a (ou n'a pas) ete emis et d'en inspecter l'URL.
 */
final class SpyMailer implements Mailer
{
    /** @var list<array{email: string, resetUrl: string}> */
    public array $sent = [];

    public function sendPasswordReset(string $email, string $resetUrl): void
    {
        $this->sent[] = ['email' => $email, 'resetUrl' => $resetUrl];
    }
}
