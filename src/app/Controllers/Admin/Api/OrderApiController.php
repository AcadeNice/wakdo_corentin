<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Api;

use App\Controllers\CounterOrderController;
use App\Core\Response;
use App\Order\OrderValidationException;

/**
 * Domaine commande en JSON (`/admin/api/orders`), memes permissions et memes
 * regles que `OrderAdminController` + `CounterOrderController` (HTML). PHP n'autorise
 * qu'un seul parent : ce controleur etend `CounterOrderController` (reutilise
 * `decodeItems()`, `messageFor()`, `orderQuery()`, `orders()`) ; la garde de
 * visibilite de source (PRE-3, RG-T12, `OrderAdminController::orderSource()`) est
 * reproduite ici en une requete equivalente (`orderDetail()`), le reste de
 * `OrderAdminController` (transitions deliver/ready/cancel) etant appele via les
 * memes repositories partages par les deux controleurs HTML.
 *
 *  - GET liste (`order.read`) : filtree par les sources visibles du role
 *    (`role_visible_source`, RG-T12), comme la file de preparation KDS ;
 *  - GET une commande (`order.read`) : `403` (jamais `404`) si elle est inconnue
 *    OU si sa source n'est pas visible -- anti-enumeration, meme principe que
 *    `OrderAdminController::deliver()` (HTML) applique ici a la lecture ;
 *  - POST creation comptoir/drive (`order.create`) : un seul endpoint JSON
 *    (contrairement aux deux pages HTML `/counter/orders`/`/drive/orders`) ; la
 *    source est IMPOSEE par `role.order_source` pour un role a canal fixe, sinon
 *    (admin/manager) elle DOIT etre choisie dans le corps (`source`), cf.
 *    `apiStore()` ;
 *  - POST ready/deliver (`order.read`/`order.deliver`) : NON PIN-gated (gestes
 *    routiniers), meme garde de visibilite PRE-3 (403 anti-enumeration) que le HTML ;
 *  - POST cancel (`order.cancel`) : meme garde de visibilite PRE-3 que le GET
 *    unitaire/ready/deliver (403 anti-enumeration, verifiee AVANT le PIN --
 *    corrige lors de la 2e relecture adverse, cf. `apiCancel()`), PUIS PIN
 *    equipier + audit (RG-T13/T14), audit ecrit par `OrderRepository::cancel()`
 *    lui-meme (pas de double-ecriture ici).
 *    LIMITE CONNUE (documentee en section 5.3 de conventions.md), COTE HTML
 *    UNIQUEMENT desormais : `OrderAdminController::cancel()` (HTML) ne verifie
 *    toujours pas cette visibilite de canal -- seule la permission
 *    `order.cancel` y est exigee. Non corrige cote HTML sur ce chantier pour ne
 *    pas introduire un comportement de securite nouveau et non revu sur un
 *    chemin de production existant ; cette API JSON, elle, ferme deja ce cas.
 *
 * Non `final` : les tests sous-classent pour injecter des doubles, meme convention
 * que le reste des controleurs admin.
 */
class OrderApiController extends CounterOrderController
{
    use JsonApiTrait;

    /**
     * @param array<string, string> $params
     */
    public function apiIndex(array $params = []): Response
    {
        $guard = $this->guardApi('order.read');
        if ($guard instanceof Response) {
            return $guard;
        }

        $visible = $this->orderQuery()->visibleSources($guard->roleId ?? 0);
        $rows = array_values(array_filter(
            $this->orderQuery()->recent(50),
            static fn (array $o): bool => in_array((string) ($o['source'] ?? ''), $visible, true),
        ));

        return $this->collectionResponse(array_map([$this, 'present'], $rows));
    }

    /**
     * Numero inconnu ET canal non visible renvoient tous deux `403 FORBIDDEN`
     * (jamais `404`) : distinguer les deux revelerait qu'une commande d'un autre
     * canal existe (enumeration). Meme principe que `OrderAdminController::deliver()`
     * (HTML), applique ici aussi a la lecture unitaire.
     *
     * @param array<string, string> $params
     */
    public function apiShow(array $params): Response
    {
        $guard = $this->guardApi('order.read');
        if ($guard instanceof Response) {
            return $guard;
        }

        $order = $this->orderDetail((string) ($params['number'] ?? ''));
        if ($order === null || !$this->sourceVisible($order['source'] ?? null, $guard->roleId ?? 0)) {
            return $this->errorResponse(403, 'FORBIDDEN', 'Commande introuvable ou hors des canaux visibles par votre rôle.');
        }

        return $this->okResponse($this->present($order));
    }

