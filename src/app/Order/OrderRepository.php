<?php

declare(strict_types=1);

namespace App\Order;

use App\Catalogue\MenuRepository;
use App\Catalogue\ProductRepository;
use App\Core\DatabaseInterface;

/**
 * Creation de commande (P4, chunk 1). Persiste une commande en `pending_payment`
 * (RG-5 etapes 1-4 : customer_order + order_item + order_item_selection +
 * order_item_modifier) dans UNE transaction. Le decrement de stock (RG-T20) et la
 * transition `paid` sont une operation distincte (pay(), 2 etapes — decision projet).
 *
 * Prix recalcules SERVEUR depuis la base (jamais le client, RG-T16) ; snapshots
 * figes (RG-T05/RG-7). order_number = "K" + id (decision utilisateur, diverge du
 * K-AAAA-MM-JJ-NNN de la spec : plus simple, pas de compteur jour). Idempotence
 * via idempotency_key (anti double-clic / retry reseau borne anonyme).
 *
 * Regles de calcul DOCUMENTEES (a confirmer en revue ; non explicitees par la spec) :
 *  - produit a l'unite : toujours format `normal`, prix = product.price_cents, TVA = product.vat_rate ;
 *  - menu : prix = price_maxi_cents si format `maxi` sinon price_normal_cents, TVA = vat_rate du BURGER du menu ;
 *  - modifier `add` : ajoute extra_price_cents (snapshot product_ingredient) au prix de la ligne, au taux TVA de la ligne ;
 *  - TVA par ligne (RG-4) : unit_ht = ROUND(unit_ttc * 1000 / (1000 + vat)), unit_vat = unit_ttc - unit_ht ;
 *    totaux = somme(unit_ttc * qty) ; total_ht = somme(unit_ht * qty) ; total_vat = total_ttc - total_ht.
 */
