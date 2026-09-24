<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use App\Auth\SmtpClient;
use App\Auth\SmtpMailer;
use App\Tests\Support\FakeSmtpTransport;

final class SmtpMailerTest extends TestCase
{
    /** @return list<string> sequence nominale de reponses serveur */
    private function happyReplies(): array
    {
        return [
            "220 ready\r\n", "250 ok\r\n", "220 go\r\n", "250 ok\r\n",
            "334 u\r\n", "334 p\r\n", "235 ok\r\n", "250 ok\r\n", "250 ok\r\n",
            "354 data\r\n", "250 queued\r\n", "221 bye\r\n",
        ];
    }

    private function mailer(FakeSmtpTransport $t): SmtpMailer
    {
        return new SmtpMailer(
            new SmtpClient($t),
            'smtp-relay.brevo.com',
            587,
            'login@smtp-brevo.com',
            'secret',
            'noreply@a3n.fr',
            'Wakdo',
        );
    }

    public function testBuildsAndSendsResetMessage(): void
    {
        $t = new FakeSmtpTransport($this->happyReplies());
        $this->mailer($t)->sendPasswordReset('client@example.fr', 'https://corentin-wakdo-admin.stark.a3n.fr/reset_password?token=abc');

        $sent = $t->written();
        self::assertStringContainsString('From: Wakdo <noreply@a3n.fr>', $sent);
        self::assertStringContainsString('To: <client@example.fr>', $sent);
        // Sujet accentue : encode-word RFC 2047 (base64 UTF-8), pas de texte brut.
        $expectedSubject = '=?UTF-8?B?' . base64_encode('Réinitialisation de votre mot de passe Wakdo') . '?=';
        self::assertStringContainsString('Subject: ' . $expectedSubject, $sent);
        self::assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $sent);
        // Corps en quoted-printable : sans extension 8BITMIME annoncee, les octets non ASCII
        // ne partent pas bruts. Une fois decode, le lien et les accents sont intacts.
        self::assertStringContainsString('Content-Transfer-Encoding: quoted-printable', $sent);
        $body = quoted_printable_decode(substr($sent, (int) strpos($sent, "\r\n\r\n")));
        self::assertStringContainsString('https://corentin-wakdo-admin.stark.a3n.fr/reset_password?token=abc', $body);
        self::assertStringContainsString('Une réinitialisation de mot de passe a été demandée', $body);
        self::assertSame(1, preg_match('/^[\x00-\x7F]*$/', $sent), 'message transmis en ASCII 7 bits');
        // L'enveloppe SMTP doit porter l'expediteur et le destinataire reels.
        self::assertStringContainsString('MAIL FROM:<noreply@a3n.fr>', $sent);
        self::assertStringContainsString('RCPT TO:<client@example.fr>', $sent);
    }

    public function testRejectsInvalidRecipient(): void
    {
        $t = new FakeSmtpTransport($this->happyReplies());
        $this->expectException(\RuntimeException::class);
        $this->mailer($t)->sendPasswordReset("victim@x.fr\r\nBcc: evil@x.com", 'https://x/reset?token=t');
    }

    public function testHeaderAndBodySeparatedByBlankLine(): void
    {
        $t = new FakeSmtpTransport($this->happyReplies());
        $this->mailer($t)->sendPasswordReset('c@e.fr', 'https://x/reset?token=t');

        // En-tetes et corps separes par une ligne vide (CRLF CRLF).
        self::assertStringContainsString("Content-Transfer-Encoding: quoted-printable\r\n\r\nBonjour,", $t->written());
    }
}
