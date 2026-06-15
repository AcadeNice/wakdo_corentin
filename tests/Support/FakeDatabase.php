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

    /** Si non nul, execute() leve cette exception (simulation panne DB / violation de contrainte). */
    public ?Throwable $failOnExecute = null;

    /** @var list<array{sql: string, params: array<string|int, mixed>}> */
    public array $writes = [];

    /** @var list<string> */
    public array $transactionEvents = [];

    public function fetch(string $sql, array $params = []): ?array
    {
        $this->reads[] = ['sql' => $sql, 'params' => $params];

        // Doit passer AVANT le lookup auth : la requete displayInfo contient aussi
        // 'FROM user u JOIN role' mais selectionne 'AS role_label'.
        if (str_contains($sql, 'AS role_label')) {
            return $this->userDisplayRow;
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

        if (str_contains($sql, 'FROM category WHERE id = :id')) {
            return $this->categoryRow;
        }

        if (str_contains($sql, 'FROM category WHERE name = :name')) {
            return $this->categoryNameTaken ? ['id' => 1] : null;
        }

        if (str_contains($sql, 'FROM category WHERE slug = :slug')) {
            return $this->categorySlugTaken ? ['id' => 1] : null;
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

        return 1;
    }

    public function transaction(callable $fn): void
    {
        $this->transactionEvents[] = 'begin';

        try {
            $fn($this);
            $this->transactionEvents[] = 'commit';
        } catch (\Throwable $exception) {
            $this->transactionEvents[] = 'rollback';

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