    /**
     * Saisie comptoir/drive (mlt 4.1, `order.create`), encaissee directement (comme
     * le HTML), sans PIN.
     *
     * Resolution de la source (section 5.3 de conventions.md) : un role a canal
     * FIXE (`counter`/`drive`, comme l'equipier qui utilise `/counter/orders` ou
     * `/drive/orders` en HTML) l'impose -- le champ `source` du corps est IGNORE
     * s'il est fourni (l'equipier de comptoir ne peut pas se faire passer pour le
     * drive). Un role SANS canal fixe (admin/manager, `role.order_source` NULL,
     * qui n'ont pas de page HTML dediee) DOIT choisir explicitement
     * `"source": "counter"` ou `"drive"` dans le corps -- sans quoi `422`.
     *
     * @param array<string, string> $params
     */
    public function apiStore(array $params = []): Response
    {
        $guard = $this->guardApi('order.create');
        if ($guard instanceof Response) {
            return $guard;
        }
        if (($csrf = $this->requireCsrf()) !== null) {
            return $csrf;
        }

        $body = $this->requireJsonBody();
        if ($body instanceof Response) {
            return $body;
        }

        $fixedSource = $this->roleFixedSource($guard->roleId ?? 0);
        if ($fixedSource !== null) {
            $source = $fixedSource;
        } else {
            $requested = $this->fieldString($body, 'source');
            if ($requested instanceof Response) {
                return $requested;
            }
            if ($requested !== 'counter' && $requested !== 'drive') {
                return $this->validationErrorResponse(['source' => 'Votre rôle n\'a pas de canal fixe : indiquez "source": "counter" ou "drive".']);
            }
            $source = $requested;
        }

        $serviceMode = $this->fieldString($body, 'service_mode');
        if ($serviceMode instanceof Response) {
            return $serviceMode;
        }
        $serviceTagRaw = $this->fieldString($body, 'service_tag');
        if ($serviceTagRaw instanceof Response) {
            return $serviceTagRaw;
        }
        $serviceTag = $serviceMode === 'dine_in' ? trim($serviceTagRaw) : '';

        $items = $this->fieldList($body, 'items');
        if ($items instanceof Response) {
            return $items;
        }
        $decoded = $this->decodeItems((string) json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($decoded === []) {
            return $this->validationErrorResponse(['items' => 'Ajoutez au moins un produit ou un menu.']);
        }

        $req = ['service_mode' => $serviceMode, 'items' => $decoded];
        if ($serviceTag !== '') {
            $req['service_tag'] = $serviceTag;
        }

        try {
            $order = $this->orders()->createStaffOrder($req, $guard->userId ?? 0, $source);
        } catch (OrderValidationException $exception) {
            return $this->validationErrorResponse(['items' => $this->messageFor($exception->getMessage())]);
        }

        return $this->createdResponse(
            array_merge($order, ['source' => $source]),
            '/admin/api/orders/' . $order['order_number'],
        );
    }

    /**
     * Etat de cuisine : paid|preparing -> ready (`order.read`), NON PIN-gated. Meme
     * garde PRE-3 (visibilite de source) que `OrderAdminController::ready()`.
     *
     * @param array<string, string> $params
     */
    public function apiReady(array $params): Response
    {
        $guard = $this->guardApi('order.read');
        if ($guard instanceof Response) {
            return $guard;
        }
        if (($csrf = $this->requireCsrf()) !== null) {
            return $csrf;
        }

        return $this->transition($params, $guard->roleId ?? 0, fn (string $number) => $this->orders()->markReady($number));
    }

    /**
     * Remise au client : paid -> delivered (`order.deliver`), NON PIN-gated. Meme
     * garde PRE-3 que `OrderAdminController::deliver()`.
     *
     * @param array<string, string> $params
     */
    public function apiDeliver(array $params): Response
    {
        $guard = $this->guardApi('order.deliver');
        if ($guard instanceof Response) {
            return $guard;
        }
        if (($csrf = $this->requireCsrf()) !== null) {
            return $csrf;
        }

        return $this->transition($params, $guard->roleId ?? 0, fn (string $number) => $this->orders()->deliver($number));
    }

    /**
     * Annulation (mlt 7.1, `order.cancel`) : PIN equipier + audit (RG-T13/T14),
     * meme flux que `OrderAdminController::cancel()`. L'audit est ecrit par
     * `OrderRepository::cancel()` lui-meme (pas de double-ecriture ici).
     *
     * @param array<string, string> $params
     */
    public function apiCancel(array $params): Response
    {
        $guard = $this->guardApi('order.cancel');
        if ($guard instanceof Response) {
            return $guard;
        }
        if (($csrf = $this->requireCsrf()) !== null) {
            return $csrf;
        }

        $body = $this->requireJsonBody();
        if ($body instanceof Response) {
            return $body;
        }

        $number = (string) ($params['number'] ?? '');
        // Numero inconnu ET canal non visible renvoient tous deux `403 FORBIDDEN`
        // (jamais `404`), AVANT toute verification de PIN : un acteur inconnu ne
        // doit pas pouvoir distinguer « la commande n'existe pas » de « la commande
        // existe mais mon role ne la voit pas », le meme principe que
        // `apiShow()`/`transition()` (relecture point 3, corrige la limite
        // documentee jusque-la dans conventions.md pour cette seule action).
        $order = $this->orderDetail($number);
        if ($order === null || !$this->sourceVisible($order['source'] ?? null, $guard->roleId ?? 0)) {
            return $this->errorResponse(403, 'FORBIDDEN', 'Commande introuvable ou hors des canaux visibles par votre rôle.');
        }

        [$email, $pin] = $this->pinFields($body);
        $actorSessionId = $guard->userId ?? 0;
        $actor = $this->pinGate()->resolve($actorSessionId, $email, $pin, 'customer_order', (int) ($order['id'] ?? 0));
        if ($actor === null) {
            return $this->pinErrorResponse('Email ou PIN invalide (requis pour annuler).');
        }

        try {
            $this->orders()->cancel($number, $actor['id'], $actor['role_id']);
        } catch (OrderValidationException $exception) {
            return match ($exception->getMessage()) {
                'ORDER_NOT_FOUND'        => $this->notFoundResponse(),
                'CANNOT_CANCEL_IN_STATE' => $this->errorResponse(422, 'CANNOT_CANCEL_IN_STATE', 'Annulation impossible : la commande est livrée ou déjà annulée.'),
                default                  => $this->errorResponse(409, 'INVALID_TRANSITION', 'Transition invalide : la commande a changé d\'état.'),
            };
        }

        $this->pinGate()->reset($actorSessionId);

        return $this->okResponse(['order_number' => $number, 'status' => 'cancelled']);
    }

    /**
     * Factorise ready()/deliver() : meme garde PRE-3 (source visible) que
     * `OrderAdminController` (HTML) -- numero inconnu ET canal non visible
     * renvoient tous deux `403 FORBIDDEN` (jamais `404`, anti-enumeration, cf.
     * `testDeliverUnknownOrderIsRejectedAsNotVisible`). $action est `markReady`
     * ou `deliver` sur `OrderRepository`.
     *
     * @param array<string, string> $params
     * @param callable(string): array<string, mixed> $action
     */
    private function transition(array $params, int $roleId, callable $action): Response
    {
        $number = (string) ($params['number'] ?? '');
        $order = $this->orderDetail($number);
        if ($order === null || !$this->sourceVisible($order['source'] ?? null, $roleId)) {
            return $this->errorResponse(403, 'FORBIDDEN', 'Commande introuvable ou hors des canaux visibles par votre rôle.');
        }

        try {
            $action($number);
        } catch (OrderValidationException $exception) {
            return $exception->getMessage() === 'ORDER_NOT_FOUND'
                ? $this->notFoundResponse()
                : $this->conflictResponse('Transition invalide pour cette commande.');
        }

        return $this->okResponse($this->present((array) $this->orderDetail($number)));
    }

    /**
     * Meme lecture ciblee qu'`OrderAdminController::orderSource()` (une seule
     * colonne, evite d'elargir `OrderRepository::findByNumber`), etendue aux champs
     * necessaires a la presentation JSON.
     *
     * @return array<string, mixed>|null
     */
    private function orderDetail(string $number): ?array
    {
        if ($number === '') {
            return null;
        }

        return $this->db()->fetch(
            'SELECT id, order_number, source, service_mode, service_tag, status, total_ttc_cents, created_at, paid_at '
            . 'FROM customer_order WHERE order_number = :n',
            ['n' => $number],
        );
    }

    private function sourceVisible(mixed $source, int $roleId): bool
    {
        return is_string($source) && $source !== '' && in_array($source, $this->orderQuery()->visibleSources($roleId), true);
    }

    /**
     * Canal FIXE du role agissant (`role.order_source`, meme champ que
     * `orderChannel` dans `AdminController::adminView()`) : `'counter'`/`'drive'`
     * pour un role de saisie dedie, `null` pour un role sans canal fixe
     * (admin/manager, cf. `db/seeds/0001_rbac_and_reference.sql` : "admin/manager
     * NULL"). `null` signifie que l'appelant doit RESOUDRE la source autrement
     * (corps JSON, section 5.3 de conventions.md) plutot que la deduire seule.
     * Meme projection de colonnes que `RoleRepository::findRole()` (pas un
     * nouveau contrat de lecture, juste la colonne utile de ce contrat existant).
     */
    private function roleFixedSource(int $roleId): ?string
    {
        $row = $this->db()->fetch(
            'SELECT id, code, label, description, default_route, order_source, is_active FROM role WHERE id = :id',
            ['id' => $roleId],
        );

        $source = $row['order_source'] ?? null;

        return ($source === 'counter' || $source === 'drive') ? $source : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'order_number'    => (string) ($row['order_number'] ?? ''),
            'source'          => (string) ($row['source'] ?? ''),
            'service_mode'    => ($row['service_mode'] ?? null) !== null ? (string) $row['service_mode'] : null,
            'service_tag'     => ($row['service_tag'] ?? null) !== null ? (string) $row['service_tag'] : null,
            'status'          => (string) ($row['status'] ?? ''),
            'total_ttc_cents' => (int) ($row['total_ttc_cents'] ?? 0),
            'created_at'      => ($row['created_at'] ?? null) !== null ? (string) $row['created_at'] : null,
            'paid_at'         => ($row['paid_at'] ?? null) !== null ? (string) $row['paid_at'] : null,
        ];
    }
}
