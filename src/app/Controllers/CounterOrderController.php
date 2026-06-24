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
 * Composeur (sous-lot 3c) : produits ET menus composes (slots accompagnement/
 * boisson/sauce + format Normal/Maxi) ET modificateurs d'ingredients (retrait/ajout).
 * La composition PROPOSABLE de chaque produit a la carte et du burger de chaque menu
 * (ingredients is_removable / is_addable + surcout) est embarquee en data-* pour que
 * counter-order.js affiche les cases "retirer" / "ajouter +X.XX EUR". Le serveur reste
 * seul juge : resolveModifiers revalide chaque modificateur metier (l'ingredient doit
 * appartenir a la recette du produit support, etre retirable pour 'remove' / ajoutable
 * pour 'add') et fige extra_price_cents (RG-T16) ; le client ne fait que PROPOSER.
 * Le panier est construit cote client (counter-order.js) et serialise en JSON dans
 * le champ cache `items_json` ; le serveur (store) le decode, revalide la forme
 * (RG-T18) puis delegue a createStaffOrder qui resout/calcule cote serveur (RG-T16).
 * Le chemin legacy `qty_<id>` (3a) reste accepte en repli quand `items_json` est
 * absent (degradation sans JS). La commande est creee directement `paid`
 * (encaissement immediat, RG-5/POST-1) sans PIN : la permission order.create suffit.
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
        $orderQuery = $this->orderQuery();

        // RG-1 (5.1, source filter) : ne lister que les commandes du canal. recent()
        // ramene les plus recentes tous canaux ; on filtre sur la source derivee du
        // chemin pour que le comptoir ne voie pas le drive et inversement.
        $orders = array_values(array_filter(
            $orderQuery->recent(50),
            static fn (array $o): bool => (string) ($o['source'] ?? '') === $source,
        ));

        // File "En cours" (RG-T12) : commandes du canal au statut paid non livrees,
        // la plus ancienne d'abord (tri paid_at croissant fait par paidQueue). Filtree
        // a la SEULE source du canal pour que l'equipier ne voie que ce qu'il sert.
        $inProgress = $orderQuery->paidQueue([$source]);

        return $this->channelView('admin/counter/index', $source, [
            'title'      => $this->channelTitle($source) . ' - Wakdo Admin',
            'orders'     => $orders,
            'inProgress' => $inProgress,
        ], $guard);
    }

    /**
     * Composeur de commande (GET .../new) : produits commandables, menus composes
     * (slots + options) + select service_mode. Tout est passe a la vue qui l'embarque
     * en data-* pour counter-order.js (aucun endpoint slots : page back-office authentifiee).
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
     * Soumission de la commande (POST). Le panier est decode depuis le champ cache
     * `items_json` (produits + menus composes construits cote client) ; en repli
     * sans JS, les quantites legacy `qty_<id>` (3a) sont relues. Chaque item est
     * revalide dans sa FORME (RG-T18) cote serveur, puis createStaffOrder resout les
     * references, recalcule les prix (RG-T16) et encaisse (source derivee du chemin,
     * acteur = equipier authentifie). Panier vide / RG-T09 / indisponibilite -> flash + re-rendu.
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

        // Numero de table (confort comptoir) : ne porte de sens qu'en sur place. On ne
        // le transmet qu'en dine_in ; persist() le rejette de toute facon hors dine_in,
        // mais ne pas le passer evite un INVALID_SERVICE_TAG sur une saisie residuelle.
        $serviceTag = $serviceMode === 'dine_in' ? trim((string) ($form['service_tag'] ?? '')) : '';

        // Chemin unifie : le panier construit par counter-order.js arrive serialise
        // dans items_json. Quand il est present, il fait foi ; les quantites legacy
        // qty_<id> ne servent qu'au repli sans JS (degradation gracieuse).
        $itemsJson = (string) ($form['items_json'] ?? '');
        $items = trim($itemsJson) !== ''
            ? $this->decodeItems($itemsJson)
            : $this->legacyQuantities($form);

        if ($items === []) {
            return $this->renderForm($guard, $source, $form, 'Ajoutez au moins un produit ou un menu.', 422);
        }

        $req = ['service_mode' => $serviceMode, 'items' => $items];
        if ($serviceTag !== '') {
            $req['service_tag'] = $serviceTag;
        }

        try {
            $order = $this->orders()->createStaffOrder(
                $req,
                $guard->userId ?? 0,
                $source,
            );
        } catch (OrderValidationException $exception) {
            return $this->renderForm($guard, $source, $form, $this->messageFor($exception->getMessage()), 422);
        }

        $this->setFlash('Commande ' . $order['order_number'] . ' enregistree et encaissee.');

        return $this->redirect($this->landing($source));
    }

    /**
     * Decode + normalise le panier soumis en JSON par counter-order.js (RG-T18 :
     * revalidation de la FORME cote serveur ; le client n'est jamais cru). Chaque
     * item mal forme est ECARTE silencieusement (un client falsifie ne bloque pas le
     * traitement des items valides ; un panier integralement invalide retombe vide ->
     * 422). La validation METIER (existence, disponibilite, options de slot, recette)
     * et le calcul de prix restent dans OrderRepository::resolveLine (source unique).
     *
     * Forme produite (calque sur ce qu'attend resolveLine) :
     *  - produit : {type:'product', product_id:int>0, quantity:int>=1, modifiers?:[...]}
     *  - menu    : {type:'menu', menu_id:int>0, quantity:int>=1, format:'normal'|'maxi',
     *               selections:[{menu_slot_id:int>0, product_id:int>0}], modifiers?:[...]}
     *  - modifier: {ingredient_id:int>0, action:'add'|'remove'}
     *
     * @return list<array<string, mixed>>
     */
    private function decodeItems(string $json): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $items = [];
        foreach ($decoded as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $type = (string) ($raw['type'] ?? '');
            $quantity = $this->positiveInt($raw['quantity'] ?? null, 1);
            $modifiers = $this->normaliseModifiers($raw['modifiers'] ?? null);

            if ($type === 'product') {
                $productId = $this->positiveInt($raw['product_id'] ?? null, 0);
                if ($productId > 0) {
                    $items[] = [
                        'type'       => 'product',
                        'product_id' => $productId,
                        'quantity'   => $quantity,
                        'modifiers'  => $modifiers,
                    ];
                }
                continue;
            }

            if ($type === 'menu') {
                $menuId = $this->positiveInt($raw['menu_id'] ?? null, 0);
                if ($menuId > 0) {
                    $items[] = [
                        'type'       => 'menu',
                        'menu_id'    => $menuId,
                        'quantity'   => $quantity,
                        'format'     => ($raw['format'] ?? 'normal') === 'maxi' ? 'maxi' : 'normal',
                        'selections' => $this->normaliseSelections($raw['selections'] ?? null),
                        'modifiers'  => $modifiers,
                    ];
                }
            }
        }

        return $items;
    }

    /**
     * Selections de slot normalisees (forme), revalidees metier par resolveSelections.
     *
     * @return list<array{menu_slot_id:int, product_id:int}>
     */
    private function normaliseSelections(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $sel) {
            if (!is_array($sel)) {
                continue;
            }
            $slotId = $this->positiveInt($sel['menu_slot_id'] ?? null, 0);
            $productId = $this->positiveInt($sel['product_id'] ?? null, 0);
            if ($slotId > 0 && $productId > 0) {
                $out[] = ['menu_slot_id' => $slotId, 'product_id' => $productId];
            }
        }

        return $out;
    }

    /**
     * Modificateurs d'ingredients normalises (forme), revalides metier par resolveModifiers.
     *
     * @return list<array{ingredient_id:int, action:string}>
     */
    private function normaliseModifiers(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $mod) {
            if (!is_array($mod)) {
                continue;
            }
            $ingredientId = $this->positiveInt($mod['ingredient_id'] ?? null, 0);
            $action = ($mod['action'] ?? '') === 'add' ? 'add' : 'remove';
            if ($ingredientId > 0) {
                $out[] = ['ingredient_id' => $ingredientId, 'action' => $action];
            }
        }

        return $out;
    }

    /**
     * Entier positif tolerant (le JSON decode peut livrer int|string|float|null).
     */
    private function positiveInt(mixed $value, int $minimum): int
    {
        $int = is_numeric($value) ? (int) $value : 0;

        return $int >= $minimum ? max($int, $minimum) : $minimum;
    }

    /**
     * Repli sans JS : panier produit construit depuis les champs `qty_<id>` (3a).
     * Conserve pour ne pas casser la saisie quand counter-order.js ne s'execute pas.
     *
     * @param array<string, mixed> $form
     * @return list<array<string, mixed>>
     */
    private function legacyQuantities(array $form): array
    {
        $items = [];
        foreach ($form as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'qty_')) {
                continue;
            }
            $productId = (int) substr($key, 4);
            $quantity = ctype_digit(trim((string) $value)) ? (int) $value : 0;
            if ($productId > 0 && $quantity >= 1) {
                $items[] = ['type' => 'product', 'product_id' => $productId, 'quantity' => $quantity];
            }
        }

        return $items;
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

    protected function menuRepository(): MenuRepository
    {
        return new MenuRepository($this->db());
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
        $productRepository = $this->productRepository();
        $products = $productRepository->availableForCatalogue();

        // Modificateurs proposables par produit a la carte : seuls les produits dont la
        // recette offre au moins un ingredient retirable/ajoutable portent une compo.
        $products = array_map(function (array $product) use ($productRepository): array {
            $product['modifiers'] = $this->proposableModifiers($productRepository, (int) ($product['id'] ?? 0));

            return $product;
        }, $products);

        return $this->channelView('admin/counter/new', $source, [
            'title'       => 'Nouvelle commande ' . ($source === 'drive' ? 'drive' : 'comptoir') . ' - Wakdo Admin',
            'products'    => $products,
            'menus'       => $this->menusWithSlots($productRepository),
            'serviceMode' => (string) ($values['service_mode'] ?? ($source === 'drive' ? 'drive' : 'dine_in')),
            'serviceTag'  => (string) ($values['service_tag'] ?? ''),
            'error'       => $error,
        ], $guard, $status);
    }

    /**
     * Menus commandables enrichis de leurs slots+options (lecture catalogue) ET des
     * modificateurs proposables du burger support, pour que counter-order.js compose
     * chaque menu SANS appel reseau supplementaire : toute la configuration est
     * embarquee en data-* au rendu (page back-office authentifiee). La forme `slots`
     * calque slotsWithOptions() (id, name, slot_type, is_required, display_order,
     * option_product_ids), consommable par la meme logique que page-product-menu.js
     * cote borne ; `burger_modifiers` calque proposableModifiers() (la selection de
     * modificateurs d'un menu cible le burger, comme resolveModifiers cote serveur).
     *
     * @return list<array<string, mixed>>
     */
    private function menusWithSlots(ProductRepository $productRepository): array
    {
        $menuRepository = $this->menuRepository();
        $menus = $menuRepository->availableForCatalogue();

        return array_map(function (array $menu) use ($menuRepository, $productRepository): array {
            $menu['slots'] = $menuRepository->slotsWithOptions((int) ($menu['id'] ?? 0));
            $menu['burger_modifiers'] = $this->proposableModifiers($productRepository, (int) ($menu['burger_product_id'] ?? 0));

            return $menu;
        }, $menus);
    }

    /**
     * Modificateurs PROPOSABLES d'un produit support : les lignes de composition()
     * dont l'ingredient est retirable (is_removable=1) OU ajoutable (is_addable=1),
     * projetees a ce dont l'UI a besoin (ingredient_id, name, is_removable, is_addable,
     * extra_price_cents). Les ingredients ni retirables ni ajoutables sont ECARTES :
     * ils n'offrent aucune case a cocher cote client, donc embarquer leur ligne
     * alourdirait le data-* sans usage. Le client ne fait que PROPOSER ces choix ;
     * resolveModifiers revalide tout cote serveur et fige le surcout (RG-T16).
     *
     * @return list<array{ingredient_id:int, name:string, is_removable:int, is_addable:int, extra_price_cents:int}>
     */
    private function proposableModifiers(ProductRepository $productRepository, int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        $out = [];
        foreach ($productRepository->composition($productId) as $line) {
            $isRemovable = (int) ($line['is_removable'] ?? 0);
            $isAddable = (int) ($line['is_addable'] ?? 0);
            if ($isRemovable !== 1 && $isAddable !== 1) {
                continue;
            }
            $out[] = [
                'ingredient_id'     => (int) ($line['ingredient_id'] ?? 0),
                'name'              => (string) ($line['ingredient_name'] ?? ''),
                'is_removable'      => $isRemovable,
                'is_addable'        => $isAddable,
                'extra_price_cents' => (int) ($line['extra_price_cents'] ?? 0),
            ];
        }

        return $out;
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
            'EMPTY_ORDER'             => 'La commande est vide : ajoutez au moins un produit ou un menu.',
            'INVALID_SERVICE_MODE'    => 'Mode de service invalide (le drive impose le mode drive).',
            'PRODUCT_UNAVAILABLE'     => 'Un produit selectionne est indisponible.',
            'MENU_UNAVAILABLE'        => 'Un menu selectionne est indisponible.',
            'INVALID_SELECTION'       => 'Un choix de menu (accompagnement / boisson / sauce) est invalide.',
            'INVALID_MODIFIER',
            'INGREDIENT_NOT_REMOVABLE',
            'INGREDIENT_NOT_ADDABLE'  => 'Une modification d\'ingredient est invalide.',
            default                   => 'Commande invalide, verifiez votre saisie.',
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
