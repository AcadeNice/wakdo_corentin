<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use App\Controllers\HealthController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;

/**
 * Sous-classe de test : pointe le fichier VERSION sur une fixture temporaire,
 * pour couvrir l'exposition de la version deployee sans dependre d'un deploiement
 * reel (le fichier est ecrit par scripts/deploy.sh sur l'hote, jamais en test).
 */
final class TestHealthController extends HealthController
{
    public string $versionPath = '';

    protected function versionFilePath(): string
    {
        return $this->versionPath;
    }
}

/**
 * La sonde expose la version deployee (SHA + date) pour prouver le CD : apres un
 * deploiement, GET /api/health doit refleter le nouveau commit. Le test n'a pas de
 * base : l'appel DB echoue et degrade le statut, mais les champs version restent
 * presents (ils sont independants de la BDD).
 */
final class HealthControllerTest extends TestCase
{
    private function controller(string $versionPath): TestHealthController
    {
        $request = new Request('GET', '/api/health', [], [], '', '203.0.113.5');
        $c = new TestHealthController($request, new Config(), new Database(new Config()));
        $c->versionPath = $versionPath;

        return $c;
    }

    public function testExposesDeployedVersionWhenFilePresent(): void
    {
        $fixture = tempnam(sys_get_temp_dir(), 'wakdo_version_');
        file_put_contents($fixture, "3dee190 2026-06-23T14:02:11+02:00\n");

        try {
            $body = $this->controller($fixture)->index()->body();
            $payload = json_decode($body, true);

            self::assertSame('3dee190', $payload['version']);
            self::assertSame('2026-06-23T14:02:11+02:00', $payload['deployed_at']);
        } finally {
            @unlink($fixture);
        }
    }

    public function testVersionNullWhenFileAbsent(): void
    {
        $missing = sys_get_temp_dir() . '/wakdo_version_does_not_exist_' . getmypid();
        @unlink($missing);

        $body = $this->controller($missing)->index()->body();
        $payload = json_decode($body, true);

        self::assertNull($payload['version']);
        self::assertNull($payload['deployed_at']);
    }
}
