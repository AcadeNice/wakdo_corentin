<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Core\DatabaseInterface;
use Throwable;

/**
 * Double de test de DatabaseInterface : aucune connexion reelle. Les lectures
 * sont scriptees par des "boutons" types (userRow, ipLockoutUntil,
 * ipFailedAttempts), les ecritures sont enregistrees pour assertion, et les
 * transactions tracent begin/commit/rollback. Permet de tester les branches de
 * securite d'AuthService / PasswordResetService sans base de donnees.
 */
final class FakeDatabase implements DatabaseInterface
{
    /**
     * Reponse de la recherche utilisateur (RG-1) ; null = email inconnu.
     *
     * @var array<string, mixed>|null
     */
    public ?array $userRow = null;

    /**
     * Hash STOCKE de reference renvoye par 'SELECT password_hash FROM user
     * LIMIT 1' (AuthService::referenceHashForDecoy(), calibrage du leurre sur
     * email inconnu) ; null = aucun utilisateur en base (borne de demarrage).
     */
    public ?string $referenceUserPasswordHash = null;

    /** lockout_until renvoye pour la porte de throttling IP ; null = pas de verrou. */
    public ?string $ipLockoutUntil = null;

    /**
     * Compteur login_throttle relu apres l'upsert atomique (sert au calcul du
     * backoff IP en PHP) ; null => 1 par defaut cote service.
     *
     * @var array<string, mixed>|null
     */
    public ?array $throttleRow = null;

    /**
     * Compteur DU COMPTE (user.failed_login_attempts) relu apres son increment
     * atomique (D-3, AuthService::recordFailure()) ; null => 1 par defaut cote
     * service. Pendant DISTINCT de $throttleRow (dimension IP).
     *
     * @var array<string, mixed>|null
     */
    public ?array $accountThrottleRow = null;

    /**
     * lockout_until DU COMPTE (user.lockout_until) renvoye pour la porte de
     * AccountLockout::isLocked() (D-1.a, reverification du mot de passe sur
     * /admin/profile/pin) ; null = pas de verrou. DISTINCT de
     * $pinThrottleLockoutUntil (pin_throttle) : les deux compteurs ne doivent
     * plus jamais s'influencer l'un l'autre.
     */
    public ?string $userAccountLockoutUntil = null;

    /**
     * Reponse de la recherche par token de reinitialisation (12.3) ; null = aucun.
     *
     * @var array<string, mixed>|null
     */
    public ?array $resetUserRow = null;

    /**
     * Reponse de la recherche par email (phase demande de reinitialisation) ; null = inconnu.
     *
     * @var array<string, mixed>|null
     */
    public ?array $emailLookupRow = null;

    /**
     * Reponse de la verification is_active + role_id + session_epoch du
     * SessionGuard (RG-T02) ; null = absent.
     *
     * @var array<string, mixed>|null
     */
    public ?array $guardUserRow = null;

    /** Resultat de Authorizer::can() (true = permission accordee). */
    public bool $canResult = false;

    /** Etat role.is_active modelise pour can()/permissionsFor() ; false => rien accorde. */
    public bool $roleActive = true;

    /**
     * Trace des lectures (fetch/fetchAll) pour asserter les parametres lies
     * (ex. liaison par code de permission, RG-T03), pendant que $writes trace les ecritures.
     *
     * @var list<array{sql: string, params: array<string|int, mixed>}>
     */
    public array $reads = [];

    /**
     * Codes de permission renvoyes par Authorizer::permissionsFor().
     *
     * @var list<string>
     */
    public array $permissionCodes = [];

    /**
     * Ligne role renvoyee pour la lecture du code de role (/admin/me) ; null = absent.
     *
     * @var array<string, mixed>|null
     */
    public ?array $roleRow = null;

    /**
     * Ligne user renvoyee pour la verification du PIN (RG-T13) ; null = absent/inactif.
     *
     * @var array<string, mixed>|null
     */
    public ?array $pinUserRow = null;

    /**
     * Ligne renvoyee pour UserDirectory::displayInfo (nom + libelle role) ; null = absent.
     *
     * @var array<string, mixed>|null
     */
    public ?array $userDisplayRow = null;

    /**
     * Lignes renvoyees par CategoryRepository::all().
     *
     * @var list<array<string, mixed>>
     */
    public array $categoriesRows = [];

    /**
     * Ligne renvoyee par CategoryRepository::find() ; null = introuvable.
     *
     * @var array<string, mixed>|null
     */
    public ?array $categoryRow = null;

    /** Resultat de CategoryRepository::nameExists(). */
    public bool $categoryNameTaken = false;

    /** Resultat de CategoryRepository::slugExists(). */
    public bool $categorySlugTaken = false;

    /**
     * Lignes renvoyees par CategoryIngredientFamilyRepository::mapByCategory()
     * (migration 0017), au format brut {category_id, family} avant regroupement.
     *
     * @var list<array<string, mixed>>
     */
    public array $categoryIngredientFamilyRows = [];

    /** Resultat de UserRepository::pinIsSet() (true = un PIN est defini). */
    public bool $userPinSet = false;

