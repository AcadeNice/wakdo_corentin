<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use App\Auth\GuardResult;
use App\Auth\SessionManager;
use App\Catalogue\IngredientFamily;
use App\Controllers\ProductController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\Request;
use App\Core\Response;
use App\Tests\Support\FakeDatabase;

/**
 * Verifie que ProductController::renderForm() (create/edit) passe au gabarit
 * `admin/products/form` les trois cles introduites par le filtrage du selecteur
 * d'ingredients par categorie (migration 0017) : `ingredients` porte desormais
 * `family` par ligne, `categoryFamilies` porte la correspondance categorie ->
 * familles autorisees, `ingredientFamilies` porte les libelles francais ordonnes.
 *
 * Distinct de ProductControllerTest.php : sa TestProductController n'expose pas
 * les donnees brutes passees a la vue (seul le HTML rendu est assertable), donc
 * ce fichier definit sa PROPRE sous-classe qui intercepte adminView() avant de
 * deleguer au parent (le rendu HTML reel n'est pas modifie, seulement observe).
 */
final class ViewDataCapturingProductController extends ProductController
{
    /** @var array<string, mixed> */
    public array $capturedViewData = [];

    public function __construct(
        Request $request,
        Config $config,
        Database $database,
        private readonly SessionManager $testSession,
        private readonly FakeDatabase $fakeDb,
    ) {
        parent::__construct($request, $config, $database);
    }

    protected function sessionManager(): SessionManager
    {
        return $this->testSession;
    }

    protected function db(): DatabaseInterface
    {
        return $this->fakeDb;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function adminView(string $name, array $data, GuardResult $guard, int $status = 200): Response
    {
        $this->capturedViewData = $data;

        return parent::adminView($name, $data, $guard, $status);
    }
}

final class ProductControllerFamilyDataTest extends TestCase
{
    /** @var list<string> */
    private array $touchedKeys = [];

    private SessionManager $session;

    protected function setUp(): void
    {
        $this->setEnv('SESSION_LIFETIME_IDLE', '14400');
        $this->setEnv('SESSION_LIFETIME_ABSOLUTE', '36000');
        $this->setEnv('STAFF_PIN_MIN_LENGTH', '4');
        $this->setEnv('STAFF_PIN_MAX_LENGTH', '12');
        $this->setEnv('ARGON2_MEMORY_COST', '1024');
        $this->setEnv('ARGON2_TIME_COST', '1');
        $this->setEnv('ARGON2_THREADS', '1');

        $this->session = new SessionManager(new Config(), true);
        $now = time();
        $this->session->set('user_id', 1);
        $this->session->set('role_id', 1);
        $this->session->set('logged_in_at', $now - 100);
        $this->session->set('last_activity', $now - 50);
    }

    protected function tearDown(): void
    {
        foreach ($this->touchedKeys as $key) {
            putenv($key);
        }
        $this->touchedKeys = [];
    }

    private function setEnv(string $key, string $value): void
    {
        $this->touchedKeys[] = $key;
        putenv($key . '=' . $value);
    }

    private function permittedDb(): FakeDatabase
    {
        $db = new FakeDatabase();
        $db->guardUserRow = ['is_active' => 1];
        $db->userDisplayRow = ['first_name' => 'Corentin', 'last_name' => 'J', 'role_label' => 'Administrateur'];
        $db->canResult = true;
        $db->permissionCodes = ['product.read', 'product.create', 'product.update', 'ingredient.manage'];

        return $db;
    }

    private function get(string $path): Request
    {
        return new Request('GET', $path, [], [], '', '203.0.113.5');
    }

    private function controller(Request $request, FakeDatabase $db): ViewDataCapturingProductController
    {
        return new ViewDataCapturingProductController($request, new Config(), new Database(new Config()), $this->session, $db);
    }

