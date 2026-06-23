<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalogue;

use App\Catalogue\ProductRepository;
use App\Tests\Support\FakeCatalogueDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tailles a la carte des boissons (R4) cote ProductRepository. La grille catalogue
 * doit EXCLURE les variantes de taille (base_product_id non nul) et les tailles
 * sont assemblees pour une base a variantes / vides pour un produit mono-taille.
 * Le double FakeCatalogueDatabase scripte les lignes renvoyees aux requetes.
 */
final class ProductRepositorySizesTest extends TestCase
{
    public function testAvailableForCatalogueQueryExcludesVariants(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->productsRows = [
            ['id' => '14', 'category_id' => '2', 'name' => 'Coca Cola', 'description' => null, 'price_cents' => '190', 'size_cl' => '30', 'image_path' => 'c.png', 'display_order' => '1'],
        ];

        $rows = (new ProductRepository($db))->availableForCatalogue();

        // La projection remonte la base 30 cl ; le filtre anti-variante est porte par
        // le SQL (base_product_id IS NULL) -> on l'asserte sur la requete tracee.
        self::assertCount(1, $rows);
        self::assertSame('14', $rows[0]['id']);
        self::assertStringContainsString('base_product_id IS NULL', $db->reads[0]['sql']);
    }

    public function testFindForCatalogueQueryExcludesVariants(): void
    {
        // Acces direct au detail : meme invariant que la liste. Une variante de
        // taille (base_product_id non nul) ne doit jamais etre une fiche autonome
        // -> /api/products/{idVariante} rend 404. Le double est scripte, donc on
        // verrouille la garde sur le SQL trace (regression = clause retiree).
        $db = new FakeCatalogueDatabase();
        $db->productRow = ['id' => '14', 'category_id' => '2', 'name' => 'Coca Cola', 'description' => null, 'price_cents' => '190', 'image_path' => 'c.png', 'display_order' => '1'];

        (new ProductRepository($db))->findForCatalogue(14);

        self::assertStringContainsString('p.id = :id', $db->reads[0]['sql']);
        self::assertStringContainsString('base_product_id IS NULL', $db->reads[0]['sql']);
    }

    public function testSizesByBaseGroupsRowsByBaseId(): void
    {
        $db = new FakeCatalogueDatabase();
        // Lignes plates : base 14 (Coca 30 cl) + sa variante 50 cl (id 99) ; base 15
        // (Fanta 30 cl) + sa variante (id 100). Le repo les groupe par base_id.
        $db->sizesByBaseRows = [
            ['base_id' => '14', 'id' => '14', 'size_cl' => '30', 'price_cents' => '190'],
            ['base_id' => '14', 'id' => '99', 'size_cl' => '50', 'price_cents' => '240'],
            ['base_id' => '15', 'id' => '15', 'size_cl' => '30', 'price_cents' => '190'],
            ['base_id' => '15', 'id' => '100', 'size_cl' => '50', 'price_cents' => '240'],
        ];

        $byBase = (new ProductRepository($db))->sizesByBase();

        self::assertSame([14, 15], array_keys($byBase));
        self::assertSame(
            [
                ['id' => 14, 'size_cl' => 30, 'price_cents' => 190],
                ['id' => 99, 'size_cl' => 50, 'price_cents' => 240],
            ],
            $byBase[14],
        );
        self::assertCount(2, $byBase[15]);
    }

    public function testSizesByBaseEmptyWhenNoVariants(): void
    {
        $db = new FakeCatalogueDatabase();
        // Aucune ligne remontee : aucune base ne porte de variante de taille.
        $byBase = (new ProductRepository($db))->sizesByBase();
        self::assertSame([], $byBase);
    }

    public function testSizesForProductReturnsBaseAndVariant(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->productSizes = [
            ['id' => '14', 'size_cl' => '30', 'price_cents' => '190'],
            ['id' => '99', 'size_cl' => '50', 'price_cents' => '240'],
        ];

        $sizes = (new ProductRepository($db))->sizesForProduct(14);

        self::assertCount(2, $sizes);
        self::assertSame('14', $sizes[0]['id']);
        self::assertSame('99', $sizes[1]['id']);
        // Le parametre :base a bien ete lie a l'id demande.
        self::assertSame(14, $db->reads[0]['params']['base'] ?? null);
    }
}
