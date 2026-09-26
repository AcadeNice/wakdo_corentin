<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Catalogue\ImportBlockedException;
use App\Catalogue\ProductImportService;
use App\Core\Config;
use App\Core\Database;

/**
 * ProductImportService contre une vraie MariaDB (schema migre + seede).
 * Auto-skip si WAKDO_DB_TESTS != 1. Categorie (it-imp-cat-*), produits
 * (it-imp-prod-*) et ingredients (it-imp-ing-*) jetables.
 *
 * Couvre ce que le double FakeDatabase (tests/Unit/Catalogue/
 * ProductImportServiceTest.php) ne peut pas prouver seul : la RECONCILIATION
 * reelle (categorie/produit/ingredient existants, lus en base), le remplacement
 * INCONDITIONNEL de la recette d'un produit existant (decision produit
 * 2026-09-26), et l'ecriture reellement TOUT OU RIEN.
 *
 * Sur le rollback : aucune valeur issue du CSV ne peut violer une CHECK de
 * table sans que preview() l'ait deja refusee (prix/TVA/quantites/seuils
 * d'ingredient nouveau sont tous poses/valides en amont) -- la transaction ne
 * peut donc pas echouer EN COURS d'ecriture sur un contenu naturellement
 * invalide. Le test de "rien de partiel" ici exploite le scenario REEL le plus
 * probable en production : l'etat a change entre l'apercu et la confirmation
 * (une categorie supprimee entre-temps) -- apply() rejoue preview() et refuse
 * AVANT d'ouvrir la transaction, donc zero ecriture, verifie directement en
 * base. Le mecanisme generique ("une exception PENDANT la transaction annule
 * tout, quelle qu'en soit la cause") est prouve separement par
 * ProductImportServiceTest::testApplyIsAllOrNothingOnFirstWriteFailure
 * (FakeDatabase::failOnExecute).
 */
final class ProductImportServiceDbTest extends TestCase
{
    private Database $db;
    private ProductImportService $service;
    private int $categoryId = 0;
    private string $categoryName = '';
    /** @var list<string> */
    private array $productNames = [];
    /** @var list<string> */
    private array $ingredientNames = [];

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

