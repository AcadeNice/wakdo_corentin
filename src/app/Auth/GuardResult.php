<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Resultat immuable d'une verification de garde de session (RG-6 + RG-T02).
 * $reason documente la cause d'un rejet pour que le controleur appelant (P3)
 * decide de la suite (redirection login, message). Valeurs possibles :
 * 'no_session' | 'idle_timeout' | 'absolute_timeout' | 'inactive' | null (OK).
 */
final class GuardResult
{
    public function __construct(
        public readonly bool $authenticated,
        public readonly ?int $userId,
        public readonly ?int $roleId,
        public readonly ?string $reason,
    ) {
    }
}
