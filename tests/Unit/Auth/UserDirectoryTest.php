<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use App\Auth\UserDirectory;
use App\Tests\Support\FakeDatabase;

/**
 * Lecture des infos d'affichage (nom + libelle de role) pour l'entete admin.
 */
final class UserDirectoryTest extends TestCase
{
    private FakeDatabase $db;

    protected function setUp(): void
    {
        $this->db = new FakeDatabase();
    }

    public function testDisplayInfoReturnsNameAndRoleLabel(): void
    {
        $this->db->userDisplayRow = [
            'first_name' => 'Corentin',
            'last_name'  => 'J',
            'role_label' => 'Administrateur',
        ];

        self::assertSame(
            ['name' => 'Corentin J', 'role_label' => 'Administrateur'],
            (new UserDirectory($this->db))->displayInfo(7),
        );
    }

    public function testDisplayInfoDefaultsWhenAbsent(): void
    {
        $this->db->userDisplayRow = null;

        self::assertSame(
            ['name' => 'Utilisateur', 'role_label' => ''],
            (new UserDirectory($this->db))->displayInfo(999),
        );
    }
}
