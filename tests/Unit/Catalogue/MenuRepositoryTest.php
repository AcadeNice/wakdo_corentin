<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalogue;

use PDOException;
use PHPUnit\Framework\TestCase;
use App\Catalogue\MenuRepository;
use App\Catalogue\MenuSlotInUseException;
use App\Tests\Support\FakeDatabase;

/**
 * Tests unitaires (FakeDatabase, pas de vraie base) de MenuRepository::update() /
 * reconcileSlots() -- rapides, cibles sur l'algorithme d'appariement et le
 * verrouillage, en complement de MenuRepositoryDbTest (preuve FK reelle).
 *
 * Contre-audit (constat 3, reserve) :
 *  - l'appariement par POSITION au sein du meme slot_type laissait un id existant
 *    heriter en silence du nom ET des options d'un AUTRE emplacement du meme type
 *    des que l'admin les intervertissait sans toucher leur nom (deux emplacements du
 *    meme type sont autorises, MenuController::SLOT_TYPES) ;
 *  - la garde "deja commande" ne verrouillait pas les lignes menu_slot lues, laissant
 *    une fenetre de concurrence entre deux reconfigurations paralleles du meme menu ;
 *  - une violation FK qui survient malgre tout (course gagnee par une commande
 *    concurrente) doit rester un refus propre (MenuSlotInUseException, 409), jamais
 *    un PDOException brut (500).
 */
