<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Api;

use PDOException;
use App\Auth\RoleRepository;
use App\Controllers\RoleController;
use App\Core\DatabaseInterface;
use App\Core\Response;

/**
 * CRUD JSON du RBAC (`/admin/api/roles`), meme permission `role.manage` et memes
 * regles que `RoleController` (HTML), reutilisees par heritage (`validate()`,
 * `selectedPermissionIds()`, `selectedSources()`, `codesForIds()`,
 * `roleRepository()`). Operation a fort impact (escalade de privileges) : PIN
 * equipier + audit (diff de permissions dans `details`) sur create ET update.
 *
 * Les cases de la matrice sont soumises en formulaire HTML comme des champs
 * scalaires `perm_<id>` / `source_<enum>` (`Request::formBody` ne conserve que les
 * scalaires) ; l'API JSON accepte des tableaux natifs `permission_ids` /
 * `visible_sources`, reencodes en ces memes cles synthetiques pour traverser
 * exactement la meme garde serveur sans divergence.
 *
 * Pas de `DELETE` : `RoleController` (HTML) n'expose aucune suppression de role
 * (risque de casser les comptes qui y sont rattaches) ; l'API JSON ne l'invente
 * pas non plus (limite documentee dans docs/api/conventions.md).
 *
 * Non `final` : les tests sous-classent pour injecter des doubles, meme
 * convention que le reste des controleurs admin. Seam de test via les hooks
 * proteges herites de RoleController/AuthenticatedController.
 */
class RoleApiController extends RoleController
{
    use JsonApiTrait;

    private const ADMIN_CODE = 'admin';

    /**
     * @param array<string, string> $params
     */
    public function apiIndex(array $params = []): Response
    {
        $guard = $this->guardApi('role.manage');
        if ($guard instanceof Response) {
            return $guard;
        }

        $rows = array_map([$this, 'present'], $this->roleRepository()->allRoles());

        return $this->collectionResponse($rows);
    }

    /**
     * @param array<string, string> $params
     */
    public function apiShow(array $params): Response
    {
        $guard = $this->guardApi('role.manage');
        if ($guard instanceof Response) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $repo = $this->roleRepository();
        $role = $repo->findRole($id);
        if ($role === null) {
            return $this->notFoundResponse();
        }

        $data = $this->present($role);
        $data['permission_ids'] = $repo->permissionIdsFor($id);
        $data['permissions'] = $repo->permissionCodesFor($id);
        $data['visible_sources'] = $repo->visibleSources($id);

        return $this->okResponse($data);
    }

    /**
     * @param array<string, string> $params
     */
    public function apiStore(array $params = []): Response
    {
        $guard = $this->guardApi('role.manage');
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
        [$data, $errors] = $this->validate($form, true);
        $errors = $formErrors + $errors;
        $permIds = $this->selectedPermissionIds($form);
        $sources = $this->selectedSources($form);
        if ($errors !== []) {
            return $this->validationErrorResponse($errors);
        }
        if ($this->roleRepository()->codeExists((string) $data['code'])) {
            return $this->conflictResponse('Ce code de rôle existe déjà.');
        }

        [$email, $pin] = $this->pinFields($body);
        $actorSessionId = $guard->userId ?? 0;
        $actor = $this->pinGate()->resolve($actorSessionId, $email, $pin, 'role', 0);
        if ($actor === null) {
            return $this->pinErrorResponse('Email ou PIN invalide (requis pour créer un rôle).');
        }

        $addedCodes = $this->codesForIds($permIds);
        $newId = 0;
        try {
            $this->db()->transaction(function (DatabaseInterface $db) use ($data, $permIds, $sources, $actor, $addedCodes, &$newId): void {
                $repo = new RoleRepository($db);
                $newId = $repo->createRole([
                    'code'          => (string) $data['code'],
                    'label'         => (string) $data['label'],
                    'description'   => $data['description'],
                    'default_route' => $data['default_route'],
                    'order_source'  => $data['order_source'],
                ]);
                $repo->replacePermissions($db, $newId, $permIds);
                $repo->replaceVisibleSources($db, $newId, $sources);
                $this->pinGate()->writeAudit($db, 'role.manage', $actor['id'], $actor['role_id'], 'role', $newId, 'Création rôle ' . (string) $data['code'], ['added' => $addedCodes, 'removed' => []]);
            });
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return $this->conflictResponse('Ce code de rôle existe déjà.');
            }

            throw $exception;
        }

        $this->pinGate()->reset($actorSessionId);
        $repo = $this->roleRepository();
        $role = $repo->findRole($newId);
        $data = $role !== null ? $this->present($role) : null;
        if ($data !== null) {
            $data['permission_ids'] = $permIds;
            $data['visible_sources'] = $sources;
        }

