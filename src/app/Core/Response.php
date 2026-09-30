<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Reponse HTTP accumulee puis emise par send().
 *
 * Permet de construire entierement la reponse avant tout echo, ce qui rend
 * le front controller testable et evite les "headers already sent".
 */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    private string $body = '';

    public function __construct(private int $status = 200)
    {
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /**
     * En-tetes REELLEMENT emis par send() -- pas necessairement ceux poses
     * explicitement (cf. Referrer-Policy ci-dessous). $this->header($name)
     * reste le miroir de ce qu'un appelant a explicitement pose (utilise par
     * la majorite des tests existants) ; cette methode est le point que send()
     * utilise pour emettre, et celui que les tests de valeur par defaut lisent.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = $this->headers;

        // D-7.a (revue adverse, contre-audit 30/09) : valeur par defaut posee
        // ICI (cote PHP), pas par Apache -- le vhost admin (docker/apache/
        // vhost.conf) ne pose plus Referrer-Policy du tout. Plusieurs
        // mecanismes Apache pour l'exclure CONDITIONNELLEMENT sur la seule
        // route /reset_password ont ete essayes et ecartes, tous verifies en
        // conteneur jetable : `<Location>` ne matche jamais (la reecriture
        // interne change l'URI AVANT toute correspondance ulterieure) ;
        // `Header setifempty` ne detecte pas la valeur deja posee par le
        // backend et EN AJOUTE UNE SECONDE ; `Header ... env=` avec une
        // variable posee par `SetEnvIf Request_URI` ou par `RewriteRule
        // [E=...]` n'est jamais vue comme vraie par `Header` (alors que
        // `CustomLog`, sur la MEME variable, la voit correctement). Poser la
        // valeur par defaut ICI, et laisser un controleur la remplacer AVANT
        // send() (PasswordResetController::renderConfirm() -> "no-referrer"
        // pour /reset_password), evite tout cet interfacage Apache fragile.
        if (!isset($headers['Referrer-Policy'])) {
            $headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';
        }

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     */
    public static function make(string $body, int $status, array $headers): self
    {
        $response = new self($status);
        $response->body = $body;

        foreach ($headers as $name => $value) {
            $response->setHeader($name, $value);
        }

        return $response;
    }

    /**
     * @param array<string|int, mixed> $data
     */
    public function json(array $data, int $status = 200): self
    {
        $this->status = $status;
        $this->setHeader('Content-Type', 'application/json; charset=utf-8');
        $this->body = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this;
    }

    public function html(string $body, int $status = 200): self
    {
        $this->status = $status;
        $this->setHeader('Content-Type', 'text/html; charset=utf-8');
        $this->body = $body;

        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers() as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $this->body;
    }
}
