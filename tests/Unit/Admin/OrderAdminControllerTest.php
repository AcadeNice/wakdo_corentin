<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\SessionManager;
use App\Controllers\OrderAdminController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\Request;
use App\Order\OrderQueryRepository;
use App\Tests\Support\FakeDatabase;

/**
 * Stub d'OrderQueryRepository : liste canned (rendu de la table teste sans base ;
 * les requetes sont couvertes par OrderQueryRepositoryDbTest).
 */
final class StubRecentOrders extends OrderQueryRepository
{
    public function recent(int $limit = 50): array
    {
        return [
            ['order_number' => 'K42', 'source' => 'counter', 'service_mode' => 'dine_in', 'service_tag' => '261', 'status' => 'paid', 'total_ttc_cents' => 1990, 'created_at' => '2026-06-19 12:00:00', 'paid_at' => '2026-06-19 12:01:00'],
            ['order_number' => 'K43', 'source' => 'counter', 'service_mode' => 'takeaway', 'service_tag' => null, 'status' => 'pending_payment', 'total_ttc_cents' => 800, 'created_at' => '2026-06-19 12:05:00', 'paid_at' => null],
            // E15 (audit schemas 6.3) : le domaine (OrderRepository::cancel) accepte
            // aussi les etats de cuisine ; le lien Annuler doit suivre, pas seulement
            // pending_payment/paid.
            ['order_number' => 'K44', 'source' => 'kiosk', 'service_mode' => 'dine_in', 'service_tag' => '10', 'status' => 'preparing', 'total_ttc_cents' => 700, 'created_at' => '2026-06-19 12:06:00', 'paid_at' => '2026-06-19 12:06:01'],
            // RG-T12 : source 'drive', utilisee par testIndexFiltersOrdersByRoleVisibleSource
            // pour verifier qu'un role dont role_visible_source exclut le drive ne
            // voit pas cette ligne, alors que recent() (non filtre) la ramene.
            ['order_number' => 'K45', 'source' => 'drive', 'service_mode' => 'dine_in', 'service_tag' => '11', 'status' => 'ready', 'total_ttc_cents' => 600, 'created_at' => '2026-06-19 12:07:00', 'paid_at' => '2026-06-19 12:07:01'],
            ['order_number' => 'K46', 'source' => 'counter', 'service_mode' => 'dine_in', 'service_tag' => '12', 'status' => 'delivered', 'total_ttc_cents' => 500, 'created_at' => '2026-06-19 12:08:00', 'paid_at' => '2026-06-19 12:08:01'],
            ['order_number' => 'K47', 'source' => 'counter', 'service_mode' => 'dine_in', 'service_tag' => '13', 'status' => 'cancelled', 'total_ttc_cents' => 400, 'created_at' => '2026-06-19 12:09:00', 'paid_at' => null],
        ];
    }
}

final class TestOrderAdminController extends OrderAdminController
{
    public function __construct(
        Request $request,
        Config $config,
        Database $database,
        private readonly SessionManager $testSession,
        private readonly FakeDatabase $fakeDb,
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
        return new StubRecentOrders($this->fakeDb);
    }
}

final class OrderAdminControllerTest extends TestCase
{
    /** @var list<string> */
    private array $touchedKeys = [];
    private SessionManager $session;
    private string $csrf = '';

    protected function setUp(): void
    {
        $this->setEnv('SESSION_LIFETIME_IDLE', '14400');
        $this->setEnv('SESSION_LIFETIME_ABSOLUTE', '36000');
        $this->setEnv('STAFF_PIN_MIN_LENGTH', '4');
        $this->setEnv('STAFF_PIN_MAX_LENGTH', '12');
        $this->setEnv('ARGON2_MEMORY_COST', '1024');
        $this->setEnv('ARGON2_TIME_COST', '1');
        $this->setEnv('ARGON2_THREADS', '1');
        $this->session = new SessionManager(new Config(), true);
        $now = time();
        $this->session->set('user_id', 1);
        $this->session->set('role_id', 2);
        $this->session->set('logged_in_at', $now - 100);
        $this->session->set('last_activity', $now - 50);
        $this->csrf = Csrf::token($this->session);
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
        $db->userDisplayRow = ['first_name' => 'Manon', 'last_name' => 'G', 'role_label' => 'Manager'];
        $db->canResult = true;
        $db->permissionCodes = ['order.read'];

        return $db;
    }

