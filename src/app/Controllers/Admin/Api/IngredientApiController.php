<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Api;

use PDOException;
use App\Controllers\IngredientController;
use App\Core\Response;

/**
 * CRUD JSON des ingredients (`/admin/api/ingredients`) + toutes les operations de
 * stock du back-office HTML, memes permissions et memes regles que
 * `IngredientController`, reutilisees par heritage (`validate()`,
 * `validateThresholds()`, `allergenRepository()`, `ingredientRepository()`) :
 *  - CRUD (`ingredient.manage`) : sans PIN (8.8 n'est pas dans l'ensemble sensible
 *    RG-T13). Conflit d'unicite ou suppression bloquee par FK -> `409 CONFLICT` ;
 *  - toggle (`ingredient.manage`), seuils (`stock.manage`), allergenes
 *    (`ingredient.manage`) : sans PIN ;
 *  - restock (`stock.manage`, mlt 9.1) : sans PIN, `user_id` = acteur de session ;
 *  - inventaire et ajustement libre (`stock.count`, mlt 9.2) : PIN equipier
 *    (RG-T13/RG-T22), `user_id` = acteur resolu par le PIN, PAS d'audit_log au
 *    succes (RG-T14 : `stock_movement` est deja la trace).
 *
 * Non `final` : les tests sous-classent pour injecter des doubles, meme
 * convention que le reste des controleurs admin. Seam de test via les hooks
 * proteges herites de IngredientController/AuthenticatedController.
 */
class IngredientApiController extends IngredientController
{
    use JsonApiTrait;

    /**
     * @param array<string, string> $params
     */
    public function apiIndex(array $params = []): Response
    {
        $guard = $this->guardApi('stock.read');
        if ($guard instanceof Response) {
            return $guard;
        }

        $rows = array_map([$this, 'present'], $this->ingredientRepository()->all());

        return $this->collectionResponse($rows);
    }

    /**
     * @param array<string, string> $params
     */
    public function apiShow(array $params): Response
    {
        $guard = $this->guardApi('stock.read');
        if ($guard instanceof Response) {
            return $guard;
        }

        $ingredient = $this->ingredientRepository()->find((int) ($params['id'] ?? 0));
        if ($ingredient === null) {
            return $this->notFoundResponse();
        }

        return $this->okResponse($this->present($ingredient));
    }

    /**
     * @param array<string, string> $params
     */
    public function apiStore(array $params = []): Response
    {
        $guard = $this->guardApi('ingredient.manage');
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

        $repo = $this->ingredientRepository();
        [$form, $formErrors] = $this->toForm($body);
        [$data, $errors] = $this->validate($form, 0);
        $errors = $formErrors + $errors;
        if ($errors !== []) {
            return $this->validationErrorResponse($errors);
        }

        try {
            // stock_quantity initial = 0 (RG-CREATE-ING) ; is_active = 1 : poses
            // cote serveur, pas lies au corps (RG-T16), meme regle que le HTML.
            $repo->create($data + ['stock_quantity' => 0, 'is_active' => 1]);
        } catch (PDOException $exception) {
            return $this->onConflict($exception);
        }

        $id = $this->lastInsertId();
        $ingredient = $repo->find($id);

        return $this->createdResponse($ingredient !== null ? $this->present($ingredient) : null, '/admin/api/ingredients/' . $id);
    }

    /**
     * @param array<string, string> $params
     */
    public function apiUpdate(array $params): Response
    {
        $guard = $this->guardApi('ingredient.manage');
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

        $id = (int) ($params['id'] ?? 0);
        $repo = $this->ingredientRepository();
        $ingredient = $repo->find($id);
        if ($ingredient === null) {
            return $this->notFoundResponse();
        }

        [$form, $formErrors] = $this->toForm($body);
        [$data, $errors] = $this->validate($form, $id, (int) $ingredient['stock_quantity']);
        $errors = $formErrors + $errors;
        if ($errors !== []) {
            return $this->validationErrorResponse($errors);
        }

        try {
            $repo->update($id, $data);
        } catch (PDOException $exception) {
            return $this->onConflict($exception);
        }

        return $this->okResponse($this->present((array) $repo->find($id)));
    }

