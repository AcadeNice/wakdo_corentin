<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Api;

use PDOException;
use Throwable;
use App\Catalogue\ImportBlockedException;
use App\Catalogue\ProductImportService;
use App\Catalogue\ProductRepository;
use App\Controllers\ProductController;
use App\Core\DatabaseInterface;
use App\Core\Money;
use App\Core\Response;

/**
 * CRUD JSON des produits (`/admin/api/products`), memes permissions et memes
 * regles que `ProductController` (HTML), reutilisees par heritage (`validate()`,
 * `changeSummary()`, `productRepository()`) :
 *  - create (`product.create`) : sans PIN ;
 *  - update (`product.update`) : PIN equipier + audit UNIQUEMENT si le prix ou la
 *    TVA changent (mlt 8.2 RG-4), corps JSON `pin_email` + `pin` ;
 *  - delete (`product.delete`) : PIN equipier + audit, `409 CONFLICT` si le
 *    produit est encore reference (FK RESTRICT).
 *
 * L'upload d'image (multipart, `ImageUploader`) reste reserve au formulaire HTML :
 * l'API JSON accepte un `image_path` deja heberge (chaine), comme le formulaire
 * le permet deja pour ce champ.
 *
 * Non `final` : les tests sous-classent pour injecter des doubles, meme
 * convention que le reste des controleurs admin. Seam de test via les hooks
 * proteges herites de ProductController/AuthenticatedController.
 */
class ProductApiController extends ProductController
{
    use JsonApiTrait;

    /**
     * @param array<string, string> $params
     */
    public function apiIndex(array $params = []): Response
    {
        $guard = $this->guardApi('product.read');
        if ($guard instanceof Response) {
            return $guard;
        }

        $rows = array_map([$this, 'present'], $this->productRepository()->all());

        return $this->collectionResponse($rows);
    }

    /**
     * @param array<string, string> $params
     */
    public function apiShow(array $params): Response
    {
        $guard = $this->guardApi('product.read');
        if ($guard instanceof Response) {
            return $guard;
        }

        $product = $this->productRepository()->find((int) ($params['id'] ?? 0));
        if ($product === null) {
            return $this->notFoundResponse();
        }

        return $this->okResponse($this->present($product));
    }

    /**
     * @param array<string, string> $params
     */
    public function apiStore(array $params = []): Response
    {
        $guard = $this->guardApi('product.create');
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

        [$form, $formErrors] = $this->toForm($body);
        [$data, $errors] = $this->validate($form, 0);
        $errors = $formErrors + $errors;
        if ($errors !== []) {
            return $this->validationErrorResponse($errors);
        }

        $this->productRepository()->create($data);
        $id = $this->lastInsertId();
        $product = $this->productRepository()->find($id);

        return $this->createdResponse($product !== null ? $this->present($product) : null, '/admin/api/products/' . $id);
    }

    /**
     * @param array<string, string> $params
     */
    public function apiUpdate(array $params): Response
    {
        $guard = $this->guardApi('product.update');
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
        $repo = $this->productRepository();
        $current = $repo->find($id);
        if ($current === null) {
            return $this->notFoundResponse();
        }

        [$form, $formErrors] = $this->toForm($body, $current);
        [$data, $errors] = $this->validate($form, $id);
        $errors = $formErrors + $errors;
        if ($errors !== []) {
            return $this->validationErrorResponse($errors);
        }

        $priceChanged = $data['price_cents'] !== (int) ($current['price_cents'] ?? 0);
        $vatChanged = $data['vat_rate'] !== (int) ($current['vat_rate'] ?? 0);

        if (!$priceChanged && !$vatChanged) {
            $repo->update($id, $data);

            return $this->okResponse($this->present((array) $repo->find($id)));
        }

        // Changement sensible (mlt 8.2 RG-4) : exige email + PIN (RG-T13).
        [$email, $pin] = $this->pinFields($body);
        $actorSessionId = $guard->userId ?? 0;
        $actor = $this->pinGate()->resolve($actorSessionId, $email, $pin, 'product', $id);
        if ($actor === null) {
            return $this->pinErrorResponse('Email ou PIN invalide (requis pour modifier prix/TVA).');
        }

        $summary = $this->changeSummary($current, $data, $priceChanged, $vatChanged);

        $this->db()->transaction(function (DatabaseInterface $db) use ($id, $data, $actor, $summary): void {
            (new ProductRepository($db))->update($id, $data);
            $this->pinGate()->writeAudit($db, 'product.update', $actor['id'], $actor['role_id'], 'product', $id, $summary);
        });

        $this->pinGate()->reset($actorSessionId);

        return $this->okResponse($this->present((array) $repo->find($id)));
    }