        $this->service = new ProductImportService();
        $suffix = bin2hex(random_bytes(4));
        $this->categoryName = 'it-imp-cat-' . $suffix;
        $this->db->execute(
            'INSERT INTO category (name, slug, display_order, is_active) VALUES (:n, :s, 999, 1)',
            ['n' => $this->categoryName, 's' => 'it-imp-cat-' . $suffix],
        );
        $this->categoryId = (int) ($this->db->fetch('SELECT id FROM category WHERE name = :n', ['n' => $this->categoryName])['id'] ?? 0);
    }

    protected function tearDown(): void
    {
        if ($this->categoryId === 0) {
            return;
        }

        foreach ($this->productNames as $name) {
            $pid = (int) ($this->db->fetch('SELECT id FROM product WHERE name = :n AND category_id = :c', ['n' => $name, 'c' => $this->categoryId])['id'] ?? 0);
            if ($pid > 0) {
                $this->db->execute('DELETE FROM product WHERE id = :id', ['id' => $pid]); // CASCADE product_ingredient
            }
        }
        foreach ($this->ingredientNames as $name) {
            $iid = (int) ($this->db->fetch('SELECT id FROM ingredient WHERE name = :n', ['n' => $name])['id'] ?? 0);
            if ($iid > 0) {
                $this->db->execute('DELETE FROM stock_movement WHERE ingredient_id = :id', ['id' => $iid]);
                $this->db->execute('DELETE FROM ingredient WHERE id = :id', ['id' => $iid]);
            }
        }
        // audit_log (append-only) n'est PAS nettoye ligne par ligne ici : cette
        // suite tourne contre une base EPHEMERE (docker jetable, php-tests.sh),
        // detruite en fin de run -- une ligne d'audit residuelle n'y a aucun cout.
        $this->db->execute('DELETE FROM category WHERE id = :id', ['id' => $this->categoryId]);
    }

    private function header(): string
    {
        return implode(';', ProductImportService::COLUMNS);
    }

    private function csvLine(string $product, string $price, string $ingredient, string $unit, string $qty): string
    {
        return sprintf('%d;%s;;%s;10;;oui;%s;%s;%s;non;non', $this->categoryId, $product, $price, $ingredient, $unit, $qty);
    }

    public function testPreviewClassifiesCreateUpdateAndFlagsPriceChange(): void
    {
        $existingName = 'it-imp-prod-existing-' . bin2hex(random_bytes(3));
        $this->productNames[] = $existingName;
        $this->db->execute(
            'INSERT INTO product (category_id, name, price_cents, vat_rate, is_available, display_order) '
            . 'VALUES (:cat, :name, 590, 100, 1, 5)',
            ['cat' => $this->categoryId, 'name' => $existingName],
        );

        $newName = 'it-imp-prod-new-' . bin2hex(random_bytes(3));
        $this->productNames[] = $newName;

        $csv = $this->header() . "\r\n"
            . $this->csvLine($existingName, '6,90', '', '', '') . "\r\n" // prix 590 -> 690 : changement
            . $this->csvLine($newName, '4,50', '', '', '') . "\r\n";

        $report = $this->service->preview($csv, $this->db);

        self::assertSame([], $report['errors']);
        self::assertCount(1, $report['productsToCreate']);
        self::assertCount(1, $report['productsToUpdate']);
        self::assertSame($newName, $report['productsToCreate'][0]['name']);
        self::assertSame($existingName, $report['productsToUpdate'][0]['name']);
        self::assertTrue($report['productsToUpdate'][0]['price_changed']);
        self::assertTrue($report['hasPriceChange']);
    }

    public function testApplyCreatesProductWithNewAndExistingIngredientsAtomically(): void
    {
        $existingIngredient = 'it-imp-ing-existing-' . bin2hex(random_bytes(3));
        $this->ingredientNames[] = $existingIngredient;
        $this->db->execute(
            'INSERT INTO ingredient (name, unit, stock_quantity, stock_capacity, pack_size, low_stock_pct, critical_stock_pct, is_active) '
            . 'VALUES (:n, :u, 50, 100, 1, 10, 5, 1)',
            ['n' => $existingIngredient, 'u' => 'unite'],
        );

        $newIngredient = 'it-imp-ing-new-' . bin2hex(random_bytes(3));
        $this->ingredientNames[] = $newIngredient;
        $productName = 'it-imp-prod-atomic-' . bin2hex(random_bytes(3));
        $this->productNames[] = $productName;

        $csv = $this->header() . "\r\n"
            . $this->csvLine($productName, '6,90', $existingIngredient, 'unite', '2') . "\r\n"
            . $this->csvLine($productName, '6,90', $newIngredient, 'g', '30') . "\r\n";

        $result = $this->service->apply($csv, $this->db, null, null);

        self::assertSame(1, $result['created']);
        self::assertSame(1, $result['ingredients_created']);

        $product = $this->db->fetch('SELECT id, price_cents FROM product WHERE name = :n AND category_id = :c', ['n' => $productName, 'c' => $this->categoryId]);
        self::assertNotNull($product);
        self::assertSame(690, (int) $product['price_cents']);

        $newIngredientRow = $this->db->fetch('SELECT id, stock_quantity, stock_capacity FROM ingredient WHERE name = :n', ['n' => $newIngredient]);
        self::assertNotNull($newIngredientRow);
        self::assertSame(0, (int) $newIngredientRow['stock_quantity']); // RG-CREATE-ING
        self::assertSame(100, (int) $newIngredientRow['stock_capacity']); // defaut du projet

        $composition = $this->db->fetchAll(
            'SELECT ingredient_id, quantity_normal FROM product_ingredient WHERE product_id = :id',
            ['id' => (int) $product['id']],
        );
        self::assertCount(2, $composition);
    }

    public function testApplyReplacesExistingProductRecipeEntirely(): void
    {
        $productName = 'it-imp-prod-replace-' . bin2hex(random_bytes(3));
        $this->productNames[] = $productName;
        $oldIngredient = 'it-imp-ing-old-' . bin2hex(random_bytes(3));
        $newIngredient = 'it-imp-ing-fresh-' . bin2hex(random_bytes(3));
        $this->ingredientNames[] = $oldIngredient;
        $this->ingredientNames[] = $newIngredient;

        $this->db->execute(
            'INSERT INTO ingredient (name, unit, stock_quantity, stock_capacity, pack_size, low_stock_pct, critical_stock_pct, is_active) '
            . 'VALUES (:n, :u, 50, 100, 1, 10, 5, 1)',
            ['n' => $oldIngredient, 'u' => 'unite'],
        );
        $oldIngredientId = (int) ($this->db->fetch('SELECT id FROM ingredient WHERE name = :n', ['n' => $oldIngredient])['id'] ?? 0);

        $this->db->execute(
            'INSERT INTO product (category_id, name, price_cents, vat_rate, is_available, display_order) VALUES (:cat, :name, 690, 100, 1, 5)',
            ['cat' => $this->categoryId, 'name' => $productName],
        );
        $productId = (int) ($this->db->fetch('SELECT id FROM product WHERE name = :n AND category_id = :c', ['n' => $productName, 'c' => $this->categoryId])['id'] ?? 0);
        $this->db->execute(
            'INSERT INTO product_ingredient (product_id, ingredient_id, quantity_normal, quantity_maxi, is_removable, is_addable, extra_price_cents) VALUES (:p, :i, 1, 1, 0, 0, 0)',
            ['p' => $productId, 'i' => $oldIngredientId],
        );

        // Le CSV ne mentionne PLUS l'ancien ingredient, seulement le nouveau :
        // la recette est REMPLACEE (decision produit confirmee), pas fusionnee.
        $csv = $this->header() . "\r\n" . $this->csvLine($productName, '6,90', $newIngredient, 'g', '15') . "\r\n";

        $this->service->apply($csv, $this->db, null, null);

        $composition = $this->db->fetchAll(
            'SELECT i.name FROM product_ingredient pi JOIN ingredient i ON i.id = pi.ingredient_id WHERE pi.product_id = :id',
            ['id' => $productId],
        );
        $names = array_map(static fn (array $r): string => (string) $r['name'], $composition);
        self::assertSame([$newIngredient], $names, 'La recette du fichier doit REMPLACER entierement l\'ancienne, pas la completer.');
    }

    public function testApplyEmptiesRecipeWhenCsvHasNoIngredientRowForExistingProduct(): void
    {
        $productName = 'it-imp-prod-emptied-' . bin2hex(random_bytes(3));
        $this->productNames[] = $productName;
        $ingredient = 'it-imp-ing-toclear-' . bin2hex(random_bytes(3));
        $this->ingredientNames[] = $ingredient;

        $this->db->execute(
            'INSERT INTO ingredient (name, unit, stock_quantity, stock_capacity, pack_size, low_stock_pct, critical_stock_pct, is_active) VALUES (:n, :u, 50, 100, 1, 10, 5, 1)',
            ['n' => $ingredient, 'u' => 'unite'],
        );
        $ingredientId = (int) ($this->db->fetch('SELECT id FROM ingredient WHERE name = :n', ['n' => $ingredient])['id'] ?? 0);
        $this->db->execute(
            'INSERT INTO product (category_id, name, price_cents, vat_rate, is_available, display_order) VALUES (:cat, :name, 690, 100, 1, 5)',
            ['cat' => $this->categoryId, 'name' => $productName],
        );
        $productId = (int) ($this->db->fetch('SELECT id FROM product WHERE name = :n AND category_id = :c', ['n' => $productName, 'c' => $this->categoryId])['id'] ?? 0);
        $this->db->execute(
            'INSERT INTO product_ingredient (product_id, ingredient_id, quantity_normal, quantity_maxi, is_removable, is_addable, extra_price_cents) VALUES (:p, :i, 1, 1, 0, 0, 0)',
            ['p' => $productId, 'i' => $ingredientId],
        );

        // Meme produit (nom+categorie), ligne SANS ingredient : documente + teste
        // (docs/api/import-produits.md section "Rapprochement d'un produit existant").
        $csv = $this->header() . "\r\n" . $this->csvLine($productName, '6,90', '', '', '') . "\r\n";

        $report = $this->service->preview($csv, $this->db);
        $classified = array_merge($report['productsToUpdate'], $report['productsUnchanged']);
        self::assertCount(1, $classified, 'Le produit existant doit etre classe (mis a jour ou inchange), jamais recree.');
        self::assertSame(0, $classified[0]['recipe_line_count']);

        $this->service->apply($csv, $this->db, null, null);

        $composition = $this->db->fetchAll('SELECT ingredient_id FROM product_ingredient WHERE product_id = :id', ['id' => $productId]);
        self::assertCount(0, $composition);
    }

    public function testApplyWritesNothingWhenCategoryDisappearsBetweenPreviewAndConfirm(): void
    {
        $productName = 'it-imp-prod-stale-' . bin2hex(random_bytes(3));
        $this->productNames[] = $productName;
        $csv = $this->header() . "\r\n" . $this->csvLine($productName, '5,00', '', '', '') . "\r\n";

        $report = $this->service->preview($csv, $this->db);
        self::assertSame([], $report['errors']); // valide au moment de l'apercu

        // La categorie disparait avant la confirmation (etat perime, RG-T18).
        $this->db->execute('DELETE FROM category WHERE id = :id', ['id' => $this->categoryId]);

        try {
            $this->service->apply($csv, $this->db, null, null);
            self::fail('apply() aurait du refuser (categorie disparue entre apercu et confirmation).');
        } catch (ImportBlockedException $exception) {
            self::assertStringContainsString('erreurs', $exception->getMessage());
        } finally {
            // Recree la categorie pour que tearDown() la retrouve et la supprime proprement.
            $this->db->execute(
                'INSERT INTO category (id, name, slug, display_order, is_active) VALUES (:id, :n, :s, 999, 1)',
                ['id' => $this->categoryId, 'n' => $this->categoryName, 's' => 'it-imp-cat-' . bin2hex(random_bytes(2))],
            );
        }

        self::assertNull($this->db->fetch('SELECT id FROM product WHERE name = :n', ['n' => $productName]), 'Aucun produit ne doit avoir ete cree (tout ou rien).');
    }

    /**
     * Relecture adverse (2026-09-26) : avant le correctif, ce cas passait
     * l'aperçu SANS erreur puis faisait échouer apply() contre la vraie
     * colonne `ingredient.name` VARCHAR(120) (exception PDO non rattrapée,
     * page d'erreur brute). Preuve contre une vraie base : preview() refuse
     * DEJA, et apply() rejette proprement (ImportBlockedException), sans
     * qu'aucune ligne ne soit écrite.
     */
    public function testApplyRejectsIngredientNameTooLongInsteadOfFailingAtWriteTime(): void
    {
        $productName = 'it-imp-prod-longname-' . bin2hex(random_bytes(3));
        $this->productNames[] = $productName;
        $longIngredientName = str_repeat('a', 200);

        $csv = $this->header() . "\r\n" . $this->csvLine($productName, '5,00', $longIngredientName, 'g', '1') . "\r\n";

        $report = $this->service->preview($csv, $this->db);
        self::assertNotSame([], $report['errors'], 'L\'apercu doit deja refuser un nom d\'ingredient trop long, pas laisser passer.');

        try {
            $this->service->apply($csv, $this->db, null, null);
            self::fail('apply() aurait du refuser (nom d\'ingrédient trop long).');
        } catch (ImportBlockedException $exception) {
            self::assertStringContainsString('erreurs', $exception->getMessage());
        }

        self::assertNull($this->db->fetch('SELECT id FROM product WHERE name = :n', ['n' => $productName]));
        self::assertNull($this->db->fetch('SELECT id FROM ingredient WHERE name = :n', ['n' => $longIngredientName]));
    }

    public function testApplyWritesSingleAuditLogRowWithCounts(): void
    {
        $productName = 'it-imp-prod-audit-' . bin2hex(random_bytes(3));
        $this->productNames[] = $productName;
        $csv = $this->header() . "\r\n" . $this->csvLine($productName, '3,20', '', '', '') . "\r\n";

        $this->service->apply($csv, $this->db, 1, 1);

        $audit = $this->db->fetch(
            "SELECT summary FROM audit_log WHERE action_code = 'product.import' AND summary LIKE :s ORDER BY id DESC LIMIT 1",
            ['s' => '%1 créé%'],
        );
        self::assertNotNull($audit);
        self::assertStringContainsString('1 créé', (string) $audit['summary']);
    }
}
