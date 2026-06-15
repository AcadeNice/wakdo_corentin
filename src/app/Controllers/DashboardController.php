<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;

/**
 * Tableau de bord back-office. GET /admin/dashboard (landing par defaut du role
 * admin, cf. seed role.default_route). Accessible a tout utilisateur authentifie ;
 * les KPI reels (stats.read) seront ajoutes au chunk statistiques.
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

        return $this->adminView(
            'admin/dashboard',
            ['title' => 'Tableau de bord - Wakdo Admin', 'activeNav' => 'dashboard'],
            $guard,
        );
    }
}
