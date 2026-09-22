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
            'first_name'   => 'Corentin',
            'last_name'    => 'J',
            'email'        => 'corentin@wakdo.local',
            'role_label'   => 'Administrateur',
            'order_source' => null,
        ];

        self::assertSame(
            ['name' => 'Corentin J', 'role_label' => 'Administrateur', 'email' => 'corentin@wakdo.local', 'order_source' => ''],
            (new UserDirectory($this->db))->displayInfo(7),
        );
    }

    public function testDisplayInfoExposesOrderSourceForChannelRoles(): void
    {
        // order_source remonte du role : sert au layout a router "Saisie commande".
        $this->db->userDisplayRow = [
            'first_name'   => 'Dana',
            'last_name'    => 'D',
            'email'        => 'dana@wakdo.local',
            'role_label'   => 'Drive Staff',
            'order_source' => 'drive',
        ];

        self::assertSame(
            ['name' => 'Dana D', 'role_label' => 'Drive Staff', 'email' => 'dana@wakdo.local', 'order_source' => 'drive'],
            (new UserDirectory($this->db))->displayInfo(8),
        );
    }

    public function testDisplayInfoDefaultsWhenAbsent(): void
    {
        $this->db->userDisplayRow = null;

        self::assertSame(
            ['name' => 'Utilisateur', 'role_label' => '', 'email' => '', 'order_source' => ''],
            (new UserDirectory($this->db))->displayInfo(999),
        );
    }
}
