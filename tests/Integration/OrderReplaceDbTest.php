<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Catalogue\MenuRepository;
use App\Catalogue\ProductRepository;
use App\Order\OrderRepository;
use App\Order\OrderValidationException;
use App\Core\Config;
use App\Core\Database;

/**
 * Modification d'une commande en attente de paiement (F18) contre une vraie MariaDB.
 * Auto-skip si WAKDO_DB_TESTS != 1. Commandes jetables (order_number prefixe
 * IT-F18R-<suffixe>), nettoyees en tearDown.
 *
 * Ce que seule une vraie base peut prouver ici :
 *  - la CASCADE fait vraiment le travail : purger les order_item emporte leurs
 *    selections de slot et leurs modificateurs d'ingredient, sans ligne orpheline ;
 *  - les totaux persistes correspondent aux lignes persistees APRES remplacement ;
 *  - le verrou de ligne existe reellement (la requete FOR UPDATE s'execute et rend la
 *    ligne) -- un double de base ne prouve pas qu'un SQL est valide ;
 *  - la modification ne laisse aucune trace dans stock_movement et ne bouge aucun
 *    stock, y compris sur des produits qui ont une recette reelle.
 */
final class OrderReplaceDbTest extends TestCase
{
    private Database $db;
    private string $suffix = '';
    private int $productA = 0;
    private int $productB = 0;
    private int $stockBaseline = 0;
    private int $movementBaseline = 0;

    private function stockSum(): int
    {
        return (int) ($this->db->fetch('SELECT SUM(stock_quantity) AS s FROM ingredient')['s'] ?? 0);
    }

    private function movementCount(): int
    {
        return (int) ($this->db->fetch('SELECT COUNT(*) AS n FROM stock_movement')['n'] ?? 0);
    }

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

        $this->suffix = bin2hex(random_bytes(4));
        // Empreinte de depart : le tearDown verifie qu'il l'a exactement restauree. Cette
        // base porte les donnees de demonstration de la soutenance ; un test qui laisse du
        // stock debite ou un mouvement orphelin derriere lui fausserait les indicateurs.
        $this->stockBaseline = $this->stockSum();
        $this->movementBaseline = $this->movementCount();

