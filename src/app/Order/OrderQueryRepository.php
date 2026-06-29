<?php

declare(strict_types=1);

namespace App\Order;

use App\Core\DatabaseInterface;

/**
 * Lecture du domaine commande pour le back-office (P4) : liste des commandes
 * recentes (order.read) + KPIs de vente du tableau de bord (stats.read).
 *
 * Separe de OrderRepository (ecriture : createPending / pay) pour garder le
 * read-side leger -- il ne depend que de DatabaseInterface, pas des repos
 * catalogue. Non `final` : seam de test (sous-classe -> double sans base).
 */
class OrderQueryRepository
{
    /**
     * Seuils de la bande SLA du KDS (RG-4 de 5.1 ; seuil cible ~10 min, Note 6).
     * Constantes plutot qu'env : un seul reglage, simple a relire et a tester ;
     * a externaliser en configuration si le besoin de variation par site apparait.
     */
    private const SLA_WARN_SECONDS = 300; // 5 min : passage vert -> ambre.
    private const SLA_LATE_SECONDS = 600; // 10 min (seuil cible) : ambre -> rouge.

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Commandes les plus recentes (tous statuts confondus), pour la liste admin.
     * Triees de la plus recente a la plus ancienne. $limit borne [1, 200] et
     * interpole comme ENTIER (pas de bind : LIMIT n'accepte pas de parametre lie
     * avec ATTR_EMULATE_PREPARES=false).
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return $this->db->fetchAll(
            'SELECT order_number, source, service_mode, service_tag, status, total_ttc_cents, created_at, paid_at '
            . 'FROM customer_order ORDER BY created_at DESC, id DESC LIMIT ' . $limit,
        );
    }

    /**
     * Sources de commande visibles par un role (role_visible_source, dictionary 3.16).
     * Liste vide en base = vue globale (admin / manager voient tout) : on renvoie alors
     * les trois sources. Sert a filtrer la file de preparation par canal.
     *
     * @return list<string>
     */
    public function visibleSources(int $roleId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT source FROM role_visible_source WHERE role_id = :r',
            ['r' => $roleId],
        );
        $sources = array_values(array_filter(array_map(
            static fn (array $r): string => (string) ($r['source'] ?? ''),
            $rows,
        )));

        return $sources === [] ? ['kiosk', 'counter', 'drive'] : $sources;
    }

    /**
     * File de preparation (KDS) : commandes au statut `paid`, triees par paid_at
     * CROISSANT (la plus ancienne d'abord, RG-T12), filtrees par les sources visibles.
     * Les sources viennent d'une allowlist (role_visible_source) et sont liees comme
     * parametres. Liste de sources vide -> file vide (pas de canal visible).
     *
     * @param list<string> $sources
     * @return list<array<string, mixed>>
     */
    public function paidQueue(array $sources): array
    {
        if ($sources === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($sources) as $i => $source) {
            $key = 's' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $source;
        }

        return $this->db->fetchAll(
            'SELECT order_number, source, service_mode, service_tag, status, total_ttc_cents, paid_at '
            . 'FROM customer_order WHERE status IN (\'paid\', \'preparing\', \'ready\') AND source IN (' . implode(', ', $placeholders) . ') '
            . 'ORDER BY paid_at ASC, id ASC',
            $params,
        );
    }

    /**
     * File de preparation enrichie pour le KDS (LIST_ORDERS_DISPLAY, mlt 5.1) :
     * memes commandes `paid` que paidQueue (meme filtre de sources, meme tri
     * paid_at croissant), mais chaque commande porte en plus :
     *  - `items`   : ses lignes order_item (label_snapshot, quantity, format),
     *                chacune avec ses `selections` (choix de slot, label_snapshot)
     *                et ses `modifiers` (ingredient + action remove/add) ;
     *  - `sla_band`: la bande SLA derivee de (now - paid_at) -- fresh / warn / late.
     *
     * RG-3 (5.1) : l'affichage s'appuie sur les SNAPSHOTS persistes ; aucune
     * re-jointure sur product/menu n'est faite. RG-4 : la couleur est calculee au
     * rendu, sans etat stocke (Note 6 du dictionnaire).
     *
     * Anti N+1 : 4 requetes au total quel que soit le nombre de commandes (la file
     * + un fetch groupe pour items / selections / modifiers via IN (...)), plutot
     * qu'un fetch par commande. L'horloge est injectable ($now) pour des bandes SLA
     * deterministes en test (meme couture ?int $now que SessionGuard / PinThrottle).
     *
     * @param list<string> $sources
     * @param int|null     $now epoch de reference pour la bande SLA ; null => time()
     * @return list<array<string, mixed>>
     */
    public function paidQueueWithDetail(array $sources, ?int $now = null): array
    {
        $now ??= time();

        if ($sources === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($sources) as $i => $source) {
            $key = 's' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $source;
        }

        // `id` est selectionne ici (a la difference de paidQueue) : il sert de cle de
        // jointure pour le fetch groupe des lignes, sans etre expose tel quel a la vue.
        $orders = $this->db->fetchAll(
            'SELECT id, order_number, source, service_mode, service_tag, status, total_ttc_cents, paid_at '
            . 'FROM customer_order WHERE status IN (\'paid\', \'preparing\', \'ready\') AND source IN (' . implode(', ', $placeholders) . ') '
            . 'ORDER BY paid_at ASC, id ASC',
            $params,
        );
        if ($orders === []) {
            return [];
        }

        $orderIds = array_map(static fn (array $o): int => (int) $o['id'], $orders);
        $items = $this->itemsForOrders($orderIds);

        $out = [];
        foreach ($orders as $order) {
            $order['items'] = $items[(int) $order['id']] ?? [];
            $order['sla_band'] = $this->slaBand((string) ($order['paid_at'] ?? ''), $now);
            unset($order['id']); // l'id technique ne sert qu'a la jointure, pas a la vue.
            $out[] = $order;
        }

        return $out;
    }

    /**
     * Bande SLA d'une commande a partir de l'ecart (now - paid_at), Note 6 / RG-4 (5.1).
     * Bandes : `fresh` si < 5 min, `warn` si 5-10 min, `late` au-dela (seuil cible
     * 10 min). Un paid_at vide ou non parsable retombe sur `fresh` (pas d'alerte sur
     * une donnee absente). Calcul pur (pas d'I/O) : la vue ne fait que mapper la bande
     * vers une classe CSS ; cf. kitchen/display.php.
     */
    public function slaBand(string $paidAt, ?int $now = null): string
    {
        $now ??= time();
        $paid = $paidAt !== '' ? strtotime($paidAt) : false;
        if ($paid === false) {
            return 'fresh';
        }

        $elapsed = $now - $paid;
        if ($elapsed >= self::SLA_LATE_SECONDS) {
            return 'late';
        }
        if ($elapsed >= self::SLA_WARN_SECONDS) {
            return 'warn';
        }

        return 'fresh';
    }

    /**
     * Charge en lot les lignes des commandes donnees (order_item + selections +
     * modifiers), regroupees par order_id puis structurees par ligne. Trois requetes
     * groupees (IN (...)) au lieu d'un fetch par commande : borne le cout a O(1)
     * aller-retours quel que soit le volume de la file. Les ids viennent de la liste
     * interne (entiers surs), interpoles comme entiers : LIMIT/IN ne lient pas avec
     * ATTR_EMULATE_PREPARES=false, et un cast (int) ferme l'injection.
     *
     * @param list<int> $orderIds
     * @return array<int, list<array<string, mixed>>> items par order_id
     */
    private function itemsForOrders(array $orderIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $orderIds)));
        if ($ids === []) {
            return [];
        }
        $inOrders = implode(', ', $ids);

        $itemRows = $this->db->fetchAll(
            'SELECT id, order_id, item_type, format, label_snapshot, quantity '
            . 'FROM order_item WHERE order_id IN (' . $inOrders . ') ORDER BY id ASC',
        );
        if ($itemRows === []) {
            return [];
        }

        $itemIds = array_map(static fn (array $r): int => (int) $r['id'], $itemRows);
        $inItems = implode(', ', array_values(array_unique($itemIds)));

        $selectionsByItem = $this->groupByItem(
            $this->db->fetchAll(
                'SELECT order_item_id, label_snapshot FROM order_item_selection '
                . 'WHERE order_item_id IN (' . $inItems . ') ORDER BY id ASC',
            ),
        );
        // order_item_modifier ne stocke PAS de libelle (uniquement ingredient_id) :
        // a la difference des selections (label_snapshot present), le nom lisible vient
        // d'une jointure sur `ingredient`. Seule re-jointure necessaire (RG-3 ne
        // l'exclut que pour product/menu). Le nom d'ingredient est relativement stable ;
        // a defaut de snapshot c'est la source disponible.
        $modifiersByItem = $this->groupByItem(
            $this->db->fetchAll(
                'SELECT oim.order_item_id, oim.action, i.name AS ingredient_name '
                . 'FROM order_item_modifier oim JOIN ingredient i ON i.id = oim.ingredient_id '
                . 'WHERE oim.order_item_id IN (' . $inItems . ') ORDER BY oim.id ASC',
            ),
        );

        $itemsByOrder = [];
        foreach ($itemRows as $row) {
            $itemId = (int) $row['id'];
            $row['selections'] = $selectionsByItem[$itemId] ?? [];
            $row['modifiers'] = $modifiersByItem[$itemId] ?? [];
            $itemsByOrder[(int) $row['order_id']][] = $row;
        }

        return $itemsByOrder;
    }

    /**
     * Regroupe des lignes filles par leur order_item_id (cle de jointure commune
     * aux selections et aux modificateurs).
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, list<array<string, mixed>>>
     */
    private function groupByItem(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) ($row['order_item_id'] ?? 0)][] = $row;
        }

        return $grouped;
    }

    /**
     * KPIs de vente : CA encaisse (TOUS les statuts post-paiement : paid, preparing,
     * ready, delivered -- l'argent est encaisse des pay(), avant la preparation), nombre
     * de commandes encaissees, panier moyen, CA et nombre du JOUR, total de commandes, et
     * la repartition par statut. Le CA exclut pending_payment (non encaisse) et cancelled.
     *
     * @return array{revenue_cents:int, paid_count:int, avg_basket_cents:int, revenue_today_cents:int, paid_count_today:int, total_orders:int, by_status:array<string,int>}
     */
    public function salesKpis(): array
    {
        $t = $this->db->fetch(
            "SELECT
                COALESCE(SUM(CASE WHEN status IN ('paid','preparing','ready','delivered') THEN total_ttc_cents ELSE 0 END), 0) AS revenue,
                COALESCE(SUM(status IN ('paid','preparing','ready','delivered')), 0) AS paid_count,
                COALESCE(SUM(CASE WHEN status IN ('paid','preparing','ready','delivered') AND created_at >= CURDATE() THEN total_ttc_cents ELSE 0 END), 0) AS revenue_today,
                COALESCE(SUM(status IN ('paid','preparing','ready','delivered') AND created_at >= CURDATE()), 0) AS paid_count_today,
                COUNT(*) AS total_orders
             FROM customer_order",
        ) ?? [];

        $revenue = (int) ($t['revenue'] ?? 0);
        $paid = (int) ($t['paid_count'] ?? 0);

        $byStatus = [];
        foreach ($this->db->fetchAll('SELECT status, COUNT(*) AS n FROM customer_order GROUP BY status') as $r) {
            $byStatus[(string) ($r['status'] ?? '')] = (int) ($r['n'] ?? 0);
        }

        return [
            'revenue_cents'       => $revenue,
            'paid_count'          => $paid,
            'avg_basket_cents'    => $paid > 0 ? intdiv($revenue, $paid) : 0,
            'revenue_today_cents' => (int) ($t['revenue_today'] ?? 0),
            'paid_count_today'    => (int) ($t['paid_count_today'] ?? 0),
            'total_orders'        => (int) ($t['total_orders'] ?? 0),
            'by_status'           => $byStatus,
        ];
    }

    /**
     * CA et nombre de commandes ENCAISSEES (paid|delivered, meme perimetre que
     * salesKpis) par JOUR sur les $days derniers jours (fenetre glissante incluant
     * aujourd'hui), pour le mini-graphe du tableau de bord. Zero-fill : chaque jour de
     * la fenetre est present meme sans vente (barre a 0) -> graphe lisible, largeur
     * stable. La liste des jours est ANCREE sur CURDATE() de la BASE (pas l'horloge
     * PHP) pour que le regroupement DATE(created_at) et le zero-fill partagent la meme
     * notion de "aujourd'hui" (un decalage de fuseau PHP/DB ferait sinon disparaitre
     * un jour). $days borne [1, 31] et interpole en ENTIER (INTERVAL n'accepte pas de
     * parametre lie avec ATTR_EMULATE_PREPARES=false ; le cast (int) ferme l'injection).
     *
     * @return list<array{day: string, orders: int, revenue_cents: int}>
     */
    public function salesByDay(int $days = 7): array
    {
        $days = max(1, min(31, $days));

        $rows = $this->db->fetchAll(
            "SELECT DATE(created_at) AS d, COUNT(*) AS orders, "
            . 'COALESCE(SUM(total_ttc_cents), 0) AS revenue '
            . 'FROM customer_order '
            . "WHERE status IN ('paid', 'preparing', 'ready', 'delivered') "
            . '  AND created_at >= (CURDATE() - INTERVAL ' . ($days - 1) . ' DAY) '
            . 'GROUP BY DATE(created_at)',
        );

        /** @var array<string, array{orders: int, revenue_cents: int}> $byDay */
        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) ($row['d'] ?? '')] = [
                'orders'        => (int) ($row['orders'] ?? 0),
                'revenue_cents' => (int) ($row['revenue'] ?? 0),
            ];
        }

        // Ancre la fenetre sur la date de la BASE (fallback horloge PHP si indisponible).
        $today = (string) ($this->db->fetch('SELECT CURDATE() AS d')['d'] ?? date('Y-m-d'));

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', (int) strtotime($today . ' -' . $i . ' day'));
            $series[] = [
                'day'           => $day,
                'orders'        => $byDay[$day]['orders'] ?? 0,
                'revenue_cents' => $byDay[$day]['revenue_cents'] ?? 0,
            ];
        }

        return $series;
    }
}