    private function controller(FakeDatabase $db): TestOrderAdminController
    {
        $request = new Request('GET', '/admin/orders', [], [], '', '203.0.113.5');

        return new TestOrderAdminController($request, new Config(), new Database(new Config()), $this->session, $db);
    }

    private function controllerWith(Request $request, FakeDatabase $db): TestOrderAdminController
    {
        return new TestOrderAdminController($request, new Config(), new Database(new Config()), $this->session, $db);
    }

    /**
     * @param array<string, string> $form
     */
    private function post(array $form, string $path): Request
    {
        return new Request('POST', $path, [], ['content-type' => 'application/x-www-form-urlencoded'], http_build_query($form), '203.0.113.5');
    }

    private function cancelDb(): FakeDatabase
    {
        $db = $this->permittedDb();
        $db->permissionCodes = ['order.read', 'order.cancel'];
        $db->orderByNumberRow = ['id' => 100, 'order_number' => 'K42', 'total_ttc_cents' => 1990, 'status' => 'paid'];
        // PRE-3 (RG-T12) : source visible par defaut (role_visible_source vide en
        // base = vue globale, cf. $db->roleSources par defaut = []) ; les tests de
        // non-visibilite ecrasent orderSourceRow/roleSources explicitement.
        $db->orderSourceRow = ['source' => 'counter'];

        return $db;
    }

    private function actingPin(FakeDatabase $db): void
    {
        $db->actingUserRow = ['id' => 9, 'role_id' => 4, 'pin_hash' => (new PasswordHasher(new Config()))->hash('4729')];
    }

    public function testRequiresOrderRead(): void
    {
        $db = $this->permittedDb();
        $db->canResult = false;

        self::assertSame(403, $this->controller($db)->index()->status());
    }

    public function testRendersOrdersList(): void
    {
        $response = $this->controller($this->permittedDb())->index();

        self::assertSame(200, $response->status());
        $body = $response->body();
        self::assertStringContainsString('Commandes', $body);
        self::assertStringContainsString('K42', $body);
        self::assertStringContainsString('Sur place', $body);   // dine_in -> libelle
        self::assertStringContainsString('261', $body);         // chevalet
        self::assertStringContainsString('19,90 EUR', $body);   // total 1990c formate
        self::assertStringContainsString('Payée', $body);       // statut paid
        self::assertStringContainsString('À emporter', $body);  // takeaway -> libelle
        // Regression F40 : date au format brut MySQL affichee telle quelle.
        self::assertStringContainsString('19/06/2026 12:00', $body);
        self::assertStringNotContainsString('2026-06-19 12:00:00', $body);
    }

    public function testIndexFiltersOrdersByRoleVisibleSource(): void
    {
        // RG-T12 : un role dont role_visible_source restreint le canal (ici kiosk +
        // counter, comme l'ecran cuisine) ne voit PAS ici les commandes du drive
        // (K45), alors que recent() les ramene toutes sans filtre -- avant ce
        // correctif, /admin/orders ignorait role_visible_source alors que le KDS et
        // la file comptoir/drive l'appliquent deja.
        $db = $this->permittedDb();
        $db->roleSources = [['source' => 'kiosk'], ['source' => 'counter']];

        $body = $this->controller($db)->index()->body();

        self::assertStringContainsString('K42', $body); // counter, visible
        self::assertStringContainsString('K44', $body); // kiosk, visible
        self::assertStringNotContainsString('K45', $body); // drive, hors sources visibles
    }

    public function testIndexShowsAllSourcesForGlobalViewRole(): void
    {
        // Contre-exemple : role_visible_source vide (admin/manager) = vue globale,
        // toutes les sources restent visibles (comportement par defaut inchange).
        $db = $this->permittedDb();
        $db->roleSources = [];

        $body = $this->controller($db)->index()->body();

        self::assertStringContainsString('K42', $body);
        self::assertStringContainsString('K45', $body);
    }

    public function testCancelLinkFollowsTheServerAcceptedStatusSet(): void
    {
        // E15 (audit schemas 6.3) : le lien Annuler doit suivre EXACTEMENT l'ensemble
        // accepte par le serveur (OrderRepository::cancel : pending_payment, paid,
        // preparing, ready), pas seulement pending_payment/paid comme le disait
        // l'ancien commentaire de ce fichier.
        $db = $this->permittedDb();
        $db->permissionCodes = ['order.read', 'order.cancel'];

        $body = $this->controller($db)->index()->body();

        self::assertStringContainsString('/admin/orders/K42/cancel', $body); // paid
        self::assertStringContainsString('/admin/orders/K43/cancel', $body); // pending_payment
        self::assertStringContainsString('/admin/orders/K44/cancel', $body); // preparing
        self::assertStringContainsString('/admin/orders/K45/cancel', $body); // ready
        self::assertStringNotContainsString('/admin/orders/K46/cancel', $body); // delivered
        self::assertStringNotContainsString('/admin/orders/K47/cancel', $body); // cancelled
    }

