<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalogue;

use App\Catalogue\ProductRepository;
use App\Tests\Support\FakeCatalogueDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Requete base-only (R4/F9-1) cote ProductRepository : la liste qui alimente les
 * selects du formulaire menu (burger principal + options) ET le select de base du
 * formulaire produit ne doit proposer QUE des produits de BASE (base_product_id IS
 * NULL). On verrouille la garde sur le SQL trace : une regression (filtre retire)
 * ferait virer le test au rouge. Le double FakeCatalogueDatabase scripte les lignes.
 */
final class ProductRepositoryBaseOnlyTest extends TestCase
{
    public function testBasesOnlyQueryFiltersVariants(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->baseProductsRows = [
            ['id' => '14', 'name' => 'Coca Cola'],
            ['id' => '15', 'name' => 'Fanta'],
        ];

        $rows = (new ProductRepository($db))->basesOnly();

        self::assertCount(2, $rows);
        self::assertSame('14', $rows[0]['id']);
        // Le predicat anti-variante vit dans le SQL : on l'asserte sur la requete.
        self::assertStringContainsString('base_product_id IS NULL', $db->reads[0]['sql']);
    }

    public function testProductIsBaseQueryCarriesBaseOnlyPredicate(): void
    {
        // Garde serveur : productIsBase() ne doit matcher qu'une ligne de base.
        $db = new FakeCatalogueDatabase();

        (new ProductRepository($db))->productIsBase(14);

        self::assertStringContainsString('base_product_id IS NULL', $db->reads[0]['sql']);
        self::assertSame(14, $db->reads[0]['params']['id'] ?? null);
    }

    public function testAllQueryJoinsBaseNameForVariantLabel(): void
    {
        // F9-4 : la liste admin enrichit chaque ligne du nom de sa base (LEFT JOIN)
        // pour pouvoir marquer "Variante de X" sans exclure les variantes.
        $db = new FakeCatalogueDatabase();
        $db->allProductsRows = [
            ['id' => '99', 'category_id' => '2', 'name' => 'Coca Cola 50cl', 'price_cents' => '240', 'vat_rate' => '100', 'is_available' => '1', 'display_order' => '2', 'size_cl' => '50', 'base_product_id' => '14', 'category_name' => 'Boissons', 'base_name' => 'Coca Cola'],
        ];

        $rows = (new ProductRepository($db))->all();

        self::assertCount(1, $rows);
        self::assertSame('Coca Cola', $rows[0]['base_name']);
        self::assertStringContainsString('LEFT JOIN product b', $db->reads[0]['sql']);
    }
}
