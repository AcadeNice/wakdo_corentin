<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalogue;

use PHPUnit\Framework\TestCase;
use App\Controllers\CatalogueController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\Request;
use App\Tests\Support\FakeCatalogueDatabase;

/**
 * Sous-classe de test : redefinit db() pour injecter le double catalogue, sans
 * base reelle. Les repos de lecture sont alors construits sur ce double, ce qui
 * exerce le cablage controleur -> repository -> SQL.
 */
final class TestCatalogueController extends CatalogueController
{
    public function __construct(
        Request $request,
        Config $config,
        Database $database,
        private readonly FakeCatalogueDatabase $fakeDb,
    ) {
        parent::__construct($request, $config, $database);
    }

    protected function db(): DatabaseInterface
    {
        return $this->fakeDb;
    }
}

final class CatalogueControllerTest extends TestCase
{
    private function controller(FakeCatalogueDatabase $db, string $path = '/api/categories'): TestCatalogueController
    {
        $request = new Request('GET', $path, [], [], '', '203.0.113.5');

        return new TestCatalogueController($request, new Config(), new Database(new Config()), $db);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $data = json_decode($body, true);
        self::assertIsArray($data);

        return $data;
    }

    public function testCategoriesReturnsActiveCollectionEnvelope(): void
    {
        $db = new FakeCatalogueDatabase();
        // Entiers scriptes en CHAINE (comme PDO peut les rendre) + champ is_active
        // parasite : le controleur doit caster en int ET ne pas le laisser fuiter.
        $db->categoriesRows = [
            ['id' => '3', 'name' => 'Burgers', 'slug' => 'burgers', 'image_path' => 'burgers.png', 'display_order' => '2', 'is_active' => '1'],
            ['id' => '1', 'name' => 'Menus', 'slug' => 'menus', 'image_path' => null, 'display_order' => '1', 'is_active' => '1'],
        ];

        $response = $this->controller($db, '/api/categories')->categories();

        self::assertSame(200, $response->status());
        self::assertSame('application/json; charset=utf-8', $response->header('Content-Type'));

        $payload = $this->decode($response->body());
        self::assertSame(2, $payload['total'] ?? null);
        self::assertIsArray($payload['data']);

        $first = $payload['data'][0];
        self::assertSame(['id', 'name', 'slug', 'image_path', 'display_order'], array_keys($first));
        self::assertSame(3, $first['id']);            // chaine '3' -> int 3
        self::assertSame(2, $first['display_order']); // chaine '2' -> int 2
        self::assertSame('burgers.png', $first['image_path']);
        self::assertNull($payload['data'][1]['image_path']); // null preserve
        self::assertArrayNotHasKey('is_active', $first);      // pas de fuite
    }

    public function testCategoriesEmptyReturnsEmptyCollection(): void
    {
        $db = new FakeCatalogueDatabase();

        $response = $this->controller($db, '/api/categories')->categories();

        self::assertSame(200, $response->status());
        $payload = $this->decode($response->body());
        self::assertSame([], $payload['data']);
        self::assertSame(0, $payload['total']);
    }

    public function testProductsReturnsAvailableCollectionWithoutVatRate(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->productsRows = [
            [
                'id' => '12', 'category_id' => '3', 'name' => 'Cheeseburger',
                'description' => 'Pain, steak, cheddar', 'price_cents' => '890',
                'vat_rate' => '100', 'image_path' => 'cheese.png', 'display_order' => '1',
                // LEFT JOIN variante Maxi : NULL pour un produit sans variante.
                'maxi_variant_name' => null,
            ],
        ];

        $response = $this->controller($db, '/api/products')->products();

        self::assertSame(200, $response->status());
        $payload = $this->decode($response->body());
        self::assertSame(1, $payload['total']);

        $product = $payload['data'][0];
        self::assertSame(
            ['id', 'category_id', 'name', 'description', 'price_cents', 'image_path', 'display_order', 'maxi_variant_name', 'sizes', 'is_orderable'],
            array_keys($product),
        );
        self::assertSame(12, $product['id']);
        self::assertSame(3, $product['category_id']);
        self::assertSame(890, $product['price_cents']);          // chaine -> int
        self::assertArrayNotHasKey('vat_rate', $product);        // fiscal interne, non expose
        self::assertArrayNotHasKey('is_available', $product);    // toujours dispo ici -> non expose
        self::assertNull($product['maxi_variant_name']);         // pas de variante -> null
        self::assertSame([], $product['sizes']);                 // produit mono-taille -> sizes vide
        self::assertTrue($product['is_orderable']);              // aucune rupture -> commandable
    }

