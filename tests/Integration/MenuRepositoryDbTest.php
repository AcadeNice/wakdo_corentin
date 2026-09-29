<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Catalogue\MenuRepository;
use App\Catalogue\MenuSlotInUseException;
use App\Core\Config;
use App\Core\Database;

/**
 * CRUD reel de MenuRepository contre une vraie MariaDB (schema migre + seede),
 * y compris la composition de slots et la RECONCILIATION EN PLACE des slots a
 * l'update (voir testUpdateReconciles...() : un menu deja commande NE PEUT PLUS
 * etre modifie via un delete-and-reinsert aveugle, order_item_selection.menu_slot_id
 * etant en ON DELETE RESTRICT -- seule une vraie base prouve que la FK reagit
 * vraiment, un double de test ne le peut pas).
 * Auto-skip si WAKDO_DB_TESTS != 1. Menu jetable (nom it-menu-*), nettoyage en
 * tearDown (les commandes jetables d'abord -- CASCADE emporte order_item +
 * order_item_selection --, puis le menu -- CASCADE emporte menu_slot + menu_slot_option).
 */
final class MenuRepositoryDbTest extends TestCase
{
    private Database $db;
    private string $name = '';
    private int $categoryId = 0;
    /** @var list<int> */
    private array $productIds = [];
    /** @var list<int> customer_order.id jetables crees par un test, purges AVANT le menu (FK order_item.menu_id RESTRICT). */
    private array $orderIds = [];

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
        $this->productIds = array_map(
            static fn (array $r): int => (int) ($r['id'] ?? 0),
            $this->db->fetchAll('SELECT id FROM product ORDER BY id LIMIT 3'),
        );
        $this->name = 'it-menu-' . bin2hex(random_bytes(4));
        $this->orderIds = [];
    }

    protected function tearDown(): void
    {
        // Ordre FK-safe : les commandes jetables d'abord (CASCADE customer_order ->
        // order_item -> order_item_selection), puis le menu (CASCADE menu -> menu_slot
        // -> menu_slot_option). Dans l'autre sens, order_item.menu_id (RESTRICT)
        // bloquerait la suppression du menu tant qu'une commande le reference encore.
        foreach ($this->orderIds as $orderId) {
            $this->db->execute('DELETE FROM customer_order WHERE id = :id', ['id' => $orderId]);
        }
        if ($this->name !== '') {
            $this->db->execute('DELETE FROM menu WHERE name = :name', ['name' => $this->name]);
        }
    }

    /**
     * Cree une commande jetable minimale portant UNE selection de slot (F18-like,
     * au plus simple : juste ce que les contraintes du schema exigent). Trace
     * l'id de commande pour le nettoyage FK-safe du tearDown.
     */
    private function placeOrderOnSlot(int $menuId, int $slotId, int $productId): int
    {
        $orderNumber = 'IT-MENUUPD-' . bin2hex(random_bytes(4));
        $this->db->execute(
            'INSERT INTO customer_order (order_number, source, service_mode, status, total_ht_cents, total_vat_cents, total_ttc_cents) '
            . 'VALUES (:num, :src, :mode, :status, :ht, :vat, :ttc)',
            ['num' => $orderNumber, 'src' => 'counter', 'mode' => 'dine_in', 'status' => 'paid', 'ht' => 700, 'vat' => 90, 'ttc' => 790],
        );
        $orderId = (int) ($this->db->fetch('SELECT LAST_INSERT_ID() AS id')['id'] ?? 0);
        $this->orderIds[] = $orderId;

        $this->db->execute(
            'INSERT INTO order_item (order_id, item_type, menu_id, format, label_snapshot, unit_price_cents_snapshot, vat_rate_snapshot, quantity) '
            . 'VALUES (:oid, :type, :menu, :fmt, :label, :price, :vat, :qty)',
            ['oid' => $orderId, 'type' => 'menu', 'menu' => $menuId, 'fmt' => 'normal', 'label' => $this->name, 'price' => 790, 'vat' => 100, 'qty' => 1],
        );
        $orderItemId = (int) ($this->db->fetch('SELECT LAST_INSERT_ID() AS id')['id'] ?? 0);

        $this->db->execute(
            'INSERT INTO order_item_selection (order_item_id, menu_slot_id, product_id, label_snapshot) '
            . 'VALUES (:oi, :slot, :prod, :label)',
            ['oi' => $orderItemId, 'slot' => $slotId, 'prod' => $productId, 'label' => 'Selection test'],
        );

        return $orderId;
    }

    public function testCreateFindUpdateSlotsAndDelete(): void
    {
        self::assertGreaterThan(0, $this->categoryId);
        self::assertCount(3, $this->productIds);
        [$burger, $optA, $optB] = $this->productIds;

        $repo = new MenuRepository($this->db);
        self::assertTrue($repo->categoryExists($this->categoryId));
        self::assertTrue($repo->productExists($burger));
        self::assertFalse($repo->productExists(0));

        // --- create : menu + 2 slots (drink avec 2 options, side avec 1) ---
        $id = $repo->create(
            [
                'category_id' => $this->categoryId,
                'burger_product_id' => $burger,
                'name' => $this->name,
                'price_normal_cents' => 790,
                'price_maxi_cents' => 990,
                'is_available' => 1,
                'display_order' => 50,
            ],
            [
                ['name' => 'Boisson', 'slot_type' => 'drink', 'is_required' => 1, 'display_order' => 0, 'options' => [$optA, $optB]],
                ['name' => 'Accompagnement', 'slot_type' => 'side', 'is_required' => 1, 'display_order' => 1, 'options' => [$optA]],
            ],
        );
        self::assertGreaterThan(0, $id);

        $found = $repo->find($id);
        self::assertNotNull($found);
        self::assertSame(790, (int) ($found['price_normal_cents'] ?? 0));
        self::assertSame(990, (int) ($found['price_maxi_cents'] ?? 0));

        $slots = $repo->slotsWithOptions($id);
        self::assertCount(2, $slots);
        self::assertSame('drink', $slots[0]['slot_type']);
        self::assertEqualsCanonicalizing([$optA, $optB], $slots[0]['option_product_ids']);
        self::assertSame('side', $slots[1]['slot_type']);
        self::assertSame([$optA], $slots[1]['option_product_ids']);

        // all() porte categorie + burger joints.
        $names = array_map(static fn (array $r): string => (string) ($r['name'] ?? ''), $repo->all());
        self::assertContains($this->name, $names);

        self::assertFalse($repo->isReferencedByOrders($id));

        // --- update : change le prix maxi ET reconfigure en 1 SEUL slot d'un
        // AUTRE slot_type ('sauce' n'existait pas) : aucun homologue possible,
        // les 2 anciens slots (drink/side, jamais commandes ici) sont donc retires.
        $repo->update(
            $id,
            [
                'category_id' => $this->categoryId,
                'burger_product_id' => $burger,
                'name' => $this->name,
                'price_normal_cents' => 790,
                'price_maxi_cents' => 1090,
                'is_available' => 0,
                'display_order' => 51,
            ],
            [
                ['name' => 'Sauce', 'slot_type' => 'sauce', 'is_required' => 0, 'display_order' => 0, 'options' => [$optB]],
            ],
        );

        $updated = $repo->find($id);
        self::assertNotNull($updated);
        self::assertSame(1090, (int) ($updated['price_maxi_cents'] ?? 0));
        self::assertSame(0, (int) ($updated['is_available'] ?? 1));

        $slotsAfter = $repo->slotsWithOptions($id);
        self::assertCount(1, $slotsAfter);                       // plus qu'1 slot : drink/side retires, sauce ajoute
        self::assertSame('sauce', $slotsAfter[0]['slot_type']);
        self::assertSame([$optB], $slotsAfter[0]['option_product_ids']);

        // --- delete : menu non reference -> suppression dure OK, slots cascade ---
        self::assertSame(1, $repo->delete($id));
        self::assertNull($repo->find($id));
        self::assertSame([], $repo->slotsWithOptions($id));
    }

    /**
     * Coeur du bug corrige (F33) : order_item_selection.menu_slot_id est en
     * ON DELETE RESTRICT (db/migrations/0001 ~l.391). L'ancien update() supprimait
     * PUIS reinserait TOUS les menu_slot du menu (delete-and-reinsert) : des qu'un
     * menu avait ete commande au moins une fois avec une selection de slot, ce
     * DELETE levait SQLSTATE 23000 -- non intercepte, donc une 500 sur un simple
     * edit de menu. Ce test reproduit exactement ce scenario ; sur le code
     * d'avant le correctif, il echoue avec un PDOException (23000,
     * fk_order_item_selection_menu_slot_id).
     *
     * Couvre les 4 cas exiges (mlt 8.5 RG-2 corrige), en un seul update() la ou
     * c'est naturel :
     *  - phase 2 : slot deja commande, config modifiee (nom + options + ordre) ->
     *    reconcilie EN PLACE (meme id de slot), la selection de commande reste
     *    intacte ; + AJOUT d'un nouveau slot -> OK ; + RETRAIT d'un slot jamais
     *    commande -> OK.
     *  - phase 3 : RETRAIT d'un slot DEJA reference par la commande -> refus
     *    propre (MenuSlotInUseException, pas de 500), et AUCUNE ecriture
     *    n'est restee (la transaction a fait un rollback complet : la config de
     *    slots est identique a l'etat de la phase 2, ce qu'un double de test ne
     *    peut pas prouver -- seule une vraie base le peut).
     */
    public function testUpdateReconcilesOrderedSlotsInPlaceAndRejectsUnsafeRemoval(): void
    {
        self::assertCount(3, $this->productIds);
        [$burger, $optA, $optB] = $this->productIds;

        $repo = new MenuRepository($this->db);

        // --- phase 1 : menu + 2 slots (drink, side), puis une commande qui
        // choisit une option du slot drink (F18-like) ---
        $id = $repo->create(
            [
                'category_id' => $this->categoryId,
                'burger_product_id' => $burger,
                'name' => $this->name,
                'price_normal_cents' => 790,
                'price_maxi_cents' => 990,
                'is_available' => 1,
                'display_order' => 50,
            ],
            [
                ['name' => 'Boisson', 'slot_type' => 'drink', 'is_required' => 1, 'display_order' => 0, 'options' => [$optA, $optB]],
                ['name' => 'Accompagnement', 'slot_type' => 'side', 'is_required' => 1, 'display_order' => 1, 'options' => [$optA]],
            ],
        );
        self::assertGreaterThan(0, $id);

        $initialSlots = $repo->slotsWithOptions($id);
        self::assertCount(2, $initialSlots);
        $drinkSlotId = $initialSlots[0]['id'];
        $sideSlotId = $initialSlots[1]['id'];
        self::assertSame('drink', $initialSlots[0]['slot_type']);
        self::assertSame('side', $initialSlots[1]['slot_type']);

        $this->placeOrderOnSlot($id, $drinkSlotId, $optA);

        // --- phase 2 : slot 'drink' MODIFIE en place (nom + options + ordre),
        // slot 'side' RETIRE (jamais commande -> OK), slot 'dessert' AJOUTE ---
        $repo->update(
            $id,
            [
                'category_id' => $this->categoryId,
                'burger_product_id' => $burger,
                'name' => $this->name,
                'price_normal_cents' => 790,
                'price_maxi_cents' => 1090,
                'is_available' => 1,
                'display_order' => 52,
            ],
            [
                ['name' => 'Boisson (maj)', 'slot_type' => 'drink', 'is_required' => 0, 'display_order' => 0, 'options' => [$optB]],
                ['name' => 'Dessert', 'slot_type' => 'dessert', 'is_required' => 0, 'display_order' => 1, 'options' => [$optA]],
            ],
        );

        $updated = $repo->find($id);
        self::assertNotNull($updated);
        self::assertSame(1090, (int) ($updated['price_maxi_cents'] ?? 0));

        $slotsAfterPhase2 = $repo->slotsWithOptions($id);
        self::assertCount(2, $slotsAfterPhase2, 'side (jamais commande) retire, dessert ajoute : 2 slots au total');

        $bySlotType = [];
        foreach ($slotsAfterPhase2 as $slot) {
            $bySlotType[$slot['slot_type']] = $slot;
        }
        self::assertArrayHasKey('drink', $bySlotType);
        self::assertArrayNotHasKey('side', $bySlotType, 'le slot side, jamais commande, a bien ete retire');
        self::assertArrayHasKey('dessert', $bySlotType);

        // Reconciliation EN PLACE, pas delete-and-reinsert : le slot drink garde
        // le MEME id (sinon la selection de commande, ci-dessous, serait orpheline
        // ou la FK aurait deja refuse la suppression avant meme d'arriver ici).
        self::assertSame($drinkSlotId, $bySlotType['drink']['id']);
        self::assertSame('Boisson (maj)', $bySlotType['drink']['name']);
        self::assertSame([$optB], $bySlotType['drink']['option_product_ids']);

        // La selection de commande passee est INTACTE : toujours 1 ligne, toujours
        // sur le meme menu_slot_id (aucune ecriture ne l'a touchee ni cascadee).
        $selection = $this->db->fetch(
            'SELECT menu_slot_id FROM order_item_selection WHERE order_item_id IN '
            . '(SELECT id FROM order_item WHERE order_id = :oid)',
            ['oid' => $this->orderIds[0]],
        );
        self::assertNotNull($selection);
        self::assertSame($drinkSlotId, (int) $selection['menu_slot_id']);

        $dessertSlotId = $bySlotType['dessert']['id'];

        // --- phase 3 : retirer le slot 'drink', DEJA reference par la commande ---
        // refus propre, PAS un PDOException brut, ET aucune ecriture (transaction
        // roll-back complet : la config de slots reste EXACTEMENT celle de la
        // phase 2, y compris les ids -- seule une vraie base le prouve).
        try {
            $repo->update(
                $id,
                [
                    'category_id' => $this->categoryId,
                    'burger_product_id' => $burger,
                    'name' => $this->name,
                    'price_normal_cents' => 790,
                    'price_maxi_cents' => 1090,
                    'is_available' => 1,
                    'display_order' => 52,
                ],
                [
                    ['name' => 'Dessert', 'slot_type' => 'dessert', 'is_required' => 0, 'display_order' => 0, 'options' => [$optA]],
                ],
            );
            self::fail('le retrait d\'un slot deja commande doit lever MenuSlotInUseException');
        } catch (MenuSlotInUseException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }

        $slotsAfterRejectedUpdate = $repo->slotsWithOptions($id);
        self::assertCount(2, $slotsAfterRejectedUpdate, 'rollback complet : toujours drink + dessert');
        $idsAfterRejectedUpdate = array_map(static fn (array $s): int => $s['id'], $slotsAfterRejectedUpdate);
        self::assertEqualsCanonicalizing([$drinkSlotId, $dessertSlotId], $idsAfterRejectedUpdate);

        // La commande garde son historique intact, y compris apres la tentative refusee.
        $selectionAfter = $this->db->fetch(
            'SELECT menu_slot_id FROM order_item_selection WHERE order_item_id IN '
            . '(SELECT id FROM order_item WHERE order_id = :oid)',
            ['oid' => $this->orderIds[0]],
        );
        self::assertNotNull($selectionAfter);
        self::assertSame($drinkSlotId, (int) $selectionAfter['menu_slot_id']);
    }
}
