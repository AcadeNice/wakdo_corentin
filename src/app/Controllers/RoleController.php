<?php

declare(strict_types=1);

namespace App\Controllers;

use PDOException;
use App\Auth\Csrf;
use App\Auth\GuardResult;
use App\Auth\PasswordHasher;
use App\Auth\PinThrottle;
use App\Auth\PinVerifier;
use App\Auth\RoleRepository;
use App\Core\DatabaseInterface;
use App\Core\Response;

/**
 * Gestion RBAC (mlt 10.4 MANAGE_RBAC), permission `role.manage`. Operations a fort
 * impact (escalade de privileges) : PIN equipier + ligne `audit_log` dans la meme
 * transaction que l'effet (RG-T13/RG-T14), throttle PIN par acteur (RG-T22). Le
 * `details` d'audit enregistre le DIFF de permissions (codes ajoutes/retires, RG-6),
 * calcule AVANT la reecriture delete-and-reinsert.
 *
 * Le catalogue de permissions est fige au seed (lecture seule). Le `code` d'un role
 * est immuable apres creation. Garde-fou anti-lockout : le role `admin` conserve
 * toujours `role.manage` et reste actif.
 *
 * Les cases de la matrice sont soumises en champs SCALAIRES (`perm_<id>`,
 * `source_<enum>`) et non en tableaux `name[]` : Request::formBody ne conserve que
 * les scalaires (pas de JS requis, pas de champ JSON cache).
 *
 * Non `final` : les tests sous-classent (seam db()/sessionManager()).
 */
class RoleController extends AdminController
{
    private const ENTITY = 'role';
    private const ADMIN_CODE = 'admin';

    /** @var list<string> ENUM role_visible_source.source / customer_order.source */
    private const SOURCES = ['kiosk', 'counter', 'drive'];

    /**
     * @param array<string, string> $params
     */
    public function index(array $params = []): Response
    {
        $guard = $this->guard('role.manage');
        if ($guard instanceof Response) {
            return $guard;
        }

        return $this->adminView('admin/roles/index', [
            'title'     => 'Roles et permissions - Wakdo Admin',
            'activeNav' => 'roles',
            'roles'     => $this->roleRepository()->allRoles(),
        ], $guard);
    }

    /**
     * @param array<string, string> $params
     */
    public function create(array $params = []): Response
    {
        $guard = $this->guard('role.manage');
        if ($guard instanceof Response) {
            return $guard;
        }

        return $this->renderForm($guard, 0, [], [], [], []);
    }

    /**
     * @param array<string, string> $params
     */
    public function store(array $params = []): Response
    {
        $guard = $this->guard('role.manage');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        [$data, $errors] = $this->validate($form, true);
        $permIds = $this->selectedPermissionIds($form);
        $sources = $this->selectedSources($form);
        if ($errors !== []) {
            return $this->renderForm($guard, 0, $form, $errors, $permIds, $sources, 422);
        }
        if ($this->roleRepository()->codeExists((string) $data['code'])) {
            return $this->renderForm($guard, 0, $form, ['code' => 'Ce code de role existe deja.'], $permIds, $sources, 409);
        }

        [$actor, $errorMsg] = $this->resolvePin($guard, $form, 0);
        if ($actor === null) {
            return $this->renderForm($guard, 0, $form, ['pin' => $errorMsg], $permIds, $sources, 422);
        }

        $addedCodes = $this->codesForIds($permIds);
        try {
            $this->db()->transaction(function (DatabaseInterface $db) use ($data, $permIds, $sources, $actor, $addedCodes): void {
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
                $this->writeAudit($db, $actor['id'], $actor['role_id'], $newId, 'Creation role ' . (string) $data['code'], ['added' => $addedCodes, 'removed' => []]);
            });
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return $this->renderForm($guard, 0, $form, ['code' => 'Ce code de role existe deja.'], $permIds, $sources, 409);
            }

            throw $exception;
        }

        $this->pinThrottle()->reset($guard->userId ?? 0);
        $this->setFlash('Role cree.');

        return $this->redirect('/admin/roles');
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(array $params): Response
    {
        $guard = $this->guard('role.manage');
        if ($guard instanceof Response) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $role = $this->roleRepository()->findRole($id);
        if ($role === null) {
            return $this->notFound($guard);
        }

        return $this->renderForm(
            $guard,
            $id,
            $role,
            [],
            $this->roleRepository()->permissionIdsFor($id),
            $this->roleRepository()->visibleSources($id),
        );
    }

