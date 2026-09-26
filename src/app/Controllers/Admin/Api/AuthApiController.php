<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Api;

use Throwable;
use App\Auth\AuthService;
use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\UserDirectory;
use App\Controllers\MeController;
use App\Core\Response;

/**
 * Connexion JSON de l'API d'administration (`/admin/api/auth/*`), docs/api/conventions.md
 * section 5.3bis. Motif : demontrer le contrat en Postman sans manipulation manuelle du
 * cookie de session (jusqu'ici : ouvrir les outils de developpement du navigateur, copier
 * `WAKDO_SID`, l'injecter a la main dans Postman) -- trop fragile pour une demonstration
 * devant jury. Le modele de securite NE CHANGE PAS : session serveur en cookie HttpOnly +
 * SameSite=Strict (`SessionManager`), jeton CSRF synchroniseur (`App\Auth\Csrf`). Aucun jeton
 * en local storage, aucune cryptographie maison : `POST /admin/api/auth/login` POSE le meme
 * cookie que `POST /login` (formulaire HTML) et renvoie le meme jeton CSRF que `GET /admin/me`
 * dans le corps de sa reponse, pour que Postman n'ait plus besoin de faire les deux etapes
 * separement.
 *
 * `extends MeController` (et non `AuthenticatedController` directement), memes raisons que
 * `CategoryApiController extends CategoryController` (ADR-0017) : `apiMe()` APPELLE
 * `show()` telle quelle (vraie reutilisation, pas une reimplementation), et herite au passage
 * de `sessionGuard()`/`authorizer()`/`sessionManager()`/`db()` (chaine `AuthenticatedController`)
 * necessaires a `apiLogout()`.
 *
 * `authService()` reproduit le hook prive d'`App\Controllers\AuthController` (meme
 * construction : `AuthService($database, $config, $sessionManager, $hasher)`) -- ce
 * controleur n'etend PAS `AuthController` (celui-ci n'expose ni `sessionGuard()` ni
 * `authorizer()`, requis par `apiLogout()`/`apiMe()`), donc le hook est duplique ici,
 * a l'identique, plutot que factorise a travers deux heritages incompatibles.
 *
 * Hors matrice CSRF/permission de `RouteMatrixTest` (voir son exclusion de
 * `/admin/api/auth/` documentee dans ce fichier) : ces trois routes n'ont ni permission
 * (roles NON authentifie pour `apiLogin`) ni le meme modele CSRF (`apiLogin` n'a PAS de
 * session avant de reussir, donc pas de jeton synchroniseur a comparer -- sa protection
 * CSRF repose sur le Content-Type impose (force un preflight CORS ferme sur ce prefixe,
 * seul mecanisme qui empeche reellement l'envoi de la requete forgee depuis un navigateur ;
 * `SameSite=Strict` protege une phase differente -- l'envoi du cookie sur les requetes
 * ULTERIEURES, pas la creation de ce cookie -- detail et source dans
 * docs/api/conventions.md section 5.3bis). Testees a part dans `AuthApiControllerTest`.
 */
class AuthApiController extends MeController
{
    use JsonApiTrait;

    private const GENERIC_ERROR = 'Email ou mot de passe incorrect';

