<?php

declare(strict_types=1);

namespace App\Controllers;

use PDOException;
use App\Auth\Csrf;
use App\Auth\GuardResult;
use App\Auth\PasswordHasher;
use App\Auth\PinThrottle;
use App\Auth\PinVerifier;
use App\Catalogue\IngredientRepository;
use App\Catalogue\NutritionGateway;
use App\Catalogue\OpenFoodFactsGateway;
use App\Core\DatabaseInterface;
use App\Core\Response;

/**
 * Stock / Ingredients (P3, mlt 8.8 + domaine 9). Quatre familles d'operations,
 * gardees par des permissions distinctes :
 *  - CRUD ingredient (8.8 MANAGE_INGREDIENT) : `ingredient.manage`, SANS PIN
 *    (8.8 n'est pas dans l'ensemble sensible RG-T13). Conflit d'unicite -> 409.
 *  - RESTOCK (9.1) : `stock.manage`, SANS PIN ; PRE-2 ingredient actif, PRE-3 N>=1 ;
 *    user_id = acteur de SESSION (capture par permission, RG-4).
 *  - INVENTORY_COUNT (9.2) : `stock.count` + PIN equipier (RG-T13) ; PRE-3 compte>=0 ;
 *    user_id = acteur resolu par PIN, ecrit dans stock_movement.user_id. PAS d'audit_log
 *    au succes (RG-T14 : le stock_movement EST la trace). Echec PIN -> pin.failed +
 *    throttle (RG-T22), comme produit/menu.
 *  - READ_STOCK (9.3) : `stock.read` ; le user_id des mouvements n'est expose qu'a
 *    manager/admin (RG-4), detecte via la permission stock.manage.
 *
 * Le stock ne bouge JAMAIS par le formulaire de definition : creation pose
 * stock_quantity=0 (RG-CREATE-ING), update ne lie ni stock_quantity ni is_active
 * (RG-T16 ; is_active bascule via toggle, soft-delete). Non `final` : les tests
 * sous-classent pour injecter des doubles.
 */
class IngredientController extends AdminController
{
    /**
     * @param array<string, string> $params
     */
    public function index(array $params = []): Response
    {
        $guard = $this->guard('stock.read');
        if ($guard instanceof Response) {
            return $guard;
        }

        $ingredients = $this->ingredientRepository()->all();

        // Compteurs par bande pour le resume du tableau de bord (3 pastilles).
        // Calcules cote serveur a partir de stock_band deja resolu par le depot,
        // pour que la vue reste declarative et la valeur testable directement.
        $counts = ['critical' => 0, 'low' => 0, 'normal' => 0];
        foreach ($ingredients as $row) {
            $band = (string) ($row['stock_band'] ?? 'normal');
            $counts[$band] = ($counts[$band] ?? 0) + 1;
        }

        return $this->adminView('admin/ingredients/index', [
            'title'       => 'Stock - Wakdo Admin',
            'activeNav'   => 'stock',
            'ingredients' => $ingredients,
            'bandCounts'  => $counts,
            'canManage'   => $this->may($guard, 'ingredient.manage'),
            'canRestock'  => $this->may($guard, 'stock.manage'),
            'canCount'    => $this->may($guard, 'stock.count'),
        ], $guard);
    }

    /**
     * @param array<string, string> $params
     */
    public function create(array $params = []): Response
    {
        $guard = $this->guard('ingredient.manage');
        if ($guard instanceof Response) {
            return $guard;
        }

        return $this->renderForm($guard, 0, [], []);
    }

