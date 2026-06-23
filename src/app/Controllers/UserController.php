<?php

declare(strict_types=1);

namespace App\Controllers;

use PDOException;
use App\Auth\Csrf;
use App\Auth\GuardResult;
use App\Auth\PasswordHasher;
use App\Auth\PinThrottle;
use App\Auth\PinVerifier;
use App\Auth\UserRepository;
use App\Core\DatabaseInterface;
use App\Core\Response;

/**
 * Gestion des comptes back-office (mlt domaine 10). Toutes les MUTATIONS sont des
 * actions sensibles (RG-T13) : re-autorisation par PIN equipier + ligne `audit_log`
 * dans la meme transaction que l'effet (RG-T14). Le throttle du PIN (RG-T22) est
 * evalue AVANT la verification, par utilisateur agissant — meme pattern que
 * ProductController.
 *
 *  - index   (user.read)       : liste, lecture seule ;
 *  - create/store (user.create), edit/update (user.update), deactivate
 *    (user.deactivate), reset-pin + erase-PII (user.update) : PIN + audit.
 *
 * Garde-fous d'integrite : pas d'auto-desactivation (mlt 10.3 PRE-2, 403) ; on ne
 * peut ni desactiver, ni retrograder, ni anonymiser le DERNIER admin actif
 * (anti-lockout, au-dela du mlt). Conflit d'unicite email -> 409 (convention PR-0).
 *
 * Non `final` : les tests sous-classent (seam db()/sessionManager()).
 */
class UserController extends AdminController
{
    private const ENTITY = 'user';

    /**
     * @param array<string, string> $params
     */
    public function index(array $params = []): Response
    {
        $guard = $this->guard('user.read');
        if ($guard instanceof Response) {
            return $guard;
        }

        return $this->adminView('admin/users/index', [
            'title'      => 'Utilisateurs - Wakdo Admin',
            'activeNav'  => 'users',
            'users'      => $this->userRepository()->all(),
            'currentId'  => $guard->userId ?? 0,
            'canCreate'  => $this->may($guard, 'user.create'),
            'canUpdate'  => $this->may($guard, 'user.update'),
            'canDeactiv' => $this->may($guard, 'user.deactivate'),
        ], $guard);
    }

    /**
     * @param array<string, string> $params
     */
    public function create(array $params = []): Response
    {
        $guard = $this->guard('user.create');
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
        $guard = $this->guard('user.create');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        [$data, $errors] = $this->validate($form, false);
        if ($errors !== []) {
            return $this->renderForm($guard, 0, $form, $errors, 422);
        }
        if ($this->userRepository()->emailExists($data['email'])) {
            return $this->renderForm($guard, 0, $form, ['email' => 'Cet email est deja utilise.'], 409);
        }

        [$actor, $errorMsg] = $this->resolvePin($guard, $form, 0);
        if ($actor === null) {
            return $this->renderForm($guard, 0, $form, ['pin' => $errorMsg], 422);
        }

        $hash = $this->passwordHasher()->hash((string) $data['password']);
        try {
            $this->db()->transaction(function (DatabaseInterface $db) use ($data, $hash, $actor): void {
                $newId = (new UserRepository($db))->create([
                    'email'         => $data['email'],
                    'password_hash' => $hash,
                    'first_name'    => $data['first_name'],
                    'last_name'     => $data['last_name'],
                    'role_id'       => $data['role_id'],
                ]);
                $this->writeAudit($db, 'user.create', $actor['id'], $actor['role_id'], $newId, 'Creation utilisateur', ['role_id' => $data['role_id']]);
            });
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return $this->renderForm($guard, 0, $form, ['email' => 'Cet email est deja utilise.'], 409);
            }

            throw $exception;
        }

        $this->pinThrottle()->reset($guard->userId ?? 0);
        $this->setFlash('Utilisateur cree.');

