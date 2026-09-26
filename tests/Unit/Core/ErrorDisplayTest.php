<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use App\Core\ErrorDisplay;

/**
 * L'image PHP-FPM embarque `display_errors = On` (docker/php-fpm/php.ini), pratique
 * en developpement. Le commentaire de ce fichier annoncait une surcharge par
 * `docker-compose.prod.yml` qui n'a jamais existe : la production a donc tourne en
 * affichant le texte des erreurs PHP au visiteur (constate le 2026-09-26 sur la pile
 * de demonstration : `display_errors=1`, `APP_DEBUG=true`).
 *
 * La politique ne depend plus d'un fichier de composition : elle est derivee de
 * `APP_DEBUG` au demarrage de l'application, donc elle suit le deploiement partout.
 * Ce test porte sur la partie PURE (le calcul de la politique), sans toucher aux
 * reglages globaux de PHP.
 */
final class ErrorDisplayTest extends TestCase
{
    public function testDebugOffHidesErrorsFromTheVisitor(): void
    {
        self::assertSame('0', ErrorDisplay::policyFor(false)['display_errors']);
    }

    public function testDebugOnShowsErrorsOnScreen(): void
    {
        self::assertSame('1', ErrorDisplay::policyFor(true)['display_errors']);
    }

    public function testStartupErrorsAreNotPartOfTheRuntimePolicy(): void
    {
        // Volontaire : une erreur de demarrage se produit avant l'execution du point
        // d'entree, donc `ini_set()` ne peut plus la masquer. La faire figurer ici
        // donnerait l'illusion d'une protection inexistante -- elle est traitee dans
        // le php.ini de l'image, a `Off`.
        self::assertArrayNotHasKey('display_startup_errors', ErrorDisplay::policyFor(false));
    }

    public function testErrorsAreAlwaysLogged(): void
    {
        // Cacher au visiteur ne doit pas revenir a perdre l'information : dans les
        // deux modes le journal reste actif, sinon un incident de production devient
        // invisible pour l'exploitant en meme temps qu'il le devient pour l'attaquant.
        foreach ([true, false] as $debug) {
            self::assertSame('1', ErrorDisplay::policyFor($debug)['log_errors']);
        }
    }

    public function testEverythingIsReportedInBothModes(): void
    {
        // On ne baisse pas le niveau de rapport en production : c'est la DESTINATION
        // qui change (journal au lieu de l'ecran), pas le perimetre observe.
        foreach ([true, false] as $debug) {
            self::assertSame((string) E_ALL, ErrorDisplay::policyFor($debug)['error_reporting']);
        }
    }

    public function testDisplayErrorsIsTheOnlySettingThatDependsOnDebug(): void
    {
        $off = ErrorDisplay::policyFor(false);
        $on  = ErrorDisplay::policyFor(true);

        self::assertSame(['display_errors'], array_keys(array_diff_assoc($on, $off)));
    }

    public function testPolicyKeysAreRealPhpSettings(): void
    {
        // Un nom de reglage mal orthographie serait silencieusement ignore par
        // ini_set() : on verifie que chaque cle existe reellement cote PHP.
        foreach (array_keys(ErrorDisplay::policyFor(false)) as $setting) {
            self::assertIsString(ini_get($setting), 'reglage PHP inconnu : ' . $setting);
        }
    }
}
