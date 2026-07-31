<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalogue;

use App\Catalogue\AllergenRepository;
use App\Tests\Support\FakeCatalogueDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Allergenes CALCULES par produit (F11b) : le dictionnaire (3.8) pose que les
 * allergenes d'un produit se deduisent de la chaine product_ingredient ->
 * ingredient_allergen -> allergen, sans ressaisie par produit. Ces tests verrouillent
 * cette derivation cote depot.
 *
 * Deux lectures distinctes, une requete chacune (pas de N+1 sur un catalogue de 58
 * produits) :
 *  - byProduct() : les allergenes, dedupliques par produit ;
 *  - unreviewedByProduct() : combien d'ingredients du produit n'ont PAS ete revus.
 *
 * La seconde porte l'honnetete du dispositif : une liste vide ne veut pas dire
 * "sans allergene" tant qu'un ingredient n'a pas ete revu.
 */
final class AllergenRepositoryByProductTest extends TestCase
{
    /**
     * Lignes plates telles que la base les renvoie (tout en chaines, PDO sans cast).
     * Le produit 10 porte deux fois le gluten (pain + panure) : la deduplication est
     * l'enjeu.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return [
            ['product_id' => '10', 'allergen_id' => '1', 'code' => 'gluten', 'name' => 'Gluten'],
            ['product_id' => '10', 'allergen_id' => '7', 'code' => 'milk',   'name' => 'Lait'],
            ['product_id' => '11', 'allergen_id' => '3', 'code' => 'eggs',   'name' => 'Oeufs'],
        ];
    }

    public function testGroupsAllergensByProductId(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->allergensByProductRows = $this->rows();

        $byProduct = (new AllergenRepository($db))->byProduct();

        self::assertSame([10, 11], array_keys($byProduct));
        self::assertCount(2, $byProduct[10]);
        self::assertCount(1, $byProduct[11]);
    }

    public function testCastsIdToIntAndKeepsCodeAndName(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->allergensByProductRows = $this->rows();

        $byProduct = (new AllergenRepository($db))->byProduct();

        // La presentation compare et serialise des entiers : un id reste string
        // ferait sortir "1" dans le JSON de la borne.
        self::assertSame(
            ['id' => 1, 'code' => 'gluten', 'name' => 'Gluten'],
            $byProduct[10][0],
        );
        self::assertSame(7, $byProduct[10][1]['id']);
    }

    public function testDeduplicationIsDoneBySqlNotInPhp(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->allergensByProductRows = [];

        (new AllergenRepository($db))->byProduct();

        // Deux ingredients d'un meme produit peuvent porter le meme allergene (pain
        // + panure -> gluten). Le regroupement SQL evite de remonter deux fois la
        // meme ligne ; sans lui, la borne afficherait "Gluten" en double.
        self::assertCount(1, $db->reads);
        self::assertStringContainsString('FROM product_ingredient pi', $db->reads[0]['sql']);
        self::assertStringContainsString('JOIN ingredient_allergen ia', $db->reads[0]['sql']);
        self::assertStringContainsString('GROUP BY pi.product_id, a.id, a.code, a.name', $db->reads[0]['sql']);
        self::assertStringContainsString('ORDER BY pi.product_id, a.id', $db->reads[0]['sql']);
    }

    public function testEmptyMappingYieldsEmptyGrouping(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->allergensByProductRows = [];

        self::assertSame([], (new AllergenRepository($db))->byProduct());
    }

    public function testUnreviewedByProductCountsOnlyUnreviewedIngredients(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->unreviewedByProductRows = [
            ['product_id' => '10', 'unreviewed' => '2'],
            ['product_id' => '12', 'unreviewed' => '1'],
        ];

        $counts = (new AllergenRepository($db))->unreviewedByProduct();

        self::assertSame([10 => 2, 12 => 1], $counts);
    }

    public function testUnreviewedByProductFiltersOnTheReviewMarkerInSql(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->unreviewedByProductRows = [];

        (new AllergenRepository($db))->unreviewedByProduct();

        // Le predicat est le coeur de l'honnetete : un ingredient sans date de revue
        // rend l'information du produit incomplete. Le retirer ferait passer tout le
        // catalogue pour verifie.
        self::assertCount(1, $db->reads);
        self::assertStringContainsString('i.allergens_reviewed_at IS NULL', $db->reads[0]['sql']);
        self::assertStringContainsString('GROUP BY pi.product_id', $db->reads[0]['sql']);
    }

    public function testProductAbsentFromUnreviewedMapIsComplete(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->unreviewedByProductRows = [['product_id' => '10', 'unreviewed' => '1']];

        $counts = (new AllergenRepository($db))->unreviewedByProduct();

        // Convention de la lecture : la requete ne remonte QUE les produits ayant au
        // moins un ingredient non revu. Absence = revu integralement.
        self::assertArrayNotHasKey(11, $counts);
    }

    public function testForProductReadsASingleProductWithABoundParameter(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->allergensForProductRows = [
            ['allergen_id' => '1', 'code' => 'gluten', 'name' => 'Gluten'],
        ];

        $allergens = (new AllergenRepository($db))->forProduct(10);

        self::assertSame([['id' => 1, 'code' => 'gluten', 'name' => 'Gluten']], $allergens);
        self::assertSame(['id' => 10], $db->reads[0]['params']);
        self::assertStringContainsString('WHERE pi.product_id = :id', $db->reads[0]['sql']);
    }

    public function testUnreviewedCountForProductReturnsZeroWhenNoRow(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->unreviewedCountRow = null;

        self::assertSame(0, (new AllergenRepository($db))->unreviewedCountForProduct(10));
    }

    public function testUnreviewedCountForProductCastsTheAggregate(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->unreviewedCountRow = ['n' => '3'];

        self::assertSame(3, (new AllergenRepository($db))->unreviewedCountForProduct(10));
    }

    public function testAllergenIdsForIngredientReturnsAFlatIntList(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->ingredientAllergenRows = [['allergen_id' => '1'], ['allergen_id' => '11']];

        // Sert a precocher les cases du formulaire back-office : la vue compare des
        // entiers (in_array strict), une liste de chaines ne cocherait rien.
        self::assertSame([1, 11], (new AllergenRepository($db))->allergenIdsForIngredient(7));
    }
}
