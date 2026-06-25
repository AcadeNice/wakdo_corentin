<?php

declare(strict_types=1);

namespace App\Controllers;

use PDOException;
use App\Auth\Csrf;
use App\Auth\GuardResult;
use App\Auth\PasswordHasher;
use App\Auth\PinThrottle;
use App\Auth\PinVerifier;
use App\Catalogue\CategoryRepository;
use App\Catalogue\MenuRepository;
use App\Catalogue\ProductRepository;
use App\Core\DatabaseInterface;
use App\Core\Response;

/**
 * CRUD des menus composes (P3, mlt 8.4-8.6). Un menu = ligne `menu` + ses
 * `menu_slot` (slots de composition) + `menu_slot_option` (produits eligibles).
 *
 *  - create (menu.create) / update (menu.update) : SANS PIN (un menu n'a pas de
 *    vat_rate ; la sensibilite fiscale est au niveau composant -> hors RG-T13) ;
 *  - delete (menu.delete) : action sensible -> PIN equipier + audit (RG-T13/T14,
 *    mlt 8.6), suppression dure seulement si non reference par order_item.menu_id
 *    (FK RESTRICT -> 409 sinon, proposer la desactivation).
 *
 * La configuration de slots est soumise en un champ cache `slots_json` (le
 * builder vanilla JS la serialise) : Request::formBody() ne retient que les
 * scalaires, donc une structure imbriquee passe par du JSON valide cote serveur.
 *
 * Non `final` : les tests sous-classent pour injecter des doubles.
 */
class MenuController extends AdminController
{
    private const SLOT_TYPES = ['drink', 'side', 'sauce', 'dessert', 'extra'];

    /**
     * @param array<string, string> $params
     */
    public function index(array $params = []): Response
    {
        $guard = $this->guard('menu.read');
        if ($guard instanceof Response) {
            return $guard;
        }

        return $this->adminView('admin/menus/index', [
            'title'     => 'Menus - Wakdo Admin',
            'activeNav' => 'menus',
            'menus'     => $this->menuRepository()->all(),
        ], $guard);
    }

    /**
     * @param array<string, string> $params
     */
    public function create(array $params = []): Response
    {
        $guard = $this->guard('menu.create');
        if ($guard instanceof Response) {
            return $guard;
        }

        return $this->renderForm($guard, 0, [], [], []);
    }

