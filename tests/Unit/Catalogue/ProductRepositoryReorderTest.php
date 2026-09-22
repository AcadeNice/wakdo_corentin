<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalogue;

use PHPUnit\Framework\TestCase;
use App\Catalogue\ProductRepository;
use App\Tests\Support\FakeDatabase;

/**
 * Rangement d'un produit dans sa categorie (reorderWithinCategory, F20 back-office).
 * Deux lectures scriptees (categorie du produit deplace, puis liste ordonnee des
 * ids de bases de cette categorie) suivies d'une renumerotation complete 1..N en
 * transaction. FakeDatabase route les deux requetes vers reorderProductRow /
 * reorderCategoryIdsRows (cf. son fetch()/fetchAll()).
 */
final class ProductRepositoryReorderTest extends TestCase
{
    /**
     * @param list<int> $ids ordre AFFICHE actuel (display_order, name) de la categorie
     */
    private function dbWithCategoryOrder(int $categoryId, array $ids): FakeDatabase
    {
        $db = new FakeDatabase();
        $db->reorderProductRow = ['category_id' => $categoryId];
        $db->reorderCategoryIdsRows = array_map(static fn (int $id): array => ['id' => $id], $ids);

        return $db;
    }

    /**
     * Table finale id => display_order ecrite par la transaction, lue depuis les
     * ecritures tracees ('UPDATE product SET display_order = :ord WHERE id = :id').
     *
     * @return array<int, int>
     */
    private function finalRanks(FakeDatabase $db): array
    {
        $ranks = [];
        foreach ($db->writes as $write) {
            if (str_contains($write['sql'], 'UPDATE product SET display_order')) {
                $ranks[(int) $write['params']['id']] = (int) $write['params']['ord'];
            }
        }

        return $ranks;
    }

    public function testMovingUpSwapsWithThePreviousProductAndRenumbersEveryone(): void
    {
        $db = $this->dbWithCategoryOrder(3, [10, 20, 30, 40]);

        self::assertTrue((new ProductRepository($db))->reorderWithinCategory(20, 'up'));

        self::assertSame(['begin', 'commit'], $db->transactionEvents);
        self::assertSame([20 => 1, 10 => 2, 30 => 3, 40 => 4], $this->finalRanks($db));
    }

    public function testMovingDownSwapsWithTheNextProductAndRenumbersEveryone(): void
    {
        $db = $this->dbWithCategoryOrder(3, [10, 20, 30, 40]);

        self::assertTrue((new ProductRepository($db))->reorderWithinCategory(20, 'down'));

        self::assertSame([10 => 1, 30 => 2, 20 => 3, 40 => 4], $this->finalRanks($db));
    }

    public function testMovingTheFirstProductUpIsANoOpWithoutError(): void
    {
        $db = $this->dbWithCategoryOrder(3, [10, 20, 30]);

        self::assertFalse((new ProductRepository($db))->reorderWithinCategory(10, 'up'));

        self::assertSame([], $db->transactionEvents, 'deja en butee haute : aucune ecriture');
        self::assertSame([], $db->writes);
    }

    public function testMovingTheLastProductDownIsANoOpWithoutError(): void
    {
        $db = $this->dbWithCategoryOrder(3, [10, 20, 30]);

        self::assertFalse((new ProductRepository($db))->reorderWithinCategory(30, 'down'));

        self::assertSame([], $db->transactionEvents, 'deja en butee basse : aucune ecriture');
        self::assertSame([], $db->writes);
    }

    public function testSingleProductInCategoryNeverMoves(): void
    {
        $db = $this->dbWithCategoryOrder(3, [10]);

        self::assertFalse((new ProductRepository($db))->reorderWithinCategory(10, 'up'));
        self::assertFalse((new ProductRepository($db))->reorderWithinCategory(10, 'down'));
        self::assertSame([], $db->writes);
    }

    public function testUnknownProductIdReturnsFalseWithoutASecondQuery(): void
    {
        $db = $this->dbWithCategoryOrder(3, [10, 20, 30]);
        $db->reorderProductRow = null; // introuvable, ou variante (base_product_id non NULL)

        self::assertFalse((new ProductRepository($db))->reorderWithinCategory(999, 'up'));

        // Court-circuit : la deuxieme lecture (liste des ids de la categorie)
        // n'a meme pas de sens sans categorie resolue, elle ne doit pas partir.
        self::assertCount(1, $db->reads);
        self::assertSame([], $db->writes);
    }

    public function testProductAbsentFromItsOwnCategoryListingReturnsFalse(): void
    {
        // Incoherence de donnees improbable mais defendue explicitement par le
        // code (array_search === false) : ne doit ni planter ni ecrire.
        $db = $this->dbWithCategoryOrder(3, [111, 222]);

        self::assertFalse((new ProductRepository($db))->reorderWithinCategory(999, 'up'));
        self::assertSame([], $db->writes);
    }

    public function testQueryExcludesSizeVariantsAndOrdersLikeTheDisplayedList(): void
    {
        $db = $this->dbWithCategoryOrder(3, [10, 20]);

        (new ProductRepository($db))->reorderWithinCategory(10, 'down');

        self::assertStringContainsString('base_product_id IS NULL', $db->reads[0]['sql']);
        self::assertStringContainsString('base_product_id IS NULL', $db->reads[1]['sql']);
        self::assertStringContainsString('ORDER BY display_order, name', $db->reads[1]['sql']);
        self::assertSame(3, $db->reads[1]['params']['cat'] ?? null);
    }

    public function testAnyDirectionOtherThanUpBehavesLikeDown(): void
    {
        // Le depot ne revalide pas $direction lui-meme (le controleur filtre deja
        // strictement 'up'/'down' avant l'appel) : seule la chaine exacte 'up' est
        // speciale, tout le reste suit la branche 'down' (ternaire sur ===).
        // Verrouille le comportement reel plutot que de le supposer.
        $sideways = $this->dbWithCategoryOrder(3, [10, 20, 30]);
        $down = $this->dbWithCategoryOrder(3, [10, 20, 30]);

        (new ProductRepository($sideways))->reorderWithinCategory(20, 'sideways');
        (new ProductRepository($down))->reorderWithinCategory(20, 'down');

        self::assertSame($this->finalRanks($down), $this->finalRanks($sideways));
    }
}
