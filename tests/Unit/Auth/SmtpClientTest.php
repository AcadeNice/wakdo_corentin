<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use App\Auth\SmtpClient;
use App\Tests\Support\FakeSmtpTransport;
use RuntimeException;

final class SmtpClientTest extends TestCase
{
    /** @return list<string> sequence nominale de reponses serveur */
    private function happyReplies(): array
    {
        return [
            "220 smtp.brevo ready\r\n",   // greeting
            "250-smtp\r\n250 AUTH LOGIN\r\n", // EHLO (multiligne)
            "220 go ahead\r\n",           // STARTTLS
            "250 ok\r\n",                 // EHLO post-TLS
            "334 VXNlcm5hbWU6\r\n",       // AUTH LOGIN
            "334 UGFzc3dvcmQ6\r\n",       // user
            "235 authenticated\r\n",      // password
            "250 ok\r\n",                 // MAIL FROM
            "250 ok\r\n",                 // RCPT TO
            "354 end data with <CRLF>.<CRLF>\r\n", // DATA
            "250 queued\r\n",             // body
            "221 bye\r\n",                // QUIT
        ];
    }

    public function testNominalConversationAuthenticatesAndSends(): void
    {
        $t = new FakeSmtpTransport($this->happyReplies());
        $client = new SmtpClient($t);

        $client->send('smtp-relay.brevo.com', 587, 'user@x', 'secret', 'from@a.fr', 'to@b.fr', "Subject: hi\r\n\r\ncorps");

        self::assertTrue($t->opened);
        self::assertTrue($t->cryptoEnabled, 'STARTTLS doit basculer le transport en TLS');
        self::assertTrue($t->closed, 'le transport doit etre ferme');

        $sent = $t->written();
        self::assertStringContainsString("STARTTLS\r\n", $sent);
        self::assertStringContainsString("AUTH LOGIN\r\n", $sent);
        self::assertStringContainsString(base64_encode('user@x') . "\r\n", $sent);
        self::assertStringContainsString(base64_encode('secret') . "\r\n", $sent);
        self::assertStringContainsString("MAIL FROM:<from@a.fr>\r\n", $sent);
        self::assertStringContainsString("RCPT TO:<to@b.fr>\r\n", $sent);
        self::assertStringContainsString("DATA\r\n", $sent);
        self::assertStringContainsString("\r\n.\r\n", $sent, 'le corps doit finir par le terminateur DATA');
        self::assertStringContainsString("QUIT\r\n", $sent);
    }

    public function testReEhloHappensAfterStarttls(): void
    {
        $t = new FakeSmtpTransport($this->happyReplies());
        (new SmtpClient($t))->send('h', 587, 'u', 'p', 'f@a.fr', 't@b.fr', "x");

        // Deux EHLO : un avant STARTTLS, un apres (session repart a zero apres TLS).
        $ehloCount = substr_count($t->written(), 'EHLO ');
        self::assertSame(2, $ehloCount);
    }

    public function testRejectedAuthThrowsAndCloses(): void
    {
        $replies = $this->happyReplies();
        $replies[6] = "535 authentication failed\r\n"; // reponse au mot de passe

        $t = new FakeSmtpTransport($replies);
        $client = new SmtpClient($t);

        try {
            $client->send('h', 587, 'u', 'bad', 'f@a.fr', 't@b.fr', 'x');
            self::fail('une auth refusee doit lever');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('AUTH password', $e->getMessage());
        }

        self::assertTrue($t->closed, 'le transport doit etre ferme meme en cas d echec (finally)');
    }

    public function testUnexpectedGreetingThrows(): void
    {
        $t = new FakeSmtpTransport(["554 service unavailable\r\n"]);
        $this->expectException(RuntimeException::class);
        (new SmtpClient($t))->send('h', 587, 'u', 'p', 'f@a.fr', 't@b.fr', 'x');
    }

    public function testRejectsCrlfInRecipientBeforeConnecting(): void
    {
        // Tentative d'injection d'une commande RCPT via le destinataire.
        $t = new FakeSmtpTransport($this->happyReplies());
        $client = new SmtpClient($t);

        try {
            $client->send('h', 587, 'u', 'p', 'f@a.fr', "t@b.fr>\r\nRCPT TO:<evil@x.com", 'x');
            self::fail('un CRLF dans l adresse doit lever');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('destinataire', $e->getMessage());
        }

        self::assertFalse($t->opened, 'aucune connexion ne doit s ouvrir si l adresse est invalide');
        self::assertSame([], $t->writes, 'rien ne doit etre emis');
    }
}