    public function testDeliverRequiresOrderDeliverPermission(): void
    {
        $db = $this->permittedDb();
        $db->canResult = false; // pas de order.deliver -> 403 avant toute action

        self::assertSame(403, $this->controller($db)->deliver(['number' => 'K42'])->status());
    }

    public function testDeliverRejectsInvalidCsrf(): void
    {
        // order.deliver accorde (canResult=true) mais aucun jeton CSRF dans la requete
        // -> la garde CSRF refuse (403) avant toute transition.
        $response = $this->controller($this->permittedDb())->deliver(['number' => 'K42']);

        self::assertSame(403, $response->status());
    }

    // --- DELIVER_ORDER (6.1, garde de visibilite PRE-3 / ERR-2) ---

    private function deliverDb(): FakeDatabase
    {
        // order.deliver accorde + permission CSRF franchie en aval ; la source de la
        // commande est scriptee par chaque test via orderSourceRow.
        $db = $this->permittedDb();
        $db->permissionCodes = ['order.read', 'order.deliver'];

        return $db;
    }

    public function testDeliverRejectsSourceNotVisibleByRole(): void
    {
        // ERR-2 (6.1) : le role agissant ne voit que 'drive' (role_visible_source),
        // mais la commande est de source 'counter' -> 403 FORBIDDEN, aucune transition.
        $db = $this->deliverDb();
        $db->roleSources = [['source' => 'drive']];
        $db->orderSourceRow = ['source' => 'counter'];

        $request = $this->post(['_csrf' => $this->csrf], '/admin/orders/C42/deliver');
        $response = $this->controllerWith($request, $db)->deliver(['number' => 'C42']);

        self::assertSame(403, $response->status());
        self::assertFalse($db->wrote('UPDATE customer_order SET status'));
    }

    public function testDeliverUnknownOrderIsRejectedAsNotVisible(): void
    {
        // Numero inconnu -> source null -> chemin "non visible" (403), pas de transition.
        $db = $this->deliverDb();
        $db->roleSources = [];          // vue globale
        $db->orderSourceRow = null;     // numero inconnu

        $request = $this->post(['_csrf' => $this->csrf], '/admin/orders/K99/deliver');
        $response = $this->controllerWith($request, $db)->deliver(['number' => 'K99']);

        self::assertSame(403, $response->status());
        self::assertFalse($db->wrote('UPDATE customer_order SET status'));
    }

    public function testDeliverSucceedsWhenSourceVisibleToRole(): void
    {
        // Source visible (le role voit kiosk+counter ; commande 'counter') -> la
        // transition paid -> delivered est tentee et la liste est re-affichee (302).
        $db = $this->deliverDb();
        $db->roleSources = [['source' => 'kiosk'], ['source' => 'counter']];
        $db->orderSourceRow = ['source' => 'counter'];
        // OrderRepository::deliver lit la commande (paid) via findByNumber/SELECT statut.
        $db->orderByNumberRow = ['id' => 100, 'order_number' => 'C42', 'total_ttc_cents' => 1990, 'status' => 'paid'];

        $request = $this->post(['_csrf' => $this->csrf], '/admin/orders/C42/deliver');
        $response = $this->controllerWith($request, $db)->deliver(['number' => 'C42']);

        self::assertSame(302, $response->status());
        self::assertSame('/admin/orders', $response->header('Location'));
        self::assertTrue($db->wrote('UPDATE customer_order SET status'));
    }

    public function testDeliverGlobalViewRoleSeesAllSources(): void
    {
        // role_visible_source vide -> vue globale (admin/manager) : une commande de
        // n'importe quel canal est remisable. visibleSources renvoie les trois sources.
        $db = $this->deliverDb();
        $db->roleSources = [];          // aucune ligne -> vue globale
        $db->orderSourceRow = ['source' => 'drive'];
        $db->orderByNumberRow = ['id' => 101, 'order_number' => 'D7', 'total_ttc_cents' => 1500, 'status' => 'paid'];

        $request = $this->post(['_csrf' => $this->csrf], '/admin/orders/D7/deliver');
        $response = $this->controllerWith($request, $db)->deliver(['number' => 'D7']);

        self::assertSame(302, $response->status());
        self::assertTrue($db->wrote('UPDATE customer_order SET status'));
    }

