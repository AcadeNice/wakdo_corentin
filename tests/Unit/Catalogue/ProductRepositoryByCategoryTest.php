<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalogue;

use App\Catalogue\ProductRepository;
use App\Tests\Support\FakeCatalogueDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Lecture groupee par categorie (F20) cote ProductRepository. La vue back-office
 * "Produits par categorie" doit montrer ce que la borne affiche : donc EXCLURE les
 * variantes de taille (base_product_id non nul, R4) et rendre le nombre de tailles
 * portees par chaque base. Le double FakeCatalogueDatabase scripte les lignes plates
 * renvoyees a la requete ; le regroupement est fait par le depot.
 */
final class ProductRepositoryByCategoryTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        // Lignes telles que la base les renvoie : tout en chaines (PDO sans cast).
        return [
            ['id' => '14', 'category_id' => '2', 'name' => 'Coca Cola', 'price_cents' => '190', 'vat_rate' => '100', 'is_available' => '1', 'display_order' => '1', 'variant_count' => '1'],
            ['id' => '15', 'category_id' => '2', 'name' => 'Eau', 'price_cents' => '100', 'vat_rate' => '55', 'is_available' => '1', 'display_order' => '3', 'variant_count' => '0'],
            ['id' => '10', 'category_id' => '3', 'name' => 'Big Mac', 'price_cents' => '600', 'vat_rate' => '100', 'is_available' => '0', 'display_order' => '4', 'variant_count' => '0'],
        ];
    }

    public function testGroupsRowsByCategoryId(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->basesByCategoryRows = $this->rows();

        $byCategory = (new ProductRepository($db))->basesByCategory();

        self::assertSame([2, 3], array_keys($byCategory));
        self::assertCount(2, $byCategory[2]);
        self::assertCount(1, $byCategory[3]);
    }

    public function testCastsEveryNumericFieldToInt(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->basesByCategoryRows = $this->rows();

        $byCategory = (new ProductRepository($db))->basesByCategory();

        // La vue compare des entiers (is_available === 1, variant_count > 0) : un cast
        // manquant ferait passer '0' pour vrai et afficherait un produit retire comme
        // disponible.
        self::assertSame(
            ['id' => 14, 'name' => 'Coca Cola', 'price_cents' => 190, 'vat_rate' => 100, 'is_available' => 1, 'display_order' => 1, 'variant_count' => 1],
            $byCategory[2][0],
        );
        self::assertSame(0, $byCategory[3][0]['is_available']);
        self::assertSame(55, $byCategory[2][1]['vat_rate']);
    }

    public function testPreservesIntraCategoryOrderFromQuery(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->basesByCategoryRows = $this->rows();

        $byCategory = (new ProductRepository($db))->basesByCategory();

        // L'ordre a l'interieur d'un groupe vient du SQL (display_order, name) ; le
        // depot ne re-trie pas. L'ordre des GROUPES est porte par la liste des
        // categories, pas par cette methode.
        self::assertSame(['Coca Cola', 'Eau'], array_column($byCategory[2], 'name'));
    }

    public function testQueryExcludesSizeVariants(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->basesByCategoryRows = [];

        (new ProductRepository($db))->basesByCategory();

        // Le predicat anti-variante est porte par le SQL, miroir de basesOnly() et
        // availableForCatalogue() : on le verrouille sur la requete tracee, une
        // regression etant le retrait de la clause.
        self::assertStringContainsString('p.base_product_id IS NULL', $db->reads[0]['sql']);
        self::assertStringContainsString('ORDER BY p.display_order, p.name', $db->reads[0]['sql']);
    }

    public function testCountsVariantsInASingleQuery(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->basesByCategoryRows = $this->rows();

        (new ProductRepository($db))->basesByCategory();

        // Le compte de tailles passe par une sous-requete correlee : un seul
        // aller-retour, donc pas de N+1 quand le catalogue grossit.
        self::assertCount(1, $db->reads);
        self::assertStringContainsString('AS variant_count', $db->reads[0]['sql']);
    }

    public function testEmptyCatalogueYieldsEmptyGrouping(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->basesByCategoryRows = [];

        self::assertSame([], (new ProductRepository($db))->basesByCategory());
    }
}
