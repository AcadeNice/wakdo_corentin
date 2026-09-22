<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalogue;

use PHPUnit\Framework\TestCase;
use App\Catalogue\OpenFoodFactsGateway;

/**
 * Parsing pur de la reponse OpenFoodFacts (Cr 3.a.3), isole du reseau :
 * parse() prend une string et ne touche jamais cURL, donc tout est testable
 * hors-ligne. On verrouille ici le contrat de l'extraction nutritionnelle :
 *   - guards de structure (JSON, products[0].nutriments) -> null ;
 *   - guard is_numeric sur energy-kcal_100g -> null ;
 *   - garde de domaine sur la valeur, alignee sur la colonne energy_kcal_100g
 *     (SMALLINT UNSIGNED, plage 0..65535 ; migration 0005) ;
 *   - forme du tableau retourne.
 *
 * Note de comportement (a confirmer cote produit) : le code NE CLAMPE PAS une
 * valeur hors plage. Une valeur < 0 ou > 65535 fait retourner null (rejet),
 * pas un rabotage a 0 ou 65535. Les cas ci-dessous testent ce comportement
 * REEL ; voir testRejectsValueAboveSmallintMax / testRejectsNegativeValue.
 */
final class OpenFoodFactsGatewayTest extends TestCase
{
    private function gateway(): OpenFoodFactsGateway
    {
        return new OpenFoodFactsGateway();
    }

    /**
     * Encode un corps de reponse OpenFoodFacts minimal autour d'une valeur de
     * nutriments, pour eviter de repeter l'enveloppe products[0] dans chaque test.
     *
     * @param array<string, mixed> $nutriments
     */
    private function bodyWithNutriments(array $nutriments): string
    {
        $json = json_encode([
            'products' => [
                ['product_name' => 'Test', 'nutriments' => $nutriments],
            ],
        ]);

        // json_encode d'un tableau structure ne peut pas echouer ici ; le cast
        // satisfait l'analyse statique (parse() exige une string).
        return (string) $json;
    }

    public function testExtractsKcalFromValidBody(): void
    {
        $result = $this->gateway()->parse($this->bodyWithNutriments(['energy-kcal_100g' => 250]));

        self::assertSame(['energy_kcal_100g' => 250, 'source' => 'OpenFoodFacts'], $result);
    }

    public function testRoundsFloatKcalToNearestInteger(): void
    {
        // (int) round((float) $kcal) : 254.6 -> 255 (round half away from zero).
        $result = $this->gateway()->parse($this->bodyWithNutriments(['energy-kcal_100g' => 254.6]));

        self::assertNotNull($result);
        self::assertSame(255, $result['energy_kcal_100g']);
    }

    public function testAcceptsNumericStringKcal(): void
    {
        // is_numeric() accepte une chaine numerique ("314") ; l'API peut renvoyer
        // la valeur encodee en string selon le produit.
        $result = $this->gateway()->parse($this->bodyWithNutriments(['energy-kcal_100g' => '314']));

        self::assertNotNull($result);
        self::assertSame(314, $result['energy_kcal_100g']);
    }

    public function testAcceptsUpperBoundValue(): void
    {
        // 65535 = max SMALLINT UNSIGNED : DANS la plage (la garde est < 0 || > 65535),
        // donc accepte tel quel.
        $result = $this->gateway()->parse($this->bodyWithNutriments(['energy-kcal_100g' => 65535]));

        self::assertNotNull($result);
        self::assertSame(65535, $result['energy_kcal_100g']);
    }

    public function testRejectsValueAboveSmallintMax(): void
    {
        // CIBLE 1 cas (2). Comportement REEL : > 65535 n'est PAS rabote a 65535,
        // il est REJETE (return null). La garde protege la colonne SMALLINT
        // UNSIGNED en refusant une valeur qui ne tiendrait pas (migration 0005).
        $result = $this->gateway()->parse($this->bodyWithNutriments(['energy-kcal_100g' => 70000]));

        self::assertNull($result);
    }

    public function testRejectsNegativeValue(): void
    {
        // CIBLE 1 cas (3). Comportement REEL : une valeur negative n'est PAS
        // ramenee a 0, elle est REJETEE (return null). Un apport energetique
        // negatif est aberrant et ne tiendrait pas dans un UNSIGNED.
        $result = $this->gateway()->parse($this->bodyWithNutriments(['energy-kcal_100g' => -5]));

        self::assertNull($result);
    }

    public function testReturnsNullWhenKcalNotNumeric(): void
    {
        // CIBLE 1 cas (4). "N/A" echoue is_numeric() -> null (champ present mais
        // non exploitable).
        $result = $this->gateway()->parse($this->bodyWithNutriments(['energy-kcal_100g' => 'N/A']));

        self::assertNull($result);
    }

    public function testReturnsNullWhenKcalFieldAbsent(): void
    {
        // nutriments present mais sans la cle energy-kcal_100g : le coalesce ?? null
        // donne null, puis is_numeric(null) est faux -> null.
        $result = $this->gateway()->parse($this->bodyWithNutriments(['fat_100g' => 12]));

        self::assertNull($result);
    }

    public function testReturnsNullWhenNutrimentsAbsent(): void
    {
        // CIBLE 1 cas (5). products[0] sans nutriments -> guard isset() faux -> null.
        $body = (string) json_encode(['products' => [['product_name' => 'Test']]]);

        self::assertNull($this->gateway()->parse($body));
    }

    public function testReturnsNullWhenNutrimentsNotArray(): void
    {
        // nutriments present mais scalaire : le guard is_array() le rejette.
        $body = (string) json_encode(['products' => [['nutriments' => 'oops']]]);

        self::assertNull($this->gateway()->parse($body));
    }

    public function testReturnsNullWhenNoProducts(): void
    {
        // Recherche sans resultat : products vide -> products[0] absent -> null.
        $body = (string) json_encode(['products' => []]);

        self::assertNull($this->gateway()->parse($body));
    }

    public function testReturnsNullOnInvalidJson(): void
    {
        // CIBLE 1 cas (6). json_decode rend null sur un corps mal forme ;
        // is_array(null) est faux -> null (tolerance aux pannes, cf. classe).
        self::assertNull($this->gateway()->parse('{not valid json'));
    }

    public function testReturnsNullOnEmptyBody(): void
    {
        // Corps vide : json_decode('') rend null -> is_array faux -> null.
        self::assertNull($this->gateway()->parse(''));
    }

    public function testReturnsNullWhenJsonIsScalar(): void
    {
        // Un JSON valide mais scalaire (pas un objet/tableau associatif) :
        // is_array(decode) est faux -> null.
        self::assertNull($this->gateway()->parse('42'));
    }
}
