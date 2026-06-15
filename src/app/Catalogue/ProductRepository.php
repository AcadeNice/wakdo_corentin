<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Core\DatabaseInterface;

/**
 * Acces aux donnees de la table product (sous-domaine Catalogue). Suit le pattern
 * etabli par CategoryRepository. La suppression dure peut etre bloquee par des FK
 * RESTRICT (order_item, menu.burger_product_id, menu_slot_option,
 * order_item_selection) : le controleur attrape la violation (SQLSTATE 23000) ->
 * 422, plutot que de pre-tester chaque reference.
 */
final class ProductRepository
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Liste pour le back-office, avec le libelle de categorie.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->fetchAll(
            'SELECT p.id, p.category_id, p.name, p.price_cents, p.vat_rate, p.is_available, '
            . 'p.display_order, c.name AS category_name '
            . 'FROM product p JOIN category c ON c.id = p.category_id '
            . 'ORDER BY p.display_order, p.name',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT id, category_id, name, description, price_cents, vat_rate, image_path, '
            . 'is_available, display_order FROM product WHERE id = :id',
            ['id' => $id],
        );
    }

    public function categoryExists(int $categoryId): bool
    {
        return $this->db->fetch('SELECT id FROM category WHERE id = :id', ['id' => $categoryId]) !== null;
    }

    /**
     * @param array{category_id: int, name: string, description: ?string, price_cents: int, vat_rate: int, image_path: ?string, is_available: int, display_order: int} $data
     */
    public function create(array $data): void
    {
        $this->db->execute(
            'INSERT INTO product (category_id, name, description, price_cents, vat_rate, image_path, is_available, display_order) '
            . 'VALUES (:category, :name, :description, :price, :vat, :image, :available, :ord)',
            $this->bind($data),
        );
    }

    /**
     * @param array{category_id: int, name: string, description: ?string, price_cents: int, vat_rate: int, image_path: ?string, is_available: int, display_order: int} $data
     */
    public function update(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE product SET category_id = :category, name = :name, description = :description, '
            . 'price_cents = :price, vat_rate = :vat, image_path = :image, is_available = :available, '
            . 'display_order = :ord WHERE id = :id',
            $this->bind($data) + ['id' => $id],
        );
    }

    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM product WHERE id = :id', ['id' => $id]);
    }

    /**
     * Allowlist d'affectation de masse (RG-T16) : seules ces colonnes sont liees.
     *
     * @param array{category_id: int, name: string, description: ?string, price_cents: int, vat_rate: int, image_path: ?string, is_available: int, display_order: int} $data
     * @return array<string, mixed>
     */
    private function bind(array $data): array
    {
        return [
            'category'    => $data['category_id'],
            'name'        => $data['name'],
            'description' => $data['description'],
            'price'       => $data['price_cents'],
            'vat'         => $data['vat_rate'],
            'image'       => $data['image_path'],
            'available'   => $data['is_available'],
            'ord'         => $data['display_order'],
        ];
    }
}
