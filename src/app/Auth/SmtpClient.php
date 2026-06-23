<?php

declare(strict_types=1);

namespace App\Auth;

use RuntimeException;

/**
 * Client SMTP minimal (sans dependance) : ESMTP + STARTTLS + AUTH LOGIN, suffisant
 * pour un relais authentifie type Brevo. Conduit la conversation contre un
 * SmtpTransport injecte ; chaque etape verifie le code de reponse attendu et leve
 * en cas d'ecart. La construction du message est laissee a l'appelant (SmtpMailer).
 */
final class SmtpClient
{
    public function __construct(
        private readonly SmtpTransport $transport,
        private readonly string $heloName = 'wakdo',
    ) {
    }

    /**
     * Ouvre la session, s'authentifie, transmet un message deja assemble
     * (en-tetes + corps, lignes en CRLF, dot-stuffing applique) puis ferme.
     */
    public function send(
        string $host,
        int $port,
        string $user,
        string $password,
        string $from,
        string $to,
        string $message,
    ): void {
        // Defense en profondeur : un CRLF dans une adresse injecterait une commande
        // SMTP (RCPT supplementaire) ou un en-tete. On refuse avant toute connexion.
        $this->assertNoInjection($from, 'expediteur');
        $this->assertNoInjection($to, 'destinataire');

        $t = $this->transport;

        try {
            $t->open($host, $port, 15);
            $this->expect($t->readReply(), 220, 'greeting');

            $this->command('EHLO ' . $this->heloName, 250, 'EHLO');
            $this->command('STARTTLS', 220, 'STARTTLS');
            $t->enableCrypto();
            // Re-EHLO obligatoire apres bascule TLS (la session repart de zero).
            $this->command('EHLO ' . $this->heloName, 250, 'EHLO TLS');

            $this->command('AUTH LOGIN', 334, 'AUTH LOGIN');
            $this->command(base64_encode($user), 334, 'AUTH user');
            $this->command(base64_encode($password), 235, 'AUTH password');

            $this->command('MAIL FROM:<' . $from . '>', 250, 'MAIL FROM');
            $this->command('RCPT TO:<' . $to . '>', 250, 'RCPT TO');
            $this->command('DATA', 354, 'DATA');

            // Corps + terminateur "<CRLF>.<CRLF>".
            $t->write($message . "\r\n.\r\n");
            $this->expect($t->readReply(), 250, 'corps du message');

            $t->write("QUIT\r\n");
            // La fermeture (221) n'est pas bloquante : le message est deja accepte.
            $t->readReply();
        } finally {
            $t->close();
        }
    }

    private function command(string $line, int $expected, string $stage): void
    {
        $this->transport->write($line . "\r\n");
        $this->expect($this->transport->readReply(), $expected, $stage);
    }

    private function assertNoInjection(string $address, string $label): void
    {
        if (preg_match('/[\r\n]/', $address) === 1) {
            throw new RuntimeException(
                sprintf('SMTP : adresse %s invalide (saut de ligne interdit)', $label),
            );
        }
    }

    private function expect(string $reply, int $code, string $stage): void
    {
        $got = (int) substr(ltrim($reply), 0, 3);
        if ($got !== $code) {
            // On ne journalise pas le corps : il peut contenir le lien de reset.
            throw new RuntimeException(
                sprintf('SMTP %s : attendu %d, recu "%s"', $stage, $code, trim($reply)),
            );
        }
    }
}
