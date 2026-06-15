<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\DatabaseInterface;

/**
 * Ecritures sur l'entite user necessaires hors du flux d'authentification
 * (definition du PIN en self-service ici ; la gestion complete des comptes
 * arrive avec le CRUD Users). Lecture seule d'affichage = UserDirectory.
 */
final class UserRepository
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Retourne le nombre de lignes affectees (1 attendu). Le hash argon2id
     * change a chaque appel (sel aleatoire), donc une cible existante donne
     * toujours 1 ; 0 revele une cible inexistante (defense en profondeur).
     */
    public function setPinHash(int $userId, string $hash): int
    {
        return $this->db->execute('UPDATE user SET pin_hash = :hash WHERE id = :id', ['hash' => $hash, 'id' => $userId]);
    }

    public function pinIsSet(int $userId): bool
    {
        return $this->db->fetch(
            'SELECT id FROM user WHERE id = :id AND pin_hash IS NOT NULL',
            ['id' => $userId],
        ) !== null;
    }
}
