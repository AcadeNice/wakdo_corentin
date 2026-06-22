<?php

declare(strict_types=1);

namespace App\Catalogue;

/**
 * Implementation de NutritionGateway sur l'API publique OpenFoodFacts (Cr 3.a.3 :
 * exploiter dans le modele des informations externes provenant d'une API). Recherche
 * un aliment par nom et renvoie son apport energetique (kcal / 100 g). Lecture seule,
 * sans cle d'API.
 *
 * Egress maitrise : invoquee UNIQUEMENT sur action explicite d'un manager/admin
 * (IngredientController::enrich), jamais au runtime de la borne. `allow_url_fopen`
 * est desactive (php.ini durci) : on passe par cURL. Tolerante aux pannes : toute
 * erreur (reseau, code HTTP, JSON, champ absent) renvoie null -> l'appelant affiche
 * un message sans interrompre l'usage.
 */
final class OpenFoodFactsGateway implements NutritionGateway
{
    private const ENDPOINT = 'https://world.openfoodfacts.org/cgi/search.pl';

    public function __construct(private readonly int $timeoutSeconds = 5)
    {
    }

    public function lookupByName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '' || !function_exists('curl_init')) {
            return null;
        }

        $url = self::ENDPOINT . '?' . http_build_query([
            'search_terms'  => $name,
            'search_simple' => 1,
            'action'        => 'process',
            'json'          => 1,
            'page_size'     => 1,
            'fields'        => 'product_name,nutriments',
        ]);

        $handle = curl_init($url);
        if ($handle === false) {
            return null;
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_USERAGENT      => 'Wakdo/1.0 (projet pedagogique RNCP)',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body   = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (!is_string($body) || $status < 200 || $status >= 300) {
            return null;
        }

        return $this->parse($body);
    }

    /**
     * Extrait l'apport energetique du premier produit de la reponse OpenFoodFacts.
     * Isole pour etre testable sans reseau.
     *
     * @return array{energy_kcal_100g:int, source:string}|null
     */
    public function parse(string $body): ?array
    {
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['products'][0]['nutriments'])
            || !is_array($data['products'][0]['nutriments'])) {
            return null;
        }

        $kcal = $data['products'][0]['nutriments']['energy-kcal_100g'] ?? null;
        if (!is_numeric($kcal)) {
            return null;
        }

        $kcal = (int) round((float) $kcal);
        if ($kcal < 0 || $kcal > 65535) {
            return null;
        }

        return ['energy_kcal_100g' => $kcal, 'source' => 'OpenFoodFacts'];
    }
}
