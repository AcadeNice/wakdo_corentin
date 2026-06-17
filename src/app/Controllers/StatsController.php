<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Catalogue\StatsRepository;
use App\Core\Response;

/**
 * Tableau de bord statistiques (mlt domaine 11). GET /admin/stats, permission
 * stats.read (landing par defaut du role manager, cf. seed role.default_route).
 * En P3, les KPIs portent sur les donnees disponibles : compteurs de catalogue
 * et sante du stock (RG-T21). Les KPIs de vente (CA, volumes) viendront avec le
 * domaine commande (P4).
 *
 * Non `final` : les tests sous-classent (seam db()/statsRepository()).
 */
class StatsController extends AdminController
{
    /**
     * @param array<string, string> $params
     */
    public function index(array $params = []): Response
    {
        $guard = $this->guard('stats.read');
        if ($guard instanceof Response) {
            return $guard;
        }

        return $this->adminView('admin/stats/index', [
            'title'     => 'Statistiques - Wakdo Admin',
            'activeNav' => 'stats',
            'counts'    => $this->statsRepository()->counts(),
            'stock'     => $this->statsRepository()->stockHealth(),
        ], $guard);
    }

    protected function statsRepository(): StatsRepository
    {
        return new StatsRepository($this->db());
    }
}
