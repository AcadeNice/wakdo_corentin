<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Core\DatabaseInterface;

/**
 * Acces aux donnees du menu compose (sous-domaine Catalogue) : la ligne `menu`,
 * ses `menu_slot` (slots de composition) et les `menu_slot_option` (produits
 * eligibles par slot). Suit le pattern de CategoryRepository / ProductRepository.
 *
 * Topologie FK (db/migrations/0001) et effet sur la suppression :
 *  - menu.category_id / menu.burger_product_id : RESTRICT (referencent catalogue).
 *  - menu_slot.menu_id : CASCADE (slots possedes par le menu).
 *  - menu_slot_option.menu_slot_id : CASCADE ; .product_id : RESTRICT.
 *  - order_item.menu_id : RESTRICT -> la suppression dure est bloquee si le menu
 *    est reference par une commande historique (mlt 8.6 RG-1 : le controleur
 *    traduit la violation en 409 et propose la desactivation).
 *
 * create() et update() ecrivent menu + slots + options dans UNE transaction
 * (RG-T08). update() reconstruit les slots en delete-and-reinsert (mlt 8.5 RG-2).
 */
final class MenuRepository
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Liste pour le back-office, avec le libelle de categorie et le nom du burger.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->fetchAll(
            'SELECT m.id, m.category_id, m.burger_product_id, m.name, m.price_normal_cents, '
            . 'm.price_maxi_cents, m.is_available, m.display_order, '
            . 'c.name AS category_name, p.name AS burger_name '
            . 'FROM menu m '
            . 'JOIN category c ON c.id = m.category_id '
            . 'JOIN product p ON p.id = m.burger_product_id '
            . 'ORDER BY m.display_order, m.name',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT id, category_id, burger_product_id, name, price_normal_cents, '
            . 'price_maxi_cents, is_available, display_order FROM menu WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * Lecture publique pour la borne (P4, docs/api/conventions.md 5.2) : menus
     * disponibles (is_available = 1) ET en categorie active (c.is_active = 1).
     * Projection enrichie (description, image_path) absente de all() back-office.
     * Liste LEGERE : sans les slots (le detail /api/menus/{id} les porte). La
     * disponibilite du burger impose (B1, RG-T21) est calculee par CatalogueController
     * (croisement avec ProductRepository::autoUnavailableIds) et exposee en is_orderable :
     * un menu dont le burger est en rupture est grise par la borne (granularite burger seul).
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableForCatalogue(): array
    {
        return $this->db->fetchAll(
            'SELECT m.id, m.category_id, m.burger_product_id, m.name, m.description, '
            . 'm.price_normal_cents, m.price_maxi_cents, m.image_path, m.display_order '
            . 'FROM menu m JOIN category c ON c.id = m.category_id '
            . 'WHERE m.is_available = 1 AND c.is_active = 1 '
            . 'ORDER BY m.display_order, m.name',
        );
    }

    /**
     * Detail menu pour la borne : meme projection que la liste, seulement si le
     * menu est disponible en categorie active ; sinon null (le controleur rend
     * 404). Les slots sont charges a part (slotsWithOptions) puis assembles par le
     * controleur.
     *
     * @return array<string, mixed>|null
     */
    public function findForCatalogue(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT m.id, m.category_id, m.burger_product_id, m.name, m.description, '
            . 'm.price_normal_cents, m.price_maxi_cents, m.image_path, m.display_order '
            . 'FROM menu m JOIN category c ON c.id = m.category_id '
            . 'WHERE m.id = :id AND m.is_available = 1 AND c.is_active = 1',
            ['id' => $id],
        );
    }

    /**
     * Slots d'un menu (ordonnes), chacun avec la liste de ses product_id eligibles.
     * Une seule requete (LEFT JOIN) regroupee en PHP par slot.
     *
     * @return list<array{id:int, name:string, slot_type:string, is_required:int, display_order:int, option_product_ids:list<int>}>
     */
    public function slotsWithOptions(int $menuId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT s.id, s.name, s.slot_type, s.is_required, s.display_order, o.product_id '
            . 'FROM menu_slot s '
            . 'LEFT JOIN menu_slot_option o ON o.menu_slot_id = s.id '
            . 'WHERE s.menu_id = :id ORDER BY s.display_order, s.id',
            ['id' => $menuId],
        );

        /** @var array<int, array{id:int, name:string, slot_type:string, is_required:int, display_order:int, option_product_ids:list<int>}> $slots */
        $slots = [];
        foreach ($rows as $r) {
            $sid = (int) ($r['id'] ?? 0);
            if (!isset($slots[$sid])) {
                $slots[$sid] = [
                    'id' => $sid,
                    'name' => (string) ($r['name'] ?? ''),
                    'slot_type' => (string) ($r['slot_type'] ?? ''),
                    'is_required' => (int) ($r['is_required'] ?? 0),
                    'display_order' => (int) ($r['display_order'] ?? 0),
                    'option_product_ids' => [],
                ];
            }
            if (($r['product_id'] ?? null) !== null) {
                $slots[$sid]['option_product_ids'][] = (int) $r['product_id'];
            }
        }

        return array_values($slots);
    }

    public function categoryExists(int $id): bool
    {
        return $this->db->fetch('SELECT id FROM category WHERE id = :id', ['id' => $id]) !== null;
    }

    public function productExists(int $id): bool
    {
        return $this->db->fetch('SELECT id FROM product WHERE id = :id', ['id' => $id]) !== null;
    }

    /**
     * Pre-verification FK-safe (mlt 8.6 RG-1) : le menu est-il reference par une
     * ligne de commande historique ? La FK order_item.menu_id est RESTRICT.
     */
    public function isReferencedByOrders(int $id): bool
    {
        return $this->db->fetch('SELECT menu_id FROM order_item WHERE menu_id = :id LIMIT 1', ['id' => $id]) !== null;
    }

    /**
     * Cree le menu et sa configuration de slots dans UNE transaction (mlt 8.4 RG-2).
     * Retourne l'id du menu cree.
     *
     * @param array{category_id:int, burger_product_id:int, name:string, price_normal_cents:int, price_maxi_cents:int, is_available:int, display_order:int} $data
     * @param list<array{name:string, slot_type:string, is_required:int, display_order:int, options:list<int>}> $slots
     */
    public function create(array $data, array $slots): int
    {
        $newId = 0;
        $this->db->transaction(function (DatabaseInterface $db) use ($data, $slots, &$newId): void {
            $db->execute(
                'INSERT INTO menu (category_id, burger_product_id, name, price_normal_cents, '
                . 'price_maxi_cents, is_available, display_order) '
                . 'VALUES (:category, :burger, :name, :pnormal, :pmaxi, :available, :ord)',
                $this->bindMenu($data),
            );
            $newId = (int) ($db->fetch('SELECT LAST_INSERT_ID() AS id')['id'] ?? 0);
            $this->insertSlots($db, $newId, $slots);
        });

        return $newId;
    }

    /**
     * Met a jour le menu et RECONSTRUIT ses slots (delete-and-reinsert, mlt 8.5
     * RG-2) dans UNE transaction : un edit de la configuration de slots est plus
     * simple et sur a re-poser entierement qu'a reconcilier en place.
     *
     * @param array{category_id:int, burger_product_id:int, name:string, price_normal_cents:int, price_maxi_cents:int, is_available:int, display_order:int} $data
     * @param list<array{name:string, slot_type:string, is_required:int, display_order:int, options:list<int>}> $slots
     */
    public function update(int $id, array $data, array $slots): void
    {
        $this->db->transaction(function (DatabaseInterface $db) use ($id, $data, $slots): void {
            $db->execute(
                'UPDATE menu SET category_id = :category, burger_product_id = :burger, name = :name, '
                . 'price_normal_cents = :pnormal, price_maxi_cents = :pmaxi, is_available = :available, '
                . 'display_order = :ord WHERE id = :id',
                $this->bindMenu($data) + ['id' => $id],
            );
            // Options d'abord (FK vers slot), puis slots, puis re-insertion.
            $db->execute(
                'DELETE FROM menu_slot_option WHERE menu_slot_id IN '
                . '(SELECT id FROM menu_slot WHERE menu_id = :id)',
                ['id' => $id],
            );
            $db->execute('DELETE FROM menu_slot WHERE menu_id = :id', ['id' => $id]);
            $this->insertSlots($db, $id, $slots);
        });
    }

    /**
     * Suppression dure. CASCADE retire menu_slot + menu_slot_option ;
     * order_item.menu_id (RESTRICT) bloque si une commande historique reference le
     * menu (le controleur attrape SQLSTATE 23000 -> 409).
     */
    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM menu WHERE id = :id', ['id' => $id]);
    }

    public function setActive(int $id, bool $active): int
    {
        return $this->db->execute(
            'UPDATE menu SET is_available = :a WHERE id = :id',
            ['a' => $active ? 1 : 0, 'id' => $id],
        );
    }

    /**
     * Insere les slots d'un menu et leurs options (helper partage create/update).
     *
     * @param list<array{name:string, slot_type:string, is_required:int, display_order:int, options:list<int>}> $slots
     */
    private function insertSlots(DatabaseInterface $db, int $menuId, array $slots): void
    {
        foreach ($slots as $slot) {
            $db->execute(
                'INSERT INTO menu_slot (menu_id, name, slot_type, is_required, display_order) '
                . 'VALUES (:menu, :name, :type, :required, :ord)',
                [
                    'menu' => $menuId,
                    'name' => $slot['name'],
                    'type' => $slot['slot_type'],
                    'required' => $slot['is_required'],
                    'ord' => $slot['display_order'],
                ],
            );
            $slotId = (int) ($db->fetch('SELECT LAST_INSERT_ID() AS id')['id'] ?? 0);
            foreach ($slot['options'] as $productId) {
                $db->execute(
                    'INSERT INTO menu_slot_option (menu_slot_id, product_id) VALUES (:slot, :product)',
                    ['slot' => $slotId, 'product' => $productId],
                );
            }
        }
    }

    /**
     * Allowlist d'affectation de masse (RG-T16) : seules ces colonnes sont liees.
     *
     * @param array{category_id:int, burger_product_id:int, name:string, price_normal_cents:int, price_maxi_cents:int, is_available:int, display_order:int} $data
     * @return array<string, mixed>
     */
    private function bindMenu(array $data): array
    {
        return [
            'category'  => $data['category_id'],
            'burger'    => $data['burger_product_id'],
            'name'      => $data['name'],
            'pnormal'   => $data['price_normal_cents'],
            'pmaxi'     => $data['price_maxi_cents'],
            'available' => $data['is_available'],
            'ord'       => $data['display_order'],
        ];
    }
}
