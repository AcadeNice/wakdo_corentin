<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Catalogue\AllergenRepository;
use App\Catalogue\IngredientRepository;
use App\Catalogue\ProductRepository;
use App\Core\Config;
use App\Core\Database;

/**
 * Allergenes calcules par produit (F11b) contre une vraie MariaDB. Auto-skip si
 * WAKDO_DB_TESTS != 1. Produit (it-alg-prod-*) et ingredients (it-alg-*) jetables.
 *
 * Ce que seule une vraie base peut prouver ici :
 *  - la chaine de jointure product_ingredient -> ingredient_allergen -> allergen
 *    remonte bien les allergenes du catalogue reel (les 14 seedes) ;
 *  - la DEDUPLICATION SQL tient quand deux ingredients du meme produit portent le
 *    meme allergene (cas du pain et de la panure) ;
 *  - la colonne allergens_reviewed_at distingue reellement "verifie sans allergene"
 *    de "jamais regarde" ;
 *  - les quatre ecritures de setAllergens sont atomiques et la trace d'audit part.
 *
 * teardown FK-safe : supprimer le produit (CASCADE emporte sa composition, ce qui
 * libere les ingredients de la FK RESTRICT sur product_ingredient), puis les liens
 * d'allergenes (CASCADE sur ingredient_id de toute facon), puis les ingredients,
 * puis les lignes d'audit du test.
 */
final class AllergenByProductDbTest extends TestCase
{
    private Database $db;
    private string $product = '';
    private string $ingA = '';
    private string $ingB = '';
    private int $categoryId = 0;
    private int $glutenId = 0;
    private int $milkId = 0;

    protected function setUp(): void
    {
        if (getenv('WAKDO_DB_TESTS') !== '1') {
            self::markTestSkipped('Tests DB desactives (definir WAKDO_DB_TESTS=1 + DB_*).');
        }

        $this->db = new Database(new Config());

        try {
            $this->db->fetch('SELECT 1');
        } catch (Throwable $exception) {
            self::markTestSkipped('Base injoignable: ' . $exception->getMessage());
        }

        $this->categoryId = (int) ($this->db->fetch('SELECT id FROM category ORDER BY id LIMIT 1')['id'] ?? 0);
        // Les ids d'allergenes viennent du CATALOGUE REEL (seed 0001), pas de constantes :
        // un renumerotage du seed ne doit pas casser le test silencieusement.
        $this->glutenId = (int) ($this->db->fetch('SELECT id FROM allergen WHERE code = :c', ['c' => 'gluten'])['id'] ?? 0);
        $this->milkId = (int) ($this->db->fetch('SELECT id FROM allergen WHERE code = :c', ['c' => 'milk'])['id'] ?? 0);
        self::assertGreaterThan(0, $this->glutenId, 'le seed INCO doit porter le code gluten');
        self::assertGreaterThan(0, $this->milkId, 'le seed INCO doit porter le code milk');

        $suffix = bin2hex(random_bytes(4));
        $this->product = 'it-alg-prod-' . $suffix;
        $this->ingA = 'it-alg-a-' . $suffix;
        $this->ingB = 'it-alg-b-' . $suffix;
    }

    protected function tearDown(): void
    {
        if ($this->product === '') {
            return;
        }

        $pid = (int) ($this->db->fetch('SELECT id FROM product WHERE name = :n', ['n' => $this->product])['id'] ?? 0);
        if ($pid > 0) {
            $this->db->execute('DELETE FROM product WHERE id = :id', ['id' => $pid]);
        }

        foreach ([$this->ingA, $this->ingB] as $name) {
            $iid = (int) ($this->db->fetch('SELECT id FROM ingredient WHERE name = :n', ['n' => $name])['id'] ?? 0);
            if ($iid > 0) {
                $this->db->execute('DELETE FROM audit_log WHERE entity_type = :t AND entity_id = :id', ['t' => 'ingredient', 'id' => $iid]);
                $this->db->execute('DELETE FROM ingredient_allergen WHERE ingredient_id = :id', ['id' => $iid]);
                $this->db->execute('DELETE FROM stock_movement WHERE ingredient_id = :id', ['id' => $iid]);
                $this->db->execute('DELETE FROM ingredient WHERE id = :id', ['id' => $iid]);
            }
        }
    }

