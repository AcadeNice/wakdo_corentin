<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Order\OrderQueryRepository;

/**
 * Liste des commandes back-office (P4, domaine 7). GET /admin/orders, permission
 * order.read. Lecture seule : pas de transition de statut ici (deliver/cancel =
 * ecrans operationnels kitchen/counter, hors back-office MVP).
 *
 * Non `final` : les tests sous-classent (seam db()/orderQuery()).
 */
class OrderAdminController extends AdminController
{
    /**
     * @param array<string, string> $params
     */
    public function index(array $params = []): Response
    {
        $guard = $this->guard('order.read');
        if ($guard instanceof Response) {
            return $guard;
        }

        return $this->adminView('admin/orders/index', [
            'title'     => 'Commandes - Wakdo Admin',
            'activeNav' => 'orders',
            'orders'    => $this->orderQuery()->recent(50),
        ], $guard);
    }

    protected function orderQuery(): OrderQueryRepository
    {
        return new OrderQueryRepository($this->db());
    }
}
