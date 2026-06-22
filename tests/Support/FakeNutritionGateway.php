<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Catalogue\NutritionGateway;

/**
 * Double de NutritionGateway : renvoie un resultat fixe et trace le nom recherche.
 * Permet de tester IngredientController::enrich sans appel reseau reel.
 */
final class FakeNutritionGateway implements NutritionGateway
{
    /** @var array{energy_kcal_100g:int, source:string}|null */
    public ?array $result = null;

    public ?string $lookedUp = null;

    public function lookupByName(string $name): ?array
    {
        $this->lookedUp = $name;

        return $this->result;
    }
}
