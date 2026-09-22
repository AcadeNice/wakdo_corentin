<?php

declare(strict_types=1);

namespace App\Controllers;

use Throwable;
use App\Auth\Csrf;
use App\Auth\LogMailer;
use App\Auth\Mailer;
use App\Auth\PasswordHasher;
use App\Auth\PasswordResetService;
use App\Auth\SessionManager;
use App\Auth\SmtpClient;
use App\Auth\SmtpMailer;
use App\Auth\StreamSmtpTransport;
use App\Core\Controller;
use App\Core\Response;

/**
 * Reinitialisation de mot de passe (mlt.md 12.3), rendu serveur en deux phases :
 * demande (GET/POST /forgot_password) puis confirmation (GET/POST /reset_password).
 * La phase demande renvoie toujours une reponse neutre (anti-enumeration).
 *
 * Non `final` a dessein : les tests sous-classent ce controleur pour surcharger
 * sessionManager()/resetService() et injecter des doubles (seam de testabilite).
 */
class PasswordResetController extends Controller
{
    private const NEUTRAL_NOTICE = 'Si un compte correspond a cet email, un lien de reinitialisation a ete envoye.';
    private const INVALID_LINK = 'Lien invalide ou expire.';

    /**
     * @param array<string, string> $params
     */
    public function showRequest(array $params = []): Response
    {
        return $this->view('auth/forgot', [
            'title'     => 'Mot de passe oublie - Wakdo Admin',
            'csrfToken' => Csrf::token($this->sessionManager()),
            'notice'    => null,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function submitRequest(array $params = []): Response
    {
        $form = $this->request->formBody();

        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->view('auth/forgot', [
                'title'     => 'Mot de passe oublie - Wakdo Admin',
                'csrfToken' => Csrf::token($this->sessionManager()),
                'notice'    => null,
            ], 403);
        }

        $email = trim($form['email'] ?? '');

        // Reponse neutre quoi qu'il arrive (existence, validite, meme panne base).
        if ($email !== '' && strlen($email) <= 254) {
            try {
                $this->resetService()->requestReset($email, $this->baseUrl());
            } catch (Throwable $exception) {
                error_log('[wakdo][auth] reset request failure: ' . $exception->getMessage());
            }
        }

        return $this->view('auth/forgot', [
            'title'     => 'Mot de passe oublie - Wakdo Admin',
            'csrfToken' => Csrf::token($this->sessionManager()),
            'notice'    => self::NEUTRAL_NOTICE,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function showConfirm(array $params = []): Response
    {
        return $this->renderConfirm($this->request->query('token') ?? '', null);
    }

    /**
     * @param array<string, string> $params
     */
    public function submitConfirm(array $params = []): Response
    {
        $form = $this->request->formBody();
        $token = $form['token'] ?? '';

        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->renderConfirm($token, 'Session expiree, merci de reessayer.', 403);
        }

        $password = $form['password'] ?? '';
        $confirm = $form['password_confirm'] ?? '';

        if ($password !== $confirm) {
            return $this->renderConfirm($token, 'Les mots de passe ne correspondent pas.');
        }

        try {
            $result = $this->resetService()->confirmReset($token, $password);
        } catch (Throwable $exception) {
            error_log('[wakdo][auth] reset confirm failure: ' . $exception->getMessage());

            return $this->renderConfirm($token, self::INVALID_LINK);
        }

        if ($result->success && $result->redirectTo !== null) {
            return $this->redirect($result->redirectTo);
        }

        return $this->renderConfirm($token, $result->error ?? self::INVALID_LINK);
    }

    protected function sessionManager(): SessionManager
    {
        return new SessionManager($this->config);
    }

    protected function resetService(): PasswordResetService
    {
        return new PasswordResetService(
            $this->database,
            $this->config,
            new PasswordHasher($this->config),
            $this->mailer(),
        );
    }

    /**
     * SMTP reel si configure (SMTP_HOST + SMTP_USER + SMTP_PASSWORD presents),
     * sinon repli sur LogMailer (le lien est journalise, pas d'envoi) : le dev
     * reste sans infra mail, la prod envoie via le relais.
     */
    protected function mailer(): Mailer
    {
        $host = $this->config->get('SMTP_HOST');
        $user = $this->config->get('SMTP_USER');
        $password = $this->config->get('SMTP_PASSWORD');

        if ($host === null || $user === null || $password === null) {
            return new LogMailer();
        }

        return new SmtpMailer(
            new SmtpClient(new StreamSmtpTransport()),
            $host,
            (int) ($this->config->get('SMTP_PORT', '587') ?? '587'),
            $user,
            $password,
            $this->config->get('MAIL_FROM_EMAIL', 'noreply@localhost') ?? 'noreply@localhost',
            $this->config->get('MAIL_FROM_NAME', 'Wakdo') ?? 'Wakdo',
        );
    }

    private function baseUrl(): string
    {
        return $this->config->get('APP_URL_ADMIN', '') ?? '';
    }

    private function redirect(string $location, int $status = 302): Response
    {
        return Response::make('', $status, ['Location' => $location]);
    }

    private function renderConfirm(string $token, ?string $error, int $status = 200): Response
    {
        return $this->view('auth/reset', [
            'title'     => 'Nouveau mot de passe - Wakdo Admin',
            'csrfToken' => Csrf::token($this->sessionManager()),
            'token'     => $token,
            'error'     => $error,
        ], $status);
    }
}