    /**
     * @param array<string, string> $params
     */
    public function store(array $params = []): Response
    {
        $guard = $this->guard('ingredient.manage');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        [$data, $errors] = $this->validate($form, 0);
        if ($errors !== []) {
            return $this->renderForm($guard, 0, $form, $errors, 422);
        }

        // stock_quantity initial = 0 (RG-CREATE-ING) ; is_active = 1 : valeurs posees
        // cote serveur, pas liees au formulaire (RG-T16). Le stock s'etablit ensuite
        // via restock/inventaire (chaque mouvement laisse une trace).
        try {
            $this->ingredientRepository()->create($data + ['stock_quantity' => 0, 'is_active' => 1]);
        } catch (PDOException $exception) {
            return $this->onWriteConflict($exception, $guard, 0, $form);
        }

        $this->setFlash('Ingredient cree.');

        return $this->redirect('/admin/ingredients');
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(array $params): Response
    {
        $guard = $this->guard('ingredient.manage');
        if ($guard instanceof Response) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $ingredient = $this->ingredientRepository()->find($id);
        if ($ingredient === null) {
            return $this->notFound($guard);
        }

        return $this->renderForm($guard, $id, $ingredient, []);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(array $params): Response
    {
        $guard = $this->guard('ingredient.manage');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $id = (int) ($params['id'] ?? 0);
        if ($this->ingredientRepository()->find($id) === null) {
            return $this->notFound($guard);
        }

        [$data, $errors] = $this->validate($form, $id);
        if ($errors !== []) {
            return $this->renderForm($guard, $id, $form, $errors, 422);
        }

        try {
            $this->ingredientRepository()->update($id, $data);
        } catch (PDOException $exception) {
            return $this->onWriteConflict($exception, $guard, $id, $form);
        }

        $this->setFlash('Ingredient mis a jour.');

        return $this->redirect('/admin/ingredients');
    }

    /**
     * @param array<string, string> $params
     */
    public function toggle(array $params): Response
    {
        $guard = $this->guard('ingredient.manage');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $id = (int) ($params['id'] ?? 0);
        $ingredient = $this->ingredientRepository()->find($id);
        if ($ingredient === null) {
            return $this->notFound($guard);
        }

        $newActive = (int) ($ingredient['is_active'] ?? 0) !== 1;
        $this->ingredientRepository()->setActive($id, $newActive);
        $this->setFlash($newActive ? 'Ingredient reactive.' : 'Ingredient desactive.');

        return $this->redirect('/admin/ingredients');
    }

    /**
     * Enrichit un ingredient avec des donnees nutritionnelles importees d'une API
     * EXTERNE (OpenFoodFacts, Cr 3.a.3). Action explicite (POST + CSRF), gardee par
     * ingredient.manage, SANS PIN (hors ensemble sensible RG-T13). Tolerante : si la
     * source ne renvoie rien, on le signale sans erreur (le flux reste utilisable).
     *
     * @param array<string, string> $params
     */
    public function enrich(array $params): Response
    {
        $guard = $this->guard('ingredient.manage');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $id = (int) ($params['id'] ?? 0);
        $ingredient = $this->ingredientRepository()->find($id);
        if ($ingredient === null) {
            return $this->notFound($guard);
        }

        $data = $this->nutritionGateway()->lookupByName((string) ($ingredient['name'] ?? ''));
        if ($data === null) {
            $this->setFlash('Aucune donnee nutritionnelle trouvee pour cet ingredient (source externe).');
        } else {
            $this->ingredientRepository()->setNutrition($id, $data);
            $this->setFlash('Donnees nutritionnelles importees depuis ' . $data['source'] . '.');
        }

        return $this->redirect('/admin/ingredients/' . $id . '/edit');
    }

    /**
     * Passerelle nutritionnelle externe. Hook protege : les tests redefinissent ce
     * seam pour injecter un double sans appel reseau.
     */
    protected function nutritionGateway(): NutritionGateway
    {
        return new OpenFoodFactsGateway();
    }

    /**
     * @param array<string, string> $params
     */
    public function confirmDelete(array $params): Response
    {
        $guard = $this->guard('ingredient.manage');
        if ($guard instanceof Response) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $ingredient = $this->ingredientRepository()->find($id);
        if ($ingredient === null) {
            return $this->notFound($guard);
        }

        return $this->renderDelete($guard, $id, $ingredient, null);
    }

    /**
     * @param array<string, string> $params
     */
    public function destroy(array $params): Response
    {
        $guard = $this->guard('ingredient.manage');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $id = (int) ($params['id'] ?? 0);
        $ingredient = $this->ingredientRepository()->find($id);
        if ($ingredient === null) {
            return $this->notFound($guard);
        }

        // 8.8 n'est PAS dans l'ensemble PIN (RG-T13) : pas de PIN a la suppression.
        // Hard-delete bloquee par FK RESTRICT (product_ingredient / stock_movement)
        // -> PDOException 23000 -> 409 Conflit (proposer la desactivation).
        try {
            $this->ingredientRepository()->delete($id);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return $this->renderDelete($guard, $id, $ingredient, 'Ingredient reference par une recette ou des mouvements de stock : suppression impossible. Desactivez-le plutot.', 409);
            }

            throw $exception;
        }

        $this->setFlash('Ingredient supprime.');

        return $this->redirect('/admin/ingredients');
    }

    /**
     * @param array<string, string> $params
     */
    public function restockForm(array $params): Response
    {
        $guard = $this->guard('stock.manage');
        if ($guard instanceof Response) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $ingredient = $this->ingredientRepository()->find($id);
        if ($ingredient === null) {
            return $this->notFound($guard);
        }

        return $this->renderRestock($guard, $id, $ingredient, [], []);
    }

    /**
     * @param array<string, string> $params
     */
    public function restock(array $params): Response
    {
        $guard = $this->guard('stock.manage');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $id = (int) ($params['id'] ?? 0);
        $ingredient = $this->ingredientRepository()->find($id);
        if ($ingredient === null) {
            return $this->notFound($guard);
        }

        $errors = [];

        // PRE-2 (9.1) : on ne reapprovisionne qu'un ingredient actif.
        if ((int) ($ingredient['is_active'] ?? 0) !== 1) {
            $errors['packs'] = 'Ingredient inactif : reactivez-le avant de reapprovisionner.';
        }

        // PRE-3 (9.1) : N >= 1 (borne haute pour eviter un debordement de stock_quantity).
        $packsRaw = trim($form['packs'] ?? '');
        $packsValid = ctype_digit($packsRaw) && (int) $packsRaw >= 1 && (int) $packsRaw <= 65535;
        if (!$packsValid && !isset($errors['packs'])) {
            $errors['packs'] = 'Le nombre de packs doit etre un entier entre 1 et 65535.';
        }

        $note = trim($form['note'] ?? '');
        if (mb_strlen($note) > 255) {
            $errors['note'] = 'Note trop longue (255 caracteres max).';
        }

        if ($errors !== []) {
            return $this->renderRestock($guard, $id, $ingredient, $form, $errors, 422);
        }

        $this->ingredientRepository()->restock($id, (int) $packsRaw, $guard->userId, $note !== '' ? $note : null);
        $this->setFlash('Reapprovisionnement enregistre.');

        return $this->redirect('/admin/ingredients');
    }

    /**
     * @param array<string, string> $params
     */
    public function inventoryForm(array $params): Response
    {
        $guard = $this->guard('stock.count');
        if ($guard instanceof Response) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $ingredient = $this->ingredientRepository()->find($id);
        if ($ingredient === null) {
            return $this->notFound($guard);
        }

        return $this->renderInventory($guard, $id, $ingredient, [], []);
    }

    /**
     * @param array<string, string> $params
     */
    public function inventory(array $params): Response
    {
        $guard = $this->guard('stock.count');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $id = (int) ($params['id'] ?? 0);
        $ingredient = $this->ingredientRepository()->find($id);
        if ($ingredient === null) {
            return $this->notFound($guard);
        }

        $errors = [];

        // PRE-3 (9.2) : comptage physique non negatif. ctype_digit borne deja >= 0.
        $actualRaw = trim($form['actual_quantity'] ?? '');
        $actualValid = ctype_digit($actualRaw) && (int) $actualRaw <= 2147483647;
        if (!$actualValid) {
            $errors['actual_quantity'] = 'Le comptage doit etre un entier >= 0.';
        }

        $note = trim($form['note'] ?? '');
        if (mb_strlen($note) > 255) {
            $errors['note'] = 'Note trop longue (255 caracteres max).';
        }

        if ($errors !== []) {
            return $this->renderInventory($guard, $id, $ingredient, $form, $errors, 422);
        }

        // RG-T13/RG-4 : correction d'inventaire = action sensible, PIN equipier.
        // RG-T22 : verrou du throttle par utilisateur AGISSANT (session), evalue AVANT
        // la verification ; sous verrou, leurre de timing et message generique, pas de
        // nouvelle ligne pin.failed.
        $actorId = $guard->userId ?? 0;
        if ($actorId > 0 && $this->pinThrottle()->isLocked($actorId)) {
            $this->pinVerifier()->payTimingDecoy($form['pin'] ?? '');

            return $this->renderInventory($guard, $id, $ingredient, $form, ['pin' => 'Email ou PIN invalide (requis pour l inventaire).'], 422);
        }

        $actor = $this->pinVerifier()->resolveActingUser(trim($form['pin_email'] ?? ''), $form['pin'] ?? '');
        if ($actor === null) {
            // RG-T08 : trace pin.failed (RG-T14) + increment throttle (RG-T22) dans UNE
            // transaction. pin.failed est un evenement securite (aucun stock_movement
            // n'est cree), il n'entre donc pas en conflit avec l'exclusion stock de RG-T14.
            $email = trim($form['pin_email'] ?? '');
            $this->db()->transaction(function (DatabaseInterface $db) use ($email, $id, $actorId): void {
                $this->logFailedPin($db, $email, $id);
                $this->pinThrottle()->recordFailureWithin($db, $actorId);
            });

            return $this->renderInventory($guard, $id, $ingredient, $form, ['pin' => 'Email ou PIN invalide (requis pour l inventaire).'], 422);
        }

        // Succes : la correction ecrit stock_movement.user_id (acteur resolu par PIN).
        // PAS de ligne audit_log (RG-T14 : la trace stock_movement suffit, pas de
        // double-journal). inventoryCount ouvre sa propre transaction (UPDATE+INSERT).
        $this->ingredientRepository()->inventoryCount($id, (int) $actualRaw, $actor['id'], $note !== '' ? $note : null);
        $this->pinThrottle()->reset($actorId);

        $this->setFlash('Inventaire enregistre.');

        return $this->redirect('/admin/ingredients');
    }

    /**
     * @param array<string, string> $params
     */
    public function movements(array $params): Response
    {
        $guard = $this->guard('stock.read');
        if ($guard instanceof Response) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $ingredient = $this->ingredientRepository()->find($id);
        if ($ingredient === null) {
            return $this->notFound($guard);
        }

        // RG-4 (9.3) : l'identite de l'acteur d'un mouvement n'est exposee qu'a
        // manager/admin (detenteurs de stock.manage) ; le personnel de ligne voit
        // les deltas sans l'auteur.
        $showActor = $this->may($guard, 'stock.manage');
        $movements = $this->ingredientRepository()->movements($id);

        $actorNames = [];
        if ($showActor) {
            foreach ($movements as $movement) {
                $uid = $movement['user_id'] !== null ? (int) $movement['user_id'] : 0;
                if ($uid > 0 && !isset($actorNames[$uid])) {
                    $actorNames[$uid] = $this->userDirectory()->displayInfo($uid)['name'];
                }
            }
        }

        return $this->adminView('admin/ingredients/movements', [
            'title'      => 'Mouvements de stock - Wakdo Admin',
            'activeNav'  => 'stock',
            'ingredient' => $ingredient,
            'movements'  => $movements,
            'showActor'  => $showActor,
            'actorNames' => $actorNames,
        ], $guard);
    }

    protected function ingredientRepository(): IngredientRepository
    {
        return new IngredientRepository($this->db());
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
     * Utilise pour adapter l'affichage (liens d'action, visibilite acteur RG-4) sans
     * remplacer la garde par-action (chaque route reste gardee independamment).
     */
    private function may(GuardResult $guard, string $permission): bool
    {
        return $guard->roleId !== null && $this->authorizer()->can($guard->roleId, $permission);
    }

    /**
     * Validation serveur (RG-T18) + allowlist des champs de definition (RG-T16).
     * stock_quantity et is_active ne sont jamais lies ici (poses cote serveur a la
     * creation, modifies via restock/inventaire/toggle). Renvoie [donnees, erreurs].
     *
     * @param array<string, string> $form
     * @return array{0: array{name: string, unit: string, stock_capacity: int, pack_size: int, pack_label: ?string, low_stock_pct: int, critical_stock_pct: int}, 1: array<string, string>}
     */
    private function validate(array $form, int $exceptId): array
    {
        $errors = [];

        $name = trim($form['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Le nom est requis (120 caracteres max).';
        } elseif ($this->ingredientRepository()->nameExists($name, $exceptId)) {
            $errors['name'] = 'Cet ingredient existe deja.';
        }

        $unit = trim($form['unit'] ?? '');
        if ($unit === '' || mb_strlen($unit) > 40) {
            $errors['unit'] = 'L unite est requise (40 caracteres max).';
        }

        $capRaw = trim($form['stock_capacity'] ?? '');
        $capValid = ctype_digit($capRaw) && (int) $capRaw >= 1 && (int) $capRaw <= 2147483647;
        if (!$capValid) {
            $errors['stock_capacity'] = 'La capacite (reference 100%) doit etre un entier >= 1.';
        }

        $packRaw = trim($form['pack_size'] ?? '');
        $packValid = ctype_digit($packRaw) && (int) $packRaw >= 1 && (int) $packRaw <= 65535;
        if (!$packValid) {
            $errors['pack_size'] = 'La taille de pack doit etre un entier entre 1 et 65535.';
        }

        $label = trim($form['pack_label'] ?? '');
        if ($label !== '' && mb_strlen($label) > 80) {
            $errors['pack_label'] = 'Libelle de pack trop long (80 caracteres max).';
        }

        $lowRaw = trim($form['low_stock_pct'] ?? '');
        $lowValid = ctype_digit($lowRaw) && (int) $lowRaw <= 100;
        if (!$lowValid) {
            $errors['low_stock_pct'] = 'Le seuil d alerte doit etre un entier entre 0 et 100.';
        }

        $critRaw = trim($form['critical_stock_pct'] ?? '');
        $critValid = ctype_digit($critRaw) && (int) $critRaw <= 100;
        if (!$critValid) {
            $errors['critical_stock_pct'] = 'Le seuil critique doit etre un entier entre 0 et 100.';
        }

        // RG-CREATE-ING : critical_stock_pct < low_stock_pct (strict).
        if ($lowValid && $critValid && (int) $critRaw >= (int) $lowRaw) {
            $errors['critical_stock_pct'] = 'Le seuil critique doit etre strictement inferieur au seuil d alerte.';
        }

        $data = [
            'name'               => $name,
            'unit'               => $unit,
            'stock_capacity'     => $capValid ? (int) $capRaw : 0,
            'pack_size'          => $packValid ? (int) $packRaw : 0,
            'pack_label'         => $label !== '' ? $label : null,
            'low_stock_pct'      => $lowValid ? (int) $lowRaw : 0,
            'critical_stock_pct' => $critValid ? (int) $critRaw : 0,
        ];

        return [$data, $errors];
    }

    /**
     * Traduit une violation d'unicite (SQLSTATE 23000, name deja pris) en
     * re-affichage 409 du formulaire (coherent avec la convention de conflit du
     * back-office). Tout autre code est repropage.
     *
     * @param array<string, mixed> $form
     */
    private function onWriteConflict(PDOException $exception, GuardResult $guard, int $id, array $form): Response
    {
        if ((string) $exception->getCode() === '23000') {
            return $this->renderForm($guard, $id, $form, ['name' => 'Cet ingredient existe deja.'], 409);
        }

        throw $exception;
    }

    private function logFailedPin(DatabaseInterface $db, string $email, int $ingredientId): void
    {
        $db->execute(
            'INSERT INTO audit_log (actor_user_id, actor_role_id, action_code, entity_type, entity_id, summary) '
            . 'VALUES (:uid, :rid, :code, :etype, :eid, :summary)',
            [
                'uid'     => null,
                'rid'     => null,
                'code'    => 'pin.failed',
                'etype'   => 'ingredient',
                'eid'     => $ingredientId,
                'summary' => 'Echec PIN inventaire (email tente: ' . $email . ')',
            ],
        );
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function renderForm(GuardResult $guard, int $id, array $values, array $errors, int $status = 200): Response
    {
        return $this->adminView('admin/ingredients/form', [
            'title'        => ($id !== 0 ? 'Modifier' : 'Nouvel') . ' ingredient - Wakdo Admin',
            'activeNav'    => 'stock',
            'ingredientId' => $id,
            'values'       => [
                'name'               => (string) ($values['name'] ?? ''),
                'unit'               => (string) ($values['unit'] ?? ''),
                'stock_capacity'     => (string) ($values['stock_capacity'] ?? ''),
                'pack_size'          => (string) ($values['pack_size'] ?? '1'),
                'pack_label'         => (string) ($values['pack_label'] ?? ''),
                'low_stock_pct'      => (string) ($values['low_stock_pct'] ?? '10'),
                'critical_stock_pct' => (string) ($values['critical_stock_pct'] ?? '5'),
                // Nutrition (lecture seule) : transmise pour que le panneau d'enrichissement
                // reflete la valeur importee (Cr 3.a.3). Absente sur create / re-rendu d'erreur.
                'energy_kcal_100g'     => (string) ($values['energy_kcal_100g'] ?? ''),
                'nutrition_source'     => (string) ($values['nutrition_source'] ?? ''),
                'nutrition_fetched_at' => (string) ($values['nutrition_fetched_at'] ?? ''),
            ],
            'errors'       => $errors,
        ], $guard, $status);
    }

    /**
     * @param array<string, mixed> $ingredient
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function renderRestock(GuardResult $guard, int $id, array $ingredient, array $values, array $errors, int $status = 200): Response
    {
        return $this->adminView('admin/ingredients/restock', [
            'title'        => 'Reapprovisionner - Wakdo Admin',
            'activeNav'    => 'stock',
            'ingredientId' => $id,
            'ingredient'   => $ingredient,
            'values'       => ['packs' => (string) ($values['packs'] ?? ''), 'note' => (string) ($values['note'] ?? '')],
            'errors'       => $errors,
        ], $guard, $status);
    }

    /**
     * @param array<string, mixed> $ingredient
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function renderInventory(GuardResult $guard, int $id, array $ingredient, array $values, array $errors, int $status = 200): Response
    {
        return $this->adminView('admin/ingredients/inventory', [
            'title'        => 'Inventaire - Wakdo Admin',
            'activeNav'    => 'stock',
            'ingredientId' => $id,
            'ingredient'   => $ingredient,
            'values'       => ['actual_quantity' => (string) ($values['actual_quantity'] ?? ''), 'note' => (string) ($values['note'] ?? '')],
            'errors'       => $errors,
        ], $guard, $status);
    }

    /**
     * @param array<string, mixed> $ingredient
     */
    private function renderDelete(GuardResult $guard, int $id, array $ingredient, ?string $error, ?int $status = null): Response
    {
        return $this->adminView('admin/ingredients/delete', [
            'title'        => 'Supprimer un ingredient - Wakdo Admin',
            'activeNav'    => 'stock',
            'ingredientId' => $id,
            'name'         => (string) ($ingredient['name'] ?? ''),
            'error'        => $error,
        ], $guard, $status ?? ($error !== null ? 422 : 200));
    }

    private function notFound(GuardResult $guard): Response
    {
        return $this->adminView('admin/not_found', ['title' => 'Introuvable', 'activeNav' => 'stock'], $guard, 404);
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
