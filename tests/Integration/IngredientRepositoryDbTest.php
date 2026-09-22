<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PDOException;
use PHPUnit\Framework\TestCase;
use Throwable;
use App\Catalogue\IngredientRepository;
use App\Core\Config;
use App\Core\Database;

/**
 * Comportement reel d'IngredientRepository contre une vraie MariaDB (schema migre
 * + seede). Auto-skip si WAKDO_DB_TESTS != 1. Ingredient jetable (nom it-ing-*) ;
 * nettoyage en tearDown : on retire d'abord ses mouvements (FK stock_movement
 * RESTRICT) puis l'ingredient.
 */
final class IngredientRepositoryDbTest extends TestCase
{
    private Database $db;
    private string $name = '';
    private int $userId = 0;

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

        $this->userId = (int) ($this->db->fetch('SELECT id FROM user ORDER BY id LIMIT 1')['id'] ?? 0);
        $this->name = 'it-ing-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->name === '') {
            return;
        }
        $id = (int) ($this->db->fetch('SELECT id FROM ingredient WHERE name = :n', ['n' => $this->name])['id'] ?? 0);
        if ($id > 0) {
            $this->db->execute('DELETE FROM stock_movement WHERE ingredient_id = :id', ['id' => $id]);
            $this->db->execute('DELETE FROM ingredient WHERE id = :id', ['id' => $id]);
        }
    }

    public function testCreateFindUpdateComputesPctAndBand(): void
    {
        $repo = new IngredientRepository($this->db);
        $id = $this->createIngredient($repo, ['stock_quantity' => 50, 'stock_capacity' => 100]);

        self::assertFalse($repo->nameExists($this->name, $id)); // s'exclut lui-meme
        self::assertTrue($repo->nameExists($this->name));
        self::assertFalse($repo->isReferenced($id));            // ni recette ni mouvement

        $found = $repo->find($id);
        self::assertNotNull($found);
        self::assertSame(50, (int) $found['stock_pct']);
        self::assertSame('normal', (string) $found['stock_band']);

        // all() porte aussi les champs calcules.
        $names = array_map(static fn (array $r): string => (string) ($r['name'] ?? ''), $repo->all());
        self::assertContains($this->name, $names);

        // update ne touche ni stock_quantity ni is_active (allowlist RG-T16).
        $repo->update($id, [
            'name' => $this->name,
            'unit' => 'sachet',
            'stock_capacity' => 200,
            'pack_size' => 25,
            'pack_label' => 'Sac 25',
            'low_stock_pct' => 20,
            'critical_stock_pct' => 10,
        ]);
        $updated = $repo->find($id);
        self::assertNotNull($updated);
        self::assertSame(200, (int) $updated['stock_capacity']);
        self::assertSame(50, (int) $updated['stock_quantity']); // inchange
        self::assertSame(25, (int) $updated['stock_pct']);      // 50/200
    }

    public function testRestockIncrementsStockAndRecordsMovement(): void
    {
        $repo = new IngredientRepository($this->db);
        $id = $this->createIngredient($repo, ['stock_quantity' => 0, 'stock_capacity' => 100, 'pack_size' => 50]);

        $repo->restock($id, 2, $this->userId, 'Livraison A');

        self::assertSame(100, (int) ($repo->find($id)['stock_quantity'] ?? -1));
        $movements = $repo->movements($id);
        self::assertCount(1, $movements);
        self::assertSame('restock', (string) $movements[0]['movement_type']);
        self::assertSame(100, (int) $movements[0]['delta']);
        self::assertSame($this->userId, (int) $movements[0]['user_id']);
        self::assertNull($movements[0]['order_id']);
        self::assertTrue($repo->isReferenced($id)); // un mouvement reference l'ingredient
    }

    public function testInventoryCountRecordsMovementEvenWhenDeltaZero(): void
    {
        $repo = new IngredientRepository($this->db);
        $id = $this->createIngredient($repo, ['stock_quantity' => 100, 'stock_capacity' => 100]);

        // Comptage conforme au theorique : delta = 0, MAIS une ligne est ecrite (RG-3).
        $repo->inventoryCount($id, 100, $this->userId, 'Inventaire mensuel');
        $movements = $repo->movements($id);
        self::assertCount(1, $movements);
        self::assertSame('inventory_correction', (string) $movements[0]['movement_type']);
        self::assertSame(0, (int) $movements[0]['delta']);

        // Comptage divergent : delta negatif, stock cale sur le compte.
        $repo->inventoryCount($id, 30, $this->userId, null);
        self::assertSame(30, (int) ($repo->find($id)['stock_quantity'] ?? -1));
        $movements = $repo->movements($id);
        self::assertCount(2, $movements);                       // plus recent en tete
        self::assertSame(-70, (int) $movements[0]['delta']);
    }

    public function testRestockClampsToCapacityAndRecordsEffectiveDelta(): void
    {
        $repo = new IngredientRepository($this->db);
        // 290/300 : un pack de 50 demanderait 340 -> cale a 300 (capacite = plafond strict).
        $id = $this->createIngredient($repo, ['stock_quantity' => 290, 'stock_capacity' => 300, 'pack_size' => 50]);

        $repo->restock($id, 1, $this->userId, 'Livraison pleine');

        $found = $repo->find($id);
        self::assertSame(300, (int) ($found['stock_quantity'] ?? -1)); // plafonne, pas 340
        self::assertSame(100, (int) ($found['stock_pct'] ?? -1));      // jamais > 100 %
        $movements = $repo->movements($id);
        self::assertSame('restock', (string) $movements[0]['movement_type']);
        self::assertSame(10, (int) $movements[0]['delta']);            // delta REELLEMENT applique (300-290), pas 50
    }

    public function testInventoryCountClampsToCapacity(): void
    {
        $repo = new IngredientRepository($this->db);
        // Comptage physique 350 sur une capacite 300 -> cale a 300 (capacite = reference 100 %).
        $id = $this->createIngredient($repo, ['stock_quantity' => 100, 'stock_capacity' => 300]);

        $repo->inventoryCount($id, 350, $this->userId, 'Comptage exceptionnel');

        $found = $repo->find($id);
        self::assertSame(300, (int) ($found['stock_quantity'] ?? -1));
        self::assertSame(100, (int) ($found['stock_pct'] ?? -1));
        $movements = $repo->movements($id);
        self::assertSame('inventory_correction', (string) $movements[0]['movement_type']);
        self::assertSame(200, (int) $movements[0]['delta']);          // 300 - 100
    }

    public function testCreateClampsInitialQuantityToCapacity(): void
    {
        $repo = new IngredientRepository($this->db);
        // Valeur initiale > capacite -> calee a la capacite des la creation (plafond strict).
        $id = $this->createIngredient($repo, ['stock_quantity' => 500, 'stock_capacity' => 300]);
        self::assertSame(300, (int) ($repo->find($id)['stock_quantity'] ?? -1));
    }

    public function testReferencedIngredientCannotBeHardDeletedButCanBeDeactivated(): void
    {
        $repo = new IngredientRepository($this->db);
        $id = $this->createIngredient($repo, ['stock_quantity' => 0, 'stock_capacity' => 100, 'pack_size' => 10]);
        $repo->restock($id, 1, $this->userId, null); // cree un mouvement -> FK RESTRICT

        $blocked = false;
        try {
            $repo->delete($id);
        } catch (PDOException $exception) {
            $blocked = (string) $exception->getCode() === '23000';
        }
        self::assertTrue($blocked, 'La suppression dure doit etre bloquee par stock_movement (FK RESTRICT).');

        // Repli : soft-delete via is_active.
        self::assertSame(1, $repo->setActive($id, false));
        self::assertSame(0, (int) ($repo->find($id)['is_active'] ?? -1));
    }

    public function testUnreferencedIngredientCanBeHardDeleted(): void
    {
        $repo = new IngredientRepository($this->db);
        $id = $this->createIngredient($repo, ['stock_quantity' => 5, 'stock_capacity' => 100]);

        self::assertFalse($repo->isReferenced($id));
        self::assertSame(1, $repo->delete($id));
        self::assertNull($repo->find($id));
    }

    public function testRestockIsCumulative(): void
    {
        $repo = new IngredientRepository($this->db);
        // Stock initial > 0 + DEUX restock : tue une mutation 'stock = :delta' (set)
        // au lieu de 'stock += :delta', et un test qui partirait de 0.
        $id = $this->createIngredient($repo, ['stock_quantity' => 30, 'stock_capacity' => 200, 'pack_size' => 50]);

        $repo->restock($id, 1, $this->userId, null); // 30 -> 80
        $repo->restock($id, 1, $this->userId, null); // 80 -> 130

        self::assertSame(130, (int) ($repo->find($id)['stock_quantity'] ?? -1));
        $movements = $repo->movements($id);
        self::assertCount(2, $movements);
        self::assertSame(50, (int) $movements[0]['delta']);
        self::assertSame(50, (int) $movements[1]['delta']);
    }

    public function testRestockRollsBackWhenMovementInsertFails(): void
    {
        $repo = new IngredientRepository($this->db);
        $id = $this->createIngredient($repo, ['stock_quantity' => 40, 'stock_capacity' => 100, 'pack_size' => 10]);

        // user_id inexistant : l'UPDATE stock passe, l'INSERT stock_movement viole
        // la FK user_id -> la transaction (RG-T08) doit TOUT annuler.
        $rolledBack = false;
        try {
            $repo->restock($id, 2, 2147483647, null);
        } catch (PDOException $exception) {
            $rolledBack = (string) $exception->getCode() === '23000';
        }
        self::assertTrue($rolledBack, 'La violation FK user_id doit lever une 23000.');
        self::assertSame(40, (int) ($repo->find($id)['stock_quantity'] ?? -1)); // stock intact (rollback)
        self::assertCount(0, $repo->movements($id));                            // aucun mouvement laisse
    }

    public function testDuplicateNameViolatesUniqueConstraint(): void
    {
        $repo = new IngredientRepository($this->db);
        $this->createIngredient($repo);

        // Meme name : la contrainte DB uk_ingredient_name (independante de l'appel
        // applicatif nameExists) doit rejeter le doublon.
        $violated = false;
        try {
            $this->createIngredient($repo);
        } catch (PDOException $exception) {
            $violated = (string) $exception->getCode() === '23000';
        }
        self::assertTrue($violated, 'uk_ingredient_name doit rejeter un doublon (SQLSTATE 23000).');
    }

    public function testMovementsAreBoundedByLimit(): void
    {
        $repo = new IngredientRepository($this->db);
        $id = $this->createIngredient($repo, ['stock_capacity' => 100, 'pack_size' => 1]);
        $repo->restock($id, 1, $this->userId, null);
        $repo->restock($id, 1, $this->userId, null);
        $repo->restock($id, 1, $this->userId, null);

        self::assertCount(3, $repo->movements($id));    // defaut large
        self::assertCount(2, $repo->movements($id, 2)); // borne LIMIT (RG-3) sur la vraie base
    }

    public function testMovementsOrderByCreatedAtBeforeId(): void
    {
        $repo = new IngredientRepository($this->db);
        $id = $this->createIngredient($repo, ['stock_capacity' => 100, 'pack_size' => 1]);
        $repo->restock($id, 1, $this->userId, 'recent-1');
        $repo->restock($id, 1, $this->userId, 'recent-2');
        // Mouvement au created_at le plus ANCIEN mais a l'id le plus ELEVE (insere en dernier) :
        // prouve que created_at DESC prime sur le tie-breaker id DESC.
        $this->db->execute(
            "INSERT INTO stock_movement (ingredient_id, movement_type, delta, created_at) "
            . "VALUES (:id, 'inventory_correction', 0, '2000-01-01 00:00:00')",
            ['id' => $id],
        );

        $movements = $repo->movements($id);
        self::assertCount(3, $movements);
        self::assertSame('2000-01-01 00:00:00', (string) $movements[2]['created_at']); // ancien -> dernier
    }

    /**
     * @param array<string, int|string|null> $overrides
     */
    private function createIngredient(IngredientRepository $repo, array $overrides = []): int
    {
        $repo->create([
            'name'               => $this->name,
            'unit'               => 'portion',
            'stock_quantity'     => (int) ($overrides['stock_quantity'] ?? 0),
            'stock_capacity'     => (int) ($overrides['stock_capacity'] ?? 100),
            'pack_size'          => (int) ($overrides['pack_size'] ?? 1),
            'pack_label'         => $overrides['pack_label'] ?? null,
            'low_stock_pct'      => (int) ($overrides['low_stock_pct'] ?? 10),
            'critical_stock_pct' => (int) ($overrides['critical_stock_pct'] ?? 5),
            'is_active'          => 1,
        ]);

        return (int) ($this->db->fetch('SELECT id FROM ingredient WHERE name = :n', ['n' => $this->name])['id'] ?? 0);
    }
}
