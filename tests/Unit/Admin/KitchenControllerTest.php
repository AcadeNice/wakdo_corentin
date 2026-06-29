<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use App\Auth\SessionManager;
use App\Controllers\KitchenController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\Request;
use App\Order\OrderQueryRepository;
use App\Tests\Support\FakeDatabase;

/**
 * Stub OrderQueryRepository : sources visibles + file enrichie canned, pour tester le
 * rendu du KDS sans base. Le SQL reel de visibleSources/paidQueueWithDetail est couvert
 * par OrderQueryRepositoryDbTest (integration) ; ici on isole le rendu de la vue :
 * detail des articles (selections + modificateurs) et bande SLA -> classe CSS. Le statut
 * canned est 'preparing' : les commandes arrivent desormais en preparation des le
 * paiement (pay()), il n'y a plus d'etat 'paid' transitoire affiche au KDS.
 */
final class StubKitchenQuery extends OrderQueryRepository
{
    public function visibleSources(int $roleId): array
    {
        return ['kiosk', 'counter', 'drive'];
    }

    public function paidQueueWithDetail(array $sources, ?int $now = null): array
    {
        return [
            [
                'order_number'    => 'K42',
                'source'          => 'kiosk',
                'service_mode'    => 'dine_in',
                'service_tag'     => '12',
                'status'          => 'preparing',
                'total_ttc_cents' => 990,
                'paid_at'         => '2026-06-19 12:01:00',
                'sla_band'        => 'warn',
                'items'           => [
                    [
                        'item_type'      => 'menu',
                        'format'         => 'maxi',
                        'label_snapshot' => 'Menu Le 280',
                        'quantity'       => 1,
                        'selections'     => [
                            ['label_snapshot' => 'Coca 50cl'],
                            ['label_snapshot' => 'Grande Frite'],
                        ],
                        'modifiers'      => [
                            ['ingredient_name' => 'oignon', 'action' => 'remove'],
                            ['ingredient_name' => 'bacon', 'action' => 'add'],
                        ],
                    ],
                ],
            ],
        ];
    }
}

/**
 * Variante : une commande deja 'preparing' (pour le bouton "Prete" -> ready).
 */
final class StubKitchenQueryPreparing extends OrderQueryRepository
{
    public function visibleSources(int $roleId): array
    {
        return ['kiosk', 'counter', 'drive'];
    }

    public function paidQueueWithDetail(array $sources, ?int $now = null): array
    {
        return [[
            'order_number'    => 'K77',
            'source'          => 'kiosk',
            'service_mode'    => 'takeaway',
            'service_tag'     => null,
            'status'          => 'preparing',
            'total_ttc_cents' => 500,
            'paid_at'         => '2026-06-19 12:01:00',
            'sla_band'        => 'fresh',
            'items'           => [],
        ]];
    }
}

final class TestKitchenController extends KitchenController
{
    public function __construct(
        Request $request,
        Config $config,
        Database $database,
        private readonly SessionManager $testSession,
        private readonly FakeDatabase $fakeDb,
        private readonly ?OrderQueryRepository $queryStub = null,
    ) {
        parent::__construct($request, $config, $database);
    }

    protected function sessionManager(): SessionManager
    {
        return $this->testSession;
    }

    protected function db(): DatabaseInterface
    {
        return $this->fakeDb;
    }

    protected function orderQuery(): OrderQueryRepository
    {
        return $this->queryStub ?? new StubKitchenQuery($this->fakeDb);
    }
}

final class KitchenControllerTest extends TestCase
{
    /** @var list<string> */
    private array $touchedKeys = [];
    private SessionManager $session;

    protected function setUp(): void
    {
        $this->setEnv('SESSION_LIFETIME_IDLE', '14400');
        $this->setEnv('SESSION_LIFETIME_ABSOLUTE', '36000');
        $this->session = new SessionManager(new Config(), true);
        $now = time();
        $this->session->set('user_id', 1);
        $this->session->set('role_id', 3); // kitchen
        $this->session->set('logged_in_at', $now - 100);
        $this->session->set('last_activity', $now - 50);
    }

    protected function tearDown(): void
    {
        foreach ($this->touchedKeys as $key) {
            putenv($key);
        }
        $this->touchedKeys = [];
    }

    private function setEnv(string $key, string $value): void
    {
        $this->touchedKeys[] = $key;
        putenv($key . '=' . $value);
    }

    private function permittedDb(): FakeDatabase
    {
        $db = new FakeDatabase();
        $db->guardUserRow = ['is_active' => 1];
        $db->userDisplayRow = ['first_name' => 'Kim', 'last_name' => 'C', 'role_label' => 'Cuisine'];
        $db->canResult = true;
        $db->permissionCodes = ['order.read'];

        return $db;
    }

    private function controller(FakeDatabase $db, ?OrderQueryRepository $queryStub = null): TestKitchenController
    {
        $request = new Request('GET', '/kitchen/display', [], [], '', '203.0.113.5');

        return new TestKitchenController($request, new Config(), new Database(new Config()), $this->session, $db, $queryStub);
    }

    public function testRequiresOrderRead(): void
    {
        $db = $this->permittedDb();
        $db->canResult = false;

        self::assertSame(403, $this->controller($db)->display()->status());
    }

    public function testRendersPaidQueue(): void
    {
        $response = $this->controller($this->permittedDb())->display();

        self::assertSame(200, $response->status());
        $body = $response->body();
        self::assertStringContainsString('Cuisine', $body);
        self::assertStringContainsString('K42', $body);
        self::assertStringContainsString('kitchen-grid', $body);
        // order.deliver accorde (canResult=true) -> bouton de remise present.
        self::assertStringContainsString('Remettre', $body);
    }

    public function testRendersItemDetailAndModifiers(): void
    {
        // Le KDS doit etre exploitable pour PREPARER : libelle, format, selections de
        // slot et modificateurs lisibles (et non plus seulement paid_at brut).
        $body = $this->controller($this->permittedDb())->display()->body();

        self::assertStringContainsString('1x Menu Le 280', $body);
        self::assertStringContainsString('(Maxi)', $body);
        self::assertStringContainsString('Coca 50cl', $body);
        self::assertStringContainsString('Grande Frite', $body);
        self::assertStringContainsString('sans oignon', $body);
        self::assertStringContainsString('+bacon', $body);
    }

    public function testAppliesSlaBandCssClass(): void
    {
        // La bande SLA (calculee serveur) est rendue en classe CSS sur la carte :
        // la file canned porte sla_band='warn'.
        $body = $this->controller($this->permittedDb())->display()->body();

        self::assertStringContainsString('kds-order--warn', $body);
    }

    public function testRendersPreparationStatusBadge(): void
    {
        // Retour oral #8 : chaque carte affiche son etat. La file canned est 'preparing'
        // (les commandes arrivent en preparation des le paiement) -> badge "En preparation".
        $body = $this->controller($this->permittedDb())->display()->body();
        self::assertStringContainsString('kitchen-status', $body);
        self::assertStringContainsString('En preparation', $body);
    }

    public function testShowsReadyButtonForPreparingOrder(): void
    {
        // Commande deja en preparation -> badge "En preparation" + bouton "Prete"
        // postant la transition ready.
        $body = $this->controller($this->permittedDb(), new StubKitchenQueryPreparing(new FakeDatabase()))->display()->body();
        self::assertStringContainsString('En preparation', $body);
        self::assertStringContainsString('Prete', $body);
        self::assertStringContainsString('/admin/orders/K77/ready', $body);
    }
}