    /**
     * @param array<string, string> $params
     */
    public function store(array $params = []): Response
    {
        $guard = $this->guard('menu.create');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        [$data, $slots, $errors] = $this->validate($form);
        if ($errors !== []) {
            return $this->renderForm($guard, 0, $form, $slots, $errors, 422);
        }

        $this->menuRepository()->create($data, $slots);
        $this->setFlash('Menu cree.');

        return $this->redirect('/admin/menus');
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(array $params): Response
    {
        $guard = $this->guard('menu.update');
        if ($guard instanceof Response) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $menu = $this->menuRepository()->find($id);
        if ($menu === null) {
            return $this->notFound($guard);
        }

        $slots = $this->menuRepository()->slotsWithOptions($id);

        return $this->renderForm($guard, $id, $menu, $this->slotsToForm($slots), []);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(array $params): Response
    {
        $guard = $this->guard('menu.update');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $id = (int) ($params['id'] ?? 0);
        if ($this->menuRepository()->find($id) === null) {
            return $this->notFound($guard);
        }

        [$data, $slots, $errors] = $this->validate($form);
        if ($errors !== []) {
            return $this->renderForm($guard, $id, $form, $slots, $errors, 422);
        }

        $this->menuRepository()->update($id, $data, $slots);
        $this->setFlash('Menu mis a jour.');

        return $this->redirect('/admin/menus');
    }

    /**
     * @param array<string, string> $params
     */
    public function toggle(array $params): Response
    {
        $guard = $this->guard('menu.update');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $id = (int) ($params['id'] ?? 0);
        $menu = $this->menuRepository()->find($id);
        if ($menu === null) {
            return $this->notFound($guard);
        }

        $this->menuRepository()->setActive($id, (int) ($menu['is_available'] ?? 0) !== 1);
        $this->setFlash('Disponibilite du menu mise a jour.');

        return $this->redirect('/admin/menus');
    }

    /**
     * @param array<string, string> $params
     */
    public function confirmDelete(array $params): Response
    {
        $guard = $this->guard('menu.delete');
        if ($guard instanceof Response) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $menu = $this->menuRepository()->find($id);
        if ($menu === null) {
            return $this->notFound($guard);
        }

        return $this->renderDelete($guard, $id, $menu, null);
    }

    /**
     * @param array<string, string> $params
     */
    public function destroy(array $params): Response
    {
        $guard = $this->guard('menu.delete');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $id = (int) ($params['id'] ?? 0);
        $menu = $this->menuRepository()->find($id);
        if ($menu === null) {
            return $this->notFound($guard);
        }

        // RG-T22 : verrou de throttle PIN par utilisateur AGISSANT (session), evalue
        // AVANT la verification ; un acteur verrouille recoit le meme 422 generique,
        // on paie un leurre de timing et on n'ecrit pas de pin.failed sous verrou.
        $actorId = $guard->userId ?? 0;
        if ($actorId > 0 && $this->pinThrottle()->isLocked($actorId)) {
            $this->pinVerifier()->payTimingDecoy($form['pin'] ?? '');

            return $this->renderDelete($guard, $id, $menu, 'Email ou PIN invalide (requis pour supprimer).');
        }

        $actor = $this->pinVerifier()->resolveActingUser(trim($form['pin_email'] ?? ''), $form['pin'] ?? '');
        if ($actor === null) {
            // RG-T08 : trace pin.failed (RG-T14) + increment throttle (RG-T22) dans
            // UNE meme transaction (pas d'etat partiel si crash entre les deux).
            $email = trim($form['pin_email'] ?? '');
            $this->db()->transaction(function (DatabaseInterface $db) use ($email, $id, $actorId): void {
                $this->logFailedPin($db, $email, $id);
                $this->pinThrottle()->recordFailureWithin($db, $actorId);
            });

            return $this->renderDelete($guard, $id, $menu, 'Email ou PIN invalide (requis pour supprimer).');
        }

        $name = (string) ($menu['name'] ?? '');

        // FK order_item.menu_id RESTRICT -> PDOException 23000 -> 409 Conflit (catch).
        // menu_slot / menu_slot_option sont CASCADE (supprimes avec le menu).
        try {
            $this->db()->transaction(function (DatabaseInterface $db) use ($id, $actor, $name): void {
                $deleted = (new MenuRepository($db))->delete($id);
                if ($deleted === 1) {
                    $this->writeAudit($db, 'menu.delete', $actor['id'], $actor['role_id'], $id, 'Suppression menu: ' . $name);
                }
            });
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return $this->renderDelete($guard, $id, $menu, 'Menu reference par des commandes : suppression impossible. Desactivez-le plutot.', 409);
            }

            throw $exception;
        }

        // PIN valide + suppression effective : reset du compteur de l'acteur de
        // SESSION (RG-T22, cle = $actorId, pas l'acteur resolu par le PIN).
        $this->pinThrottle()->reset($actorId);

        $this->setFlash('Menu supprime.');

        return $this->redirect('/admin/menus');
    }

    protected function menuRepository(): MenuRepository
    {
        return new MenuRepository($this->db());
    }

    protected function productRepository(): ProductRepository
    {
        return new ProductRepository($this->db());
    }

    protected function categoryRepository(): CategoryRepository
    {
        return new CategoryRepository($this->db());
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
     * Validation serveur (RG-T18) + allowlist (RG-T16). Renvoie [donnees menu,
     * slots normalises, erreurs]. Les slots viennent du champ cache slots_json.
     *
     * @param array<string, string> $form
     * @return array{0: array{category_id:int, burger_product_id:int, name:string, price_normal_cents:int, price_maxi_cents:int, is_available:int, display_order:int}, 1: list<array{name:string, slot_type:string, is_required:int, display_order:int, options:list<int>}>, 2: array<string, string>}
     */
    private function validate(array $form): array
    {
        $errors = [];

        $categoryRaw = trim($form['category_id'] ?? '');
        $categoryId = ctype_digit($categoryRaw) ? (int) $categoryRaw : 0;
        if ($categoryId === 0 || !$this->menuRepository()->categoryExists($categoryId)) {
            $errors['category_id'] = 'Categorie requise et valide.';
        }

        // F9-2 : le burger principal doit etre un produit de BASE (R4). productIsBase
        // rejette une variante de taille meme si l'UI (base-only) est contournee :
        // une variante n'est pas un produit autonome commercialisable en menu.
        $burgerRaw = trim($form['burger_product_id'] ?? '');
        $burgerId = ctype_digit($burgerRaw) ? (int) $burgerRaw : 0;
        if ($burgerId === 0 || !$this->menuRepository()->productIsBase($burgerId)) {
            $errors['burger_product_id'] = 'Le produit burger de base est requis et doit etre un produit de base (pas une variante de taille).';
        }

        $name = trim($form['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Le nom est requis (120 caracteres max).';
        }

        $priceNormal = $this->parsePrice($form['price_normal_cents'] ?? '');
        if ($priceNormal === null) {
            $errors['price_normal_cents'] = 'Le prix Normal (centimes) doit etre un entier strictement positif.';
        }

        $priceMaxi = $this->parsePrice($form['price_maxi_cents'] ?? '');
        if ($priceMaxi === null) {
            $errors['price_maxi_cents'] = 'Le prix Maxi (centimes) doit etre un entier strictement positif.';
        }

        $orderRaw = trim($form['display_order'] ?? '0');
        $displayOrder = ctype_digit($orderRaw) && (int) $orderRaw <= 65535 ? (int) $orderRaw : -1;
        if ($displayOrder < 0) {
            $errors['display_order'] = 'L\'ordre d\'affichage doit etre un entier entre 0 et 65535.';
        }

        $slots = $this->parseSlots($form['slots_json'] ?? '', $errors);

        $data = [
            'category_id'        => $categoryId,
            'burger_product_id'  => $burgerId,
            'name'               => $name,
            'price_normal_cents' => $priceNormal ?? 0,
            'price_maxi_cents'   => $priceMaxi ?? 0,
            'is_available'       => isset($form['is_available']) ? 1 : 0,
            'display_order'      => $displayOrder < 0 ? 0 : $displayOrder,
        ];

        return [$data, $slots, $errors];
    }

    /**
     * Decode + valide la configuration de slots soumise en JSON. Precondition
     * mlt 8.4 : >=1 slot avec >=1 option ; chaque option doit exister.
     *
     * @param array<string, string> $errors
     * @return list<array{name:string, slot_type:string, is_required:int, display_order:int, options:list<int>}>
     */
    private function parseSlots(string $json, array &$errors): array
    {
        if (trim($json) === '') {
            $errors['slots'] = 'Au moins un slot avec au moins une option est requis.';

            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || $decoded === []) {
            $errors['slots'] = 'Configuration de slots invalide.';

            return [];
        }

        $slots = [];
        $order = 0;
        foreach ($decoded as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $slotName = is_string($raw['name'] ?? null) ? trim($raw['name']) : '';
            $slotType = is_string($raw['slot_type'] ?? null) ? $raw['slot_type'] : '';
            $required = !empty($raw['is_required']) ? 1 : 0;

            // F9-2 : une option de slot doit etre un produit de BASE (R4). Un id de
            // variante de taille (base_product_id non nul) est REJETE explicitement
            // (422) plutot que filtre en silence : choisir une variante comme option
            // serait un contournement de l'UI base-only, et un drop muet ferait perdre
            // un choix sans message clair. Un id inconnu reste filtre (allowlist).
            $optionIds = [];
            $hasVariantOption = false;
            foreach (is_array($raw['options'] ?? null) ? $raw['options'] : [] as $opt) {
                $pid = is_numeric($opt) ? (int) $opt : 0;
                if ($pid <= 0 || !$this->menuRepository()->productExists($pid)) {
                    continue; // id inconnu : filtre (allowlist), pas une erreur
                }
                if (!$this->menuRepository()->productIsBase($pid)) {
                    $hasVariantOption = true;
                    continue; // variante de taille : non eligible comme option de menu
                }
                $optionIds[] = $pid;
            }
            $optionIds = array_values(array_unique($optionIds));

            if ($slotName === '' || mb_strlen($slotName) > 80) {
                $errors['slots'] = 'Chaque slot doit avoir un nom (80 caracteres max).';
                continue;
            }
            if (!in_array($slotType, self::SLOT_TYPES, true)) {
                $errors['slots'] = 'Type de slot invalide.';
                continue;
            }
            if ($hasVariantOption) {
                $errors['slots'] = 'Une variante de taille ne peut pas etre proposee comme option de menu (choisissez le produit de base).';
                continue;
            }
            if ($optionIds === []) {
                $errors['slots'] = 'Chaque slot doit proposer au moins une option valide.';
                continue;
            }

            $slots[] = [
                'name' => $slotName,
                'slot_type' => $slotType,
                'is_required' => $required,
                'display_order' => $order++,
                'options' => $optionIds,
            ];
        }

        if ($slots === [] && !isset($errors['slots'])) {
            $errors['slots'] = 'Au moins un slot avec au moins une option est requis.';
        }

        return $slots;
    }

    private function parsePrice(string $raw): ?int
    {
        $raw = trim($raw);

        return ctype_digit($raw) && (int) $raw > 0 && (int) $raw <= 4294967295 ? (int) $raw : null;
    }

    /**
     * Transforme les slots charges (repository) en structure JSON pour pre-remplir
     * le builder a l'edition.
     *
     * @param list<array{id:int, name:string, slot_type:string, is_required:int, display_order:int, option_product_ids:list<int>}> $slots
     * @return list<array{name:string, slot_type:string, is_required:int, options:list<int>}>
     */
    private function slotsToForm(array $slots): array
    {
        return array_map(static fn (array $s): array => [
            'name' => $s['name'],
            'slot_type' => $s['slot_type'],
            'is_required' => $s['is_required'],
            'options' => $s['option_product_ids'],
        ], $slots);
    }

    /**
     * @param array<string, mixed> $values  valeurs du menu (re-rendu) ou row trouvee
     * @param list<array<string, mixed>> $slots  slots pre-remplis (structure JSON)
     * @param array<string, string> $errors
     */
    private function renderForm(GuardResult $guard, int $id, array $values, array $slots, array $errors, int $status = 200): Response
    {
        return $this->adminView('admin/menus/form', [
            'title'      => ($id !== 0 ? 'Modifier' : 'Nouveau') . ' menu - Wakdo Admin',
            'activeNav'  => 'menus',
            'menuId'     => $id,
            'categories' => $this->categoryRepository()->all(),
            // F9-1 : listes deroulantes base-only (burger principal + options de
            // slot). basesOnly() exclut les variantes de taille (R4) ; all() les
            // inclut (liste admin), il ne doit donc pas alimenter ces selects.
            'products'   => $this->productRepository()->basesOnly(),
            'slotTypes'  => self::SLOT_TYPES,
            'values'     => [
                'category_id'        => (string) ($values['category_id'] ?? ''),
                'burger_product_id'  => (string) ($values['burger_product_id'] ?? ''),
                'name'               => (string) ($values['name'] ?? ''),
                'price_normal_cents' => (string) ($values['price_normal_cents'] ?? ''),
                'price_maxi_cents'   => (string) ($values['price_maxi_cents'] ?? ''),
                'is_available'       => $errors === [] ? ((int) ($values['is_available'] ?? 1) === 1) : array_key_exists('is_available', $values),
                'display_order'      => (string) ($values['display_order'] ?? '0'),
            ],
            'slotsJson'  => json_encode($slots, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
            'errors'     => $errors,
        ], $guard, $status);
    }

    /**
     * @param array<string, mixed> $menu
     */
    private function renderDelete(GuardResult $guard, int $id, array $menu, ?string $error, ?int $status = null): Response
    {
        return $this->adminView('admin/menus/delete', [
            'title'     => 'Supprimer un menu - Wakdo Admin',
            'activeNav' => 'menus',
            'menuId'    => $id,
            'name'      => (string) ($menu['name'] ?? ''),
            'error'     => $error,
        ], $guard, $status ?? ($error !== null ? 422 : 200));
    }

    private function notFound(GuardResult $guard): Response
    {
        return $this->adminView('admin/not_found', ['title' => 'Introuvable', 'activeNav' => 'menus'], $guard, 404);
    }

    private function redirect(string $location): Response
    {
        return Response::make('', 302, ['Location' => $location]);
    }

    private function invalidCsrf(): Response
    {
        return Response::make('Requete invalide.', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * Trace une tentative de PIN echouee sur une action sensible (RG-T14), acteur
     * inconnu (PIN non resolu). Recoit le $db de la transaction (atomicite RG-T08).
     */
    private function logFailedPin(DatabaseInterface $db, string $email, int $menuId): void
    {
        $db->execute(
            'INSERT INTO audit_log (actor_user_id, actor_role_id, action_code, entity_type, entity_id, summary) '
            . 'VALUES (:uid, :rid, :code, :etype, :eid, :summary)',
            [
                'uid' => null,
                'rid' => null,
                'code' => 'pin.failed',
                'etype' => 'menu',
                'eid' => $menuId,
                'summary' => 'Echec PIN action sensible (email tente: ' . $email . ')',
            ],
        );
    }

    private function writeAudit(DatabaseInterface $db, string $action, int $userId, int $roleId, int $entityId, string $summary): void
    {
        $db->execute(
            'INSERT INTO audit_log (actor_user_id, actor_role_id, action_code, entity_type, entity_id, summary) '
            . 'VALUES (:uid, :rid, :code, :etype, :eid, :summary)',
            ['uid' => $userId, 'rid' => $roleId, 'code' => $action, 'etype' => 'menu', 'eid' => $entityId, 'summary' => $summary],
        );
    }
}
