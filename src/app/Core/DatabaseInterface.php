<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Contrat d'acces aux donnees consomme par les services applicatifs (auth...).
 * Il expose uniquement les operations dont ils ont besoin (lecture, ecriture,
 * transaction atomique) sans la primitive bas niveau query()/PDOStatement.
 *
 * Raison d'etre : permettre aux services securite-critiques (AuthService,
 * PasswordResetService, SessionGuard) d'etre testes unitairement avec un double
 * en memoire, tout en gardant la classe Database concrete `final`. Le seul autre
 * implementeur est ce double de test : interface justifiee, pas speculative.
 */
interface DatabaseInterface
{
    /**
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetch(string $sql, array $params = []): ?array;

    /**
     * @param array<string|int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array;

    /**
     * @param array<string|int, mixed> $params
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * Execute $fn dans une transaction atomique : commit si succes, rollback
     * complet sur tout Throwable (puis repropagation).
     *
     * @param callable(DatabaseInterface): void $fn
     */
    public function transaction(callable $fn): void;
}
