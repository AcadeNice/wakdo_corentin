<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Csrf;
use App\Catalogue\MenuRepository;
use App\Catalogue\ProductRepository;
use App\Core\Response;
use App\Order\OrderQueryRepository;
use App\Order\OrderRepository;
use App\Order\OrderValidationException;

/**
 * Domaine commande back-office (P4 + P3 operationnel). GET /admin/orders : liste
 * recente (order.read). POST /admin/orders/{number}/deliver : transition paid ->
 * delivered (DELIVER_ORDER, geste unique, order.deliver), NON PIN-gated. L'annulation
 * (CANCEL_ORDER, PIN + restock) et la file cuisine (KitchenController) sont traitees
 * ailleurs.
 *
 * Non `final` : les tests sous-classent (seam db()/orderQuery()/orders()).
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
            'title'      => 'Commandes - Wakdo Admin',
            'activeNav'  => 'orders',
            'orders'     => $this->orderQuery()->recent(50),
        ], $guard);
    }

    /**
     * Remise au client : paid -> delivered (mlt 6.1). POST + CSRF, garde order.deliver.
     * Pas de PIN (geste routinier). Issue affichee en flash, retour a la liste.
     *
     * @param array<string, string> $params
     */
    public function deliver(array $params = []): Response
    {
        $guard = $this->guard('order.deliver');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        try {
            $this->orders()->deliver((string) ($params['number'] ?? ''));
            $this->setFlash('Commande remise (livree).');
        } catch (OrderValidationException $exception) {
            $this->setFlash(
                $exception->getMessage() === 'ORDER_NOT_FOUND'
                    ? 'Commande introuvable.'
                    : 'Transition invalide : la commande n\'est pas au statut paye.',
            );
        }

        return $this->redirect('/admin/orders');
    }

    protected function orderQuery(): OrderQueryRepository
    {
        return new OrderQueryRepository($this->db());
    }

    protected function orders(): OrderRepository
    {
        $db = $this->db();

        return new OrderRepository($db, new ProductRepository($db), new MenuRepository($db));
    }

    private function redirect(string $location): Response
    {
        return Response::make('', 302, ['Location' => $location]);
    }

    private function invalidCsrf(): Response
    {
        return Response::make('Requete invalide.', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
