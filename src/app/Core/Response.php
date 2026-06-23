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
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
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

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $this->body;
    }
}
