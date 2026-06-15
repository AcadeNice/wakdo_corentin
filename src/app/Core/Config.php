<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Acces type a la configuration, lue depuis les variables d'environnement.
 *
 * Pas de parsing .env : en conteneur le .env n'est pas monte, les valeurs
 * sont injectees par docker-compose / l'environnement (getenv).
 */
final class Config
{
    public function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);

        // getenv renvoie false si absent ; une chaine vide est traitee comme absente
        // car les variables d'env vides n'apportent pas d'information exploitable.
        if ($value === false || $value === '') {
            return $default;
        }

        return $value;
    }

    /**
     * Lit une valeur obligatoire ; echoue tot si la config est incomplete
     * plutot que de laisser une erreur survenir plus loin (fail-fast).
     */
    public function required(string $key): string
    {
        $value = $this->get($key);

        if ($value === null) {
            throw new RuntimeException(sprintf('Missing required configuration: %s', $key));
        }

        return $value;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return $value === null ? $default : (int) $value;
    }

    /**
     * Interprete les conventions usuelles de booleen textuel d'environnement.
     */
    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function appEnv(): string
    {
        return $this->get('APP_ENV', 'production') ?? 'production';
    }

    public function isDebug(): bool
    {
        return $this->bool('APP_DEBUG', false);
    }

    public function timezone(): string
    {
        return $this->get('APP_TIMEZONE', 'UTC') ?? 'UTC';
    }
}
