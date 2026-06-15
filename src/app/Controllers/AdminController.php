<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Csrf;
use App\Auth\GuardResult;
use App\Auth\UserDirectory;
use App\Core\Response;

/**
 * Base des pages back-office rendues serveur (P3). Etend AuthenticatedController
 * (session + autorisation) et :
 *  - rend dans le shell admin (topbar + sidebar) via layoutName(),
 *  - fournit guard() : applique RG-6/RG-T02 (redirige vers /login si non
 *    authentifie) puis RG-T03 (403 si la permission manque), sinon renvoie la
 *    GuardResult,
 *  - injecte le contexte commun du layout (utilisateur, role, permissions, CSRF).
 *
 * Non `final` : les controleurs concrets (Dashboard, Category...) en heritent ;
 * les tests sous-classent pour injecter des doubles.
 */
abstract class AdminController extends AuthenticatedController
{
    protected function layoutName(): string
    {
        return 'admin/layout';
    }

    /**
     * Garde de page : 302 vers /login si la session est absente/expiree/inactive ;
     * 403 (page admin) si $permission est exigee et non detenue ; sinon la
     * GuardResult authentifiee. L'appelant fait : if ($g instanceof Response) return $g;
     */
    protected function guard(?string $permission = null): GuardResult|Response
    {
        $result = $this->sessionGuard()->check();

        if (!$result->authenticated || $result->userId === null || $result->roleId === null) {
            return Response::make('', 302, ['Location' => '/login']);
        }

        if ($permission !== null && !$this->authorizer()->can($result->roleId, $permission)) {
            return $this->adminView('admin/forbidden', ['title' => 'Acces refuse', 'activeNav' => ''], $result, 403);
        }

        return $result;
    }

    /**
     * Rend une vue dans le shell admin en injectant le contexte commun
     * (nom/role de l'utilisateur, permissions pour la navigation, jeton CSRF).
     * Les cles passees dans $data ont priorite (ex. activeNav).
     *
     * @param array<string, mixed> $data
     */
    protected function adminView(string $name, array $data, GuardResult $guard, int $status = 200): Response
    {
        $userId = $guard->userId ?? 0;
        $roleId = $guard->roleId ?? 0;
        $info = $this->userDirectory()->displayInfo($userId);

        $context = [
            'currentUserName' => $info['name'],
            'currentUserRole' => $info['role_label'],
            'permissions'     => $this->authorizer()->permissionsFor($roleId),
            'csrfToken'       => Csrf::token($this->sessionManager()),
            'activeNav'       => '',
            'flash'           => $this->takeFlash(),
        ];

        return $this->view($name, $data + $context, $status);
    }

    protected function userDirectory(): UserDirectory
    {
        return new UserDirectory($this->database);
    }

    /**
     * Message de confirmation a afficher apres une redirection (pose avant le 302,
     * consomme au rendu suivant). Stocke en session pour survivre a la redirection.
     */
    protected function setFlash(string $message): void
    {
        $this->sessionManager()->set('_flash', $message);
    }

    private function takeFlash(): ?string
    {
        $flash = $this->sessionManager()->get('_flash');
        if ($flash === null) {
            return null;
        }

        $this->sessionManager()->set('_flash', null);

        return is_string($flash) ? $flash : null;
    }
}