        return $this->redirect('/admin/users');
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(array $params): Response
    {
        $guard = $this->guard('user.update');
        if ($guard instanceof Response) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $user = $this->userRepository()->find($id);
        if ($user === null) {
            return $this->notFound($guard);
        }

        return $this->renderForm($guard, $id, $user, []);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(array $params): Response
    {
        $guard = $this->guard('user.update');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $id = (int) ($params['id'] ?? 0);
        $current = $this->userRepository()->find($id);
        if ($current === null) {
            return $this->notFound($guard);
        }

        [$data, $errors] = $this->validate($form, true);
        if ($errors !== []) {
            return $this->renderForm($guard, $id, $form, $errors, 422);
        }
        if ($this->userRepository()->emailExists($data['email'], $id)) {
            return $this->renderForm($guard, $id, $form, ['email' => 'Cet email est deja utilise.'], 409);
        }

        $isActive = isset($form['is_active']) ? 1 : 0;

        // Anti-lockout : on ne retire pas le statut d'admin actif au DERNIER admin
        // actif (desactivation OU changement de role) -> sinon back-office inaccessible.
        if ($this->isLastActiveAdmin($current) && ($isActive === 0 || $data['role_id'] !== (int) ($current['role_id'] ?? 0))) {
            return $this->renderForm($guard, $id, $form, ['role_id' => 'Impossible de retirer le dernier administrateur actif.'], 422);
        }

        [$actor, $errorMsg] = $this->resolvePin($guard, $form, $id);
        if ($actor === null) {
            return $this->renderForm($guard, $id, $form, ['pin' => $errorMsg], 422);
        }

        $changed = $this->changedFields($current, $data, $isActive);
        $newHash = $data['password'] !== null ? $this->passwordHasher()->hash((string) $data['password']) : null;

        try {
            $this->db()->transaction(function (DatabaseInterface $db) use ($id, $data, $isActive, $newHash, $actor, $changed): void {
                $repo = new UserRepository($db);
                $repo->update($id, [
                    'email'      => $data['email'],
                    'first_name' => $data['first_name'],
                    'last_name'  => $data['last_name'],
                    'role_id'    => $data['role_id'],
                    'is_active'  => $isActive,
                ]);
                if ($newHash !== null) {
                    $repo->setPasswordHash($id, $newHash);
                }
                $this->writeAudit($db, 'user.update', $actor['id'], $actor['role_id'], $id, 'Mise a jour utilisateur', ['fields' => $changed]);
            });
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return $this->renderForm($guard, $id, $form, ['email' => 'Cet email est deja utilise.'], 409);
            }

            throw $exception;
        }

        $this->pinThrottle()->reset($guard->userId ?? 0);
        $this->setFlash('Utilisateur mis a jour.');

        return $this->redirect('/admin/users');
    }

    /**
     * @param array<string, string> $params
     */
    public function confirmDeactivate(array $params): Response
    {
        $guard = $this->guard('user.deactivate');
        if ($guard instanceof Response) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $user = $this->userRepository()->find($id);
        if ($user === null) {
            return $this->notFound($guard);
        }

        return $this->renderConfirm($guard, 'deactivate', $id, $user, null);
    }

    /**
     * @param array<string, string> $params
     */
    public function deactivate(array $params): Response
    {
        $guard = $this->guard('user.deactivate');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $id = (int) ($params['id'] ?? 0);
        $user = $this->userRepository()->find($id);
        if ($user === null) {
            return $this->notFound($guard);
        }

        // mlt 10.3 PRE-2 : pas d'auto-desactivation (on ne se coupe pas l'acces).
        if ($id === ($guard->userId ?? 0)) {
            return $this->renderConfirm($guard, 'deactivate', $id, $user, 'Vous ne pouvez pas desactiver votre propre compte.', 403);
        }
        if ($this->isLastActiveAdmin($user)) {
            return $this->renderConfirm($guard, 'deactivate', $id, $user, 'Impossible de desactiver le dernier administrateur actif.', 422);
        }

        [$actor, $errorMsg] = $this->resolvePin($guard, $form, $id);
        if ($actor === null) {
            return $this->renderConfirm($guard, 'deactivate', $id, $user, $errorMsg, 422);
        }

        $this->db()->transaction(function (DatabaseInterface $db) use ($id, $actor): void {
            (new UserRepository($db))->deactivate($id);
            $this->writeAudit($db, 'user.deactivate', $actor['id'], $actor['role_id'], $id, 'Desactivation utilisateur', null);
        });

        $this->pinThrottle()->reset($guard->userId ?? 0);
        $this->setFlash('Utilisateur desactive.');

        return $this->redirect('/admin/users');
    }

