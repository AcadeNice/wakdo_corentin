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
     * Deplace une categorie d'un rang vers le haut ou vers le bas, et renumerote
     * toute la liste de 1 a N dans la foulee.
     *
     * Renumeroter integralement plutot qu'echanger deux valeurs est volontaire :
     * l'ordre saisi a la main a laisse des doublons et des zeros (un formulaire
     * laisse vide valait 0, donc le la categorie remontait en tete). Un simple
     * echange conserverait ces trous ; une renumerotation les efface des le
     * premier clic, et l'ordre affiche devient exactement l'ordre stocke.
     *
     * L'ordre de reference est celui de la liste affichee (display_order puis
     * name) : ce que l'utilisateur voit est ce sur quoi il agit.
     *
     * @return bool false si la categorie est introuvable ou deja en bout de liste
     */
    public function reorder(int $id, string $direction): bool
    {
        // Meme tri que all() : l'ordre de reference est celui de la liste affichee.
        $ids = array_map(
            static fn (array $r): int => (int) ($r['id'] ?? 0),
            $this->db->fetchAll('SELECT id FROM category ORDER BY display_order, name'),
        );

        $position = array_search($id, $ids, true);
        if ($position === false) {
            return false;
        }

        $cible = $direction === 'up' ? $position - 1 : $position + 1;
        if ($cible < 0 || $cible >= count($ids)) {
            // Deja en haut ou en bas : rien a faire, et surtout pas d'erreur.
            return false;
        }

        [$ids[$position], $ids[$cible]] = [$ids[$cible], $ids[$position]];

        // Une seule transaction : un ordre partiellement reecrit serait pire que
        // l'ordre de depart.
        $this->db->transaction(static function (DatabaseInterface $db) use ($ids): void {
            foreach ($ids as $rang => $identifiant) {
                $db->execute(
                    'UPDATE category SET display_order = :ord WHERE id = :id',
                    ['ord' => $rang + 1, 'id' => $identifiant],
                );
            }
        });

        return true;
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

    /**
     * Lecture publique pour la borne (P4, docs/api/conventions.md 5.2) : seulement
     * les categories actives, triees comme la liste back-office. Le flag is_active
     * n'est pas selectionne (toutes celles-ci le sont) -> rien d'inutile a la borne.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeForCatalogue(): array
    {
        return $this->db->fetchAll(
            'SELECT id, name, slug, image_path, display_order '
            . 'FROM category WHERE is_active = 1 ORDER BY display_order, name',
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
