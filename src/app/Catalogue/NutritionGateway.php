<?php

declare(strict_types=1);

namespace App\Catalogue;

/**
 * Passerelle vers une source nutritionnelle externe (Cr 3.a.3). Abstraction (seam)
 * qui permet d'injecter un double en test sans appel reseau reel. L'implementation
 * de production (OpenFoodFactsGateway) interroge une API tierce ; les tests
 * utilisent un double deterministe.
 */
interface NutritionGateway
{
    /**
     * Recherche un aliment par son nom et renvoie son apport energetique.
     *
     * @return array{energy_kcal_100g:int, source:string}|null null si rien de
     *         trouve ou en cas d'erreur (reseau, format) : l'appelant le signale
     *         sans casser le flux.
     */
    public function lookupByName(string $name): ?array;
}
