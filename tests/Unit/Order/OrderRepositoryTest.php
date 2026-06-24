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

    public function testDrinkSizeVariantIsPricedByItsOwnProductRow(): void
    {
        // R4 : la 50 cl est une LIGNE produit distincte (id 99, 240c). La borne resout
        // la taille en product_id ; le domaine commande la facture comme n'importe quel
        // produit (price_cents de SA ligne), sans logique de taille -> flux inchange.
        $db = new FakeOrderDatabase();
        $db->products[14] = ['id' => 14, 'name' => 'Coca Cola', 'price_cents' => 190, 'vat_rate' => 100, 'is_available' => 1];
        $db->products[99] = ['id' => 99, 'name' => 'Coca Cola 50cl', 'price_cents' => 240, 'vat_rate' => 100, 'is_available' => 1, 'base_product_id' => 14, 'size_cl' => 50];

        $res = $this->repo($db)->createPending([
            'service_mode' => 'takeaway',
            'items' => [['type' => 'product', 'product_id' => 99, 'quantity' => 1]],
        ]);

        // Prix = celui de la ligne 50 cl (240c), pas de la base 30 cl (190c).
        $item = $db->firstWrite('INSERT INTO order_item ');
        self::assertSame(99, $item['pid']);
        self::assertSame('Coca Cola 50cl', $item['label']);
        self::assertSame(240, $item['price']);
        self::assertSame(240, $res['total_ttc_cents']);
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

    public function testMenuMaxiSwapsSideSelectionToGrandeVariant(): void
    {
        // Au format maxi, l'accompagnement Moyenne Frite (variante = Grande Frite,
        // id 24) doit etre persiste comme Grande Frite : la selection stocke l'id +
        // le label de la variante, pour que le decrement de stock frappe la Grande.
        $db = new FakeOrderDatabase();
        $db->menus[5] = ['id' => 5, 'burger_product_id' => 12, 'name' => 'Menu', 'price_normal_cents' => 990, 'price_maxi_cents' => 1200, 'is_available' => 1];
        $db->products[12] = ['id' => 12, 'name' => 'Burger', 'price_cents' => 600, 'vat_rate' => 100, 'is_available' => 1];
        $db->products[23] = ['id' => 23, 'name' => 'Moyenne Frite', 'price_cents' => 275, 'vat_rate' => 100, 'is_available' => 1, 'maxi_variant_product_id' => 24];
        $db->products[24] = ['id' => 24, 'name' => 'Grande Frite', 'price_cents' => 350, 'vat_rate' => 100, 'is_available' => 1, 'maxi_variant_product_id' => null];
        $db->slotRows[5] = [['id' => 8, 'name' => 'Accompagnement', 'slot_type' => 'side', 'is_required' => 1, 'display_order' => 2, 'product_id' => 23]];

        $this->repo($db)->createPending([
            'service_mode' => 'takeaway',
            'items' => [['type' => 'menu', 'menu_id' => 5, 'quantity' => 1, 'format' => 'maxi',
                'selections' => [['menu_slot_id' => 8, 'product_id' => 23]]]], // borne envoie la Moyenne
        ]);

        $sel = $db->firstWrite('INSERT INTO order_item_selection');
        self::assertSame(24, $sel['pid']); // swap -> Grande Frite
        self::assertSame('Grande Frite', $sel['label']);
        self::assertSame(8, $sel['slot']);
    }

    public function testMenuMaxiSwapsDrinkSelectionToLargeVariant(): void
    {
        // Au format maxi, la boisson fontaine Coca Cola (variante = Coca Cola 50cl,
        // id 15) doit etre persistee comme la 50 cl : meme mecanique que l'accompagnement
        // Grande Frite (maxi_variant_product_id), pour que le stock decremente la 50 cl
        // et que le snapshot reflete "Coca Cola 50cl". Aucune garde sur le slot_type.
        $db = new FakeOrderDatabase();
        $db->menus[5] = ['id' => 5, 'burger_product_id' => 12, 'name' => 'Menu', 'price_normal_cents' => 990, 'price_maxi_cents' => 1200, 'is_available' => 1];
        $db->products[12] = ['id' => 12, 'name' => 'Burger', 'price_cents' => 600, 'vat_rate' => 100, 'is_available' => 1];
        $db->products[14] = ['id' => 14, 'name' => 'Coca Cola', 'price_cents' => 190, 'vat_rate' => 100, 'is_available' => 1, 'maxi_variant_product_id' => 15];
        $db->products[15] = ['id' => 15, 'name' => 'Coca Cola 50cl', 'price_cents' => 240, 'vat_rate' => 100, 'is_available' => 1, 'maxi_variant_product_id' => null];
        $db->slotRows[5] = [['id' => 9, 'name' => 'Boisson', 'slot_type' => 'drink', 'is_required' => 1, 'display_order' => 1, 'product_id' => 14]];

        $this->repo($db)->createPending([
            'service_mode' => 'takeaway',
            'items' => [['type' => 'menu', 'menu_id' => 5, 'quantity' => 1, 'format' => 'maxi',
                'selections' => [['menu_slot_id' => 9, 'product_id' => 14]]]], // borne envoie la 30 cl
        ]);

        $sel = $db->firstWrite('INSERT INTO order_item_selection');
        self::assertSame(15, $sel['pid']); // swap -> Coca Cola 50cl
        self::assertSame('Coca Cola 50cl', $sel['label']);
        self::assertSame(9, $sel['slot']);
    }

    public function testMenuMaxiKeepsBottledDrinkWithoutVariant(): void
    {
        // Une boisson en bouteille (Eau) n'a pas de variante 50 cl : meme en Maxi la
        // selection reste l'Eau de base (degradation gracieuse, modele fast-food).
        $db = new FakeOrderDatabase();
        $db->menus[5] = ['id' => 5, 'burger_product_id' => 12, 'name' => 'Menu', 'price_normal_cents' => 990, 'price_maxi_cents' => 1200, 'is_available' => 1];
        $db->products[12] = ['id' => 12, 'name' => 'Burger', 'price_cents' => 600, 'vat_rate' => 100, 'is_available' => 1];
        $db->products[16] = ['id' => 16, 'name' => 'Eau', 'price_cents' => 150, 'vat_rate' => 100, 'is_available' => 1, 'maxi_variant_product_id' => null];
        $db->slotRows[5] = [['id' => 9, 'name' => 'Boisson', 'slot_type' => 'drink', 'is_required' => 1, 'display_order' => 1, 'product_id' => 16]];

        $this->repo($db)->createPending([
            'service_mode' => 'takeaway',
            'items' => [['type' => 'menu', 'menu_id' => 5, 'quantity' => 1, 'format' => 'maxi',
                'selections' => [['menu_slot_id' => 9, 'product_id' => 16]]]],
        ]);

        $sel = $db->firstWrite('INSERT INTO order_item_selection');
        self::assertSame(16, $sel['pid']); // pas de variante -> reste l'Eau
        self::assertSame('Eau', $sel['label']);
    }

    public function testProductInStockRuptureRejectedAtOrderCreation(): void
    {
        // RG-T21 : un produit liste (is_available=1) mais en rupture calculee par le
        // stock est REFUSE a la creation de commande (garde serveur load-bearing, pas
        // seulement grise sur la borne). Couvre le bypass URL directe / repli sans-JS.
        $db = new FakeOrderDatabase();
        $db->products[12] = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'is_available' => 1];
        $db->autoUnavailableRows = [['product_id' => 12]];

        $this->expectException(OrderValidationException::class);
        $this->expectExceptionMessage('PRODUCT_UNAVAILABLE');
        $this->repo($db)->createPending([
            'service_mode' => 'takeaway',
            'items' => [['type' => 'product', 'product_id' => 12, 'quantity' => 1]],
        ]);
    }

    public function testMenuRejectedAtOrderWhenBurgerInStockRupture(): void
    {
        // RG-T21 (granularite burger seul) : le burger impose en rupture calculee rend
        // le menu non commandable cote serveur, meme is_available=1.
        $db = new FakeOrderDatabase();
        $db->menus[5] = ['id' => 5, 'burger_product_id' => 12, 'name' => 'Menu', 'price_normal_cents' => 990, 'price_maxi_cents' => 1200, 'is_available' => 1];
        $db->products[12] = ['id' => 12, 'name' => 'Burger', 'price_cents' => 600, 'vat_rate' => 100, 'is_available' => 1];
        $db->autoUnavailableRows = [['product_id' => 12]];

        $this->expectException(OrderValidationException::class);
        $this->expectExceptionMessage('MENU_UNAVAILABLE');
        $this->repo($db)->createPending([
            'service_mode' => 'takeaway',
            'items' => [['type' => 'menu', 'menu_id' => 5, 'quantity' => 1, 'format' => 'normal', 'selections' => []]],
        ]);
    }

    public function testMenuNormalKeepsBaseSideSelection(): void
    {
        // Format normal : aucune substitution, l'accompagnement reste la Moyenne
        // Frite meme si une variante Maxi est definie sur le produit.
        $db = new FakeOrderDatabase();
        $db->menus[5] = ['id' => 5, 'burger_product_id' => 12, 'name' => 'Menu', 'price_normal_cents' => 990, 'price_maxi_cents' => 1200, 'is_available' => 1];
        $db->products[12] = ['id' => 12, 'name' => 'Burger', 'price_cents' => 600, 'vat_rate' => 100, 'is_available' => 1];
        $db->products[23] = ['id' => 23, 'name' => 'Moyenne Frite', 'price_cents' => 275, 'vat_rate' => 100, 'is_available' => 1, 'maxi_variant_product_id' => 24];
        $db->products[24] = ['id' => 24, 'name' => 'Grande Frite', 'price_cents' => 350, 'vat_rate' => 100, 'is_available' => 1, 'maxi_variant_product_id' => null];
        $db->slotRows[5] = [['id' => 8, 'name' => 'Accompagnement', 'slot_type' => 'side', 'is_required' => 1, 'display_order' => 2, 'product_id' => 23]];

        $this->repo($db)->createPending([
            'service_mode' => 'takeaway',
            'items' => [['type' => 'menu', 'menu_id' => 5, 'quantity' => 1, 'format' => 'normal',
                'selections' => [['menu_slot_id' => 8, 'product_id' => 23]]]],
        ]);

        $sel = $db->firstWrite('INSERT INTO order_item_selection');
        self::assertSame(23, $sel['pid']); // pas de swap -> Moyenne Frite
        self::assertSame('Moyenne Frite', $sel['label']);
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

    // --- createStaffOrder() : comptoir/drive, source tagguee + encaissement immediat (mlt 4.1) ---

    public function testStaffOrderCounterTagsSourcePrefixesCAndPaysImmediately(): void
    {
        $db = new FakeOrderDatabase();
        $db->products[12] = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'is_available' => 1];
        $db->compositions[12] = [['ingredient_id' => 5, 'quantity_normal' => 1, 'quantity_maxi' => 1]];
        // persist() insere en pending puis pay() relit la commande par numero + ses
        // lignes : on simule la ligne persistee (id 100 -> 'C100', pending) et son
        // order_item, pour que le decrement de stock de pay() s'execute (RG-T20).
        $db->orderByNumber = ['id' => 100, 'order_number' => 'C100', 'total_ttc_cents' => 890, 'status' => 'pending_payment'];
        $db->orderItems = [['id' => 1, 'item_type' => 'product', 'product_id' => 12, 'menu_id' => null, 'format' => 'normal', 'quantity' => 1]];

        $res = $this->repo($db)->createStaffOrder([
            'service_mode' => 'dine_in',
            'items' => [['type' => 'product', 'product_id' => 12, 'quantity' => 1]],
        ], 7, 'counter');

        // POST-1 : source counter, prefixe 'C' + id, acting_user_id pose, status paid.
        $order = $db->firstWrite('INSERT INTO customer_order');
        self::assertSame('counter', $order['source']);
        self::assertSame(7, $order['acting']);
        $renumber = $db->firstWrite('UPDATE customer_order SET order_number');
        self::assertSame('C100', $renumber['num']);
        self::assertSame('paid', $res['status']);
        self::assertSame('C100', $res['order_number']);

        // POST-3 : stock decremente avec user_id = equipier (RG-4/RG-T20).
        $move = $db->firstWrite('INSERT INTO stock_movement');
        self::assertSame(-1, $move['delta']);
        self::assertSame(7, $move['uid']);
        // L'acteur est aussi pose a la transition paid (acting_user_id, COALESCE).
        $transition = $db->firstWrite('UPDATE customer_order SET status');
        self::assertSame(7, $transition['uid']);
    }

    public function testStaffOrderDrivePrefixesD(): void
    {
        $db = new FakeOrderDatabase();
        $db->products[12] = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'is_available' => 1];
        $db->orderByNumber = ['id' => 100, 'order_number' => 'D100', 'total_ttc_cents' => 890, 'status' => 'pending_payment'];

        $res = $this->repo($db)->createStaffOrder([
            'service_mode' => 'drive', // RG-T09 : drive impose drive
            'items' => [['type' => 'product', 'product_id' => 12, 'quantity' => 1]],
        ], 7, 'drive');

        $order = $db->firstWrite('INSERT INTO customer_order');
        self::assertSame('drive', $order['source']);
        self::assertSame('D100', $db->firstWrite('UPDATE customer_order SET order_number')['num']);
        self::assertSame('paid', $res['status']);
    }

    public function testStaffOrderDriveRejectsNonDriveServiceMode(): void
    {
        // RG-T09 / ERR-2 : source drive mais service_mode != drive -> INVALID_SERVICE_MODE.
        $db = new FakeOrderDatabase();
        $db->products[12] = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'is_available' => 1];

        try {
            $this->repo($db)->createStaffOrder([
                'service_mode' => 'takeaway',
                'items' => [['type' => 'product', 'product_id' => 12, 'quantity' => 1]],
            ], 7, 'drive');
            self::fail('expected OrderValidationException');
        } catch (OrderValidationException $exception) {
            self::assertSame('INVALID_SERVICE_MODE', $exception->getMessage());
        }

        // Verifie AVANT l'INSERT : aucune commande n'est creee.
        self::assertSame(0, $db->countWrites('INSERT INTO customer_order'));
    }

    public function testStaffOrderRejectsUnknownSource(): void
    {
        $db = new FakeOrderDatabase();

        $this->expectException(OrderValidationException::class);
        $this->expectExceptionMessage('INVALID_SOURCE');
        $this->repo($db)->createStaffOrder([
            'service_mode' => 'dine_in',
            'items' => [['type' => 'product', 'product_id' => 12, 'quantity' => 1]],
        ], 7, 'kiosk');
    }

    public function testStaffOrderRejectsEmptyCart(): void
    {
        $db = new FakeOrderDatabase();

        $this->expectException(OrderValidationException::class);
        $this->expectExceptionMessage('EMPTY_ORDER');
        $this->repo($db)->createStaffOrder([
            'service_mode' => 'dine_in',
            'items' => [],
        ], 7, 'counter');
    }

    public function testKioskCreatePendingStaysUnchanged(): void
    {
        // Garde-fou de non-regression : le flux kiosk reste source 'kiosk', prefixe 'K',
        // acting_user_id NULL, status pending_payment (createStaffOrder ne l'a pas altere).
        $db = new FakeOrderDatabase();
        $db->products[12] = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'is_available' => 1];

        $res = $this->repo($db)->createPending([
            'service_mode' => 'takeaway',
            'items' => [['type' => 'product', 'product_id' => 12, 'quantity' => 1]],
        ]);

        $order = $db->firstWrite('INSERT INTO customer_order');
        self::assertSame('kiosk', $order['source']);
        self::assertNull($order['acting']);
        self::assertSame('K100', $res['order_number']);
        self::assertSame('pending_payment', $res['status']);
        // Pas d'encaissement automatique pour le kiosk : aucune transition paid.
        self::assertSame(0, $db->countWrites('UPDATE customer_order SET status'));
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

    // --- cancel() : transition gardee + re-credit conditionnel + audit (RG-T07/T11/T14) ---

    public function testCancelPendingTransitionsWithoutRecredit(): void
    {
        // pending_payment n'avait jamais decremente le stock (le decrement est pose a
        // `paid`) : annulation = transition + audit, AUCUN re-credit (RG-3).
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'pending_payment'];
        $db->orderItems = [['id' => 1, 'item_type' => 'product', 'product_id' => 12, 'menu_id' => null, 'format' => 'normal', 'quantity' => 2]];
        $db->compositions[12] = [['ingredient_id' => 5, 'quantity_normal' => 1, 'quantity_maxi' => 1]];

        $res = $this->repo($db)->cancel('K100', 9, 4);

        self::assertSame('cancelled', $res['status']);
        self::assertSame('K100', $res['order_number']);
        self::assertSame(1, $db->countWrites('UPDATE customer_order SET status'));
        // Aucun mouvement de stock (re-credit) : la commande n'etait pas payee.
        self::assertSame(0, $db->countWrites('UPDATE ingredient SET stock_quantity'));
        self::assertSame(0, $db->countWrites('INSERT INTO stock_movement'));
        // Trace d'audit ecrite avec l'acteur resolu par PIN.
        self::assertSame(1, $db->countWrites('INSERT INTO audit_log'));
        $audit = $db->firstWrite('INSERT INTO audit_log');
        self::assertSame('order.cancel', $audit['code']);
        self::assertSame('customer_order', $audit['etype']);
        self::assertSame(100, $audit['eid']);
        self::assertSame(9, $audit['uid']);
        self::assertSame(4, $audit['rid']);
    }

    public function testCancelPaidRecreditsStockAndWritesMovementAndAudit(): void
    {
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'paid'];
        $db->saleMovementsExist = true; // payee -> mouvements 'sale' poses -> re-credit attendu
        $db->orderItems = [['id' => 1, 'item_type' => 'product', 'product_id' => 12, 'menu_id' => null, 'format' => 'normal', 'quantity' => 2]];
        $db->compositions[12] = [['ingredient_id' => 5, 'quantity_normal' => 1, 'quantity_maxi' => 1]];

        $res = $this->repo($db)->cancel('K100', 9, 4);

        self::assertSame('cancelled', $res['status']);
        self::assertSame(1, $db->countWrites('UPDATE customer_order SET status'));
        // 2 unites consommees (qn 1 * quantite 2) -> re-credit +2 sur l'ingredient 5.
        $inc = $db->firstWrite('UPDATE ingredient SET stock_quantity');
        self::assertSame(2, $inc['u']);
        self::assertSame(5, $inc['id']);
        // Type 'cancellation' code en dur dans le SQL (cf. pay() qui code 'sale').
        self::assertStringContainsString("'cancellation'", $db->firstWriteSql('INSERT INTO stock_movement'));
        $move = $db->firstWrite('INSERT INTO stock_movement');
        self::assertSame(2, $move['delta']);        // delta POSITIF (re-credit)
        self::assertSame(100, $move['oid']);
        self::assertSame(9, $move['uid']);          // acteur resolu par PIN
        // Audit ecrit avec le montant re-credite (pre-status paid).
        self::assertSame(1, $db->countWrites('INSERT INTO audit_log'));
        $audit = $db->firstWrite('INSERT INTO audit_log');
        self::assertSame('order.cancel', $audit['code']);
        self::assertStringContainsString('paid', (string) $audit['summary']);
        self::assertStringContainsString('890c', (string) $audit['summary']);
    }

    public function testCancelRecreditsWhenSaleMovementsExistEvenIfPreStatusPending(): void
    {
        // Anti-course pending_payment -> paid -> cancel : la commande lue en pending a
        // en realite ete payee (mouvements 'sale' poses par un pay() concurrent). Le
        // re-credit se decide sur l'existence des mouvements 'sale', pas sur le
        // pre-status -> il a bien lieu (pas de derive de stock silencieuse).
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'pending_payment'];
        $db->saleMovementsExist = true;
        $db->orderItems = [['id' => 1, 'item_type' => 'product', 'product_id' => 12, 'menu_id' => null, 'format' => 'normal', 'quantity' => 2]];
        $db->compositions[12] = [['ingredient_id' => 5, 'quantity_normal' => 1, 'quantity_maxi' => 1]];

        $this->repo($db)->cancel('K100', 9, 4);

        self::assertSame(2, $db->firstWrite('UPDATE ingredient SET stock_quantity')['u']);
        self::assertStringContainsString("'cancellation'", $db->firstWriteSql('INSERT INTO stock_movement'));
    }

    public function testCancelRejectsUnknownOrder(): void
    {
        $db = new FakeOrderDatabase();
        $db->orderByNumber = null;

        $this->expectException(OrderValidationException::class);
        $this->expectExceptionMessage('ORDER_NOT_FOUND');
        $this->repo($db)->cancel('K404', 9, 4);
    }

    public function testCancelRejectsTerminalStatus(): void
    {
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'delivered'];

        $this->expectException(OrderValidationException::class);
        $this->expectExceptionMessage('CANNOT_CANCEL_IN_STATE');
        $this->repo($db)->cancel('K100', 9, 4);
    }

    public function testCancelAlreadyCancelledRejected(): void
    {
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'cancelled'];

        $this->expectException(OrderValidationException::class);
        $this->expectExceptionMessage('CANNOT_CANCEL_IN_STATE');
        $this->repo($db)->cancel('K100', 9, 4);
    }

    public function testCancelConcurrentRaceThrowsInvalidTransition(): void
    {
        // La garde RG-T07 (status IN (...)) n'affecte 0 ligne : un autre appel a deja
        // transite vers un statut terminal -> INVALID_TRANSITION, aucun re-credit.
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'paid'];
        $db->payUpdateAffected = 0;
        $db->orderItems = [['id' => 1, 'item_type' => 'product', 'product_id' => 12, 'menu_id' => null, 'format' => 'normal', 'quantity' => 2]];
        $db->compositions[12] = [['ingredient_id' => 5, 'quantity_normal' => 1, 'quantity_maxi' => 1]];

        try {
            $this->repo($db)->cancel('K100', 9, 4);
            self::fail('expected OrderValidationException');
        } catch (OrderValidationException $exception) {
            self::assertSame('INVALID_TRANSITION', $exception->getMessage());
        }

        self::assertSame(0, $db->countWrites('UPDATE ingredient SET stock_quantity'));
        self::assertSame(0, $db->countWrites('INSERT INTO stock_movement'));
        self::assertSame(0, $db->countWrites('INSERT INTO audit_log'));
    }
}
