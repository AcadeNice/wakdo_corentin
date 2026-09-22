<?php

declare(strict_types=1);

namespace App\Auth;

use RuntimeException;

/**
 * Mailer SMTP reel (relais authentifie type Brevo). Implemente l'interface Mailer
 * a la place de LogMailer quand le SMTP est configure (voir PasswordResetController).
 * Assemble un message texte/plain UTF-8 conforme puis delegue l'envoi a SmtpClient.
 */
final class SmtpMailer implements Mailer
{
    public function __construct(
        private readonly SmtpClient $client,
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $password,
        private readonly string $fromEmail,
        private readonly string $fromName,
    ) {
    }

    public function sendPasswordReset(string $email, string $resetUrl): void
    {
        // Garde destinataire : une adresse valide ne contient ni CRLF ni structure
        // d'injection (verrou en plus de la garde transport de SmtpClient).
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('SmtpMailer : adresse destinataire invalide');
        }

        $subject = 'Reinitialisation de votre mot de passe Wakdo';
        $body = "Bonjour,\r\n\r\n"
            . "Une reinitialisation de mot de passe a ete demandee pour ce compte.\r\n"
            . "Pour definir un nouveau mot de passe, ouvrez ce lien :\r\n\r\n"
            . $resetUrl . "\r\n\r\n"
            . "Ce lien expire rapidement. Si vous n'etes pas a l'origine de la demande, "
            . "ignorez cet email.\r\n";

        $message = $this->buildMessage($email, $subject, $body);

        $this->client->send(
            $this->host,
            $this->port,
            $this->user,
            $this->password,
            $this->fromEmail,
            $email,
            $message,
        );
    }

    /** Assemble en-tetes + corps en CRLF, avec dot-stuffing pour la phase DATA. */
    private function buildMessage(string $to, string $subject, string $body): string
    {
        $headers = [
            'From: ' . $this->encodeHeader($this->fromName) . ' <' . $this->fromEmail . '>',
            'To: <' . $to . '>',
            'Subject: ' . $this->encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        $raw = implode("\r\n", $headers) . "\r\n\r\n" . $this->normalizeEol($body);

        return $this->dotStuff($raw);
    }

    /** RFC 2047 (encoded-word base64) si la valeur sort de l'ASCII imprimable. */
    private function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /** Normalise toutes les fins de ligne en CRLF (LF ou CR isoles -> CRLF). */
    private function normalizeEol(string $text): string
    {
        return (string) preg_replace('/\r\n|\r|\n/', "\r\n", $text);
    }

    /** Double un point en debut de ligne (RFC 5321 transparency). */
    private function dotStuff(string $message): string
    {
        $lines = explode("\r\n", $message);
        foreach ($lines as $i => $line) {
            if (isset($line[0]) && $line[0] === '.') {
                $lines[$i] = '.' . $line;
            }
        }

        return implode("\r\n", $lines);
    }
}
