<?php

declare(strict_types=1);

namespace App\Controllers;

use Throwable;
use App\Auth\Csrf;
use App\Auth\LogMailer;
use App\Auth\Mailer;
use App\Auth\PasswordHasher;
use App\Auth\PasswordResetService;
use App\Auth\PasswordResetThrottle;
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
    private const NEUTRAL_NOTICE = 'Si un compte correspond à cet email, un lien de réinitialisation a été envoyé.';
    private const INVALID_LINK = 'Lien invalide ou expiré.';

    /**
     * @param array<string, string> $params
     */
    public function showRequest(array $params = []): Response
    {
        return $this->view('auth/forgot', [
            'title'     => 'Mot de passe oublié - Wakdo Admin',
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
                'title'     => 'Mot de passe oublié - Wakdo Admin',
                'csrfToken' => Csrf::token($this->sessionManager()),
                'notice'    => null,
            ], 403);
        }

        $email = trim($form['email'] ?? '');
        $ip = $this->request->clientIp();
        $blocked = false;

        // Defaut corrige (avant : aucune limite, 30 demandes consecutives pour la
        // meme adresse passaient toutes). Gate AVANT tout travail, comme la porte
        // de throttling du login (PRE-3) : si l'adresse OU l'IP source est
        // verrouillee, on ne fait NI recherche NI envoi -- mais la reponse rendue
        // reste EXACTEMENT la meme (statut 429, meme notice neutre) que l'adresse
        // existe ou non, pour ne rien reveler par ce canal (anti-enumeration).
        //
        // Throttle et envoi DANS LE MEME try/catch (panne base y comprise) : cette
        // route reste "fail neutral" quoi qu'il arrive (existence, validite du
        // throttle, meme panne base) -- une base injoignable ne doit ni faire
        // fuiter de detail (try/catch existant avant ce lot) ni, desormais,
        // transformer une lecture de throttle en 500 (le throttle est une defense
        // en profondeur contre l'abus, pas un controle dont l'echec doit priver
        // l'utilisateur legitime de la reponse neutre habituelle -- fail-open ICI,
        // a la difference du login qui reste fail-closed sur son AUTHENTIFICATION).
        if ($email !== '' && strlen($email) <= 254) {
            try {
                $blocked = $this->resetThrottle()->isBlocked($email, $ip);

                if (!$blocked) {
                    $this->resetThrottle()->recordAttempt($email, $ip);
                    $this->resetService()->requestReset($email, $this->baseUrl());
                }
            } catch (Throwable $exception) {
                error_log('[wakdo][auth] reset request failure: ' . $exception->getMessage());
            }
        }

        return $this->view('auth/forgot', [
            'title'     => 'Mot de passe oublié - Wakdo Admin',
            'csrfToken' => Csrf::token($this->sessionManager()),
            'notice'    => self::NEUTRAL_NOTICE,
        ], $blocked ? 429 : 200);
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
            return $this->renderConfirm($token, 'Session expirée, merci de réessayer.', 403);
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

    protected function resetThrottle(): PasswordResetThrottle
    {
        return new PasswordResetThrottle($this->database, $this->config);
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

    /**
     * D-7.a (revue adverse, contre-audit 30/09) : cette page porte le jeton
     * BRUT de reinitialisation dans sa propre URL (`?token=...`). La politique
     * par defaut (`Referrer-Policy: strict-origin-when-cross-origin`, posee
     * par `Response::headers()`) laisse le navigateur envoyer l'URL COMPLETE --
     * jeton compris -- en en-tete Referer sur toute requete de MEME origine :
     * les ressources de cette page (feuille de style, script, logo,
     * `layout.php`/`reset.php`) ET l'envoi du formulaire lui-meme (qui poste
     * vers `/reset_password`) en sont, capturant le jeton dans le journal
     * d'acces (`%{Referer}i`) meme sur un envoi qui echoue (jeton encore
     * valable jusqu'a 1h). `no-referrer` (aucune restriction sur QUELLE partie
     * serait sure a envoyer : rien n'est envoye) coupe les trois fuites d'un
     * coup.
     *
     * Posee ICI (cote application) : `App\Core\Response::headers()` pose une
     * valeur par defaut ("strict-origin-when-cross-origin") pour toute reponse
     * qui ne l'a pas deja fixee, donc `setHeader()` ci-dessous, appele AVANT
     * `send()`, l'emporte pour cette seule route -- que ce soit CE defaut PHP
     * ou celui, equivalent, pose par le vhost admin (`Header ... "expr=-z
     * resp('Referrer-Policy')"`, D-7.b, `docker/apache/vhost.conf`) pour les
     * reponses qu'Apache seul peut produire (fichiers statiques, erreurs
     * Apache). Plusieurs mecanismes Apache pour exclure CONDITIONNELLEMENT
     * cette seule route sans le concours de PHP ont ete essayes et ecartes
     * avant D-7.b -- voir `docker/apache/vhost.conf` pour le detail.
     */
    private function renderConfirm(string $token, ?string $error, int $status = 200): Response
    {
        return $this->view('auth/reset', [
            'title'     => 'Nouveau mot de passe - Wakdo Admin',
            'csrfToken' => Csrf::token($this->sessionManager()),
            'token'     => $token,
            'error'     => $error,
        ], $status)->setHeader('Referrer-Policy', 'no-referrer');
    }
}
