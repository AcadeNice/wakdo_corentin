<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Catalogue\AllergenRepository;
use App\Core\Config;
use App\Core\Database;

/**
 * AllergenRepository contre une vraie MariaDB (schema migre + seed reference).
 * Auto-skip si WAKDO_DB_TESTS != 1. Lecture seule (donnees de reference) : aucun
 * fixture/teardown. Verifie que les 14 allergenes INCO sont references avec
 * code + name + description.
 */
final class AllergenReadDbTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        if (getenv('WAKDO_DB_TESTS') !== '1') {
            self::markTestSkipped('Tests DB desactives (definir WAKDO_DB_TESTS=1 + DB_*).');
        }

        $this->db = new Database(new Config());

        try {
            $this->db->fetch('SELECT 1');
        } catch (Throwable $exception) {
            self::markTestSkipped('Base injoignable: ' . $exception->getMessage());
        }
    }

    public function testListsIncoReferenceWithCodeNameAndDescription(): void
    {
        $rows = (new AllergenRepository($this->db))->all();

        self::assertGreaterThanOrEqual(14, count($rows), 'les 14 allergenes INCO doivent etre references');
        foreach ($rows as $a) {
            self::assertArrayHasKey('code', $a);
            self::assertArrayHasKey('name', $a);
            self::assertArrayHasKey('description', $a);
            self::assertNotSame('', (string) ($a['name'] ?? ''));
        }
        // La description INCO est seede (migration 0001 + seed 0001) : au moins une non vide.
        $descriptions = array_filter($rows, static fn (array $a): bool => (string) ($a['description'] ?? '') !== '');
        self::assertNotEmpty($descriptions, 'la description INCO doit etre exposee');
    }
}
