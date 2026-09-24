<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PDOException;
use PHPUnit\Framework\TestCase;
use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\SessionManager;
use App\Controllers\RoleController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\Request;
use App\Tests\Support\FakeDatabase;

final class TestRoleController extends RoleController
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

final class RoleControllerTest extends TestCase
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
        $db->userDisplayRow = ['first_name' => 'Cor', 'last_name' => 'J', 'role_label' => 'Administrateur'];
        $db->canResult = true;
        $db->permissionCodes = ['role.manage'];
        // Catalogue minimal : id 1 = role.manage (le vecteur de lockout).
        $db->permissionsRows = [
            ['id' => 1, 'code' => 'role.manage', 'label' => 'Manage RBAC'],
            ['id' => 2, 'code' => 'stats.read', 'label' => 'Stats'],
            ['id' => 3, 'code' => 'user.read', 'label' => 'Users'],
        ];

        return $db;
    }

    private function actingPin(FakeDatabase $db): void
    {
        $db->actingUserRow = ['id' => 9, 'role_id' => 4, 'pin_hash' => (new PasswordHasher(new Config()))->hash('4729')];
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function createForm(array $overrides = []): array
    {
        return array_merge([
            '_csrf' => $this->csrf,
            'code' => 'kitchen_kds',
            'label' => 'Kitchen KDS',
            'default_route' => '/kitchen/display',
            'order_source' => '',
            'perm_1' => '1',          // role.manage coche
            'source_counter' => '1',
            'pin_email' => 'sam@wakdo.local',
            'pin' => '4729',
        ], $overrides);
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

    private function controller(Request $request, FakeDatabase $db): TestRoleController
    {
        return new TestRoleController($request, new Config(), new Database(new Config()), $this->session, $db);
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

    public function testIndexRequiresRoleManage(): void
    {
        $db = $this->permittedDb();
        $db->canResult = false;

        self::assertSame(403, $this->controller($this->get('/admin/roles'), $db)->index()->status());
    }

    public function testIndexListsRoles(): void
    {
        $db = $this->permittedDb();
        $db->rolesAllRows = [
            ['id' => 2, 'code' => 'manager', 'label' => 'Manager', 'default_route' => '/admin/stats', 'order_source' => null, 'is_active' => 1],
            ['id' => 3, 'code' => 'kitchen', 'label' => 'Équipier cuisine', 'default_route' => '/kitchen/display', 'order_source' => null, 'is_active' => 1],
        ];

        $response = $this->controller($this->get('/admin/roles'), $db)->index();
        self::assertSame(200, $response->status());
        $body = $response->body();
        self::assertStringContainsString('manager', $body);
        // Regression F40 (page d'accueil affichee comme un chemin brut) : la page
        // d'accueil du role kitchen doit avoir un libelle humain, pas /kitchen/display.
        self::assertStringContainsString('Écran cuisine (KDS)', $body);
        self::assertStringNotContainsString('>/kitchen/display<', $body);
    }

    public function testCreateFormKeepsLayoutSidebarPermissions(): void
    {
        // Regression : AdminController::adminView() fusionne $data + $context ; le
        // catalogue complet des permissions passe par renderForm() sous la meme cle
        // 'permissions' que la liste des codes de l'utilisateur courant ecrasait
        // celle du layout (l'union `+` garde la cle de gauche). La sidebar recevait
        // alors le catalogue (list<array>) au lieu de list<string>, si bien que
        // in_array(<code>, $perms, true) etait toujours faux et masquait tout le
        // menu sauf le Tableau de bord (defaut visible capture 40).
        $db = $this->permittedDb();

        $response = $this->controller($this->get('/admin/roles/create'), $db)->create();

        self::assertSame(200, $response->status());
        $body = $response->body();
        // Seul admin/layout.php affiche ce marqueur, sous la condition $can('user.read')
        // || $can('role.manage'). Le lien "Annuler" du formulaire pointe LUI AUSSI vers
        // /admin/roles (roles/form.php) : verifier seulement l'URL ne prouve rien (une
        // sonde de relecture independante l'a confirme). Ces deux assertions ne peuvent
        // reussir que si le layout a lu une VRAIE liste de codes de permission, pas le
        // catalogue complet renvoye jusqu'ici sous la meme cle 'permissions'.
        self::assertStringContainsString('<div class="sidebar-section-label">Administration</div>', $body);
        self::assertStringContainsString('<a href="/admin/roles" class="sidebar-item active">Rôles</a>', $body);
    }

    public function testEditFormShowsHumanPageLabelNotRawTechnicalPath(): void
    {
        // Regression F40 (page d'accueil affichee comme un chemin brut) : les roles
        // operationnels (kitchen/counter/drive) n'etaient pas dans $routeOptions, donc
        // le repli affichait le chemin technique brut comme libelle de l'option
        // deja selectionnee dans la liste deroulante.
        $db = $this->permittedDb();
        $db->roleManageRow = ['id' => 3, 'code' => 'kitchen', 'label' => 'Équipier cuisine', 'description' => '', 'default_route' => '/kitchen/display', 'order_source' => null, 'is_active' => 1];

        $response = $this->controller($this->get('/admin/roles/3/edit'), $db)->edit(['id' => '3']);

        self::assertSame(200, $response->status());
        $body = $response->body();
        self::assertStringContainsString('Écran cuisine (KDS)', $body);
        self::assertStringNotContainsString('>/kitchen/display<', $body);
    }

    public function testStoreCreatesCustomRoleWithPinAndAudit(): void
    {
        $db = $this->permittedDb();
        $this->actingPin($db);
        $db->lastInsertId = 10;

        $response = $this->controller($this->post($this->createForm(), '/admin/roles'), $db)->store();

        self::assertSame(302, $response->status());
        self::assertSame(['begin', 'commit'], $db->transactionEvents);
        self::assertTrue($db->wrote('INSERT INTO role '));
        self::assertTrue($db->wrote('INSERT INTO role_permission'));
        $audit = $this->findWrite($db, 'INSERT INTO audit_log');
        self::assertNotNull($audit);
        self::assertSame('role.manage', $audit['params']['code'] ?? null);
        self::assertSame(9, $audit['params']['uid'] ?? null); // acteur = PIN
    }

    public function testStoreRejectsDuplicateCodeWith409(): void
    {
        $db = $this->permittedDb();
        $this->actingPin($db);
        $db->roleCodeTaken = true;

        $response = $this->controller($this->post($this->createForm(), '/admin/roles'), $db)->store();

        self::assertSame(409, $response->status());
        self::assertFalse($db->wrote('INSERT INTO role '));
    }

    public function testStoreRejectsInvalidCode(): void
    {
        $db = $this->permittedDb();
        $response = $this->controller($this->post($this->createForm(['code' => 'Bad Code!']), '/admin/roles'), $db)->store();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO role '));
    }

    public function testStoreRejectsInvalidCsrf(): void
    {
        $db = $this->permittedDb();
        $response = $this->controller($this->post($this->createForm(['_csrf' => 'bad']), '/admin/roles'), $db)->store();

        self::assertSame(403, $response->status());
    }

    public function testStoreWithoutValidPinLogsFailed(): void
    {
        $db = $this->permittedDb();
        $db->actingUserRow = null;

        $response = $this->controller($this->post($this->createForm(['pin' => '0000']), '/admin/roles'), $db)->store();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('INSERT INTO role '));
        self::assertSame(['pin.failed'], $db->auditActions());
    }

    public function testUpdateNotFound(): void
    {
        $db = $this->permittedDb();
        $db->roleManageRow = null;

        self::assertSame(404, $this->controller($this->post($this->createForm(), '/admin/roles/9'), $db)->update(['id' => '9'])->status());
    }

    public function testUpdateAppliesWithPinAndAuditDiff(): void
    {
        $db = $this->permittedDb();
        $db->roleManageRow = ['id' => 5, 'code' => 'counter', 'label' => 'Counter', 'description' => null, 'default_route' => '/counter/orders', 'order_source' => 'counter', 'is_active' => 1];
        $db->permissionCodes = ['stats.read']; // permissions actuelles (diff RG-6 reutilise ce bouton)
        $this->actingPin($db);

        // perm_1 (role.manage) coche, is_active coche.
        $form = ['_csrf' => $this->csrf, 'label' => 'Counter', 'default_route' => '/counter/orders', 'order_source' => 'counter', 'perm_1' => '1', 'is_active' => '1', 'pin_email' => 'sam@wakdo.local', 'pin' => '4729'];
        $response = $this->controller($this->post($form, '/admin/roles/5'), $db)->update(['id' => '5']);

        self::assertSame(302, $response->status());
        self::assertTrue($db->wrote('UPDATE role SET'));
        self::assertTrue($db->wrote('INSERT INTO role_permission'));
        self::assertSame('role.manage', ($this->findWrite($db, 'INSERT INTO audit_log')['params']['code'] ?? null));
    }

    public function testUpdateBlocksRemovingRoleManageFromAdmin(): void
    {
        $db = $this->permittedDb();
        $db->roleManageRow = ['id' => 1, 'code' => 'admin', 'label' => 'Administrator', 'description' => null, 'default_route' => '/admin/dashboard', 'order_source' => null, 'is_active' => 1];

        // role.manage (perm_1) NON coche -> retirerait role.manage a l'admin.
        $form = ['_csrf' => $this->csrf, 'label' => 'Administrator', 'perm_2' => '1', 'is_active' => '1', 'pin_email' => 'sam@wakdo.local', 'pin' => '4729'];
        $response = $this->controller($this->post($form, '/admin/roles/1'), $db)->update(['id' => '1']);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('administrateur', $response->body());
        self::assertFalse($db->wrote('UPDATE role SET'));
    }

    public function testUpdateBlocksDeactivatingAdminRole(): void
    {
        $db = $this->permittedDb();
        $db->roleManageRow = ['id' => 1, 'code' => 'admin', 'label' => 'Administrator', 'description' => null, 'default_route' => '/admin/dashboard', 'order_source' => null, 'is_active' => 1];

        // role.manage conserve mais is_active absent -> desactivation de l'admin -> bloque.
        $form = ['_csrf' => $this->csrf, 'label' => 'Administrator', 'perm_1' => '1', 'pin_email' => 'sam@wakdo.local', 'pin' => '4729'];
        $response = $this->controller($this->post($form, '/admin/roles/1'), $db)->update(['id' => '1']);

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('UPDATE role SET'));
    }

    public function testUpdateLockedActorReturns422WithoutEffect(): void
    {
        $db = $this->permittedDb();
        $db->roleManageRow = ['id' => 5, 'code' => 'counter', 'label' => 'Counter', 'description' => null, 'default_route' => null, 'order_source' => 'counter', 'is_active' => 1];
        $this->actingPin($db);
        $db->pinThrottleLockoutUntil = date('Y-m-d H:i:s', time() + 300);

        $form = ['_csrf' => $this->csrf, 'label' => 'Counter', 'perm_1' => '1', 'is_active' => '1', 'pin_email' => 'sam@wakdo.local', 'pin' => '4729'];
        $response = $this->controller($this->post($form, '/admin/roles/5'), $db)->update(['id' => '5']);

        self::assertSame(422, $response->status());
        self::assertSame([], $db->auditActions());      // pas de pin.failed sous verrou
        self::assertFalse($db->wrote('UPDATE role SET'));
    }
}
