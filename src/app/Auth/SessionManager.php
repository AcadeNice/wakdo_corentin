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

    private int $regenerateCalls = 0;

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
        // secure (conditionnel HTTPS, cf. cookieSecure)+httponly+SameSite=Strict :
        // back-office, aucune entree cross-site.
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->cookieSecure(),
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
        // Compteur d'appels, tenu QUEL QUE SOIT le mode (test compris) : en mode
        // test, aucun effet de bord observable n'existe (pas de session PHP
        // reelle) pour prouver qu'AuthController::login()/AuthService::authenticate()
        // appelle bien regenerate() sur un succes (RG-3, anti-fixation) --
        // regenerateCallCount() est le seul espion possible sans sous-classer
        // cette classe `final`. Ne change rien au comportement de production :
        // la valeur n'y est jamais lue.
        $this->regenerateCalls++;

        if ($this->testMode) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Nombre d'appels a regenerate() depuis la construction de cette instance.
     * Espion de test (docblock de regenerate() ci-dessus) ; sans effet sur le
     * comportement de production.
     */
    public function regenerateCallCount(): int
    {
        return $this->regenerateCalls;
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
                    'secure' => $this->cookieSecure(),
                    'httponly' => true,
                    'samesite' => 'Strict',
                ]);
            }
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * Le cookie de session est marque Secure UNIQUEMENT sur une connexion HTTPS.
     * En HTTP (dev / standalone local) un cookie Secure serait rejete par le
     * navigateur et casserait la session. En prod, Traefik termine le TLS et
     * transmet X-Forwarded-Proto=https ; l'app n'etant joignable que par ce proxy
     * sur le reseau interne, cet en-tete est fiable ici.
     */
    private function cookieSecure(): bool
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (is_string($forwarded) && strtolower($forwarded) === 'https') {
            return true;
        }

        $https = $_SERVER['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        return ((int) ($_SERVER['SERVER_PORT'] ?? 0)) === 443;
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
