<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use App\Auth\Authorizer;
use App\Auth\Csrf;
use App\Auth\PasswordHasher;
use App\Auth\SessionGuard;
use App\Auth\SessionManager;
use App\Auth\UserDirectory;
use App\Auth\UserRepository;
use App\Controllers\ProfileController;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseInterface;
use App\Core\Request;
use App\Tests\Support\FakeDatabase;

final class TestProfileController extends ProfileController
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

    // Couture DB unique : la re-verification du mot de passe courant et l'ecriture
    // d'audit du set de PIN passent par db() ; on la route vers le double.
    protected function db(): DatabaseInterface
    {
        return $this->fakeDb;
    }

    protected function sessionGuard(): SessionGuard
    {
        return new SessionGuard($this->testSession, $this->fakeDb, $this->config);
    }

    protected function authorizer(): Authorizer
    {
        return new Authorizer($this->fakeDb);
    }

    protected function userDirectory(): UserDirectory
    {
        return new UserDirectory($this->fakeDb);
    }

    protected function userRepository(): UserRepository
    {
        return new UserRepository($this->fakeDb);
    }
}

final class ProfileControllerTest extends TestCase
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

    /** Mot de passe courant de reference pour la re-verification au set de PIN. */
    private const CURRENT_PASSWORD = 'S3cret-Wakdo!';

    private function permittedDb(): FakeDatabase
    {
        $db = new FakeDatabase();
        $db->guardUserRow = ['is_active' => 1];
        $db->userDisplayRow = ['first_name' => 'Corentin', 'last_name' => 'J', 'role_label' => 'Administrateur'];
        $db->canResult = true;
        $db->permissionCodes = ['category.manage'];
        // Re-verification d'identite : hash argon2id du mot de passe courant (couts
        // de test poses en setUp). currentPasswordRow null -> verify echoue.
        $db->currentPasswordRow = ['password_hash' => (new PasswordHasher(new Config()))->hash(self::CURRENT_PASSWORD)];

        return $db;
    }

    /**
     * @param array<string, string> $form
     */
    private function post(array $form): Request
    {
        return new Request(
            'POST',
            '/admin/profile/pin',
            [],
            ['content-type' => 'application/x-www-form-urlencoded'],
            http_build_query($form),
            '203.0.113.5',
        );
    }

    /**
     * Requete nominale de set de PIN : CSRF valide, PIN + confirmation, et le mot de
     * passe courant attendu par la re-verification d'identite (permittedDb).
     */
    private function validPost(): Request
    {
        return $this->post([
            '_csrf' => $this->csrf, 'pin' => '4729', 'pin_confirm' => '4729', 'current_password' => self::CURRENT_PASSWORD,
        ]);
    }

    private function controller(Request $request, FakeDatabase $db): TestProfileController
    {
        return new TestProfileController($request, new Config(), new Database(new Config()), $this->session, $db);
    }

    public function testRedirectsToLoginWithoutSession(): void
    {
        $request = new Request('GET', '/admin/profile/pin', [], [], '', '203.0.113.5');
        $response = $this->controller($request, new FakeDatabase())->showPin();

        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('Location'));
    }

    public function testShowPinReflectsStatus(): void
    {
        $request = new Request('GET', '/admin/profile/pin', [], [], '', '203.0.113.5');

        $db = $this->permittedDb();
        $db->userPinSet = false;
        $response = $this->controller($request, $db)->showPin();
        self::assertSame(200, $response->status());
        self::assertStringContainsString('name="pin"', $response->body());
        self::assertStringContainsString('aucun PIN défini', $response->body());

        $db2 = $this->permittedDb();
        $db2->userPinSet = true;
        self::assertStringContainsString('un PIN est défini', $this->controller($request, $db2)->showPin()->body());
    }

    public function testShowPinExposesLengthPolicyForLiveValidation(): void
    {
        // Controle pendant la saisie (Cr 2.b.1) : le motif des deux champs reprend la
        // politique serveur (STAFF_PIN_MIN/MAX_LENGTH, 4 a 12 par defaut) et la
        // confirmation est liee au PIN, pour signaler l'ecart avant l'envoi.
        $request = new Request('GET', '/admin/profile/pin', [], [], '', '203.0.113.5');
        $body = $this->controller($request, $this->permittedDb())->showPin()->body();

        self::assertSame(2, substr_count($body, 'pattern="[0-9]{4,12}"'));
        self::assertStringContainsString('data-match="pin"', $body);
    }

    public function testUpdatePinValidStoresHashAndRedirects(): void
    {
        $db = $this->permittedDb();
        $db->userPinSet = false; // premiere definition -> summary "PIN defini"
        $response = $this->controller($this->validPost(), $db)->updatePin();

        self::assertSame(302, $response->status());
        self::assertSame('/admin/profile/pin', $response->header('Location'));
        self::assertSame('PIN enregistré.', $this->session->get('_flash'));

        // Invariant central : la cible est l'utilisateur de la SESSION (1, pose en
        // setUp), jamais un champ de formulaire ; et c'est un hash, pas le PIN clair.
        $write = null;
        foreach ($db->writes as $w) {
            if (str_contains($w['sql'], 'UPDATE user SET pin_hash')) {
                $write = $w;
                break;
            }
        }
        self::assertNotNull($write);
        self::assertSame(1, $write['params']['id'] ?? null);
        self::assertNotSame('4729', $write['params']['hash'] ?? null);
    }

    public function testUpdatePinWritesAuditTrace(): void
    {
        // ADR-0004 / RG-T14 : le set de PIN ecrit une ligne audit_log (action pin.set),
        // imputee a l'utilisateur de session, sans jamais journaliser le PIN ni un hash.
        $db = $this->permittedDb();
        $db->userPinSet = true; // un PIN existe deja -> changement
        $response = $this->controller($this->validPost(), $db)->updatePin();

        self::assertSame(302, $response->status());
        self::assertSame(['pin.set'], $db->auditActions());

        $audit = null;
        foreach ($db->writes as $w) {
            if (str_contains($w['sql'], 'INSERT INTO audit_log')) {
                $audit = $w;
                break;
            }
        }
        self::assertNotNull($audit);
        self::assertSame(1, $audit['params']['uid'] ?? null);        // acteur = session userId
        self::assertSame('user', $audit['params']['etype'] ?? null);
        self::assertSame(1, $audit['params']['eid'] ?? null);
        // Aucune valeur sensible dans le summary (ni PIN clair, ni hash).
        $summary = (string) ($audit['params']['summary'] ?? '');
        self::assertStringNotContainsString('4729', $summary);
        self::assertStringContainsString('modifié', $summary);       // userPinSet=true -> "PIN modifié"
    }

    public function testUpdatePinRejectsWrongCurrentPassword(): void
    {
        // Re-verification d'identite : mauvais mot de passe courant -> 422, pas
        // d'ecriture du PIN. D-1 (contre-audit 2026-09-30) : cet echec est
        // desormais compte (dimension COMPTE, D-1.a) et trace (auth.reauth_failed)
        // -- avant ce correctif, ce formulaire n'armait aucun verrou et ne
        // laissait aucune trace (poste a session partagee).
        $db = $this->permittedDb();
        $request = $this->post([
            '_csrf' => $this->csrf, 'pin' => '4729', 'pin_confirm' => '4729', 'current_password' => 'wrong-password',
        ]);
        $response = $this->controller($request, $db)->updatePin();

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Mot de passe actuel incorrect', $response->body());
        self::assertFalse($db->wrote('UPDATE user SET pin_hash'));
        self::assertNull($this->session->get('_flash'));
    }

    public function testUpdatePinRejectsWrongCurrentPasswordRecordsThrottleAndAudit(): void
    {
        // D-1.a (revue adverse, contre-audit 30/09) : un echec incremente
        // DESORMAIS le compteur DU COMPTE (user.failed_login_attempts, meme
        // increment SQL atomique et meme politique que AuthService::
        // recordFailure(), reutilise via App\Auth\AccountLockout) -- PLUS
        // pin_throttle (D-1 d'origine, corrige : ce compteur etait partage avec
        // le PIN d'action sensible et remis a zero par le succes d'un TIERS).
        // Ecrit aussi une trace d'audit `auth.reauth_failed`, SANS jamais y
        // ecrire la valeur saisie (RGPD art. 5.1.c, meme discipline que pin.failed).
        $db = $this->permittedDb();
        $request = $this->post([
            '_csrf' => $this->csrf, 'pin' => '4729', 'pin_confirm' => '4729', 'current_password' => 'wrong-password',
        ]);
        $response = $this->controller($request, $db)->updatePin();

        self::assertSame(422, $response->status());
        self::assertTrue($db->wrote('UPDATE user SET failed_login_attempts = failed_login_attempts + 1'));
        self::assertFalse($db->wrote('pin_throttle'), 'D-1.a : la re-verification du mot de passe ne doit plus toucher pin_throttle.');
        self::assertSame(['auth.reauth_failed'], $db->auditActions());

        $audit = null;
        foreach ($db->writes as $w) {
            if (str_contains($w['sql'], 'INSERT INTO audit_log')) {
                $audit = $w;
            }
        }
        self::assertNotNull($audit);
        self::assertSame(1, $audit['params']['uid'] ?? null);
        $summary = (string) ($audit['params']['summary'] ?? '');
        self::assertStringNotContainsString('wrong-password', $summary);

        // Increment du throttle + audit dans UNE seule transaction (RG-T08).
        self::assertContains('begin', $db->eventLog);
        self::assertContains('commit', $db->eventLog);
    }

    public function testUpdatePinLockedRejectsWithoutPayingArgon2idOrRevealingPassword(): void
    {
        // D-1.a : le verrou lu est DESORMAIS celui du COMPTE (user.lockout_until,
        // AccountLockout::isLocked()), evalue AVANT toute verification. Un
        // compte deja verrouille ne paie MEME PAS argon2id (contrairement au
        // login, il n'y a ici qu'une seule identite possible -- celle de la
        // session -- donc rien a rendre indiscernable d'un "email inconnu" ;
        // payer le cout n'apporterait aucune garantie supplementaire, juste une
        // charge CPU inutile pendant le verrou) et le message ne revele pas si
        // le mot de passe fourni etait bon.
        $db = $this->permittedDb();
        $db->userAccountLockoutUntil = date('Y-m-d H:i:s', time() + 300);

        // Mot de passe COURANT ET CORRECT : si la verification etait quand meme
        // executee, elle reussirait et le PIN serait enregistre -- la preuve que
        // le verrou coupe bien AVANT verify().
        $response = $this->controller($this->validPost(), $db)->updatePin();

        self::assertSame(422, $response->status());
        self::assertStringNotContainsString('Mot de passe actuel incorrect', $response->body());
        self::assertFalse($db->wrote('UPDATE user SET pin_hash'));
        self::assertSame([], $db->auditActions());
        self::assertFalse($db->wrote('failed_login_attempts + 1')); // pas de double-compte sous verrou deja actif

        foreach ($db->reads as $read) {
            self::assertStringNotContainsString('password_hash FROM user', $read['sql']);
        }
    }

    public function testUpdatePinValidResetsThrottleOnSuccess(): void
    {
        // D-1.a : un succes remet a zero le compteur DU COMPTE (D-1.a) --
        // jamais pin_throttle desormais (un manager qui s'est trompe puis a
        // reussi n'est pas penalise plus tard, mais sur SON PROPRE budget).
        $db = $this->permittedDb();
        $db->userPinSet = false;
        $response = $this->controller($this->validPost(), $db)->updatePin();

        self::assertSame(302, $response->status());
        self::assertTrue($db->wrote('UPDATE user SET failed_login_attempts = 0'));
        self::assertFalse($db->wrote('pin_throttle'), 'D-1.a : le succes ne doit plus toucher pin_throttle.');
    }

    /**
     * D-1.a (2e revue adverse, contre-audit 30/09) : la version precedente de
     * ce test posait un verrou DEJA ACTIF (`userAccountLockoutUntil`) puis
     * verifiait seulement qu'il tenait -- il ne pouvait PAS echouer meme si
     * `ProfileController` etait revenu a `pin_throttle`, puisque
     * `PinThrottle::reset()` n'a de toute facon aucun effet observable sur le
     * champ `userAccountLockoutUntil` de ce double (deux champs distincts,
     * aucune relation entre eux dans `FakeDatabase`) : le test passait pour la
     * MAUVAISE raison.
     *
     * Version probante : part d'un etat "4 echecs deja au compteur DU COMPTE"
     * (`accountThrottleRow`, la relecture post-increment qu'`AccountLockout::
     * recordFailureWithin()` ferait apres un 5e), simule une action PIN
     * reussie AILLEURS (`PinThrottle::reset()`, une table totalement distincte
     * -- sans effet ici par construction), PUIS soumet UN mauvais mot de passe
     * de plus et verifie que le verrou DU COMPTE est CALCULE a partir de cet
     * etat (5e echec -> `lockout_until` pose). Cette assertion echoue SI le
     * code revenait a `pin_throttle` : le verrou lirait alors
     * `pinThrottleAttempts` (defaut 1 dans ce double, jamais 5), et
     * `UPDATE user SET lockout_until` ne serait meme pas ecrit (l'ecriture
     * irait vers `pin_throttle`).
     *
     * Limite assumee : `FakeDatabase` n'est pas relationnelle -- elle ne peut
     * pas prouver qu'un VRAI succes de PIN sur un VRAI tiers, contre une VRAIE
     * base, laisserait `user.lockout_until` intact ; c'est le test navigateur
     * (`tests/e2e/security-bruteforce.spec.js`, scenario D-1.a) qui apporte
     * cette preuve de bout en bout, avec une vraie annulation de commande
     * autorisee par l'email+PIN d'un autre compte.
     */
    public function testUpdatePinFailureLocksTheAccountFromItsPriorAttemptCountRegardlessOfPinThrottle(): void
    {
        $db = $this->permittedDb();
        // Relecture post-increment simulee pour le 5e echec (4 deja presents).
        $db->accountThrottleRow = ['failed_login_attempts' => 5];

        // Action PIN reussie AILLEURS, avec l'identite d'un TIERS : ne doit
        // avoir AUCUN effet sur le calcul ci-dessous.
        (new \App\Auth\PinThrottle($db, new \App\Core\Config()))->reset(1);

        $request = $this->post([
            '_csrf' => $this->csrf, 'pin' => '4729', 'pin_confirm' => '4729', 'current_password' => 'faux-5',
        ]);
        $response = $this->controller($request, $db)->updatePin();

        self::assertSame(422, $response->status());

        $lockWrite = null;
        foreach ($db->writes as $w) {
            if (str_contains($w['sql'], 'UPDATE user SET lockout_until')) {
                $lockWrite = $w;
            }
        }
        self::assertNotNull($lockWrite, 'le verrou DU COMPTE doit etre calcule (et non celui du PIN).');
        self::assertNotNull($lockWrite['params']['lock'] ?? null, 'au seuil (5e echec), un lockout_until doit etre pose.');
    }

    public function testUpdatePinFailsWhenNoRowAffected(): void
    {
        // Cible inexistante (0 ligne affectee) : pas de faux succes, pas de flash, pas
        // d'audit (l'ecriture du PIN n'a rien affecte).
        $db = $this->permittedDb();
        $db->executeRowCount = 0;

        $response = $this->controller($this->validPost(), $db)->updatePin();

        self::assertSame(500, $response->status());
        self::assertNull($this->session->get('_flash'));
        self::assertFalse($db->wrote('INSERT INTO audit_log'));
    }

    public function testUpdatePinMismatchRerenders422(): void
    {
        $db = $this->permittedDb();
        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'pin' => '4729', 'pin_confirm' => '0000']), $db)->updatePin();

        self::assertSame(422, $response->status());
        self::assertStringContainsString('ne correspondent pas', $response->body());
        self::assertFalse($db->wrote('UPDATE user SET pin_hash'));
    }

    public function testUpdatePinTooShortRerenders422(): void
    {
        $db = $this->permittedDb();
        $response = $this->controller($this->post(['_csrf' => $this->csrf, 'pin' => '12', 'pin_confirm' => '12']), $db)->updatePin();

        self::assertSame(422, $response->status());
        self::assertFalse($db->wrote('UPDATE user SET pin_hash'));
    }

    public function testUpdatePinRejectsInvalidCsrf(): void
    {
        $db = $this->permittedDb();
        $response = $this->controller($this->post(['_csrf' => 'wrong', 'pin' => '4729', 'pin_confirm' => '4729']), $db)->updatePin();

        self::assertSame(403, $response->status());
        self::assertFalse($db->wrote('UPDATE user SET pin_hash'));
    }
}