    public function testProductsMarksAutoUnavailableProductAsNotOrderable(): void
    {
        // RG-T21 : deux produits listes (is_available=1), mais l'un (id 12) est en
        // rupture calculee par le stock -> is_orderable false ; l'autre (id 13) true.
        $db = new FakeCatalogueDatabase();
        $db->productsRows = [
            ['id' => '12', 'category_id' => '3', 'name' => 'Cheeseburger', 'description' => null, 'price_cents' => '890', 'image_path' => null, 'display_order' => '1', 'maxi_variant_name' => null],
            ['id' => '13', 'category_id' => '3', 'name' => 'Hamburger', 'description' => null, 'price_cents' => '790', 'image_path' => null, 'display_order' => '2', 'maxi_variant_name' => null],
        ];
        $db->autoUnavailableRows = [['product_id' => '12']]; // 12 en rupture

        $response = $this->controller($db, '/api/products')->products();

        self::assertSame(200, $response->status());
        $data = $this->decode($response->body())['data'];
        self::assertFalse($data[0]['is_orderable']); // 12 en rupture
        self::assertTrue($data[1]['is_orderable']);  // 13 commandable
    }

    public function testProductDetailAutoUnavailableIsNotOrderable(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->productRow = [
            'id' => '12', 'category_id' => '3', 'name' => 'Cheeseburger',
            'description' => null, 'price_cents' => '890', 'image_path' => null, 'display_order' => '1',
        ];
        $db->autoUnavailableRows = [['product_id' => '12']];

        $response = $this->controller($db, '/api/products/12')->product(['id' => '12']);

        self::assertSame(200, $response->status());
        self::assertFalse($this->decode($response->body())['data']['is_orderable']);
    }

    public function testProductsListExposesMaxiVariantName(): void
    {
        $db = new FakeCatalogueDatabase();
        // "Moyenne Frite" (accompagnement) a une variante Maxi "Grande Frite" : le
        // LEFT JOIN remonte mv.name AS maxi_variant_name, expose tel quel a la borne.
        $db->productsRows = [
            [
                'id' => '23', 'category_id' => '4', 'name' => 'Moyenne Frite',
                'description' => null, 'price_cents' => '250',
                'image_path' => 'frite.png', 'display_order' => '1',
                'maxi_variant_name' => 'Grande Frite',
            ],
        ];

        $response = $this->controller($db, '/api/products')->products();

        self::assertSame(200, $response->status());
        $product = $this->decode($response->body())['data'][0];
        self::assertSame('Grande Frite', $product['maxi_variant_name']);
    }

    public function testProductsListPresentsSizesArrayForDrinkWithVariants(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->productsRows = [
            [
                'id' => '14', 'category_id' => '2', 'name' => 'Coca Cola',
                'description' => null, 'price_cents' => '190', 'size_cl' => '30',
                'image_path' => 'c.png', 'display_order' => '1',
            ],
        ];
        // sizesByBase() (R4) : la base 14 porte deux tailles (30 cl id 14, 50 cl id 99).
        $db->sizesByBaseRows = [
            ['base_id' => '14', 'id' => '14', 'size_cl' => '30', 'price_cents' => '190'],
            ['base_id' => '14', 'id' => '99', 'size_cl' => '50', 'price_cents' => '240'],
        ];

        $response = $this->controller($db, '/api/products')->products();

        self::assertSame(200, $response->status());
        $product = $this->decode($response->body())['data'][0];

        // Chaque taille : product_id resolu, volume, prix, label HUMAIN ("30 cl").
        self::assertSame(
            [
                ['product_id' => 14, 'size_cl' => 30, 'price_cents' => 190, 'label' => '30 cl'],
                ['product_id' => 99, 'size_cl' => 50, 'price_cents' => 240, 'label' => '50 cl'],
            ],
            $product['sizes'],
        );
        // La 30 cl reutilise le product_id de la base : l'ajout direct reste possible.
        self::assertSame(14, $product['sizes'][0]['product_id']);
    }

    public function testProductDetailPresentsSizesWhenVariantsExist(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->productRow = [
            'id' => '14', 'category_id' => '2', 'name' => 'Coca Cola',
            'description' => null, 'price_cents' => '190', 'size_cl' => '30',
            'image_path' => null, 'display_order' => '1',
        ];
        $db->productSizes = [
            ['id' => '14', 'size_cl' => '30', 'price_cents' => '190'],
            ['id' => '99', 'size_cl' => '50', 'price_cents' => '240'],
        ];

        $response = $this->controller($db, '/api/products/14')->product(['id' => '14']);

        self::assertSame(200, $response->status());
        $product = $this->decode($response->body())['data'];
        self::assertCount(2, $product['sizes']);
        self::assertSame('50 cl', $product['sizes'][1]['label']);
    }

