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
     * Tailles d'un produit (R4) renvoyees par ProductRepository::sizesForProduct() ;
     * la requete porte (id = :base OR base_product_id = :base).
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

        // R4 : tailles groupees (sizesByBase) et tailles d'un produit (sizesForProduct).
        // Testees avant la branche catalogue : toutes deux lisent FROM product.
        if (str_contains($sql, 'AS base_id')) {
            return $this->sizesByBaseRows;
        }
        if (str_contains($sql, '(id = :base OR base_product_id = :base)')) {
            return $this->productSizes;
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
