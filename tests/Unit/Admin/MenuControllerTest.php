<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PDOException;
use PHPUnit\Framework\TestCase;
use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\SessionManager;
use App\Controllers\MenuController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\Request;
use App\Tests\Support\FakeDatabase;

/**
 * Sous-classe de test : seam db() (FakeDatabase) + sessionManager() (session test).
 */
final class TestMenuController extends MenuController
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
}

final class MenuControllerTest extends TestCase
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
        $this->session->set('role_id', 1);
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
        $db->userDisplayRow = ['first_name' => 'Corentin', 'last_name' => 'J', 'role_label' => 'Administrateur'];
        $db->canResult = true;
        $db->permissionCodes = ['menu.read', 'menu.create', 'menu.update', 'menu.delete'];
        $db->categoryRow = ['id' => 1, 'name' => 'Menus'];        // categoryExists -> true
        $db->productRow = ['id' => 1, 'name' => 'Big Mac'];        // productExists -> true (burger + options)
        return $db;
    }

    private function get(string $path): Request
    {
        return new Request('GET', $path, [], [], '', '203.0.113.5');
    }

    /**
     * @param array<string, string> $form
     */
    private function post(array $form, string $path): Request
    {
        return new Request('POST', $path, [], ['content-type' => 'application/x-www-form-urlencoded'], http_build_query($form), '203.0.113.5');
    }

    private function controller(Request $request, FakeDatabase $db): TestMenuController
    {
        return new TestMenuController($request, new Config(), new Database(new Config()), $this->session, $db);
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function validForm(array $overrides = []): array
    {
        $slots = (string) json_encode([
            ['name' => 'Boisson', 'slot_type' => 'drink', 'is_required' => 1, 'options' => [1]],
        ]);

        return array_merge([
            '_csrf' => $this->csrf,
            'category_id' => '1',
            'burger_product_id' => '1',
            'name' => 'Best Of',
            'price_normal_cents' => '790',
            'price_maxi_cents' => '990',
            'display_order' => '1',
            'is_available' => '1',
            'slots_json' => $slots,
        ], $overrides);
    }

    private function actingPin(FakeDatabase $db): void
    {
        $db->actingUserRow = ['id' => 9, 'role_id' => 4, 'pin_hash' => (new PasswordHasher(new Config()))->hash('4729')];
    }

    public function testIndexRequiresMenuRead(): void
    {
        $db = $this->permittedDb();
        $db->canResult = false;

        self::assertSame(403, $this->controller($this->get('/admin/menus'), $db)->index()->status());
    }

    public function testIndexListsMenus(): void
    {
        $db = $this->permittedDb();
        $db->menusRows = [
            ['id' => 1, 'category_id' => 1, 'burger_product_id' => 2, 'name' => 'Best Of Big Mac', 'price_normal_cents' => 790, 'price_maxi_cents' => 990, 'is_available' => 1, 'display_order' => 0, 'category_name' => 'Menus', 'burger_name' => 'Big Mac'],
        ];

        $response = $this->controller($this->get('/admin/menus'), $db)->index();
        self::assertSame(200, $response->status());
        self::assertStringContainsString('Best Of Big Mac', $response->body());
        self::assertStringContainsString('Nouveau menu', $response->body());
    }

    public function testStoreCreatesMenuWithSlots(): void
    {
        $db = $this->permittedDb();
        $response = $this->controller($this->post($this->validForm(), '/admin/menus'), $db)->store();

        self::assertSame(302, $response->status());
        self::assertTrue($db->wrote('INSERT INTO menu'));
        self::assertTrue($db->wrote('INSERT INTO menu_slot'));
        self::assertTrue($db->wrote('INSERT INTO menu_slot_option'));
        self::assertFalse($db->wrote('INSERT INTO audit_log')); // create = pas d'action sensible (mlt 8.4)
        self::assertSame('Menu cree.', $this->session->get('_flash'));
    }

    public function testStoreRejectsWithoutSlots(): void
    {
        $db = $this->permittedDb();
        // Precondition mlt 8.4 : >=1 slot avec >=1 option. Ici aucun slot.
        $response = $this->controller($this->post($this->validForm(['slots_json' => '[]']), '/admin/menus'), $db)->store();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO menu'));
    }

    public function testStoreRejectsSlotWithoutOption(): void
    {
        $db = $this->permittedDb();
        $slots = (string) json_encode([['name' => 'Boisson', 'slot_type' => 'drink', 'is_required' => 1, 'options' => []]]);
        $response = $this->controller($this->post($this->validForm(['slots_json' => $slots]), '/admin/menus'), $db)->store();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO menu'));
    }

    public function testStoreRejectsInvalidCsrf(): void
    {
        $db = $this->permittedDb();
        $response = $this->controller($this->post($this->validForm(['_csrf' => 'wrong']), '/admin/menus'), $db)->store();

        self::assertSame(403, $response->status());
        self::assertFalse($db->wrote('INSERT INTO menu'));
    }

    public function testUpdateRebuildsSlots(): void
    {
        $db = $this->permittedDb();
        $db->menuRow = ['id' => 5, 'category_id' => 1, 'burger_product_id' => 2, 'name' => 'Best Of', 'price_normal_cents' => 790, 'price_maxi_cents' => 990, 'is_available' => 1, 'display_order' => 0];

        $response = $this->controller($this->post($this->validForm(), '/admin/menus/5'), $db)->update(['id' => '5']);

        self::assertSame(302, $response->status());
        self::assertTrue($db->wrote('UPDATE menu SET'));
        // delete-and-reinsert des slots (mlt 8.5 RG-2).
        self::assertTrue($db->wrote('DELETE FROM menu_slot'));
        self::assertTrue($db->wrote('INSERT INTO menu_slot'));
    }

    public function testDestroyLockedActorReturns422WithoutDeletingOrAuditing(): void
    {
        $db = $this->permittedDb();
        $db->menuRow = ['id' => 5, 'name' => 'Best Of'];
        $this->actingPin($db);
        $db->pinThrottleLockoutUntil = '2099-01-01 00:00:00';

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'pin_email' => 'staff@wakdo.local', 'pin' => '4729'], '/admin/menus/5/delete'), $db)->destroy(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('DELETE FROM menu'));
        self::assertSame([], $db->auditActions());
    }

    public function testDestroyWrongPinRecordsFailureOnSessionActor(): void
    {
        $db = $this->permittedDb();
        $db->menuRow = ['id' => 5, 'name' => 'Best Of'];
        $db->actingUserRow = null; // email/PIN invalide

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'pin_email' => 'ghost@wakdo.local', 'pin' => '0000'], '/admin/menus/5/delete'), $db)->destroy(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertSame(['pin.failed'], $db->auditActions());
        self::assertTrue($db->wrote('INSERT INTO pin_throttle')); // RG-T22 increment sur l'agissant
        // RG-T08 : pin.failed + increment throttle dans UNE transaction.
        self::assertSame(['begin', 'commit'], $db->transactionEvents);
    }

    public function testDestroyValidPinDeletesAuditsAndResets(): void
    {
        $db = $this->permittedDb();
        $db->menuRow = ['id' => 5, 'name' => 'Best Of'];
        $this->actingPin($db);

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'pin_email' => 'staff@wakdo.local', 'pin' => '4729'], '/admin/menus/5/delete'), $db)->destroy(['id' => '5']);

        self::assertSame(302, $response->status());
        self::assertTrue($db->wrote('DELETE FROM menu'));
        self::assertSame(['menu.delete'], $db->auditActions());
        // L'audit porte l'acteur RESOLU PAR PIN (id 9), dans la transaction de l'effet.
        $audit = $this->findWrite($db, 'INSERT INTO audit_log');
        self::assertNotNull($audit);
        self::assertSame(9, $audit['params']['uid'] ?? null);
        $this->assertAuditWithinTransaction($db);
        // Reset du throttle sur l'acteur de SESSION (id 1).
        $reset = $this->findWrite($db, 'UPDATE pin_throttle SET failed_attempts = 0');
        self::assertNotNull($reset);
        self::assertSame(1, $reset['params']['uid'] ?? null);
    }

    public function testDestroyReferencedByOrderReturns409(): void
    {
        $db = $this->permittedDb();
        $db->menuRow = ['id' => 5, 'name' => 'Best Of'];
        $this->actingPin($db);
        $db->failOnExecute = new PDOException('referenced', 23000); // FK order_item.menu_id RESTRICT

        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'pin_email' => 'staff@wakdo.local', 'pin' => '4729'], '/admin/menus/5/delete'), $db)->destroy(['id' => '5']);

        self::assertSame(409, $response->status());
        self::assertStringContainsString('suppression impossible', $response->body());
    }

    public function testToggleFlipsAvailability(): void
    {
        $db = $this->permittedDb();
        $db->menuRow = ['id' => 5, 'name' => 'Best Of', 'is_available' => 1];

        $response = $this->controller($this->post(['_csrf' => $this->csrf], '/admin/menus/5/toggle'), $db)->toggle(['id' => '5']);

        self::assertSame(302, $response->status());
        $write = $this->findWrite($db, 'UPDATE menu SET is_available');
        self::assertNotNull($write);
        self::assertSame(0, $write['params']['a'] ?? null); // 1 -> 0
    }

    /**
     * @return array{sql: string, params: array<string|int, mixed>}|null
     */
    private function findWrite(FakeDatabase $db, string $needle): ?array
    {
        foreach ($db->writes as $write) {
            if (str_contains($write['sql'], $needle)) {
                return $write;
            }
        }

        return null;
    }

    private function assertAuditWithinTransaction(FakeDatabase $db): void
    {
        $log = $db->eventLog;
        $begin = array_search('begin', $log, true);
        $commit = array_search('commit', $log, true);
        $auditAt = null;
        foreach ($log as $i => $event) {
            if (str_contains($event, 'INSERT INTO audit_log')) {
                $auditAt = $i;
            }
        }

        self::assertIsInt($begin);
        self::assertIsInt($commit);
        self::assertNotNull($auditAt);
        self::assertTrue($begin < $auditAt && $auditAt < $commit, 'audit_log doit etre ecrit entre begin et commit');
    }
}
