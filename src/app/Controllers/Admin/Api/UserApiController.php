<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Api;

use PDOException;
use App\Auth\UserRepository;
use App\Controllers\UserController;
use App\Core\DatabaseInterface;
use App\Core\Response;

/**
 * CRUD JSON des comptes back-office (`/admin/api/users`), memes permissions et
 * memes regles que `UserController` (HTML), reutilisees par heritage
 * (`validate()`, `isLastActiveAdmin()`, `userRepository()`). TOUTES les mutations
 * sont des actions sensibles (RG-T13) : PIN equipier + audit dans la meme
 * transaction que l'effet (RG-T14), corps JSON `pin_email` + `pin`.
 *
 * `DELETE` == desactivation (`user.deactivate`), PAS de suppression physique ni
 * d'effacement RGPD : meme semantique que le bouton "Désactiver" du back-office.
 * L'anonymisation RGPD (`apiErase()`, mlt 10.5, ADR-0007) et la reinitialisation
 * du PIN (`apiResetPin()`) ont chacune leur propre sous-chemin ci-dessous, toutes
 * deux PIN-gated comme leur equivalent HTML.
 *
 * Non `final` : les tests sous-classent pour injecter des doubles, meme
 * convention que le reste des controleurs admin. Seam de test via les hooks
 * proteges herites de UserController/AuthenticatedController.
 */
class UserApiController extends UserController
{
    use JsonApiTrait;

    /**
     * @param array<string, string> $params
     */
    public function apiIndex(array $params = []): Response
    {
        $guard = $this->guardApi('user.read');
        if ($guard instanceof Response) {
            return $guard;
        }

        $rows = array_map([$this, 'present'], $this->userRepository()->all());

        return $this->collectionResponse($rows);
    }

    /**
     * @param array<string, string> $params
     */
    public function apiShow(array $params): Response
    {
        $guard = $this->guardApi('user.read');
        if ($guard instanceof Response) {
            return $guard;
        }

        $user = $this->userRepository()->find((int) ($params['id'] ?? 0));
        if ($user === null) {
            return $this->notFoundResponse();
        }

        return $this->okResponse($this->present($user));
    }

    /**
     * @param array<string, string> $params
     */
    public function apiStore(array $params = []): Response
    {
        $guard = $this->guardApi('user.create');
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
        [$data, $errors] = $this->validate($form, false);
        $errors = $formErrors + $errors;
        if ($errors !== []) {
            return $this->validationErrorResponse($errors);
        }
        if ($this->userRepository()->emailExists((string) $data['email'])) {
            return $this->conflictResponse('Cet email est déjà utilisé.');
        }

        [$email, $pin] = $this->pinFields($body);
        $actorSessionId = $guard->userId ?? 0;
        $actor = $this->pinGate()->resolve($actorSessionId, $email, $pin, 'user', 0);
        if ($actor === null) {
            return $this->pinErrorResponse('Email ou PIN invalide (requis pour créer un utilisateur).');
        }

        $hash = $this->passwordHasher()->hash((string) $data['password']);
        $newId = 0;
        try {
            $this->db()->transaction(function (DatabaseInterface $db) use ($data, $hash, $actor, &$newId): void {
                $newId = (new UserRepository($db))->create([
                    'email'         => $data['email'],
                    'password_hash' => $hash,
                    'first_name'    => $data['first_name'],
                    'last_name'     => $data['last_name'],
                    'role_id'       => $data['role_id'],
                ]);
                $this->pinGate()->writeAudit($db, 'user.create', $actor['id'], $actor['role_id'], 'user', $newId, 'Création utilisateur', ['role_id' => $data['role_id']]);
            });
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return $this->conflictResponse('Cet email est déjà utilisé.');
            }

            throw $exception;
        }

        $this->pinGate()->reset($actorSessionId);
        $user = $this->userRepository()->find($newId);

