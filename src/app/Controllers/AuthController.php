<?php

declare(strict_types=1);

namespace App\Controllers;

use Throwable;
use App\Auth\AuthService;
use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\SessionManager;
use App\Core\Controller;
use App\Core\Response;

/**
 * Connexion / deconnexion du back-office (mlt.md 12.1 et 12.2). Rendu serveur :
 * GET /login affiche le formulaire (jeton CSRF en champ cache), POST /login
 * authentifie puis redirige (302) vers role.default_route, POST /logout detruit
 * la session.
 *
 * Le Router n'injecte que (Request, Config, Database) ; le controleur fabrique
 * donc son graphe de services via des hooks proteges, surchargeables en test.
 *
 * Non `final` a dessein : les tests sous-classent ce controleur pour surcharger
 * sessionManager()/authService() et injecter des doubles (seam de testabilite).
 */
class AuthController extends Controller
{
    private const GENERIC_ERROR = 'Email ou mot de passe incorrect';

    /**
     * @param array<string, string> $params
     */
    public function showLogin(array $params = []): Response
    {
        $notice = $this->request->query('reset') === 'ok'
            ? 'Mot de passe reinitialise. Vous pouvez vous connecter.'
            : null;

        return $this->renderLogin(null, $notice);
    }

    /**
     * @param array<string, string> $params
     */
    public function login(array $params = []): Response
    {
        $form = $this->request->formBody();

        // PRE-2 / ERR-2 : jeton CSRF valide sinon 403, avant tout traitement.
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->renderLogin('Session expiree, merci de reessayer.', null, 403);
        }

        // RG-T18 : validation et bornes de longueur cote serveur.
        $email = trim($form['email'] ?? '');
        $password = $form['password'] ?? '';

        if ($email === '' || $password === '' || strlen($email) > 254 || strlen($password) > 4096) {
            return $this->renderLogin(self::GENERIC_ERROR);
        }

        try {
            $result = $this->authService()->authenticate($email, $password, $this->request->clientIp());
        } catch (Throwable $exception) {
            // Fail-closed : une panne base ne doit jamais authentifier. On ne
            // divulgue rien, on re-affiche le formulaire avec le message generique.
            error_log('[wakdo][auth] login failure: ' . $exception->getMessage());

            return $this->renderLogin(self::GENERIC_ERROR);
        }

        if ($result->success && $result->redirectTo !== null) {
            return $this->redirect($result->redirectTo);
        }

        return $this->renderLogin($result->error ?? self::GENERIC_ERROR);
    }

    /**
     * @param array<string, string> $params
     */
    public function logout(array $params = []): Response
    {
        $form = $this->request->formBody();

        // D11 : deconnexion en POST garde par CSRF (un GET forgeable pourrait
        // deconnecter un poste en plein service). CSRF invalide -> 403, pas de destroy.
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return Response::make('Requete invalide.', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $this->authService()->logout();

        return $this->redirect('/login');
    }

    protected function sessionManager(): SessionManager
    {
        return new SessionManager($this->config);
    }

    protected function authService(): AuthService
    {
        return new AuthService(
            $this->database,
            $this->config,
            $this->sessionManager(),
            new PasswordHasher($this->config),
        );
    }

    private function redirect(string $location, int $status = 302): Response
    {
        return Response::make('', $status, ['Location' => $location]);
    }

    private function renderLogin(?string $error, ?string $notice = null, int $status = 200): Response
    {
        return $this->view('auth/login', [
            'title'     => 'Connexion - Wakdo Admin',
            'csrfToken' => Csrf::token($this->sessionManager()),
            'error'     => $error,
            'notice'    => $notice,
        ], $status);
    }
}
