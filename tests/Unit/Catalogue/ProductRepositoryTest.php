<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalogue;

use PHPUnit\Framework\TestCase;
use App\Catalogue\ProductRepository;

/**
 * Disponibilite produit CALCULEE (RG-T21), logique pure sans base. Un produit est
 * commandable ssi son flag is_available vaut 1 ET chaque ingredient NON RETIRABLE
 * (is_removable=0) de sa composition est au-dessus de la bande critique. Un retrait
 * manuel (is_available=0) prime sur tout ; un ingredient retirable/optionnel en
 * critique ne bloque pas le produit (seul son supplement devient indisponible).
 * Derivation pure, sans ecriture ni cascade (mcd 5.3 / RG-T21). La bande critique
 * est celle d'IngredientRepository::stockBand (source unique de la derivation).
 */
final class ProductRepositoryTest extends TestCase
{
    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private function line(array $over = []): array
    {
        return array_merge([
            'is_removable'       => 0,
            'stock_quantity'     => 50,
            'stock_capacity'     => 100,
            'low_stock_pct'      => 10,
            'critical_stock_pct' => 5,
        ], $over);
    }

    public function testManualUnavailabilityAlwaysBlocks(): void
    {
        // is_available=0 : retrait manuel, prime meme sur un stock plein (surcharge forte).
        self::assertFalse(ProductRepository::isOrderable(false, [$this->line()]));
    }

    public function testAvailableWithoutCompositionIsOrderable(): void
    {
        self::assertTrue(ProductRepository::isOrderable(true, []));
    }

    public function testRequiredIngredientAtCriticalBlocks(): void
    {
        // Requis, 5/100 <= 5% -> bande critique -> rupture automatique.
        self::assertFalse(ProductRepository::isOrderable(true, [$this->line(['stock_quantity' => 5])]));
    }

    public function testRequiredIngredientJustAboveCriticalDoesNotBlock(): void
    {
        // Requis, 6/100 > 5% -> bande basse (pas critique) -> reste commandable.
        self::assertTrue(ProductRepository::isOrderable(true, [$this->line(['stock_quantity' => 6])]));
    }

    public function testRemovableIngredientAtCriticalDoesNotBlock(): void
    {
        // Retirable (is_removable=1) a 0 : seul son supplement saute, le produit reste commandable.
        self::assertTrue(ProductRepository::isOrderable(true, [$this->line(['is_removable' => 1, 'stock_quantity' => 0])]));
    }

    public function testOneRequiredCriticalAmongManyBlocks(): void
    {
        self::assertFalse(ProductRepository::isOrderable(true, [
            $this->line(['stock_quantity' => 50]),                      // requis, ok
            $this->line(['is_removable' => 1, 'stock_quantity' => 0]),  // retirable critique, ok
            $this->line(['stock_quantity' => 5]),                       // requis critique -> bloque
        ]));
    }
}
