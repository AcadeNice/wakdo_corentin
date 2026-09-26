<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Csrf;
use App\Core\Response;

/**
 * GET /admin/me : identite et permissions de l'utilisateur de la session courante.
 *
 * Premier consommateur reel de SessionGuard (RG-6 + RG-T02) et d'Authorizer
 * (RG-T03) : la garde rejette toute session absente/expiree/inactive (401), sinon
 * on renvoie le role et la liste des permissions rechargee depuis la base.
 *
 * `csrf_token` (RG-T01) : point d'entree du jeton CSRF pour l'API d'administration
 * JSON (`App\Controllers\Admin\Api`, docs/api/conventions.md section 5.3), qui n'a
 * pas de formulaire HTML pour porter un champ cache `_csrf`. Un client JSON
 * (Postman, script) lit ce champ puis le renvoie en en-tete `X-CSRF-Token` sur
 * chaque POST/PUT/DELETE. Le jeton est SYNCHRONISEUR (App\Auth\Csrf) : il ne
 * tourne qu'a la regeneration de session (login), jamais a chaque lecture d'ici,
 * donc un enchainement de requetes Postman reste valide sans le rafraichir.
 *
 * NB P2 : les pages admin sont encore des fichiers statiques servis par Apache
 * (hors PHP), donc non couvertes par cette garde. Leur protection arrive en P3
 * quand elles deviennent des vues rendues serveur passant par ce socle.
 *
 * Non `final` a dessein : les tests sous-classent pour injecter des doubles
 * (session en mode test + FakeDatabase) via les hooks proteges.
 */
class MeController extends AuthenticatedController
{
    /**
     * @param array<string, string> $params
     */
    public function show(array $params = []): Response
    {
        $guard = $this->sessionGuard()->check();

        if (!$guard->authenticated || $guard->userId === null || $guard->roleId === null) {
            return $this->json(
                ['data' => null, 'error' => ['code' => 'AUTH_REQUIRED', 'message' => 'Authentification requise']],
                401,
            );
        }

        $authorizer = $this->authorizer();

        return $this->json([
            'data' => [
                'user_id'     => $guard->userId,
                'role_id'     => $guard->roleId,
                'role_code'   => $authorizer->roleCode($guard->roleId),
                'permissions' => $authorizer->permissionsFor($guard->roleId),
                'csrf_token'  => Csrf::token($this->sessionManager()),
            ],
        ]);
    }
}
