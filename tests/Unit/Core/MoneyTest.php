<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use App\Core\Money;

/**
 * Conversion euros <-> centimes des formulaires de prix (F40, section "Textes
 * techniques ou en anglais" de defauts-visibles.md). Source unique de validation
 * serveur (RG-T18) des champs price_cents/price_normal_cents/price_maxi_cents : la
 * base reste en centimes, la saisie et l'affichage en euros.
 */
final class MoneyTest extends TestCase
{
    public function testParsesCommaDecimalSeparator(): void
    {
        self::assertSame(190, Money::parseEurosToCents('1,90'));
    }

    public function testParsesDotDecimalSeparator(): void
    {
        self::assertSame(190, Money::parseEurosToCents('1.90'));
    }

    public function testParsesWholeEurosWithoutDecimals(): void
    {
        self::assertSame(500, Money::parseEurosToCents('5'));
    }

    public function testParsesSingleDecimalDigitAsTens(): void
    {
        // "1,9" = 1 EUR 90, pas 1 EUR 09 : la decimale manquante est completee a droite.
        self::assertSame(190, Money::parseEurosToCents('1,9'));
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        self::assertSame(890, Money::parseEurosToCents('  8,90  '));
    }

    public function testParsesOneEuroTen(): void
    {
        self::assertSame(110, Money::parseEurosToCents('1,10'));
    }

    public function testParsesTenCentsWrittenWithOneDecimal(): void
    {
        // "0,1" = 10 centimes (0 EUR 10), pas 1 centime : la decimale manquante est
        // completee a DROITE ("1" -> "10"), jamais a gauche ("1" -> "01").
        self::assertSame(10, Money::parseEurosToCents('0,1'));
    }

    public function testParsesNineteenNinetyNine(): void
    {
        self::assertSame(1999, Money::parseEurosToCents('19,99'));
    }

    public function testRejectsMoreThanTwoDecimals(): void
    {
        self::assertNull(Money::parseEurosToCents('1,900'));
    }

    public function testRejectsNegativeAmount(): void
    {
        self::assertNull(Money::parseEurosToCents('-1,90'));
    }

    public function testRejectsZero(): void
    {
        self::assertNull(Money::parseEurosToCents('0'));
    }

    public function testRejectsEmptyString(): void
    {
        self::assertNull(Money::parseEurosToCents(''));
    }

    public function testRejectsNonNumericText(): void
    {
        self::assertNull(Money::parseEurosToCents('abc'));
    }

    public function testRejectsAmountAboveTheUnsignedIntBound(): void
    {
        self::assertNull(Money::parseEurosToCents('99999999999'));
    }

    public function testCentsToEurosFormatsWithFrenchComma(): void
    {
        self::assertSame('1,90', Money::centsToEuros(190));
    }

    public function testCentsToEurosAlwaysShowsTwoDecimals(): void
    {
        self::assertSame('5,00', Money::centsToEuros(500));
    }

    public function testRoundTripPreservesTheAmount(): void
    {
        $cents = 1990;
        self::assertSame($cents, Money::parseEurosToCents(Money::centsToEuros($cents)));
    }
}
