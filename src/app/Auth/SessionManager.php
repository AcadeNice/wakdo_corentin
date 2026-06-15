<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;

/**
 * Seul fichier autorise a toucher $_SESSION, les fonctions session_* et le
 * cookie de session. Tout le reste de l'auth opere sur cette facade injectee,
 * ce qui rend les services et le CSRF testables sans session reelle.
 *
 * En mode test (testMode = true), aucune session PHP n'est demarree : l'etat
 * vit dans un sac memoire. Indispensable car PHPUnit tourne avec
 * beStrictAboutOutputDuringTests : un session_start emettrait un en-tete et
 * ferait echouer la suite.
 */
final class SessionManager
{
    /** @var array<string, mixed> */
    private array $bag = [];

    public function __construct(
        private readonly Config $config,
        private readonly bool $testMode = false,
    ) {
    }

    /**
     * Demarre la session du vhost admin avec des cookies durcis. Idempotent :
     * le front controller peut l'avoir deja demarree avant le dispatch.
     */
    public function start(): void
    {
        if ($this->testMode) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Defense : ne pas tenter de poser le cookie si la sortie a commence.
        if (headers_sent()) {
            return;
        }

        // lifetime=0 : cookie de session ; les bornes idle 4h / absolue 10h sont
        // appliquees applicativement par SessionGuard (RG-6), pas par le cookie.
        // secure+httponly+SameSite=Strict : back-office, aucune entree cross-site.
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_name($this->config->get('SESSION_NAME', 'WAKDO_SID') ?? 'WAKDO_SID');
        session_start();
    }

    /**
     * Regenere l'identifiant de session (RG-3) : protege contre la fixation de
     * session apres une authentification reussie.
     */
    public function regenerate(): void
    {
        if ($this->testMode) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function get(string $key): mixed
    {
        if ($this->testMode) {
            return $this->bag[$key] ?? null;
        }

        return $_SESSION[$key] ?? null;
    }

    /**
     * Accesseur type : evite qu'une valeur mixed de session ne file dans un
     * parametre lie PDO ou un calcul d'entier (friction PHPStan L6).
     * Les identifiants et timestamps stockes sont des entiers positifs.
     */
    public function getInt(string $key): ?int
    {
        $value = $this->get($key);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    public function set(string $key, mixed $value): void
    {
        if ($this->testMode) {
            $this->bag[$key] = $value;

            return;
        }

        $_SESSION[$key] = $value;
    }

    /**
     * Efface les donnees de session (RG-1 de LOGOUT_USER).
     */
    public function clear(): void
    {
        if ($this->testMode) {
            $this->bag = [];

            return;
        }

        $_SESSION = [];
    }

    /**
     * Expire le cookie de session cote client puis detruit la session serveur
     * (RG-2 + RG-3 de LOGOUT_USER). Le cookie reprend les memes attributs durcis.
     */
    public function destroy(): void
    {
        if ($this->testMode) {
            $this->bag = [];

            return;
        }

        if (ini_get('session.use_cookies') !== false) {
            $name = session_name();
            if ($name !== false) {
                setcookie($name, '', [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'secure' => true,
                    'httponly' => true,
                    'samesite' => 'Strict',
                ]);
            }
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function id(): string
    {
        if ($this->testMode) {
            return 'test-session';
        }

        $id = session_id();

        return $id === false ? '' : $id;
    }
}