    /**
     * Ligne {password_hash} renvoyee pour la re-verification d'identite au set de PIN
     * (ProfileController::currentPasswordHash) ; null = compte absent/inactif.
     *
     * @var array<string, mixed>|null
     */
    public ?array $currentPasswordRow = null;

    /**
     * Lignes renvoyees par ProductRepository::all().
     *
     * @var list<array<string, mixed>>
     */
    public array $productsRows = [];

    /**
     * Lignes PLATES renvoyees a la requete ProductRepository::basesByCategory() (F20) ;
     * le depot les groupe lui-meme par category_id.
     *
     * @var list<array<string, mixed>>
     */
    public array $basesByCategoryRows = [];

    /**
     * Ligne renvoyee par ProductRepository::find() ; null = introuvable.
     *
     * @var array<string, mixed>|null
     */
    public ?array $productRow = null;

    /**
     * Lignes renvoyees par ProductRepository::find(id), indexees PAR ID -- contrairement
     * a $productRow (une seule reponse pour n'importe quel id). Sert la disponibilite
     * de CHAQUE option de slot du composeur comptoir/drive (CounterOrderController::
     * slotsWithAvailability, defaut #4/RG-T21), qui appelle find() une fois par option et
     * a besoin d'une reponse DIFFERENTE par id. Verifiee AVANT $productRow (repli) :
     * un id absent d'ici retombe sur $productRow pour ne rien casser des tests existants
     * qui reutilisent ce bouton unique pour le burger ET l'option selectionnee.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $productByIdRows = [];

    /**
     * Lignes {id, name} renvoyees par ProductRepository::basesOnly() (R4/F9-1) :
     * produits de base eligibles aux selects menu / formulaire produit.
     *
     * @var list<array<string, mixed>>
     */
    public array $baseProductsRows = [];

    /**
     * Resultat de ProductRepository::productIsBase() / MenuRepository::productIsBase()
     * (R4/F9-2) : true => l'id designe un produit de BASE (base_product_id IS NULL).
     * Defaut true : un produit ordinaire est une base ; un test le passe a false pour
     * simuler une VARIANTE de taille presentee la ou seules les bases sont eligibles.
     */
    public bool $productIsBase = true;

    /**
     * Ligne {category_id} renvoyee par ProductRepository::reorderWithinCategory()
     * pour son SELECT initial (categorie du produit a deplacer) ; null = produit
     * introuvable ou variante (base_product_id non NULL, exclue par la requete).
     *
     * @var array<string, mixed>|null
     */
    public ?array $reorderProductRow = null;

    /**
     * Lignes {id} renvoyees par ProductRepository::reorderWithinCategory() pour la
     * liste ORDONNEE des produits de BASE de la categorie deplacee (deuxieme
     * lecture, apres le lookup initial ci-dessus).
     *
     * @var list<array<string, mixed>>
     */
    public array $reorderCategoryIdsRows = [];

    /**
     * Slug de categorie renvoye par MenuRepository::productCategorySlug() (garde F12) ;
     * null => productCategorySlug() retourne null (id inconnu / produit sans categorie),
     * ce qui fait rejeter l'option par le controleur. Defaut 'boissons' : aligne sur le
     * slot 'drink' du formulaire valide de reference (validForm), donc une option passe
     * la garde de categorie par defaut. Un test le change pour simuler une option hors
     * categorie (ex. 'burgers' dans un slot 'drink').
     */
    public ?string $productCategorySlug = 'boissons';

    /**
     * Ligne renvoyee par MenuRepository::find() ; null = introuvable.
     *
     * @var array<string, mixed>|null
     */
    public ?array $menuRow = null;

    /**
     * Ligne renvoyee par OrderRepository::findByNumber() / cancel() (lecture par
     * order_number) ; null = numero inconnu.
     *
     * @var array<string, mixed>|null
     */
    public ?array $orderByNumberRow = null;

    /**
     * Ligne {source} renvoyee pour OrderAdminController::orderSource (garde de
     * visibilite PRE-3, 6.1) ; null = numero inconnu (traite comme non visible).
     *
     * @var array<string, mixed>|null
     */
    public ?array $orderSourceRow = null;

    /**
     * Lignes renvoyees par MenuRepository::all().
     *
     * @var list<array<string, mixed>>
     */
    public array $menusRows = [];

    /**
     * Lignes (LEFT JOIN slot/option) renvoyees par MenuRepository::slotsWithOptions().
     *
     * @var list<array<string, mixed>>
     */
    public array $menuSlotRows = [];

    /** Resultat de MenuRepository::isReferencedByOrders() (true = reference par une commande). */
    public bool $menuReferenced = false;

    /**
     * Ids de menu_slot DEJA reference par order_item_selection (garde FK-safe de
     * reconcileSlots(), MenuRepository::isSlotReferencedByOrders()) ; vide = aucun
     * slot n'est reference, tout retrait de slot est accepte.
     *
     * @var list<int>
     */
    public array $referencedSlotIds = [];

    /**
     * Ligne renvoyee pour IngredientRepository::find() et les lectures ciblees de
     * restock/inventory (pack_size, stock_quantity) ; null = introuvable.
     *
     * @var array<string, mixed>|null
     */
    public ?array $ingredientRow = null;