    public function testJoinsRecipeToTheRealIncoCatalogue(): void
    {
        [$pid, $iaId] = $this->fixtureWithTwoIngredients();
        $allergens = new AllergenRepository($this->db);
        (new IngredientRepository($this->db))->setAllergens(
            $iaId,
            [['id' => $this->glutenId, 'name' => 'Gluten']],
            'Test integration',
            null,
            null,
        );

        $byProduct = $allergens->byProduct();

        self::assertArrayHasKey($pid, $byProduct);
        self::assertSame([$this->glutenId], array_column($byProduct[$pid], 'id'));
        // Le libelle vient du catalogue seede, pas du parametre passe a l'ecriture.
        self::assertSame('gluten', $byProduct[$pid][0]['code']);
        self::assertNotSame('', $byProduct[$pid][0]['name']);
    }

    public function testDeduplicatesAnAllergenCarriedByTwoIngredients(): void
    {
        [$pid, $iaId, $ibId] = $this->fixtureWithTwoIngredients();
        $ingredients = new IngredientRepository($this->db);
        // Le cas reel : le pain ET la panure apportent tous deux du gluten.
        $ingredients->setAllergens($iaId, [['id' => $this->glutenId, 'name' => 'Gluten']], 'Test', null, null);
        $ingredients->setAllergens($ibId, [['id' => $this->glutenId, 'name' => 'Gluten']], 'Test', null, null);

        $forProduct = (new AllergenRepository($this->db))->forProduct($pid);

        // Une seule entree : afficher "Gluten" deux fois ferait douter le client de la
        // fiabilite de l'information.
        self::assertCount(1, $forProduct);
        self::assertSame($this->glutenId, $forProduct[0]['id']);
    }

    public function testUnionsAllergensOfDifferentIngredients(): void
    {
        [$pid, $iaId, $ibId] = $this->fixtureWithTwoIngredients();
        $ingredients = new IngredientRepository($this->db);
        $ingredients->setAllergens($iaId, [['id' => $this->glutenId, 'name' => 'Gluten']], 'Test', null, null);
        $ingredients->setAllergens($ibId, [['id' => $this->milkId, 'name' => 'Lait']], 'Test', null, null);

        $ids = array_column((new AllergenRepository($this->db))->forProduct($pid), 'id');

        sort($ids);
        $expected = [$this->glutenId, $this->milkId];
        sort($expected);
        self::assertSame($expected, $ids);
    }

    public function testAnUnreviewedIngredientMakesTheProductIncomplete(): void
    {
        [$pid, $iaId] = $this->fixtureWithTwoIngredients();
        // Un seul des deux ingredients est revu : le produit reste incomplet.
        (new IngredientRepository($this->db))->setAllergens($iaId, [['id' => $this->glutenId, 'name' => 'Gluten']], 'Test', null, null);
        $allergens = new AllergenRepository($this->db);

        self::assertSame(1, $allergens->unreviewedCountForProduct($pid));
        self::assertArrayHasKey($pid, $allergens->unreviewedByProduct());
    }

    public function testReviewingEveryIngredientMakesTheProductComplete(): void
    {
        [$pid, $iaId, $ibId] = $this->fixtureWithTwoIngredients();
        $ingredients = new IngredientRepository($this->db);
        $ingredients->setAllergens($iaId, [['id' => $this->glutenId, 'name' => 'Gluten']], 'Test', null, null);
        // Le second est revu SANS allergene : c'est une affirmation, pas un silence.
        $ingredients->setAllergens($ibId, [], 'Test', null, null);
        $allergens = new AllergenRepository($this->db);

        self::assertSame(0, $allergens->unreviewedCountForProduct($pid));
        self::assertArrayNotHasKey($pid, $allergens->unreviewedByProduct());
        // Et la liste du produit ne contient que le gluten du premier ingredient.
        self::assertCount(1, $allergens->forProduct($pid));
    }