    /**
     * @param array<string, string> $params
     */
    public function apiDestroy(array $params): Response
    {
        $guard = $this->guardApi('product.delete');
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
        $repo = $this->productRepository();
        $product = $repo->find($id);
        if ($product === null) {
            return $this->notFoundResponse();
        }

        [$email, $pin] = $this->pinFields($body);
        $actorSessionId = $guard->userId ?? 0;
        $actor = $this->pinGate()->resolve($actorSessionId, $email, $pin, 'product', $id);
        if ($actor === null) {
            return $this->pinErrorResponse('Email ou PIN invalide (requis pour supprimer).');
        }

        $name = (string) ($product['name'] ?? '');
        $cascaded = $repo->compositionCount($id);
        $summary = 'Suppression produit: ' . $name . ' (' . $cascaded . ' ligne(s) de recette cascade-supprimée(s))';

        try {
            $this->db()->transaction(function (DatabaseInterface $db) use ($id, $actor, $summary): void {
                $deleted = (new ProductRepository($db))->delete($id);
                if ($deleted === 1) {
                    $this->pinGate()->writeAudit($db, 'product.delete', $actor['id'], $actor['role_id'], 'product', $id, $summary);
                }
            });
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return $this->conflictResponse('Produit référencé par des commandes ou menus : suppression impossible.');
            }

            throw $exception;
        }

        // Le produit est parti : son image deposee n'a plus de proprietaire (remove()
        // ignore les chemins du catalogue livre avec le projet), meme geste que
        // ProductController::destroy() (HTML).
        $this->imageUploader()->remove(is_string($product['image_path'] ?? null) ? (string) $product['image_path'] : null);

        $this->pinGate()->reset($actorSessionId);

