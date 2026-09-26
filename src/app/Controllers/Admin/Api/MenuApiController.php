<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Api;

use PDOException;
use App\Catalogue\MenuRepository;
use App\Controllers\MenuController;
use App\Core\DatabaseInterface;
use App\Core\Money;
use App\Core\Response;

/**
 * CRUD JSON des menus composes (`/admin/api/menus`), memes permissions et memes
 * regles que `MenuController` (HTML), reutilisees par heritage (`validate()`,
 * `menuRepository()`) :
 *  - create (`menu.create`) / update (`menu.update`) : sans PIN ;
 *  - delete (`menu.delete`) : PIN equipier + audit, `409 CONFLICT` si le menu est
 *    encore reference par des commandes (FK RESTRICT).
 *
 * La configuration de slots, soumise en formulaire HTML comme un champ cache
 * `slots_json` (une structure imbriquee ne passe pas par `Request::formBody()`,
 * qui ne retient que les scalaires), est ici un tableau JSON natif `slots` :
 * reencode en chaine pour traverser exactement la meme garde serveur
 * (`parseSlots()`, allowlist RG-T16, categories eligibles F12) sans divergence.
 *
 * Non `final` : les tests sous-classent pour injecter des doubles, meme
 * convention que le reste des controleurs admin. Seam de test via les hooks
 * proteges herites de MenuController/AuthenticatedController.
 */
class MenuApiController extends MenuController
{
    use JsonApiTrait;

    /**
     * @param array<string, string> $params
     */
    public function apiIndex(array $params = []): Response
    {
        $guard = $this->guardApi('menu.read');
        if ($guard instanceof Response) {
            return $guard;
        }

        $rows = array_map([$this, 'present'], $this->menuRepository()->all());

        return $this->collectionResponse($rows);
    }

    /**
     * @param array<string, string> $params
     */
    public function apiShow(array $params): Response
    {
        $guard = $this->guardApi('menu.read');
        if ($guard instanceof Response) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $menu = $this->menuRepository()->find($id);
        if ($menu === null) {
            return $this->notFoundResponse();
        }

        $data = $this->present($menu);
        $data['slots'] = $this->presentSlots($this->menuRepository()->slotsWithOptions($id));

        return $this->okResponse($data);
    }

    /**
     * @param array<string, string> $params
     */
    public function apiStore(array $params = []): Response
    {
        $guard = $this->guardApi('menu.create');
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
        [$data, $slots, $errors] = $this->validate($form);
        $errors = $formErrors + $errors;
        if ($errors !== []) {
            return $this->validationErrorResponse($errors);
        }

        $id = $this->menuRepository()->create($data, $slots);

        return $this->createdResponse($this->present((array) $this->menuRepository()->find($id)), '/admin/api/menus/' . $id);
    }

    /**
     * @param array<string, string> $params
     */
    public function apiUpdate(array $params): Response
    {
        $guard = $this->guardApi('menu.update');
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
        $repo = $this->menuRepository();
        $current = $repo->find($id);
        if ($current === null) {
            return $this->notFoundResponse();
        }

        [$form, $formErrors] = $this->toForm($body, $current);
        [$data, $slots, $errors] = $this->validate($form);
        $errors = $formErrors + $errors;
        if ($errors !== []) {
            return $this->validationErrorResponse($errors);
        }

        $repo->update($id, $data, $slots);

        return $this->okResponse($this->present((array) $repo->find($id)));
    }

    /**
     * Bascule la disponibilite du menu (equivalent du bouton HTML). Meme permission
     * (`menu.update`), sans PIN.
     *
     * @param array<string, string> $params
     */
    public function apiToggle(array $params): Response
    {
        $guard = $this->guardApi('menu.update');
        if ($guard instanceof Response) {
            return $guard;
        }
        if (($csrf = $this->requireCsrf()) !== null) {
            return $csrf;
        }

        $id = (int) ($params['id'] ?? 0);
        $repo = $this->menuRepository();
        $menu = $repo->find($id);
        if ($menu === null) {
            return $this->notFoundResponse();
        }

        $newAvailable = (int) ($menu['is_available'] ?? 0) !== 1;
        $repo->setActive($id, $newAvailable);

        return $this->okResponse(['id' => $id, 'is_available' => $newAvailable]);
    }

