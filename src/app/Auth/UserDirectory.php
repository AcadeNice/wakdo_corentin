<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\DatabaseInterface;

/**
 * Lecture des informations d'affichage d'un utilisateur (nom + libelle de role)
 * pour l'entete du back-office. Separe d'Authorizer (qui ne traite que les
 * permissions) ; depend de DatabaseInterface pour rester testable avec un double.
 */
final class UserDirectory
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * @return array{name: string, role_label: string, email: string}
     */
    public function displayInfo(int $userId): array
    {
        $row = $this->db->fetch(
            'SELECT u.first_name, u.last_name, u.email, r.label AS role_label '
            . 'FROM user u JOIN role r ON r.id = u.role_id WHERE u.id = :id',
            ['id' => $userId],
        );

        $first = is_string($row['first_name'] ?? null) ? $row['first_name'] : '';
        $last = is_string($row['last_name'] ?? null) ? $row['last_name'] : '';
        $name = trim($first . ' ' . $last);

        return [
            'name'       => $name !== '' ? $name : 'Utilisateur',
            'role_label' => is_string($row['role_label'] ?? null) ? $row['role_label'] : '',
            'email'      => is_string($row['email'] ?? null) ? $row['email'] : '',
        ];
    }
}
