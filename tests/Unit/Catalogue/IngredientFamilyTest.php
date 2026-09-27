<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalogue;

use PHPUnit\Framework\TestCase;
use App\Catalogue\IngredientFamily;

/**
 * Liste canonique des familles d'ingredients (migration 0017), contrat fige :
 * dix slugs, dans cet ordre exact, avec leurs libelles francais. Verrouille
 * l'ordre (le formulaire ingredient l'affiche tel quel) et la validite/isFood(),
 * seule logique non triviale de cette classe pure (sans base).
 */
final class IngredientFamilyTest extends TestCase
{
    public function testOrderIsTheFrozenContract(): void
    {
        self::assertSame([
            'pain', 'viande', 'fromage', 'legume', 'sauce',
            'feculent', 'dose_boisson', 'contenant', 'dessert', 'dosette',
        ], IngredientFamily::slugs());
    }

    public function testLabelsAreFrenchAndKeyedInCanonicalOrder(): void
    {
        $labels = IngredientFamily::labels();

        self::assertSame(IngredientFamily::slugs(), array_keys($labels));
        self::assertSame('Pain', $labels['pain']);
        self::assertSame('Viande', $labels['viande']);
        self::assertSame('Fromage', $labels['fromage']);
        self::assertSame('Légume', $labels['legume']);
        self::assertSame('Sauce', $labels['sauce']);
        self::assertSame('Féculent', $labels['feculent']);
        self::assertSame('Dose boisson', $labels['dose_boisson']);
        self::assertSame('Contenant', $labels['contenant']);
        self::assertSame('Dessert', $labels['dessert']);
        self::assertSame('Dosette', $labels['dosette']);
    }

    public function testIsValidAcceptsOnlyCanonicalSlugs(): void
    {
        foreach (IngredientFamily::slugs() as $slug) {
            self::assertTrue(IngredientFamily::isValid($slug), $slug . ' doit etre valide');
        }
        self::assertFalse(IngredientFamily::isValid('boisson')); // pas 'dose_boisson'
        self::assertFalse(IngredientFamily::isValid(''));
        self::assertFalse(IngredientFamily::isValid('Pain')); // sensible a la casse, comme les slugs de categorie
    }

    public function testIsFoodIsFalseOnlyForContenant(): void
    {
        // "Gobelet" (ADR-0015) : materiau au contact des denrees, pas un aliment.
        self::assertFalse(IngredientFamily::isFood(IngredientFamily::CONTENANT));

        foreach (IngredientFamily::slugs() as $slug) {
            if ($slug === IngredientFamily::CONTENANT) {
                continue;
            }
            self::assertTrue(IngredientFamily::isFood($slug), $slug . ' doit etre un aliment');
        }

        // Non classe (null) : traite comme un aliment par defaut (meme degradation
        // sure que le filtrage par categorie -- non classe n'est pas presume "non
        // alimentaire").
        self::assertTrue(IngredientFamily::isFood(null));
    }
}
