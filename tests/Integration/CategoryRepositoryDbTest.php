<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Catalogue\CategoryRepository;
use App\Core\Config;
use App\Core\Database;

/**
 * CRUD reel de CategoryRepository contre une vraie MariaDB (schema migre).
 * Auto-skip si WAKDO_DB_TESTS != 1. Utilise un slug/libelle uniques (it-cat-*)
 * pour ne pas heurter les 9 categories seedees ; nettoyage en tearDown.
 */
final class CategoryRepositoryDbTest extends TestCase
{
    private Database $db;
    private string $slug = '';
    private string $name = '';

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

        $suffix = bin2hex(random_bytes(4));
        $this->slug = 'it-cat-' . $suffix;
        $this->name = 'IT Cat ' . $suffix;
    }

    protected function tearDown(): void
    {
        if ($this->slug !== '') {
            $this->db->execute('DELETE FROM category WHERE slug = :slug', ['slug' => $this->slug]);
        }
    }

    public function testCreateFindUpdateAndToggle(): void
    {
        $repo = new CategoryRepository($this->db);

        $repo->create([
            'name' => $this->name,
            'slug' => $this->slug,
            'image_path' => null,
            'display_order' => 99,
            'is_active' => 1,
        ]);

        $idRow = $this->db->fetch('SELECT id FROM category WHERE slug = :slug', ['slug' => $this->slug]);
        $id = (int) ($idRow['id'] ?? 0);
        self::assertGreaterThan(0, $id);

        $found = $repo->find($id);
        self::assertNotNull($found);
        self::assertSame($this->name, $found['name']);
        self::assertSame(1, (int) ($found['is_active'] ?? 0));

        // Unicite : present sauf si on s'exclut soi-meme.
        self::assertTrue($repo->nameExists($this->name));
        self::assertFalse($repo->nameExists($this->name, $id));
        self::assertTrue($repo->slugExists($this->slug));
        self::assertFalse($repo->slugExists($this->slug, $id));

        $repo->update($id, [
            'name' => $this->name . ' (maj)',
            'slug' => $this->slug,
            'image_path' => 'x.png',
            'display_order' => 100,
        ]);
        $updated = $repo->find($id);
        self::assertNotNull($updated);
        self::assertSame($this->name . ' (maj)', $updated['name']);
        self::assertSame('x.png', $updated['image_path']);

        $repo->setActive($id, false);
        $toggled = $repo->find($id);
        self::assertNotNull($toggled);
        self::assertSame(0, (int) ($toggled['is_active'] ?? 1));

        // all() renvoie la categorie creee.
        $slugs = array_map(static fn (array $r): string => (string) ($r['slug'] ?? ''), $repo->all());
        self::assertContains($this->slug, $slugs);
    }
}