    public function testProductDetailHasEmptySizesWhenSingleSize(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->productRow = [
            'id' => '17', 'category_id' => '2', 'name' => 'Eau',
            'description' => null, 'price_cents' => '100', 'size_cl' => null,
            'image_path' => null, 'display_order' => '3',
        ];
        // sizesForProduct ne remonte que la base (1 ligne) : pas de dimension taille.
        $db->productSizes = [
            ['id' => '17', 'size_cl' => null, 'price_cents' => '100'],
        ];

        $response = $this->controller($db, '/api/products/17')->product(['id' => '17']);

        self::assertSame(200, $response->status());
        $product = $this->decode($response->body())['data'];
        self::assertSame([], $product['sizes']);
    }

    public function testProductDetailReturnsData(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->productRow = [
            'id' => '12', 'category_id' => '3', 'name' => 'Cheeseburger',
            'description' => null, 'price_cents' => '890', 'vat_rate' => '100',
            'image_path' => null, 'display_order' => '1',
            // Detail d'un accompagnement avec variante Maxi : le nom doit ressortir.
            'maxi_variant_name' => 'Grande Frite',
        ];

        $response = $this->controller($db, '/api/products/12')->product(['id' => '12']);

        self::assertSame(200, $response->status());
        $payload = $this->decode($response->body());
        $product = $payload['data'];
        self::assertSame(12, $product['id']);
        self::assertSame(890, $product['price_cents']);
        self::assertNull($product['description']);
        self::assertSame('Grande Frite', $product['maxi_variant_name']); // variante exposee
        self::assertArrayNotHasKey('vat_rate', $product);
        // L'id a bien ete lie a la lecture, converti en entier (le repo a recu :id = 12).
        self::assertSame(12, $db->reads[0]['params']['id'] ?? null);
    }

    public function testProductDetailUnknownReturns404(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->productRow = null; // absent / indisponible / categorie inactive

        $response = $this->controller($db, '/api/products/999')->product(['id' => '999']);

        self::assertSame(404, $response->status());
        $payload = $this->decode($response->body());
        self::assertNull($payload['data']);
        self::assertSame('NOT_FOUND', $payload['error']['code'] ?? null);
    }

    public function testProductDetailNonNumericReturns404WithoutQuery(): void
    {
        $db = new FakeCatalogueDatabase();

        $response = $this->controller($db, '/api/products/abc')->product(['id' => 'abc']);

        self::assertSame(404, $response->status());
        // id non numerique -> 0 -> court-circuit : aucun aller-retour BDD.
        self::assertSame([], $db->reads);
    }

    public function testMenusReturnsLightCollectionWithoutSlots(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->menusRows = [
            [
                'id' => '1', 'category_id' => '1', 'burger_product_id' => '5',
                'name' => 'Menu Maxi Best Of', 'description' => 'Burger + frites + boisson',
                'price_normal_cents' => '990', 'price_maxi_cents' => '1190',
                'image_path' => 'menu.png', 'display_order' => '1', 'is_available' => '1',
            ],
        ];

        $response = $this->controller($db, '/api/menus')->menus();

        self::assertSame(200, $response->status());
        $payload = $this->decode($response->body());
        self::assertSame(1, $payload['total']);

        $menu = $payload['data'][0];
        self::assertSame(
            ['id', 'category_id', 'burger_product_id', 'name', 'description', 'price_normal_cents', 'price_maxi_cents', 'image_path', 'display_order', 'is_orderable'],
            array_keys($menu),
        );
        self::assertSame(1, $menu['id']);
        self::assertSame(5, $menu['burger_product_id']);
        self::assertSame(990, $menu['price_normal_cents']);
        self::assertSame(1190, $menu['price_maxi_cents']);
        self::assertTrue($menu['is_orderable']);            // burger non en rupture
        self::assertArrayNotHasKey('slots', $menu);        // liste legere : pas de slots
        self::assertArrayNotHasKey('is_available', $menu);  // toujours dispo ici
        self::assertArrayNotHasKey('vat_rate', $menu);
    }

    public function testMenuNotOrderableWhenBurgerAutoUnavailable(): void
    {
        // RG-T21 (granularite burger seul) : le burger impose (id 5) est en rupture
        // calculee -> le menu n'est plus commandable, meme s'il est is_available=1.
        $db = new FakeCatalogueDatabase();
        $db->menusRows = [
            ['id' => '1', 'category_id' => '1', 'burger_product_id' => '5', 'name' => 'Menu Big', 'description' => null, 'price_normal_cents' => '990', 'price_maxi_cents' => '1190', 'image_path' => null, 'display_order' => '1'],
        ];
        $db->autoUnavailableRows = [['product_id' => '5']]; // burger en rupture

        $response = $this->controller($db, '/api/menus')->menus();

        self::assertSame(200, $response->status());
        self::assertFalse($this->decode($response->body())['data'][0]['is_orderable']);
    }

