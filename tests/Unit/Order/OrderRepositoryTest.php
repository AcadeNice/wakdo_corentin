<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use App\Catalogue\MenuRepository;
use App\Catalogue\ProductRepository;
use App\Order\OrderRepository;
use App\Order\OrderValidationException;
use App\Tests\Support\FakeOrderDatabase;

/**
 * Couvre createPending (calcul RG-4, numero K+id, idempotence, validation) et pay
 * (transition gardee -> paid, decrement de stock atomique RG-T20, idempotence)
 * sur le double dedie FakeOrderDatabase, sans base reelle.
 */
final class OrderRepositoryTest extends TestCase
{
    private function repo(FakeOrderDatabase $db): OrderRepository
    {
        return new OrderRepository($db, new ProductRepository($db), new MenuRepository($db));
    }

    public function testProductOrderComputesLineVatAndKId(): void
    {
        $db = new FakeOrderDatabase();
        $db->products[12] = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'is_available' => 1];

        $res = $this->repo($db)->createPending([
            'idempotency_key' => 'abc',
            'service_mode' => 'takeaway',
            'items' => [['type' => 'product', 'product_id' => 12, 'quantity' => 1]],
        ]);

        // 890 TTC a 10% -> HT = round(890*1000/1100) = 809, TVA = 81.
        $order = $db->firstWrite('INSERT INTO customer_order');
        self::assertSame(890, $order['ttc']);
        self::assertSame(809, $order['ht']);
        self::assertSame(81, $order['vat']);
        self::assertSame('K100', $res['order_number']);
        self::assertSame('pending_payment', $res['status']);
        self::assertSame(890, $res['total_ttc_cents']);