    /**
     * @param array<string, string> $params
     */
    public function update(array $params): Response
    {
        $guard = $this->guard('role.manage');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $id = (int) ($params['id'] ?? 0);
        $current = $this->roleRepository()->findRole($id);
        if ($current === null) {
            return $this->notFound($guard);
        }

        [$data, $errors] = $this->validate($form, false);
        $permIds = $this->selectedPermissionIds($form);
        $sources = $this->selectedSources($form);
        if ($errors !== []) {
            return $this->renderForm($guard, $id, $form + ['code' => $current['code']], $errors, $permIds, $sources, 422);
        }

        $isActive = isset($form['is_active']) ? 1 : 0;
        $newCodes = $this->codesForIds($permIds);

        // Garde-fou anti-lockout : le role admin garde role.manage ET reste actif.
        if ((string) ($current['code'] ?? '') === self::ADMIN_CODE) {
            if (!in_array('role.manage', $newCodes, true) || $isActive === 0) {
                return $this->renderForm($guard, $id, $form + ['code' => $current['code']], ['permissions' => 'Le role administrateur doit conserver role.manage et rester actif.'], $permIds, $sources, 422);
            }
        }

        [$actor, $errorMsg] = $this->resolvePin($guard, $form, $id);
        if ($actor === null) {
            return $this->renderForm($guard, $id, $form + ['code' => $current['code']], ['pin' => $errorMsg], $permIds, $sources, 422);
        }

        // Diff de permissions (RG-6), calcule AVANT la reecriture.
        $currentCodes = $this->roleRepository()->permissionCodesFor($id);
        $added = array_values(array_diff($newCodes, $currentCodes));
        $removed = array_values(array_diff($currentCodes, $newCodes));

        $this->db()->transaction(function (DatabaseInterface $db) use ($id, $data, $isActive, $permIds, $sources, $actor, $added, $removed): void {
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
            $this->writeAudit($db, $actor['id'], $actor['role_id'], $id, 'Mise a jour RBAC role ' . (string) ($data['code'] ?? ''), ['added' => $added, 'removed' => $removed]);
        });

        $this->pinThrottle()->reset($guard->userId ?? 0);
        $this->setFlash('Role mis a jour.');

        return $this->redirect('/admin/roles');
    }

    // --- Helpers ---

