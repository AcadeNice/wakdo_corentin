<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use App\Core\NumericInput;

/**
 * Source UNIQUE de validation des entiers de stock (packs/comptage/ajustement),
 * partagee entre `IngredientController` (HTML) et `IngredientApiController`
 * (JSON, via `JsonApiTrait::fieldInt()`). Relecture adverse (2e passe, point 5) :
 * ce fichier n'existait pas alors qu'ADR-0017 le citait deja comme preuve de
 * test -- corrige ici (le mensonge documentaire est corrige en meme temps que
 * le code manquant, pas seulement le texte).
 */
final class NumericInputTest extends TestCase
{
    // --- digits() : entier NON signe ---

    public function testDigitsAcceptsNativeIntInRange(): void
    {
        self::assertSame(5, NumericInput::digits(5, 1, 100));
    }

    public function testDigitsAcceptsDigitString(): void
    {
        self::assertSame(5, NumericInput::digits('5', 1, 100));
    }

    public function testDigitsRejectsEmptyString(): void
    {
        self::assertNull(NumericInput::digits('', 1, 100));
    }

    public function testDigitsRejectsSpaces(): void
    {
        // ctype_digit() refuse tout caractere non-chiffre, y compris un espace de
        // bourrage : NumericInput ne trim() pas lui-meme (c'est a l'appelant de le
        // faire s'il le souhaite, cf. les 3 appels HTML qui trim() avant appel).
        self::assertNull(NumericInput::digits(' 5', 1, 100));
        self::assertNull(NumericInput::digits('5 ', 1, 100));
        self::assertNull(NumericInput::digits(' 5 ', 1, 100));
    }

    public function testDigitsRejectsLeadingPlusSign(): void
    {
        self::assertNull(NumericInput::digits('+5', 1, 100));
    }

    public function testDigitsAcceptsLeadingZero(): void
    {
        // "05" est all-digits (ctype_digit vrai) : cast (int) en 5, valeur normale.
        self::assertSame(5, NumericInput::digits('05', 1, 100));
    }

    public function testDigitsRejectsNegativeZeroString(): void
    {
        // "-0" n'est pas all-digits (le signe n'est pas un chiffre) : digits() (non
        // signe) le refuse -- seul signedDigits() l'accepte (cf. plus bas).
        self::assertNull(NumericInput::digits('-0', 0, 100));
    }

    public function testDigitsRejectsDecimalPoint(): void
    {
        self::assertNull(NumericInput::digits('1.5', 1, 100));
        self::assertNull(NumericInput::digits(1.5, 1, 100));
    }

    public function testDigitsRejectsScientificNotation(): void
    {
        self::assertNull(NumericInput::digits('1e3', 1, 10000));
    }

    public function testDigitsRespectsBounds(): void
    {
        self::assertNull(NumericInput::digits(0, 1, 10));
        self::assertSame(1, NumericInput::digits(1, 1, 10));
        self::assertSame(10, NumericInput::digits(10, 1, 10));
        self::assertNull(NumericInput::digits(11, 1, 10));
    }

    public function testDigitsRejectsVeryLargeNumberBeyondRange(): void
    {
        // Chaine all-digits de 30 caracteres : ctype_digit() ne regarde pas la
        // magnitude, mais le garde-fou min/max rattrape le cas (la valeur castee,
        // meme si le cast (int) deborde, reste hors de l'intervalle autorise).
        self::assertNull(NumericInput::digits(str_repeat('9', 30), 1, 2147483647));
    }

    public function testDigitsRejectsTrailingNewline(): void
    {
        self::assertNull(NumericInput::digits("5\n", 1, 100));
    }

    public function testDigitsRejectsBoolean(): void
    {
        self::assertNull(NumericInput::digits(true, 1, 100));
        self::assertNull(NumericInput::digits(false, 0, 100));
    }

    public function testDigitsRejectsJsonFloat(): void
    {
        // Un flottant JSON natif (json_decode('2.9') === 2.9, type PHP float) :
        // ni is_int() ni is_string() ne matchent -> refuse.
        self::assertNull(NumericInput::digits(2.9, 1, 100));
    }

    public function testDigitsRejectsArabicIndicDigit(): void
    {
        // "٥" (U+0665, chiffre arabe-indien "5") : ctype_digit() ne reconnait que
        // les chiffres ASCII 0-9 (verification octet par octet), pas les chiffres
        // Unicode d'autres systemes d'ecriture.
        self::assertNull(NumericInput::digits("\u{0665}", 1, 100));
    }

    public function testDigitsRejectsArray(): void
    {
        self::assertNull(NumericInput::digits(['5'], 1, 100));
    }

