<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Resultat immuable d'une operation d'authentification (login ou confirmation
 * de reinitialisation). Le controleur mappe ce resultat vers une reponse HTTP
 * sans re-deriver les branches de securite.
 *
 * Le message d'echec par defaut est unique et generique (anti-enumeration) :
 * identifiants faux, compte inactif et throttle partagent le meme texte.
 */
final class AuthResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?int $userId,
        public readonly ?int $roleId,
        public readonly ?string $redirectTo,
        public readonly ?string $error,
    ) {
    }

    public static function success(int $userId, int $roleId, string $redirectTo): self
    {
        return new self(true, $userId, $roleId, $redirectTo, null);
    }

    public static function failure(string $error = 'Email ou mot de passe incorrect'): self
    {
        return new self(false, null, null, null, $error);
    }
}
