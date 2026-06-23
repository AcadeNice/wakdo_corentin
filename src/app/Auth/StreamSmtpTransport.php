<?php

declare(strict_types=1);

namespace App\Auth;

use RuntimeException;

/**
 * Transport SMTP reel sur socket TCP (stream_socket_client + STARTTLS). Aucune
 * dependance externe. Non teste unitairement (effet de bord reseau) : la logique
 * du protocole est couverte via SmtpClient + un transport double.
 */
final class StreamSmtpTransport implements SmtpTransport
{
    /** @var resource|null */
    private $stream = null;

    public function open(string $host, int $port, int $timeoutSeconds): void
    {
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
            $errno,
            $errstr,
            $timeoutSeconds,
        );

        if ($stream === false) {
            throw new RuntimeException(sprintf('SMTP : connexion echouee (%s)', $errstr));
        }

        stream_set_timeout($stream, $timeoutSeconds);
        $this->stream = $stream;
    }

    public function write(string $raw): void
    {
        fwrite($this->requireStream(), $raw);
    }

    public function readReply(): string
    {
        $stream = $this->requireStream();
        $data = '';
        $lines = 0;

        while (($line = fgets($stream, 515)) !== false) {
            $data .= $line;

            // Bornes anti-boucle sur reponse malformee (ni ligne finale, ni EOF).
            if (++$lines > 100 || strlen($data) > 65536) {
                break;
            }

            // Continuation UNIQUEMENT si '-' en 4e position ; toute autre ligne
            // (y compris trop courte) termine la reponse.
            if (!(strlen($line) >= 4 && $line[3] === '-')) {
                break;
            }
        }

        if ($data === '') {
            throw new RuntimeException('SMTP : aucune reponse du serveur');
        }

        return $data;
    }

    public function enableCrypto(): void
    {
        if (!stream_socket_enable_crypto($this->requireStream(), true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('SMTP : echec de la negociation TLS (STARTTLS)');
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }

    /** @return resource */
    private function requireStream()
    {
        if (!is_resource($this->stream)) {
            throw new RuntimeException('SMTP : transport non ouvert');
        }

        return $this->stream;
    }
}
