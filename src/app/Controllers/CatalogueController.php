<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Catalogue\AllergenRepository;
use App\Catalogue\CategoryRepository;
use App\Catalogue\MenuRepository;
use App\Catalogue\ProductRepository;
use App\Core\Controller;
use App\Core\DatabaseInterface;
use App\Core\Response;

/**
 * API publique de LECTURE du catalogue pour la borne kiosk (P4, lecture
 * catalogue, docs/api/conventions.md section 5.2). Anonyme : la borne consulte
 * sans session. Lecture seule, aucune mutation.
 *
 * La borne ne voit que le COMMANDABLE : categories actives, produits disponibles
 * dont la categorie est active (filtrage en SQL cote repository). Les champs
 * suivent le dictionnaire (snake_case, prix en centimes, section 8.1) ; le
 * rapprochement vers la forme heritee de la borne se fait en un point unique,
 * data.js (section 8.3). vat_rate n'est PAS expose (calcul fiscal cote serveur).
 *
 * Le typage de sortie est explicite (present*) : la valeur JSON ne depend pas du
 * mode de fetch PDO (price_cents reste un entier, image_path reste nullable),
 * meme discipline que OrderController::present.
 *
 * Enveloppe standard : {data} (collection avec total) / {data:null, error:{code,
 * message}}. Non `final` : les tests sous-classent pour injecter un acces BDD
 * double via le hook db().
 */
class CatalogueController extends Controller
{
    /**
     * @param array<string, string> $params
     */
    public function categories(array $params = []): Response
    {
        $rows = array_map(
            fn (array $row): array => $this->presentCategory($row),
            $this->categoriesRepo()->activeForCatalogue(),
        );

        return $this->json(['data' => $rows, 'total' => count($rows)]);
    }

    /**
     * @param array<string, string> $params
     */
    public function products(array $params = []): Response
    {
        $repo = $this->productsRepo();
        // R4 : les tailles de TOUS les produits a variantes sont chargees en UNE
        // requete (sizesByBase), pas une par produit -> /api/products reste un seul
        // aller-retour cache-friendly cote borne (data.js memoise la liste).
        $sizesByBase = $repo->sizesByBase();
        // RG-T21 : rupture calculee par le stock, en UNE requete (set d'ids). Un
        // produit liste (is_available=1) mais en rupture devient non commandable ->
        // la borne le grise au lieu de laisser composer une commande vouee a echouer.
        $unavailable = array_fill_keys($repo->autoUnavailableIds(), true);
        $rows = array_map(
            fn (array $row): array => $this->presentProduct(
                $row,
                $sizesByBase[(int) ($row['id'] ?? 0)] ?? [],
                !isset($unavailable[(int) ($row['id'] ?? 0)]),
            ),
            $repo->availableForCatalogue(),
        );

        return $this->json(['data' => $rows, 'total' => count($rows)]);
    }

