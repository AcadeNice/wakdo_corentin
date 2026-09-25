<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Api;

use stdClass;
use App\Auth\Csrf;
use App\Auth\GuardResult;
use App\Auth\PasswordHasher;
use App\Auth\PinGate;
use App\Auth\PinThrottle;
use App\Auth\PinVerifier;
use App\Core\NumericInput;
use App\Core\Response;

/**
 * Reponses et gardes communes de l'API d'administration JSON (`/admin/api/...`,
 * docs/api/conventions.md section 5.3). Sous `/admin` (et non `/api`) : le vhost
 * kiosk ne relaie que `/api/*` (docker/apache/vhost.conf), donc cette API
 * authentifiee ne traverse jamais l'origine borne (reduction de surface
 * d'attaque, meme raisonnement que `/admin/me`).
 *
 * TRAIT plutot que classe de base : chaque `*ApiController` etend le controleur
 * HTML existant de sa ressource (ex. `CategoryApiController extends
 * CategoryController`) pour reutiliser SA validation et SES repositories
 * (methodes anciennement `private`, elargies a `protected`) sans dupliquer les
 * regles metier. PHP n'autorise qu'un seul parent : ce trait apporte les reponses
 * JSON et la porte PIN/CSRF, propres au transport API, sans consommer ce seul lien
 * d'heritage. Suppose un hote qui etend (directement ou non) `AuthenticatedController`
 * (fournit `sessionGuard()`, `authorizer()`, `sessionManager()`, `db()`, `config`,
 * `request`, `json()`).
 *
 * @property-read \App\Core\Config $config
 * @property-read \App\Core\Request $request
 */
trait JsonApiTrait
{
    /**
     * Garde JSON : 401 si la session est absente/expiree/inactive (RG-6/RG-T02),
     * 403 si $permission est fournie et non detenue (RG-T03). Sinon la
     * GuardResult authentifiee. L'appelant fait : if ($g instanceof Response) return $g;
     */
    protected function guardApi(?string $permission = null): GuardResult|Response
    {
        $result = $this->sessionGuard()->check();

        if (!$result->authenticated || $result->userId === null || $result->roleId === null) {
            return $this->errorResponse(401, 'AUTH_REQUIRED', 'Authentification requise');
        }

        if ($permission !== null && !$this->authorizer()->can($result->roleId, $permission)) {
            return $this->errorResponse(403, 'FORBIDDEN', 'Permission manquante');
        }

        return $result;
    }

    /**
     * Valide le jeton CSRF (RG-T01) transmis dans l'en-tete `X-CSRF-Token`, obtenu
     * par le client via `GET /admin/me` (champ `csrf_token`). Meme jeton
     * synchroniseur que le back-office HTML (`App\Auth\Csrf`) ; seul le point de
     * transport change (en-tete plutot qu'un champ de formulaire cache `_csrf`).
     */
    protected function requireCsrf(): ?Response
    {
        if (Csrf::validate($this->sessionManager(), $this->request->header('X-CSRF-Token'))) {
            return null;
        }

        return $this->errorResponse(403, 'CSRF_INVALID', 'Jeton CSRF absent ou invalide (en-tête X-CSRF-Token)');
    }

    /**
     * Corps JSON de la requete : trois garde-fous avant de le rendre a l'appelant.
     *
     * 1. Content-Type : un corps non vide DOIT s'annoncer `application/json` ;
     *    un autre type (ou aucun) sur un corps non vide -> `415
     *    UNSUPPORTED_MEDIA_TYPE` (RFC 9110 §15.5.16), documente en section 5.3 de
     *    docs/api/conventions.md.
     * 2. Syntaxe JSON : un corps illisible -> `400 INVALID_JSON`, plutot que de
     *    laisser la validation champ-par-champ produire un 422 trompeur (un champ
     *    "manquant" alors que le vrai probleme est un JSON illisible).
     * 3. Forme de la racine : SEUL un objet JSON (`{...}`) est accepte. Une liste
     *    (`[...]`), un nombre, une chaine ou un booleen a la racine -> `400
     *    INVALID_JSON` (impossible a interpreter comme un ensemble de champs).
     *    `json_decode()` SANS le mode associatif distingue un objet JSON
     *    (`stdClass`) d'une liste (`array`), ce que le mode associatif seul ne
     *    permet pas ({} et [] deviennent tous deux `[]` en PHP).
     *
     * @return array<string, mixed>|Response
     */
    protected function requireJsonBody(): array|Response
    {
        $raw = trim($this->request->rawBody());
        if ($raw === '' || $raw === 'null') {
            return [];
        }

        $contentType = strtolower((string) ($this->request->header('Content-Type') ?? ''));
        if (!str_starts_with($contentType, 'application/json')) {
            return $this->errorResponse(415, 'UNSUPPORTED_MEDIA_TYPE', 'Content-Type doit être application/json pour un corps non vide');
        }

        $probe = json_decode($raw);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->errorResponse(400, 'INVALID_JSON', 'Corps JSON invalide');
        }
        if (!($probe instanceof stdClass)) {
            return $this->errorResponse(400, 'INVALID_JSON', 'Le corps JSON doit être un objet ({...}), pas une liste ni une valeur simple');
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true);