    /**
     * @param array<string, string> $params
     */
    public function confirmResetPin(array $params): Response
    {
        $guard = $this->guard('user.update');
        if ($guard instanceof Response) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $user = $this->userRepository()->find($id);
        if ($user === null) {
            return $this->notFound($guard);
        }

        return $this->renderConfirm($guard, 'reset-pin', $id, $user, null);
    }

    /**
     * @param array<string, string> $params
     */
    public function resetPin(array $params): Response
    {
        $guard = $this->guard('user.update');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $id = (int) ($params['id'] ?? 0);
        $user = $this->userRepository()->find($id);
        if ($user === null) {
            return $this->notFound($guard);
        }

        [$actor, $errorMsg] = $this->resolvePin($guard, $form, $id);
        if ($actor === null) {
            return $this->renderConfirm($guard, 'reset-pin', $id, $user, $errorMsg, 422);
        }

        // Met le PIN a NULL : l'equipier le redefinit en self-service. L'admin
        // n'a jamais connaissance du PIN d'autrui.
        $this->db()->transaction(function (DatabaseInterface $db) use ($id, $actor): void {
            (new UserRepository($db))->clearPin($id);
            $this->writeAudit($db, 'user.update', $actor['id'], $actor['role_id'], $id, 'Reinitialisation du PIN', ['fields' => ['pin_hash']]);
        });

        $this->pinThrottle()->reset($guard->userId ?? 0);
        $this->setFlash('PIN reinitialise : l\'equipier doit le redefinir.');

        return $this->redirect('/admin/users');
    }

    /**
     * @param array<string, string> $params
     */
    public function confirmErase(array $params): Response
    {
        $guard = $this->guard('user.update');
        if ($guard instanceof Response) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $user = $this->userRepository()->find($id);
        if ($user === null) {
            return $this->notFound($guard);
        }

        return $this->renderConfirm($guard, 'erase', $id, $user, null);
    }

    /**
     * Effacement RGPD (mlt 10.5) : anonymise la ligne (tombstone), preserve les FK.
     *
     * @param array<string, string> $params
     */
    public function erase(array $params): Response
    {
        $guard = $this->guard('user.update');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $id = (int) ($params['id'] ?? 0);
        $user = $this->userRepository()->find($id);
        if ($user === null) {
            return $this->notFound($guard);
        }

        // PRE-3 : deja anonymise -> 409.
        if (($user['anonymized_at'] ?? null) !== null) {
            return $this->renderConfirm($guard, 'erase', $id, $user, 'Ce compte est deja anonymise.', 409);
        }
        if ($id === ($guard->userId ?? 0)) {
            return $this->renderConfirm($guard, 'erase', $id, $user, 'Vous ne pouvez pas anonymiser votre propre compte.', 403);
        }
        if ($this->isLastActiveAdmin($user)) {
            return $this->renderConfirm($guard, 'erase', $id, $user, 'Impossible d\'anonymiser le dernier administrateur actif.', 422);
        }

        [$actor, $errorMsg] = $this->resolvePin($guard, $form, $id);
        if ($actor === null) {
            return $this->renderConfirm($guard, 'erase', $id, $user, $errorMsg, 422);
        }

        $erased = 0;
        $this->db()->transaction(function (DatabaseInterface $db) use ($id, $actor, &$erased): void {
            $erased = (new UserRepository($db))->anonymise($id);
            if ($erased === 1) {
                $this->writeAudit($db, 'user.erase_pii', $actor['id'], $actor['role_id'], $id, 'Anonymisation RGPD (droit a l effacement)', null);
            }
        });

        // Course : anonymise entre la lecture et l'effacement -> 0 ligne (409).
        if ($erased !== 1) {
            return $this->renderConfirm($guard, 'erase', $id, $user, 'Ce compte est deja anonymise.', 409);
        }

        $this->pinThrottle()->reset($guard->userId ?? 0);
        $this->setFlash('Compte anonymise (RGPD).');

        return $this->redirect('/admin/users');
    }