    public function testAnEmptyReviewIsDistinguishableFromNoReviewInTheColumn(): void
    {
        [, $iaId, $ibId] = $this->fixtureWithTwoIngredients();
        (new IngredientRepository($this->db))->setAllergens($ibId, [], 'Emballage', null, null);

        $reviewed = $this->db->fetch('SELECT allergens_reviewed_at, allergens_source FROM ingredient WHERE id = :id', ['id' => $ibId]);
        $untouched = $this->db->fetch('SELECT allergens_reviewed_at, allergens_source FROM ingredient WHERE id = :id', ['id' => $iaId]);

        // Les deux ingredients ont ZERO allergene lie. Seule la colonne les distingue :
        // sans elle, la borne ne pourrait pas dire "verifie" pour l'un et "on ne sait
        // pas" pour l'autre.
        self::assertArrayHasKey('allergens_reviewed_at', $reviewed);
        self::assertNotNull($reviewed['allergens_reviewed_at']);
        self::assertSame('Emballage', $reviewed['allergens_source']);
        self::assertNull($untouched['allergens_reviewed_at']);
    }

    public function testFindExposesTheReviewColumnsToTheBackOfficeForm(): void
    {
        [, $iaId] = $this->fixtureWithTwoIngredients();
        $ingredients = new IngredientRepository($this->db);
        $ingredients->setAllergens($iaId, [['id' => $this->glutenId, 'name' => 'Gluten']], 'Fiche fournisseur', null, null);

        $row = $ingredients->find($iaId);

        // find() et all() listent leurs colonnes EXPLICITEMENT : oublier les deux
        // nouvelles ferait afficher "Jamais revu" a un ingredient pourtant revu. Un
        // double de base ne peut pas attraper ca -- il rend la ligne qu'on lui donne,
        // pas celle que le SQL demande. D'ou ce test contre la vraie base.
        self::assertNotNull($row);
        self::assertArrayHasKey('allergens_reviewed_at', $row);
        self::assertArrayHasKey('allergens_source', $row);
        self::assertNotNull($row['allergens_reviewed_at']);
        self::assertSame('Fiche fournisseur', $row['allergens_source']);
    }

    public function testAllAlsoExposesTheReviewColumns(): void
    {
        [, $iaId] = $this->fixtureWithTwoIngredients();
        (new IngredientRepository($this->db))->setAllergens($iaId, [], 'Emballage', null, null);

        $rows = (new IngredientRepository($this->db))->all();
        $mine = array_values(array_filter($rows, fn (array $r): bool => (int) $r['id'] === $iaId));

        // Le rappel de la page Stock compte sur cette colonne : absente, il compterait
        // TOUS les ingredients comme non revus.
        self::assertCount(1, $mine);
        self::assertArrayHasKey('allergens_reviewed_at', $mine[0]);
        self::assertNotNull($mine[0]['allergens_reviewed_at']);
    }

    public function testReplacingASetLeavesNoStaleLink(): void
    {
        [, $iaId] = $this->fixtureWithTwoIngredients();
        $ingredients = new IngredientRepository($this->db);
        $ingredients->setAllergens($iaId, [['id' => $this->glutenId, 'name' => 'Gluten'], ['id' => $this->milkId, 'name' => 'Lait']], 'v1', null, null);
        $ingredients->setAllergens($iaId, [['id' => $this->milkId, 'name' => 'Lait']], 'v2', null, null);

        $ids = (new AllergenRepository($this->db))->allergenIdsForIngredient($iaId);

        // Le delete-and-reinsert doit REMPLACER : un gluten survivant a la correction
        // ferait afficher un allergene retire a dessein.
        self::assertSame([$this->milkId], $ids);
    }

    public function testReviewWritesAnAuditRowNamingTheAllergens(): void
    {
        [, $iaId] = $this->fixtureWithTwoIngredients();
        (new IngredientRepository($this->db))->setAllergens(
            $iaId,
            [['id' => $this->glutenId, 'name' => 'Gluten']],
            'Fiche fournisseur',
            null,
            null,
        );

        $row = $this->db->fetch(
            'SELECT action_code, summary FROM audit_log WHERE entity_type = :t AND entity_id = :id ORDER BY id DESC LIMIT 1',
            ['t' => 'ingredient', 'id' => $iaId],
        );

        self::assertNotNull($row);
        self::assertSame('ingredient.allergens', $row['action_code']);
        self::assertStringContainsString('Gluten', (string) $row['summary']);
        self::assertStringContainsString('Fiche fournisseur', (string) $row['summary']);
    }

