<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Core\DatabaseInterface;

/**
 * Agregats de pilotage du back-office (tableau de bord, permission stats.read).
 * KPIs sur les donnees DISPONIBLES en P3 : compteurs de catalogue et sante du
 * stock (bandes RG-T21). Les KPIs de vente (CA, volumes) dependent du domaine
 * commande (P4) et sont hors perimetre ici.
 *
 * Non `final` : les tests sous-classent pour stubber les agregats sans base.
 */
class StatsRepository
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Compteurs de catalogue : total + sous-ensemble actif/disponible par entite.
     * SUM(bool) compte les lignes verifiant le predicat (COALESCE pour table vide).
     * Chaque entree porte 'total' + la cle de sous-ensemble ('available' pour
     * product/menu, 'active' pour category/ingredient).
     *
     * @return array<string, array<string, int>>
     */
    public function counts(): array
    {
        return [
            'products'    => $this->pair('SELECT COUNT(*) AS total, COALESCE(SUM(is_available = 1), 0) AS n FROM product', 'available'),
            'categories'  => $this->pair('SELECT COUNT(*) AS total, COALESCE(SUM(is_active = 1), 0) AS n FROM category', 'active'),
            'menus'       => $this->pair('SELECT COUNT(*) AS total, COALESCE(SUM(is_available = 1), 0) AS n FROM menu', 'available'),
            'ingredients' => $this->pair('SELECT COUNT(*) AS total, COALESCE(SUM(is_active = 1), 0) AS n FROM ingredient', 'active'),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function pair(string $sql, string $key): array
    {
        $row = $this->db->fetch($sql) ?? [];

        return ['total' => (int) ($row['total'] ?? 0), $key => (int) ($row['n'] ?? 0)];
    }

    /**
     * Sante du stock : repartition des ingredients ACTIFS par bande (RG-T21, via
     * IngredientRepository::stockBand = source unique de la derivation) + liste
     * d'alerte (bandes low/critical), triee du plus critique au moins critique.
     *
     * @return array{active_total:int, bands:array{normal:int,low:int,critical:int}, alerts:list<array{name:string,stock_pct:int,stock_band:string}>}
     */
    public function stockHealth(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT name, stock_quantity, stock_capacity, low_stock_pct, critical_stock_pct '
            . 'FROM ingredient WHERE is_active = 1 ORDER BY name',
        );

        $bands = ['normal' => 0, 'low' => 0, 'critical' => 0];
        $alerts = [];
        foreach ($rows as $r) {
            $qty = (int) ($r['stock_quantity'] ?? 0);
            $cap = (int) ($r['stock_capacity'] ?? 0);
            $band = IngredientRepository::stockBand(
                $qty,
                $cap,
                (int) ($r['low_stock_pct'] ?? 0),
                (int) ($r['critical_stock_pct'] ?? 0),
            );
            $bands[$band]++;
            if ($band !== 'normal') {
                $alerts[] = [
                    'name'       => (string) ($r['name'] ?? ''),
                    'stock_pct'  => IngredientRepository::stockPct($qty, $cap),
                    'stock_band' => $band,
                ];
            }
        }

        // Plus critique (pourcentage le plus bas) en tete.
        usort($alerts, static fn (array $a, array $b): int => $a['stock_pct'] <=> $b['stock_pct']);

        return ['active_total' => count($rows), 'bands' => $bands, 'alerts' => $alerts];
    }
}