    // --- ETAT DE PREPARATION (#8 : ready, garde order.read) ---
    // Le passage en preparation est automatique au paiement (pay()) : il ne reste que le
    // geste manuel "Prete" cote KDS, donc une seule action de controleur (ready).

    private function prepDb(): FakeDatabase
    {
        $db = $this->permittedDb();
        $db->permissionCodes = ['order.read'];

        return $db;
    }

    public function testReadyRequiresOrderRead(): void
    {
        $db = $this->prepDb();
        $db->canResult = false; // pas de order.read -> 403 avant toute action

        self::assertSame(403, $this->controller($db)->ready(['number' => 'K42'])->status());
    }

    public function testReadyRejectsInvalidCsrf(): void
    {
        // order.read accorde mais aucun jeton CSRF (controller() construit un GET).
        $response = $this->controller($this->prepDb())->ready(['number' => 'K42']);
        self::assertSame(403, $response->status());
    }

    public function testReadyRejectsSourceNotVisibleByRole(): void
    {
        // Meme garde PRE-3 que deliver : role voit 'drive', commande 'counter' -> 403.
        $db = $this->prepDb();
        $db->roleSources = [['source' => 'drive']];
        $db->orderSourceRow = ['source' => 'counter'];

        $request = $this->post(['_csrf' => $this->csrf], '/admin/orders/C42/ready');
        $response = $this->controllerWith($request, $db)->ready(['number' => 'C42']);

        self::assertSame(403, $response->status());
        self::assertFalse($db->wrote('UPDATE customer_order SET status'));
    }

    public function testReadySucceedsAndRedirectsToKitchen(): void
    {
        $db = $this->prepDb();
        $db->roleSources = [];                                   // vue globale
        $db->orderSourceRow = ['source' => 'kiosk'];
        $db->orderByNumberRow = ['id' => 100, 'order_number' => 'K42', 'total_ttc_cents' => 1990, 'status' => 'preparing'];

        $request = $this->post(['_csrf' => $this->csrf], '/admin/orders/K42/ready');
        $response = $this->controllerWith($request, $db)->ready(['number' => 'K42']);

        self::assertSame(302, $response->status());
        self::assertSame('/kitchen/display', $response->header('Location')); // retour au KDS, pas /admin/orders
        self::assertTrue($db->wrote('UPDATE customer_order SET status'));
    }

    // --- CANCEL_ORDER (7.1, order.cancel + PIN equipier RG-T13) ---

    public function testCancelRequiresOrderCancelPermission(): void
    {
        $db = $this->cancelDb();
        $db->canResult = false; // pas de order.cancel -> 403 avant toute action

        $request = $this->post([
            '_csrf' => $this->csrf, 'pin_email' => 'sam@wakdo.local', 'pin' => '4729',
        ], '/admin/orders/K42/cancel');

        self::assertSame(403, $this->controllerWith($request, $db)->cancel(['number' => 'K42'])->status());
    }

    public function testCancelRejectsInvalidCsrf(): void
    {
        // order.cancel accorde mais jeton CSRF absent -> 403 avant toute transition.
        $db = $this->cancelDb();
        $request = $this->post(['pin_email' => 'sam@wakdo.local', 'pin' => '4729'], '/admin/orders/K42/cancel');

        self::assertSame(403, $this->controllerWith($request, $db)->cancel(['number' => 'K42'])->status());
    }

    public function testCancelWithBadPinLogsFailedAndDoesNotCancel(): void
    {
        $db = $this->cancelDb();
        $db->actingUserRow = null; // email/PIN non resolu

        $request = $this->post([
            '_csrf' => $this->csrf, 'pin_email' => 'ghost@wakdo.local', 'pin' => '0000',
        ], '/admin/orders/K42/cancel');

        $response = $this->controllerWith($request, $db)->cancel(['number' => 'K42']);

        self::assertSame(422, $response->status());
        self::assertSame(['pin.failed'], $db->auditActions());     // trace detective (RG-T22)
        self::assertFalse($db->wrote('UPDATE customer_order SET status')); // aucune transition
    }

