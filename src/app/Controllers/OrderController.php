<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Catalogue\MenuRepository;
use App\Catalogue\ProductRepository;
use App\Core\Controller;
use App\Core\DatabaseInterface;
use App\Core\Response;
use App\Order\OrderRepository;
use App\Order\OrderValidationException;

/**
 * API publique de commande borne (P4, domaine 7). Anonyme : la borne kiosk poste
 * sans session ; l'idempotence (RG-T19, idempotency_key) tient lieu de garde-fou
 * anti double-clic / retry reseau. Deux operations :
 *  - POST /api/orders               : creation en pending_payment (RG-5 etapes 1-4) ;
 *  - POST /api/orders/{number}/pay  : encaissement -> paid + decrement stock (RG-T20).
 *
 * Les erreurs metier (OrderValidationException) sont mappees par code :
 * ORDER_NOT_FOUND -> 404, INVALID_TRANSITION -> 409, le reste (reference /
 * disponibilite / selection / modificateur) -> 422. Enveloppe standard
 * {data} / {data:null, error:{code, message}}.
 *
 * Non `final` a dessein : les tests sous-classent pour injecter un acces BDD double
 * (FakeOrderDatabase) via le hook protege db().
 */
class OrderController extends Controller
{
    /**
     * @param array<string, string> $params
     */
    public function create(array $params = []): Response
    {
        try {
            $order = $this->orders()->createPending($this->request->json());
        } catch (OrderValidationException $exception) {
            return $this->orderError($exception);
        }

        return $this->json(['data' => $this->present($order)], 201);
    }

    /**
     * @param array<string, string> $params
     */
    public function pay(array $params = []): Response
    {
        try {
            $order = $this->orders()->pay((string) ($params['number'] ?? ''));
        } catch (OrderValidationException $exception) {
            return $this->orderError($exception);
        }

        return $this->json(['data' => $this->present($order)]);
    }

    /**
     * Lecture publique du statut d'une commande par son numero (suivi borne apres
     * encaissement). Anonyme, lecture seule ; 404 si le numero est inconnu.
     *
     * @param array<string, string> $params
     */
    public function show(array $params = []): Response
    {
        $order = $this->orders()->findByNumber((string) ($params['number'] ?? ''));
        if ($order === null) {
            return $this->json(
                ['data' => null, 'error' => ['code' => 'ORDER_NOT_FOUND', 'message' => $this->messageFor('ORDER_NOT_FOUND')]],
                404,
            );
        }

        return $this->json(['data' => $this->present($order)]);
    }

    /**
     * Fabrique le repository de commande sur l'acces BDD courant. Hook de test
     * (sous-classe -> double) : redefinir db() suffit a injecter une base factice.
     */
    protected function orders(): OrderRepository
    {
        $db = $this->db();

        return new OrderRepository($db, new ProductRepository($db), new MenuRepository($db));
    }

    /**
     * Acces BDD comme DatabaseInterface (seam de test). Database l'implemente.
     */
    protected function db(): DatabaseInterface
    {
        return $this->database;
    }

    /**
     * @param array{id:int, order_number:string, total_ttc_cents:int, status:string} $order
     * @return array{id:int, order_number:string, status:string, total_ttc_cents:int}
     */
    private function present(array $order): array
    {
        return [
            'id'              => $order['id'],
            'order_number'    => $order['order_number'],
            'status'          => $order['status'],
            'total_ttc_cents' => $order['total_ttc_cents'],
        ];
    }

    private function orderError(OrderValidationException $exception): Response
    {
        $code = $exception->getMessage();
        $status = match ($code) {
            'ORDER_NOT_FOUND'    => 404,
            'INVALID_TRANSITION' => 409,
            default              => 422,
        };

        return $this->json(
            ['data' => null, 'error' => ['code' => $code, 'message' => $this->messageFor($code)]],
            $status,
        );
    }

    /**
     * Message lisible par code metier. Reste cote serveur : la borne affiche un
     * libelle generique, ce texte sert au diagnostic / aux logs.
     */
    private function messageFor(string $code): string
    {
        return match ($code) {
            'ORDER_NOT_FOUND'          => 'Commande introuvable.',
            'INVALID_TRANSITION'       => 'Transition de statut invalide.',
            'EMPTY_ORDER'              => 'La commande est vide.',
            'INVALID_SERVICE_MODE'     => 'Mode de service invalide.',
            'INVALID_SERVICE_TAG'      => 'Numero de chevalet invalide.',
            'INVALID_ITEM_TYPE'        => 'Type d\'article invalide.',
            'PRODUCT_UNAVAILABLE'      => 'Produit indisponible.',
            'MENU_UNAVAILABLE'         => 'Menu indisponible.',
            'INVALID_SELECTION'        => 'Choix invalide pour ce menu.',
            'INVALID_MODIFIER'         => 'Modification d\'ingredient invalide.',
            'INGREDIENT_NOT_REMOVABLE' => 'Cet ingredient ne peut pas etre retire.',
            'INGREDIENT_NOT_ADDABLE'   => 'Cet ingredient ne peut pas etre ajoute.',
            default                    => 'Requete invalide.',
        };
    }
}