    /**
     * @param array<string, string> $params
     */
    public function product(array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $repo = $this->productsRepo();
        $row = $id > 0 ? $repo->findForCatalogue($id) : null;

        if ($row === null) {
            return $this->json(
                ['data' => null, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Produit introuvable.']],
                404,
            );
        }

        // R4 : sur le detail, les tailles ne sont presentees que si le produit en a
        // au moins une VARIANTE (sinon sizesForProduct ne remonte que la base, et la
        // base seule n'est pas une dimension de taille -> sizes vide cote presentation).
        $sizes = $repo->sizesForProduct($id);
        // RG-T21 : meme dispo calculee qu'en liste, pour ce produit (membership du set).
        $orderable = !in_array($id, $repo->autoUnavailableIds(), true);

        return $this->json(['data' => $this->presentProduct($row, count($sizes) > 1 ? $sizes : [], $orderable)]);
    }

    /**
     * @param array<string, string> $params
     */
    public function menus(array $params = []): Response
    {
        // RG-T21 (granularite : burger impose seul) : un menu dont le burger principal
        // est en rupture calculee n'est plus commandable. Set d'ids produits en rupture
        // reutilise pour tous les menus (pas de N+1).
        $unavailable = array_fill_keys($this->productsRepo()->autoUnavailableIds(), true);
        $rows = array_map(
            fn (array $row): array => $this->presentMenu(
                $row,
                !isset($unavailable[(int) ($row['burger_product_id'] ?? 0)]),
            ),
            $this->menusRepo()->availableForCatalogue(),
        );

        return $this->json(['data' => $rows, 'total' => count($rows)]);
    }

    /**
     * @param array<string, string> $params
     */
    public function menu(array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $repo = $this->menusRepo();
        $row = $id > 0 ? $repo->findForCatalogue($id) : null;

        if ($row === null) {
            return $this->json(
                ['data' => null, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Menu introuvable.']],
                404,
            );
        }

        // RG-T21 (burger impose seul) : dispo calculee du menu = burger non en rupture.
        $orderable = !in_array((int) ($row['burger_product_id'] ?? 0), $this->productsRepo()->autoUnavailableIds(), true);
        // Detail = menu + ses slots de composition (B1 burger impose, B2 Normal/Maxi).
        $menu = $this->presentMenu($row, $orderable) + ['slots' => $this->presentSlots($repo->slotsWithOptions($id))];

        return $this->json(['data' => $menu]);
    }

    /**
     * Allergenes INCO (info generale, 14 categories). Public anonyme, lecture seule.
     *
     * @param array<string, string> $params
     */
    public function allergens(array $params = []): Response
    {
        $rows = array_map(
            fn (array $row): array => $this->presentAllergen($row),
            $this->allergensRepo()->all(),
        );

        return $this->json(['data' => $rows, 'total' => count($rows)]);
    }

    protected function categoriesRepo(): CategoryRepository
    {
        return new CategoryRepository($this->db());
    }

    protected function productsRepo(): ProductRepository
    {
        return new ProductRepository($this->db());
    }

    protected function menusRepo(): MenuRepository
    {
        return new MenuRepository($this->db());
    }

    protected function allergensRepo(): AllergenRepository
    {
        return new AllergenRepository($this->db());
    }

    /**
     * Acces BDD comme DatabaseInterface (seam de test). Database l'implemente.
     */
    protected function db(): DatabaseInterface
    {
        return $this->database;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, code: string, name: string}
     */
    private function presentAllergen(array $row): array
    {
        return [
            'id'   => (int) ($row['id'] ?? 0),
            'code' => (string) ($row['code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, name: string, slug: string, image_path: ?string, display_order: int}
     */
    private function presentCategory(array $row): array
    {
        return [
            'id'            => (int) ($row['id'] ?? 0),
            'name'          => (string) ($row['name'] ?? ''),
            'slug'          => (string) ($row['slug'] ?? ''),
            'image_path'    => $this->nullableString($row['image_path'] ?? null),
            'display_order' => (int) ($row['display_order'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $sizes tailles de la base (R4) : base +
     *        variantes ; vide si le produit n'a pas de dimension taille. Chaque entree
     *        devient {product_id, size_cl, price_cents, label} ; le label humain est
     *        derive du volume ("30 cl") -- aucun slug/enum ne fuit a l'ecran.
     * @return array{id: int, category_id: int, name: string, description: ?string, price_cents: int, image_path: ?string, display_order: int, maxi_variant_name: ?string, sizes: list<array{product_id: int, size_cl: int, price_cents: int, label: string}>, is_orderable: bool}
     */
    private function presentProduct(array $row, array $sizes = [], bool $isOrderable = true): array
    {
        return [
            'id'            => (int) ($row['id'] ?? 0),
            'category_id'   => (int) ($row['category_id'] ?? 0),
            'name'          => (string) ($row['name'] ?? ''),
            'description'   => $this->nullableString($row['description'] ?? null),
            'price_cents'   => (int) ($row['price_cents'] ?? 0),
            'image_path'    => $this->nullableString($row['image_path'] ?? null),
            'display_order' => (int) ($row['display_order'] ?? 0),
            // Nom de la variante Maxi de l'accompagnement (ex. "Grande Frite") ; NULL si
            // le produit n'a pas de variante. La borne l'affiche en format Maxi pour ne
            // pas montrer "Moyenne Frite" sur un menu agrandi.
            'maxi_variant_name' => $this->nullableString($row['maxi_variant_name'] ?? null),
            'sizes'         => array_map(
                static function (array $size): array {
                    $cl = (int) ($size['size_cl'] ?? 0);

                    return [
                        'product_id'  => (int) ($size['id'] ?? 0),
                        'size_cl'     => $cl,
                        'price_cents' => (int) ($size['price_cents'] ?? 0),
                        'label'       => $cl . ' cl',
                    ];
                },
                array_values($sizes),
            ),
            // is_orderable : false si rupture calculee par le stock (RG-T21). La borne
            // grise la tuile (echo UX) ; l'enforcement qui fait foi est cote serveur a la
            // creation de commande (OrderRepository::resolveLine refuse un item en
            // rupture). Le retrait manuel (is_available=0) est deja exclu en amont.
            'is_orderable'  => $isOrderable,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, category_id: int, burger_product_id: int, name: string, description: ?string, price_normal_cents: int, price_maxi_cents: int, image_path: ?string, display_order: int, is_orderable: bool}
     */
    private function presentMenu(array $row, bool $isOrderable = true): array
    {
        return [
            'id'                 => (int) ($row['id'] ?? 0),
            'category_id'        => (int) ($row['category_id'] ?? 0),
            'burger_product_id'  => (int) ($row['burger_product_id'] ?? 0),
            'name'               => (string) ($row['name'] ?? ''),
            'description'        => $this->nullableString($row['description'] ?? null),
            'price_normal_cents' => (int) ($row['price_normal_cents'] ?? 0),
            'price_maxi_cents'   => (int) ($row['price_maxi_cents'] ?? 0),
            'image_path'         => $this->nullableString($row['image_path'] ?? null),
            'display_order'      => (int) ($row['display_order'] ?? 0),
            // is_orderable : false si le burger impose est en rupture calculee (RG-T21,
            // granularite burger seul). La borne grise le menu.
            'is_orderable'       => $isOrderable,
        ];
    }

    /**
     * Slots de composition d'un menu pour la borne. MenuRepository::slotsWithOptions
     * a deja groupe les options par slot et type les valeurs ; on expose is_required
     * en vrai booleen (plus naturel pour le client JS) et on garde la liste d'ids
     * de produits eligibles (la borne resout les libelles via /api/products).
     *
     * @param list<array{id: int, name: string, slot_type: string, is_required: int, display_order: int, option_product_ids: list<int>}> $slots
     * @return list<array{id: int, name: string, slot_type: string, is_required: bool, display_order: int, option_product_ids: list<int>}>
     */
    private function presentSlots(array $slots): array
    {
        return array_map(
            static fn (array $slot): array => [
                'id'                 => $slot['id'],
                'name'               => $slot['name'],
                'slot_type'          => $slot['slot_type'],
                'is_required'        => $slot['is_required'] !== 0,
                'display_order'      => $slot['display_order'],
                'option_product_ids' => $slot['option_product_ids'],
            ],
            $slots,
        );
    }

    /**
     * Preserve NULL (colonne nullable) tout en restant strictement type : un
     * scalaire devient une chaine, tout le reste (null, tableau) devient null.
     */
    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
