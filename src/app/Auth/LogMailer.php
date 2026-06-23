<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Implementation de developpement : aucune infra mail en P2, on journalise le
 * lien de reinitialisation (error_log -> logs du conteneur) pour pouvoir le
 * recuperer en dev. Le lien contient le token brut, qui n'est jamais persiste.
 */
final class LogMailer implements Mailer
{
    public function sendPasswordReset(string $email, string $resetUrl): void
    {
        error_log(sprintf('[wakdo][password-reset] %s -> %s', $email, $resetUrl));
    }
}