        $item = $db->firstWrite('INSERT INTO order_item ');
        self::assertSame('Cheeseburger', $item['label']);
        self::assertSame(890, $item['price']);
        self::assertSame(100, $item['vat']);
    }

    public function testMenuMaxiUsesBurgerVatAndMaxiPrice(): void
    {
        $db = new FakeOrderDatabase();
        $db->menus[5] = ['id' => 5, 'burger_product_id' => 12, 'name' => 'Menu Best Of', 'price_normal_cents' => 990, 'price_maxi_cents' => 1200, 'is_available' => 1];
        $db->products[12] = ['id' => 12, 'name' => 'Burger', 'price_cents' => 600, 'vat_rate' => 100, 'is_available' => 1];
        $db->products[20] = ['id' => 20, 'name' => 'Coca', 'price_cents' => 250, 'vat_rate' => 100, 'is_available' => 1];
        $db->slotRows[5] = [['id' => 7, 'name' => 'Boisson', 'slot_type' => 'drink', 'is_required' => 1, 'display_order' => 0, 'product_id' => 20]];

        $res = $this->repo($db)->createPending([
            'service_mode' => 'dine_in',
            'service_tag' => '42',
            'items' => [['type' => 'menu', 'menu_id' => 5, 'quantity' => 1, 'format' => 'maxi',
                'selections' => [['menu_slot_id' => 7, 'product_id' => 20]]]],
        ]);

        // 1200 TTC a 10% -> HT = round(1200*1000/1100) = 1091, TVA = 109.
        $order = $db->firstWrite('INSERT INTO customer_order');
        self::assertSame(1200, $order['ttc']);
        self::assertSame(1091, $order['ht']);
        self::assertSame('42', $order['tag']);
        $item = $db->firstWrite('INSERT INTO order_item ');
        self::assertSame('maxi', $item['fmt']);
        self::assertSame(1200, $item['price']);
        self::assertSame(1, $db->countWrites('INSERT INTO order_item_selection'));
    }

    public function testAddModifierAddsExtraToLine(): void
    {
        $db = new FakeOrderDatabase();
        $db->products[12] = ['id' => 12, 'name' => 'Burger', 'price_cents' => 890, 'vat_rate' => 100, 'is_available' => 1];
        $db->compositions[12] = [['ingredient_id' => 3, 'is_removable' => 1, 'is_addable' => 1, 'extra_price_cents' => 50, 'quantity_normal' => 1, 'quantity_maxi' => 1]];

        $res = $this->repo($db)->createPending([
            'service_mode' => 'takeaway',
            'items' => [['type' => 'product', 'product_id' => 12, 'quantity' => 1,
                'modifiers' => [['ingredient_id' => 3, 'action' => 'add']]]],
        ]);

        self::assertSame(940, $res['total_ttc_cents']); // 890 + 50
        self::assertSame(1, $db->countWrites('INSERT INTO order_item_modifier'));
    }

    public function testIdempotentReturnsExistingWithoutInsert(): void
    {
        $db = new FakeOrderDatabase();
        $db->existingByKey = ['id' => 7, 'order_number' => 'K7', 'total_ttc_cents' => 500, 'status' => 'pending_payment'];

        $res = $this->repo($db)->createPending([
            'idempotency_key' => 'dup',
            'service_mode' => 'takeaway',
            'items' => [['type' => 'product', 'product_id' => 12, 'quantity' => 1]],
        ]);

        self::assertSame('K7', $res['order_number']);
        self::assertSame(0, $db->countWrites('INSERT INTO customer_order'));
    }

    public function testRejectsUnknownProduct(): void
    {
        $db = new FakeOrderDatabase();
        $this->expectException(OrderValidationException::class);
        $this->repo($db)->createPending([
            'service_mode' => 'takeaway',
            'items' => [['type' => 'product', 'product_id' => 999, 'quantity' => 1]],
        ]);
    }

    public function testRejectsSelectionOutsideSlotOptions(): void
    {
        $db = new FakeOrderDatabase();
        $db->menus[5] = ['id' => 5, 'burger_product_id' => 12, 'name' => 'Menu', 'price_normal_cents' => 990, 'price_maxi_cents' => 1200, 'is_available' => 1];
        $db->products[12] = ['id' => 12, 'name' => 'Burger', 'price_cents' => 600, 'vat_rate' => 100, 'is_available' => 1];
        $db->slotRows[5] = [['id' => 7, 'name' => 'Boisson', 'slot_type' => 'drink', 'is_required' => 1, 'display_order' => 0, 'product_id' => 20]];

        $this->expectException(OrderValidationException::class);
        $this->repo($db)->createPending([
            'service_mode' => 'takeaway',
            'items' => [['type' => 'menu', 'menu_id' => 5, 'quantity' => 1, 'format' => 'normal',
                'selections' => [['menu_slot_id' => 7, 'product_id' => 999]]]], // 999 hors options
        ]);
    }

    // --- pay() : transition + decrement de stock (RG-5 etapes 5-6, RG-T20) ---

    public function testPayTransitionsToPaidAndDecrementsProductRecipe(): void
    {
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'pending_payment'];
        $db->orderItems = [['id' => 1, 'item_type' => 'product', 'product_id' => 12, 'menu_id' => null, 'format' => 'normal', 'quantity' => 2]];
        $db->compositions[12] = [['ingredient_id' => 5, 'quantity_normal' => 1, 'quantity_maxi' => 1]];

        $res = $this->repo($db)->pay('K100');

        self::assertSame('paid', $res['status']);
        self::assertSame('K100', $res['order_number']);
        self::assertSame(1, $db->countWrites('UPDATE customer_order SET status'));

        // 2 unites consommees (qn 1 * quantite 2) -> stock -2 sur l'ingredient 5.
        $dec = $db->firstWrite('UPDATE ingredient SET stock_quantity');
        self::assertSame(2, $dec['u']);
        self::assertSame(5, $dec['id']);
        $move = $db->firstWrite('INSERT INTO stock_movement');
        self::assertSame(-2, $move['delta']);
        self::assertSame(100, $move['oid']);
        self::assertNull($move['uid']); // kiosk : pas d'acteur.
    }

    public function testPayIsIdempotentWhenAlreadyPaid(): void
    {
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'paid'];
        $db->orderItems = [['id' => 1, 'item_type' => 'product', 'product_id' => 12, 'menu_id' => null, 'format' => 'normal', 'quantity' => 2]];
        $db->compositions[12] = [['ingredient_id' => 5, 'quantity_normal' => 1, 'quantity_maxi' => 1]];

        $res = $this->repo($db)->pay('K100');

        self::assertSame('paid', $res['status']);
        self::assertSame(0, $db->countWrites('UPDATE customer_order SET status'));
        self::assertSame(0, $db->countWrites('UPDATE ingredient SET stock_quantity'));
        self::assertSame(0, $db->countWrites('INSERT INTO stock_movement'));
    }

    public function testPayRejectsUnknownOrder(): void
    {
        $db = new FakeOrderDatabase();
        $db->orderByNumber = null;

        $this->expectException(OrderValidationException::class);
        $this->expectExceptionMessage('ORDER_NOT_FOUND');
        $this->repo($db)->pay('K404');
    }

    public function testPayRejectsTerminalStatus(): void
    {
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'cancelled'];

        $this->expectException(OrderValidationException::class);
        $this->expectExceptionMessage('INVALID_TRANSITION');
        $this->repo($db)->pay('K100');
    }

    public function testPayLosesConcurrentRaceReturnsPaidWithoutDecrement(): void
    {
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'pending_payment'];
        $db->payUpdateAffected = 0; // un autre process a deja transite...
        $db->recheckStatus = 'paid'; // ...vers paid : on sort idempotent.
        $db->orderItems = [['id' => 1, 'item_type' => 'product', 'product_id' => 12, 'menu_id' => null, 'format' => 'normal', 'quantity' => 2]];
        $db->compositions[12] = [['ingredient_id' => 5, 'quantity_normal' => 1, 'quantity_maxi' => 1]];

        $res = $this->repo($db)->pay('K100');

        self::assertSame('paid', $res['status']);
        self::assertSame(0, $db->countWrites('UPDATE ingredient SET stock_quantity'));
        self::assertSame(0, $db->countWrites('INSERT INTO stock_movement'));
    }

    public function testPayMenuDecrementsBurgerAndSelectionRecipesAtMaxi(): void
    {
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 1200, 'status' => 'pending_payment'];
        $db->menus[5] = ['id' => 5, 'burger_product_id' => 12, 'name' => 'Menu', 'price_normal_cents' => 990, 'price_maxi_cents' => 1200, 'is_available' => 1];
        $db->orderItems = [['id' => 1, 'item_type' => 'menu', 'product_id' => null, 'menu_id' => 5, 'format' => 'maxi', 'quantity' => 1]];
        $db->selectionsByItem[1] = [['product_id' => 20]];
        $db->compositions[12] = [['ingredient_id' => 3, 'quantity_normal' => 1, 'quantity_maxi' => 2]]; // burger : maxi -> 2
        $db->compositions[20] = [['ingredient_id' => 7, 'quantity_normal' => 1, 'quantity_maxi' => 1]]; // boisson : 1

        $this->repo($db)->pay('K100');

        $decs = $db->allWrites('UPDATE ingredient SET stock_quantity');
        self::assertCount(2, $decs);
        // Ordonne par ingredient_id (ordre de verrou stable) : 3 puis 7.
        self::assertSame(3, $decs[0]['id']);
        self::assertSame(2, $decs[0]['u']);
        self::assertSame(7, $decs[1]['id']);
        self::assertSame(1, $decs[1]['u']);
    }

    public function testPayAppliesRemoveAndAddModifiers(): void
    {
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'pending_payment'];
        $db->orderItems = [['id' => 1, 'item_type' => 'product', 'product_id' => 12, 'menu_id' => null, 'format' => 'normal', 'quantity' => 1]];
        $db->compositions[12] = [
            ['ingredient_id' => 3, 'quantity_normal' => 1, 'quantity_maxi' => 1], // retire
            ['ingredient_id' => 9, 'quantity_normal' => 1, 'quantity_maxi' => 1], // ajoute
        ];
        $db->modifiersByItem[1] = [
            ['ingredient_id' => 3, 'action' => 'remove'],
            ['ingredient_id' => 9, 'action' => 'add'],
        ];

        $this->repo($db)->pay('K100');

        // ingredient 3 retire -> aucun mouvement ; ingredient 9 ajoute -> base + supplement = 2.
        $decs = $db->allWrites('UPDATE ingredient SET stock_quantity');
        self::assertCount(1, $decs);
        self::assertSame(9, $decs[0]['id']);
        self::assertSame(2, $decs[0]['u']);
    }

    public function testPayAggregatesSharedIngredientIntoSingleMovement(): void
    {
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 1500, 'status' => 'pending_payment'];
        $db->orderItems = [
            ['id' => 1, 'item_type' => 'product', 'product_id' => 12, 'menu_id' => null, 'format' => 'normal', 'quantity' => 1],
            ['id' => 2, 'item_type' => 'product', 'product_id' => 13, 'menu_id' => null, 'format' => 'normal', 'quantity' => 1],
        ];
        $db->compositions[12] = [['ingredient_id' => 5, 'quantity_normal' => 1, 'quantity_maxi' => 1]];
        $db->compositions[13] = [['ingredient_id' => 5, 'quantity_normal' => 1, 'quantity_maxi' => 1]];

        $this->repo($db)->pay('K100');

        // Meme ingredient sur deux lignes -> un seul mouvement, delta agrege -2.
        self::assertSame(1, $db->countWrites('INSERT INTO stock_movement'));
        self::assertSame(1, $db->countWrites('UPDATE ingredient SET stock_quantity'));
        $move = $db->firstWrite('INSERT INTO stock_movement');
        self::assertSame(-2, $move['delta']);
    }

    public function testPayAttributesActingUserWhenProvided(): void
    {
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'pending_payment'];
        $db->orderItems = [['id' => 1, 'item_type' => 'product', 'product_id' => 12, 'menu_id' => null, 'format' => 'normal', 'quantity' => 1]];
        $db->compositions[12] = [['ingredient_id' => 5, 'quantity_normal' => 1, 'quantity_maxi' => 1]];

        $this->repo($db)->pay('K100', 7);

        $transition = $db->firstWrite('UPDATE customer_order SET status');
        self::assertSame(7, $transition['uid']);
        $move = $db->firstWrite('INSERT INTO stock_movement');
        self::assertSame(7, $move['uid']);
    }

    public function testDeliverTransitionsPaidToDelivered(): void
    {
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'paid'];

        $res = $this->repo($db)->deliver('K100');

        self::assertSame('delivered', $res['status']);
        self::assertSame('K100', $res['order_number']);
        self::assertNotSame([], $db->firstWrite('UPDATE customer_order SET status'));
    }

    public function testDeliverUnknownThrows(): void
    {
        $db = new FakeOrderDatabase();
        $db->orderByNumber = null;

        $this->expectException(OrderValidationException::class);
        $this->expectExceptionMessage('ORDER_NOT_FOUND');
        $this->repo($db)->deliver('K404');
    }

    public function testDeliverNonPaidThrowsInvalidTransition(): void
    {
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'pending_payment'];

        $this->expectException(OrderValidationException::class);
        $this->expectExceptionMessage('INVALID_TRANSITION');
        $this->repo($db)->deliver('K100');
    }

    public function testDeliverAlreadyDeliveredIsIdempotent(): void
    {
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'delivered'];

        $res = $this->repo($db)->deliver('K100');

        self::assertSame('delivered', $res['status']);
        // Idempotent : aucune transition reecrite.
        self::assertSame([], $db->firstWrite('UPDATE customer_order SET status'));
    }

    public function testDeliverConcurrentRaceRecoversIdempotent(): void
    {
        // Course perdue : l'UPDATE garde par status='paid' n'affecte 0 ligne (un autre
        // appel a deja transite). Le recheck voit 'delivered' -> on sort idempotent.
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'paid'];
        $db->payUpdateAffected = 0;
        $db->recheckStatus = 'delivered';

        $res = $this->repo($db)->deliver('K100');

        self::assertSame('delivered', $res['status']);
    }

    public function testDeliverConcurrentRaceToTerminalThrows(): void
    {
        // Course perdue ET le recheck montre un statut non-delivered (ex. cancelled)
        // -> transition invalide.
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'paid'];
        $db->payUpdateAffected = 0;
        $db->recheckStatus = 'cancelled';

        $this->expectException(OrderValidationException::class);
        $this->expectExceptionMessage('INVALID_TRANSITION');
        $this->repo($db)->deliver('K100');
    }
}
