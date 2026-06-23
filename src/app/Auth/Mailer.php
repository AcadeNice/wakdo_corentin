<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Seam d'envoi du lien de reinitialisation de mot de passe. Interface justifiee
 * (contrairement a un repository) car une implementation SMTP reelle est
 * explicitement prevue pour une phase ulterieure : elle se branchera ici sans
 * toucher PasswordResetService.
 */
interface Mailer
{
    public function sendPasswordReset(string $email, string $resetUrl): void;
}
