<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Csrf;
use App\Auth\GuardResult;
use App\Auth\PasswordHasher;
use App\Auth\PinThrottle;
use App\Auth\PinVerifier;
use App\Catalogue\MenuRepository;
use App\Catalogue\ProductRepository;
use App\Core\DatabaseInterface;
use App\Core\Response;
use App\Order\OrderQueryRepository;
use App\Order\OrderRepository;
use App\Order\OrderValidationException;

/**
 * Domaine commande back-office (P4 + P3 operationnel). GET /admin/orders : liste
 * recente (order.read). POST /admin/orders/{number}/deliver : transition paid ->
 * delivered (DELIVER_ORDER, geste unique, order.deliver), NON PIN-gated.
 * GET/POST /admin/orders/{number}/cancel : annulation (CANCEL_ORDER, mlt 7.1,
 * order.cancel) avec PIN equipier + audit + restock conditionnel (RG-T13/T14). La
 * file cuisine (KitchenController) est traitee ailleurs.
 *
 * Non `final` : les tests sous-classent (seam db()/orderQuery()/orders()/pin*()).
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
            // RG-T03 : adapte l'affichage (bouton Annuler) sans remplacer la garde
            // par-action de cancel(). manager n'a PAS order.cancel (decision D5).
            'canCancel'  => $this->may($guard, 'order.cancel'),
        ], $guard);
    }

    /**
     * Remise au client : paid -> delivered (mlt 6.1). POST + CSRF, garde order.deliver.
     * Pas de PIN (geste routinier). Issue affichee en flash, retour a la liste.
     *
     * PRE-3 / ERR-2 (6.1) : au-dela de la permission, la SOURCE de la commande doit
     * etre VISIBLE par le role de l'acteur (role_visible_source). Sans ce controle,
     * tout role detenant order.deliver pourrait remettre une commande de n'importe
     * quel canal (ex. un equipier drive remettant une commande comptoir). La file
     * KDS (KitchenController) est deja filtree par visibleSources a l'affichage ;
     * cette garde rejoue la regle cote ecriture (defense en profondeur, meme posture
     * que cancel() qui ne se fie pas a l'affichage de la liste). Hors des sources
     * visibles -> 403 FORBIDDEN (memes statut/vue que la garde de permission).
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

        $number = (string) ($params['number'] ?? '');

        // PRE-3 (6.1) : refuse la remise d'une commande dont la source n'est pas dans
        // les sources visibles du role agissant. visibleSources reutilise
        // role_visible_source (memes donnees que KitchenController) : liste vide en base
        // = vue globale (admin/manager voient les trois sources). Le numero inconnu
        // (source null) retombe sur le chemin "non visible" -> 403 ; la branche
        // ORDER_NOT_FOUND ci-dessous reste atteignable pour une course (commande
        // supprimee entre la lecture et la transition).
        $source = $this->orderSource($number);
        if ($source === null || !in_array($source, $this->orderQuery()->visibleSources($guard->roleId ?? 0), true)) {
            return $this->forbidden($guard);
        }

        try {
            $this->orders()->deliver($number);
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

    /**
     * Etat de cuisine : paid -> preparing (retour oral #8). POST + CSRF, garde
     * order.read (KDS), meme controle de source-visibilite (PRE-3) que deliver.
     *
     * @param array<string, string> $params
     */
    public function preparing(array $params = []): Response
    {
        return $this->advancePrep($params, 'preparing');
    }

    /**
     * Etat de cuisine : paid|preparing -> ready (retour oral #8). POST + CSRF, garde
     * order.read, meme controle de source-visibilite que deliver.
     *
     * @param array<string, string> $params
     */
    public function ready(array $params = []): Response
    {
        return $this->advancePrep($params, 'ready');
    }

    /**
     * Tronc commun des transitions de preparation du KDS (preparing / ready) : garde
     * order.read + CSRF + source visible par le role (PRE-3, defense en profondeur
     * comme deliver/cancel : on ne se fie pas a l'affichage de la file), puis
     * markPreparing/markReady. Issue affichee en flash, retour au KDS (/kitchen/display,
     * l'ecran d'ou part le geste).
     *
     * @param array<string, string> $params
     */
    private function advancePrep(array $params, string $target): Response
    {
        $guard = $this->guard('order.read');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $number = (string) ($params['number'] ?? '');

        $source = $this->orderSource($number);
        if ($source === null || !in_array($source, $this->orderQuery()->visibleSources($guard->roleId ?? 0), true)) {
            return $this->forbidden($guard);
        }

        try {
            if ($target === 'preparing') {
                $this->orders()->markPreparing($number);
                $this->setFlash('Commande passee en preparation.');
            } else {
                $this->orders()->markReady($number);
                $this->setFlash('Commande marquee prete.');
            }
        } catch (OrderValidationException $exception) {
            $this->setFlash(
                $exception->getMessage() === 'ORDER_NOT_FOUND'
                    ? 'Commande introuvable.'
                    : 'Transition invalide pour cette commande.',
            );
        }

        return $this->redirect('/kitchen/display');
    }

    /**
     * Page de confirmation d'annulation (CANCEL_ORDER, mlt 7.1). Garde order.cancel.
     * Affiche numero/statut/total + le formulaire PIN equipier (modele RG-T13). La
     * commande est chargee en lecture seule (OrderRepository::findByNumber) ; statut
     * terminal (delivered/cancelled) -> message bloquant, pas de formulaire.
     *
     * @param array<string, string> $params
     */
    public function confirmCancel(array $params): Response
    {
        $guard = $this->guard('order.cancel');
        if ($guard instanceof Response) {
            return $guard;
        }

        $order = $this->orders()->findByNumber((string) ($params['number'] ?? ''));
        if ($order === null) {
            return $this->notFound($guard);
        }

        return $this->renderCancel($guard, $order, null);
    }

    /**
     * Annulation effective (CANCEL_ORDER, mlt 7.1). POST + CSRF + garde order.cancel,
     * puis flux PIN equipier IDENTIQUE a IngredientController::inventory (RG-T13/T22) :
     * verrou throttle par utilisateur AGISSANT evalue AVANT la verification (leurre de
     * timing, message generique) ; sur echec PIN -> pin.failed + increment throttle dans
     * UNE transaction. Sur PIN OK -> OrderRepository::cancel (transition + restock
     * conditionnel + audit dans sa propre transaction), reset du throttle, flash.
     *
     * @param array<string, string> $params
     */
    public function cancel(array $params): Response
    {
        $guard = $this->guard('order.cancel');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $number = (string) ($params['number'] ?? '');
        $order = $this->orders()->findByNumber($number);
        if ($order === null) {
            return $this->notFound($guard);
        }

        // RG-T22 : verrou du throttle par utilisateur AGISSANT (session), evalue AVANT
        // la verification ; sous verrou, leurre de timing + message generique, pas de
        // nouvelle ligne pin.failed (les echecs ayant arme le verrou sont deja audites).
        $actorId = $guard->userId ?? 0;
        if ($actorId > 0 && $this->pinThrottle()->isLocked($actorId)) {
            $this->pinVerifier()->payTimingDecoy($form['pin'] ?? '');

            return $this->renderCancel($guard, $order, 'Email ou PIN invalide (requis pour annuler).', 422);
        }

        $actor = $this->pinVerifier()->resolveActingUser(trim($form['pin_email'] ?? ''), $form['pin'] ?? '');
        if ($actor === null) {
            // RG-T08 : trace pin.failed (RG-T14) + increment throttle (RG-T22) dans UNE
            // transaction. pin.failed est un evenement securite (pas l'effet metier).
            $email = trim($form['pin_email'] ?? '');
            $entityId = (int) ($order['id'] ?? 0);
            $this->db()->transaction(function (DatabaseInterface $db) use ($email, $entityId, $actorId): void {
                $this->logFailedPin($db, $email, $entityId);
                $this->pinThrottle()->recordFailureWithin($db, $actorId);
            });

            return $this->renderCancel($guard, $order, 'Email ou PIN invalide (requis pour annuler).', 422);
        }

        try {
            $this->orders()->cancel($number, (int) $actor['id'], (int) $actor['role_id']);
            // PIN valide : reinitialise le compteur de l'acteur de SESSION (RG-T22, cle
            // = $actorId), surtout pas $actor['id'] (l'equipier resolu par le PIN).
            $this->pinThrottle()->reset($actorId);
            $this->setFlash('Commande annulee.');
        } catch (OrderValidationException $exception) {
            $this->setFlash(match ($exception->getMessage()) {
                'ORDER_NOT_FOUND'         => 'Commande introuvable.',
                'CANNOT_CANCEL_IN_STATE'  => 'Annulation impossible : la commande est livree ou deja annulee.',
                default                   => 'Transition invalide : la commande a change d\'etat.',
            });
        }

        return $this->redirect('/admin/orders');
    }

    protected function orderQuery(): OrderQueryRepository
    {
        return new OrderQueryRepository($this->db());
    }

    /**
     * Source (canal) d'une commande par son numero, pour la garde de visibilite
     * PRE-3 (6.1). Lecture ciblee d'une seule colonne : OrderRepository::findByNumber
     * renvoie une forme typee {id, order_number, total_ttc_cents, status} qui n'expose
     * pas la source ; plutot que d'elargir ce contrat partage, on lit ici le seul champ
     * requis (meme couture db() que logFailedPin). Renvoie null si le numero est
     * inconnu (traite comme non visible par l'appelant).
     */
    protected function orderSource(string $number): ?string
    {
        if ($number === '') {
            return null;
        }
        $row = $this->db()->fetch(
            'SELECT source FROM customer_order WHERE order_number = :n',
            ['n' => $number],
        );
        $source = is_string($row['source'] ?? null) ? (string) $row['source'] : '';

        return $source === '' ? null : $source;
    }

    protected function orders(): OrderRepository
    {
        $db = $this->db();

        return new OrderRepository($db, new ProductRepository($db), new MenuRepository($db));
    }

    protected function pinVerifier(): PinVerifier
    {
        return new PinVerifier($this->db(), $this->config, $this->passwordHasher());
    }

    protected function pinThrottle(): PinThrottle
    {
        return new PinThrottle($this->db(), $this->config);
    }

    protected function passwordHasher(): PasswordHasher
    {
        return new PasswordHasher($this->config);
    }

    /**
     * RG-T03 : la permission est-elle detenue par le role de la session courante ?
     * Sert a adapter l'affichage (bouton Annuler) sans remplacer la garde par-action.
     */
    private function may(GuardResult $guard, string $permission): bool
    {
        return $guard->roleId !== null && $this->authorizer()->can($guard->roleId, $permission);
    }

    /**
     * Trace une tentative de PIN echouee sur l'annulation (RG-T14) : rend le
     * brute-force d'attribution detectable. Acteur inconnu (PIN non resolu).
     */
    private function logFailedPin(DatabaseInterface $db, string $email, int $orderId): void
    {
        $db->execute(
            'INSERT INTO audit_log (actor_user_id, actor_role_id, action_code, entity_type, entity_id, summary) '
            . 'VALUES (:uid, :rid, :code, :etype, :eid, :summary)',
            [
                'uid'     => null,
                'rid'     => null,
                'code'    => 'pin.failed',
                'etype'   => 'customer_order',
                'eid'     => $orderId,
                'summary' => 'Echec PIN annulation (email tente: ' . $email . ')',
            ],
        );
    }

    /**
     * @param array{id:int, order_number:string, total_ttc_cents:int, status:string} $order
     */
    private function renderCancel(GuardResult $guard, array $order, ?string $error, int $status = 200): Response
    {
        return $this->adminView('admin/orders/cancel', [
            'title'     => 'Annuler une commande - Wakdo Admin',
            'activeNav' => 'orders',
            'order'     => $order,
            'error'     => $error,
        ], $guard, $status);
    }

    private function notFound(GuardResult $guard): Response
    {
        return $this->adminView('admin/not_found', ['title' => 'Introuvable', 'activeNav' => 'orders'], $guard, 404);
    }

    /**
     * Refus de visibilite (ERR-2, 6.1) : la source de la commande n'est pas visible
     * par le role agissant. Memes statut (403) et vue (admin/forbidden) que la garde
     * de permission d'AdminController::guard, pour une convention de refus homogene.
     */
    private function forbidden(GuardResult $guard): Response
    {
        return $this->adminView('admin/forbidden', ['title' => 'Acces refuse', 'activeNav' => 'orders'], $guard, 403);
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
