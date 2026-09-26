<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Source UNIQUE de validation des entiers de stock (packs, comptage, ajustement),
 * partagee entre les formulaires HTML (`IngredientController`, valeurs toujours
 * chaines via `Request::formBody()`) et l'API JSON (`IngredientApiController`,
 * valeurs eventuellement entieres natives via `Request::json()`).
 *
 * Meme regle des deux cotes : une chaine de chiffres (`ctype_digit`, positif
 * uniquement) ou un entier JSON natif ; un flottant (`2.9`), une notation
 * scientifique (`"1e3"`), une chaine avec separateur decimal (`"1.5"`), un
 * booleen ou un tableau sont REFUSES (`null`), jamais tronques ni castes en
 * silence. `signedDigits()` ajoute le signe optionnel pour l'ajustement libre
 * (delta).
 */
final class NumericInput
{
    private function __construct()
    {
    }

    /**
     * Entier NON signe dans `[min, max]`. `$raw` est une chaine (deja `trim()`
     * par l'appelant) ou une valeur JSON decodee quelconque.
     */
    public static function digits(mixed $raw, int $min, int $max): ?int
    {
        if (is_int($raw)) {
            return ($raw >= $min && $raw <= $max) ? $raw : null;
        }

        if (is_string($raw) && $raw !== '' && ctype_digit($raw)) {
            $value = (int) $raw;

            return ($value >= $min && $value <= $max) ? $value : null;
        }

        return null;
    }

    /**
     * Entier SIGNE dans `[min, max]` (signe `-` optionnel). Pour l'ajustement
     * libre de stock : l'appelant rejette en plus la valeur 0 (une correction de
     * delta nul n'a pas de sens), ce que cette fonction ne fait pas elle-meme
     * (0 est un entier signe valide dans l'absolu).
     *
     * Modificateur `D` (relecture point 6) : sans lui, `$` en PCRE matche aussi
     * juste AVANT un `\n` final (`"5\n"` matcherait `/^-?\d+$/`), un piege connu.
     * Verifie sans changement de comportement HTML (`git diff origin/dev`) : les
     * 3 appelants HTML (`IngredientController::restock/inventory/adjust`) font
     * deja `trim()` sur la valeur AVANT de l'appeler -- `trim()` retire deja tout
     * `\n` final, donc cette regle plus stricte ne change rien pour eux. Seul
     * l'appel direct du corps JSON (sans `trim()`, `App\Controllers\Admin\Api\
     * JsonApiTrait::fieldInt()`) pouvait heurter ce piege.
     */
    public static function signedDigits(mixed $raw, int $min, int $max): ?int
    {
        if (is_int($raw)) {
            return ($raw >= $min && $raw <= $max) ? $raw : null;
        }

        if (is_string($raw) && preg_match('/^-?\d+$/D', $raw) === 1) {
            $value = (int) $raw;

            return ($value >= $min && $value <= $max) ? $value : null;
        }

        return null;
    }
}
