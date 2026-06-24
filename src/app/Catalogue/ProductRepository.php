<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Core\DatabaseInterface;

/**
 * Acces aux donnees de la table product (sous-domaine Catalogue). Suit le pattern
 * etabli par CategoryRepository.
 *
 * Topologie des FK entrantes sur product(id) (db/migrations/0001) et effet sur la
 * suppression dure :
 *  - RESTRICT (bloquent la suppression) : order_item, menu.burger_product_id,
 *    menu_slot_option, order_item_selection. Le controleur attrape la violation
 *    (SQLSTATE 23000) -> 409 Conflit, plutot que de pre-tester chaque reference.
 *  - CASCADE : product_ingredient (la recette appartient au produit ; la
 *    supprimer avec le produit est voulu). La suppression n'est donc PAS bloquee
 *    par une recette existante. Le nombre de lignes cascade-supprimees est compte
 *    (compositionCount) et trace dans le resume d'audit par ProductController::destroy
 *    (dette #27 close) pour ne laisser aucune perte hors-trace.
 */
final class ProductRepository
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Liste pour le back-office, avec le libelle de categorie.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->fetchAll(
            'SELECT p.id, p.category_id, p.name, p.price_cents, p.vat_rate, p.is_available, '
            . 'p.display_order, c.name AS category_name '
            . 'FROM product p JOIN category c ON c.id = p.category_id '
            . 'ORDER BY p.display_order, p.name',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        // maxi_variant_product_id : expose la variante Grande de l'accompagnement
        // pour que OrderRepository::resolveSelections puisse substituer au format
        // Maxi (cote serveur uniquement ; la borne n'en a pas besoin).
        return $this->db->fetch(
            'SELECT id, category_id, name, description, price_cents, maxi_variant_product_id, '
            . 'vat_rate, image_path, is_available, display_order FROM product WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * Lecture publique pour la borne (P4, docs/api/conventions.md 5.2) : produits
     * commandables seulement (is_available = 1) ET dont la categorie est active
     * (c.is_active = 1), pour ne jamais proposer un produit dont l'onglet de
     * categorie n'apparait pas. vat_rate n'est pas selectionne : le calcul fiscal
     * vit cote serveur a la commande, la borne ne l'affiche pas. Filtre de
     * disponibilite = flag is_available (retrait manuel) ; la dispo CALCULEE RG-T21
     * (rupture par stock) n'exclut PAS la ligne ici : CatalogueController la croise
     * avec autoUnavailableIds() pour exposer is_orderable, et la borne grise la tuile
     * (visible mais non commandable) au lieu de la masquer.
     *
     * base_product_id IS NULL (R4) : les VARIANTES de taille (ex. "Coca Cola 50cl")
     * ne sont jamais des tuiles catalogue autonomes ; elles sont atteintes via le
     * picker de taille de la base, qui les expose par sizesForProduct(). size_cl est
     * remonte pour que le controleur sache quelles bases portent une dimension taille.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableForCatalogue(): array
    {
        // mv.name (LEFT JOIN sur la variante Maxi) : la borne affiche ce nom quand le
        // menu est commande en Maxi, sans refaire un aller-retour pour resoudre la
        // variante. NULL si le produit n'a pas de variante Maxi. La SUBSTITUTION reelle
        // a la commande reste serveur (OrderRepository::resolveSelections) ; ici c'est
        // un libelle d'affichage seulement.
        return $this->db->fetchAll(
            'SELECT p.id, p.category_id, p.name, p.description, p.price_cents, p.size_cl, '
            . 'p.image_path, p.display_order, mv.name AS maxi_variant_name '
            . 'FROM product p JOIN category c ON c.id = p.category_id '
            . 'LEFT JOIN product mv ON mv.id = p.maxi_variant_product_id '
            . 'WHERE p.is_available = 1 AND c.is_active = 1 AND p.base_product_id IS NULL '
            . 'ORDER BY p.display_order, p.name',
        );
    }

    /**
     * Tailles commandables d'un produit de base (R4) : la base elle-meme + ses
     * variantes de taille disponibles, triees par volume croissant (30 cl puis
     * 50 cl). Chaque ligne porte son propre product_id et son price_cents : la
     * borne resout la taille choisie en product_id, le domaine commande facture
     * ce product_id sans logique de taille (flux inchange). Seules les variantes
     * disponibles (is_available = 1) sont remontees ; la base est toujours incluse
     * (l'appelant ne demande les tailles que pour une base deja affichable).
     * NULLs de size_cl tries en premier (la base sans dimension n'a pas de variante,
     * ce cas ne remonte qu'une ligne).
     *
     * @return array<int, array<string, mixed>>
     */
    public function sizesForProduct(int $baseId): array
    {
        return $this->db->fetchAll(
            'SELECT id, size_cl, price_cents FROM product '
            . 'WHERE (id = :base OR base_product_id = :base) AND is_available = 1 '
            . 'ORDER BY size_cl IS NULL DESC, size_cl, id',
            ['base' => $baseId],
        );
    }

    /**
     * Toutes les tailles des produits AYANT au moins une variante de taille (R4),
     * indexees par id de la base, en UNE requete (evite le N+1 sur la liste
     * /api/products, cache-friendly cote borne). Ne remonte que les bases dont une
     * variante existe : un produit mono-taille n'apparait pas (le controleur lui
     * laisse alors un tableau sizes vide). La base est incluse parmi ses tailles.
     * Lignes triees par base puis volume croissant (30 cl avant 50 cl).
     *
     * @return array<int, list<array<string, mixed>>> base_id => [{id, size_cl, price_cents}, ...]
     */
    public function sizesByBase(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT COALESCE(p.base_product_id, p.id) AS base_id, p.id, p.size_cl, p.price_cents '
            . 'FROM product p '
            . 'WHERE p.is_available = 1 AND ('
            . '    p.base_product_id IS NOT NULL '
            . '    OR EXISTS (SELECT 1 FROM product v WHERE v.base_product_id = p.id AND v.is_available = 1)'
            . ') '
            . 'ORDER BY base_id, p.size_cl IS NULL DESC, p.size_cl, p.id',
        );

        /** @var array<int, list<array<string, mixed>>> $byBase */
        $byBase = [];
        foreach ($rows as $row) {
            $baseId = (int) ($row['base_id'] ?? 0);
            $byBase[$baseId][] = [
                'id'          => (int) ($row['id'] ?? 0),
                'size_cl'     => (int) ($row['size_cl'] ?? 0),
                'price_cents' => (int) ($row['price_cents'] ?? 0),
            ];
        }

        return $byBase;
    }

    /**
     * Detail produit pour la borne : la meme projection que la liste, et seulement
     * si le produit est commandable (is_available = 1) en categorie active ; sinon
     * null (le controleur rend 404). Un produit retire ou en categorie masquee est
     * donc invisible meme par lien direct.
     *
     * base_product_id IS NULL (R4) : meme invariant que availableForCatalogue() --
     * une VARIANTE de taille n'est jamais une fiche detail autonome. Un acces direct
     * a /api/products/{idVariante} rend donc null -> 404 ; la 50 cl ne s'atteint que
     * via le picker de taille de sa base, jamais par lien direct.
     *
     * @return array<string, mixed>|null
     */
    public function findForCatalogue(int $id): ?array
    {
        // Meme projection (et meme LEFT JOIN variante Maxi) que la liste : la borne
        // recoit maxi_variant_name aussi par lien direct (NULL si pas de variante).
        return $this->db->fetch(
            'SELECT p.id, p.category_id, p.name, p.description, p.price_cents, '
            . 'p.image_path, p.display_order, mv.name AS maxi_variant_name '
            . 'FROM product p JOIN category c ON c.id = p.category_id '
            . 'LEFT JOIN product mv ON mv.id = p.maxi_variant_product_id '
            . 'WHERE p.id = :id AND p.is_available = 1 AND c.is_active = 1 AND p.base_product_id IS NULL',
            ['id' => $id],
        );
    }

    public function categoryExists(int $categoryId): bool
    {
        return $this->db->fetch('SELECT id FROM category WHERE id = :id', ['id' => $categoryId]) !== null;
    }

    /**
     * @param array{category_id: int, name: string, description: ?string, price_cents: int, vat_rate: int, image_path: ?string, is_available: int, display_order: int} $data
     */
    public function create(array $data): void
    {
        $this->db->execute(
            'INSERT INTO product (category_id, name, description, price_cents, vat_rate, image_path, is_available, display_order) '
            . 'VALUES (:category, :name, :description, :price, :vat, :image, :available, :ord)',
            $this->bind($data),
        );
    }

    /**
     * @param array{category_id: int, name: string, description: ?string, price_cents: int, vat_rate: int, image_path: ?string, is_available: int, display_order: int} $data
     */
    public function update(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE product SET category_id = :category, name = :name, description = :description, '
            . 'price_cents = :price, vat_rate = :vat, image_path = :image, is_available = :available, '
            . 'display_order = :ord WHERE id = :id',
            $this->bind($data) + ['id' => $id],
        );
    }

    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM product WHERE id = :id', ['id' => $id]);
    }

    public function ingredientExists(int $id): bool
    {
        return $this->db->fetch('SELECT id FROM ingredient WHERE id = :id', ['id' => $id]) !== null;
    }

    /**
     * Composition (recette) d'un produit : lignes product_ingredient enrichies du
     * nom + de l'unite de l'ingredient et de ses champs de stock (pour la
     * disponibilite calculee RG-T21). Ordonnee par nom d'ingredient.
     *
     * @return array<int, array<string, mixed>>
     */
    public function composition(int $productId): array
    {
        return $this->db->fetchAll(
            'SELECT pi.product_id, pi.ingredient_id, pi.quantity_normal, pi.quantity_maxi, '
            . 'pi.is_removable, pi.is_addable, pi.extra_price_cents, '
            . 'i.name AS ingredient_name, i.unit AS ingredient_unit, '
            . 'i.stock_quantity, i.stock_capacity, i.low_stock_pct, i.critical_stock_pct '
            . 'FROM product_ingredient pi JOIN ingredient i ON i.id = pi.ingredient_id '
            . 'WHERE pi.product_id = :id ORDER BY i.name',
            ['id' => $productId],
        );
    }

    /**
     * Nombre de lignes de composition d'un produit. Sert a tracer la cascade #27 :
     * combien de product_ingredient seront emportees par la suppression du produit
     * (FK product_id CASCADE), pour ne laisser aucune perte hors-trace dans l'audit.
     */
    public function compositionCount(int $productId): int
    {
        return (int) ($this->db->fetch(
            'SELECT COUNT(*) AS n FROM product_ingredient WHERE product_id = :id',
            ['id' => $productId],
        )['n'] ?? 0);
    }

    /**
     * Remplace integralement la composition d'un produit (delete-and-reinsert, mlt
     * 8.5 RG-2 transpose a la recette) dans UNE transaction (RG-T08) : reposer
     * l'ensemble est plus simple et sur qu'une reconciliation en place. La PK
     * composite (product_id, ingredient_id) garantit l'unicite par ingredient ;
     * l'appelant (controleur) a deja deduplique et valide les bornes (RG-T18).
     *
     * @param list<array{ingredient_id:int, quantity_normal:int, quantity_maxi:int, is_removable:int, is_addable:int, extra_price_cents:int}> $lines
     */
    public function setComposition(int $productId, array $lines): void
    {
        $this->db->transaction(function (DatabaseInterface $db) use ($productId, $lines): void {
            $db->execute('DELETE FROM product_ingredient WHERE product_id = :id', ['id' => $productId]);
            foreach ($lines as $line) {
                $db->execute(
                    'INSERT INTO product_ingredient (product_id, ingredient_id, quantity_normal, '
                    . 'quantity_maxi, is_removable, is_addable, extra_price_cents) '
                    . 'VALUES (:product, :ingredient, :qn, :qm, :rem, :add, :extra)',
                    [
                        'product'    => $productId,
                        'ingredient' => $line['ingredient_id'],
                        'qn'         => $line['quantity_normal'],
                        'qm'         => $line['quantity_maxi'],
                        'rem'        => $line['is_removable'],
                        'add'        => $line['is_addable'],
                        'extra'      => $line['extra_price_cents'],
                    ],
                );
            }
        });
    }

    /**
     * Ids des produits en RUPTURE AUTOMATIQUE par le stock (RG-T21) : au moins un
     * ingredient requis (is_removable=0) au niveau ou sous la bande critique
     * (stock_quantity * 100 <= stock_capacity * critical_stock_pct, l'arithmetique
     * entiere de IngredientRepository::stockBand). Calcule en UNE requete pour
     * eviter le N+1 a l'affichage de la liste. Distinct du retrait manuel
     * (is_available=0), que la vue signale separement.
     *
     * @return list<int>
     */
    public function autoUnavailableIds(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT pi.product_id FROM product_ingredient pi '
            . 'JOIN ingredient i ON i.id = pi.ingredient_id '
            . 'WHERE pi.is_removable = 0 AND i.stock_quantity * 100 <= i.stock_capacity * i.critical_stock_pct',
        );

        return array_map(static fn (array $r): int => (int) ($r['product_id'] ?? 0), $rows);
    }

    /**
     * Disponibilite produit CALCULEE (RG-T21) : commandable ssi le flag
     * is_available vaut 1 ET chaque ingredient NON RETIRABLE (is_removable=0) de la
     * composition est au-dessus de la bande critique (stockBand != 'critical').
     * Derivation pure, sans ecriture ni cascade : un ingredient requis tombant en
     * critique met le produit en rupture automatique ; un ingredient retirable/
     * optionnel en critique ne bloque pas (seul son supplement devient indispo) ;
     * un retrait manuel (is_available=0) prime sur tout. La bande critique est celle
     * d'IngredientRepository::stockBand (source unique de la derivation).
     *
     * @param array<int, array<string, mixed>> $composition lignes de composition()
     */
    public static function isOrderable(bool $flagAvailable, array $composition): bool
    {
        if (!$flagAvailable) {
            return false;
        }

        foreach ($composition as $line) {
            if ((int) ($line['is_removable'] ?? 1) !== 0) {
                continue; // retirable/optionnel : n'entre pas dans la disponibilite du produit
            }
            $band = IngredientRepository::stockBand(
                (int) ($line['stock_quantity'] ?? 0),
                (int) ($line['stock_capacity'] ?? 0),
                (int) ($line['low_stock_pct'] ?? 0),
                (int) ($line['critical_stock_pct'] ?? 0),
            );
            if ($band === 'critical') {
                return false;
            }
        }

        return true;
    }

    /**
     * Allowlist d'affectation de masse (RG-T16) : seules ces colonnes sont liees.
     *
     * @param array{category_id: int, name: string, description: ?string, price_cents: int, vat_rate: int, image_path: ?string, is_available: int, display_order: int} $data
     * @return array<string, mixed>
     */
    private function bind(array $data): array
    {
        return [
            'category'    => $data['category_id'],
            'name'        => $data['name'],
            'description' => $data['description'],
            'price'       => $data['price_cents'],
            'vat'         => $data['vat_rate'],
            'image'       => $data['image_path'],
            'available'   => $data['is_available'],
            'ord'         => $data['display_order'],
        ];
    }
}