    /**
     * Identifiants d'ingredients qui EXISTENT, quand un scenario en a besoin de
     * plusieurs (une recette a plusieurs lignes, alors que $ingredientRow n'en
     * decrit qu'une). Vide = on retombe sur l'id porte par $ingredientRow.
     *
     * @var list<int>
     */
    public array $existingIngredientIds = [];

    /**
     * Lignes renvoyees par IngredientRepository::all().
     *
     * @var list<array<string, mixed>>
     */
    public array $ingredientsRows = [];

    /** Resultat de IngredientRepository::nameExists(). */
    public bool $ingredientNameTaken = false;

    /**
     * Lignes renvoyees par IngredientRepository::movements().
     *
     * @var list<array<string, mixed>>
     */
    public array $movementsRows = [];

    /**
     * Catalogue des 14 allergenes INCO renvoye par AllergenRepository::all() (F11b) :
     * alimente la matrice de cases du formulaire ingredient.
     *
     * @var list<array<string, mixed>>
     */
    public array $allergensRows = [];

    /**
     * Lignes {allergen_id} renvoyees par AllergenRepository::allergenIdsForIngredient()
     * (F11b) : les cases deja cochees pour cet ingredient.
     *
     * @var list<array<string, mixed>>
     */
    public array $ingredientAllergenRows = [];

    /**
     * Lignes renvoyees par ProductRepository::composition() (JOIN product_ingredient/ingredient).
     *
     * @var list<array<string, mixed>>
     */
    public array $compositionRows = [];

    /**
     * Lignes {product_id} renvoyees par ProductRepository::autoUnavailableIds()
     * (produits en rupture automatique par le stock, RG-T21).
     *
     * @var list<array<string, mixed>>
     */
    public array $autoUnavailableRows = [];

    /** Compteur renvoye par ProductRepository::compositionCount() (trace cascade #27). */
    public int $productCompositionCount = 0;

    /**
     * Lignes renvoyees par UserRepository::all() (JOIN role).
     *
     * @var list<array<string, mixed>>
     */
    public array $usersRows = [];

    /**
     * Ligne renvoyee par UserRepository::find() (gestion des comptes) ; null = absent.
     *
     * @var array<string, mixed>|null
     */
    public ?array $userManageRow = null;

    /** Resultat de UserRepository::emailExists(). */
    public bool $userEmailTaken = false;

    /** Resultat de UserRepository::activeRoleExists() (role existe ET actif). */
    public bool $roleActiveExists = true;

    /** Id renvoye par SELECT LAST_INSERT_ID() (create user/menu). */
    public int $lastInsertId = 0;

    /** Compteur renvoye par UserRepository::activeAdminCount() (garde dernier admin). */
    public int $activeAdminCount = 0;

    /** Resultat de UserRepository::isAdmin(). */
    public bool $userIsAdmin = false;

    /**
     * Lignes {id,label} renvoyees par le select de roles (UserController::rolesForSelect).
     *
     * @var list<array<string, mixed>>
     */
    public array $rolesRows = [];

    /**
     * Lignes renvoyees par RoleRepository::allRoles().
     *
     * @var list<array<string, mixed>>
     */
    public array $rolesAllRows = [];

    /**
     * Ligne renvoyee par RoleRepository::findRole() ; null = absent.
     *
     * @var array<string, mixed>|null
     */
    public ?array $roleManageRow = null;

    /** Resultat de RoleRepository::codeExists(). */
    public bool $roleCodeTaken = false;

    /**
     * Catalogue renvoye par RoleRepository::allPermissions().
     *
     * @var list<array<string, mixed>>
     */
    public array $permissionsRows = [];

    /**
     * Lignes {permission_id} renvoyees par RoleRepository::permissionIdsFor().
     *
     * @var list<array<string, mixed>>
     */
    public array $rolePermIds = [];

    /**
     * Lignes {source} renvoyees par RoleRepository::visibleSources().
     *
     * @var list<array<string, mixed>>
     */
    public array $roleSources = [];

    /**
     * Recouvrement PAR ROLE des sources visibles (cle roleId, valeur list<string>),
     * prioritaire sur $roleSources (global) quand la cle existe. Meme raison que
     * $permissionCodesByRole : les tests D-4 (portee role_visible_source) doivent
     * distinguer, DANS LE MEME test, les sources de l'ACTEUR de celles du role VISE
     * -- deux roles differents que $roleSources (une seule reponse, insensible au
     * role demande) ne peut pas exprimer separement.
     *
     * @var array<int, list<string>>
     */
    public array $visibleSourcesByRole = [];

    /**
     * Recouvrement PAR ROLE de can() : cle "<roleId>:<code>" -> bool, prioritaire sur
     * $grantedCodes/$canResult quand elle existe pour le couple (role, code) demande.
     * Necessaire aux tests D-4 (elevation de privilege, UserController) qui doivent
     * distinguer, DANS LE MEME test, "le role de L'ACTEUR porte role.manage" de "le
     * role VISE (cible actuelle ou nouvellement affectee) porte role.manage" -- deux
     * roles differents que $grantedCodes/$canResult (une seule reponse, insensible
     * au role demande) ne peuvent pas exprimer separement.
     *
     * @var array<string, bool>
     */
    public array $canByRole = [];

