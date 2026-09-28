<?php

declare(strict_types=1);

namespace App\Tests\Unit\Health;

use PHPUnit\Framework\TestCase;
use App\Health\CapturedResponses;
use App\Health\RouteMap;

/**
 * Le trajet de la page Sante affiche, quand il ne peut pas faire l'appel lui-meme
 * (une ecriture, un refus qui demande un autre compte), la reponse REELLE capturee
 * sur une pile jetable par tests/e2e/health-capture.spec.js. Ce test tient le fichier
 * en phase avec le routeur : une route ajoutee, renommee ou retiree sans refaire la
 * capture le fait echouer, en nommant la route. Il ne fait aucune requete : il lit le
 * fichier versionne.
 */
final class CapturedResponsesTest extends TestCase
{
    public function testTheCapturedFileLoadsWithItsProvenance(): void
    {
        $doc = CapturedResponses::load();

        self::assertIsArray($doc, 'src/app/Health/captured-responses.json doit exister et etre du JSON valide');
        self::assertIsString($doc['captured_at'] ?? null);
        self::assertIsString($doc['where'] ?? null);
        self::assertIsArray($doc['routes'] ?? null);
    }

    public function testEveryRouteOfTheRouterHasItsCapturedSuccessOrAWrittenReason(): void
    {
        $doc = CapturedResponses::load();
        self::assertIsArray($doc);
        $missing = [];
        foreach (RouteMap::rows() as $row) {
            $key = $row['m'] . ' ' . $row['p'];
            $ok = $doc['routes'][$key]['ok'] ?? null;
            $valid = is_array($ok) && (isset($ok['status']) || (($ok['not_reproduced'] ?? false) === true && is_string($ok['how'] ?? null)));
            if (!$valid) {
                $missing[] = $key;
            }
        }

        self::assertSame([], $missing, 'Routes sans reponse capturee : relancer tests/e2e/run-health-capture.sh');
    }

    public function testNoCapturedRouteIsUnknownToTheRouter(): void
    {
        $doc = CapturedResponses::load();
        self::assertIsArray($doc);
        $known = [];
        foreach (RouteMap::rows() as $row) {
            $known[$row['m'] . ' ' . $row['p']] = true;
        }

        $stale = array_values(array_filter(array_keys($doc['routes']), static fn (string $k): bool => !isset($known[$k])));

        self::assertSame([], $stale, 'Captures de routes qui n\'existent plus : relancer tests/e2e/run-health-capture.sh');
    }

    public function testCapturedBodiesCarryNoSecret(): void
    {
        // La capture masque les jetons et les mots de passe : le fichier est versionne
        // et transmis a la page. Un jeton de session ou CSRF en clair (64 caracteres
        // hexadecimaux) ou un mot de passe de demonstration ne doit jamais y figurer.
        $raw = (string) file_get_contents(CapturedResponses::path());

        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{64}/', $raw);
        self::assertStringNotContainsString('2026!', $raw);
        self::assertStringNotContainsString('WAKDO_SID=', $raw);
    }

    public function testAMissingFileGivesNullRatherThanAnError(): void
    {
        self::assertNull(CapturedResponses::load('/chemin/qui/n-existe/pas.json'));
    }
}