class OrderRepository
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ProductRepository $products,
        private readonly MenuRepository $menus,
    ) {
    }

    /**
     * @return array{id:int, order_number:string, total_ttc_cents:int, status:string}|null
     */
    public function findByIdempotencyKey(string $key): ?array
    {
        if ($key === '') {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT id, order_number, total_ttc_cents, status FROM customer_order WHERE idempotency_key = :k',
            ['k' => $key],
        );
        if ($row === null) {
            return null;
        }

        return [
            'id'              => (int) $row['id'],
            'order_number'    => (string) $row['order_number'],
            'total_ttc_cents' => (int) $row['total_ttc_cents'],
            'status'          => (string) $row['status'],
        ];
    }

    /**
     * Recherche une commande par son numero (prefixe canal K/C/D + id). Lecture
     * publique du statut cote borne (suivi apres encaissement). Renvoie null si le
     * numero est inconnu. Lecture seule : ne sert que des champs non sensibles
     * (la commande kiosk est anonyme, pas de PII).
     *
     * @return array{id:int, order_number:string, total_ttc_cents:int, status:string}|null
     */
    public function findByNumber(string $number): ?array
    {
        if ($number === '') {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT id, order_number, total_ttc_cents, status FROM customer_order WHERE order_number = :n',
            ['n' => $number],
        );
        if ($row === null) {
            return null;
        }

        return [
            'id'              => (int) $row['id'],
            'order_number'    => (string) $row['order_number'],
            'total_ttc_cents' => (int) $row['total_ttc_cents'],
            'status'          => (string) $row['status'],
        ];
    }

    /**
     * Cree une commande borne en pending_payment. Idempotent sur idempotency_key.
     *
     * Tolerant sur la forme d'entree (corps JSON decode tel quel) : chaque cle est
     * relue defensivement et la validation metier leve OrderValidationException.
     *
     * @param array<string, mixed> $req
     * @return array{id:int, order_number:string, total_ttc_cents:int, status:string}
     * @throws OrderValidationException si une reference est invalide / indisponible.
     */
    public function createPending(array $req): array
    {
        $key = trim((string) ($req['idempotency_key'] ?? ''));
        $existing = $this->findByIdempotencyKey($key);
        if ($existing !== null) {
            return $existing;
        }

        $serviceMode = (string) ($req['service_mode'] ?? '');
        if (!in_array($serviceMode, ['dine_in', 'takeaway', 'drive'], true)) {
            throw new OrderValidationException('INVALID_SERVICE_MODE');
        }
        $serviceTag = $serviceMode === 'dine_in' ? trim((string) ($req['service_tag'] ?? '')) : '';
        if ($serviceTag !== '' && mb_strlen($serviceTag) > 20) {
            throw new OrderValidationException('INVALID_SERVICE_TAG');
        }

        $items = isset($req['items']) && is_array($req['items']) ? $req['items'] : [];
        if ($items === []) {
            throw new OrderValidationException('EMPTY_ORDER');
        }

        // Resolution + calcul (lecture seule) AVANT la transaction d'ecriture.
        $lines = array_map(fn (array $item): array => $this->resolveLine($item), $items);

        $totalTtc = 0;
        $totalHt = 0;
        foreach ($lines as $l) {
            $totalTtc += $l['unit_ttc'] * $l['quantity'];
            $totalHt += $l['unit_ht'] * $l['quantity'];
        }
        $totalVat = $totalTtc - $totalHt;
        if ($totalTtc <= 0) {
            throw new OrderValidationException('EMPTY_ORDER');
        }

        $result = ['id' => 0, 'order_number' => '', 'total_ttc_cents' => $totalTtc, 'status' => 'pending_payment'];

        $this->db->transaction(function (DatabaseInterface $db) use ($key, $serviceMode, $serviceTag, $lines, $totalTtc, $totalHt, $totalVat, &$result): void {
            $db->execute(
                'INSERT INTO customer_order '
                . '(order_number, idempotency_key, source, service_mode, service_tag, status, '
                . ' total_ht_cents, total_vat_cents, total_ttc_cents) '
                . "VALUES ('', :idem, 'kiosk', :mode, :tag, 'pending_payment', :ht, :vat, :ttc)",
                [
                    'idem' => $key !== '' ? $key : null,
                    'mode' => $serviceMode,
                    'tag'  => $serviceTag !== '' ? $serviceTag : null,
                    'ht'   => $totalHt,
                    'vat'  => $totalVat,
                    'ttc'  => $totalTtc,
                ],
            );
            $orderId = (int) ($db->fetch('SELECT LAST_INSERT_ID() AS id')['id'] ?? 0);
            $orderNumber = 'K' . $orderId;
            $db->execute(
                'UPDATE customer_order SET order_number = :num WHERE id = :id',
                ['num' => $orderNumber, 'id' => $orderId],
            );

            foreach ($lines as $l) {
                $db->execute(
                    'INSERT INTO order_item '
                    . '(order_id, item_type, product_id, menu_id, format, label_snapshot, '
                    . ' unit_price_cents_snapshot, vat_rate_snapshot, quantity) '
                    . 'VALUES (:oid, :type, :pid, :mid, :fmt, :label, :price, :vat, :qty)',
                    [
                        'oid'   => $orderId,
                        'type'  => $l['item_type'],
                        'pid'   => $l['product_id'],
                        'mid'   => $l['menu_id'],
                        'fmt'   => $l['format'],
                        'label' => $l['label'],
                        'price' => $l['unit_ttc'],
                        'vat'   => $l['vat_rate'],
                        'qty'   => $l['quantity'],
                    ],
                );
                $itemId = (int) ($db->fetch('SELECT LAST_INSERT_ID() AS id')['id'] ?? 0);

                foreach ($l['selections'] as $sel) {
                    $db->execute(
                        'INSERT INTO order_item_selection (order_item_id, menu_slot_id, product_id, label_snapshot) '
                        . 'VALUES (:oiid, :slot, :pid, :label)',
                        ['oiid' => $itemId, 'slot' => $sel['menu_slot_id'], 'pid' => $sel['product_id'], 'label' => $sel['label']],
                    );
                }
                foreach ($l['modifiers'] as $mod) {
                    $db->execute(
                        'INSERT INTO order_item_modifier (order_item_id, ingredient_id, action, extra_price_cents) '
                        . 'VALUES (:oiid, :ing, :act, :extra)',
                        ['oiid' => $itemId, 'ing' => $mod['ingredient_id'], 'act' => $mod['action'], 'extra' => $mod['extra_price_cents']],
                    );
                }
            }

            $result['id'] = $orderId;
            $result['order_number'] = $orderNumber;
        });

        return $result;
    }

    /**
     * Encaisse une commande pending_payment : transition -> paid ET decrement de
     * stock atomique (RG-5 etapes 5-6, RG-T11 / RG-T20) dans UNE transaction.
     *
     * Idempotent : une commande deja `paid` est renvoyee telle quelle sans
     * re-decrementer ; `delivered` / `cancelled` -> INVALID_TRANSITION ; numero
     * inconnu -> ORDER_NOT_FOUND. La transition est gardee par `status =
     * 'pending_payment'` dans l'UPDATE : sous une course concurrente, seul le
     * premier appel decremente (l'autre voit 0 ligne affectee et sort idempotent).
     *
     * Decrement (RG-5 etape 5) : par ingredient consomme, units =
     * (format maxi ? quantity_maxi : quantity_normal) * order_item.quantity, ajuste
     * par les modificateurs de la ligne (remove => pas de decrement pour cet
     * ingredient ; add => portion de base + supplement). Les unites sont AGREGEES
     * par ingredient sur toute la commande : un seul UPDATE auto-verrouillant et une
     * seule ligne stock_movement(sale) par ingredient affecte (POST-4). Les UPDATE
     * sont ordonnes par ingredient_id (ordre de verrou stable -> pas de deadlock
     * entre commandes concurrentes). stock_quantity est signe (survente possible,
     * RG-T20) : le decrement ne se conditionne a aucun plancher.
     *
     * NB : inerte tant que les recettes (product_ingredient) ne sont pas seedees —
     * la transition `paid` s'applique, mais aucun mouvement de stock n'est produit
     * faute de composition. La logique s'active des que les recettes existent.
     *
     * @param int|null $actingUserId acteur comptoir/drive (stock_movement.user_id +
     *                               customer_order.acting_user_id) ; NULL pour le kiosk.
     * @return array{id:int, order_number:string, total_ttc_cents:int, status:string}
     * @throws OrderValidationException
     */
    public function pay(string $orderNumber, ?int $actingUserId = null): array
    {
        $order = $this->db->fetch(
            'SELECT id, order_number, total_ttc_cents, status FROM customer_order WHERE order_number = :n',
            ['n' => $orderNumber],
        );
        if ($order === null) {
            throw new OrderValidationException('ORDER_NOT_FOUND');
        }

        $result = [
            'id'              => (int) $order['id'],
            'order_number'    => (string) $order['order_number'],
            'total_ttc_cents' => (int) $order['total_ttc_cents'],
            'status'          => 'paid',
        ];

        $status = (string) $order['status'];
        if ($status === 'paid') {
            return $result; // idempotent : deja encaissee, pas de re-decrement.
        }
        if ($status !== 'pending_payment') {
            throw new OrderValidationException('INVALID_TRANSITION'); // delivered / cancelled.
        }

        $orderId = (int) $order['id'];
        $this->db->transaction(function (DatabaseInterface $db) use ($orderId, $actingUserId): void {
            $affected = $db->execute(
                'UPDATE customer_order SET status = \'paid\', paid_at = NOW(), '
                . 'acting_user_id = COALESCE(:uid, acting_user_id), updated_at = NOW() '
                . 'WHERE id = :id AND status = \'pending_payment\'',
                ['uid' => $actingUserId, 'id' => $orderId],
            );
            if ($affected === 0) {
                // Course perdue : un autre appel a deja transite. S'il a abouti a
                // `paid`, il a fait le decrement -> on sort idempotent ; sinon la
                // transition est invalide (statut terminal).
                $current = (string) ($db->fetch('SELECT status FROM customer_order WHERE id = :id', ['id' => $orderId])['status'] ?? '');
                if ($current === 'paid') {
                    return;
                }
                throw new OrderValidationException('INVALID_TRANSITION');
            }

            foreach ($this->consumption($db, $orderId) as $ingredientId => $units) {
                $db->execute(
                    'UPDATE ingredient SET stock_quantity = stock_quantity - :u WHERE id = :id',
                    ['u' => $units, 'id' => $ingredientId],
                );
                $db->execute(
                    'INSERT INTO stock_movement (ingredient_id, movement_type, delta, order_id, user_id, note) '
                    . 'VALUES (:ing, \'sale\', :delta, :oid, :uid, NULL)',
                    ['ing' => $ingredientId, 'delta' => -$units, 'oid' => $orderId, 'uid' => $actingUserId],
                );
            }
        });

        return $result;
    }

    /**
     * Transition paid -> delivered (DELIVER_ORDER, geste unique de remise, mlt 6.1).
     * NON PIN-gated : operation routiniere, hors ensemble sensible RG-T13. Idempotente
     * (une commande deja delivered est renvoyee sans erreur). 404 si inconnue ;
     * INVALID_TRANSITION si la commande n'est pas au statut paid (pending / cancelled).
     *
     * @return array{id:int, order_number:string, total_ttc_cents:int, status:string}
     * @throws OrderValidationException
     */
    public function deliver(string $orderNumber): array
    {
        $order = $this->db->fetch(
            'SELECT id, order_number, total_ttc_cents, status FROM customer_order WHERE order_number = :n',
            ['n' => $orderNumber],
        );
        if ($order === null) {
            throw new OrderValidationException('ORDER_NOT_FOUND');
        }

        $result = [
            'id'              => (int) $order['id'],
            'order_number'    => (string) $order['order_number'],
            'total_ttc_cents' => (int) $order['total_ttc_cents'],
            'status'          => 'delivered',
        ];

        $status = (string) $order['status'];
        if ($status === 'delivered') {
            return $result; // idempotent : remise deja actee.
        }
        if ($status !== 'paid') {
            throw new OrderValidationException('INVALID_TRANSITION'); // pending_payment / cancelled.
        }

        $affected = $this->db->execute(
            'UPDATE customer_order SET status = \'delivered\', delivered_at = NOW(), '
            . 'updated_at = NOW() WHERE id = :id AND status = \'paid\'',
            ['id' => (int) $order['id']],
        );
        if ($affected === 0) {
            // Course perdue : un autre appel a deja transite. Idempotent si delivered.
            $current = (string) ($this->db->fetch('SELECT status FROM customer_order WHERE id = :id', ['id' => (int) $order['id']])['status'] ?? '');
            if ($current === 'delivered') {
                return $result;
            }
            throw new OrderValidationException('INVALID_TRANSITION');
        }

        return $result;
    }

    /**
     * Annulation d'une commande (CANCEL_ORDER, mlt 7.1). Transition gardee
     * pending_payment|paid -> cancelled, re-credit de stock CONDITIONNEL et ecriture
     * audit_log dans UNE transaction (RG-T07/T08/T11/T14).
     *
     * Le re-credit n'a lieu que si la commande etait `paid` AVANT l'annulation : une
     * commande `pending_payment` n'avait jamais decremente le stock (le decrement est
     * pose a la transition `paid`, cf. pay()), il n'y a donc rien a re-crediter. Le
     * re-credit reutilise consumption() (memes unites que le decrement de pay()),
     * inversees (delta positif) ; un ingredient entierement retire (modifieur remove)
     * n'a pas ete decremente -> consumption() ne le retourne pas -> pas de re-credit.
     *
     * Concurrence (RG-T07/RG-T20) : le statut est relu A L'INTERIEUR de la transaction
     * via l'UPDATE garde par `status IN ('pending_payment','paid')` ; 0 ligne affectee
     * = course perdue (un autre appel a deja transite) -> INVALID_TRANSITION. Le
     * re-credit se base sur le pre-status lu en entree (coherent : seul l'appel qui a
     * remporte la garde poursuit, et il n'y a pas de SELECT FOR UPDATE — RG-T20).
     *
     * @param int|null $actingUserId equipier resolu par PIN (audit_log.actor_user_id +
     *                               stock_movement.user_id) ; le controleur le fournit.
     * @param int|null $actingRoleId role de l'equipier resolu par PIN (audit_log.actor_role_id).
     * @return array{id:int, order_number:string, total_ttc_cents:int, status:string}
     * @throws OrderValidationException
     */
    public function cancel(string $orderNumber, ?int $actingUserId, ?int $actingRoleId): array
    {
        $order = $this->db->fetch(
            'SELECT id, order_number, total_ttc_cents, status FROM customer_order WHERE order_number = :n',
            ['n' => $orderNumber],
        );
        if ($order === null) {
            throw new OrderValidationException('ORDER_NOT_FOUND');
        }

        $preStatus = (string) $order['status'];
        if (!in_array($preStatus, ['pending_payment', 'paid'], true)) {
            throw new OrderValidationException('CANNOT_CANCEL_IN_STATE'); // delivered / cancelled (statut terminal).
        }

        $result = [
            'id'              => (int) $order['id'],
            'order_number'    => (string) $order['order_number'],
            'total_ttc_cents' => (int) $order['total_ttc_cents'],
            'status'          => 'cancelled',
        ];

        $orderId = (int) $order['id'];
        $totalTtc = (int) $order['total_ttc_cents'];
        $this->db->transaction(function (DatabaseInterface $db) use ($orderId, $preStatus, $totalTtc, $actingUserId, $actingRoleId): void {
            $affected = $db->execute(
                'UPDATE customer_order SET status = \'cancelled\', cancelled_at = NOW(), updated_at = NOW() '
                . 'WHERE id = :id AND status IN (\'pending_payment\', \'paid\')',
                ['id' => $orderId],
            );
            if ($affected === 0) {
                // Course perdue : la garde RG-T07 n'a affecte aucune ligne (un autre
                // appel a deja transite vers un statut terminal). Pas d'issue idempotente
                // pour l'annulation (a la difference de pay/deliver) : on signale la
                // transition invalide et la transaction est annulee (aucun re-credit).
                throw new OrderValidationException('INVALID_TRANSITION');
            }

            // RG-3 : re-credit CONDITIONNEL. On le decide sur l'EXISTENCE de mouvements
            // 'sale' pour cette commande (poses au decrement de pay()), PAS sur le
            // pre-status lu hors transaction : insensible a la course
            // pending_payment -> paid -> cancel (sinon un pay() concurrent gagnant
            // laisserait le stock decremente sans re-credit, derive silencieuse). De
            // fait idempotent : sans mouvement 'sale', rien a re-crediter. Memes unites
            // que pay() (consumption), inversees (delta positif).
            $restocked = $this->hasSaleMovements($db, $orderId);
            if ($restocked) {
                foreach ($this->consumption($db, $orderId) as $ingredientId => $units) {
                    $db->execute(
                        'UPDATE ingredient SET stock_quantity = stock_quantity + :u WHERE id = :id',
                        ['u' => $units, 'id' => $ingredientId],
                    );
                    $db->execute(
                        'INSERT INTO stock_movement (ingredient_id, movement_type, delta, order_id, user_id, note) '
                        . 'VALUES (:ing, \'cancellation\', :delta, :oid, :uid, NULL)',
                        ['ing' => $ingredientId, 'delta' => $units, 'oid' => $orderId, 'uid' => $actingUserId],
                    );
                }
            }

            // RG-6/RG-T14 : trace d'audit immuable dans la meme transaction que l'effet.
            $recredit = $restocked ? $totalTtc : 0;
            $summary = 'Annulation depuis ' . $preStatus . ', re-credit ' . $recredit . 'c';
            $db->execute(
                'INSERT INTO audit_log (actor_user_id, actor_role_id, action_code, entity_type, entity_id, summary) '
                . 'VALUES (:uid, :rid, :code, :etype, :eid, :summary)',
                [
                    'uid'     => $actingUserId,
                    'rid'     => $actingRoleId,
                    'code'    => 'order.cancel',
                    'etype'   => 'customer_order',
                    'eid'     => $orderId,
                    'summary' => $summary,
                ],
            );
        });

        return $result;
    }

    /**
     * Vrai si la commande porte au moins un mouvement de stock `sale` (donc elle a
     * deja ete encaissee/decrementee par pay()). Sert a decider le re-credit a
     * l'annulation independamment du statut observe hors transaction (anti-course).
     */
    private function hasSaleMovements(DatabaseInterface $db, int $orderId): bool
    {
        return $db->fetch(
            'SELECT 1 AS x FROM stock_movement WHERE order_id = :oid AND movement_type = \'sale\' LIMIT 1',
            ['oid' => $orderId],
        ) !== null;
    }

    /**
     * Unites de stock a decrementer, AGREGEES par ingredient_id sur toute la
     * commande (lecture des lignes persistees + recettes des produits supports).
     * Cle = ingredient_id, triee croissant (ordre de verrou stable). Un ingredient
     * dont l'unite agregee retombe a 0 (entierement retire) n'est PAS retourne :
     * aucun mouvement n'est alors produit. Voir pay() pour la regle de calcul.
     *
     * @return array<int, int>
     */
    private function consumption(DatabaseInterface $db, int $orderId): array
    {
        $items = $db->fetchAll(
            'SELECT id, item_type, product_id, menu_id, format, quantity FROM order_item WHERE order_id = :oid',
            ['oid' => $orderId],
        );

        /** @var array<int, int> $units */
        $units = [];
        foreach ($items as $item) {
            $itemId = (int) $item['id'];
            $quantity = max(1, (int) $item['quantity']);
            $maxi = ((string) $item['format']) === 'maxi';

            // Produit(s) dont la recette est consommee : le produit pour une ligne
            // produit ; le burger + chaque selection pour une ligne menu.
            $productIds = [];
            if ((string) $item['item_type'] === 'product') {
                $productIds[] = (int) $item['product_id'];
            } else {
                $menu = $this->menus->find((int) $item['menu_id']);
                if ($menu !== null) {
                    $productIds[] = (int) $menu['burger_product_id'];
                }
                foreach ($db->fetchAll('SELECT product_id FROM order_item_selection WHERE order_item_id = :oiid', ['oiid' => $itemId]) as $sel) {
                    $productIds[] = (int) $sel['product_id'];
                }
            }

            // Modificateurs de la ligne (ingredient_id => action). Ils s'appliquent a
            // toute recette de la ligne contenant l'ingredient ; en pratique ils
            // ciblent le produit support (burger), dont les ingredients ne recoupent
            // pas ceux des selections (boisson / accompagnement).
            $actions = [];
            foreach ($db->fetchAll('SELECT ingredient_id, action FROM order_item_modifier WHERE order_item_id = :oiid', ['oiid' => $itemId]) as $mod) {
                $actions[(int) $mod['ingredient_id']] = (string) $mod['action'];
            }

            foreach ($productIds as $productId) {
                foreach ($this->products->composition($productId) as $row) {
                    $ingredientId = (int) $row['ingredient_id'];
                    $perUnit = $maxi ? (int) $row['quantity_maxi'] : (int) $row['quantity_normal'];
                    $base = $perUnit * $quantity;
                    $consumed = match ($actions[$ingredientId] ?? null) {
                        'remove' => 0,
                        'add'    => $base * 2, // portion de base + supplement (RG-5).
                        default  => $base,
                    };
                    if ($consumed > 0) {
                        $units[$ingredientId] = ($units[$ingredientId] ?? 0) + $consumed;
                    }
                }
            }
        }

        ksort($units);

        return $units;
    }

    /**
     * Resout une ligne (produit ou menu) : lit le catalogue, valide, calcule le prix.
     *
     * @param array<string, mixed> $item
     * @return array{item_type:string, product_id:?int, menu_id:?int, format:string, label:string, unit_ttc:int, unit_ht:int, vat_rate:int, quantity:int, selections:list<array{menu_slot_id:int,product_id:int,label:string}>, modifiers:list<array{ingredient_id:int,action:string,extra_price_cents:int}>}
     */
    private function resolveLine(array $item): array
    {
        $type = (string) ($item['type'] ?? '');
        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        $format = ($item['format'] ?? 'normal') === 'maxi' ? 'maxi' : 'normal';

        if ($type === 'product') {
            $product = $this->products->find((int) ($item['product_id'] ?? 0));
            if ($product === null || (int) ($product['is_available'] ?? 0) !== 1) {
                throw new OrderValidationException('PRODUCT_UNAVAILABLE');
            }
            $unitBase = (int) $product['price_cents'];
            $vat = (int) $product['vat_rate'];
            $modifiers = $this->resolveModifiers($item, (int) $product['id']);
            $unitTtc = $unitBase + $this->modifiersExtra($modifiers);

            return $this->line('product', (int) $product['id'], null, 'normal', (string) $product['name'], $unitTtc, $vat, $quantity, [], $modifiers);
        }

        if ($type === 'menu') {
            $menu = $this->menus->find((int) ($item['menu_id'] ?? 0));
            if ($menu === null || (int) ($menu['is_available'] ?? 0) !== 1) {
                throw new OrderValidationException('MENU_UNAVAILABLE');
            }
            $burger = $this->products->find((int) $menu['burger_product_id']);
            $vat = $burger !== null ? (int) $burger['vat_rate'] : 100;
            $unitBase = $format === 'maxi' ? (int) $menu['price_maxi_cents'] : (int) $menu['price_normal_cents'];
            $selections = $this->resolveSelections($item, (int) $menu['id']);
            $modifiers = $this->resolveModifiers($item, (int) $menu['burger_product_id']);
            $unitTtc = $unitBase + $this->modifiersExtra($modifiers);

            return $this->line('menu', null, (int) $menu['id'], $format, (string) $menu['name'], $unitTtc, $vat, $quantity, $selections, $modifiers);
        }

        throw new OrderValidationException('INVALID_ITEM_TYPE');
    }

    /**
     * @param list<array{ingredient_id:int,action:string,extra_price_cents:int}> $modifiers
     */
    private function modifiersExtra(array $modifiers): int
    {
        $extra = 0;
        foreach ($modifiers as $m) {
            if ($m['action'] === 'add') {
                $extra += $m['extra_price_cents'];
            }
        }

        return $extra;
    }

    /**
     * @param array<string, mixed> $item
     * @return list<array{menu_slot_id:int,product_id:int,label:string}>
     */
    private function resolveSelections(array $item, int $menuId): array
    {
        $slots = $this->menus->slotsWithOptions($menuId);
        /** @var array<int, list<int>> $optionsBySlot */
        $optionsBySlot = [];
        foreach ($slots as $s) {
            $optionsBySlot[(int) $s['id']] = array_map('intval', $s['option_product_ids']);
        }

        $out = [];
        $raw = isset($item['selections']) && is_array($item['selections']) ? $item['selections'] : [];
        foreach ($raw as $sel) {
            $slotId = (int) ($sel['menu_slot_id'] ?? 0);
            $pid = (int) ($sel['product_id'] ?? 0);
            if (!isset($optionsBySlot[$slotId]) || !in_array($pid, $optionsBySlot[$slotId], true)) {
                throw new OrderValidationException('INVALID_SELECTION');
            }
            $product = $this->products->find($pid);
            $out[] = ['menu_slot_id' => $slotId, 'product_id' => $pid, 'label' => $product !== null ? (string) $product['name'] : ''];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $item
     * @return list<array{ingredient_id:int,action:string,extra_price_cents:int}>
     */
    private function resolveModifiers(array $item, int $productId): array
    {
        $raw = isset($item['modifiers']) && is_array($item['modifiers']) ? $item['modifiers'] : [];
        if ($raw === []) {
            return [];
        }
        // Recette du produit support : valide l'ingredient + figes l'extra_price (add).
        $recipe = [];
        foreach ($this->products->composition($productId) as $ing) {
            $recipe[(int) $ing['ingredient_id']] = $ing;
        }

        $out = [];
        foreach ($raw as $mod) {
            $ingId = (int) ($mod['ingredient_id'] ?? 0);
            $action = ($mod['action'] ?? '') === 'add' ? 'add' : 'remove';
            if (!isset($recipe[$ingId])) {
                throw new OrderValidationException('INVALID_MODIFIER');
            }
            $row = $recipe[$ingId];
            if ($action === 'remove' && (int) ($row['is_removable'] ?? 0) !== 1) {
                throw new OrderValidationException('INGREDIENT_NOT_REMOVABLE');
            }
            if ($action === 'add' && (int) ($row['is_addable'] ?? 0) !== 1) {
                throw new OrderValidationException('INGREDIENT_NOT_ADDABLE');
            }
            $out[] = [
                'ingredient_id'     => $ingId,
                'action'            => $action,
                'extra_price_cents' => $action === 'add' ? (int) ($row['extra_price_cents'] ?? 0) : 0,
            ];
        }

        return $out;
    }

    /**
     * @param list<array{menu_slot_id:int,product_id:int,label:string}> $selections
     * @param list<array{ingredient_id:int,action:string,extra_price_cents:int}> $modifiers
     * @return array{item_type:string, product_id:?int, menu_id:?int, format:string, label:string, unit_ttc:int, unit_ht:int, vat_rate:int, quantity:int, selections:list<array{menu_slot_id:int,product_id:int,label:string}>, modifiers:list<array{ingredient_id:int,action:string,extra_price_cents:int}>}
     */
    private function line(string $type, ?int $productId, ?int $menuId, string $format, string $label, int $unitTtc, int $vat, int $quantity, array $selections, array $modifiers): array
    {
        $unitHt = (int) round($unitTtc * 1000 / (1000 + $vat));

        return [
            'item_type'  => $type,
            'product_id' => $productId,
            'menu_id'    => $menuId,
            'format'     => $format,
            'label'      => $label,
            'unit_ttc'   => $unitTtc,
            'unit_ht'    => $unitHt,
            'vat_rate'   => $vat,
            'quantity'   => $quantity,
            'selections' => $selections,
            'modifiers'  => $modifiers,
        ];
    }
}
