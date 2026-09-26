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
 *  - POST /api/orders/{number}/pay  : encaissement -> preparing (part en cuisine sans
 *    geste manuel supplementaire ; paid_at + preparing_at poses ensemble, voir
 *    OrderRepository::pay) + decrement stock (RG-T20).
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
     * RESTREINT AU CANAL KIOSK (relecture adverse, point 5b) : cet endpoint est
     * PUBLIC, sans session, et les numeros sont SEQUENTIELS (prefixe canal + id
     * auto-incremente) -- avant ce correctif, `findByNumber()` ne filtrait pas par
     * source : n'importe qui pouvait deviner un numero comptoir/drive voisin
     * ("K102" existe -> "C102"/"D102" probablement aussi) et en lire le statut ET
     * le total, alors que ces commandes ne sont PAS anonymes par nature (saisies par
     * un equipier identifie). Une commande d'un AUTRE canal rend la MEME reponse 404
     * qu'un numero inconnu (anti-enumeration : ne pas reveler qu'une commande existe
     * hors kiosk).
     *
     * @param array<string, string> $params
     */
    public function show(array $params = []): Response
    {
        $order = $this->orders()->findByNumber((string) ($params['number'] ?? ''));
        if ($order === null || ($order['source'] ?? '') !== 'kiosk') {
            return $this->json(
                ['data' => null, 'error' => ['code' => 'ORDER_NOT_FOUND', 'message' => $this->messageFor('ORDER_NOT_FOUND')]],
                404,
            );
        }

        return $this->json(['data' => $this->presentPublicStatus($order)]);
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

    /**
     * Presentation du suivi PUBLIC (show(), restreint au canal kiosk) : uniquement
     * `order_number` + `status`. Ni `id` (technique, sans usage cote client) ni
     * `total_ttc_cents` (relecture adverse, point 5b) : aucun ecran borne ne
     * consomme ce champ sur cet endpoint aujourd'hui (verifie -- `checkout.js` ne
     * fait que POSTer `/api/orders` puis `/api/orders/{number}/pay` ; ni
     * `page-confirmation.js` ni `confirm-modal.js` n'appellent `GET
     * /api/orders/{number}`), donc pas de raison de l'exposer "au cas ou" sur un
     * endpoint anonyme. `present()` ci-dessus reste utilise par `create()`/`pay()`,
     * qui EUX ont besoin du total pour l'affichage de paiement.
     *
     * @param array{order_number:string, status:string} $order
     * @return array{order_number:string, status:string}
     */
    private function presentPublicStatus(array $order): array
    {
        return [
            'order_number' => $order['order_number'],
            'status'       => $order['status'],
        ];
    }

    private function orderError(OrderValidationException $exception): Response
    {
        $code = $exception->getMessage();
        $status = match ($code) {
            'ORDER_NOT_FOUND'    => 404,
            // Conflit d'etat : la commande visee n'est pas dans un etat qui accepte
            // l'operation. ORDER_CANCELLED est le cas particulier ou la cle d'idempotence
            // du client porte une commande annulee ou expiree (F18) : la colonne etant
            // UNIQUE, la cle est definitivement consommee et la borne doit en prendre
            // une neuve. Un 422 ferait croire a une charge utile mal formee.
            'INVALID_TRANSITION' => 409,
            'ORDER_CANCELLED'    => 409,
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
            'ORDER_CANCELLED'          => 'Cette commande a été annulée : recommencez une nouvelle commande.',
            'EMPTY_ORDER'              => 'La commande est vide.',
            'INVALID_SERVICE_MODE'     => 'Mode de service invalide.',
            'INVALID_SERVICE_TAG'      => 'Numéro de chevalet invalide.',
            'INVALID_ITEM_TYPE'        => 'Type d\'article invalide.',
            'PRODUCT_UNAVAILABLE'      => 'Produit indisponible.',
            'MENU_UNAVAILABLE'         => 'Menu indisponible.',
            'INVALID_SELECTION'        => 'Choix invalide pour ce menu.',
            'INVALID_MODIFIER'         => 'Modification d\'ingrédient invalide.',
            'INGREDIENT_NOT_REMOVABLE' => 'Cet ingrédient ne peut pas être retiré.',
            'INGREDIENT_NOT_ADDABLE'   => 'Cet ingrédient ne peut pas être ajouté.',
            default                    => 'Requête invalide.',
        };
    }
}