    /**
     * POST /admin/api/auth/login. PAS de guardApi() (aucune session requise avant
     * d'en creer une) ni de requireCsrf() (le jeton synchroniseur n'existe pas encore
     * avant l'authentification reussie -- cf. docblock de classe pour la protection
     * CSRF retenue a la place). Reutilise AuthService::authenticate() a l'identique
     * d'App\Controllers\AuthController::login() : meme limitation par compte et par
     * IP, meme ralentissement degressif, meme message generique d'echec, meme
     * regeneration de session, meme controle de compte actif (tout est dans
     * AuthService, aucune regle de securite n'est reecrite ici).
     *
     * @param array<string, string> $params
     */
    public function apiLogin(array $params = []): Response
    {
        $body = $this->requireJsonBody();
        if ($body instanceof Response) {
            return $body;
        }

        $emailRaw = $body['email'] ?? null;
        $passwordRaw = $body['password'] ?? null;

        $fieldErrors = [];
        if (!$this->isScalarField($emailRaw)) {
            $fieldErrors['email'] = 'Ce champ doit être une valeur simple (texte), pas un tableau ni un objet.';
        }
        if (!$this->isScalarField($passwordRaw)) {
            $fieldErrors['password'] = 'Ce champ doit être une valeur simple (texte), pas un tableau ni un objet.';
        }
        if ($fieldErrors !== []) {
            return $this->validationErrorResponse($fieldErrors);
        }

        $email = trim((string) ($emailRaw ?? ''));
        $password = (string) ($passwordRaw ?? '');

        // RG-T18 : memes bornes que le formulaire HTML (AuthController::login()).
        // Verifiees AVANT tout appel a AuthService : un format invalide est un
        // probleme de SAISIE (422), pas une tentative d'identifiants (401) -- la
        // distinction ne fuit rien puisqu'elle est evaluee identiquement pour
        // n'importe quelle entree de cette forme, avant toute lecture en base.
        $boundErrors = [];
        if ($email === '' || strlen($email) > 254) {
            $boundErrors['email'] = "L'email est requis (254 caractères maximum).";
        }
        if ($password === '' || strlen($password) > 4096) {
            $boundErrors['password'] = 'Le mot de passe est requis (4096 caractères maximum).';
        }
        if ($boundErrors !== []) {
            return $this->validationErrorResponse($boundErrors);
        }

        try {
            $result = $this->authService()->authenticate($email, $password, $this->request->clientIp());
        } catch (Throwable $exception) {
            // Fail-closed, meme comportement que le formulaire HTML : une panne base
            // ne doit jamais authentifier ni divulguer sa nature.
            error_log('[wakdo][auth-api] login failure: ' . $exception->getMessage());

            return $this->errorResponse(401, 'INVALID_CREDENTIALS', self::GENERIC_ERROR);
        }

        if (!$result->success) {
            if ($result->retryAfterSeconds !== null) {
                return $this->errorResponse(429, 'TOO_MANY_ATTEMPTS', $result->error ?? self::GENERIC_ERROR)
                    ->setHeader('Retry-After', (string) $result->retryAfterSeconds);
            }

            return $this->errorResponse(401, 'INVALID_CREDENTIALS', $result->error ?? self::GENERIC_ERROR);
        }

        /** @var int $userId */
        $userId = $result->userId;
        /** @var int $roleId */
        $roleId = $result->roleId;

        $authorizer = $this->authorizer();
        $info = (new UserDirectory($this->db()))->displayInfo($userId);

        return $this->okResponse([
            'user' => [
                'id'           => $userId,
                'email'        => $info['email'] !== '' ? $info['email'] : $email,
                'display_name' => $info['name'],
                'role'         => $authorizer->roleCode($roleId) ?? '',
            ],
            'permissions' => $authorizer->permissionsFor($roleId),
            'csrf_token'  => Csrf::token($this->sessionManager()),
        ]);
    }

    /**
     * POST /admin/api/auth/logout : session + CSRF requis (a la difference du
     * formulaire HTML qui ne verifie QUE le CSRF -- ici la garde 401 passe
     * d'abord, meme convention que toute route `/admin/api/*` protegee). Detruit
     * la session (App\Auth\AuthService::logout(), inchange) puis repond 204 SANS
     * corps -- rien a renvoyer, la session n'existe plus.
     *
     * @param array<string, string> $params
     */
    public function apiLogout(array $params = []): Response
    {
        $guard = $this->guardApi();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (($csrf = $this->requireCsrf()) !== null) {
            return $csrf;
        }

        $this->authService()->logout();

        return (new Response())->setStatus(204);
    }

    /**
     * GET /admin/api/auth/me : ALIAS pur de GET /admin/me (garde, toujours servi
     * -- docblock de MeController), pour que toute la demonstration Postman/Bruno
     * reste sous le seul prefixe `/admin/api/...`. Delegue a `show()` HERITE de
     * MeController (aucune reimplementation : meme identite, memes permissions,
     * meme jeton CSRF, meme code 401 si la session est absente/expiree/inactive).
     *
     * @param array<string, string> $params
     */
    public function apiMe(array $params = []): Response
    {
        return $this->show($params);
    }

    /**
     * Meme construction que le hook prive d'App\Controllers\AuthController
     * (docblock de classe : pourquoi il est duplique ici plutot que partage).
     */
    protected function authService(): AuthService
    {
        return new AuthService(
            $this->db(),
            $this->config,
            $this->sessionManager(),
            new PasswordHasher($this->config),
        );
    }
}
