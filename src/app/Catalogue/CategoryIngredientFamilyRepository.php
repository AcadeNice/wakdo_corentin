<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Core\DatabaseInterface;

/**
 * Acces a la correspondance categorie -> familles d'ingredients autorisees
 * (migration 0017, table `category_ingredient_family`). Repository dedie plutot
 * qu'ajoute a CategoryRepository ou IngredientRepository : la table de jointure
 * ne porte ni des categories ni des ingredients, elle porte une REGLE qui relie
 * les deux -- un troisieme sous-domaine, meme raisonnement que
 * AllergenRepository pour `ingredient_allergen`.
 *
 * Seule la lecture est necessaire (F1 : le formulaire produit filtre son
 * selecteur, rien dans ce lot n'edite la correspondance depuis le back-office) ;
 * pas de create()/update() ici tant qu'aucun ecran n'en a besoin (Rasoir
 * d'Ockham, mantra #37).
 */
final class CategoryIngredientFamilyRepository
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Correspondance COMPLETE, groupee par categorie : id de categorie => liste
     * des slugs de familles autorisees. Une categorie SANS restriction (ex.
     * `menus`) est ABSENTE de ce tableau -- decision documentee (contrat fige) :
     * `array_key_exists()` distingue "aucune ligne, donc pas de filtre" d'une
     * liste vide qu'il faudrait alors interpreter comme "rien n'est autorise".
     *
     * @return array<int, list<string>>
     */
    public function mapByCategory(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT category_id, family FROM category_ingredient_family ORDER BY category_id, family',
        );

        $map = [];
        foreach ($rows as $row) {
            $categoryId = (int) ($row['category_id'] ?? 0);
            $map[$categoryId][] = (string) ($row['family'] ?? '');
        }

        return $map;
    }
}
