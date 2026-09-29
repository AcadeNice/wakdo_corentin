<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use App\Auth\PasswordHasher;
use App\Auth\PinGate;
use App\Auth\PinThrottle;
use App\Auth\PinVerifier;
use App\Core\Config;
use App\Tests\Support\FakeDatabase;

/**
 * `PinGate` factorise la porte PIN pour l'API JSON (RG-T13/RG-T22) : verrou
 * evalue AVANT verification, leurre de timing sous verrou, trace `pin.failed` +
 * throttle sur PIN invalide, `entity_id` normalise (0 -> NULL sur creation).
 */
final class PinGateTest extends TestCase
{
    /** @var list<string> */
    private array $touchedKeys = [];

    private FakeDatabase $db;

    protected function setUp(): void
    {
        $this->setEnv('STAFF_PIN_MIN_LENGTH', '4');
        $this->setEnv('STAFF_PIN_MAX_LENGTH', '12');
        $this->setEnv('ARGON2_MEMORY_COST', '1024');
        $this->setEnv('ARGON2_TIME_COST', '1');
        $this->setEnv('ARGON2_THREADS', '1');

        $this->db = new FakeDatabase();
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

    private function gate(): PinGate
    {
        $config = new Config();
        $hasher = new PasswordHasher($config);

        return new PinGate($this->db, new PinVerifier($this->db, $config, $hasher), new PinThrottle($this->db, $config));
    }

    public function testResolveWithValidPinReturnsActor(): void
    {
        $this->db->actingUserRow = ['id' => 9, 'role_id' => 4, 'pin_hash' => (new PasswordHasher(new Config()))->hash('4729')];

        $actor = $this->gate()->resolve(1, 'e@e.fr', '4729', 'product', 5);

        self::assertSame(['id' => 9, 'role_id' => 4], $actor);
    }

    public function testResolveWithInvalidPinTracesFailureAndThrottle(): void
    {
        $actor = $this->gate()->resolve(1, 'e@e.fr', 'wrong', 'product', 5);

        self::assertNull($actor);
        self::assertSame(['pin.failed'], $this->db->auditActions());
        self::assertTrue($this->db->wrote('INSERT INTO pin_throttle'));
    }

    /**
     * Minimisation RGPD (art. 5.1.c) : l'adresse SAISIE ne doit plus jamais
     * apparaitre dans `summary`, sous aucune forme. Ce test est le test ROUGE
     * de l'ecart -- avant correctif, `summary` contenait
     * "... (email tenté: cible@wakdo.local)".
     */
    public function testResolveWithInvalidPinNeverWritesTheAttemptedEmail(): void
    {
        $this->gate()->resolve(1, 'cible@wakdo.local', 'wrong', 'product', 5);

        $write = $this->auditWrite();
        self::assertNotNull($write);
        self::assertStringNotContainsString('cible@wakdo.local', (string) $write['params']['summary']);
        self::assertStringNotContainsString('cible@wakdo.local', (string) $write['params']['details']);
    }

    /**
     * Quand l'adresse saisie correspond a un compte EXISTANT, l'identifiant du
     * compte (pas son adresse) est trace dans `details` -- utile en revue pour
     * reperer un brute-force cible sans reecrire l'adresse en clair.
     */
    public function testResolveWithInvalidPinRecordsTargetUserIdWhenEmailMatchesAnAccount(): void
    {
        $this->db->pinFailedTargetUserRow = ['target_user_id' => 42];

        $this->gate()->resolve(1, 'cible@wakdo.local', 'wrong', 'product', 5);

        $write = $this->auditWrite();
        self::assertNotNull($write);
        $details = json_decode((string) $write['params']['details'], true);
        self::assertSame(42, $details['target_user_id'] ?? null);
        self::assertSame('action sensible', $details['context'] ?? null);
        self::assertStringNotContainsString('inconnue', (string) $write['params']['summary']);
    }

    /**
     * Quand l'adresse saisie ne correspond a AUCUN compte, `target_user_id` est
     * null et le resume le dit en clair ("adresse inconnue"), sans jamais
     * ecrire l'adresse elle-meme.
     */
    public function testResolveWithInvalidPinRecordsUnknownAddressWhenEmailMatchesNoAccount(): void
    {
        $this->db->pinFailedTargetUserRow = null;

        $this->gate()->resolve(1, 'personne@wakdo.local', 'wrong', 'product', 5);

        $write = $this->auditWrite();
        self::assertNotNull($write);
        $details = json_decode((string) $write['params']['details'], true);
        self::assertArrayHasKey('target_user_id', $details);
        self::assertNull($details['target_user_id']);
        self::assertStringContainsString('adresse inconnue', (string) $write['params']['summary']);
    }

    /**
     * @return array{sql: string, params: array<string, mixed>}|null
     */
    private function auditWrite(): ?array
    {
        foreach ($this->db->writes as $candidate) {
            if (str_contains($candidate['sql'], 'INSERT INTO audit_log')) {
                return $candidate;
            }
        }

        return null;
    }

    public function testResolveNormalizesZeroEntityIdToNullOnCreation(): void
    {
        // Sur une creation (id pas encore attribue), l'appelant passe 0 ; PinGate
        // l'ecrit NULL (meme regle que UserController/RoleController::logFailedPin),
        // jamais une FK vers une ligne qui n'existe pas.
        $this->gate()->resolve(1, 'e@e.fr', 'wrong', 'user', 0);

        $write = null;
        foreach ($this->db->writes as $candidate) {
            if (str_contains($candidate['sql'], 'INSERT INTO audit_log')) {
                $write = $candidate;
            }
        }
        self::assertNotNull($write);
        self::assertArrayHasKey('eid', $write['params']);
        self::assertNull($write['params']['eid']);
    }

    public function testResolveWithLockedAccountPaysTimingDecoyAndWritesNothing(): void
    {
        // Verrou actif (RG-T22) : gate-before-verify, leurre de timing (le meme
        // cout argon2id qu'un vrai verify), et SURTOUT aucune nouvelle ecriture
        // (ni pin.failed, ni increment du throttle) -- les echecs ayant arme le
        // verrou sont deja audites, on ne les re-audite pas a chaque tentative
        // sous verrou (borne l'amplification de l'audit append-only).
        $this->db->pinThrottleLockoutUntil = date('Y-m-d H:i:s', time() + 3600);

        $actor = $this->gate()->resolve(1, 'e@e.fr', '4729', 'product', 5);

        self::assertNull($actor);
        self::assertSame([], $this->db->writes);
        self::assertSame([], $this->db->auditActions());
    }

    public function testResetDelegatesToThrottle(): void
    {
        // reset() ne doit pas exploser sans verrou pose ; verifie juste l'absence
        // d'erreur (le comportement de PinThrottle::reset() est teste par ailleurs,
        // PinThrottleTest).
        $this->gate()->reset(1);

        self::assertTrue($this->db->wrote('UPDATE pin_throttle SET failed_attempts'));
    }

    public function testWriteAuditWritesTheSameShapeAsHtmlControllers(): void
    {
        $this->gate()->writeAudit($this->db, 'product.update', 9, 4, 'product', 5, 'price_cents 590 -> 620', ['field' => 'price_cents']);

        self::assertTrue($this->db->wrote('INSERT INTO audit_log'));
        $write = null;
        foreach ($this->db->writes as $candidate) {
            if (str_contains($candidate['sql'], 'INSERT INTO audit_log')) {
                $write = $candidate;
            }
        }
        self::assertNotNull($write);
        self::assertSame('product.update', $write['params']['code'] ?? null);
        self::assertSame(5, $write['params']['eid'] ?? null);
        self::assertSame(9, $write['params']['uid'] ?? null);
    }
}