    protected function roleRepository(): RoleRepository
    {
        return new RoleRepository($this->db());
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
     * @param array<string, string> $form
     * @return list<int>
     */
    private function selectedPermissionIds(array $form): array
    {
        $ids = [];
        foreach ($this->roleRepository()->allPermissions() as $p) {
            $pid = (int) ($p['id'] ?? 0);
            if ($pid > 0 && ($form['perm_' . $pid] ?? '') !== '') {
                $ids[] = $pid;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, string> $form
     * @return list<string>
     */
    private function selectedSources(array $form): array
    {
        $out = [];
        foreach (self::SOURCES as $source) {
            if (($form['source_' . $source] ?? '') !== '') {
                $out[] = $source;
            }
        }

        return $out;
    }

    /**
     * Codes de permission correspondant a une liste d'ids (via le catalogue).
     *
     * @param list<int> $ids
     * @return list<string>
     */
    private function codesForIds(array $ids): array
    {
        $map = [];
        foreach ($this->roleRepository()->allPermissions() as $p) {
            $map[(int) ($p['id'] ?? 0)] = (string) ($p['code'] ?? '');
        }
        $codes = [];
        foreach ($ids as $id) {
            if (isset($map[$id]) && $map[$id] !== '') {
                $codes[] = $map[$id];
            }
        }

        return $codes;
    }

    /**
     * Porte du PIN sensible (RG-T13 + throttle RG-T22), identique a UserController.
     *
     * @param array<string, string> $form
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private function resolvePin(GuardResult $guard, array $form, int $entityId): array
    {
        $generic = 'Email ou PIN invalide (requis pour cette action).';
        $actorId = $guard->userId ?? 0;

        if ($actorId > 0 && $this->pinThrottle()->isLocked($actorId)) {
            $this->pinVerifier()->payTimingDecoy($form['pin'] ?? '');

            return [null, $generic];
        }

        $actor = $this->pinVerifier()->resolveActingUser(trim($form['pin_email'] ?? ''), $form['pin'] ?? '');
        if ($actor === null) {
            $email = trim($form['pin_email'] ?? '');
            $this->db()->transaction(function (DatabaseInterface $db) use ($email, $entityId, $actorId): void {
                $this->logFailedPin($db, $email, $entityId);
                $this->pinThrottle()->recordFailureWithin($db, $actorId);
            });

            return [null, $generic];
        }

        return [$actor, ''];
    }

    /**
     * Validation serveur (RG-T18). `code` requis + immuable a la creation seulement.
     *
     * @param array<string, string> $form
     * @return array{0: array{code: ?string, label: string, description: ?string, default_route: ?string, order_source: ?string}, 1: array<string, string>}
     */
    private function validate(array $form, bool $isCreate): array
    {
        $errors = [];

        $code = null;
        if ($isCreate) {
            $code = trim($form['code'] ?? '');
            if ($code === '' || mb_strlen($code) > 40 || preg_match('/^[a-z][a-z0-9_]{1,39}$/', $code) !== 1) {
                $errors['code'] = 'Code requis : minuscules/chiffres/_ , commence par une lettre (40 max).';
            }
        }

        $label = trim($form['label'] ?? '');
        if ($label === '' || mb_strlen($label) > 80) {
            $errors['label'] = 'Le libelle est requis (80 caracteres max).';
        }

        $route = trim($form['default_route'] ?? '');
        if (mb_strlen($route) > 120) {
            $errors['default_route'] = 'Route par defaut trop longue (120 max).';
        }

        $source = trim($form['order_source'] ?? '');
        if ($source !== '' && !in_array($source, self::SOURCES, true)) {
            $errors['order_source'] = 'Source de commande invalide.';
        }

        $description = trim($form['description'] ?? '');

        $data = [
            'code'          => $code,
            'label'         => $label,
            'description'   => $description !== '' ? $description : null,
            'default_route' => $route !== '' ? $route : null,
            'order_source'  => $source !== '' ? $source : null,
        ];

        return [$data, $errors];
    }

    private function logFailedPin(DatabaseInterface $db, string $email, int $entityId): void
    {
        $db->execute(
            'INSERT INTO audit_log (actor_user_id, actor_role_id, action_code, entity_type, entity_id, summary) '
            . 'VALUES (:uid, :rid, :code, :etype, :eid, :summary)',
            [
                'uid'     => null,
                'rid'     => null,
                'code'    => 'pin.failed',
                'etype'   => self::ENTITY,
                'eid'     => $entityId > 0 ? $entityId : null,
                'summary' => 'Echec PIN gestion RBAC (email tente: ' . $email . ')',
            ],
        );
    }

    /**
     * @param array<string, mixed> $details
     */
    private function writeAudit(DatabaseInterface $db, int $userId, int $roleId, int $entityId, string $summary, array $details): void
    {
        $db->execute(
            'INSERT INTO audit_log (actor_user_id, actor_role_id, action_code, entity_type, entity_id, summary, details) '
            . 'VALUES (:uid, :rid, :code, :etype, :eid, :summary, :details)',
            [
                'uid'     => $userId,
                'rid'     => $roleId,
                'code'    => 'role.manage',
                'etype'   => self::ENTITY,
                'eid'     => $entityId,
                'summary' => $summary,
                'details' => (string) json_encode($details),
            ],
        );
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     * @param list<int> $selectedPermIds
     * @param list<string> $selectedSources
     */
    private function renderForm(GuardResult $guard, int $id, array $values, array $errors, array $selectedPermIds, array $selectedSources, int $status = 200): Response
    {
        return $this->adminView('admin/roles/form', [
            'title'           => ($id !== 0 ? 'Modifier' : 'Nouveau') . ' role - Wakdo Admin',
            'activeNav'       => 'roles',
            'roleId'          => $id,
            'isAdminRole'     => (string) ($values['code'] ?? '') === self::ADMIN_CODE,
            'permissions'     => $this->roleRepository()->allPermissions(),
            'sources'         => self::SOURCES,
            'selectedPerms'   => $selectedPermIds,
            'selectedSources' => $selectedSources,
            'values'          => [
                'code'          => (string) ($values['code'] ?? ''),
                'label'         => (string) ($values['label'] ?? ''),
                'description'   => (string) ($values['description'] ?? ''),
                'default_route' => (string) ($values['default_route'] ?? ''),
                'order_source'  => (string) ($values['order_source'] ?? ''),
                'is_active'     => $id === 0 ? true : ((int) ($values['is_active'] ?? 1) === 1),
            ],
            'errors'          => $errors,
            'csrfToken'       => Csrf::token($this->sessionManager()),
        ], $guard, $status);
    }

    private function notFound(GuardResult $guard): Response
    {
        return $this->adminView('admin/not_found', ['title' => 'Introuvable', 'activeNav' => 'roles'], $guard, 404);
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
