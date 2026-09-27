<?php

declare(strict_types=1);

namespace App\Tests\Unit\Health;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use App\Health\Probes;

/**
 * Verifie que `Probes::all()` colle EXACTEMENT au contrat (section 4) : sept
 * sondes, dans l'ordre, avec les champs attendus par la vue (chantier B) et par
 * le test d'innocuite (`tests/Integration/HealthProbesSafetyDbTest.php`).
 */
final class ProbesTest extends TestCase
{
    public function testSevenProbesInContractOrder(): void
    {
        $ids = array_column(Probes::all(), 'id');

        self::assertSame(
            ['sante', 'catalogue', 'session', 'jeton', 'type', 'introuvable', 'methode'],
            $ids,
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: int, 4: ?string}>
     */
    public static function probeProvider(): iterable
    {
        yield 'sante' => ['sante', 'GET', '/api/health', 200, null];
        yield 'catalogue' => ['catalogue', 'GET', '/api/products', 200, null];
        yield 'session' => ['session', 'GET', '/admin/api/products', 401, 'AUTH_REQUIRED'];
        yield 'jeton' => ['jeton', 'PUT', '/admin/api/roles/0', 403, 'CSRF_INVALID'];
        yield 'type' => ['type', 'PUT', '/admin/api/roles/0', 415, 'UNSUPPORTED_MEDIA_TYPE'];
        yield 'introuvable' => ['introuvable', 'GET', '/api/nexiste-pas', 404, 'NOT_FOUND'];
        yield 'methode' => ['methode', 'DELETE', '/api/products', 405, 'METHOD_NOT_ALLOWED'];
    }

    #[DataProvider('probeProvider')]
    public function testProbeMatchesContract(string $id, string $method, string $url, int $expect, ?string $expectCode): void
    {
        $probe = self::findProbe($id);

        self::assertSame($method, $probe['method']);
        self::assertSame($url, $probe['url']);
        self::assertSame($expect, $probe['expect']);
        self::assertSame($expectCode, $probe['expectCode']);
        self::assertNotSame('', trim($probe['label']));
        self::assertNotSame('', trim($probe['why']));
    }

    public function testSessionProbeOmitsCredentials(): void
    {
        self::assertSame('omit', self::findProbe('session')['credentials']);
    }

    public function testJetonProbeSendsNoCsrfHeaderWithJsonBody(): void
    {
        $probe = self::findProbe('jeton');

        self::assertFalse($probe['sendCsrf']);
        self::assertSame('application/json', $probe['contentType']);
        self::assertSame('{}', $probe['body']);
        self::assertSame('include', $probe['credentials']);
    }

    public function testTypeProbeSendsValidCsrfWithWrongContentType(): void
    {
        $probe = self::findProbe('type');

        self::assertTrue($probe['sendCsrf']);
        self::assertSame('text/plain', $probe['contentType']);
        self::assertNotSame('', (string) $probe['body']);
        self::assertSame('include', $probe['credentials']);
    }

    /**
     * @return array{
     *     id: string, label: string, method: string, url: string,
     *     credentials: string, sendCsrf: bool, contentType: ?string,
     *     body: ?string, expect: int, expectCode: ?string, why: string,
     * }
     */
    private static function findProbe(string $id): array
    {
        foreach (Probes::all() as $probe) {
            if ($probe['id'] === $id) {
                return $probe;
            }
        }

        self::fail("Sonde introuvable : {$id}");
    }
}
