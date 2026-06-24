<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Core\DatabaseInterface;

/**
 * Lecture des allergenes a declaration obligatoire (INCO) : info GENERALE (les 14
 * categories), pas un calcul par produit (le mapping ingredient_allergen reste
 * differe). Sert l'endpoint public anonyme /api/allergens. Le schema porte
 * code + name + description ; la description (texte INCO seede) est exposee par
 * l'API et consommee par la borne via /api/allergens.
 *
 * Non `final` : seam de test (sous-classe -> double sans base).
 */
class AllergenRepository
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Les allergenes references, tries par id (ordre INCO du seed).
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->fetchAll('SELECT id, code, name, description FROM allergen ORDER BY id');
    }
}
