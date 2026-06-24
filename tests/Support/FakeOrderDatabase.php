<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Core\DatabaseInterface;

/**
 * Double DatabaseInterface dedie au domaine Commande (P4). Le double generique
 * FakeDatabase repond par boutons fixes (un seul produit/menu) ; une commande
 * mele plusieurs produits/menus distincts, d'ou ce double indexe par id.
 *
 * Couvre createPending (catalogue + idempotence) ET pay (lecture de la commande
 * persistee + recettes -> decrement). Les ecritures sont tracees pour assertion ;
 * payUpdateAffected simule l'issue de la transition gardee (0 = course perdue).
 */
final class FakeOrderDatabase implements DatabaseInterface
{
    /** @var list<array{sql:string, params:array<string,mixed>}> */
    public array $writes = [];

    /** @var array<int, array<string,mixed>> produits indexes par id (find). */
    public array $products = [];
    /** @var array<int, array<string,mixed>> menus indexes par id (find). */
    public array $menus = [];
    /** @var array<int, list<array<string,mixed>>> slots (slotsWithOptions) par menu id. */
    public array $slotRows = [];
    /** @var array<int, list<array<string,mixed>>> recettes (composition) par produit id. */
    public array $compositions = [];
    /** @var list<array<string,mixed>> ids produits en rupture calculee (autoUnavailableIds, RG-T21). */
    public array $autoUnavailableRows = [];

    /** Commande existante renvoyee par la recherche idempotency_key ; null = aucune. */
    /** @var array<string,mixed>|null */
    public ?array $existingByKey = null;

    /** Commande renvoyee par la recherche order_number (pay) ; null = introuvable. */
    /** @var array<string,mixed>|null */
    public ?array $orderByNumber = null;

    /** Statut relu apres une transition gardee a 0 ligne (course concurrente). */
    public string $recheckStatus = 'paid';

    /** La commande porte-t-elle des mouvements 'sale' (= deja encaissee/decrementee) ?
     *  Pilote le re-credit a l'annulation (OrderRepository::hasSaleMovements). */
    public bool $saleMovementsExist = false;

    /** Lignes order_item renvoyees pour la commande encaissee. */
    /** @var list<array<string,mixed>> */
    public array $orderItems = [];

    /** Selections (product_id) par order_item id. */
    /** @var array<int, list<array<string,mixed>>> */
    public array $selectionsByItem = [];

    /** Modificateurs (ingredient_id, action) par order_item id. */
    /** @var array<int, list<array<string,mixed>>> */
    public array $modifiersByItem = [];

    /** Lignes affectees par l'UPDATE de transition pending_payment -> paid. */
    public int $payUpdateAffected = 1;

    private int $autoId = 99;

    public function fetch(string $sql, array $params = []): ?array
    {
        if (str_contains($sql, 'LAST_INSERT_ID')) {
            return ['id' => $this->autoId];
        }
        if (str_contains($sql, 'FROM customer_order WHERE idempotency_key')) {
            return $this->existingByKey;
        }
        if (str_contains($sql, 'FROM customer_order WHERE order_number')) {
            return $this->orderByNumber;
        }
        if (str_contains($sql, 'SELECT status FROM customer_order WHERE id')) {
            return ['status' => $this->recheckStatus];
        }
        if (str_contains($sql, 'FROM product WHERE id = :id')) {
            return $this->products[(int) $params['id']] ?? null;
        }
        if (str_contains($sql, 'FROM menu WHERE id = :id')) {
            return $this->menus[(int) $params['id']] ?? null;
        }
        if (str_contains($sql, 'FROM stock_movement WHERE order_id')) {
            return $this->saleMovementsExist ? ['x' => 1] : null;
        }

        return null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        if (str_contains($sql, 'FROM menu_slot s')) {
            return $this->slotRows[(int) $params['id']] ?? [];
        }
        // RG-T21 : autoUnavailableIds() (sans param) AVANT composition() (avec :id) :
        // les deux lisent product_ingredient ; on desambiguise sur SELECT DISTINCT.
        if (str_contains($sql, 'SELECT DISTINCT pi.product_id')) {
            return $this->autoUnavailableRows;
        }
        if (str_contains($sql, 'FROM product_ingredient pi')) {
            return $this->compositions[(int) $params['id']] ?? [];
        }
        if (str_contains($sql, 'FROM order_item WHERE order_id')) {
            return $this->orderItems;
        }
        if (str_contains($sql, 'FROM order_item_selection WHERE order_item_id')) {
            return $this->selectionsByItem[(int) $params['oiid']] ?? [];
        }
        if (str_contains($sql, 'FROM order_item_modifier WHERE order_item_id')) {
            return $this->modifiersByItem[(int) $params['oiid']] ?? [];
        }

        return [];
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->writes[] = ['sql' => $sql, 'params' => $params];

        if (str_contains($sql, 'INSERT INTO customer_order') || str_contains($sql, 'INSERT INTO order_item ')) {
            $this->autoId++;
        }
        if (str_contains($sql, 'UPDATE customer_order SET status')) {
            return $this->payUpdateAffected;
        }

        return 1;
    }

    public function transaction(callable $fn): void
    {
        $fn($this);
    }

    /** @return array<string,mixed> */
    public function firstWrite(string $needle): array
    {
        foreach ($this->writes as $write) {
            if (str_contains($write['sql'], $needle)) {
                return $write['params'];
            }
        }

        return [];
    }

    /** SQL de la premiere ecriture dont le texte contient $needle (chaine vide sinon). */
    public function firstWriteSql(string $needle): string
    {
        foreach ($this->writes as $write) {
            if (str_contains($write['sql'], $needle)) {
                return $write['sql'];
            }
        }

        return '';
    }

    public function countWrites(string $needle): int
    {
        return count(array_filter($this->writes, static fn (array $w): bool => str_contains($w['sql'], $needle)));
    }

    /**
     * Parametres de toutes les ecritures dont le SQL contient $needle (ordre d'insertion).
     *
     * @return list<array<string,mixed>>
     */
    public function allWrites(string $needle): array
    {
        $out = [];
        foreach ($this->writes as $write) {
            if (str_contains($write['sql'], $needle)) {
                $out[] = $write['params'];
            }
        }

        return $out;
    }
}
