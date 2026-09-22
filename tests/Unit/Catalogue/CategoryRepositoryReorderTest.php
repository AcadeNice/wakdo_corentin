<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalogue;

use PHPUnit\Framework\TestCase;
use App\Catalogue\CategoryRepository;
use App\Tests\Support\FakeDatabase;

/**
 * Rangement d'une categorie (reorder(), miroir de ProductRepository::reorderWithinCategory
 * en plus simple : une seule liste globale, pas de regroupement par categorie).
 * FakeDatabase route 'SELECT id FROM category ORDER BY display_order, name' vers
 * categoriesRows (meme branche que all(), la colonne selectionnee ne changeant pas
 * le dispatch texte).
 */
final class CategoryRepositoryReorderTest extends TestCase
{
    /**
     * @param list<int> $ids ordre AFFICHE actuel (display_order, name)
     */
    private function dbWithOrder(array $ids): FakeDatabase
    {
        $db = new FakeDatabase();
        $db->categoriesRows = array_map(static fn (int $id): array => ['id' => $id], $ids);

        return $db;
    }

    /**
     * @return array<int, int>
     */
    private function finalRanks(FakeDatabase $db): array
    {
        $ranks = [];
        foreach ($db->writes as $write) {
            if (str_contains($write['sql'], 'UPDATE category SET display_order')) {
                $ranks[(int) $write['params']['id']] = (int) $write['params']['ord'];
            }
        }

        return $ranks;
    }

    public function testMovingUpSwapsWithThePreviousCategoryAndRenumbersEveryone(): void
    {
        $db = $this->dbWithOrder([1, 2, 3, 4]);

        self::assertTrue((new CategoryRepository($db))->reorder(2, 'up'));

        self::assertSame(['begin', 'commit'], $db->transactionEvents);
        self::assertSame([2 => 1, 1 => 2, 3 => 3, 4 => 4], $this->finalRanks($db));
    }

    public function testMovingDownSwapsWithTheNextCategoryAndRenumbersEveryone(): void
    {
        $db = $this->dbWithOrder([1, 2, 3, 4]);

        self::assertTrue((new CategoryRepository($db))->reorder(2, 'down'));

        self::assertSame([1 => 1, 3 => 2, 2 => 3, 4 => 4], $this->finalRanks($db));
    }

    public function testMovingTheFirstCategoryUpIsANoOpWithoutError(): void
    {
        $db = $this->dbWithOrder([1, 2, 3]);

        self::assertFalse((new CategoryRepository($db))->reorder(1, 'up'));

        self::assertSame([], $db->transactionEvents);
        self::assertSame([], $db->writes);
    }

    public function testMovingTheLastCategoryDownIsANoOpWithoutError(): void
    {
        $db = $this->dbWithOrder([1, 2, 3]);

        self::assertFalse((new CategoryRepository($db))->reorder(3, 'down'));

        self::assertSame([], $db->transactionEvents);
        self::assertSame([], $db->writes);
    }

    public function testSingleCategoryNeverMoves(): void
    {
        $db = $this->dbWithOrder([1]);

        self::assertFalse((new CategoryRepository($db))->reorder(1, 'up'));
        self::assertFalse((new CategoryRepository($db))->reorder(1, 'down'));
        self::assertSame([], $db->writes);
    }

    public function testUnknownCategoryIdReturnsFalseWithoutWriting(): void
    {
        $db = $this->dbWithOrder([1, 2, 3]);

        self::assertFalse((new CategoryRepository($db))->reorder(999, 'up'));

        self::assertSame([], $db->writes);
    }

    public function testOrdersLikeTheDisplayedList(): void
    {
        $db = $this->dbWithOrder([1, 2]);

        (new CategoryRepository($db))->reorder(1, 'down');

        self::assertStringContainsString('FROM category ORDER BY display_order, name', $db->reads[0]['sql']);
    }

    public function testAnyDirectionOtherThanUpBehavesLikeDown(): void
    {
        // Meme comportement que ProductRepository::reorderWithinCategory : seule la
        // chaine exacte 'up' est speciale (ternaire ===), le reste suit 'down'. Le
        // controleur filtre deja strictement 'up'/'down' avant l'appel.
        $sideways = $this->dbWithOrder([1, 2, 3]);
        $down = $this->dbWithOrder([1, 2, 3]);

        (new CategoryRepository($sideways))->reorder(2, 'sideways');
        (new CategoryRepository($down))->reorder(2, 'down');

        self::assertSame($this->finalRanks($down), $this->finalRanks($sideways));
    }
}
