<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Core\DatabaseInterface;

/**
 * Lecture des allergenes a declaration obligatoire (INCO). Deux niveaux :
 *
 *  - le CATALOGUE des 14 categories (all()), reference reglementaire servie par
 *    /api/allergens et affichee en texte d'aide ;
 *  - les allergenes CALCULES par produit (byProduct, forProduct), derives de la
 *    chaine product_ingredient -> ingredient_allergen -> allergen. C'est ce que
 *    pose le dictionnaire 3.8 : aucune ressaisie par produit, la donnee vit sur
 *    l'ingredient et remonte par la recette.
 *
 * L'etat de REVUE (unreviewedByProduct, unreviewedCountForProduct) accompagne
 * toujours le calcul. Sans lui, un produit sans ligne d'allergene serait ambigu :
 * "verifie, aucun des 14" et "personne n'a encore regarde" se ressemblent dans
 * les donnees mais doivent produire deux messages differents a l'ecran. La
 * colonne ingredient.allergens_reviewed_at (migration 0011) porte la distinction.
 *
 * Chaque lecture de liste est groupee en UNE requete : le catalogue borne affiche
 * 58 produits, une requete par produit serait un N+1 sur le chemin le plus chaud.
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

    /**
     * Allergenes calcules de TOUS les produits, groupes par product_id.
     *
     * Le GROUP BY porte la deduplication : deux ingredients d'un meme produit
     * peuvent porter le meme allergene (le pain et la panure apportent tous deux du
     * gluten) et la borne n'a pas a afficher "Gluten" deux fois. GROUP BY plutot que
     * DISTINCT pour ne pas se confondre avec ProductRepository::autoUnavailableIds(),
     * qui lit les memes tables avec un SELECT DISTINCT.
     *
     * Un produit sans aucun allergene mappe est ABSENT du tableau -- l'appelant croise
     * avec unreviewedByProduct() pour savoir si cette absence veut dire "aucun" ou
     * "pas encore regarde".
     *
     * @return array<int, list<array{id: int, code: string, name: string}>>
     */
    public function byProduct(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT pi.product_id, a.id AS allergen_id, a.code, a.name '
            . 'FROM product_ingredient pi '
            . 'JOIN ingredient_allergen ia ON ia.ingredient_id = pi.ingredient_id '
            . 'JOIN allergen a ON a.id = ia.allergen_id '
            . 'GROUP BY pi.product_id, a.id, a.code, a.name '
            . 'ORDER BY pi.product_id, a.id',
        );

        $byProduct = [];
        foreach ($rows as $row) {
            $byProduct[(int) ($row['product_id'] ?? 0)][] = $this->present($row);
        }

        return $byProduct;
    }

    /**
     * Allergenes calcules d'UN produit. Meme derivation que byProduct(), bornee a un
     * produit -- pendant de ProductRepository::sizesForProduct() face a sizesByBase().
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    public function forProduct(int $productId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT a.id AS allergen_id, a.code, a.name '
            . 'FROM product_ingredient pi '
            . 'JOIN ingredient_allergen ia ON ia.ingredient_id = pi.ingredient_id '
            . 'JOIN allergen a ON a.id = ia.allergen_id '
            . 'WHERE pi.product_id = :id '
            . 'GROUP BY a.id, a.code, a.name ORDER BY a.id',
            ['id' => $productId],
        );

        return array_map(fn (array $row): array => $this->present($row), $rows);
    }

    /**
     * Pour chaque produit, le nombre de ses ingredients dont les allergenes n'ont PAS
     * ete revus. La requete ne remonte que les produits concernes : un produit absent
     * du tableau est integralement revu, sa liste d'allergenes est donc affirmable.
     *
     * @return array<int, int>
     */
    public function unreviewedByProduct(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT pi.product_id, COUNT(*) AS unreviewed '
            . 'FROM product_ingredient pi '
            . 'JOIN ingredient i ON i.id = pi.ingredient_id '
            . 'WHERE i.allergens_reviewed_at IS NULL '
            . 'GROUP BY pi.product_id',
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) ($row['product_id'] ?? 0)] = (int) ($row['unreviewed'] ?? 0);
        }

        return $counts;
    }

    /**
     * Nombre d'ingredients non revus d'UN produit. 0 = information affirmable.
     */
    public function unreviewedCountForProduct(int $productId): int
    {
        return (int) ($this->db->fetch(
            'SELECT COUNT(*) AS n FROM product_ingredient pi '
            . 'JOIN ingredient i ON i.id = pi.ingredient_id '
            . 'WHERE pi.product_id = :id AND i.allergens_reviewed_at IS NULL',
            ['id' => $productId],
        )['n'] ?? 0);
    }

    /**
     * Ids des allergenes mappes sur un ingredient. Sert a precocher les cases du
     * formulaire back-office : la vue compare en strict, d'ou le cast entier.
     *
     * @return list<int>
     */
    public function allergenIdsForIngredient(int $ingredientId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT allergen_id FROM ingredient_allergen WHERE ingredient_id = :id ORDER BY allergen_id',
            ['id' => $ingredientId],
        );

        return array_map(static fn (array $row): int => (int) ($row['allergen_id'] ?? 0), $rows);
    }

    /**
     * Typage explicite de sortie : la valeur JSON ne depend pas du mode de fetch PDO
     * (l'id reste un entier), meme discipline que CatalogueController::present*.
     *
     * @param array<string, mixed> $row
     * @return array{id: int, code: string, name: string}
     */
    private function present(array $row): array
    {
        return [
            'id'   => (int) ($row['allergen_id'] ?? 0),
            'code' => (string) ($row['code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
        ];
    }
}
