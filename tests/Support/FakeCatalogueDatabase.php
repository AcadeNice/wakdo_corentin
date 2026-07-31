<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Core\DatabaseInterface;
use RuntimeException;

/**
 * Double de DatabaseInterface dedie aux lectures catalogue de la borne
 * (CatalogueController). Les lignes sont scriptees par "boutons" types
 * (categoriesRows, productsRows, productRow) ; les lectures sont tracees pour
 * asserter le court-circuit (id non numerique = aucun aller-retour BDD).
 *
 * Lecture seule a dessein : ce controleur ne doit jamais ecrire. execute() et
 * transaction() levent donc une exception -> un test vire au rouge si une
 * mutation s'y glisse (garde du contrat read-only).
 */
final class FakeCatalogueDatabase implements DatabaseInterface
{
    /**
     * Lignes renvoyees par CategoryRepository::activeForCatalogue().
     *
     * @var list<array<string, mixed>>
     */
    public array $categoriesRows = [];

    /**
     * Lignes renvoyees par ProductRepository::availableForCatalogue().
     *
     * @var list<array<string, mixed>>
     */
    public array $productsRows = [];

    /**
     * Lignes {id, name} renvoyees par ProductRepository::basesOnly() (R4/F9-1).
     *
     * @var list<array<string, mixed>>
     */
    public array $baseProductsRows = [];

    /**
     * Lignes renvoyees par ProductRepository::all() (liste admin enrichie, F9-4).
     *
     * @var list<array<string, mixed>>
     */
    public array $allProductsRows = [];

    /**
     * Ligne renvoyee par ProductRepository::findForCatalogue() ; null = absent /
     * indisponible / categorie inactive.
     *
     * @var array<string, mixed>|null
     */
    public ?array $productRow = null;

    /**
     * Lignes renvoyees par MenuRepository::availableForCatalogue().
     *
     * @var list<array<string, mixed>>
     */
    public array $menusRows = [];

    /**
     * Ligne renvoyee par MenuRepository::findForCatalogue() ; null = absent /
     * indisponible / categorie inactive.
     *
     * @var array<string, mixed>|null
     */
    public ?array $menuRow = null;

    /**
     * Lignes brutes (LEFT JOIN slot/option) renvoyees par MenuRepository::slotsWithOptions().
     *
     * @var list<array<string, mixed>>
     */
    public array $menuSlotRows = [];

    /**
     * Lignes PLATES (base_id, id, size_cl, price_cents) renvoyees a la requete
     * sizesByBase() (R4) ; le repo les groupe lui-meme par base_id.
     *
     * @var list<array<string, mixed>>
     */
    public array $sizesByBaseRows = [];

    /**
     * Lignes PLATES (id, category_id, name, price_cents, vat_rate, is_available,
     * display_order, variant_count) renvoyees a la requete basesByCategory() (F20) ;
     * le repo les groupe lui-meme par category_id.
     *
     * @var list<array<string, mixed>>
     */
    public array $basesByCategoryRows = [];

    /**
     * Tailles d'un produit (R4) renvoyees par ProductRepository::sizesForProduct() ;
     * la requete porte (id = :base_self OR base_product_id = :base_variant) --
     * placeholders distincts (EMULATE_PREPARES=false), cf. HY093.
     *
     * @var list<array<string, mixed>>
     */
    public array $productSizes = [];

    /**
     * Lignes {product_id} renvoyees par ProductRepository::autoUnavailableIds()
     * (RG-T21 : produits en rupture calculee par le stock). Vide = rien en rupture.
     *
     * @var list<array<string, mixed>>
     */
    public array $autoUnavailableRows = [];

    /**
     * Lignes renvoyees par AllergenRepository::all() (14 allergenes INCO :
     * id, code, name, description).
     *
     * @var list<array<string, mixed>>
     */
    public array $allergensRows = [];

    /**
     * Lignes PLATES (product_id, allergen_id, code, name) renvoyees a la requete
     * AllergenRepository::byProduct() (F11b) ; le depot les groupe par product_id.
     *
     * @var list<array<string, mixed>>
     */
    public array $allergensByProductRows = [];

    /**
     * Lignes (product_id, unreviewed) renvoyees a AllergenRepository::unreviewedByProduct()
     * (F11b) : nombre d'ingredients du produit dont les allergenes n'ont pas ete revus.
     *
     * @var list<array<string, mixed>>
     */
    public array $unreviewedByProductRows = [];

    /**
     * Lignes (allergen_id, code, name) renvoyees a AllergenRepository::forProduct().
     *
     * @var list<array<string, mixed>>
     */
    public array $allergensForProductRows = [];

    /**
     * Ligne {n} renvoyee a AllergenRepository::unreviewedCountForProduct() ; null =
     * aucun ingredient non revu.
     *
     * @var array<string, mixed>|null
     */
    public ?array $unreviewedCountRow = null;

