<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Api;

use PDOException;
use App\Controllers\CategoryController;
use App\Core\Response;

/**
 * CRUD JSON des categories (`/admin/api/categories`), meme permission
 * `category.manage` et memes regles que `CategoryController` (HTML), reutilisees
 * par heritage (`validate()`, `categoryRepository()`). Aucun PIN : la gestion de
 * categorie n'est pas dans l'ensemble sensible RG-T13.
 *
 * Pas de suppression dure (FK RESTRICT si des produits/menus referencent la
 * categorie) : `DELETE` bascule `is_active = 0`, meme semantique que le bouton
 * "Masquer" du back-office (`toggle()`), documentee dans docs/api/conventions.md.
 *
 * Non `final` : les tests sous-classent pour injecter des doubles, meme
 * convention que le reste des controleurs admin. Seam de test via les hooks
 * proteges herites de CategoryController/AuthenticatedController.
 */
class CategoryApiController extends CategoryController
{
    use JsonApiTrait;

    /**
     * @param array<string, string> $params
     */
    public function apiIndex(array $params = []): Response
    {
        $guard = $this->guardApi('category.manage');
        if ($guard instanceof Response) {
            return $guard;
        }

        $rows = array_map([$this, 'present'], $this->categoryRepository()->all());

        return $this->collectionResponse($rows);
    }

    /**
     * @param array<string, string> $params
     */
    public function apiShow(array $params): Response
    {
        $guard = $this->guardApi('category.manage');
        if ($guard instanceof Response) {
            return $guard;
        }

        $category = $this->categoryRepository()->find((int) ($params['id'] ?? 0));
        if ($category === null) {
            return $this->notFoundResponse();
        }

        return $this->okResponse($this->present($category));
    }

    /**
     * @param array<string, string> $params
     */
    public function apiStore(array $params = []): Response
    {
        $guard = $this->guardApi('category.manage');
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

        $repo = $this->categoryRepository();
        [$form, $formErrors] = $this->toForm($body);
        [$data, $errors] = $this->validate($form, $repo, 0);
        $errors = $formErrors + $errors;
        if ($errors !== []) {
            return $this->validationErrorResponse($errors);
        }

        try {
            $repo->create($data);
        } catch (PDOException $exception) {
            return $this->onConflict($exception);
        }

        $id = $this->lastInsertId();
        $category = $repo->find($id);

        return $this->createdResponse($category !== null ? $this->present($category) : null, '/admin/api/categories/' . $id);
    }

    /**
     * @param array<string, string> $params
     */
    public function apiUpdate(array $params): Response
    {
        $guard = $this->guardApi('category.manage');
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
        $repo = $this->categoryRepository();
        if ($repo->find($id) === null) {
            return $this->notFoundResponse();
        }

        [$form, $formErrors] = $this->toForm($body);
        [$data, $errors] = $this->validate($form, $repo, $id);
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
     * Pas de suppression dure (cf. docblock de classe) : bascule `is_active = 0`,
     * idempotente (une categorie deja masquee reste masquee, pas d'erreur).
     *
     * @param array<string, string> $params
     */
    public function apiDestroy(array $params): Response
    {
        $guard = $this->guardApi('category.manage');
        if ($guard instanceof Response) {
            return $guard;
        }
        if (($csrf = $this->requireCsrf()) !== null) {
            return $csrf;
        }

        $id = (int) ($params['id'] ?? 0);
        $repo = $this->categoryRepository();
        if ($repo->find($id) === null) {
            return $this->notFoundResponse();
        }

        $repo->setActive($id, false);

        return $this->okResponse(['id' => $id, 'status' => 'deactivated']);
    }

    /**
     * Bascule l'etat visible/masque (equivalent du bouton "Afficher"/"Masquer" HTML,
     * dans les deux sens contrairement a `DELETE` qui ne masque que). Meme permission,
     * sans PIN.
     *
     * @param array<string, string> $params
     */
    public function apiToggle(array $params): Response
    {
        $guard = $this->guardApi('category.manage');
        if ($guard instanceof Response) {
            return $guard;
        }
        if (($csrf = $this->requireCsrf()) !== null) {
            return $csrf;
        }

        $id = (int) ($params['id'] ?? 0);
        $repo = $this->categoryRepository();
        $category = $repo->find($id);
        if ($category === null) {
            return $this->notFoundResponse();
        }

        $newActive = (int) ($category['is_active'] ?? 0) !== 1;
        $repo->setActive($id, $newActive);

        return $this->okResponse(['id' => $id, 'is_active' => $newActive]);
    }

    /**
     * Deplace une categorie d'un rang (`direction`: "up"|"down"). Meme permission,
     * sans PIN (ecriture protegee par CSRF comme toute mutation).
     *
     * @param array<string, string> $params
     */
    public function apiMove(array $params): Response
    {
        $guard = $this->guardApi('category.manage');
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
        $repo = $this->categoryRepository();
        if ($repo->find($id) === null) {
            return $this->notFoundResponse();
        }

        $moved = $repo->reorder($id, $direction);

        return $this->okResponse(['id' => $id, 'moved' => $moved]);
    }

    /**
     * Adapte un corps JSON au tableau de chaines attendu par `validate()`
     * (reutilise tel quel : meme regles, memes messages que le formulaire HTML).
     * Un champ non scalaire (tableau/objet) devient une erreur nommee (via
     * `JsonApiTrait::scalarForm()`), jamais un cast silencieux en `"Array"`.
     *
     * @param array<string, mixed> $body
     * @return array{0: array<string, string>, 1: array<string, string>} [form, erreurs]
     */
    private function toForm(array $body): array
    {
        return $this->scalarForm($body, [
            'name'          => '',
            'slug'          => '',
            'image_path'    => '',
            'display_order' => '0',
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id'            => (int) ($row['id'] ?? 0),
            'name'          => (string) ($row['name'] ?? ''),
            'slug'          => (string) ($row['slug'] ?? ''),
            'image_path'    => ($row['image_path'] ?? null) !== null ? (string) $row['image_path'] : null,
            'display_order' => (int) ($row['display_order'] ?? 0),
            'is_active'     => (int) ($row['is_active'] ?? 0) === 1,
        ];
    }

    private function onConflict(PDOException $exception): Response
    {
        if ((string) $exception->getCode() === '23000') {
            return $this->conflictResponse('Ce libellé ou cette référence existe déjà.');
        }

        throw $exception;
    }
}
