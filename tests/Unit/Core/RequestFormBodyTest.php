<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use App\Core\Request;

/**
 * Couvre les deux accesseurs ajoutes au Request pour l'auth back-office :
 * formBody() (login = formulaire POST urlencode, pas JSON) et clientIp()
 * (IP reelle derriere Traefik pour la cle de throttling par IP).
 */
final class RequestFormBodyTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, string $rawBody, array $headers = [], string $remoteAddr = ''): Request
    {
        return new Request($method, '/login', [], $headers, $rawBody, $remoteAddr);
    }

    public function testFormBodyParsesUrlencodedBody(): void
    {
        $request = $this->request(
            'POST',
            'email=admin%40wakdo.local&password=secret+pass',
            ['content-type' => 'application/x-www-form-urlencoded'],
        );

        self::assertSame(
            ['email' => 'admin@wakdo.local', 'password' => 'secret pass'],
            $request->formBody(),
        );
    }

    public function testFormBodyToleratesCharsetSuffixOnContentType(): void
    {
        $request = $this->request(
            'POST',
            'a=1',
            ['content-type' => 'application/x-www-form-urlencoded; charset=UTF-8'],
        );

        self::assertSame(['a' => '1'], $request->formBody());
    }

    public function testFormBodyReturnsEmptyForJsonContentType(): void
    {
        $request = $this->request('POST', '{"email":"x"}', ['content-type' => 'application/json']);

        self::assertSame([], $request->formBody());
    }

    public function testFormBodyReturnsEmptyWhenContentTypeAbsent(): void
    {
        $request = $this->request('POST', 'email=x');

        self::assertSame([], $request->formBody());
    }

    public function testFormBodyDropsArrayShapedValues(): void
    {
        // parse_str transforme "tags[]=a&tags[]=b" en tableau : on ne garde que
        // les scalaires pour tenir le contrat array<string, string>.
        $request = $this->request(
            'POST',
            'name=ok&tags%5B%5D=a&tags%5B%5D=b',
            ['content-type' => 'application/x-www-form-urlencoded'],
        );

        self::assertSame(['name' => 'ok'], $request->formBody());
    }

    public function testClientIpUsesLastForwardedHop(): void
    {
        // Seul le dernier hop (ajoute par Traefik) est de confiance ; les entrees
        // de gauche sont fournies par le client et donc falsifiables.
        $request = $this->request(
            'POST',
            '',
            ['x-forwarded-for' => '10.0.0.9, 203.0.113.7'],
            '172.18.0.2',
        );

        self::assertSame('203.0.113.7', $request->clientIp());
    }

    public function testClientIpFallsBackToRemoteAddrWhenNoForwardedHeader(): void
    {
        $request = $this->request('POST', '', [], '198.51.100.4');

        self::assertSame('198.51.100.4', $request->clientIp());
    }

    public function testClientIpFallsBackWhenForwardedHopIsMalformed(): void
    {
        $request = $this->request(
            'POST',
            '',
            ['x-forwarded-for' => 'not-an-ip'],
            '198.51.100.4',
        );

        self::assertSame('198.51.100.4', $request->clientIp());
    }

    public function testClientIpAcceptsIpv6(): void
    {
        $request = $this->request(
            'POST',
            '',
            ['x-forwarded-for' => '2001:db8::1'],
            '172.18.0.2',
        );

        self::assertSame('2001:db8::1', $request->clientIp());
    }

    public function testClientIpReturnsSentinelWhenNothingResolvable(): void
    {
        $request = $this->request('POST', '', [], '');

        self::assertSame('0.0.0.0', $request->clientIp());
    }
}