    /**
     * @param array<string, string> $params
     */
    public function apiDestroy(array $params): Response
    {
        $guard = $this->guardApi('menu.delete');
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
        $repo = $this->menuRepository();
        $menu = $repo->find($id);
        if ($menu === null) {
            return $this->notFoundResponse();
        }

        [$email, $pin] = $this->pinFields($body);
        $actorSessionId = $guard->userId ?? 0;
        $actor = $this->pinGate()->resolve($actorSessionId, $email, $pin, 'menu', $id);
        if ($actor === null) {
            return $this->pinErrorResponse('Email ou PIN invalide (requis pour supprimer).');
        }

        $name = (string) ($menu['name'] ?? '');

        try {
            $this->db()->transaction(function (DatabaseInterface $db) use ($id, $actor, $name): void {
                $deleted = (new MenuRepository($db))->delete($id);
                if ($deleted === 1) {
                    $this->pinGate()->writeAudit($db, 'menu.delete', $actor['id'], $actor['role_id'], 'menu', $id, 'Suppression menu: ' . $name);
                }
            });
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return $this->conflictResponse('Menu référencé par des commandes : suppression impossible. Désactivez-le plutôt.');
            }

            throw $exception;
        }

        $this->pinGate()->reset($actorSessionId);

        return $this->okResponse(['id' => $id, 'status' => 'deleted']);
    }

    /**
     * Adapte un corps JSON au tableau de chaines attendu par `validate()`.
     * `price_normal_cents`/`price_maxi_cents` sont des ENTIERS STRICTS de
     * centimes (meme regle que `ProductApiController::toForm()`, RG-T18) : un
     * flottant ou une chaine en euros sont refuses (422), jamais tronques ni
     * relus comme des euros. `slots` doit rester un tableau JSON (sinon erreur
     * nommee) ; il est reencode en chaine pour `parseSlots()` (allowlist F12).
     *
     * @param array<string, mixed> $body
     * @return array{0: array<string, string>, 1: array<string, string>} [form, erreurs]
     */
    /**
     * `$current` (fourni uniquement sur PUT) fait PRESERVER `is_available` si le
     * champ est absent du corps (PUT PARTIEL, meme regle que `is_active` sur
     * users/roles -- relecture point 6). Sur POST, absent -> `false` par defaut.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed>|null $current
     * @return array{0: array<string, string>, 1: array<string, string>} [form, erreurs]
     */
    private function toForm(array $body, ?array $current = null): array
    {
        [$form, $errors] = $this->scalarForm($body, [
            'category_id'       => '',
            'burger_product_id' => '',
            'name'              => '',
            'display_order'     => '0',
        ]);

        foreach (['price_normal_cents', 'price_maxi_cents'] as $key) {
            // fieldStrictJsonInt() : type JSON natif `int` uniquement -- aucun
            // equivalent formulaire HTML pour ce champ.
            $value = $this->fieldStrictJsonInt($body, $key);
            if ($value instanceof Response) {
                $errors[$key] = $key . ' doit être un entier (centimes), pas un texte ni un nombre décimal.';
                $form[$key] = '';
            } else {
                $form[$key] = $value === null ? '' : Money::centsToEuros($value);
            }
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

        $slotsRaw = $this->fieldList($body, 'slots');
        if ($slotsRaw instanceof Response) {
            $errors['slots'] = 'slots doit être un tableau.';
            $slotsRaw = [];
        }
        $form['slots_json'] = (string) json_encode($slotsRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [$form, $errors];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id'                  => (int) ($row['id'] ?? 0),
            'category_id'         => (int) ($row['category_id'] ?? 0),
            'burger_product_id'   => (int) ($row['burger_product_id'] ?? 0),
            'name'                => (string) ($row['name'] ?? ''),
            'price_normal_cents'  => (int) ($row['price_normal_cents'] ?? 0),
            'price_maxi_cents'    => (int) ($row['price_maxi_cents'] ?? 0),
            'is_available'        => (int) ($row['is_available'] ?? 0) === 1,
            'display_order'       => (int) ($row['display_order'] ?? 0),
        ];
    }

    /**
     * @param list<array{id:int, name:string, slot_type:string, is_required:int, display_order:int, option_product_ids:list<int>}> $slots
     * @return list<array<string, mixed>>
     */
    private function presentSlots(array $slots): array
    {
        return array_map(static fn (array $s): array => [
            'name'         => (string) $s['name'],
            'slot_type'    => (string) $s['slot_type'],
            'is_required'  => (int) $s['is_required'] === 1,
            'options'      => array_values(array_map('intval', $s['option_product_ids'])),
        ], $slots);
    }
}