    /**
     * Lignes {allergen_id} renvoyees a AllergenRepository::allergenIdsForIngredient()
     * (precochage du formulaire back-office).
     *
     * @var list<array<string, mixed>>
     */
    public array $ingredientAllergenRows = [];

    /**
     * Trace des lectures pour asserter le court-circuit du detail (id <= 0).
     *
     * @var list<array{sql: string, params: array<string|int, mixed>}>
     */
    public array $reads = [];

    public function fetch(string $sql, array $params = []): ?array
    {
        $this->reads[] = ['sql' => $sql, 'params' => $params];

        if (str_contains($sql, 'FROM product p JOIN category') && str_contains($sql, 'WHERE p.id = :id')) {
            return $this->productRow;
        }

        if (str_contains($sql, 'FROM menu m JOIN category') && str_contains($sql, 'WHERE m.id = :id')) {
            return $this->menuRow;
        }

        // F11b : nombre d'ingredients non revus d'UN produit (unreviewedCountForProduct).
        if (str_contains($sql, 'i.allergens_reviewed_at IS NULL')) {
            return $this->unreviewedCountRow;
        }

        return null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->reads[] = ['sql' => $sql, 'params' => $params];

        if (str_contains($sql, 'FROM category WHERE is_active = 1')) {
            return $this->categoriesRows;
        }

        // RG-T21 : ids des produits en rupture calculee (autoUnavailableIds). Desambigue
        // de composition() (meme table) par SELECT DISTINCT, propre a cette requete.
        if (str_contains($sql, 'SELECT DISTINCT pi.product_id')) {
            return $this->autoUnavailableRows;
        }

        // F20 : bases groupees par categorie (basesByCategory). Desambigue par
        // 'AS variant_count', alias propre a cette requete : elle lit FROM product avec
        // l'alias p, donc la branche basesOnly ('FROM product WHERE ...', sans alias)
        // ne l'attrape pas, et elle ne joint pas category.
        if (str_contains($sql, 'AS variant_count')) {
            return $this->basesByCategoryRows;
        }

        // R4 : tailles groupees (sizesByBase) et tailles d'un produit (sizesForProduct).
        // Testees avant la branche catalogue : toutes deux lisent FROM product.
        if (str_contains($sql, 'AS base_id')) {
            return $this->sizesByBaseRows;
        }
        if (str_contains($sql, '(id = :base_self OR base_product_id = :base_variant)')) {
            return $this->productSizes;
        }

        // F9-1 : liste base-only (basesOnly) pour les selects menu/produit.
        if (str_contains($sql, 'FROM product WHERE base_product_id IS NULL')) {
            return $this->baseProductsRows;
        }

        // F9-4 : liste admin enrichie (all()) -- LEFT JOIN base, sans le filtre de
        // disponibilite borne. Distinguee de availableForCatalogue() par l'absence
        // de 'WHERE p.is_available = 1' et la presence de 'LEFT JOIN product b'.
        if (str_contains($sql, 'FROM product p JOIN category') && str_contains($sql, 'LEFT JOIN product b')) {
            return $this->allProductsRows;
        }

        if (str_contains($sql, 'FROM product p JOIN category') && str_contains($sql, 'WHERE p.is_available = 1')) {
            return $this->productsRows;
        }

        if (str_contains($sql, 'FROM menu m JOIN category') && str_contains($sql, 'WHERE m.is_available = 1')) {
            return $this->menusRows;
        }

        if (str_contains($sql, 'FROM menu_slot s')) {
            return $this->menuSlotRows;
        }

        // F11b : les trois lectures d'allergenes calcules. Elles precedent la branche
        // 'FROM allergen' (catalogue des 14) car elles JOIGNENT allergen sans y lire
        // FROM ; l'ordre reste explicite plutot que dependant de cette nuance.
        // Le detail d'un produit est teste AVANT la liste : meme jointure, il ne s'en
        // distingue que par sa clause WHERE.
        if (str_contains($sql, 'JOIN ingredient_allergen ia') && str_contains($sql, 'WHERE pi.product_id = :id')) {
            return $this->allergensForProductRows;
        }
        if (str_contains($sql, 'JOIN ingredient_allergen ia')) {
            return $this->allergensByProductRows;
        }
        if (str_contains($sql, 'i.allergens_reviewed_at IS NULL')) {
            return $this->unreviewedByProductRows;
        }
        if (str_contains($sql, 'FROM ingredient_allergen WHERE ingredient_id = :id')) {
            return $this->ingredientAllergenRows;
        }

        if (str_contains($sql, 'FROM allergen')) {
            return $this->allergensRows;
        }

        return [];
    }

    public function execute(string $sql, array $params = []): int
    {
        throw new RuntimeException('Lecture seule : execute() interdit sur le catalogue borne.');
    }

    public function transaction(callable $fn): void
    {
        throw new RuntimeException('Lecture seule : transaction() interdit sur le catalogue borne.');
    }
}