    public function testCreateFormPassesCategoryFamiliesAndIngredientFamiliesToTheView(): void
    {
        $db = $this->permittedDb();
        $db->categoriesRows = [
            ['id' => 3, 'name' => 'Burgers', 'slug' => 'burgers', 'display_order' => 1, 'is_active' => 1],
            ['id' => 1, 'name' => 'Menus', 'slug' => 'menus', 'display_order' => 0, 'is_active' => 1],
        ];
        $db->ingredientsRows = [
            ['id' => 5, 'name' => 'Cheddar', 'unit' => 'tranche', 'family' => 'fromage', 'stock_quantity' => 40, 'stock_capacity' => 100, 'is_active' => 1],
            ['id' => 6, 'name' => 'Brownie', 'unit' => 'pièce', 'family' => 'dessert', 'stock_quantity' => 40, 'stock_capacity' => 100, 'is_active' => 1],
        ];
        // category_ingredient_family brut : categorie 3 (burgers) restreinte a pain+fromage.
        $db->categoryIngredientFamilyRows = [
            ['category_id' => 3, 'family' => 'fromage'],
            ['category_id' => 3, 'family' => 'pain'],
        ];

        $controller = $this->controller($this->get('/admin/products/create'), $db);
        $response = $controller->create();

        self::assertSame(200, $response->status());

        $data = $controller->capturedViewData;
        self::assertArrayHasKey('categoryFamilies', $data);
        self::assertArrayHasKey('ingredientFamilies', $data);

        // categoryFamilies : id de categorie => liste de slugs, seule la categorie
        // restreinte (3) apparait. "Menus" (1) est ABSENTE (aucune restriction).
        self::assertSame(['fromage', 'pain'], $data['categoryFamilies'][3]);
        self::assertArrayNotHasKey(1, $data['categoryFamilies']);

        // ingredientFamilies : les libelles francais, EXACTEMENT la source unique
        // IngredientFamily::labels() (ordre canonique inclus).
        self::assertSame(IngredientFamily::labels(), $data['ingredientFamilies']);

        // ingredients : chaque ligne porte desormais `family`.
        $byName = [];
        foreach ($data['ingredients'] as $row) {
            $byName[$row['name']] = $row['family'];
        }
        self::assertSame('fromage', $byName['Cheddar']);
        self::assertSame('dessert', $byName['Brownie']);
    }

    public function testEditFormAlsoPassesCategoryFamiliesAndIngredientFamilies(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'category_id' => 3, 'name' => 'Big Mac', 'description' => '', 'price_cents' => 590, 'vat_rate' => 100, 'is_available' => 1, 'display_order' => 1];
        $db->categoriesRows = [['id' => 3, 'name' => 'Burgers', 'slug' => 'burgers', 'display_order' => 1, 'is_active' => 1]];
        $db->categoryIngredientFamilyRows = [['category_id' => 3, 'family' => 'viande']];

        $controller = $this->controller($this->get('/admin/products/5/edit'), $db);
        $response = $controller->edit(['id' => '5']);

        self::assertSame(200, $response->status());
        self::assertSame(['viande'], $controller->capturedViewData['categoryFamilies'][3]);
        self::assertSame(IngredientFamily::labels(), $controller->capturedViewData['ingredientFamilies']);
    }

    // --- Page recette dediee (recipeForm/renderRecipe) : meme contrat, categorie FIXE ---

    public function testRecipeFormPassesProductCategoryIdCategoryFamiliesAndIngredientFamilies(): void
    {
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'category_id' => 3, 'name' => 'Big Mac'];
        // Correspondance de PLUSIEURS categories : la page recette ne doit PAS la
        // retailler cote serveur, elle passe la carte COMPLETE (meme forme que
        // renderForm) + productCategoryId pour que le client sache quelle entree lire.
        $db->categoryIngredientFamilyRows = [
            ['category_id' => 3, 'family' => 'viande'],
            ['category_id' => 3, 'family' => 'pain'],
            ['category_id' => 7, 'family' => 'dessert'],
        ];

        $controller = $this->controller($this->get('/admin/products/5/recipe'), $db);
        $response = $controller->recipeForm(['id' => '5']);

        self::assertSame(200, $response->status());

        $data = $controller->capturedViewData;
        self::assertSame(3, $data['productCategoryId']);
        // Forme complete, NON filtree a la seule categorie du produit : la
        // categorie 7 (etrangere a ce produit) reste presente.
        self::assertSame(['viande', 'pain'], $data['categoryFamilies'][3]);
        self::assertSame(['dessert'], $data['categoryFamilies'][7]);
        self::assertSame(IngredientFamily::labels(), $data['ingredientFamilies']);
    }

    public function testRecipeFormExposesZeroCategoryIdWhenProductCategoryIsMissing(): void
    {
        // Garde defensive : un produit sans category_id lisible (ligne corrompue,
        // jamais en usage normal -- category_id est NOT NULL en base) ne doit pas
        // faire planter la page ; productCategoryId retombe a 0, une categorie
        // qu'aucune ligne category_ingredient_family ne peut porter (FK > 0).
        $db = $this->permittedDb();
        $db->productRow = ['id' => 5, 'name' => 'Big Mac'];

        $controller = $this->controller($this->get('/admin/products/5/recipe'), $db);
        $controller->recipeForm(['id' => '5']);

        self::assertSame(0, $controller->capturedViewData['productCategoryId']);
    }
}
