<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Core\DatabaseInterface;

/**
 * Acces aux donnees du stock (sous-domaine Ingredients/stock). Suit le pattern
 * etabli par CategoryRepository / ProductRepository (DatabaseInterface, requetes
 * preparees, allowlist d'ecriture RG-T16).
 *
 * Modele de stock en pourcentage (mcd 5.3) : stock_capacity (> 0) = 100 % de
 * reference ; stock_pct et la bande (normal/low/critical) sont CALCULES, jamais
 * stockes (stockPct/stockBand). stock_quantity est SIGNE : il peut devenir
 * negatif quand les ventes depassent le stock compte (survente assumee, remontee
 * au manager) ; le systeme ne bloque jamais une commande sur le stock.
 *
 * Le stock ne bouge JAMAIS par ecriture directe de stock_quantity hors creation :
 *  - restock(...)        : +N packs (mlt 9.1), sans PIN, acteur capture par permission ;
 *  - inventoryCount(...) : comptage absolu (mlt 9.2), PIN, ecrit une ligne MEME si delta=0.
 * Chaque mouvement insere une ligne stock_movement (journal append-only) dans la
 * MEME transaction que la mise a jour du stock (RG-T08). L'imputabilite passe par
 * stock_movement.user_id, PAS par audit_log (RG-T14 exclut le stock du double-journal).
 *
 * Topologie FK (db/migrations/0001) : ingredient est reference par product_ingredient
 * (RESTRICT) et stock_movement (RESTRICT) -> la suppression dure est bloquee des
 * qu'une recette ou un mouvement existe ; le controleur traduit la violation
 * (SQLSTATE 23000) en 409 et propose la desactivation (is_active).
 */
