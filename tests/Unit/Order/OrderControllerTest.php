<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use App\Controllers\OrderController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\Request;
use App\Tests\Support\FakeOrderDatabase;

/**
 * Sous-classe de test : redefinit le hook db() pour injecter le double dedie, sans
 * base reelle. orders() construit alors le vrai OrderRepository sur ce double, ce
 * qui exerce le cablage complet controleur -> repository.
 */
final class TestOrderController extends OrderController
{
    public function __construct(
        Request $request,
        Config $config,
        Database $database,
        private readonly FakeOrderDatabase $fakeDb,
    ) {
        parent::__construct($request, $config, $database);
    }

    protected function db(): DatabaseInterface
    {
        return $this->fakeDb;
    }
}

final class OrderControllerTest extends TestCase
{
    private function controller(FakeOrderDatabase $db, string $body = '', string $path = '/api/orders'): TestOrderController
    {
        $request = new Request('POST', $path, [], ['content-type' => 'application/json'], $body, '203.0.113.5');

        return new TestOrderController($request, new Config(), new Database(new Config()), $db);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonBody(array $payload): string
    {
        return (string) json_encode($payload);
    }

    public function testCreateReturns201WithOrderNumber(): void
    {
        $db = new FakeOrderDatabase();
        $db->products[12] = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'is_available' => 1];

        $body = $this->jsonBody(['service_mode' => 'takeaway', 'items' => [['type' => 'product', 'product_id' => 12, 'quantity' => 1]]]);
        $response = $this->controller($db, $body)->create();

        self::assertSame(201, $response->status());
        $data = json_decode($response->body(), true);
        self::assertIsArray($data);
        self::assertSame('K100', $data['data']['order_number'] ?? null);
        self::assertSame('pending_payment', $data['data']['status'] ?? null);
        self::assertSame(890, $data['data']['total_ttc_cents'] ?? null);
    }

    public function testCreateUnknownProductReturns422(): void
    {
        $db = new FakeOrderDatabase();
        $body = $this->jsonBody(['service_mode' => 'takeaway', 'items' => [['type' => 'product', 'product_id' => 999, 'quantity' => 1]]]);

        $response = $this->controller($db, $body)->create();

        self::assertSame(422, $response->status());
        $data = json_decode($response->body(), true);
        self::assertIsArray($data);
        self::assertSame('PRODUCT_UNAVAILABLE', $data['error']['code'] ?? null);
    }

    public function testCreateInvalidServiceModeReturns422(): void
    {
        $db = new FakeOrderDatabase();
        $body = $this->jsonBody(['service_mode' => 'bogus', 'items' => [['type' => 'product', 'product_id' => 12, 'quantity' => 1]]]);

        $response = $this->controller($db, $body)->create();

        self::assertSame(422, $response->status());
        $data = json_decode($response->body(), true);
        self::assertIsArray($data);
        self::assertSame('INVALID_SERVICE_MODE', $data['error']['code'] ?? null);
    }

    public function testCreateWithTooLongIdempotencyKeyReturns422NotAServerError(): void
    {
        $db = new FakeOrderDatabase();
        $db->products[12] = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'is_available' => 1];
        $body = $this->jsonBody(['idempotency_key' => str_repeat('k', 39), 'service_mode' => 'takeaway', 'items' => [['type' => 'product', 'product_id' => 12, 'quantity' => 1]]]);

        $response = $this->controller($db, $body)->create();

