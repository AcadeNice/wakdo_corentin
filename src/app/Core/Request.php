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
     * @param array<string, array<string, mixed>>  $files
     * @param array<string, mixed>                 $post
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
        // Fichiers envoyes en multipart ($_FILES). Defaut vide pour la meme
        // raison que remoteAddr : les appels existants a 5 ou 6 arguments
        // restent valides sans retouche.
        private readonly array $files = [],
        // Champs POST tels que PHP les a NATIVEMENT parses ($_POST). Defaut vide
        // pour la meme raison que files/remoteAddr. Seule source possible pour un
        // corps multipart/form-data (voir formBody()) : contrairement a l'urlencode,
        // on ne peut pas re-parser ce format nous-memes depuis php://input.
        private readonly array $post = [],
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

        /** @var array<string, array<string, mixed>> $files */
        $files = $_FILES;

        /** @var array<string, mixed> $post */
        $post = $_POST;

        return new self(
            $method,
            $path,
            $query,
            self::extractHeaders(),
            (string) file_get_contents('php://input'),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            $files,
            $post,
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
     * Decode le corps d'un formulaire POST en map cle => valeur : urlencode
     * (parse_str sur php://input) OU multipart/form-data (formulaire avec upload
     * de fichier, ex. produit/categorie avec image).
     *
     * BUG (corrige ici) : cette methode ne reconnaissait QUE l'urlencode. Pour un
     * formulaire multipart -- CHAQUE formulaire produit/categorie, qui portent tous
     * les deux enctype="multipart/form-data" pour l'upload d'image -- elle
     * renvoyait TOUJOURS [], _csrf compris. Csrf::validate() echouait alors a
     * chaque soumission, image ou pas, grosse ou petite : "Requete invalide."
     * systematique sur la creation/modification de produit ou de categorie, avant
     * meme que la validation applicative ou la taille du fichier n'entrent en jeu.
     *
     * php://input n'est pas fiable a re-parser soi-meme pour du multipart (format
     * a limites, contrairement a l'urlencode) : $_POST, que PHP a deja NATIVEMENT
     * parse pour les DEUX types de contenu, est la seule source correcte ici.
     *
     * Renvoie [] si le content-type n'est ni multipart ni urlencode (JSON, absent
     * ...), pour laisser la validation metier decider (pas de fatale ici).
     *
     * @return array<string, string>
     */
    public function formBody(): array
    {
        $contentType = $this->header('content-type') ?? '';

        if (str_starts_with($contentType, 'multipart/form-data')) {
            return $this->scalarize($this->post);
        }

        if (!str_starts_with($contentType, 'application/x-www-form-urlencoded')) {
            return [];
        }

        parse_str($this->rawBody, $parsed);

        return $this->scalarize($parsed);
    }

    /**
     * Le corps de la requete a-t-il ete rejete par la limite post_max_size de
     * PHP ? Dans ce cas PHP vide $_POST ET $_FILES AVANT que le script ne
     * s'execute, sans lever d'exception applicative (seul un warning du moteur,
     * absent des journaux applicatifs) : sans detection explicite, la requete
     * ressemble juste a un formulaire "sans CSRF" et remonte le 403 generique
     * invalidCsrf(), qui ne dit rien a l'equipier sur la vraie cause (une image
     * trop lourde, le cas le plus frequent en pratique).
     *
     * Un POST de formulaire legitime, meme "vide" de contenu utile, pose au
     * moins le champ cache _csrf dans $_POST et/ou une entree $_FILES
     * (UPLOAD_ERR_NO_FILE pour un champ fichier laisse vide) : les DEUX vides en
     * meme temps, avec un Content-Length annonce non nul, ne peuvent donc venir
     * que de ce depassement.
     */
    public function bodyExceededPostMaxSize(): bool
    {
        if ($this->method !== 'POST' || $this->post !== [] || $this->files !== []) {
            return false;
        }

        $contentType = $this->header('content-type') ?? '';
        $isForm = str_starts_with($contentType, 'multipart/form-data')
            || str_starts_with($contentType, 'application/x-www-form-urlencoded');
        if (!$isForm) {
            return false;
        }

        return ((int) ($this->header('content-length') ?? '0')) > 0;
    }

    /**
     * Ne retient que les valeurs SCALAIRES d'un tableau associatif, converties
     * en chaine, pour tenir le contrat strict array<string, string> attendu par
     * formBody(). $_POST comme parse_str() peuvent produire des valeurs tableau
     * (ex. champ "tags[]") ; ce cas est neutralise ici plutot que de jeter.
     *
     * @param array<array-key, mixed> $parsed
     * @return array<string, string>
     */
    private function scalarize(array $parsed): array
    {
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
    /**
     * Un fichier envoye, par le nom du champ du formulaire.
     *
     * Renvoie null quand le champ est absent, ce qui laisse a l'appelant la
     * distinction entre "champ jamais soumis" et "champ soumis mais vide"
     * (ce deuxieme cas arrive avec le code UPLOAD_ERR_NO_FILE).
     *
     * @return array<string, mixed>|null
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) ? $file : null;
    }

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