final class IngredientRepository
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Liste pour le back-office, enrichie du pourcentage et de la bande calcules.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, name, unit, stock_quantity, stock_capacity, pack_size, pack_label, '
            . 'low_stock_pct, critical_stock_pct, is_active FROM ingredient ORDER BY name',
        );

        return array_map([self::class, 'withStatus'], $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->fetch(
            'SELECT id, name, unit, stock_quantity, stock_capacity, pack_size, pack_label, '
            . 'low_stock_pct, critical_stock_pct, is_active FROM ingredient WHERE id = :id',
            ['id' => $id],
        );

        return $row === null ? null : self::withStatus($row);
    }

    public function nameExists(string $name, int $exceptId = 0): bool
    {
        return $this->db->fetch(
            'SELECT id FROM ingredient WHERE name = :name AND id <> :id',
            ['name' => $name, 'id' => $exceptId],
        ) !== null;
    }

    /**
     * Creation : pose les valeurs initiales, stock_quantity inclus (point de
     * depart du stock). Allowlist RG-T16.
     *
     * @param array{name: string, unit: string, stock_quantity: int, stock_capacity: int, pack_size: int, pack_label: ?string, low_stock_pct: int, critical_stock_pct: int, is_active: int} $data
     */
    public function create(array $data): void
    {
        $this->db->execute(
            'INSERT INTO ingredient (name, unit, stock_quantity, stock_capacity, pack_size, '
            . 'pack_label, low_stock_pct, critical_stock_pct, is_active) '
            . 'VALUES (:name, :unit, :qty, :cap, :pack, :label, :low, :crit, :active)',
            [
                'name'   => $data['name'],
                'unit'   => $data['unit'],
                'qty'    => $data['stock_quantity'],
                'cap'    => $data['stock_capacity'],
                'pack'   => $data['pack_size'],
                'label'  => $data['pack_label'],
                'low'    => $data['low_stock_pct'],
                'crit'   => $data['critical_stock_pct'],
                'active' => $data['is_active'],
            ],
        );
    }

    /**
     * Mise a jour des attributs de definition. Allowlist RG-T16 : stock_quantity
     * et is_active NE sont PAS modifiables ici. Le stock ne bouge que via
     * restock/inventoryCount (ledger) ; is_active bascule via setActive
     * (soft-delete). Les lier ici ouvrirait une affectation de masse non voulue.
     *
     * @param array{name: string, unit: string, stock_capacity: int, pack_size: int, pack_label: ?string, low_stock_pct: int, critical_stock_pct: int} $data
     */
    public function update(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE ingredient SET name = :name, unit = :unit, stock_capacity = :cap, '
            . 'pack_size = :pack, pack_label = :label, low_stock_pct = :low, '
            . 'critical_stock_pct = :crit WHERE id = :id',
            [
                'name'  => $data['name'],
                'unit'  => $data['unit'],
                'cap'   => $data['stock_capacity'],
                'pack'  => $data['pack_size'],
                'label' => $data['pack_label'],
                'low'   => $data['low_stock_pct'],
                'crit'  => $data['critical_stock_pct'],
                'id'    => $id,
            ],
        );
    }

    public function setActive(int $id, bool $active): int
    {
        return $this->db->execute(
            'UPDATE ingredient SET is_active = :a WHERE id = :id',
            ['a' => $active ? 1 : 0, 'id' => $id],
        );
    }

    /**
     * Suppression dure. Bloquee par FK RESTRICT (product_ingredient / stock_movement)
     * des qu'une recette ou un mouvement reference l'ingredient ; le controleur
     * attrape SQLSTATE 23000 -> 409 et propose la desactivation.
     */
    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM ingredient WHERE id = :id', ['id' => $id]);
    }

    /**
     * Pre-verification FK-safe : l'ingredient est-il reference par une recette
     * (product_ingredient) ou un mouvement de stock (stock_movement) ? Les deux
     * FK sont RESTRICT, donc l'un ou l'autre bloque la suppression dure.
     */
    public function isReferenced(int $id): bool
    {
        if ($this->db->fetch('SELECT ingredient_id FROM product_ingredient WHERE ingredient_id = :id LIMIT 1', ['id' => $id]) !== null) {
            return true;
        }

        return $this->db->fetch('SELECT id FROM stock_movement WHERE ingredient_id = :id LIMIT 1', ['id' => $id]) !== null;
    }

    /**
     * Reapprovisionnement (mlt 9.1) : +N packs => stock += N * pack_size, et une
     * ligne stock_movement(restock) dans la MEME transaction (RG-T08). Sans PIN :
     * $userId est l'acteur de session (capture par la permission stock.manage,
     * RG-4), pas un acteur resolu par PIN. Les bornes d'entree (packs >= 1, mlt 9.1
     * PRE-3) sont validees par l'appelant (controleur, RG-T18), pas ici.
     */
    public function restock(int $id, int $packs, ?int $userId, ?string $note = null): void
    {
        $this->db->transaction(function (DatabaseInterface $db) use ($id, $packs, $userId, $note): void {
            $packSize = (int) ($db->fetch('SELECT pack_size FROM ingredient WHERE id = :id', ['id' => $id])['pack_size'] ?? 0);
            $delta = $packs * $packSize;
            $db->execute(
                'UPDATE ingredient SET stock_quantity = stock_quantity + :delta WHERE id = :id',
                ['delta' => $delta, 'id' => $id],
            );
            $this->insertMovement($db, $id, 'restock', $delta, $userId, $note);
        });
    }

    /**
     * Inventaire (mlt 9.2) : comptage physique absolu => stock_quantity = compte,
     * et une ligne stock_movement(inventory_correction, delta = compte - actuel)
     * dans la MEME transaction. RG-3 : la ligne est ecrite MEME si delta = 0 (un
     * comptage conforme reste une preuve de controle a tracer). $userId est
     * l'acteur resolu par le PIN (RG-T13). La borne d'entree (compte >= 0, mlt 9.2
     * PRE-3) est validee par l'appelant (controleur, RG-T18), pas ici.
     */
    public function inventoryCount(int $id, int $countedQuantity, ?int $userId, ?string $note = null): void
    {
        $this->db->transaction(function (DatabaseInterface $db) use ($id, $countedQuantity, $userId, $note): void {
            $current = (int) ($db->fetch('SELECT stock_quantity FROM ingredient WHERE id = :id', ['id' => $id])['stock_quantity'] ?? 0);
            $delta = $countedQuantity - $current;
            $db->execute(
                'UPDATE ingredient SET stock_quantity = :q WHERE id = :id',
                ['q' => $countedQuantity, 'id' => $id],
            );
            $this->insertMovement($db, $id, 'inventory_correction', $delta, $userId, $note);
        });
    }

    /**
     * Registre append-only des mouvements d'un ingredient, du plus recent au plus
     * ancien, BORNE (mlt 9.3 READ_STOCK RG-3 prescrit LIMIT :n ; stock_movement
     * croit a chaque vente, on ne materialise pas tout). La FK order_id reste NULL
     * pour restock/inventory (renseignee cote commande en P4). La visibilite de
     * user_id (RG-4 : manager/admin seulement) est appliquee par le controleur, pas ici.
     *
     * @return array<int, array<string, mixed>>
     */
    public function movements(int $id, int $limit = 50): array
    {
        // La borne est interpolee en entier (cast int + plancher 1) plutot que
        // liee en placeholder : avec ATTR_EMULATE_PREPARES=false (Database), un
        // ':limit' lie comme chaine fait echouer MariaDB sur LIMIT. Un int n'a
        // aucun risque d'injection.
        $bounded = max(1, $limit);

        return $this->db->fetchAll(
            'SELECT id, ingredient_id, movement_type, delta, order_id, user_id, note, created_at '
            . 'FROM stock_movement WHERE ingredient_id = :id ORDER BY created_at DESC, id DESC '
            . 'LIMIT ' . $bounded,
            ['id' => $id],
        );
    }

    private function insertMovement(DatabaseInterface $db, int $ingredientId, string $type, int $delta, ?int $userId, ?string $note): void
    {
        $db->execute(
            'INSERT INTO stock_movement (ingredient_id, movement_type, delta, order_id, user_id, note) '
            . 'VALUES (:ingredient, :type, :delta, NULL, :user, :note)',
            [
                'ingredient' => $ingredientId,
                'type'       => $type,
                'delta'      => $delta,
                'user'       => $userId,
                'note'       => $note,
            ],
        );
    }

    /**
     * Pourcentage de stock = round(quantity / capacity * 100). Calcule, non stocke.
     * Garde anti division par zero (stock_capacity porte un CHECK > 0 en base).
     */
    public static function stockPct(int $quantity, int $capacity): int
    {
        if ($capacity <= 0) {
            return 0;
        }

        return (int) round($quantity * 100 / $capacity);
    }

    /**
     * Bande a 3 niveaux (mcd 5.3), en arithmetique entiere (pas de flottant) :
     *  - critical : quantity <= capacity * critical_pct / 100 (rupture auto)
     *  - low      : quantity <= capacity * low_pct / 100 (alerte, encore commandable)
     *  - normal   : au-dessus.
     * Un stock negatif (survente) tombe en critical. critical_pct < low_pct est
     * garanti par un CHECK de table.
     */
    public static function stockBand(int $quantity, int $capacity, int $lowPct, int $critPct): string
    {
        if ($capacity <= 0) {
            return 'critical';
        }

        $scaled = $quantity * 100;
        if ($scaled <= $capacity * $critPct) {
            return 'critical';
        }
        if ($scaled <= $capacity * $lowPct) {
            return 'low';
        }

        return 'normal';
    }

    /**
     * Enrichit une ligne ingredient des champs calcules stock_pct et stock_band.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function withStatus(array $row): array
    {
        $quantity = (int) ($row['stock_quantity'] ?? 0);
        $capacity = (int) ($row['stock_capacity'] ?? 0);
        $lowPct = (int) ($row['low_stock_pct'] ?? 0);
        $critPct = (int) ($row['critical_stock_pct'] ?? 0);

        $row['stock_pct'] = self::stockPct($quantity, $capacity);
        $row['stock_band'] = self::stockBand($quantity, $capacity, $lowPct, $critPct);

        return $row;
    }
}