        self::assertSame(422, $response->status());
        $data = json_decode($response->body(), true);
        self::assertIsArray($data);
        self::assertSame('INVALID_IDEMPOTENCY_KEY', $data['error']['code'] ?? null);
        self::assertSame('Clé d\'idempotence invalide (36 caractères au plus).', $data['error']['message'] ?? null);
    }

    public function testPayReturns200Preparing(): void
    {
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'pending_payment'];

        $response = $this->controller($db, '', '/api/orders/K100/pay')->pay(['number' => 'K100']);

        self::assertSame(200, $response->status());
        $data = json_decode($response->body(), true);
        self::assertIsArray($data);
        // Le paiement met directement en preparation (retour oral) : l'API reflete l'etat reel.
        self::assertSame('preparing', $data['data']['status'] ?? null);
        self::assertSame('K100', $data['data']['order_number'] ?? null);
    }

    public function testPayUnknownReturns404(): void
    {
        $db = new FakeOrderDatabase();
        $db->orderByNumber = null;

        $response = $this->controller($db, '', '/api/orders/K404/pay')->pay(['number' => 'K404']);

        self::assertSame(404, $response->status());
        $data = json_decode($response->body(), true);
        self::assertIsArray($data);
        self::assertSame('ORDER_NOT_FOUND', $data['error']['code'] ?? null);
    }

    public function testPayTerminalStatusReturns409(): void
    {
        // 'cancelled' est le seul statut terminal qui refuse le paiement (INVALID_TRANSITION).
        // Les etats encaisses (paid/preparing/ready/delivered) sont idempotents -> 200.
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'cancelled'];

        $response = $this->controller($db, '', '/api/orders/K100/pay')->pay(['number' => 'K100']);

        self::assertSame(409, $response->status());
        $data = json_decode($response->body(), true);
        self::assertIsArray($data);
        self::assertSame('INVALID_TRANSITION', $data['error']['code'] ?? null);
    }

    public function testCreateWithASpentKeyReturns409OrderCancelled(): void
    {
        // F18 : la cle d'idempotence du client porte une commande annulee par un equipier
        // ou expiree par le planificateur. La colonne etant UNIQUE, la cle est consommee.
        // 409 (conflit d'etat) et non 422 : la charge utile est valide, c'est l'etat de la
        // commande visee qui refuse l'operation. La borne s'en sert pour repartir d'une
        // cle neuve ; un 422 lui ferait croire a une erreur de saisie.
        $db = new FakeOrderDatabase();
        $db->products[12] = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'is_available' => 1];
        $db->existingByKey = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'cancelled'];

        $body = $this->jsonBody([
            'idempotency_key' => 'cle-morte',
            'service_mode' => 'takeaway',
            'items' => [['type' => 'product', 'product_id' => 12, 'quantity' => 1]],
        ]);
        $response = $this->controller($db, $body)->create();

        self::assertSame(409, $response->status());
        $data = json_decode($response->body(), true);
        self::assertIsArray($data);
        self::assertSame('ORDER_CANCELLED', $data['error']['code'] ?? null);
        // Le message reste cote serveur mais doit orienter le diagnostic.
        self::assertStringContainsString('annulée', (string) ($data['error']['message'] ?? ''));
    }

    public function testCreateWithAKnownPendingKeyReturnsTheSameOrderUpdated(): void
    {
        $db = new FakeOrderDatabase();
        $db->products[12] = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'is_available' => 1];
        $db->existingByKey = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 500, 'status' => 'pending_payment'];
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 500, 'status' => 'pending_payment'];

        $body = $this->jsonBody([
            'idempotency_key' => 'cle-session',
            'service_mode' => 'takeaway',
            'items' => [['type' => 'product', 'product_id' => 12, 'quantity' => 1]],
        ]);
        $response = $this->controller($db, $body)->create();

        // Meme numero, total recalcule : le client a modifie son panier, sa commande suit.
        self::assertSame(201, $response->status());
        $data = json_decode($response->body(), true);
        self::assertIsArray($data);
        self::assertSame('K100', $data['data']['order_number'] ?? null);
        self::assertSame(890, $data['data']['total_ttc_cents'] ?? null);
    }

    public function testShowReturnsOrderStatus(): void
    {
        // Relecture adverse (changes), point 5b : cet endpoint est PUBLIC ANONYME et
        // les numeros sont sequentiels -- il ne doit exposer que le statut d'une
        // commande KIOSK (aucun ecran borne ne consomme total_ttc_cents aujourd'hui,
        // verifie dans checkout.js/page-confirmation.js/confirm-modal.js), jamais
        // celui d'une commande comptoir/drive.
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'paid', 'source' => 'kiosk'];

        $response = $this->controller($db, '', '/api/orders/K100')->show(['number' => 'K100']);

        self::assertSame(200, $response->status());
        $data = json_decode($response->body(), true);
        self::assertIsArray($data);
        self::assertSame('K100', $data['data']['order_number'] ?? null);
        self::assertSame('paid', $data['data']['status'] ?? null);
        self::assertArrayNotHasKey('total_ttc_cents', $data['data']);
        self::assertArrayNotHasKey('id', $data['data']);
    }

    public function testShowUnknownReturns404(): void
    {
        $db = new FakeOrderDatabase();
        $db->orderByNumber = null;

        $response = $this->controller($db, '', '/api/orders/K404')->show(['number' => 'K404']);

        self::assertSame(404, $response->status());
        $data = json_decode($response->body(), true);
        self::assertIsArray($data);
        self::assertSame('ORDER_NOT_FOUND', $data['error']['code'] ?? null);
    }

    public function testShowNonKioskOrderReturns404SameAsUnknown(): void
    {
        // Point 5b : une commande comptoir/drive n'est pas "kiosk anonyme" --
        // findByNumber() ne filtre pas par source, et les numeros sont sequentiels
        // (K/C/D + id) : sans cette garde, n'importe qui pouvait lire le statut ET
        // le total d'une commande comptoir/drive via cet endpoint public anonyme.
        // Meme reponse (404 ORDER_NOT_FOUND) qu'un numero inconnu -- anti-enumeration.
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'C100', 'total_ttc_cents' => 890, 'status' => 'paid', 'source' => 'counter'];

        $response = $this->controller($db, '', '/api/orders/C100')->show(['number' => 'C100']);
        $unknownResponse = $this->controller(new FakeOrderDatabase(), '', '/api/orders/C999')->show(['number' => 'C999']);

        self::assertSame(404, $response->status());
        $data = json_decode($response->body(), true);
        self::assertSame('ORDER_NOT_FOUND', $data['error']['code'] ?? null);
        self::assertSame($unknownResponse->body(), $response->body());
    }

    public function testShowEmptyNumberReturns404(): void
    {
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 1, 'order_number' => 'K1', 'total_ttc_cents' => 100, 'status' => 'paid', 'source' => 'kiosk'];

        // Numero vide : court-circuite avant toute lecture BDD (findByNumber renvoie null).
        $response = $this->controller($db, '', '/api/orders/')->show(['number' => '']);

        self::assertSame(404, $response->status());
    }

    // -------------------------------------------------------------------------
    // Securite / integrite : quantite hors bornes (defaut 1, cf. tests/e2e/
    // security-order-integrity.spec.js) -- couvre le chemin REEL POST (json_decode
    // du corps brut), pas seulement OrderRepository en PHP direct.
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: int|string|float}>
     */
    public static function outOfRangeQuantities(): array
    {
        return [
            'negative'          => [-5],
            'zero'              => [0],
            'int min (32 bits)' => [-2147483648],
            'au-dessus de 20'   => [21],
            // Au-dela de SMALLINT UNSIGNED (order_item.quantity, migration 0001) :
            // avant ce correctif, l'exception PDO remontait en 500.
            'au-dela de la colonne SQL' => [70000],
        ];
    }

    #[DataProvider('outOfRangeQuantities')]
    public function testCreateWithOutOfRangeQuantityReturns422NotAServerError(int|string|float $quantity): void
    {
        $db = new FakeOrderDatabase();
        $db->products[12] = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'is_available' => 1];
        $body = $this->jsonBody(['service_mode' => 'takeaway', 'items' => [['type' => 'product', 'product_id' => 12, 'quantity' => $quantity]]]);

        $response = $this->controller($db, $body)->create();

        self::assertSame(422, $response->status());
        $data = json_decode($response->body(), true);
        self::assertIsArray($data);
        self::assertSame('INVALID_QUANTITY', $data['error']['code'] ?? null);
    }

    public function testCreateWithNonIntegerQuantityReturns422(): void
    {
        // 2.5 exemplaires d'un article n'a pas de sens : refuse plutot que tronque en
        // silence a 2 (ce qu'un cast (int) nu ferait).
        $db = new FakeOrderDatabase();
        $db->products[12] = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'is_available' => 1];
        $body = $this->jsonBody(['service_mode' => 'takeaway', 'items' => [['type' => 'product', 'product_id' => 12, 'quantity' => 2.5]]]);

        $response = $this->controller($db, $body)->create();

        self::assertSame(422, $response->status());
        $data = json_decode($response->body(), true);
        self::assertSame('INVALID_QUANTITY', $data['error']['code'] ?? null);
    }

    public function testCreateWithMaximumAllowedQuantityIsAccepted(): void
    {
        // Borne HAUTE incluse (20) : ne doit pas etre refusee comme "hors bornes".
        $db = new FakeOrderDatabase();
        $db->products[12] = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 100, 'vat_rate' => 100, 'is_available' => 1];
        $body = $this->jsonBody(['service_mode' => 'takeaway', 'items' => [['type' => 'product', 'product_id' => 12, 'quantity' => 20]]]);

        $response = $this->controller($db, $body)->create();

        self::assertSame(201, $response->status());
        $data = json_decode($response->body(), true);
        self::assertSame(2000, $data['data']['total_ttc_cents'] ?? null);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformedOrderBodies(): array
    {
        return [
            'ligne scalaire (chaine)' => [['service_mode' => 'takeaway', 'items' => ['pas-un-objet']]],
            'ligne scalaire (nombre)' => [['service_mode' => 'takeaway', 'items' => [42]]],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('malformedOrderBodies')]
    public function testCreateWithMalformedItemReturns422NotAServerError(array $payload): void
    {
        // Avant ce correctif : array_map(fn (array $item) ...) sous strict_types leve un
        // TypeError (500) des qu'une ligne n'est pas un tableau/objet JSON.
        $db = new FakeOrderDatabase();
        $body = $this->jsonBody($payload);

        $response = $this->controller($db, $body)->create();

        self::assertSame(422, $response->status());
        $data = json_decode($response->body(), true);
        self::assertIsArray($data);
        self::assertSame('INVALID_ITEM_TYPE', $data['error']['code'] ?? null);
    }

    public function testCreateWithArrayIdempotencyKeyReturns422NotArrayString(): void
    {
        // Avant ce correctif : (string) ['a','b'] devient la chaine litterale "Array"
        // (avec un avertissement PHP), une cle partagee par tous les clients qui
        // omettent ce champ correctement.
        $db = new FakeOrderDatabase();
        $db->products[12] = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'is_available' => 1];
        $body = $this->jsonBody([
            'idempotency_key' => ['a', 'b'],
            'service_mode' => 'takeaway',
            'items' => [['type' => 'product', 'product_id' => 12, 'quantity' => 1]],
        ]);

        $response = $this->controller($db, $body)->create();

        self::assertSame(422, $response->status());
        $data = json_decode($response->body(), true);
        self::assertSame('INVALID_IDEMPOTENCY_KEY', $data['error']['code'] ?? null);
    }

    public function testCreateWithMoreThanFiftyLinesReturns422(): void
    {
        $db = new FakeOrderDatabase();
        $db->products[12] = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 100, 'vat_rate' => 100, 'is_available' => 1];
        $items = array_fill(0, 51, ['type' => 'product', 'product_id' => 12, 'quantity' => 1]);
        $body = $this->jsonBody(['service_mode' => 'takeaway', 'items' => $items]);

        $response = $this->controller($db, $body)->create();

        self::assertSame(422, $response->status());
        $data = json_decode($response->body(), true);
        self::assertSame('TOO_MANY_ITEMS', $data['error']['code'] ?? null);
    }

    public function testCreateWithFiftyLinesIsAccepted(): void
    {
        // Borne HAUTE incluse (50 lignes) : ne doit pas etre refusee comme "trop de lignes".
        $db = new FakeOrderDatabase();
        $db->products[12] = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 100, 'vat_rate' => 100, 'is_available' => 1];
        $items = array_fill(0, 50, ['type' => 'product', 'product_id' => 12, 'quantity' => 1]);
        $body = $this->jsonBody(['service_mode' => 'takeaway', 'items' => $items]);

        $response = $this->controller($db, $body)->create();

        self::assertSame(201, $response->status());
    }

    public function testCreateWithTotalQuantityAboveFiftyReturns422(): void
    {
        // Chaque ligne respecte individuellement MAX_QUANTITY_PER_LINE (<=20) et le
        // nombre de lignes respecte MAX_LINES_PER_ORDER (<=50) ; seule la SOMME des
        // quantites (51) depasse le plafond global d'articles (couvre le chemin REEL
        // POST, pas seulement OrderRepository en PHP direct -- cf. security-order-
        // integrity.spec.js).
        $db = new FakeOrderDatabase();
        $db->products[12] = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 100, 'vat_rate' => 100, 'is_available' => 1];
        $items = [
            ['type' => 'product', 'product_id' => 12, 'quantity' => 20],
            ['type' => 'product', 'product_id' => 12, 'quantity' => 20],
            ['type' => 'product', 'product_id' => 12, 'quantity' => 11],
        ];
        $body = $this->jsonBody(['service_mode' => 'takeaway', 'items' => $items]);

        $response = $this->controller($db, $body)->create();

        self::assertSame(422, $response->status());
        $data = json_decode($response->body(), true);
        self::assertSame('ORDER_TOO_LARGE', $data['error']['code'] ?? null);
    }

    public function testCreateWithTotalQuantityOfExactlyFiftyIsAccepted(): void
    {
        // Borne HAUTE incluse (50 articles au total) : ne doit pas etre refusee comme
        // "commande trop volumineuse".
        $db = new FakeOrderDatabase();
        $db->products[12] = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 100, 'vat_rate' => 100, 'is_available' => 1];
        $items = [
            ['type' => 'product', 'product_id' => 12, 'quantity' => 20],
            ['type' => 'product', 'product_id' => 12, 'quantity' => 20],
            ['type' => 'product', 'product_id' => 12, 'quantity' => 10],
        ];
        $body = $this->jsonBody(['service_mode' => 'takeaway', 'items' => $items]);

        $response = $this->controller($db, $body)->create();

        self::assertSame(201, $response->status());
        $data = json_decode($response->body(), true);
        self::assertSame(5000, $data['data']['total_ttc_cents'] ?? null);
    }

    public function testCreateMenuWithUnavailableOptionReturns422(): void
    {
        // Defaut #4 : une option de menu en rupture calculee (RG-T21) reste refusee
        // meme par acces direct a l'API, pas seulement grisee sur la borne.
        $db = new FakeOrderDatabase();
        $db->menus[5] = ['id' => 5, 'burger_product_id' => 12, 'name' => 'Menu', 'price_normal_cents' => 990, 'price_maxi_cents' => 1200, 'is_available' => 1];
        $db->products[12] = ['id' => 12, 'name' => 'Burger', 'price_cents' => 600, 'vat_rate' => 100, 'is_available' => 1];
        $db->products[20] = ['id' => 20, 'name' => 'Coca', 'price_cents' => 250, 'vat_rate' => 100, 'is_available' => 1];
        $db->slotRows[5] = [['id' => 7, 'name' => 'Boisson', 'slot_type' => 'drink', 'is_required' => 1, 'display_order' => 0, 'product_id' => 20]];
        $db->autoUnavailableRows = [['product_id' => 20]];

        $body = $this->jsonBody([
            'service_mode' => 'takeaway',
            'items' => [['type' => 'menu', 'menu_id' => 5, 'quantity' => 1, 'format' => 'normal',
                'selections' => [['menu_slot_id' => 7, 'product_id' => 20]]]],
        ]);
        $response = $this->controller($db, $body)->create();

        self::assertSame(422, $response->status());
        $data = json_decode($response->body(), true);
        self::assertSame('OPTION_UNAVAILABLE', $data['error']['code'] ?? null);
    }
}