    public function testMenuDetailNotOrderableWhenBurgerAutoUnavailable(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->menuRow = [
            'id' => '1', 'category_id' => '1', 'burger_product_id' => '5', 'name' => 'Menu Big',
            'description' => null, 'price_normal_cents' => '990', 'price_maxi_cents' => '1190',
            'image_path' => null, 'display_order' => '1',
        ];
        $db->menuSlotRows = [];
        $db->autoUnavailableRows = [['product_id' => '5']];

        $response = $this->controller($db, '/api/menus/1')->menu(['id' => '1']);

        self::assertSame(200, $response->status());
        self::assertFalse($this->decode($response->body())['data']['is_orderable']);
    }

    public function testMenuDetailReturnsDataWithSlots(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->menuRow = [
            'id' => '1', 'category_id' => '1', 'burger_product_id' => '5',
            'name' => 'Menu Maxi Best Of', 'description' => null,
            'price_normal_cents' => '990', 'price_maxi_cents' => '1190',
            'image_path' => null, 'display_order' => '1',
        ];
        // Lignes brutes (LEFT JOIN) : slot 7 a deux options, slot 8 une, ordre par slot.
        $db->menuSlotRows = [
            ['id' => '7', 'name' => 'Boisson', 'slot_type' => 'drink', 'is_required' => '1', 'display_order' => '1', 'product_id' => '27'],
            ['id' => '7', 'name' => 'Boisson', 'slot_type' => 'drink', 'is_required' => '1', 'display_order' => '1', 'product_id' => '28'],
            ['id' => '8', 'name' => 'Sauce', 'slot_type' => 'sauce', 'is_required' => '0', 'display_order' => '2', 'product_id' => '40'],
        ];

        $response = $this->controller($db, '/api/menus/1')->menu(['id' => '1']);

        self::assertSame(200, $response->status());
        $payload = $this->decode($response->body());
        $menu = $payload['data'];
        self::assertSame(1, $menu['id']);
        self::assertSame(5, $menu['burger_product_id']);
        self::assertArrayNotHasKey('vat_rate', $menu);

        self::assertIsArray($menu['slots']);
        self::assertCount(2, $menu['slots']);

        $drink = $menu['slots'][0];
        self::assertSame(['id', 'name', 'slot_type', 'is_required', 'display_order', 'option_product_ids'], array_keys($drink));
        self::assertSame(7, $drink['id']);
        self::assertSame('drink', $drink['slot_type']);
        self::assertTrue($drink['is_required']);            // tinyint 1 -> bool true
        self::assertSame([27, 28], $drink['option_product_ids']); // ints groupes

        $sauce = $menu['slots'][1];
        self::assertFalse($sauce['is_required']);           // tinyint 0 -> bool false
        self::assertSame([40], $sauce['option_product_ids']);
    }

    public function testMenuDetailUnknownReturns404(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->menuRow = null;

        $response = $this->controller($db, '/api/menus/999')->menu(['id' => '999']);

        self::assertSame(404, $response->status());
        $payload = $this->decode($response->body());
        self::assertNull($payload['data']);
        self::assertSame('NOT_FOUND', $payload['error']['code'] ?? null);
    }

    public function testMenuDetailNonNumericReturns404WithoutQuery(): void
    {
        $db = new FakeCatalogueDatabase();

        $response = $this->controller($db, '/api/menus/abc')->menu(['id' => 'abc']);

        self::assertSame(404, $response->status());
        self::assertSame([], $db->reads);
    }

    public function testMenuDetailSlotWithoutOptionsExposesEmptyList(): void
    {
        $db = new FakeCatalogueDatabase();
        $db->menuRow = [
            'id' => '1', 'category_id' => '1', 'burger_product_id' => '5',
            'name' => 'Menu', 'description' => null,
            'price_normal_cents' => '990', 'price_maxi_cents' => '1190',
            'image_path' => null, 'display_order' => '1',
        ];
        // Slot remonte par le LEFT JOIN SANS option eligible (product_id NULL) : doit
        // ressortir avec une liste VIDE, pas [0] ni absent (contrat 'slot vide -> []').
        $db->menuSlotRows = [
            ['id' => '9', 'name' => 'Extra', 'slot_type' => 'extra', 'is_required' => '0', 'display_order' => '1', 'product_id' => null],
        ];

        $response = $this->controller($db, '/api/menus/1')->menu(['id' => '1']);

        self::assertSame(200, $response->status());
        $payload = $this->decode($response->body());
        $slots = $payload['data']['slots'];
        self::assertCount(1, $slots);
        self::assertSame('extra', $slots[0]['slot_type']);
        self::assertSame([], $slots[0]['option_product_ids']);
    }
}