        return $decoded;
    }

    /**
     * Vrai si $value peut nourrir un champ de formulaire "forme HTML" (absent,
     * `null`, ou scalaire) : un tableau ou un objet JSON pour un champ cense etre
     * simple n'est PAS caste en silence en `"Array"` (ce que ferait `(string)
     * $value`, avec un avertissement PHP) -- l'appelant doit alors refuser le
     * champ (422) plutot que de le passer a `validate()`.
     */
    protected function isScalarField(mixed $value): bool
    {
        return $value === null || is_scalar($value);
    }

    /**
     * Construit le tableau de chaines "forme formulaire" attendu par les
     * `validate()` herites des controleurs HTML, a partir d'un sous-ensemble
     * scalaire du corps JSON. Un champ absent ou `null` recoit sa valeur par
     * defaut ; un champ non scalaire (tableau/objet) devient une erreur de
     * validation NOMMEE (agregee avec celles de `validate()`) plutot qu'un cast
     * silencieux. Un booleen JSON (`true`/`false`) se convertit en `"1"`/`""`,
     * meme convention qu'une case a cocher HTML absente = valeur vide.
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $defaults cle du corps => valeur par defaut si absente/null
     * @return array{0: array<string, string>, 1: array<string, string>} [form, erreurs]
     */
    protected function scalarForm(array $body, array $defaults): array
    {
        $form = [];
        $errors = [];
        foreach ($defaults as $key => $default) {
            $value = array_key_exists($key, $body) ? $body[$key] : null;
            if ($value === null) {
                $form[$key] = $default;
                continue;
            }
            if (!is_scalar($value)) {
                $errors[$key] = 'Ce champ doit être une valeur simple (texte, nombre ou booléen), pas un tableau ni un objet.';
                $form[$key] = $default;
                continue;
            }
            $form[$key] = is_bool($value) ? ($value ? '1' : '') : (string) $value;
        }

        return [$form, $errors];
    }

    /**
     * SEUL point d'acces autorise a un champ CHAINE du corps JSON dans les
     * controleurs `*ApiController` (relecture adverse, point 1) : un controleur
     * qui lit `$body['x']` ou le caste `(string) $body['x']` directement risque le
     * cast PHP silencieux d'un tableau en la chaine littérale "Array" (avec un
     * avertissement, ex. `restock` avec `{"packs":2,"note":["x"]}` enregistrait
     * la note "Array"). Absent/`null` -> `$default` ; scalaire -> chaine (`true`
     * devient `"1"`, `false` devient `""`, meme convention qu'une case a cocher
     * HTML absente) ; tableau/objet -> 422 NOMME plutot qu'un cast silencieux.
     * @param array<string, mixed> $body
     */
    protected function fieldString(array $body, string $key, string $default = ''): string|Response
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return $default;
        }

        $value = $body[$key];
        if (!is_scalar($value)) {
            return $this->validationErrorResponse([$key => 'Ce champ doit être une valeur simple (texte, nombre ou booléen), pas un tableau ni un objet.']);
        }

        return is_bool($value) ? ($value ? '1' : '') : (string) $value;
    }

    /**
     * SEUL point d'acces autorise a un champ ENTIER du corps JSON (point 1) :
     * delegue a `NumericInput` (source unique avec le formulaire HTML), donc
     * aucun flottant (2.9), notation scientifique ("1e3") ou booleen n'est
     * jamais tronque en silence. `$required` distingue un champ obligatoire
     * (absent -> erreur NOMMEE) d'un champ optionnel (absent -> `$default`,
     * jamais valide). `$signed` autorise un signe `-` (ajustements de stock).
     * @param array<string, mixed> $body
     */
    protected function fieldInt(array $body, string $key, int $min, int $max, int $default = 0, bool $required = false, bool $signed = false): int|Response
    {
        $present = array_key_exists($key, $body) && $body[$key] !== null;
        if (!$present) {
            if ($required) {
                return $this->validationErrorResponse([$key => 'Ce champ est requis et doit être un entier.']);
            }

            return $default;
        }

        $value = $signed
            ? NumericInput::signedDigits($body[$key], $min, $max)
            : NumericInput::digits($body[$key], $min, $max);
        if ($value === null) {
            return $this->validationErrorResponse([$key => "Ce champ doit être un entier valide entre {$min} et {$max}."]);
        }

        return $value;
    }

    /**
     * Valide qu'une valeur DEJA extraite du corps est un booleen JSON STRICT
     * (`true`/`false` natifs) : ni la chaine `"false"` (non vide, donc
     * "truthy" pour un `!empty()` naif -- le bug corrige ici, relecture point 6),
     * ni `0`/`1`, ni `"on"`/`"off"`. Usage interne des deux accesseurs booleens
     * ci-dessous ; un appelant exterieur au trait ne doit jamais lire `$body[...]`
     * lui-meme pour construire `$value` (point 1).
     */
    protected function requireBool(mixed $value, string $key): bool|Response
    {
        if (!is_bool($value)) {
            return $this->validationErrorResponse([$key => 'Ce champ doit être un booléen JSON strict (true ou false), pas une chaîne ni un nombre.']);
        }

        return $value;
    }

    /**
     * Champ booleen optionnel a la CREATION : absent/`null` -> `$default` fixe
     * (ex. `is_available` = `false` par defaut sur un nouveau produit) ;
     * present -> `requireBool()`.
     * @param array<string, mixed> $body
     */
    protected function fieldBoolCreate(array $body, string $key, bool $default): bool|Response
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return $default;
        }

        return $this->requireBool($body[$key], $key);
    }

    /**
     * Champ booleen d'un PUT PARTIEL : absent/`null` -> PRESERVE `$current`
     * (ne desactive/rend jamais indisponible par omission) ; present ->
     * `requireBool()`. Meme regle pour `is_active` (users/roles) et
     * `is_available` (produits/menus) : un seul point d'implementation pour
     * cette semantique (relecture point 6).
     * @param array<string, mixed> $body
     */
    protected function fieldBoolPreserving(array $body, string $key, bool $current): bool|Response
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return $current;
        }

        return $this->requireBool($body[$key], $key);
    }

    /**
     * Variante MONETAIRE de l'entier strict (`price_cents`, `price_normal_cents`,
     * `price_maxi_cents`) : contrairement a `fieldInt()` (qui accepte aussi une
     * chaine de chiffres, pour rester compatible avec les champs partages avec le
     * formulaire HTML via `NumericInput`), un montant en centimes n'a PAS
     * d'equivalent formulaire HTML -- seul le type JSON natif `int` est accepte,
     * jamais une chaine ("590"), jamais un flottant (590.9), jamais "12,50" relu
     * comme des euros. Absent/`null` -> `null` (l'appelant decide si le champ est
     * obligatoire) ; present et non-`int` -> 422 NOMME.
     * @param array<string, mixed> $body
     */
    protected function fieldStrictJsonInt(array $body, string $key): int|null|Response
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }
        if (!is_int($body[$key])) {
            return $this->validationErrorResponse([$key => "{$key} doit être un entier (centimes), pas un texte ni un nombre décimal."]);
        }

        return $body[$key];
    }

    /**
     * SEUL point d'acces autorise a une liste d'entiers STRICTS du corps JSON
     * (`permission_ids`, `allergen_ids`...) : absent -> liste vide ; racine
     * non-liste -> 422 NOMME ; un seul element non entier strict (`"1.9"`,
     * `"abc"`, un objet) -> 422 NOMME, la liste entiere est refusee (jamais un
     * filtrage silencieux qui tronquerait la demande -- le bug corrige ici,
     * relecture point 6 : `"1.9"` etait accepte par `is_numeric()` puis tronque
     * a `1` par `(int)`).
     *
     * @return list<int>|Response
     * @param array<string, mixed> $body
     */
    protected function fieldIntList(array $body, string $key, int $min, int $max): array|Response
    {
        $raw = $body[$key] ?? [];
        if (!is_array($raw)) {
            return $this->validationErrorResponse([$key => 'Ce champ doit être une liste d\'entiers.']);
        }

        $result = [];
        foreach ($raw as $item) {
            $value = NumericInput::digits($item, $min, $max);
            if ($value === null) {
                return $this->validationErrorResponse([$key => 'Chaque élément de la liste doit être un entier valide (aucun décimal).']);
            }
            $result[] = $value;
        }

        return $result;
    }

    /**
     * SEUL point d'acces autorise a une liste GENERIQUE du corps JSON (items de
     * commande, composition de recette, slots de menu, sources visibles d'un
     * role) : absent -> liste vide ; racine non-liste -> 422 NOMME. Le contenu
     * de chaque element reste valide plus loin par les regles heritees
     * (`decodeItems()`, `parseComposition()`...), inchangees par ce refactor.
     *
     * @return list<mixed>|Response
     * @param array<string, mixed> $body
     */
    protected function fieldList(array $body, string $key): array|Response
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return [];
        }
        if (!is_array($body[$key])) {
            return $this->validationErrorResponse([$key => 'Ce champ doit être une liste.']);
        }

        return $body[$key];
    }

    protected function pinGate(): PinGate
    {
        return new PinGate($this->db(), $this->pinVerifierApi(), $this->pinThrottleApi());
    }

    protected function pinVerifierApi(): PinVerifier
    {
        return new PinVerifier($this->db(), $this->config, new PasswordHasher($this->config));
    }

    protected function pinThrottleApi(): PinThrottle
    {
        return new PinThrottle($this->db(), $this->config);
    }

    /**
     * Champs PIN attendus dans le corps JSON (modele "identifiant equipier + PIN",
     * RG-T13) : `pin_email` + `pin`, memes noms que les champs de formulaire HTML
     * (`pin_email`/`pin`) pour rester un seul modele mental cote equipe.
     *
     * @param array<string, mixed> $body
     * @return array{0: string, 1: string}
     */
    protected function pinFields(array $body): array
    {
        $email = is_string($body['pin_email'] ?? null) ? trim((string) $body['pin_email']) : '';
        $pin = is_scalar($body['pin'] ?? null) ? (string) $body['pin'] : '';

        return [$email, $pin];
    }

    protected function notFoundResponse(): Response
    {
        return $this->errorResponse(404, 'NOT_FOUND', 'Ressource introuvable');
    }

    protected function conflictResponse(string $message): Response
    {
        return $this->errorResponse(409, 'CONFLICT', $message);
    }

    /**
     * @param array<string, string> $fields
     */
    protected function validationErrorResponse(array $fields): Response
    {
        return $this->json([
            'data'  => null,
            'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Entrée invalide', 'fields' => $fields],
        ], 422);
    }

    protected function pinErrorResponse(string $message): Response
    {
        return $this->json(['data' => null, 'error' => ['code' => 'PIN_INVALID', 'message' => $message]], 422);
    }

    protected function errorResponse(int $status, string $code, string $message): Response
    {
        return $this->json(['data' => null, 'error' => ['code' => $code, 'message' => $message]], $status);
    }

    protected function okResponse(mixed $data, int $status = 200): Response
    {
        return $this->json(['data' => $data], $status);
    }

    /**
     * 201 Created + en-tete `Location` (RFC 9110 §10.2.2), pour toute ressource
     * nouvellement creee (POST /admin/api/<ressource>).
     */
    protected function createdResponse(mixed $data, string $location): Response
    {
        return $this->json(['data' => $data], 201)->setHeader('Location', $location);
    }

    /**
     * `SELECT LAST_INSERT_ID()` sur la connexion courante : les repositories dont
     * `create()` ne renvoie pas l'id (CategoryRepository, ProductRepository,
     * IngredientRepository) suivent la meme technique que UserRepository::create()
     * pour recuperer l'id de la ligne qui vient d'etre inseree.
     */
    protected function lastInsertId(): int
    {
        $row = $this->db()->fetch('SELECT LAST_INSERT_ID() AS id');

        return (int) ($row['id'] ?? 0);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    protected function collectionResponse(array $rows): Response
    {
        return $this->json(['data' => $rows, 'total' => count($rows)], 200);
    }
}
