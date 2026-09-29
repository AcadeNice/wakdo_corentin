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
 * (RG-T08). update() RECONCILIE les slots EN PLACE (reconcileSlots(), mlt 8.5
 * RG-2 corrige) plutot que de tout supprimer puis reinserer : order_item_selection
 * .menu_slot_id est en ON DELETE RESTRICT, donc l'ancien delete-and-reinsert
 * levait SQLSTATE 23000 (non intercepte -> 500) des qu'un menu deja commande au
 * moins une fois avec une selection de slot etait modifie. Voir le docblock de
 * update() pour le detail de la regle d'appariement.
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
        // c.name AS category_name : sans elle, CounterOrderController::catNameOf()
        // (admin/counter/new.php) range tous les menus sous l'onglet de repli "Autres"
        // faute de nom de categorie (regression F40, captures 21/22).
        return $this->db->fetchAll(
            'SELECT m.id, m.category_id, c.name AS category_name, m.burger_product_id, m.name, m.description, '
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
     * Le produit existe-t-il ET est-il un produit de BASE (base_product_id IS NULL,
     * R4) ? Garde serveur de l'eligibilite au menu (F9-2) : un menu ne peut prendre
     * comme burger principal NI comme option de slot une VARIANTE de taille (ex.
     * "Coca Cola 50cl"), qui n'est pas un produit autonome. Predicat plus strict que
     * productExists() : il rejette une variante meme si l'UI est contournee. Le
     * formulaire menu n'expose deja que des bases (ProductRepository::basesOnly),
     * cette garde verrouille le chemin serveur en plus.
     */
    public function productIsBase(int $id): bool
    {
        return $this->db->fetch(
            'SELECT id FROM product WHERE id = :id AND base_product_id IS NULL',
            ['id' => $id],
        ) !== null;
    }

    /**
     * Slug de categorie d'un produit, ou null si l'id est inconnu. Garde serveur F12 :
     * une option de slot doit appartenir a une categorie autorisee pour le slot_type
     * du slot (mapping unique cote MenuController). Le controleur croise ce slug avec
     * la liste autorisee et rejette (422) une option hors categorie meme si l'UI de
     * filtrage est contournee -- defense en profondeur (RG-T18), par-dessus la garde
     * base-only existante (productIsBase, F9).
     */
    public function productCategorySlug(int $id): ?string
    {
        $row = $this->db->fetch(
            'SELECT c.slug AS category_slug FROM product p '
            . 'JOIN category c ON c.id = p.category_id WHERE p.id = :id',
            ['id' => $id],
        );

        $slug = $row['category_slug'] ?? null;

        return is_string($slug) ? $slug : null;
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
     * Met a jour le menu et RECONCILIE ses slots EN PLACE dans UNE transaction
     * (mlt 8.5 RG-2 corrige). Historiquement update() supprimait puis reinserait
     * tous les menu_slot (delete-and-reinsert) : simple, mais faux des qu'un menu
     * avait deja ete commande, car order_item_selection.menu_slot_id est en
     * ON DELETE RESTRICT (db/migrations/0001 ~l.391) -- la suppression du slot
     * choisi par un client levait SQLSTATE 23000, non intercepte, donc une 500
     * sur un simple edit de menu (bug reel, reproduit par MenuRepositoryDbTest).
     *
     * Regle d'appariement (aucun identifiant de slot n'est transmis : ni le
     * formulaire HTML -- champ cache slots_json --, ni le corps JSON de l'API
     * -- MenuApiController::toForm() -- n'envoient d'id, seulement
     * name/slot_type/is_required/options) : appariement par POSITION AU SEIN DU
     * MEME slot_type (reconcileSlots()). Le n-ieme slot existant de type "drink"
     * est apparie au n-ieme slot SOUMIS de type "drink", quelle que soit sa position
     * parmi les autres types. Choisie plutot qu'un appariement par position
     * ABSOLUE (index dans la liste entiere des slots) : retirer un slot d'un
     * AUTRE type ne decale alors plus artificiellement l'identite des slots
     * suivants, et changer le TYPE d'un slot a une position donnee est traite
     * comme un retrait + un ajout (donc bloque par la garde FK si l'ancien slot a
     * deja ete commande) -- comportement attendu : on ne "transforme" jamais
     * silencieusement l'historique d'un client.
     *
     * - slot soumis apparie a un slot existant : UPDATE de ses champs et
     *   remplacement total de ses options (menu_slot_option n'est reference par
     *   AUCUNE commande -- seule sa ligne parent menu_slot l'est via
     *   order_item_selection -- son remplacement est donc toujours sans risque FK).
     * - slot soumis sans homologue existant du meme type : INSERT (nouveau slot).
     * - slot existant sans homologue soumis du meme type (retire du formulaire) :
     *   jamais commande -> DELETE (CASCADE retire ses options) ; deja reference
     *   par order_item_selection -> MenuSlotInUseException, AVANT toute ecriture
     *   (reconcileSlots() garde la totalite des slots surnumeraires avant
     *   d'ecrire quoi que ce soit) : update() n'a alors rien ecrit de la
     *   configuration de slots, et le rollback de la transaction annule aussi la
     *   mise a jour du menu lui-meme -- aucune ecriture partielle.
     *
     * @param array{category_id:int, burger_product_id:int, name:string, price_normal_cents:int, price_maxi_cents:int, is_available:int, display_order:int} $data
     * @param list<array{name:string, slot_type:string, is_required:int, display_order:int, options:list<int>}> $slots
     * @throws MenuSlotInUseException si un slot retire est deja reference par une commande
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
            $this->reconcileSlots($db, $id, $slots);
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
     * Insere les slots d'un menu et leurs options (chemin create(), aucun slot
     * existant a apparier). Partage insertSlotRow()/insertSlotOptions() avec la
     * branche "nouveau slot" de reconcileSlots() (update()).
     *
     * @param list<array{name:string, slot_type:string, is_required:int, display_order:int, options:list<int>}> $slots
     */
    private function insertSlots(DatabaseInterface $db, int $menuId, array $slots): void
    {
        foreach ($slots as $slot) {
            $slotId = $this->insertSlotRow($db, $menuId, $slot);
            $this->insertSlotOptions($db, $slotId, $slot['options']);
        }
    }

    /**
     * Reconcilie EN PLACE les menu_slot d'un menu avec la configuration soumise.
     * Voir le docblock de update() pour le POURQUOI (bug FK RESTRICT corrige) et
     * la regle d'appariement (position au sein du meme slot_type).
     *
     * Deux passes deliberement separees :
     *  1. Appariement PUR, aucune ecriture : construit le plan (quel slot soumis
     *     va sur quel id existant, ou aucun -> nouveau) et la liste des ids
     *     surnumeraires (retires du formulaire). La garde FK-safe tourne sur
     *     CETTE liste avant la moindre ecriture -- jamais un slot deja modifie
     *     en base puis une exception sur un AUTRE slot, ce qui laisserait un
     *     doute sur l'etat ecrit avant le rollback de la transaction appelante.
     *  2. Ecritures, une fois le plan entier valide par la garde.
     *
     * @param list<array{name:string, slot_type:string, is_required:int, display_order:int, options:list<int>}> $slots
     * @throws MenuSlotInUseException si un slot retire est deja reference par order_item_selection
     */
    private function reconcileSlots(DatabaseInterface $db, int $menuId, array $slots): void
    {
        $existing = $db->fetchAll(
            'SELECT id, slot_type FROM menu_slot WHERE menu_id = :id ORDER BY display_order, id',
            ['id' => $menuId],
        );

        /** @var array<string, list<int>> $pool */
        $pool = [];
        foreach ($existing as $row) {
            $pool[(string) $row['slot_type']][] = (int) $row['id'];
        }

        /** @var list<array{slot: array{name:string, slot_type:string, is_required:int, display_order:int, options:list<int>}, existingId: int|null}> $plan */
        $plan = [];
        foreach ($slots as $slot) {
            $type = $slot['slot_type'];
            $existingId = null;
            if (isset($pool[$type]) && $pool[$type] !== []) {
                $existingId = array_shift($pool[$type]);
            }
            $plan[] = ['slot' => $slot, 'existingId' => $existingId];
        }

        // Slots surnumeraires : les ids qui restent dans $pool n'ont trouve aucun
        // homologue soumis de leur slot_type -> le formulaire les retire.
        $surplus = array_merge([], ...array_values($pool));
        foreach ($surplus as $slotId) {
            if ($this->isSlotReferencedByOrders($db, $slotId)) {
                throw new MenuSlotInUseException(
                    'Un slot retiré du menu est déjà référencé par une commande : mise à jour '
                    . 'impossible. Conservez ce slot ou laissez le menu inchangé.',
                );
            }
        }

        foreach ($plan as $item) {
            if ($item['existingId'] === null) {
                $slotId = $this->insertSlotRow($db, $menuId, $item['slot']);
                $this->insertSlotOptions($db, $slotId, $item['slot']['options']);
                continue;
            }

            $db->execute(
                'UPDATE menu_slot SET name = :name, is_required = :required, display_order = :ord WHERE id = :id',
                [
                    'name'     => $item['slot']['name'],
                    'required' => $item['slot']['is_required'],
                    'ord'      => $item['slot']['display_order'],
                    'id'       => $item['existingId'],
                ],
            );
            $this->replaceSlotOptions($db, $item['existingId'], $item['slot']['options']);
        }

        foreach ($surplus as $slotId) {
            // CASCADE (fk_menu_slot_option_menu_slot_id) retire ses options.
            $db->execute('DELETE FROM menu_slot WHERE id = :id', ['id' => $slotId]);
        }
    }

    /**
     * Un menu_slot est-il deja choisi dans au moins une commande (order_item_selection
     * .menu_slot_id, ON DELETE RESTRICT) ? Garde FK-safe de reconcileSlots(), meme
     * esprit que isReferencedByOrders() (niveau menu) mais au niveau slot. Prend le
     * $db de la transaction appelante (et non $this->db) : meme convention que le
     * reste de update()/reconcileSlots().
     */
    private function isSlotReferencedByOrders(DatabaseInterface $db, int $slotId): bool
    {
        return $db->fetch(
            'SELECT id FROM order_item_selection WHERE menu_slot_id = :id LIMIT 1',
            ['id' => $slotId],
        ) !== null;
    }

    /**
     * Insere une ligne menu_slot et renvoie son id. Helper partage entre
     * insertSlots() (create()) et la branche "nouveau slot" de reconcileSlots().
     *
     * @param array{name:string, slot_type:string, is_required:int, display_order:int, options:list<int>} $slot
     */
    private function insertSlotRow(DatabaseInterface $db, int $menuId, array $slot): int
    {
        $db->execute(
            'INSERT INTO menu_slot (menu_id, name, slot_type, is_required, display_order) '
            . 'VALUES (:menu, :name, :type, :required, :ord)',
            [
                'menu'     => $menuId,
                'name'     => $slot['name'],
                'type'     => $slot['slot_type'],
                'required' => $slot['is_required'],
                'ord'      => $slot['display_order'],
            ],
        );

        return (int) ($db->fetch('SELECT LAST_INSERT_ID() AS id')['id'] ?? 0);
    }

    /**
     * Insere les options d'un slot qui n'en a encore aucune (slot neuf). Pas de
     * DELETE prealable ici (contrairement a replaceSlotOptions()) : superflu sur
     * un slot qui vient d'etre cree.
     *
     * @param list<int> $productIds
     */
    private function insertSlotOptions(DatabaseInterface $db, int $slotId, array $productIds): void
    {
        foreach ($productIds as $productId) {
            $db->execute(
                'INSERT INTO menu_slot_option (menu_slot_id, product_id) VALUES (:slot, :product)',
                ['slot' => $slotId, 'product' => $productId],
            );
        }
    }

    /**
     * Remplace integralement les options d'un slot EXISTANT (branche "slot
     * apparie" de reconcileSlots()) : toujours sans risque FK, menu_slot_option
     * n'est reference par aucune commande (seule sa ligne parent menu_slot l'est,
     * via order_item_selection -- verifie sur le schema, db/migrations/0001).
     *
     * @param list<int> $productIds
     */
    private function replaceSlotOptions(DatabaseInterface $db, int $slotId, array $productIds): void
    {
        $db->execute('DELETE FROM menu_slot_option WHERE menu_slot_id = :id', ['id' => $slotId]);
        $this->insertSlotOptions($db, $slotId, $productIds);
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
