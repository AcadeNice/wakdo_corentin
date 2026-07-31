<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order;

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
        self::assertStringContainsString('annulee', (string) ($data['error']['message'] ?? ''));
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
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'paid'];

        $response = $this->controller($db, '', '/api/orders/K100')->show(['number' => 'K100']);

        self::assertSame(200, $response->status());
        $data = json_decode($response->body(), true);
        self::assertIsArray($data);
        self::assertSame('K100', $data['data']['order_number'] ?? null);
        self::assertSame('paid', $data['data']['status'] ?? null);
        self::assertSame(890, $data['data']['total_ttc_cents'] ?? null);
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

    public function testShowEmptyNumberReturns404(): void
    {
        $db = new FakeOrderDatabase();
        $db->orderByNumber = ['id' => 1, 'order_number' => 'K1', 'total_ttc_cents' => 100, 'status' => 'paid'];

        // Numero vide : court-circuite avant toute lecture BDD (findByNumber renvoie null).
        $response = $this->controller($db, '', '/api/orders/')->show(['number' => '']);

        self::assertSame(404, $response->status());
    }
}