    public function testCancelWithValidPinTransitionsToCancelled(): void
    {
        $db = $this->cancelDb();
        $this->actingPin($db); // equipier id 9, PIN 4729

        $request = $this->post([
            '_csrf' => $this->csrf, 'pin_email' => 'sam@wakdo.local', 'pin' => '4729',
        ], '/admin/orders/K42/cancel');

        $response = $this->controllerWith($request, $db)->cancel(['number' => 'K42']);

        self::assertSame(302, $response->status());
        self::assertSame('/admin/orders', $response->header('Location'));
        self::assertTrue($db->wrote('UPDATE customer_order SET status'));
        // L'annulation est tracee avec l'acteur resolu par PIN (RG-T14).
        self::assertSame(['order.cancel'], $db->auditActions());
    }

    public function testCancelUnknownOrderReturns403NotFoundToAvoidEnumeration(): void
    {
        // PRE-3 (RG-T12, limite fermee) : un numero inconnu (source null) retombe
        // desormais sur le MEME chemin "canal non visible" (403), verifie AVANT le
        // PIN, comme OrderApiController::apiCancel() -- avant ce correctif ce cas
        // rendait 404 sans meme verifier la visibilite de canal.
        $db = $this->cancelDb();
        $db->orderByNumberRow = null; // numero inconnu
        $db->orderSourceRow = null;   // numero inconnu -> orderSource() renvoie null

        $request = $this->post([
            '_csrf' => $this->csrf, 'pin_email' => 'sam@wakdo.local', 'pin' => '4729',
        ], '/admin/orders/K99/cancel');

        $response = $this->controllerWith($request, $db)->cancel(['number' => 'K99']);

        self::assertSame(403, $response->status());
        self::assertFalse($db->wrote('UPDATE customer_order SET status'));
    }

    public function testCancelRejectsSourceNotVisibleByRole(): void
    {
        // PRE-3 (RG-T12) : le role agissant ne voit que 'drive' (role_visible_source),
        // la commande est de source 'counter' -> 403 AVANT tout PIN (aucune tentative
        // de resolution d'acteur, aucun audit pin.failed).
        $db = $this->cancelDb();
        $db->roleSources = [['source' => 'drive']];
        $db->orderSourceRow = ['source' => 'counter'];

        $request = $this->post([
            '_csrf' => $this->csrf, 'pin_email' => 'sam@wakdo.local', 'pin' => '4729',
        ], '/admin/orders/K42/cancel');

        $response = $this->controllerWith($request, $db)->cancel(['number' => 'K42']);

        self::assertSame(403, $response->status());
        self::assertFalse($db->wrote('UPDATE customer_order SET status'));
        self::assertSame([], $db->auditActions()); // pas de pin.failed : le PIN n'est jamais tente
    }

    public function testConfirmCancelRendersPinForm(): void
    {
        $db = $this->cancelDb();
        $request = new Request('GET', '/admin/orders/K42/cancel', [], [], '', '203.0.113.5');

        $response = $this->controllerWith($request, $db)->confirmCancel(['number' => 'K42']);

        self::assertSame(200, $response->status());
        $body = $response->body();
        self::assertStringContainsString('K42', $body);
        self::assertStringContainsString('PIN', $body);
    }

    public function testConfirmCancelForbiddenWhenSourceNotVisible(): void
    {
        // PRE-3 (RG-T12) : la page de confirmation (GET, lecture seule) ne doit pas
        // reveler numero/statut/total d'une commande hors des canaux visibles du
        // role -- fermer seulement le POST cancel() laisserait fuiter l'existence
        // et le statut de la commande via cette page.
        $db = $this->cancelDb();
        $db->roleSources = [['source' => 'drive']];
        $db->orderSourceRow = ['source' => 'counter'];
        $request = new Request('GET', '/admin/orders/K42/cancel', [], [], '', '203.0.113.5');

        $response = $this->controllerWith($request, $db)->confirmCancel(['number' => 'K42']);

        self::assertSame(403, $response->status());
    }

    public function testConfirmCancelUnknownOrderReturns403NotFoundToAvoidEnumeration(): void
    {
        $db = $this->cancelDb();
        $db->orderByNumberRow = null;
        $db->orderSourceRow = null;
        $request = new Request('GET', '/admin/orders/K99/cancel', [], [], '', '203.0.113.5');

        $response = $this->controllerWith($request, $db)->confirmCancel(['number' => 'K99']);

        self::assertSame(403, $response->status());
    }
}