        return $this->createdResponse($user !== null ? $this->present($user) : null, '/admin/api/users/' . $newId);
    }

    /**
     * @param array<string, string> $params
     */
    public function apiUpdate(array $params): Response
    {
        $guard = $this->guardApi('user.update');
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
        $repo = $this->userRepository();
        $current = $repo->find($id);
        if ($current === null) {
            return $this->notFoundResponse();
        }

        [$form, $formErrors] = $this->toForm($body);
        [$data, $errors] = $this->validate($form, true);
        $errors = $formErrors + $errors;
        if ($errors !== []) {
            return $this->validationErrorResponse($errors);
        }
        if ($repo->emailExists((string) $data['email'], $id)) {
            return $this->conflictResponse('Cet email est déjà utilisé.');
        }

        // PUT PARTIEL (documente en section 5.3 de conventions.md) : `is_active`
        // absent du corps CONSERVE la valeur actuelle (ne desactive jamais par
        // omission) ; seul `is_active:false` explicite (booleen JSON STRICT --
        // la chaine "false" est refusee, 422, pas lue comme un booleen truthy)
        // desactive, sous la meme permission (`user.update`) et le meme PIN que
        // le HTML.
        $isActiveBool = $this->fieldBoolPreserving($body, 'is_active', (int) ($current['is_active'] ?? 1) === 1);
        if ($isActiveBool instanceof Response) {
            return $isActiveBool;
        }
        $isActive = $isActiveBool ? 1 : 0;

        if ($this->isLastActiveAdmin($current) && ($isActive === 0 || $data['role_id'] !== (int) ($current['role_id'] ?? 0))) {
            return $this->validationErrorResponse(['role_id' => 'Impossible de retirer le dernier administrateur actif.']);
        }

        [$email, $pin] = $this->pinFields($body);
        $actorSessionId = $guard->userId ?? 0;
        $actor = $this->pinGate()->resolve($actorSessionId, $email, $pin, 'user', $id);
        if ($actor === null) {
            return $this->pinErrorResponse('Email ou PIN invalide (requis pour modifier un utilisateur).');
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
                $this->pinGate()->writeAudit($db, 'user.update', $actor['id'], $actor['role_id'], 'user', $id, 'Mise à jour utilisateur', ['fields' => $changed]);
            });
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return $this->conflictResponse('Cet email est déjà utilisé.');
            }

            throw $exception;
        }

        $this->pinGate()->reset($actorSessionId);

        return $this->okResponse($this->present((array) $repo->find($id)));
    }

    /**
     * `DELETE` == desactivation (cf. docblock de classe). mlt 10.3 PRE-2 : pas
     * d'auto-desactivation. Anti-lockout : pas le dernier admin actif.
     *
     * @param array<string, string> $params
     */
    public function apiDestroy(array $params): Response
    {
        $guard = $this->guardApi('user.deactivate');
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
        $repo = $this->userRepository();
        $user = $repo->find($id);
        if ($user === null) {
            return $this->notFoundResponse();
        }

        if ($id === ($guard->userId ?? 0)) {
            return $this->errorResponse(403, 'FORBIDDEN', 'Vous ne pouvez pas désactiver votre propre compte.');
        }
        if ($this->isLastActiveAdmin($user)) {
            return $this->validationErrorResponse(['id' => 'Impossible de désactiver le dernier administrateur actif.']);
        }

        [$email, $pin] = $this->pinFields($body);
        $actorSessionId = $guard->userId ?? 0;
        $actor = $this->pinGate()->resolve($actorSessionId, $email, $pin, 'user', $id);
        if ($actor === null) {
            return $this->pinErrorResponse('Email ou PIN invalide (requis pour désactiver).');
        }

        $this->db()->transaction(function (DatabaseInterface $db) use ($id, $actor): void {
            (new UserRepository($db))->deactivate($id);
            $this->pinGate()->writeAudit($db, 'user.deactivate', $actor['id'], $actor['role_id'], 'user', $id, 'Désactivation utilisateur');
        });

        $this->pinGate()->reset($actorSessionId);

        return $this->okResponse(['id' => $id, 'status' => 'deactivated']);
    }

    /**
     * Reinitialisation du PIN (self-service pour l'equipier cible, qui le redefinit
     * ensuite via `/admin/profile/pin`). `user.update` + PIN equipier (RG-T13) : c'est
     * une mutation de credential, donc dans l'ensemble sensible comme les autres
     * mutations utilisateur.
     *
     * @param array<string, string> $params
     */
    public function apiResetPin(array $params): Response
    {
        $guard = $this->guardApi('user.update');
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
        $repo = $this->userRepository();
        if ($repo->find($id) === null) {
            return $this->notFoundResponse();
        }

        [$email, $pin] = $this->pinFields($body);
        $actorSessionId = $guard->userId ?? 0;
        $actor = $this->pinGate()->resolve($actorSessionId, $email, $pin, 'user', $id);
        if ($actor === null) {
            return $this->pinErrorResponse('Email ou PIN invalide (requis pour réinitialiser le PIN).');
        }

        $this->db()->transaction(function (DatabaseInterface $db) use ($id, $actor): void {
            (new UserRepository($db))->clearPin($id);
            $this->pinGate()->writeAudit($db, 'user.update', $actor['id'], $actor['role_id'], 'user', $id, 'Réinitialisation du PIN', ['fields' => ['pin_hash']]);
        });

        $this->pinGate()->reset($actorSessionId);

        return $this->okResponse(['id' => $id, 'status' => 'pin_reset']);
    }

    /**
     * Effacement RGPD (mlt 10.5, tombstone/anonymisation, ADR-0007) : PAS une
     * suppression physique (preserve les FK). `user.update` + PIN (RG-T13). Memes
     * garde-fous que le HTML : pas sur son propre compte (403), pas le dernier admin
     * actif (422), deja anonymise -> 409.
     *
     * @param array<string, string> $params
     */
    public function apiErase(array $params): Response
    {
        $guard = $this->guardApi('user.update');
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
        $repo = $this->userRepository();
        $user = $repo->find($id);
        if ($user === null) {
            return $this->notFoundResponse();
        }

        if (($user['anonymized_at'] ?? null) !== null) {
            return $this->conflictResponse('Ce compte est déjà anonymisé.');
        }
        if ($id === ($guard->userId ?? 0)) {
            return $this->errorResponse(403, 'FORBIDDEN', 'Vous ne pouvez pas anonymiser votre propre compte.');
        }
        if ($this->isLastActiveAdmin($user)) {
            return $this->validationErrorResponse(['id' => 'Impossible d\'anonymiser le dernier administrateur actif.']);
        }

        [$email, $pin] = $this->pinFields($body);
        $actorSessionId = $guard->userId ?? 0;
        $actor = $this->pinGate()->resolve($actorSessionId, $email, $pin, 'user', $id);
        if ($actor === null) {
            return $this->pinErrorResponse('Email ou PIN invalide (requis pour anonymiser).');
        }

        $erased = 0;
        $this->db()->transaction(function (DatabaseInterface $db) use ($id, $actor, &$erased): void {
            $erased = (new UserRepository($db))->anonymise($id);
            if ($erased === 1) {
                $this->pinGate()->writeAudit($db, 'user.erase_pii', $actor['id'], $actor['role_id'], 'user', $id, 'Anonymisation RGPD (droit à l\'effacement)');
            }
        });

        if ($erased !== 1) {
            // Course : anonymise entre la lecture et l'ecriture -> 0 ligne (409).
            return $this->conflictResponse('Ce compte est déjà anonymisé.');
        }

        $this->pinGate()->reset($actorSessionId);

        return $this->okResponse(['id' => $id, 'status' => 'anonymized']);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: array<string, string>, 1: array<string, string>} [form, erreurs]
     */
    private function toForm(array $body): array
    {
        return $this->scalarForm($body, [
            'email'      => '',
            'first_name' => '',
            'last_name'  => '',
            'role_id'    => '',
            'password'   => '',
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id'         => (int) ($row['id'] ?? 0),
            'email'      => (string) ($row['email'] ?? ''),
            'first_name' => (string) ($row['first_name'] ?? ''),
            'last_name'  => (string) ($row['last_name'] ?? ''),
            'role_id'    => (int) ($row['role_id'] ?? 0),
            'is_active'  => (int) ($row['is_active'] ?? 0) === 1,
            'anonymized_at' => ($row['anonymized_at'] ?? null) !== null ? (string) $row['anonymized_at'] : null,
        ];
    }
}
