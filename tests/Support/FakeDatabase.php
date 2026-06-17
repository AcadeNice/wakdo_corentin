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
     * Reponse de la verification is_active du SessionGuard (RG-T02) ; null = absent.
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
     * Ligne role renvoyee pour la lecture du code de role (/api/me) ; null = absent.
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

    /** Resultat de UserRepository::pinIsSet() (true = un PIN est defini). */
    public bool $userPinSet = false;

    /**
     * Lignes renvoyees par ProductRepository::all().
     *
     * @var list<array<string, mixed>>
     */
    public array $productsRows = [];

    /**
     * Ligne renvoyee par ProductRepository::find() ; null = introuvable.
     *
     * @var array<string, mixed>|null
     */
    public ?array $productRow = null;

    /**
     * Ligne renvoyee par MenuRepository::find() ; null = introuvable.
     *
     * @var array<string, mixed>|null
     */
    public ?array $menuRow = null;

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
     * Ligne renvoyee pour IngredientRepository::find() et les lectures ciblees de
     * restock/inventory (pack_size, stock_quantity) ; null = introuvable.
     *
     * @var array<string, mixed>|null
     */
    public ?array $ingredientRow = null;

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

    /** Compteur pin_throttle relu apres l'upsert (PinThrottle::recordFailure) ; 1 par defaut. */
    public int $pinThrottleAttempts = 1;

    /** Si non nul, execute() leve cette exception (simulation panne DB / violation de contrainte). */
    public ?Throwable $failOnExecute = null;

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

        if (str_contains($sql, 'SELECT id FROM user WHERE email')) {
            return $this->emailLookupRow;
        }

        if (str_contains($sql, 'SELECT is_active FROM user WHERE id')) {
            return $this->guardUserRow;
        }

        if (str_contains($sql, 'SELECT 1 AS granted FROM role_permission')) {
            if ($this->grantedCodes !== null) {
                $code = $params['code'] ?? null;

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

        // Exige is_active = 1 (garde RG-T13) : retirer le predicat en production
        // ferait virer au rouge les tests de resolveActingUser.
        if (str_contains($sql, 'pin_hash FROM user WHERE email') && str_contains($sql, 'is_active = 1')) {
            return $this->actingUserRow;
        }

        if (str_contains($sql, 'FROM product WHERE id = :id')) {
            return $this->productRow;
        }

        if (str_contains($sql, 'FROM category WHERE id = :id')) {
            return $this->categoryRow;
        }

        if (str_contains($sql, 'FROM menu WHERE id = :id')) {
            return $this->menuRow;
        }

        if (str_contains($sql, 'FROM order_item WHERE menu_id')) {
            return $this->menuReferenced ? ['menu_id' => 1] : null;
        }

        // Ingredient : nameExists (avant la route par id, qui ne matche pas
        // 'WHERE name'), puis find() + lectures ciblees pack_size/stock_quantity.
        if (str_contains($sql, 'FROM ingredient WHERE name = :name')) {
            return $this->ingredientNameTaken ? ['id' => 1] : null;
        }

        if (str_contains($sql, 'FROM ingredient WHERE id = :id')) {
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

        if (str_contains($sql, 'FROM product p JOIN category')) {
            return $this->productsRows;
        }

        if (str_contains($sql, 'FROM menu m JOIN category')) {
            return $this->menusRows;
        }

        if (str_contains($sql, 'FROM menu_slot s')) {
            return $this->menuSlotRows;
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
            return $this->roleSources;
        }

        // Sert Authorizer::permissionsFor ET RoleRepository::permissionCodesFor
        // (meme requete 'SELECT p.code FROM role_permission rp JOIN permission p') :
        // les deux renvoient $permissionCodes (le diff RBAC reutilise ce bouton).
        if (str_contains($sql, 'SELECT p.code FROM role_permission')) {
            if (!$this->roleActive) {
                return [];
            }

            return array_map(static fn (string $code): array => ['code' => $code], $this->permissionCodes);
        }

        return [];
    }

    public function execute(string $sql, array $params = []): int
    {
        if ($this->failOnExecute !== null) {
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
