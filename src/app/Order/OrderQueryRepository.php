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
            'SELECT order_number, source, service_mode, service_tag, total_ttc_cents, paid_at '
            . 'FROM customer_order WHERE status = \'paid\' AND source IN (' . implode(', ', $placeholders) . ') '
            . 'ORDER BY paid_at ASC, id ASC',
            $params,
        );
    }

    /**
     * KPIs de vente : CA encaisse (statuts paid + delivered), nombre de commandes
     * encaissees, panier moyen, CA et nombre du JOUR, total de commandes, et la
     * repartition par statut. Le CA exclut les commandes pending_payment (non
     * encaissees) et cancelled.
     *
     * @return array{revenue_cents:int, paid_count:int, avg_basket_cents:int, revenue_today_cents:int, paid_count_today:int, total_orders:int, by_status:array<string,int>}
     */
    public function salesKpis(): array
    {
        $t = $this->db->fetch(
            "SELECT
                COALESCE(SUM(CASE WHEN status IN ('paid','delivered') THEN total_ttc_cents ELSE 0 END), 0) AS revenue,
                COALESCE(SUM(status IN ('paid','delivered')), 0) AS paid_count,
                COALESCE(SUM(CASE WHEN status IN ('paid','delivered') AND created_at >= CURDATE() THEN total_ttc_cents ELSE 0 END), 0) AS revenue_today,
                COALESCE(SUM(status IN ('paid','delivered') AND created_at >= CURDATE()), 0) AS paid_count_today,
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
}
