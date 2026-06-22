<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Csrf;
use App\Auth\GuardResult;
use App\Catalogue\MenuRepository;
use App\Catalogue\ProductRepository;
use App\Core\DatabaseInterface;
use App\Core\Response;
use App\Order\OrderQueryRepository;
use App\Order\OrderRepository;
use App\Order\OrderValidationException;

/**
 * Saisie de commande comptoir / drive en back-office (CREATE_COUNTER_ORDER, mlt 4.1).
 * UN seul controleur sert les DEUX canaux : la `source` est derivee du CHEMIN de la
 * requete (un chemin commencant par '/drive' -> 'drive', sinon 'counter'). Ce choix
 * evite un controleur par canal alors que la logique est identique ; seules la source
 * auto-tagguee, le titre et les liens d'action changent. Le decoupage par chemin (et
 * non par parametre de route) garantit que counter et drive restent etanches : un
 * equipier drive ne peut pas creer une commande comptoir en falsifiant un champ.
 *
 * Version PRODUITS uniquement (sous-lot 3a) : les menus composes (slots) viendront
 * dans un sous-lot ulterieur. La commande est creee directement `paid` (encaissement
 * immediat, RG-5/POST-1) sans PIN : la permission order.create suffit.
 *
 * Non `final` : les tests sous-classent pour injecter des doubles (db/orderQuery/orders).
 */
class CounterOrderController extends AdminController
{
    /**
     * Liste des commandes recentes du canal courant + lien "Nouvelle commande".
     * Corrige le 404 des landings /counter/orders et /drive/orders (role.default_route).
     *
     * @param array<string, string> $params
     */
    public function index(array $params = []): Response
    {
        $guard = $this->guard('order.create');
        if ($guard instanceof Response) {
            return $guard;
        }

        $source = $this->source();

        // RG-1 (5.1, source filter) : ne lister que les commandes du canal. recent()
        // ramene les plus recentes tous canaux ; on filtre sur la source derivee du
        // chemin pour que le comptoir ne voie pas le drive et inversement.
        $orders = array_values(array_filter(
            $this->orderQuery()->recent(50),
            static fn (array $o): bool => (string) ($o['source'] ?? '') === $source,
        ));

        return $this->channelView('admin/counter/index', $source, [
            'title'  => $this->channelTitle($source) . ' - Wakdo Admin',
            'orders' => $orders,
        ], $guard);
    }

    /**
     * Composeur de commande (GET .../new) : produits commandables + select service_mode.
     *
     * @param array<string, string> $params
     */
    public function create(array $params = []): Response
    {
        $guard = $this->guard('order.create');
        if ($guard instanceof Response) {
            return $guard;
        }

        $source = $this->source();

        return $this->renderForm($guard, $source, [], null);
    }

    /**
     * Soumission de la commande (POST). Construit le panier depuis les quantites
     * saisies, encaisse via createStaffOrder (source derivee du chemin, acteur =
     * equipier authentifie). Panier vide / RG-T09 / indisponibilite -> flash + re-rendu.
     *
     * @param array<string, string> $params
     */
    public function store(array $params = []): Response
    {
        $guard = $this->guard('order.create');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $source = $this->source();
        $serviceMode = (string) ($form['service_mode'] ?? '');

        // Panier = une ligne produit par quantite >= 1. Le champ s'appelle qty_<id>
        // (un champ nombre par produit listable) ; on ne retient que les positifs.
        $items = [];
        foreach ($form as $key => $value) {
            if (!str_starts_with($key, 'qty_')) {
                continue;
            }
            $productId = (int) substr($key, 4);
            $quantity = ctype_digit(trim($value)) ? (int) $value : 0;
            if ($productId > 0 && $quantity >= 1) {
                $items[] = ['type' => 'product', 'product_id' => $productId, 'quantity' => $quantity];
            }
        }

        if ($items === []) {
            return $this->renderForm($guard, $source, $form, 'Ajoutez au moins un produit (quantite >= 1).', 422);
        }

        try {
            $order = $this->orders()->createStaffOrder(
                ['service_mode' => $serviceMode, 'items' => $items],
                $guard->userId ?? 0,
                $source,
            );
        } catch (OrderValidationException $exception) {
            return $this->renderForm($guard, $source, $form, $this->messageFor($exception->getMessage()), 422);
        }

        $this->setFlash('Commande ' . $order['order_number'] . ' enregistree et encaissee.');

        return $this->redirect($this->landing($source));
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

    protected function productRepository(): ProductRepository
    {
        return new ProductRepository($this->db());
    }

    /**
     * Canal derive du chemin de la requete : tout chemin sous /drive est le canal
     * drive, le reste (/counter...) est le comptoir. Source unique de la verite pour
     * la source auto-tagguee, les titres et les liens.
     */
    private function source(): string
    {
        return str_starts_with($this->request->path(), '/drive') ? 'drive' : 'counter';
    }

    private function landing(string $source): string
    {
        return $source === 'drive' ? '/drive/orders' : '/counter/orders';
    }

    private function newPath(string $source): string
    {
        return $source === 'drive' ? '/drive/orders/new' : '/counter/orders/new';
    }

    private function channelTitle(string $source): string
    {
        return $source === 'drive' ? 'Commandes drive' : 'Commandes comptoir';
    }

    /**
     * Rend le composeur produits (vue partagee par les deux canaux).
     *
     * @param array<string, mixed> $values valeurs du formulaire a reafficher (re-rendu d'erreur)
     */
    private function renderForm(GuardResult $guard, string $source, array $values, ?string $error, int $status = 200): Response
    {
        return $this->channelView('admin/counter/new', $source, [
            'title'       => 'Nouvelle commande ' . ($source === 'drive' ? 'drive' : 'comptoir') . ' - Wakdo Admin',
            'products'    => $this->productRepository()->availableForCatalogue(),
            'serviceMode' => (string) ($values['service_mode'] ?? ($source === 'drive' ? 'drive' : 'dine_in')),
            'error'       => $error,
        ], $guard, $status);
    }

    /**
     * Vue de canal : injecte les liens et le titre derives de la source pour que les
     * vues partagees (comptoir/drive) s'adaptent sans connaitre le decoupage par chemin.
     *
     * @param array<string, mixed> $data
     */
    private function channelView(string $name, string $source, array $data, GuardResult $guard, int $status = 200): Response
    {
        return $this->adminView($name, $data + [
            'activeNav'    => $source === 'drive' ? 'drive' : 'counter',
            'source'       => $source,
            'channelTitle' => $this->channelTitle($source),
            'landing'      => $this->landing($source),
            'newPath'      => $this->newPath($source),
        ], $guard, $status);
    }

    /**
     * Message lisible pour un code d'erreur metier (re-rendu de formulaire).
     */
    private function messageFor(string $code): string
    {
        return match ($code) {
            'EMPTY_ORDER'          => 'La commande est vide : ajoutez au moins un produit.',
            'INVALID_SERVICE_MODE' => 'Mode de service invalide (le drive impose le mode drive).',
            'PRODUCT_UNAVAILABLE'  => 'Un produit selectionne est indisponible.',
            default                => 'Commande invalide, verifiez votre saisie.',
        };
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
