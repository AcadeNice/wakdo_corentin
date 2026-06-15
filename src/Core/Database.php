<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Enveloppe PDO MariaDB, requetes preparees exclusivement (anti-SQLi, Cr 4.e.1).
 *
 * Connexion paresseuse : le PDO n'est ouvert qu'au premier acces afin que les
 * routes sans BDD (ex : la home back-office) fonctionnent meme si la base est
 * indisponible.
 */
final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $this->config->required('DB_HOST'),
                $this->config->int('DB_PORT', 3306),
                $this->config->required('DB_NAME'),
            );

            $this->pdo = new PDO(
                $dsn,
                $this->config->required('DB_USER'),
                $this->config->required('DB_PASSWORD'),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // Vraies requetes preparees cote serveur (pas d'emulation) :
                    // le SQL et les valeurs voyagent separement, fermant l'injection.
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ],
            );
        }

        return $this->pdo;
    }

    /**
     * Prepare puis execute une requete avec ses parametres lies.
     *
     * @param array<string|int, mixed> $params
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Execute une ecriture et renvoie le nombre de lignes affectees.
     *
     * @param array<string|int, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }
}