final class MenuRepositoryTest extends TestCase
{
    /**
     * @return array{category_id:int, burger_product_id:int, name:string, price_normal_cents:int, price_maxi_cents:int, is_available:int, display_order:int}
     */
    private function menuData(): array
    {
        return [
            'category_id' => 1,
            'burger_product_id' => 2,
            'name' => 'Best Of',
            'price_normal_cents' => 790,
            'price_maxi_cents' => 990,
            'is_available' => 1,
            'display_order' => 0,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findSlotUpdateById(FakeDatabase $db, int $id): ?array
    {
        foreach ($db->writes as $write) {
            if (str_starts_with($write['sql'], 'UPDATE menu_slot SET') && ($write['params']['id'] ?? null) === $id) {
                return $write;
            }
        }

        return null;
    }

    public function testReconcileSlotsMatchesByNameBeforeFallingBackToPosition(): void
    {
        // Deux emplacements du MEME slot_type ('side'), intervertis en POSITION dans
        // la soumission mais avec le MEME nom que l'existant : l'id 10 ("Accompagnement
        // 1") doit garder ce nom et ses NOUVELLES options ([3]), l'id 11
        // ("Accompagnement 2") de meme avec [4] -- jamais l'inverse, meme si la
        // position soumise est echangee.
        $db = new FakeDatabase();
        $db->menuRow = ['id' => 5] + $this->menuData();
        $db->menuSlotRows = [
            ['id' => 10, 'name' => 'Accompagnement 1', 'slot_type' => 'side', 'is_required' => 1, 'display_order' => 0, 'product_id' => 1],
            ['id' => 11, 'name' => 'Accompagnement 2', 'slot_type' => 'side', 'is_required' => 1, 'display_order' => 1, 'product_id' => 2],
        ];

        (new MenuRepository($db))->update(5, $this->menuData(), [
            ['name' => 'Accompagnement 2', 'slot_type' => 'side', 'is_required' => 1, 'display_order' => 0, 'options' => [3]],
            ['name' => 'Accompagnement 1', 'slot_type' => 'side', 'is_required' => 1, 'display_order' => 1, 'options' => [4]],
        ]);

        $updateA = $this->findSlotUpdateById($db, 10);
        $updateB = $this->findSlotUpdateById($db, 11);
        self::assertNotNull($updateA, 'id 10 doit etre mis a jour EN PLACE (apparie par nom)');
        self::assertNotNull($updateB, 'id 11 doit etre mis a jour EN PLACE (apparie par nom)');
        self::assertSame('Accompagnement 1', $updateA['params']['name'] ?? null);
        self::assertSame('Accompagnement 2', $updateB['params']['name'] ?? null);

        // id 10 ("Accompagnement 1") est apparie a l'entree soumise "Accompagnement 1"
        // (options [4]), PAS a "Accompagnement 2" (options [3]) malgre sa position en
        // tete de la soumission -- c'est precisement l'inversion que corrige ce constat.
        $optionsA = array_values(array_filter(
            $db->writes,
            static fn (array $w): bool => $w['sql'] === 'INSERT INTO menu_slot_option (menu_slot_id, product_id) VALUES (:slot, :product)'
                && ($w['params']['slot'] ?? null) === 10,
        ));
        self::assertNotEmpty($optionsA);
        self::assertSame(4, $optionsA[0]['params']['product'] ?? null);
    }

    public function testReconcileSlotsFallsBackToPositionWhenNamesDontMatch(): void
    {
        // Comportement PRESERVE (mlt 8.5 RG-2) : quand le nom soumis differe (edition
        // de contenu legitime), l'appariement retombe sur la POSITION au sein du meme
        // slot_type, exactement comme avant ce correctif.
        $db = new FakeDatabase();
        $db->menuRow = ['id' => 5] + $this->menuData();
        $db->menuSlotRows = [
            ['id' => 10, 'name' => 'Boisson', 'slot_type' => 'drink', 'is_required' => 1, 'display_order' => 0, 'product_id' => 1],
        ];

        (new MenuRepository($db))->update(5, $this->menuData(), [
            ['name' => 'Boisson (maj)', 'slot_type' => 'drink', 'is_required' => 0, 'display_order' => 0, 'options' => [2]],
        ]);

        $update = $this->findSlotUpdateById($db, 10);
        self::assertNotNull($update, 'sans homologue par nom, le seul slot drink existant reste apparie par position');
        self::assertSame('Boisson (maj)', $update['params']['name'] ?? null);
    }

    public function testReconcileSlotsLocksExistingSlotRowsBeforeTheInUseGuard(): void
    {
        // La lecture des slots existants doit verrouiller les lignes (SELECT ... FOR
        // UPDATE), pour fermer la fenetre de concurrence entre deux administrateurs
        // qui reconfigurent le MEME menu en parallele.
        $db = new FakeDatabase();
        $db->menuRow = ['id' => 5] + $this->menuData();
        $db->menuSlotRows = [
            ['id' => 10, 'name' => 'Boisson', 'slot_type' => 'drink', 'is_required' => 1, 'display_order' => 0, 'product_id' => 1],
        ];

        (new MenuRepository($db))->update(5, $this->menuData(), [
            ['name' => 'Boisson', 'slot_type' => 'drink', 'is_required' => 1, 'display_order' => 0, 'options' => [1]],
        ]);

        $lockingReads = array_values(array_filter(
            $db->reads,
            static fn (array $r): bool => str_contains($r['sql'], 'FROM menu_slot WHERE menu_id') && str_contains($r['sql'], 'FOR UPDATE'),
        ));
        self::assertNotEmpty($lockingReads, 'la lecture des slots existants doit porter FOR UPDATE');
    }

    public function testReconcileSlotsTranslatesLateConstraintViolationOnSurplusDeleteIntoMenuSlotInUseException(): void
    {
        // Le verrou (FOR UPDATE) reduit la fenetre de course mais ne l'annule pas :
        // une commande concurrente peut inserer order_item_selection SANS jamais
        // verrouiller menu_slot. Le pre-check (isSlotReferencedByOrders) ne voit
        // encore rien ici (referencedSlotIds vide) -- la course est gagnee juste
        // apres, au DELETE lui-meme (23000). Le refus doit rester propre (409),
        // jamais un PDOException brut (500).
        $db = new FakeDatabase();
        $db->menuRow = ['id' => 5] + $this->menuData();
        $db->menuSlotRows = [
            ['id' => 10, 'name' => 'Boisson', 'slot_type' => 'drink', 'is_required' => 1, 'display_order' => 0, 'product_id' => 1],
        ];
        $db->failOnExecute = new PDOException('Integrity constraint violation', 23000);
        $db->failOnExecuteMatching = 'DELETE FROM menu_slot WHERE id';

        $this->expectException(MenuSlotInUseException::class);

        // Aucun slot soumis -> 'drink' (id 10) est en surplus -> DELETE tente, echoue
        // en 23000.
        (new MenuRepository($db))->update(5, $this->menuData(), []);
    }

    public function testReconcileSlotsLetsUnrelatedPdoExceptionsPropagate(): void
    {
        // Une violation SQL SANS RAPPORT avec la FK order_item_selection (autre
        // SQLSTATE) ne doit PAS etre avalee en MenuSlotInUseException : seule 23000
        // est traduite, tout le reste remonte tel quel.
        $db = new FakeDatabase();
        $db->menuRow = ['id' => 5] + $this->menuData();
        $db->menuSlotRows = [
            ['id' => 10, 'name' => 'Boisson', 'slot_type' => 'drink', 'is_required' => 1, 'display_order' => 0, 'product_id' => 1],
        ];
        $db->failOnExecute = new PDOException('Connexion perdue', 2002);
        $db->failOnExecuteMatching = 'DELETE FROM menu_slot WHERE id';

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('Connexion perdue');

        (new MenuRepository($db))->update(5, $this->menuData(), []);
    }
}