    /**
     * @param array<string, string> $params
     */
    public function apiDestroy(array $params): Response
    {
        $guard = $this->guardApi('ingredient.manage');
        if ($guard instanceof Response) {
            return $guard;
        }
        if (($csrf = $this->requireCsrf()) !== null) {
            return $csrf;
        }

        $id = (int) ($params['id'] ?? 0);
        $repo = $this->ingredientRepository();
        if ($repo->find($id) === null) {
            return $this->notFoundResponse();
        }

        try {
            $repo->delete($id);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return $this->conflictResponse('Ingrédient référencé par une recette ou des mouvements de stock : suppression impossible. Désactivez-le plutôt.');
            }

            throw $exception;
        }

        return $this->okResponse(['id' => $id, 'status' => 'deleted']);
    }

    /**
     * Reapprovisionnement (mlt 9.1). Sans PIN (`stock.manage`) ; PRE-2 ingredient
     * actif, PRE-3 N>=1..65535 ; `user_id` = acteur de SESSION.
     *
     * @param array<string, string> $params
     */
    public function apiRestock(array $params): Response
    {
        $guard = $this->guardApi('stock.manage');
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

        $id = (int) ($params['id'] ?? 0);
        $repo = $this->ingredientRepository();
        $ingredient = $repo->find($id);
        if ($ingredient === null) {
            return $this->notFoundResponse();
        }

        if ((int) ($ingredient['is_active'] ?? 0) !== 1) {
            return $this->validationErrorResponse(['packs' => 'Ingrédient inactif : réactivez-le avant de réapprovisionner.']);
        }

        // fieldInt() delegue a NumericInput::digits() : meme regle EXACTE que le
        // formulaire HTML (source unique). Un entier JSON natif est accepte tel
        // quel ; un flottant (2.9), une chaine "1e3"/"1.5" ou un booleen sont
        // refuses (jamais tronques en silence).
        $packs = $this->fieldInt($body, 'packs', 1, 65535, required: true);
        if ($packs instanceof Response) {
            return $packs;
        }

        $note = $this->fieldString($body, 'note');
        if ($note instanceof Response) {
            return $note;
        }
        $note = trim($note);
        if (mb_strlen($note) > 255) {
            return $this->validationErrorResponse(['note' => 'Note trop longue (255 caractères max).']);
        }

        $repo->restock($id, $packs, $guard->userId, $note !== '' ? $note : null);

        return $this->okResponse($this->present((array) $repo->find($id)));
    }

    /**
     * Active/desactive un ingredient (soft-delete). Meme permission que le CRUD
     * (`ingredient.manage`), sans PIN (bascule d'etat, pas une ecriture financiere).
     *
     * @param array<string, string> $params
     */
    public function apiToggle(array $params): Response
    {
        $guard = $this->guardApi('ingredient.manage');
        if ($guard instanceof Response) {
            return $guard;
        }
        if (($csrf = $this->requireCsrf()) !== null) {
            return $csrf;
        }

        $id = (int) ($params['id'] ?? 0);
        $repo = $this->ingredientRepository();
        $ingredient = $repo->find($id);
        if ($ingredient === null) {
            return $this->notFoundResponse();
        }

        $newActive = (int) ($ingredient['is_active'] ?? 0) !== 1;
        $repo->setActive($id, $newActive);

        return $this->okResponse(['id' => $id, 'is_active' => $newActive]);
    }

    /**
     * Reglage rapide des seuils (F13). Meme regle que `update()` pour les seuils
     * (`validateThresholds()`, source unique) ; `stock.manage`, sans PIN (calibrage,
     * pas un comptage d'inventaire RG-T13).
     *
     * @param array<string, string> $params
     */
    public function apiThresholds(array $params): Response
    {
        $guard = $this->guardApi('stock.manage');
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

        $id = (int) ($params['id'] ?? 0);
        $repo = $this->ingredientRepository();
        $ingredient = $repo->find($id);
        if ($ingredient === null) {
            return $this->notFoundResponse();
        }

        $stockCapacity = $this->fieldString($body, 'stock_capacity');
        if ($stockCapacity instanceof Response) {
            return $stockCapacity;
        }
        $lowStockPct = $this->fieldString($body, 'low_stock_pct');
        if ($lowStockPct instanceof Response) {
            return $lowStockPct;
        }
        $criticalStockPct = $this->fieldString($body, 'critical_stock_pct');
        if ($criticalStockPct instanceof Response) {
            return $criticalStockPct;
        }

        $form = [
            'stock_capacity'     => $stockCapacity,
            'low_stock_pct'      => $lowStockPct,
            'critical_stock_pct' => $criticalStockPct,
        ];
        [$data, $errors] = $this->validateThresholds($form, (int) $ingredient['stock_quantity']);
        if ($errors !== []) {
            return $this->validationErrorResponse($errors);
        }

        $repo->updateThresholds($id, $data['stock_capacity'], $data['low_stock_pct'], $data['critical_stock_pct']);

        return $this->okResponse($this->present((array) $repo->find($id)));
    }

    /**
     * Inventaire (mlt 9.2, comptage physique absolu). `stock.count` + PIN equipier
     * (RG-T13) : meme flux que le HTML (verrou avant verification, leurre de timing,
     * pin.failed + throttle sur echec). PAS d'audit_log au succes (RG-T14) : la ligne
     * `stock_movement` EST la trace ; `user_id` = acteur resolu par le PIN.
     *
     * @param array<string, string> $params
     */
    public function apiInventory(array $params): Response
    {
        $guard = $this->guardApi('stock.count');
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

        $id = (int) ($params['id'] ?? 0);
        $repo = $this->ingredientRepository();
        if ($repo->find($id) === null) {
            return $this->notFoundResponse();
        }

        // fieldInt() delegue a NumericInput::digits() : source unique partagee avec
        // IngredientController (HTML). Un flottant (2.9), "1e3" ou un booleen sont
        // refuses (422), jamais tronques.
        $actual = $this->fieldInt($body, 'actual_quantity', 0, 2147483647, required: true);
        if ($actual instanceof Response) {
            return $actual;
        }
        $note = $this->fieldString($body, 'note');
        if ($note instanceof Response) {
            return $note;
        }
        $note = trim($note);
        if (mb_strlen($note) > 255) {
            return $this->validationErrorResponse(['note' => 'Note trop longue (255 caractères max).']);
        }

        [$email, $pin] = $this->pinFields($body);
        $actorSessionId = $guard->userId ?? 0;
        $actor = $this->pinGate()->resolve($actorSessionId, $email, $pin, 'ingredient', $id);
        if ($actor === null) {
            return $this->pinErrorResponse('Email ou PIN invalide (requis pour l\'inventaire).');
        }

        $repo->inventoryCount($id, (int) $actual, $actor['id'], $note !== '' ? $note : null);
        $this->pinGate()->reset($actorSessionId);

        return $this->okResponse($this->present((array) $repo->find($id)));
    }

    /**
     * Ajustement libre (delta signe). Meme garde que l'inventaire (`stock.count` +
     * PIN, RG-T13/R9) : une baisse non attribuee masquerait de la demarque.
     *
     * @param array<string, string> $params
     */
    public function apiAdjust(array $params): Response
    {
        $guard = $this->guardApi('stock.count');
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

        $id = (int) ($params['id'] ?? 0);
        $repo = $this->ingredientRepository();
        if ($repo->find($id) === null) {
            return $this->notFoundResponse();
        }

        // fieldInt(..., signed: true) delegue a NumericInput::signedDigits() :
        // source unique partagee avec IngredientController (HTML). Le rejet de la
        // valeur 0 reste ici (specifique a l'ajustement, verifie apres coup).
        $delta = $this->fieldInt($body, 'delta', -2147483647, 2147483647, required: true, signed: true);
        if ($delta instanceof Response) {
            return $delta;
        }
        if ($delta === 0) {
            return $this->validationErrorResponse(['delta' => 'L\'ajustement doit être un entier non nul (ex. 5 pour ajouter, -3 pour retirer).']);
        }
        $note = $this->fieldString($body, 'note');
        if ($note instanceof Response) {
            return $note;
        }
        $note = trim($note);
        if (mb_strlen($note) > 255) {
            return $this->validationErrorResponse(['note' => 'Note trop longue (255 caractères max).']);
        }

        [$email, $pin] = $this->pinFields($body);
        $actorSessionId = $guard->userId ?? 0;
        $actor = $this->pinGate()->resolve($actorSessionId, $email, $pin, 'ingredient', $id);
        if ($actor === null) {
            return $this->pinErrorResponse('Email ou PIN invalide (requis pour l\'ajustement).');
        }

        $repo->adjust($id, $delta, $actor['id'], $note !== '' ? $note : null);
        $this->pinGate()->reset($actorSessionId);

        return $this->okResponse($this->present((array) $repo->find($id)));
    }

    /**
     * Revue des allergenes (F11b). Meme permission que le CRUD (`ingredient.manage`),
     * SANS PIN (hors ensemble sensible RG-T13). La source est obligatoire (une revue
     * non sourcee n'est pas verifiable). `allergen_ids` (liste d'ids) reencodee en
     * cles synthetiques `allergen_<id>`, meme convention que `permission_ids` des
     * roles.
     *
     * @param array<string, string> $params
     */
    public function apiAllergens(array $params): Response
    {
        $guard = $this->guardApi('ingredient.manage');
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

        $id = (int) ($params['id'] ?? 0);
        $repo = $this->ingredientRepository();
        if ($repo->find($id) === null) {
            return $this->notFoundResponse();
        }

        // fieldIntList() : entiers STRICTS (point 6) -- "1.9" est refuse (422),
        // jamais tronque en silence a 1 par un ancien `intval()` sur un
        // `is_numeric()` trop permissif.
        $requestedIds = $this->fieldIntList($body, 'allergen_ids', 1, 2147483647);
        if ($requestedIds instanceof Response) {
            return $requestedIds;
        }

        $catalogue = $this->allergenRepository()->all();
        $retained = [];
        foreach ($catalogue as $allergen) {
            $allergenId = (int) ($allergen['id'] ?? 0);
            if (in_array($allergenId, $requestedIds, true)) {
                $retained[] = ['id' => $allergenId, 'name' => (string) ($allergen['name'] ?? '')];
            }
        }

        $source = $this->fieldString($body, 'source');
        if ($source instanceof Response) {
            return $source;
        }
        $source = mb_substr(trim($source), 0, 120);
        if ($source === '') {
            return $this->validationErrorResponse(['source' => 'Indiquez d\'où vient l\'information (fiche fournisseur, emballage). Une revue sans source n\'est pas vérifiable.']);
        }

        $repo->setAllergens($id, $retained, $source, $guard->userId, $guard->roleId);

        return $this->okResponse(['id' => $id, 'allergens' => $retained, 'source' => $source]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: array<string, string>, 1: array<string, string>} [form, erreurs]
     */
    private function toForm(array $body): array
    {
        return $this->scalarForm($body, [
            'name'               => '',
            'unit'               => '',
            'pack_size'          => '',
            'pack_label'         => '',
            'stock_capacity'     => '',
            'low_stock_pct'      => '',
            'critical_stock_pct' => '',
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id'                  => (int) ($row['id'] ?? 0),
            'name'                => (string) ($row['name'] ?? ''),
            'unit'                => (string) ($row['unit'] ?? ''),
            'stock_quantity'      => (int) ($row['stock_quantity'] ?? 0),
            'stock_capacity'      => (int) ($row['stock_capacity'] ?? 0),
            'pack_size'           => (int) ($row['pack_size'] ?? 0),
            'pack_label'          => ($row['pack_label'] ?? null) !== null ? (string) $row['pack_label'] : null,
            'low_stock_pct'       => (int) ($row['low_stock_pct'] ?? 0),
            'critical_stock_pct'  => (int) ($row['critical_stock_pct'] ?? 0),
            'is_active'           => (int) ($row['is_active'] ?? 0) === 1,
        ];
    }

    private function onConflict(PDOException $exception): Response
    {
        if ((string) $exception->getCode() === '23000') {
            return $this->conflictResponse('Cet ingrédient existe déjà.');
        }

        throw $exception;
    }
}