        return $this->okResponse(['id' => $id, 'status' => 'deleted']);
    }

    /**
     * Deplace un produit d'un rang dans sa categorie (`direction`: "up"|"down").
     * Meme permission (`product.update`), sans PIN.
     *
     * @param array<string, string> $params
     */
    public function apiMove(array $params): Response
    {
        $guard = $this->guardApi('product.update');
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

        $direction = $this->fieldString($body, 'direction');
        if ($direction instanceof Response) {
            return $direction;
        }
        if ($direction !== 'up' && $direction !== 'down') {
            return $this->validationErrorResponse(['direction' => 'La direction doit être "up" ou "down".']);
        }

        $id = (int) ($params['id'] ?? 0);
        $repo = $this->productRepository();
        if ($repo->find($id) === null) {
            return $this->notFoundResponse();
        }

        $moved = $repo->reorderWithinCategory($id, $direction);

        return $this->okResponse(['id' => $id, 'moved' => $moved]);
    }

    /**
     * Lit la recette (composition product_ingredient) d'un produit. Meme permission
     * que sa modification (`ingredient.manage`, distincte du CRUD produit).
     *
     * @param array<string, string> $params
     */
    public function apiRecipeShow(array $params): Response
    {
        $guard = $this->guardApi('ingredient.manage');
        if ($guard instanceof Response) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $repo = $this->productRepository();
        if ($repo->find($id) === null) {
            return $this->notFoundResponse();
        }

        return $this->okResponse(['id' => $id, 'composition' => $repo->composition($id)]);
    }

    /**
     * Remplace la recette d'un produit. Reutilise `parseComposition()` (allowlist
     * RG-T16, PK composite dedupliquee) : le corps JSON `composition` (tableau natif)
     * est reencode en chaine pour traverser exactement la meme garde que le champ
     * cache `composition_json` du formulaire HTML. Sans PIN (`ingredient.manage`,
     * hors ensemble sensible RG-T13) ; composition vide autorisee (purge la recette).
     *
     * @param array<string, string> $params
     */
    public function apiRecipeSave(array $params): Response
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
        $repo = $this->productRepository();
        if ($repo->find($id) === null) {
            return $this->notFoundResponse();
        }

        $composition = $this->fieldList($body, 'composition');
        if ($composition instanceof Response) {
            return $composition;
        }
        $errors = [];
        $lines = $this->parseComposition((string) json_encode($composition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $errors);
        if ($errors !== []) {
            return $this->validationErrorResponse($errors);
        }

        $repo->setComposition($id, $lines);

        return $this->okResponse(['id' => $id, 'composition' => $repo->composition($id)]);
    }

    /**
     * Gabarit CSV telechargeable, meme contenu que la route HTML equivalente
     * (`GET /admin/products/import/template`) -- mais enveloppe en JSON
     * (`{"data": {"csv": "...", "filename": "..."}}`) plutot qu'en piece jointe
     * `text/csv` : ce point d'entree reste sur le meme contrat que le reste de
     * l'API admin (demo Postman/Bruno, docs/api/import-produits.md).
     *
     * @param array<string, string> $params
     */
    public function apiImportTemplate(array $params = []): Response
    {
        $guard = $this->guardApi('product.create');
        if ($guard instanceof Response) {
            return $guard;
        }

        return $this->okResponse(['csv' => ProductImportService::templateCsv(), 'filename' => 'wakdo-import-produits.csv']);
    }

    /**
     * Aperçu (`?dry_run=1`) ou application d'un import CSV, meme service que la
     * route HTML (`ProductImportService`). Le CSV voyage dans le corps JSON
     * (`csv`, une chaîne) -- pas en multipart, pour rester sur le contrat JSON
     * de l'API admin. Si le rapport signale un changement de prix, `pin_email`/
     * `pin` (memes champs que le reste de l'API, `pinFields()`) sont exigés pour
     * APPLIQUER (jamais pour l'aperçu). Autorisation : `guardImportAuthorizations()`
     * (herite de ProductController, meme methode que la route HTML -- product.update
     * des qu'un produit existant est touche, ingredient.manage des qu'une recette
     * existante est remplacee ou qu'un ingredient serait cree) ; sinon 422 nommé,
     * comme toute violation RG-T03 de ce trait.
     *
     * @param array<string, string> $params
     */
    public function apiImportRun(array $params = []): Response
    {
        $guard = $this->guardApi('product.create');
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

        $csv = $this->fieldString($body, 'csv');
        if ($csv instanceof Response) {
            return $csv;
        }
        if (trim($csv) === '') {
            return $this->validationErrorResponse(['csv' => 'Le champ "csv" (contenu du fichier) est requis.']);
        }

        $service = new ProductImportService();
        $report = $service->preview($csv, $this->db());
        $this->guardImportAuthorizations($guard, $report);

        $dryRun = $this->request->query('dry_run') === '1';
        if ($dryRun) {
            return $this->okResponse($report);
        }

        if ($report['errors'] !== []) {
            return $this->validationErrorResponse(['csv' => 'Le fichier contient des erreurs : voir le détail.', 'details' => $report['errors']]);
        }

        $actorSessionId = (int) ($guard->userId ?? 0);
        $actorId = $actorSessionId;
        $actorRoleId = (int) ($guard->roleId ?? 0);

        if ($report['hasPriceChange']) {
            [$email, $pin] = $this->pinFields($body);
            $actor = $this->pinGate()->resolve($actorSessionId, $email, $pin, 'product', 0);
            if ($actor === null) {
                return $this->pinErrorResponse('Email ou PIN invalide (requis : ce fichier modifie au moins un prix).');
            }
            $actorId = (int) $actor['id'];
            $actorRoleId = (int) $actor['role_id'];
        }

        try {
            $result = $service->apply($csv, $this->db(), $actorId, $actorRoleId);
        } catch (ImportBlockedException $exception) {
            return $this->conflictResponse($exception->getMessage());
        } catch (Throwable $exception) {
            // Filet de securite : apply() n'ecrit que DANS la transaction de
            // ProductImportService (RG-T08), donc toute exception non prevue
            // ici a deja ete annulee (rollback) avant de remonter -- rien n'est
            // resté a moitié écrit. Sans ce filet, une erreur inattendue (ex. une
            // valeur trop longue pour une colonne, si un controle amont a laissé
            // passer un cas non prevu) remontait en 500 brut jusqu'au client.
            //
            // Ce filet court-circuite le gestionnaire GLOBAL (src/public/admin/
            // index.php) qui, lui, trace TOUJOURS avant de repondre ("l'incident
            // doit rester diagnosticable cote serveur") -- meme trace ICI (meme
            // format), sinon ce cas precis redeviendrait invisible cote serveur
            // (releve par relecture adverse, 2026-09-26).
            error_log(sprintf(
                '[wakdo] Unhandled %s: %s @ %s:%d',
                get_class($exception),
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
            ));

            return $this->errorResponse(500, 'IMPORT_FAILED', 'L\'import a échoué de façon inattendue : rien n\'a été enregistré. Réessayez, ou contactez un administrateur si cela persiste.');
        }

        if ($report['hasPriceChange']) {
            $this->pinGate()->reset($actorSessionId);
        }

        return $this->okResponse($result);
    }

    /**
     * Adapte un corps JSON au tableau de chaines attendu par `validate()`.
     *
     * `price_cents` est un ENTIER STRICT de centimes (RG-T18) : un flottant
     * (`590.9`), une chaine ("12,50", meme si elle ressemble a un montant en
     * euros) ou tout non-entier sont refuses (422), jamais tronques ni relus
     * comme des euros. Un entier valide est reencode en euros
     * (`Money::centsToEuros`) pour traverser SANS DIVERGENCE la meme regle de
     * bornes que le formulaire HTML (`Money::parseEurosToCents`).
     *
     * Les autres champs passent par `scalarForm()` (rejet nomme d'un
     * tableau/objet). `is_available` : `$current` (fourni uniquement sur PUT)
     * fait PRESERVER la valeur actuelle si le champ est absent du corps (PUT
     * PARTIEL, meme regle que `is_active` sur users/roles -- relecture point 6) ;
     * sur POST (`$current === null`), absent -> `false` par defaut.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed>|null $current
     * @return array{0: array<string, string>, 1: array<string, string>} [form, erreurs]
     */
    private function toForm(array $body, ?array $current = null): array
    {
        [$form, $errors] = $this->scalarForm($body, [
            'category_id'             => '',
            'name'                    => '',
            'description'             => '',
            'vat_rate'                => '',
            'image_path'              => '',
            'display_order'           => '0',
            'size_cl'                 => '',
            'base_product_id'         => '',
            'maxi_variant_product_id' => '',
        ]);

        // fieldStrictJsonInt() : type JSON natif `int` uniquement (jamais une
        // chaine ni un flottant) -- aucun equivalent formulaire HTML pour ce champ.
        $priceCents = $this->fieldStrictJsonInt($body, 'price_cents');
        if ($priceCents instanceof Response) {
            $errors['price_cents'] = 'price_cents doit être un entier (centimes), pas un texte ni un nombre décimal.';
            $form['price_cents'] = '';
        } else {
            $form['price_cents'] = $priceCents === null ? '' : Money::centsToEuros($priceCents);
        }

        $currentAvailable = (int) ($current['is_available'] ?? 0) === 1;
        $isAvailable = $current === null
            ? $this->fieldBoolCreate($body, 'is_available', false)
            : $this->fieldBoolPreserving($body, 'is_available', $currentAvailable);
        if ($isAvailable instanceof Response) {
            $errors['is_available'] = 'Ce champ doit être un booléen JSON strict (true ou false), pas une chaîne, un nombre, un tableau ni un objet.';
            $form['is_available'] = '';
        } else {
            $form['is_available'] = $isAvailable ? '1' : '';
        }

        return [$form, $errors];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id'                      => (int) ($row['id'] ?? 0),
            'category_id'             => (int) ($row['category_id'] ?? 0),
            'name'                    => (string) ($row['name'] ?? ''),
            'description'             => ($row['description'] ?? null) !== null ? (string) $row['description'] : null,
            'price_cents'             => (int) ($row['price_cents'] ?? 0),
            'vat_rate'                => (int) ($row['vat_rate'] ?? 0),
            'size_cl'                 => ($row['size_cl'] ?? null) !== null ? (int) $row['size_cl'] : null,
            'base_product_id'         => ($row['base_product_id'] ?? null) !== null ? (int) $row['base_product_id'] : null,
            'maxi_variant_product_id' => ($row['maxi_variant_product_id'] ?? null) !== null ? (int) $row['maxi_variant_product_id'] : null,
            'image_path'              => ($row['image_path'] ?? null) !== null ? (string) $row['image_path'] : null,
            'is_available'            => (int) ($row['is_available'] ?? 0) === 1,
            'display_order'           => (int) ($row['display_order'] ?? 0),
        ];
    }
}