    /**
     * Recouvrement PAR ROLE de la LISTE de codes de permission (cle roleId, valeur
     * list<string>), prioritaire sur $permissionCodes quand la cle existe. Cle
     * ENTIERE, pas chaine : un litteral PHP ['9' => [...]] normalise deja la cle en
     * int 9 (cles de tableau numeriques), ce que reflete le type ci-dessous.
     * Sert `Authorizer::permissionsFor()` ET `RoleRepository::permissionCodesFor()`
     * (meme forme de requete, distinguees seulement par le nom du parametre lie :
     * `:role` pour la premiere, `:id` pour la seconde -- $params['id'] ?? $params
     * ['role'] les couvre toutes les deux). Contrairement a $permissionCodes/
     * $roleActive (une seule reponse globale), ce recouvrement laisse passer sa
     * valeur MEME quand $roleActive vaut false : c'est exactement le comportement
     * reel de `RoleRepository::permissionCodesFor()`, qui lit `role_permission`
     * SANS jointure sur `role.is_active` (D-4, limite du role desactive relevee en
     * revue -- la garde de UserController doit lire les permissions d'un role cible
     * MEME s'il est desormais desactive, sans changer `Authorizer::can()` pour
     * l'autorisation normale des routes, qui doit continuer de filtrer les roles
     * desactives).
     *
     * @var array<int, list<string>>
     */
    public array $permissionCodesByRole = [];

    /**
     * Allowlist optionnelle de codes de permission accordes (RG-T03). Si non nul,
     * can() repond par appartenance du :code lie a cette liste (permet de tester la
     * differenciation par permission, ex. RG-4 : stock.read sans stock.manage) ;
     * sinon on retombe sur le bouton global $canResult.
     *
     * @var list<string>|null
     */
    public ?array $grantedCodes = null;

    /**
     * Ligne renvoyee pour PinVerifier::resolveActingUser (id, role_id, pin_hash) ;
     * null = email inconnu/inactif.
     *
     * @var array<string, mixed>|null
     */
    public ?array $actingUserRow = null;

    /**
     * lockout_until renvoye pour la porte du throttle PIN (RG-T22, PinThrottle::isLocked) ;
     * null = pas de verrou.
     */
    public ?string $pinThrottleLockoutUntil = null;

    /**
     * Ligne {id} renvoyee par la recherche du compte CIBLE d'un PIN echoue
     * (PinGate::auditFailedPin(), minimisation RGPD art. 5.1.c) ; null = aucun
     * compte pour cette adresse ("adresse inconnue"). Distinct de $emailLookupRow
     * (PasswordResetService, requete 'AND is_active = 1') : cette recherche-ci
     * porte sur N'IMPORTE QUEL compte, actif ou non, puisqu'elle sert a auditer
     * une tentative, pas a authentifier.
     *
     * @var array<string, mixed>|null
     */
    public ?array $pinFailedTargetUserRow = null;

    /** Compteur pin_throttle relu apres l'upsert (PinThrottle::recordFailure) ; 1 par defaut. */
    public int $pinThrottleAttempts = 1;

    /**
     * lockout_until renvoyes pour les deux dimensions de PasswordResetThrottle
     * (POST /forgot_password) ; null = pas de verrou pour cette dimension.
     */
    public ?string $passwordResetEmailLockoutUntil = null;
    public ?string $passwordResetIpLockoutUntil = null;

    /**
     * Compteurs password_reset_throttle relus apres l'upsert, un par dimension
     * (PasswordResetThrottle::increment()) ; 1 par defaut.
     */
    public int $passwordResetEmailAttempts = 1;
    public int $passwordResetIpAttempts = 1;

    /** Si non nul, execute() leve cette exception (simulation panne DB / violation de contrainte). */
    public ?Throwable $failOnExecute = null;

    /**
     * Restreint failOnExecute a la SEULE ecriture dont le SQL CONTIENT cette
     * sous-chaine ; vide (defaut) = TOUTE ecriture echoue, comportement historique
     * inchange. Sert a simuler une violation qui ne frappe qu'UNE ecriture precise
     * au milieu d'une transaction (ex. le DELETE d'un menu_slot en course avec une
     * commande concurrente, MenuRepository::reconcileSlots()) sans faire echouer les
     * ecritures qui la precedent dans la meme transaction.
     */
    public string $failOnExecuteMatching = '';

    /** Si non nul, fetch() leve cette exception (simulation panne DB en LECTURE, ex. base arretee). */
    public ?Throwable $failOnFetch = null;

    /** Nombre de lignes affectees renvoye par execute() (1 par defaut). */
    public int $executeRowCount = 1;

    /** @var list<array{sql: string, params: array<string|int, mixed>}> */
    public array $writes = [];

    /** @var list<string> */
    public array $transactionEvents = [];

    /**
     * Journal ordonne entrelacant ecritures et bornes de transaction, pour
     * verifier qu'une ecriture (ex. audit_log) tombe bien ENTRE begin et commit
     * (atomicite RG-T08), ce que deux listes disjointes ne prouvent pas.
     *
     * @var list<string>
     */
    public array $eventLog = [];

