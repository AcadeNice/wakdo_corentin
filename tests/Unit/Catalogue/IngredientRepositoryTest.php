<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalogue;

use PHPUnit\Framework\TestCase;
use App\Catalogue\IngredientRepository;

/**
 * Logique pure du calcul de stock (pourcentage + bande a 3 niveaux), sans base.
 * Le pourcentage et la bande sont CALCULES, jamais stockes (mcd 5.3) : ces deux
 * fonctions sont la source unique de cette derivation, reutilisee par all()/find()
 * et par les vues. Le stock peut etre negatif (survente assumee) -> bande critique.
 */
final class IngredientRepositoryTest extends TestCase
{
    public function testStockPctRoundsQuantityOverCapacity(): void
    {
        self::assertSame(50, IngredientRepository::stockPct(50, 100));
        self::assertSame(0, IngredientRepository::stockPct(0, 100));
        self::assertSame(100, IngredientRepository::stockPct(100, 100));
        self::assertSame(33, IngredientRepository::stockPct(1, 3));   // arrondi
        self::assertSame(-10, IngredientRepository::stockPct(-10, 100)); // survente
        // Cas ou l'arrondi MONTE : verrouille round() vs troncature/floor.
        self::assertSame(67, IngredientRepository::stockPct(2, 3));   // round(66.67) -> 67
        self::assertSame(13, IngredientRepository::stockPct(1, 8));   // round(12.5) -> 13 (half away from zero)
    }

    public function testStockPctGuardsAgainstZeroCapacity(): void
    {
        // stock_capacity porte un CHECK > 0 en base ; garde defensive si une ligne
        // aberrante arrive quand meme (pas de division par zero).
        self::assertSame(0, IngredientRepository::stockPct(10, 0));
    }

    public function testClampToCapacityCapsAtCapacityKeepingOversellNegative(): void
    {
        // Capacite = plafond STRICT (decision metier, retour oral) : stock_quantity ne
        // depasse jamais stock_capacity. Borne HAUTE uniquement -- la survente negative
        // (stock < 0) reste libre (signal manager), seul le depassement est cale.
        self::assertSame(300, IngredientRepository::clampToCapacity(340, 300)); // depassement -> plafond
        self::assertSame(300, IngredientRepository::clampToCapacity(300, 300)); // pile a 100 %
        self::assertSame(250, IngredientRepository::clampToCapacity(250, 300)); // sous le plafond -> inchange
        self::assertSame(-10, IngredientRepository::clampToCapacity(-10, 300)); // survente : borne basse libre
        self::assertSame(50, IngredientRepository::clampToCapacity(50, 0));      // capacite invalide -> pas de clamp
    }

    public function testStockBandNormalAboveLowThreshold(): void
    {
        self::assertSame('normal', IngredientRepository::stockBand(50, 100, 10, 5));
        self::assertSame('normal', IngredientRepository::stockBand(11, 100, 10, 5));
    }

    public function testStockBandLowAtOrUnderLowThreshold(): void
    {
        self::assertSame('low', IngredientRepository::stockBand(10, 100, 10, 5));
        self::assertSame('low', IngredientRepository::stockBand(6, 100, 10, 5));
    }

    public function testStockBandCriticalAtOrUnderCriticalThreshold(): void
    {
        self::assertSame('critical', IngredientRepository::stockBand(5, 100, 10, 5));
        self::assertSame('critical', IngredientRepository::stockBand(0, 100, 10, 5));
        self::assertSame('critical', IngredientRepository::stockBand(-3, 100, 10, 5)); // survente
    }

    public function testStockBandStressesIntegerArithmeticAtNonHundredCapacity(): void
    {
        // capacity != 100 : seuil low = 150*10/100 = 15, seuil critical = 150*5/100 = 7.5.
        // L'arithmetique entiere (quantity*100 <= capacity*pct) doit tomber juste sur
        // ces frontieres non rondes (sinon une mutation de la formule passerait).
        self::assertSame('low', IngredientRepository::stockBand(15, 150, 10, 5));      // 1500 <= 1500
        self::assertSame('normal', IngredientRepository::stockBand(16, 150, 10, 5));   // 1600 > 1500
        self::assertSame('critical', IngredientRepository::stockBand(7, 150, 10, 5));  // 700 <= 750
        self::assertSame('low', IngredientRepository::stockBand(8, 150, 10, 5));       // 800 > 750, <= 1500
    }
}