    public function testStockIsUntouchedByAnAllergenReview(): void
    {
        [, $iaId] = $this->fixtureWithTwoIngredients();
        $before = $this->db->fetch('SELECT stock_quantity FROM ingredient WHERE id = :id', ['id' => $iaId]);
        $movementsBefore = (int) ($this->db->fetch('SELECT COUNT(*) AS n FROM stock_movement WHERE ingredient_id = :id', ['id' => $iaId])['n'] ?? 0);

        (new IngredientRepository($this->db))->setAllergens($iaId, [['id' => $this->glutenId, 'name' => 'Gluten']], 'Test', null, null);

        $after = $this->db->fetch('SELECT stock_quantity FROM ingredient WHERE id = :id', ['id' => $iaId]);
        $movementsAfter = (int) ($this->db->fetch('SELECT COUNT(*) AS n FROM stock_movement WHERE ingredient_id = :id', ['id' => $iaId])['n'] ?? 0);

        // Declarer un allergene est une information, pas un geste de stock. Toute
        // ecriture sur stock_quantity ou stock_movement serait une regression grave :
        // elle fabriquerait ou detruirait de la marchandise dans le journal.
        self::assertSame($before['stock_quantity'], $after['stock_quantity']);
        self::assertSame($movementsBefore, $movementsAfter);
    }

    public function testRealCatalogueExposesTheFourteenIncoCodes(): void
    {
        $codes = array_column((new AllergenRepository($this->db))->all(), 'code');

        // Verrou sur le socle : les 14 codes du reglement doivent exister avant qu'un
        // seed de revue puisse s'y rattacher (FK RESTRICT sur allergen_id).
        foreach (['gluten', 'crustaceans', 'eggs', 'fish', 'peanuts', 'soybeans', 'milk',
                  'nuts', 'celery', 'mustard', 'sesame', 'sulphites', 'lupin', 'molluscs'] as $code) {
            self::assertContains($code, $codes, "le code INCO {$code} doit etre reference");
        }
    }

    /**
     * Produit jetable avec deux ingredients requis. Renvoie [productId, ingA, ingB].
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function fixtureWithTwoIngredients(): array
    {
        $products = new ProductRepository($this->db);
        $ingredients = new IngredientRepository($this->db);

        $products->create([
            'category_id' => $this->categoryId,
            'name' => $this->product,
            'description' => null,
            'price_cents' => 590,
            'size_cl' => null,
            'base_product_id' => null,
            'maxi_variant_product_id' => null,
            'vat_rate' => 100,
            'image_path' => null,
            'is_available' => 1,
            'display_order' => 99,
        ]);
        $pid = (int) ($this->db->fetch('SELECT id FROM product WHERE name = :n', ['n' => $this->product])['id'] ?? 0);

        $ids = [];
        foreach ([$this->ingA, $this->ingB] as $name) {
            $ingredients->create([
                'name' => $name,
                'unit' => 'portion',
                'stock_quantity' => 50,
                'stock_capacity' => 100,
                'pack_size' => 1,
                'pack_label' => null,
                'low_stock_pct' => 10,
                'critical_stock_pct' => 5,
                'is_active' => 1,
            ]);
            $ids[] = (int) ($this->db->fetch('SELECT id FROM ingredient WHERE name = :n', ['n' => $name])['id'] ?? 0);
        }

        $products->setComposition($pid, array_map(
            static fn (int $ingredientId): array => [
                'ingredient_id'     => $ingredientId,
                'quantity_normal'   => 1,
                'quantity_maxi'     => 1,
                'is_removable'      => 0,
                'is_addable'        => 0,
                'extra_price_cents' => 0,
            ],
            $ids,
        ));

        return [$pid, $ids[0], $ids[1]];
    }
}