    public function fetch(string $sql, array $params = []): ?array
    {
        if ($this->failOnFetch !== null) {
            throw $this->failOnFetch;
        }

        $this->reads[] = ['sql' => $sql, 'params' => $params];

        // Doit passer AVANT le lookup auth : la requete displayInfo contient aussi
        // 'FROM user u JOIN role' mais selectionne 'AS role_label'.
        if (str_contains($sql, 'AS role_label')) {
            return $this->userDisplayRow;
        }

        // --- Gestion des comptes (UserController/UserRepository) ---
        // AVANT le lookup auth 'FROM user u JOIN role' : les agregats RBAC le
        // contiennent aussi (COUNT admins, isAdmin), il faut les router en premier.
        if (str_contains($sql, 'COUNT(*) AS n FROM user u JOIN role')) {
            return ['n' => $this->activeAdminCount];
        }

        if (str_contains($sql, "WHERE u.id = :id AND r.code = 'admin'")) {
            return $this->userIsAdmin ? ['id' => 1] : null;
        }

        // AVANT 'SELECT id FROM user WHERE email' (emailLookupRow) : unicite (exclut une id).
        if (str_contains($sql, 'FROM user WHERE email = :email AND id <> :id')) {
            return $this->userEmailTaken ? ['id' => 1] : null;
        }

        if (str_contains($sql, 'anonymized_at FROM user WHERE id')) {
            return $this->userManageRow;
        }

        if (str_contains($sql, 'FROM role WHERE id = :id AND is_active = 1')) {
            return $this->roleActiveExists ? ['id' => 1] : null;
        }

        // RBAC (RoleRepository) : findRole (7 colonnes) + codeExists (unicite).
        if (str_contains($sql, 'order_source, is_active FROM role WHERE id = :id')) {
            return $this->roleManageRow;
        }

        if (str_contains($sql, 'FROM role WHERE code = :code AND id <> :id')) {
            return $this->roleCodeTaken ? ['id' => 1] : null;
        }

        if (str_contains($sql, 'LAST_INSERT_ID')) {
            return ['id' => $this->lastInsertId];
        }

        if (str_contains($sql, 'FROM user u JOIN role')) {
            return $this->userRow;
        }

        if (str_contains($sql, 'password_reset_token_hash')) {
            return $this->resetUserRow;
        }

        // Recherche du compte CIBLE pour l'audit pin.failed minimise
        // (PinGate::auditFailedPin()) : distinguee de la route generique
        // ci-dessous par sa projection 'id AS target_user_id' (aucun chevauchement
        // de substring avec 'SELECT id FROM user WHERE email').
        if (str_contains($sql, 'id AS target_user_id FROM user WHERE email')) {
            return $this->pinFailedTargetUserRow;
        }

        if (str_contains($sql, 'SELECT id FROM user WHERE email')) {
            return $this->emailLookupRow;
        }

        if (str_contains($sql, 'SELECT is_active, role_id, session_epoch FROM user WHERE id')) {
            return $this->guardUserRow;
        }

        if (str_contains($sql, 'SELECT 1 AS granted FROM role_permission')) {
            $code = $params['code'] ?? null;
            $role = $params['role'] ?? null;
            if (is_string($code) && $role !== null) {
                $key = $role . ':' . $code;
                if (array_key_exists($key, $this->canByRole)) {
                    // Recouvrement EXPLICITE, par role : une affirmation precise du test
                    // l'emporte sur le repli global $roleActive (memes semantiques que
                    // $permissionCodesByRole, necessaire pour simuler un role DESACTIVE
                    // pour la cible D-4 tout en gardant l'acteur autorise sur ses propres
                    // permissions dans le meme test).
                    return $this->canByRole[$key] ? ['granted' => 1] : null;
                }
            }

            if ($this->grantedCodes !== null) {
                return (is_string($code) && in_array($code, $this->grantedCodes, true) && $this->roleActive) ? ['granted' => 1] : null;
            }

            return ($this->canResult && $this->roleActive) ? ['granted' => 1] : null;
        }

        if (str_contains($sql, 'FROM role r WHERE r.id')) {
            return $this->roleRow;
        }

        // Exige le predicat is_active = 1 : si la production le retirait, le double
        // renverrait null et le test verify-true virerait au rouge (garde RG-T13).
        if (str_contains($sql, 'SELECT pin_hash FROM user WHERE id') && str_contains($sql, 'is_active = 1')) {
            return $this->pinUserRow;
        }

        if (str_contains($sql, 'FROM user WHERE id = :id AND pin_hash IS NOT NULL')) {
            return $this->userPinSet ? ['id' => 1] : null;
        }

        // D-3 (contre-audit 30/09) : relecture du compteur DU COMPTE apres son
        // increment atomique (AuthService::recordFailure()), meme motif que
        // throttleRow pour la dimension IP -- sert au calcul du backoff compte
        // en PHP, sans jamais dependre d'une valeur lue AVANT la transaction.
        if (str_contains($sql, 'SELECT failed_login_attempts FROM user WHERE id')) {
            return $this->accountThrottleRow;
        }

        // D-1.a (contre-audit 30/09) : verrou DU COMPTE (AccountLockout::
        // isLocked(), reverification du mot de passe sur /admin/profile/pin) --
        // AVANT ce predicat pour ne pas etre masque par une route plus large
        // matchant 'FROM user WHERE id'.
        if (str_contains($sql, 'SELECT lockout_until FROM user WHERE id')) {
            return ['lockout_until' => $this->userAccountLockoutUntil];
        }

        // Re-verification d'identite au set de PIN (ProfileController) : lecture du
        // password_hash du compte actif de session. is_active = 1 dans le predicat :
        // retirer ce filtre en production ferait virer au rouge le test du compte inactif.
        if (str_contains($sql, 'SELECT password_hash FROM user WHERE id') && str_contains($sql, 'is_active = 1')) {
            return $this->currentPasswordRow;
        }

        // AuthService::referenceHashForDecoy() : un hash STOCKE quelconque
        // (distinct des routes ci-dessus qui filtrent toutes par id/email) pour
        // calibrer le leurre sur email inconnu. Le predicat `password_hash <> ''`
        // est exige ICI aussi : il exclut les tombstones RGPD (dont le hash est
        // vide), et le retirer en production rouvrirait l'ecart de temps --
        // AuthServiceTest::testReferenceHashQueryExcludesAnonymisedTombstones
        // vire au rouge si la requete perd ce predicat ou son tri.
        if (str_contains($sql, 'SELECT password_hash FROM user') && str_contains($sql, "password_hash <> ''")) {
            return $this->referenceUserPasswordHash !== null ? ['password_hash' => $this->referenceUserPasswordHash] : null;
        }

        // Exige is_active = 1 (garde RG-T13) : retirer le predicat en production
        // ferait virer au rouge les tests de resolveActingUser.
        if (str_contains($sql, 'pin_hash FROM user WHERE email') && str_contains($sql, 'is_active = 1')) {
            return $this->actingUserRow;
        }

        // F12 : slug de categorie d'un produit (productCategorySlug), garde de categorie
        // d'option de slot. Distinguee des routes 'FROM product WHERE id' par le JOIN
        // category + la projection 'category_slug' ; null => option rejetee (hors
        // categorie / id inconnu). Doit passer AVANT les routes produit generiques.
        if (str_contains($sql, 'c.slug AS category_slug FROM product p JOIN category c')) {
            return $this->productCategorySlug !== null ? ['category_slug' => $this->productCategorySlug] : null;
        }

        // ProductRepository::reorderWithinCategory() : lookup initial (categorie du
        // produit a deplacer). Doit passer AVANT la route productIsBase juste en
        // dessous : les deux requetes partagent le meme predicat de fin
        // ('WHERE id = :id AND base_product_id IS NULL'), seule la colonne SELECT
        // differe (category_id ici, id la-bas).
        if (str_contains($sql, 'SELECT category_id FROM product WHERE id')) {
            return $this->reorderProductRow;
        }

        // R4/F9-2 : predicat base-only (productIsBase). Doit passer AVANT la route
        // generique 'FROM product WHERE id = :id' (productRow) qu'elle matche aussi.
        if (str_contains($sql, 'FROM product WHERE id = :id') && str_contains($sql, 'base_product_id IS NULL')) {
            return $this->productIsBase ? ['id' => 1] : null;
        }

        if (str_contains($sql, 'FROM product WHERE id = :id')) {
            $id = (int) ($params['id'] ?? 0);

            return $this->productByIdRows[$id] ?? $this->productRow;
        }

        if (str_contains($sql, 'FROM category WHERE id = :id')) {
            return $this->categoryRow;
        }

        if (str_contains($sql, 'FROM menu WHERE id = :id')) {
            return $this->menuRow;
        }

        // Garde de visibilite PRE-3 (6.1) : lecture ciblee de la seule colonne source
        // par OrderAdminController::orderSource. Doit passer AVANT la route generique
        // 'FROM customer_order WHERE order_number' (orderByNumberRow) qu'elle matche
        // aussi. null = numero inconnu (l'appelant le traite comme non visible).
        if (str_contains($sql, 'SELECT source FROM customer_order WHERE order_number')) {
            return $this->orderSourceRow;
        }

        if (str_contains($sql, 'FROM customer_order WHERE order_number')) {
            return $this->orderByNumberRow;
        }

        if (str_contains($sql, 'FROM order_item WHERE menu_id')) {
            return $this->menuReferenced ? ['menu_id' => 1] : null;
        }

        // Garde FK-safe PAR SLOT de reconcileSlots() (MenuRepository::update()) :
        // distincte de la garde par MENU juste au-dessus ('FROM order_item WHERE
        // menu_id'), une table differente (order_item_selection, pas order_item).
        if (str_contains($sql, 'FROM order_item_selection WHERE menu_slot_id')) {
            $id = (int) ($params['id'] ?? 0);

            return in_array($id, $this->referencedSlotIds, true) ? ['id' => $id] : null;
        }

        // Ingredient : nameExists (avant la route par id, qui ne matche pas
        // 'WHERE name'), puis find() + lectures ciblees pack_size/stock_quantity.
        if (str_contains($sql, 'FROM ingredient WHERE name = :name')) {
            return $this->ingredientNameTaken ? ['id' => 1] : null;
        }

        if (str_contains($sql, 'FROM ingredient WHERE id = :id')) {
            // Le vrai SQL filtre sur l'id : une lecture d'un AUTRE id que celui
            // pose par le test doit rendre "introuvable", sinon un double trop
            // complaisant fait croire que n'importe quel ingredient existe
            // (ProductRepository::ingredientExists() repose entierement sur ce
            // point). Ne s'applique que si le test a pose un id ET que la
            // requete en demande un : les autres montages gardent le
            // comportement historique.
            $wanted = $params['id'] ?? null;
            if ($wanted === null) {
                return $this->ingredientRow;
            }
            if ($this->existingIngredientIds !== []) {
                return in_array((int) $wanted, $this->existingIngredientIds, true)
                    ? ($this->ingredientRow ?? ['id' => (int) $wanted])
                    : null;
            }
            $posed = $this->ingredientRow['id'] ?? null;
            if ($posed !== null && (int) $wanted !== (int) $posed) {
                return null;
            }

            return $this->ingredientRow;
        }

        if (str_contains($sql, 'COUNT(*) AS n FROM product_ingredient')) {
            return ['n' => $this->productCompositionCount];
        }

        if (str_contains($sql, 'FROM category WHERE name = :name')) {
            return $this->categoryNameTaken ? ['id' => 1] : null;
        }

        if (str_contains($sql, 'FROM category WHERE slug = :slug')) {
            return $this->categorySlugTaken ? ['id' => 1] : null;
        }

        if (str_contains($sql, 'lockout_until FROM pin_throttle')) {
            return ['lockout_until' => $this->pinThrottleLockoutUntil];
        }

        if (str_contains($sql, 'failed_attempts FROM pin_throttle')) {
            return ['failed_attempts' => $this->pinThrottleAttempts];
        }

        if (str_contains($sql, 'lockout_until FROM password_reset_throttle')) {
            $kind = $params['kind'] ?? null;

            if ($kind === 'email') {
                return ['lockout_until' => $this->passwordResetEmailLockoutUntil];
            }
            if ($kind === 'ip') {
                return ['lockout_until' => $this->passwordResetIpLockoutUntil];
            }

            return null;
        }

        if (str_contains($sql, 'failed_attempts FROM password_reset_throttle')) {
            $kind = $params['kind'] ?? null;

            if ($kind === 'email') {
                return ['failed_attempts' => $this->passwordResetEmailAttempts];
            }
            if ($kind === 'ip') {
                return ['failed_attempts' => $this->passwordResetIpAttempts];
            }

            return null;
        }

        if (str_contains($sql, 'SELECT lockout_until FROM login_throttle')) {
            return ['lockout_until' => $this->ipLockoutUntil];
        }

        if (str_contains($sql, 'SELECT failed_attempts FROM login_throttle')) {
            return $this->throttleRow;
        }

        return null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->reads[] = ['sql' => $sql, 'params' => $params];

        if (str_contains($sql, 'FROM category ORDER BY')) {
            return $this->categoriesRows;
        }

        // CategoryIngredientFamilyRepository::mapByCategory() (migration 0017) : distinct
        // de 'FROM category ORDER BY' juste au-dessus (table differente, meme si le nom
        // commence pareil -- verifie AVANT toute autre branche 'category').
        if (str_contains($sql, 'FROM category_ingredient_family')) {
            return $this->categoryIngredientFamilyRows;
        }

        // F20 : bases groupees par categorie (basesByCategory). Desambigue par
        // 'AS variant_count', alias propre a cette requete : elle alias la table
        // (FROM product p) et ne joint pas category, donc ni la branche basesOnly
        // ci-dessous ni la branche all() plus bas ne l'attrapent.
        if (str_contains($sql, 'AS variant_count')) {
            return $this->basesByCategoryRows;
        }

        // ProductRepository::reorderWithinCategory() : liste ORDONNEE des ids de la
        // categorie deplacee (deuxieme lecture). Doit passer AVANT basesOnly()
        // juste en dessous : cette derniere ne filtre pas par category_id, donc ne
        // matcherait pas de toute facon, mais l'ordre explicite evite toute
        // ambiguite si son SQL evolue un jour.
        if (str_contains($sql, 'WHERE category_id = :cat AND base_product_id IS NULL')) {
            return $this->reorderCategoryIdsRows;
        }

        // R4/F9-1 : liste base-only (basesOnly) pour les selects. Distincte de la
        // liste admin enrichie (all(), 'FROM product p JOIN category').
        if (str_contains($sql, 'FROM product WHERE base_product_id IS NULL')) {
            return $this->baseProductsRows;
        }

        if (str_contains($sql, 'FROM product p JOIN category')) {
            return $this->productsRows;
        }

        if (str_contains($sql, 'FROM menu m JOIN category')) {
            return $this->menusRows;
        }

        if (str_contains($sql, 'FROM menu_slot s')) {
            return $this->menuSlotRows;
        }

        // reconcileSlots() (MenuRepository::update()) : identite {id, slot_type, name}
        // des menu_slot EXISTANTS du menu (verrouilles, FOR UPDATE), pour
        // l'appariement par NOM puis par position au sein du meme slot_type.
        // Distincte de la route juste au-dessus ('FROM menu_slot s', LEFT JOIN
        // options pour slotsWithOptions()) par son texte SQL propre ; derivee du
        // MEME $menuSlotRows (dedoublonne par id), pour que les deux lectures
        // restent coherentes sans deux jeux de donnees a tenir a jour.
        if (str_contains($sql, 'SELECT id, slot_type, name FROM menu_slot WHERE menu_id')) {
            $seen = [];
            $rows = [];
            foreach ($this->menuSlotRows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $rows[] = ['id' => $id, 'slot_type' => (string) ($row['slot_type'] ?? ''), 'name' => (string) ($row['name'] ?? '')];
            }

            return $rows;
        }

        if (str_contains($sql, 'FROM ingredient ORDER BY name')) {
            return $this->ingredientsRows;
        }

        // Composition d'un produit (recette) vs ensemble des produits en rupture
        // auto : meme table jointe, distingues par la clause WHERE.
        if (str_contains($sql, 'FROM product_ingredient pi') && str_contains($sql, 'is_removable = 0')) {
            return $this->autoUnavailableRows;
        }

        if (str_contains($sql, 'FROM product_ingredient pi') && str_contains($sql, 'WHERE pi.product_id')) {
            return $this->compositionRows;
        }

        if (str_contains($sql, 'FROM user u JOIN role r ON r.id = u.role_id')) {
            return $this->usersRows;
        }

        if (str_contains($sql, 'FROM role WHERE is_active = 1 ORDER BY label')) {
            return $this->rolesRows;
        }

        // F11b : catalogue des 14 et cases deja cochees pour un ingredient.
        if (str_contains($sql, 'FROM ingredient_allergen WHERE ingredient_id = :id')) {
            return $this->ingredientAllergenRows;
        }
        if (str_contains($sql, 'FROM allergen ORDER BY id')) {
            return $this->allergensRows;
        }

        if (str_contains($sql, 'FROM stock_movement WHERE ingredient_id')) {
            return $this->movementsRows;
        }

        // --- RBAC (RoleRepository) ---
        if (str_contains($sql, 'FROM role ORDER BY id')) {
            return $this->rolesAllRows;
        }

        if (str_contains($sql, 'FROM permission ORDER BY id')) {
            return $this->permissionsRows;
        }

        if (str_contains($sql, 'permission_id FROM role_permission WHERE role_id')) {
            return $this->rolePermIds;
        }

        if (str_contains($sql, 'FROM role_visible_source WHERE role_id')) {
            // Sert RoleRepository::visibleSources() (param 'id') ET
            // OrderQueryRepository::visibleSources() (param 'r').
            $roleParam = $params['id'] ?? ($params['r'] ?? null);
            if (is_int($roleParam) && array_key_exists($roleParam, $this->visibleSourcesByRole)) {
                return array_map(static fn (string $s): array => ['source' => $s], $this->visibleSourcesByRole[$roleParam]);
            }

            return $this->roleSources;
        }

        // Sert Authorizer::permissionsFor ET RoleRepository::permissionCodesFor
        // (meme forme de requete 'SELECT p.code FROM role_permission rp JOIN
        // permission p') : $permissionCodesByRole (par role) est prioritaire sur
        // $permissionCodes (global) quand la cle existe, ET ignore $roleActive --
        // voir le docblock de $permissionCodesByRole pour le POURQUOI (D-4, role
        // desactive).
        if (str_contains($sql, 'SELECT p.code FROM role_permission')) {
            $roleParam = $params['id'] ?? ($params['role'] ?? null);
            if (is_int($roleParam) && array_key_exists($roleParam, $this->permissionCodesByRole)) {
                return array_map(static fn (string $code): array => ['code' => $code], $this->permissionCodesByRole[$roleParam]);
            }

            if (!$this->roleActive) {
                return [];
            }

            return array_map(static fn (string $code): array => ['code' => $code], $this->permissionCodes);
        }

        return [];
    }

