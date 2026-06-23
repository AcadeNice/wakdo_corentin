<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Representation immuable de la requete HTTP entrante.
 *
 * Construite depuis les super-globales par fromGlobals() ; le reste de
 * l'application ne touche jamais $_SERVER / $_GET directement.
 */
final class Request
{
    /**
     * @param array<string, string>               $query
     * @param array<string, string>               $headers
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $headers,
        private readonly string $rawBody,
        // Adresse de la connexion TCP entrante (le proxy Traefik en frontal).
        // Defaut vide pour conserver la compatibilite des appels a 5 arguments
        // (tests existants). clientIp() s'en sert comme repli derriere X-Forwarded-For.
        private readonly string $remoteAddr = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // REQUEST_URI inclut la query string ; on isole le chemin seul.
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $path = self::normalizePath($path);

        /** @var array<string, string> $query */
        $query = $_GET;

        return new self(
            $method,
            $path,
            $query,
            self::extractHeaders(),
            (string) file_get_contents('php://input'),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        );
    }

    /**
     * Garde un slash de tete et retire le slash de fin (sauf racine) pour
     * que "/api/health/" et "/api/health" matchent la meme route.
     */
    private static function normalizePath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    /**
     * @return array<string, string>
     */
    private static function extractHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($name)] = (string) $value;
            }
        }

        // Content-Type / Content-Length ne sont pas prefixes HTTP_ par PHP.
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(string $key, ?string $default = null): ?string
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function allQuery(): array
    {
        return $this->query;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * Decode le corps JSON ; renvoie un tableau vide si le corps est vide ou
     * invalide, pour laisser la validation metier decider (pas de fatale ici).
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->rawBody === '') {
            return [];
        }

        $decoded = json_decode($this->rawBody, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Decode un corps application/x-www-form-urlencoded en map cle => valeur.
     * Symetrique de json() : renvoie [] si le content-type n'est pas un
     * formulaire urlencode, pour laisser la validation metier decider (pas de
     * fatale ici). Le back-office se connecte par formulaire POST, pas par JSON.
     *
     * @return array<string, string>
     */
    public function formBody(): array
    {
        $contentType = $this->header('content-type') ?? '';

        if (!str_starts_with($contentType, 'application/x-www-form-urlencoded')) {
            return [];
        }

        parse_str($this->rawBody, $parsed);

        // parse_str peut produire des valeurs tableau (cle[]=...) ; on ne retient
        // que les scalaires convertis en chaine pour tenir le contrat strict
        // array<string, string> (et neutraliser une cle de type "champ[]").
        $form = [];
        foreach ($parsed as $key => $value) {
            if (is_scalar($value)) {
                $form[(string) $key] = (string) $value;
            }
        }

        return $form;
    }

    /**
     * IP client reelle derriere le reverse proxy Traefik. REMOTE_ADDR est ici
     * toujours l'adresse du proxy, donc on lit X-Forwarded-For et on retient le
     * DERNIER hop : c'est celui ajoute par Traefik (proxy de confiance), tandis
     * que les entrees de gauche sont fournies par le client et donc falsifiables.
     * La valeur est validee par FILTER_VALIDATE_IP et bornee a 45 caracteres
     * (taille de login_throttle.ip_address). Repli sur REMOTE_ADDR si l'en-tete
     * est absent ou invalide ; sentinelle 0.0.0.0 en dernier recours.
     *
     * Hypothese de deploiement : un unique proxy de confiance (Traefik) est
     * toujours en frontal. Sans lui, X-Forwarded-For serait falsifiable ; le
     * verrou par compte (failed_login_attempts) reste alors le garde-fou.
     */
    public function clientIp(): string
    {
        $forwarded = $this->header('x-forwarded-for');

        if ($forwarded !== null && $forwarded !== '') {
            $hops = explode(',', $forwarded);
            $candidate = trim((string) end($hops));

            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return substr($candidate, 0, 45);
            }
        }

        if ($this->remoteAddr !== '' && filter_var($this->remoteAddr, FILTER_VALIDATE_IP) !== false) {
            return substr($this->remoteAddr, 0, 45);
        }

        return '0.0.0.0';
    }
}