    public function testDigitsRejectsNull(): void
    {
        self::assertNull(NumericInput::digits(null, 1, 100));
    }

    // --- signedDigits() : entier SIGNE ---

    public function testSignedDigitsAcceptsNativeIntInRange(): void
    {
        self::assertSame(-5, NumericInput::signedDigits(-5, -100, 100));
    }

    public function testSignedDigitsAcceptsNegativeDigitString(): void
    {
        self::assertSame(-5, NumericInput::signedDigits('-5', -100, 100));
    }

    public function testSignedDigitsAcceptsPositiveDigitStringWithoutSign(): void
    {
        self::assertSame(5, NumericInput::signedDigits('5', -100, 100));
    }

    public function testSignedDigitsRejectsEmptyString(): void
    {
        self::assertNull(NumericInput::signedDigits('', -100, 100));
    }

    public function testSignedDigitsRejectsSpaces(): void
    {
        self::assertNull(NumericInput::signedDigits(' 5', -100, 100));
        self::assertNull(NumericInput::signedDigits('-5 ', -100, 100));
    }

    public function testSignedDigitsRejectsLeadingPlusSign(): void
    {
        self::assertNull(NumericInput::signedDigits('+5', -100, 100));
    }

    public function testSignedDigitsAcceptsLeadingZero(): void
    {
        self::assertSame(5, NumericInput::signedDigits('05', -100, 100));
        self::assertSame(-5, NumericInput::signedDigits('-05', -100, 100));
    }

    public function testSignedDigitsAcceptsNegativeZeroString(): void
    {
        // "-0" matche `/^-?\d+$/D` (un signe suivi d'un ou plusieurs chiffres) ;
        // (int) "-0" === 0, une valeur signee valide dans l'absolu (le rejet de 0
        // specifique a l'ajustement de stock est fait par l'APPELANT, pas ici).
        self::assertSame(0, NumericInput::signedDigits('-0', -100, 100));
    }

    public function testSignedDigitsRejectsDecimalPoint(): void
    {
        self::assertNull(NumericInput::signedDigits('-1.5', -100, 100));
        self::assertNull(NumericInput::signedDigits(1.5, -100, 100));
    }

    public function testSignedDigitsRejectsScientificNotation(): void
    {
        self::assertNull(NumericInput::signedDigits('1e3', -10000, 10000));
    }

    public function testSignedDigitsRespectsBounds(): void
    {
        self::assertNull(NumericInput::signedDigits(-11, -10, 10));
        self::assertSame(-10, NumericInput::signedDigits(-10, -10, 10));
        self::assertSame(10, NumericInput::signedDigits(10, -10, 10));
        self::assertNull(NumericInput::signedDigits(11, -10, 10));
    }

    public function testSignedDigitsRejectsVeryLargeNumberBeyondRange(): void
    {
        self::assertNull(NumericInput::signedDigits('-' . str_repeat('9', 30), -2147483647, 2147483647));
    }

    /**
     * Piege PCRE corrige (relecture point 6) : sans le modificateur `D`, `$` en
     * PCRE matche aussi juste AVANT un `\n` final, donc `/^-?\d+$/` (sans `D`)
     * acceptait a tort `"5\n"`. Verifie SANS changement de comportement HTML
     * (`git diff origin/dev` sur `IngredientController.php`) : les 3 appelants
     * HTML (`restock`/`inventory`/`adjust`) font deja `trim()` sur la valeur
     * AVANT d'appeler `NumericInput` -- `trim()` retire deja tout `\n` final,
     * donc durcir la regle ne change rien pour eux. Seul un appel direct du
     * corps JSON (sans `trim()`) pouvait heurter ce piege.
     */
    public function testSignedDigitsRejectsTrailingNewline(): void
    {
        self::assertNull(NumericInput::signedDigits("5\n", -100, 100));
        self::assertNull(NumericInput::signedDigits("-5\n", -100, 100));
    }

    public function testSignedDigitsRejectsBoolean(): void
    {
        self::assertNull(NumericInput::signedDigits(true, -100, 100));
        self::assertNull(NumericInput::signedDigits(false, -100, 100));
    }

    public function testSignedDigitsRejectsJsonFloat(): void
    {
        self::assertNull(NumericInput::signedDigits(-2.9, -100, 100));
    }

    public function testSignedDigitsRejectsArabicIndicDigit(): void
    {
        self::assertNull(NumericInput::signedDigits("\u{0665}", -100, 100));
    }

    public function testSignedDigitsRejectsArray(): void
    {
        self::assertNull(NumericInput::signedDigits(['5'], -100, 100));
    }

    public function testSignedDigitsRejectsNull(): void
    {
        self::assertNull(NumericInput::signedDigits(null, -100, 100));
    }
}
