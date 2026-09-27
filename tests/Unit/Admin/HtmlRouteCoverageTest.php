<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use App\Tests\Support\HtmlRouteHarness;

/**
 * Filets de la preuve route par route du back-office HTML (Html*Test) : la
 * table d'exigences, le routeur reel et les scenarios d'exercice doivent
 * parler des MEMES routes. Sans ces garde-fous, une route ajoutee au routeur
 * mais oubliee dans la table (ou l'inverse) sortirait des fournisseurs de
 * donnees sans qu'aucun test ne le signale.
 */
final class HtmlRouteCoverageTest extends TestCase
{
    public function testTheBackOfficeTableIsNotEmpty(): void
    {
        self::assertNotSame([], HtmlRouteHarness::boEntries(), 'Aucune route back-office HTML dans RouteSecurity::ENTRIES : le filtre de surface a-t-il diverge ?');
    }

    public function testPermissionCatalogueIsReadFromTheSeed(): void
    {
        $catalogue = HtmlRouteHarness::permissionCatalogue();

        // 23 : le catalogue du seed RBAC, que la page ne modifie pas (contrat).
        self::assertCount(23, $catalogue);
        foreach (HtmlRouteHarness::boEntries() as $entry) {
            if ($entry[3] !== null) {
                self::assertContains($entry[3], $catalogue, HtmlRouteHarness::key($entry) . " annonce une permission absente du catalogue.");
            }
        }
    }

    public function testEveryBackOfficeEntryIsServedByTheRealRouter(): void
    {
        $handlers = HtmlRouteHarness::handlers();
        foreach (HtmlRouteHarness::boEntries() as $entry) {
            self::assertArrayHasKey(HtmlRouteHarness::key($entry), $handlers, HtmlRouteHarness::key($entry) . ' figure dans la table mais pas dans routes.php.');
        }
    }

    public function testEveryBackOfficeRouteOfTheRouterHasItsEntry(): void
    {
        $tabled = array_map(static fn (array $entry): string => HtmlRouteHarness::key($entry), HtmlRouteHarness::boEntries());
        foreach (array_keys(HtmlRouteHarness::handlers()) as $key) {
            [, $path] = explode(' ', $key, 2);
            if (HtmlRouteHarness::isBoPath($path)) {
                self::assertContains($key, $tabled, "$key est routee mais absente de RouteSecurity::ENTRIES : ses exigences ne seraient ni affichees ni testees.");
            }
        }
    }

    public function testEveryBackOfficeWriteHasASuccessScenarioAndNoScenarioIsStale(): void
    {
        $scenarios = HtmlRouteHarness::scenarios();
        $writes = [];
        foreach (HtmlRouteHarness::boEntries() as $entry) {
            if (HtmlRouteHarness::isWrite($entry)) {
                $writes[] = HtmlRouteHarness::key($entry);
                self::assertArrayHasKey(HtmlRouteHarness::key($entry), $scenarios, HtmlRouteHarness::key($entry) . ' : aucun scenario de reussite, les preuves jeton / code ne peuvent pas l\'exercer.');
            }
        }
        foreach (array_keys($scenarios) as $key) {
            self::assertContains($key, $writes, "Scenario « $key » sans route d'ecriture correspondante dans la table.");
        }
    }

    public function testEveryPricePinRouteDescribesASensitiveChange(): void
    {
        foreach (HtmlRouteHarness::boEntries() as $entry) {
            if ($entry[5] !== 'price') {
                continue;
            }
            $scenario = HtmlRouteHarness::scenario(HtmlRouteHarness::key($entry)) ?? [];
            $price = $scenario['price'] ?? null;
            self::assertIsArray($price, HtmlRouteHarness::key($entry) . ' annonce pin = price sans scenario de changement de prix.');
            self::assertNotEmpty($price['variants'] ?? [], HtmlRouteHarness::key($entry) . ' : aucune variante de changement de prix.');
        }
    }
}
