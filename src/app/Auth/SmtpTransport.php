<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Couche transport d'une session SMTP : abstrait le socket reel pour que la
 * logique du protocole (SmtpClient) soit testable sans reseau (double en test).
 */
interface SmtpTransport
{
    public function open(string $host, int $port, int $timeoutSeconds): void;

    /** Ecrit exactement $raw sur la connexion (CRLF inclus par l'appelant). */
    public function write(string $raw): void;

    /**
     * Lit une reponse SMTP complete. Gere le multiligne (RFC 5321 : les lignes
     * de continuation ont un '-' en 4e position, la derniere un espace).
     */
    public function readReply(): string;

    /** Bascule la connexion en TLS (apres STARTTLS). */
    public function enableCrypto(): void;

    public function close(): void;
}