        return $this->createdResponse($data, '/admin/api/roles/' . $newId);
    }

    /**
     * @param array<string, string> $params
     */
    public function apiUpdate(array $params): Response
    {
        $guard = $this->guardApi('role.manage');
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
        $repo = $this->roleRepository();
        $current = $repo->findRole($id);
        if ($current === null) {
            return $this->notFoundResponse();
        }

        [$form, $formErrors] = $this->toForm($body);
        [$data, $errors] = $this->validate($form, false);
        $errors = $formErrors + $errors;
        $permIds = $this->selectedPermissionIds($form);
        $sources = $this->selectedSources($form);
        if ($errors !== []) {
            return $this->validationErrorResponse($errors);
        }

        // PUT PARTIEL (documente en section 5.3 de conventions.md) : is_active
        // absent du corps CONSERVE la valeur actuelle, comme pour les utilisateurs.
        // Booleen JSON STRICT : la chaine "false" est refusee (422), jamais lue
        // comme un booleen truthy par un `!empty()` naif.
        $isActiveBool = $this->fieldBoolPreserving($body, 'is_active', (int) ($current['is_active'] ?? 1) === 1);
        if ($isActiveBool instanceof Response) {
            return $isActiveBool;
        }
        $isActive = $isActiveBool ? 1 : 0;
        $newCodes = $this->codesForIds($permIds);

        // Garde-fou anti-lockout : le role admin garde role.manage ET reste actif.
        if ((string) ($current['code'] ?? '') === self::ADMIN_CODE) {
            if (!in_array('role.manage', $newCodes, true) || $isActive === 0) {
                return $this->validationErrorResponse(['permissions' => 'Le rôle administrateur doit conserver role.manage et rester actif.']);
            }
        }

        [$email, $pin] = $this->pinFields($body);
        $actorSessionId = $guard->userId ?? 0;
        $actor = $this->pinGate()->resolve($actorSessionId, $email, $pin, 'role', $id);
        if ($actor === null) {
            return $this->pinErrorResponse('Email ou PIN invalide (requis pour modifier un rôle).');
        }

        $currentCodes = $repo->permissionCodesFor($id);
        $added = array_values(array_diff($newCodes, $currentCodes));
        $removed = array_values(array_diff($currentCodes, $newCodes));

        $this->db()->transaction(function (DatabaseInterface $db) use ($id, $data, $isActive, $permIds, $sources, $actor, $added, $removed, $current): void {
            $repo = new RoleRepository($db);
            $repo->updateRole($id, [
                'label'         => (string) $data['label'],
                'description'   => $data['description'],
                'default_route' => $data['default_route'],
                'order_source'  => $data['order_source'],
                'is_active'     => $isActive,
            ]);
            $repo->replacePermissions($db, $id, $permIds);
            $repo->replaceVisibleSources($db, $id, $sources);
            $this->pinGate()->writeAudit($db, 'role.manage', $actor['id'], $actor['role_id'], 'role', $id, 'Mise à jour RBAC rôle ' . (string) ($current['code'] ?? ''), ['added' => $added, 'removed' => $removed]);
        });

        $this->pinGate()->reset($actorSessionId);
        $role = $repo->findRole($id);
        $out = $role !== null ? $this->present($role) : null;
        if ($out !== null) {
            $out['permission_ids'] = $permIds;
            $out['visible_sources'] = $sources;
        }

        return $this->okResponse($out);
    }

    /**
     * Adapte un corps JSON au tableau de chaines attendu par `validate()` /
     * `selectedPermissionIds()` / `selectedSources()` : `permission_ids` (liste
     * d'ids) et `visible_sources` (liste d'enum) sont reencodes en cles
     * synthetiques `perm_<id>` / `source_<enum>`, meme convention que le
     * formulaire HTML (matrice de cases a cocher). Les champs scalaires passent
     * par `scalarForm()` (rejet nomme d'un tableau/objet) ; `permission_ids`/
     * `visible_sources` doivent eux-memes rester des tableaux (sinon erreur
     * nommee), l'inverse de la regle des champs scalaires.
     *
     * @param array<string, mixed> $body
     * @return array{0: array<string, string>, 1: array<string, string>} [form, erreurs]
     */
    private function toForm(array $body): array
    {
        [$form, $errors] = $this->scalarForm($body, [
            'code'          => '',
            'label'         => '',
            'description'   => '',
            'default_route' => '',
            'order_source'  => '',
        ]);

        // fieldIntList() : entiers STRICTS (point 6) -- "1.9" est refuse (422),
        // jamais tronque en silence a 1 par un ancien `is_numeric()`+`(int)`.
        $permissionIds = $this->fieldIntList($body, 'permission_ids', 1, 2147483647);
        if ($permissionIds instanceof Response) {
            $errors['permission_ids'] = 'permission_ids doit être une liste d\'identifiants entiers.';
            $permissionIds = [];
        } else {
            foreach ($permissionIds as $permId) {
                $form['perm_' . $permId] = '1';
            }
        }

        $visibleSources = $this->fieldList($body, 'visible_sources');
        if ($visibleSources instanceof Response) {
            $errors['visible_sources'] = 'visible_sources doit être un tableau.';
            $visibleSources = [];
        }
        foreach ($visibleSources as $source) {
            if (is_string($source)) {
                $form['source_' . $source] = '1';
            }
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
            'id'            => (int) ($row['id'] ?? 0),
            'code'          => (string) ($row['code'] ?? ''),
            'label'         => (string) ($row['label'] ?? ''),
            'description'   => ($row['description'] ?? null) !== null ? (string) $row['description'] : null,
            'default_route' => ($row['default_route'] ?? null) !== null ? (string) $row['default_route'] : null,
            'order_source'  => ($row['order_source'] ?? null) !== null ? (string) $row['order_source'] : null,
            'is_active'     => (int) ($row['is_active'] ?? 0) === 1,
        ];
    }
}