    // --- Helpers ---

    protected function userRepository(): UserRepository
    {
        return new UserRepository($this->db());
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

    private function may(GuardResult $guard, string $permission): bool
    {
        return $guard->roleId !== null && $this->authorizer()->can($guard->roleId, $permission);
    }

    /**
     * Le compte cible est-il le dernier administrateur ACTIF ? (actif + role admin
     * + un seul admin actif au total). Garde anti-lockout du back-office.
     *
     * @param array<string, mixed> $user
     */
    private function isLastActiveAdmin(array $user): bool
    {
        return (int) ($user['is_active'] ?? 0) === 1
            && $this->userRepository()->isAdmin((int) ($user['id'] ?? 0))
            && $this->userRepository()->activeAdminCount() === 1;
    }

    /**
     * Porte du PIN d'action sensible (RG-T13 + throttle RG-T22), mutualisee par
     * toutes les mutations. Verrou evalue AVANT la verification (leurre de timing) ;
     * sur echec hors verrou, ecrit pin.failed + increment du throttle dans UNE
     * transaction (RG-T08/RG-T14). Retourne [acteur resolu, null] au succes, sinon
     * [null, message generique]. La reinitialisation du compteur (succes) est
     * laissee a l'appelant, apres l'effet.
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
     * Validation serveur (RG-T18) + normalisation. Mot de passe requis a la creation
     * (>= 8), optionnel a l'edition (re-hache seulement si fourni, mlt 10.2 RG-1/2).
     *
     * @param array<string, string> $form
     * @return array{0: array{email: string, first_name: string, last_name: string, role_id: int, password: ?string}, 1: array<string, string>}
     */
    private function validate(array $form, bool $isUpdate): array
    {
        $errors = [];

        $email = trim($form['email'] ?? '');
        if ($email === '' || mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Email valide requis (254 caracteres max).';
        }

        $first = trim($form['first_name'] ?? '');
        if ($first === '' || mb_strlen($first) > 60) {
            $errors['first_name'] = 'Le prenom est requis (60 caracteres max).';
        }

        $last = trim($form['last_name'] ?? '');
        if ($last === '' || mb_strlen($last) > 60) {
            $errors['last_name'] = 'Le nom est requis (60 caracteres max).';
        }

        $roleRaw = trim($form['role_id'] ?? '');
        $roleId = ctype_digit($roleRaw) ? (int) $roleRaw : 0;
        if ($roleId === 0 || !$this->userRepository()->activeRoleExists($roleId)) {
            $errors['role_id'] = 'Role requis et actif.';
        }

        $password = (string) ($form['password'] ?? '');
        if (!$isUpdate && mb_strlen($password) < 8) {
            $errors['password'] = 'Mot de passe requis (8 caracteres min).';
        } elseif ($isUpdate && $password !== '' && mb_strlen($password) < 8) {
            $errors['password'] = 'Le nouveau mot de passe doit faire 8 caracteres min.';
        }

        $data = [
            'email'      => $email,
            'first_name' => $first,
            'last_name'  => $last,
            'role_id'    => $roleId,
            'password'   => $password !== '' ? $password : null,
        ];

        return [$data, $errors];
    }

    /**
     * Noms des champs modifies (pas les valeurs, pas de PII) pour le `details`
     * d'audit (RG-T14).
     *
     * @param array<string, mixed> $current
     * @param array{email: string, first_name: string, last_name: string, role_id: int, password: ?string} $data
     * @return list<string>
     */
    private function changedFields(array $current, array $data, int $isActive): array
    {
        $changed = [];
        if ($data['email'] !== (string) ($current['email'] ?? '')) {
            $changed[] = 'email';
        }
        if ($data['first_name'] !== (string) ($current['first_name'] ?? '')) {
            $changed[] = 'first_name';
        }
        if ($data['last_name'] !== (string) ($current['last_name'] ?? '')) {
            $changed[] = 'last_name';
        }
        if ($data['role_id'] !== (int) ($current['role_id'] ?? 0)) {
            $changed[] = 'role_id';
        }
        if ($isActive !== (int) ($current['is_active'] ?? 0)) {
            $changed[] = 'is_active';
        }
        if ($data['password'] !== null) {
            $changed[] = 'password_hash';
        }

        return $changed;
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
                'summary' => 'Echec PIN gestion utilisateur (email tente: ' . $email . ')',
            ],
        );
    }

    /**
     * @param array<string, mixed>|null $details
     */
    private function writeAudit(DatabaseInterface $db, string $action, int $userId, int $roleId, int $entityId, string $summary, ?array $details): void
    {
        $db->execute(
            'INSERT INTO audit_log (actor_user_id, actor_role_id, action_code, entity_type, entity_id, summary, details) '
            . 'VALUES (:uid, :rid, :code, :etype, :eid, :summary, :details)',
            [
                'uid'     => $userId,
                'rid'     => $roleId,
                'code'    => $action,
                'etype'   => self::ENTITY,
                'eid'     => $entityId,
                'summary' => $summary,
                'details' => $details !== null ? (string) json_encode($details) : null,
            ],
        );
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function renderForm(GuardResult $guard, int $id, array $values, array $errors, int $status = 200): Response
    {
        return $this->adminView('admin/users/form', [
            'title'     => ($id !== 0 ? 'Modifier' : 'Nouvel') . ' utilisateur - Wakdo Admin',
            'activeNav' => 'users',
            'userId'    => $id,
            'roles'     => $this->rolesForSelect(),
            'values'    => [
                'email'      => (string) ($values['email'] ?? ''),
                'first_name' => (string) ($values['first_name'] ?? ''),
                'last_name'  => (string) ($values['last_name'] ?? ''),
                'role_id'    => (string) ($values['role_id'] ?? ''),
                // Defaut actif a la creation ; sur re-rendu refleter la presence du champ.
                'is_active'  => $id === 0 ? true : ((int) ($values['is_active'] ?? 1) === 1),
            ],
            'errors'    => $errors,
            'csrfToken' => Csrf::token($this->sessionManager()),
        ], $guard, $status);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function renderConfirm(GuardResult $guard, string $kind, int $id, array $user, ?string $error, ?int $status = null): Response
    {
        return $this->adminView('admin/users/confirm', [
            'title'     => 'Confirmation - Wakdo Admin',
            'activeNav' => 'users',
            'kind'      => $kind,
            'userId'    => $id,
            'userLabel' => trim(((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? ''))) ?: (string) ($user['email'] ?? ''),
            'error'     => $error,
            'csrfToken' => Csrf::token($this->sessionManager()),
        ], $guard, $status ?? ($error !== null ? 422 : 200));
    }

    /**
     * Roles actifs pour le select (id + label), via une lecture directe (pas de
     * repo dedie avant le lot RBAC).
     *
     * @return list<array{id:int, label:string}>
     */
    private function rolesForSelect(): array
    {
        $rows = $this->db()->fetchAll('SELECT id, label FROM role WHERE is_active = 1 ORDER BY label');

        return array_map(static fn (array $r): array => [
            'id'    => (int) ($r['id'] ?? 0),
            'label' => (string) ($r['label'] ?? ''),
        ], $rows);
    }

    private function notFound(GuardResult $guard): Response
    {
        return $this->adminView('admin/not_found', ['title' => 'Introuvable', 'activeNav' => 'users'], $guard, 404);
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