        // Deux produits REELS du catalogue, disponibles et de prix differents. Choisis
        // dans la base plutot que codes en dur : un renumerotage du seed ne doit pas
        // casser le test en silence.
        $rows = $this->db->fetchAll(
            'SELECT p.id, p.price_cents FROM product p JOIN category c ON c.id = p.category_id '
            . 'WHERE p.is_available = 1 AND c.is_active = 1 AND p.base_product_id IS NULL '
            . 'ORDER BY p.id LIMIT 2',
        );
        self::assertCount(2, $rows, 'le catalogue doit porter au moins deux produits commandables');
        $this->productA = (int) $rows[0]['id'];
        $this->productB = (int) $rows[1]['id'];
    }

    /**
     * Nettoyage EXACT. Trois pieges, tous evites deliberement :
     *
     *  1. On cible par idempotency_key, jamais par order_number. Les numeros sont
     *     generes ('K' + id) donc non prefixables : un LIKE 'K%' emporterait TOUTES les
     *     commandes borne de la base de demonstration.
     *  2. Un test encaisse, donc du stock est debite et des lignes stock_movement sont
     *     ecrites. Comme stock_movement.order_id est ON DELETE SET NULL, supprimer la
     *     commande laisserait le mouvement ET le stock debite. On re-credite donc
     *     exactement l'inverse des deltas mesures, puis on supprime les mouvements.
     *  3. Ordre FK-safe : mouvements d'abord, commandes ensuite (customer_order CASCADE
     *     vers order_item, qui CASCADE vers ses enfants).
     */
    protected function tearDown(): void
    {
        if ($this->suffix === '') {
            return;
        }

        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->db->fetchAll(
                'SELECT id FROM customer_order WHERE idempotency_key LIKE :k',
                ['k' => 'IT-F18R-' . $this->suffix . '%'],
            ),
        );
        if ($ids === []) {
            $this->assertFootprintRestored();

            return;
        }
        $list = implode(',', array_map('intval', $ids));

        foreach ($this->db->fetchAll(
            'SELECT ingredient_id, SUM(delta) AS d FROM stock_movement '
            . 'WHERE order_id IN (' . $list . ') GROUP BY ingredient_id',
        ) as $row) {
            $this->db->execute(
                'UPDATE ingredient SET stock_quantity = stock_quantity - :d WHERE id = :id',
                ['d' => (int) $row['d'], 'id' => (int) $row['ingredient_id']],
            );
        }
        $this->db->execute('DELETE FROM stock_movement WHERE order_id IN (' . $list . ')');
        $this->db->execute('DELETE FROM customer_order WHERE id IN (' . $list . ')');

        $this->assertFootprintRestored();
    }

    /**
     * Verifie que le test n'a laisse AUCUNE empreinte sur le stock. Leve plutot que
     * d'asserter : une assertion dans tearDown serait rattachee au mauvais test, alors
     * qu'une exception fait echouer bruyamment et nomme l'ecart.
     */
    private function assertFootprintRestored(): void
    {
        $stock = $this->stockSum();
        $movements = $this->movementCount();
        if ($stock !== $this->stockBaseline || $movements !== $this->movementBaseline) {
            throw new \RuntimeException(sprintf(
                'Nettoyage incomplet : stock %d -> %d, mouvements %d -> %d. '
                . 'La base de demonstration doit etre rendue intacte.',
                $this->stockBaseline,
                $stock,
                $this->movementBaseline,
                $movements,
            ));
        }
    }

    private function repo(): OrderRepository
    {
        return new OrderRepository($this->db, new ProductRepository($this->db), new MenuRepository($this->db));
    }

    /**
     * Cree une commande jetable via le chemin REEL (createPending) et renvoie son etat.
     *
     * @return array{id:int, order_number:string, total_ttc_cents:int, status:string}
     */
    private function createOrder(int $productId, int $quantity = 1, string $tag = 'a'): array
    {
        return $this->repo()->createPending([
            'idempotency_key' => 'IT-F18R-' . $this->suffix . '-' . $tag,
            'service_mode'    => 'takeaway',
            'items'           => [['type' => 'product', 'product_id' => $productId, 'quantity' => $quantity]],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderRow(int $id): array
    {
        $row = $this->db->fetch(
            'SELECT status, total_ht_cents, total_vat_cents, total_ttc_cents FROM customer_order WHERE id = :id',
            ['id' => $id],
        );
        self::assertNotNull($row);

        return $row;
    }

    private function itemCount(int $orderId): int
    {
        return (int) ($this->db->fetch(
            'SELECT COUNT(*) AS n FROM order_item WHERE order_id = :id',
            ['id' => $orderId],
        )['n'] ?? 0);
    }

    public function testReplacesTheLinesAndThePersistedTotals(): void
    {
        $created = $this->createOrder($this->productA, 1);
        $before = $this->orderRow($created['id']);

        $replaced = $this->repo()->replaceItems($created['order_number'], [
            'service_mode' => 'takeaway',
            'items'        => [['type' => 'product', 'product_id' => $this->productB, 'quantity' => 3]],
        ]);

        $after = $this->orderRow($created['id']);
        self::assertSame($created['order_number'], $replaced['order_number']);
        self::assertSame('pending_payment', $after['status']);
        self::assertSame($replaced['total_ttc_cents'], (int) $after['total_ttc_cents']);
        self::assertNotSame((int) $before['total_ttc_cents'], (int) $after['total_ttc_cents']);
        // La TVA reste la difference entre TTC et HT sur les nouvelles lignes.
        self::assertSame(
            (int) $after['total_ttc_cents'] - (int) $after['total_ht_cents'],
            (int) $after['total_vat_cents'],
        );
        self::assertSame(1, $this->itemCount($created['id']));
    }

    public function testPersistedTotalMatchesThePersistedLines(): void
    {
        $created = $this->createOrder($this->productA, 1);

        $this->repo()->replaceItems($created['order_number'], [
            'service_mode' => 'takeaway',
            'items'        => [
                ['type' => 'product', 'product_id' => $this->productA, 'quantity' => 2],
                ['type' => 'product', 'product_id' => $this->productB, 'quantity' => 1],
            ],
        ]);

        // Verrou d'integrite : le total de la commande doit etre la somme des snapshots
        // de ses lignes. Un ecart voudrait dire qu'on facture autre chose que ce qui est
        // enregistre -- exactement ce qu'un remplacement mal ordonne produirait.
        $sum = (int) ($this->db->fetch(
            'SELECT SUM(unit_price_cents_snapshot * quantity) AS s FROM order_item WHERE order_id = :id',
            ['id' => $created['id']],
        )['s'] ?? 0);
        self::assertSame((int) $this->orderRow($created['id'])['total_ttc_cents'], $sum);
        self::assertSame(2, $this->itemCount($created['id']));
    }

    public function testPurgeLeavesNoOrphanSelectionOrModifier(): void
    {
        // Commande avec un MENU : elle porte des selections de slot, donc des enfants de
        // order_item. C'est le cas ou la CASCADE doit faire son travail.
        $menu = $this->db->fetch(
            'SELECT m.id, m.burger_product_id FROM menu m JOIN category c ON c.id = m.category_id '
            . 'WHERE m.is_available = 1 AND c.is_active = 1 ORDER BY m.id LIMIT 1',
        );
        if ($menu === null) {
            self::markTestSkipped('aucun menu commandable dans le catalogue');
        }
        $menuId = (int) $menu['id'];
        $slot = $this->db->fetch(
            'SELECT s.id, o.product_id FROM menu_slot s JOIN menu_slot_option o ON o.menu_slot_id = s.id '
            . 'WHERE s.menu_id = :m ORDER BY s.display_order LIMIT 1',
            ['m' => $menuId],
        );
        if ($slot === null) {
            self::markTestSkipped('le menu n a aucune option de slot');
        }

        $created = $this->repo()->createPending([
            'idempotency_key' => 'IT-F18R-' . $this->suffix . '-menu',
            'service_mode'    => 'takeaway',
            'items'           => [[
                'type' => 'menu', 'menu_id' => $menuId, 'quantity' => 1, 'format' => 'normal',
                'selections' => [['menu_slot_id' => (int) $slot['id'], 'product_id' => (int) $slot['product_id']]],
            ]],
        ]);

        $selectionsBefore = (int) ($this->db->fetch(
            'SELECT COUNT(*) AS n FROM order_item_selection s JOIN order_item i ON i.id = s.order_item_id '
            . 'WHERE i.order_id = :id',
            ['id' => $created['id']],
        )['n'] ?? 0);
        self::assertGreaterThan(0, $selectionsBefore, 'la commande menu doit porter des selections');

        $this->repo()->replaceItems($created['order_number'], [
            'service_mode' => 'takeaway',
            'items'        => [['type' => 'product', 'product_id' => $this->productA, 'quantity' => 1]],
        ]);

        // Les selections de l'ancienne ligne menu doivent avoir disparu avec elle. Une
        // selection orpheline resterait invisible mais fausserait le calcul de stock a
        // l'encaissement (consumption lit les selections pour trouver les recettes).
        $orphans = (int) ($this->db->fetch(
            'SELECT COUNT(*) AS n FROM order_item_selection s '
            . 'LEFT JOIN order_item i ON i.id = s.order_item_id WHERE i.id IS NULL',
        )['n'] ?? 0);
        self::assertSame(0, $orphans);
        self::assertSame(1, $this->itemCount($created['id']));
    }

    public function testTheRowLockQueryIsValidSqlAndReturnsTheOrder(): void
    {
        $created = $this->createOrder($this->productA, 1);

        // Un double de base ne prouve pas qu'un SQL s'execute. replaceItems reussit donc
        // la requete FOR UPDATE a ete acceptee par MariaDB et a rendu la ligne : sans
        // elle, la methode aurait leve ORDER_NOT_FOUND.
        $replaced = $this->repo()->replaceItems($created['order_number'], [
            'service_mode' => 'takeaway',
            'items'        => [['type' => 'product', 'product_id' => $this->productB, 'quantity' => 1]],
        ]);

        self::assertSame($created['id'], $replaced['id']);
    }

    public function testStockIsUntouchedByAModification(): void
    {
        $created = $this->createOrder($this->productA, 1);
        $sumBefore = (int) ($this->db->fetch('SELECT SUM(stock_quantity) AS s FROM ingredient')['s'] ?? 0);
        $movementsBefore = (int) ($this->db->fetch('SELECT COUNT(*) AS n FROM stock_movement')['n'] ?? 0);

        $this->repo()->replaceItems($created['order_number'], [
            'service_mode' => 'takeaway',
            'items'        => [['type' => 'product', 'product_id' => $this->productB, 'quantity' => 5]],
        ]);

        // Une commande en attente n'a rien consomme : modifier son contenu ne doit ni
        // debiter ni recrediter. C'est le meme invariant que l'expiration (F10), verifie
        // ici sur des produits qui ont une recette reelle.
        self::assertSame($sumBefore, (int) ($this->db->fetch('SELECT SUM(stock_quantity) AS s FROM ingredient')['s'] ?? 0));
        self::assertSame($movementsBefore, (int) ($this->db->fetch('SELECT COUNT(*) AS n FROM stock_movement')['n'] ?? 0));
    }

    public function testAPaidOrderIsRefusedAndLeftIntact(): void
    {
        $created = $this->createOrder($this->productA, 1);
        $this->repo()->pay($created['order_number']);
        $itemsBefore = $this->itemCount($created['id']);
        $totalBefore = (int) $this->orderRow($created['id'])['total_ttc_cents'];

        try {
            $this->repo()->replaceItems($created['order_number'], [
                'service_mode' => 'takeaway',
                'items'        => [['type' => 'product', 'product_id' => $this->productB, 'quantity' => 4]],
            ]);
            self::fail('une commande encaissee ne doit pas etre modifiable');
        } catch (OrderValidationException $exception) {
            self::assertSame('INVALID_TRANSITION', $exception->getMessage());
        }

        // Le stock de cette commande est deja debite : changer ses lignes rendrait le
        // journal des mouvements incoherent avec le contenu facture.
        self::assertSame($itemsBefore, $this->itemCount($created['id']));
        self::assertSame($totalBefore, (int) $this->orderRow($created['id'])['total_ttc_cents']);
    }

    public function testKnownKeyUpdatesTheSameOrderInsteadOfCreatingASecond(): void
    {
        $first = $this->createOrder($this->productA, 1, 'reuse');
        $countBefore = (int) ($this->db->fetch('SELECT COUNT(*) AS n FROM customer_order')['n'] ?? 0);

        // Meme cle, panier different : c'est le scenario borne de F18 (le client est
        // reparti modifier son panier puis revient payer).
        $second = $this->repo()->createPending([
            'idempotency_key' => 'IT-F18R-' . $this->suffix . '-reuse',
            'service_mode'    => 'takeaway',
            'items'           => [['type' => 'product', 'product_id' => $this->productB, 'quantity' => 2]],
        ]);

        self::assertSame($first['order_number'], $second['order_number']);
        self::assertSame($first['id'], $second['id']);
        // Aucune commande creee : c'est ce qui supprime l'orpheline a la source, au lieu
        // de la laisser au balayage de 2h (F10).
        self::assertSame($countBefore, (int) ($this->db->fetch('SELECT COUNT(*) AS n FROM customer_order')['n'] ?? 0));
        self::assertNotSame($first['total_ttc_cents'], $second['total_ttc_cents']);
    }

    public function testPaymentAfterAModificationChargesTheNewTotal(): void
    {
        $created = $this->createOrder($this->productA, 1);

        $replaced = $this->repo()->replaceItems($created['order_number'], [
            'service_mode' => 'takeaway',
            'items'        => [['type' => 'product', 'product_id' => $this->productB, 'quantity' => 2]],
        ]);
        $paid = $this->repo()->pay($created['order_number']);

        // Le bout du bout de F18 : ce qui est encaisse est le panier MODIFIE. Avant le
        // lot, pay() renvoyait le total lu avant la modification.
        self::assertSame($replaced['total_ttc_cents'], $paid['total_ttc_cents']);
        self::assertSame('preparing', $paid['status']);
        self::assertSame(
            $paid['total_ttc_cents'],
            (int) $this->orderRow($created['id'])['total_ttc_cents'],
        );
    }
}