    public function execute(string $sql, array $params = []): int
    {
        if ($this->failOnExecute !== null && ($this->failOnExecuteMatching === '' || str_contains($sql, $this->failOnExecuteMatching))) {
            throw $this->failOnExecute;
        }

        $this->writes[] = ['sql' => $sql, 'params' => $params];
        $this->eventLog[] = 'write:' . substr($sql, 0, 24);

        return $this->executeRowCount;
    }

    public function transaction(callable $fn): void
    {
        $this->transactionEvents[] = 'begin';
        $this->eventLog[] = 'begin';

        try {
            $fn($this);
            $this->transactionEvents[] = 'commit';
            $this->eventLog[] = 'commit';
        } catch (\Throwable $exception) {
            $this->transactionEvents[] = 'rollback';
            $this->eventLog[] = 'rollback';

            throw $exception;
        }
    }

    public function wrote(string $needle): bool
    {
        foreach ($this->writes as $write) {
            if (str_contains($write['sql'], $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Codes d'action audit_log inseres (dans l'ordre).
     *
     * @return list<string>
     */
    public function auditActions(): array
    {
        $codes = [];

        foreach ($this->writes as $write) {
            if (str_contains($write['sql'], 'INSERT INTO audit_log')) {
                $code = $write['params']['code'] ?? null;
                $codes[] = is_string($code) ? $code : '';
            }
        }

        return $codes;
    }
}
