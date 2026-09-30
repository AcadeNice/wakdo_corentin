<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use App\Core\Response;

/**
 * D-7.a (revue adverse, contre-audit 30/09) : `headers()` (ce que `send()`
 * emet reellement) pose une valeur par defaut de Referrer-Policy -- PAS
 * Apache. Voir `docker/apache/vhost.conf` (vhost admin) pour le detail des
 * mecanismes Apache essayes pour exclure conditionnellement /reset_password
 * de sa valeur par defaut, tous ecartes (verifies en conteneur jetable) au
 * profit de cette valeur par defaut cote PHP.
 *
 * `proc_open`/`pcntl_fork` sont indisponibles dans l'image PHP de ce depot
 * (deja constate pour D-3) : `#[RunInSeparateProcess]` + `headers_list()`
 * n'est donc pas une option ici -- `headers()` est le point d'observation
 * choisi, exactement celui que `send()` utilise pour emettre.
 */
final class ResponseTest extends TestCase
{
    public function testHeadersAppliesDefaultReferrerPolicyWhenNoneSet(): void
    {
        $response = Response::make('ok', 200, []);

        self::assertSame('strict-origin-when-cross-origin', $response->headers()['Referrer-Policy'] ?? null);
    }

    public function testHeadersKeepsAControllerSetReferrerPolicyInstead(): void
    {
        $response = Response::make('ok', 200, ['Referrer-Policy' => 'no-referrer']);

        self::assertSame('no-referrer', $response->headers()['Referrer-Policy'] ?? null);
    }

    /**
     * `header($name)` (accesseur singulier, deja utilise par la majorite des
     * tests existants) reste le miroir de ce qui a ete EXPLICITEMENT pose --
     * il ne doit PAS se mettre a renvoyer la valeur par defaut de
     * Referrer-Policy quand rien n'a ete pose (seul `headers()`, utilise par
     * `send()`, porte ce defaut).
     */
    public function testSingularHeaderAccessorDoesNotApplyTheDefault(): void
    {
        $response = Response::make('ok', 200, []);

        self::assertNull($response->header('Referrer-Policy'));
    }
}
