<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Catalogue\StatsRepository;
use App\Core\Response;
use App\Order\OrderQueryRepository;

/**
 * Tableau de bord back-office. GET /admin/dashboard (landing par defaut du role
 * admin, cf. seed role.default_route). Accessible a tout utilisateur authentifie.
 * Affiche des indicateurs synthetiques (catalogue + sante stock) ; le detail vit
 * sous /admin/stats (permission stats.read).
 *
 * Non `final` : les tests sous-classent pour injecter des doubles via les hooks.
 */
class DashboardController extends AdminController
{
    /**
     * @param array<string, string> $params
     */
    public function index(array $params = []): Response
    {
        $guard = $this->guard();
        if ($guard instanceof Response) {
            return $guard;
        }

        $stats = $this->statsRepository();

        $data = [
            'title'     => 'Tableau de bord - Wakdo Admin',
            'activeNav' => 'dashboard',
            'counts'    => $stats->counts(),
            'stock'     => $stats->stockHealth(),
        ];

        // KPIs de vente + mini-graphe : le CA encaisse n'est expose qu'aux roles
        // habilites stats.read (un equipier ne voit que catalogue + sante stock).
        // Le tableau de bord lui-meme reste accessible a tout utilisateur authentifie ;
        // on enrichit seulement la donnee selon la permission (pas une garde de page).
        if ($this->authorizer()->can((int) $guard->roleId, 'stats.read')) {
            $orders = $this->orderQuery();
            $data['sales'] = $orders->salesKpis();
            $data['salesByDay'] = $orders->salesByDay(7);
        }

        return $this->adminView('admin/dashboard', $data, $guard);
    }

    protected function statsRepository(): StatsRepository
    {
        return new StatsRepository($this->db());
    }

    protected function orderQuery(): OrderQueryRepository
    {
        return new OrderQueryRepository($this->db());
    }
}
