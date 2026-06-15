<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Core\DatabaseInterface;

/**
 * Acces aux donnees de la table category (sous-domaine Catalogue). Premier
 * repository du CRUD admin (P3) : centralise les requetes preparees d'une entite,
 * reutilisees par les 6 actions du controleur et la validation d'unicite. Depend
 * de DatabaseInterface pour rester testable avec un double.
 *
 * Pas de suppression dure : une categorie reference par des produits/menus
 * (FK ON DELETE RESTRICT) ne se supprime pas ; la permission category.manage
 * couvre create/update/deactivate (cf. seed). On bascule is_active a la place.
 */
final class CategoryRepository
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->fetchAll(
            'SELECT id, name, slug, image_path, display_order, is_active '
            . 'FROM category ORDER BY display_order, name',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT id, name, slug, image_path, display_order, is_active FROM category WHERE id = :id',
            ['id' => $id],
        );
    }

    public function nameExists(string $name, int $exceptId = 0): bool
    {
        return $this->db->fetch(
            'SELECT id FROM category WHERE name = :name AND id <> :id LIMIT 1',
            ['name' => $name, 'id' => $exceptId],
        ) !== null;
    }

    public function slugExists(string $slug, int $exceptId = 0): bool
    {
        return $this->db->fetch(
            'SELECT id FROM category WHERE slug = :slug AND id <> :id LIMIT 1',
            ['slug' => $slug, 'id' => $exceptId],
        ) !== null;
    }

    /**
     * @param array{name: string, slug: string, image_path: ?string, display_order: int, is_active: int} $data
     */
    public function create(array $data): void
    {
        $this->db->execute(
            'INSERT INTO category (name, slug, image_path, display_order, is_active) '
            . 'VALUES (:name, :slug, :image, :ord, :active)',
            [
                'name' => $data['name'],
                'slug' => $data['slug'],
                'image' => $data['image_path'],
                'ord' => $data['display_order'],
                'active' => $data['is_active'],
            ],
        );
    }

    /**
     * @param array{name: string, slug: string, image_path: ?string, display_order: int} $data
     */
    public function update(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE category SET name = :name, slug = :slug, image_path = :image, display_order = :ord WHERE id = :id',
            [
                'name' => $data['name'],
                'slug' => $data['slug'],
                'image' => $data['image_path'],
                'ord' => $data['display_order'],
                'id' => $id,
            ],
        );
    }

    public function setActive(int $id, bool $active): void
    {
        $this->db->execute(
            'UPDATE category SET is_active = :active WHERE id = :id',
            ['active' => $active ? 1 : 0, 'id' => $id],
        );
    }
}
