<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\GuardResult;
use App\Core\Response;
use App\Order\OrderQueryRepository;

/**
 * Affichage cuisine (KDS, Kitchen Display System). GET /kitchen/display, permission
 * order.read. Landing par defaut du role kitchen (seed role.default_route). Lecture
 * SEULE : la file des commandes `paid` triee par paid_at croissant (la plus ancienne
 * d'abord, RG-T12), filtree par les sources visibles du role (role_visible_source :
 * kitchen voit tout ; counter voit kiosk+counter ; drive voit drive). Aucune
 * transition de statut ici (la remise se fait via OrderAdminController::deliver).
 *
 * Non `final` : les tests sous-classent (seam db()/orderQuery()).
 */
class KitchenController extends AdminController
{
    /**
     * @param array<string, string> $params
     */
    public function display(array $params = []): Response
    {
        $guard = $this->guard('order.read');
        if ($guard instanceof Response) {
            return $guard;
        }

        $sources = $this->orderQuery()->visibleSources($guard->roleId ?? 0);

        return $this->adminView('admin/kitchen/display', [
            'title'      => 'Cuisine - Wakdo Admin',
            'activeNav'  => 'kitchen',
            'orders'     => $this->orderQuery()->paidQueue($sources),
            'canDeliver' => $this->may($guard, 'order.deliver'),
        ], $guard);
    }

    protected function orderQuery(): OrderQueryRepository
    {
        return new OrderQueryRepository($this->db());
    }

    private function may(GuardResult $guard, string $permission): bool
    {
        return $this->authorizer()->can($guard->roleId ?? 0, $permission);
    }
}
