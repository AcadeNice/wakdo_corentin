<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use App\Auth\Csrf;
use App\Core\Response;
use App\Tests\Support\FakeDatabase;
use App\Tests\Support\HtmlRouteHarness;

/**
 * Badge « code personnel » de la page « Santé de l'API », back-office HTML, et
 * son pendant : l'absence de badge ne cache pas un code exige.
 *
 *  - `pin = always` : bonne permission, jeton valide, formulaire VALIDE mais
 *    sans code -> 422 (formulaire reaffiche avec l'erreur), aucune ecriture
 *    metier ; le MEME appel avec le code -> l'action ecrit. Le formulaire est
 *    valide dans les deux cas, sinon le 422 viendrait de la validation et ne
 *    dirait rien du code.
 *  - `pin = price` : sans code, un changement de prix (ou de TVA) est refuse ;
 *    une modification qui ne touche ni prix ni TVA passe sans code.
 *  - route d'ecriture SANS `pin` : le formulaire valide, sans code, reussit.
 *  - `reauth = password` : sans le mot de passe courant, refus sans ecriture.
 *
 * « Aucune ecriture metier » : un refus de code ecrit VOLONTAIREMENT deux
 * traces, la ligne `pin.failed` d'audit_log (RG-T14) et le compteur anti-essais
 * `pin_throttle` (RG-T22) ; tout le reste (INSERT, UPDATE, DELETE, REPLACE) doit
 * etre absent, et la trace `pin.failed` presente prouve que c'est bien la porte
 * du code qui a refuse.
 */
final class HtmlRoutePinTest extends TestCase
{
    protected function setUp(): void
    {
        HtmlRouteHarness::applyEnv();
    }

    protected function tearDown(): void
    {
        HtmlRouteHarness::clearEnv();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool, 3: ?string}>
     */
    public static function alwaysPinRoutes(): array
    {
        return self::entriesWhere(static fn (array $entry): bool => $entry[5] === 'always');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool, 3: ?string}>
     */
    public static function pricePinRoutes(): array
    {
        return self::entriesWhere(static fn (array $entry): bool => $entry[5] === 'price');
    }

    /**
     * Une ligne par (route `pin = price`, variante de changement sensible).
     *
     * @return array<string, array{0: string, 1: string, 2: bool, 3: ?string, 4: string}>
     */
    public static function priceChangeVariants(): array
    {
        $cases = [];
        foreach (self::pricePinRoutes() as $key => [$method, $path, $anonymous, $permission]) {
            $scenario = HtmlRouteHarness::scenario($key) ?? [];
            $price = is_array($scenario['price'] ?? null) ? $scenario['price'] : [];
            $variants = is_array($price['variants'] ?? null) ? $price['variants'] : ['(scenario absent)' => []];
            foreach (array_keys($variants) as $variant) {
                $cases[$key . ' / ' . $variant] = [$method, $path, $anonymous, $permission, (string) $variant];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool, 3: ?string}>
     */
    public static function writesWithoutPin(): array
    {
        return self::entriesWhere(static fn (array $entry): bool => HtmlRouteHarness::isWrite($entry) && $entry[5] === null);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool, 3: ?string}>
     */
    public static function reauthRoutes(): array
    {
        return self::entriesWhere(static fn (array $entry): bool => $entry[6] === 'password');
    }

    // --- pin = always ---

    #[DataProvider('alwaysPinRoutes')]
    public function testValidFormWithoutCodeIsRefusedWithoutBusinessWrite(string $method, string $path, bool $anonymous, ?string $permission): void
    {
        $scenario = $this->scenario($method, $path);
        [$response, $db] = $this->call($method, $path, $anonymous, $permission, $scenario, HtmlRouteHarness::form($scenario));

        self::assertSame(422, $response->status(), "$method $path sans code personnel devrait etre refusee (formulaire reaffiche, 422), obtenu {$response->status()}.");
        self::assertSame([], HtmlRouteHarness::businessWrites($db), "$method $path sans code personnel ne devrait faire aucune ecriture metier.");
        self::assertSame(1, HtmlRouteHarness::pinFailures($db), "$method $path : le refus devrait venir de la porte du code (une trace pin.failed), pas d'une autre validation.");
    }

    /**
     * Minimisation RGPD (art. 5.1.c) : sur CHAQUE route `pin = always` (toutes
     * les ressources back-office HTML dont le PIN echoue via `PinGate::
     * auditFailedPin()`, cf. PinGate.php), l'adresse SAISIE au champ `pin_email`
     * ne doit jamais apparaitre dans `audit_log` (ni `summary`, ni `details`), et
     * `details.target_user_id` doit porter l'identifiant du compte quand
     * l'adresse en designe un, ou `null` + "adresse inconnue" sinon. Un seul test
     * parametre (le meme montage route-par-route que
     * `testValidFormWithoutCodeIsRefusedWithoutBusinessWrite` ci-dessus) plutot
     * qu'une copie par controleur : la table `alwaysPinRoutes()` couvre deja
     * Order, User, Role, Product (delete), Menu (delete) et Ingredient
     * (inventory + adjust) -- les 6 ressources HTML du PIN d'action sensible.
     */
    #[DataProvider('alwaysPinRoutes')]
    public function testWrongPinNeverLeaksTheAttemptedEmailAndRecordsTargetUserId(string $method, string $path, bool $anonymous, ?string $permission): void
    {
        $scenario = $this->scenario($method, $path);
        $baseForm = HtmlRouteHarness::form($scenario);

        // Cas 1 : l'adresse saisie correspond a un compte EXISTANT -> son
        // identifiant est trace dans `details`, jamais l'adresse.
        [, $dbKnown] = $this->call(
            $method,
            $path,
            $anonymous,
            $permission,
            $scenario,
            $baseForm + ['pin_email' => 'cible@wakdo.local', 'pin' => 'faux'],
            42,
        );
        $writeKnown = $this->pinFailedWrite($dbKnown);
        self::assertNotNull($writeKnown, "$method $path : devrait tracer pin.failed sur PIN invalide.");
        self::assertStringNotContainsString('cible@wakdo.local', (string) $writeKnown['params']['summary'], "$method $path : l'adresse saisie ne doit jamais apparaitre dans summary.");
        self::assertStringNotContainsString('cible@wakdo.local', (string) ($writeKnown['params']['details'] ?? ''), "$method $path : l'adresse saisie ne doit jamais apparaitre dans details.");
        $detailsKnown = json_decode((string) ($writeKnown['params']['details'] ?? '{}'), true);
        self::assertSame(42, $detailsKnown['target_user_id'] ?? null, "$method $path : target_user_id attendu quand l'adresse correspond a un compte existant.");
        self::assertStringNotContainsString('inconnue', (string) $writeKnown['params']['summary'], "$method $path : un compte connu ne doit pas dire « adresse inconnue ».");

        // Cas 2 : l'adresse saisie ne correspond a AUCUN compte -> null + "adresse inconnue".
        [, $dbUnknown] = $this->call(
            $method,
            $path,
            $anonymous,
            $permission,
            $scenario,
            $baseForm + ['pin_email' => 'personne@wakdo.local', 'pin' => 'faux'],
        );
        $writeUnknown = $this->pinFailedWrite($dbUnknown);
        self::assertNotNull($writeUnknown, "$method $path : devrait tracer pin.failed sur PIN invalide.");
        self::assertStringNotContainsString('personne@wakdo.local', (string) $writeUnknown['params']['summary'], "$method $path : l'adresse saisie ne doit jamais apparaitre dans summary.");
        self::assertStringNotContainsString('personne@wakdo.local', (string) ($writeUnknown['params']['details'] ?? ''), "$method $path : l'adresse saisie ne doit jamais apparaitre dans details.");
        $detailsUnknown = json_decode((string) ($writeUnknown['params']['details'] ?? '{}'), true);
        self::assertArrayHasKey('target_user_id', $detailsUnknown, "$method $path : details doit toujours porter la cle target_user_id.");
        self::assertNull($detailsUnknown['target_user_id'], "$method $path : adresse inconnue -> target_user_id null.");
        self::assertStringContainsString('adresse inconnue', (string) $writeUnknown['params']['summary'], "$method $path : adresse inconnue -> le resume doit le dire en clair.");
    }

    #[DataProvider('alwaysPinRoutes')]
    public function testSameFormWithTheCodeIsApplied(string $method, string $path, bool $anonymous, ?string $permission): void
    {
        $scenario = $this->scenario($method, $path);
        [$response, $db] = $this->call($method, $path, $anonymous, $permission, $scenario, HtmlRouteHarness::form($scenario) + HtmlRouteHarness::pinFields());

        $this->assertSucceeded($method . ' ' . $path . ' avec le code', $scenario, $response, $db);
    }

    // --- pin = price ---

    #[DataProvider('priceChangeVariants')]
    public function testSensitiveChangeWithoutCodeIsRefused(string $method, string $path, bool $anonymous, ?string $permission, string $variant): void
    {
        [$price, $form] = $this->priceScenario($method, $path, $variant);
        [$response, $db] = $this->call($method, $path, $anonymous, $permission, $price, $form);

        self::assertSame(422, $response->status(), "$method $path, changement de $variant sans code personnel : refus attendu (422), obtenu {$response->status()}.");
        self::assertSame([], HtmlRouteHarness::businessWrites($db), "$method $path, changement de $variant sans code : aucune ecriture metier.");
        self::assertSame(1, HtmlRouteHarness::pinFailures($db), "$method $path, changement de $variant : le refus devrait venir de la porte du code.");
    }

    #[DataProvider('priceChangeVariants')]
    public function testSensitiveChangeWithTheCodeIsApplied(string $method, string $path, bool $anonymous, ?string $permission, string $variant): void
    {
        [$price, $form] = $this->priceScenario($method, $path, $variant);
        [$response, $db] = $this->call($method, $path, $anonymous, $permission, $price, $form + HtmlRouteHarness::pinFields());

        $this->assertSucceeded("$method $path, changement de $variant avec le code", $price, $response, $db);
    }

    #[DataProvider('pricePinRoutes')]
    public function testChangeTouchingNeitherPriceNorVatNeedsNoCode(string $method, string $path, bool $anonymous, ?string $permission): void
    {
        $scenario = $this->scenario($method, $path);
        [$response, $db] = $this->call($method, $path, $anonymous, $permission, $scenario, HtmlRouteHarness::form($scenario));

        $this->assertSucceeded("$method $path sans changement de prix ni de TVA, sans code", $scenario, $response, $db);
        self::assertSame(0, HtmlRouteHarness::pinFailures($db), "$method $path sans changement de prix ni de TVA ne devrait pas consulter le code.");
    }

    // --- pas d'exigence fantome ---

    #[DataProvider('writesWithoutPin')]
    public function testWriteWithoutAnnouncedCodeSucceedsWithoutOne(string $method, string $path, bool $anonymous, ?string $permission): void
    {
        $scenario = $this->scenario($method, $path);
        [$response, $db] = $this->call($method, $path, $anonymous, $permission, $scenario, HtmlRouteHarness::form($scenario));

        $this->assertSucceeded("$method $path (aucun code annonce), sans code", $scenario, $response, $db);
        self::assertSame(0, HtmlRouteHarness::pinFailures($db), "$method $path n'annonce aucun code personnel mais en a verifie un (trace pin.failed).");
        self::assertStringNotContainsString('PIN invalide', $response->body(), "$method $path n'annonce aucun code personnel mais reclame un PIN.");

        // Si le scenario sait aussi toucher un prix ou une TVA, ce changement-la
        // doit lui aussi passer sans code : sinon la table cacherait un
        // « code personnel sur changement de prix ».
        $price = is_array($scenario['price'] ?? null) ? $scenario['price'] : [];
        /** @var array<string, array<string, string>> $variants */
        $variants = is_array($price['variants'] ?? null) ? $price['variants'] : [];
        foreach (array_keys($variants) as $variant) {
            [$variantScenario, $form] = $this->priceScenario($method, $path, (string) $variant);
            [$response, $db] = $this->call($method, $path, $anonymous, $permission, $variantScenario, $form);
            $this->assertSucceeded("$method $path (aucun code annonce), changement de $variant sans code", $variantScenario, $response, $db);
            self::assertSame(0, HtmlRouteHarness::pinFailures($db), "$method $path n'annonce aucun code personnel mais en exige un pour un changement de $variant.");
        }
    }

    // --- reauth = password ---

    #[DataProvider('reauthRoutes')]
    public function testWithoutCurrentPasswordIsRefusedWithoutWrite(string $method, string $path, bool $anonymous, ?string $permission): void
    {
        $scenario = $this->scenario($method, $path);
        $field = is_string($scenario['reauthField'] ?? null) ? $scenario['reauthField'] : 'current_password';
        $form = HtmlRouteHarness::form($scenario);
        unset($form[$field]);
        [$response, $db] = $this->call($method, $path, $anonymous, $permission, $scenario, $form);

        self::assertSame(422, $response->status(), "$method $path sans le mot de passe courant devrait etre refusee (422), obtenu {$response->status()}.");
        self::assertSame([], HtmlRouteHarness::writes($db), "$method $path sans le mot de passe courant ne devrait rien ecrire.");
    }

    // --- aides ---

    /**
     * @param \Closure(array{0: string, 1: string, 2: bool, 3: ?string, 4: ?string, 5: ?string, 6: ?string}): bool $keep
     * @return array<string, array{0: string, 1: string, 2: bool, 3: ?string}>
     */
    private static function entriesWhere(\Closure $keep): array
    {
        $cases = [];
        foreach (HtmlRouteHarness::boEntries() as $entry) {
            if ($keep($entry)) {
                $cases[HtmlRouteHarness::key($entry)] = [$entry[0], $entry[1], $entry[2], $entry[3]];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, mixed>
     */
    private function scenario(string $method, string $path): array
    {
        $scenario = HtmlRouteHarness::scenario($method . ' ' . $path);
        self::assertNotNull($scenario, "$method $path : aucun scenario de reussite dans HtmlRouteHarness::scenarios() -- impossible de prouver l'exigence de code sans formulaire valide.");

        return $scenario;
    }

    /**
     * Variante « changement sensible » d'une route `pin = price` : le formulaire
     * de base, surcharge par les champs de la variante, avec la preparation
     * propre a la variante.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function priceScenario(string $method, string $path, string $variant): array
    {
        $base = $this->scenario($method, $path);
        self::assertIsArray($base['price'] ?? null, "$method $path annonce pin = price : le scenario doit decrire un changement de prix (cle 'price').");
        /** @var array<string, mixed> $price */
        $price = $base['price'];
        /** @var array<string, array<string, string>> $variants */
        $variants = $price['variants'] ?? [];
        self::assertArrayHasKey($variant, $variants);

        $scenario = $price + ['form' => $base['form'] ?? []];
        $form = $variants[$variant] + HtmlRouteHarness::form($scenario);

        return [$scenario, $form];
    }

    /**
     * @param array<string, mixed> $scenario
     * @param array<string, string> $form sans _csrf, ajoute ici
     * @return array{0: Response, 1: FakeDatabase}
     */
    /**
     * @param array<string, mixed> $scenario
     * @param array<string, string> $form sans _csrf, ajoute ici
     * @param int|null $pinFailedTargetUserId compte EXISTANT simule pour la
     *        recherche cible de `PinGate::auditFailedPin()` (minimisation RGPD) ;
     *        null (defaut, deja la valeur par defaut de `FakeDatabase::
     *        $pinFailedTargetUserRow`) = adresse ne correspondant a aucun compte.
     * @return array{0: Response, 1: FakeDatabase}
     */
    private function call(string $method, string $path, bool $anonymous, ?string $permission, array $scenario, array $form, ?int $pinFailedTargetUserId = null): array
    {
        $session = $anonymous ? HtmlRouteHarness::anonymousSession() : HtmlRouteHarness::authenticatedSession();
        /** @var list<string> $extra */
        $extra = is_array($scenario['extraPerms'] ?? null) ? $scenario['extraPerms'] : [];
        $db = HtmlRouteHarness::grantedDb(array_values(array_unique(array_merge($permission === null ? [] : [$permission], $extra))));
        HtmlRouteHarness::primeWorld($db, $method, $path);
        $db->pinFailedTargetUserRow = $pinFailedTargetUserId !== null ? ['target_user_id' => $pinFailedTargetUserId] : null;
        $form['_csrf'] = Csrf::token($session);

        return [HtmlRouteHarness::exercise($method, $path, $session, $db, $form, $scenario), $db];
    }

    /**
     * @return array{sql: string, params: array<string, mixed>}|null
     */
    private function pinFailedWrite(FakeDatabase $db): ?array
    {
        foreach ($db->writes as $write) {
            if (str_contains($write['sql'], 'INSERT INTO audit_log') && ($write['params']['code'] ?? null) === 'pin.failed') {
                return $write;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $scenario
     */
    private function assertSucceeded(string $label, array $scenario, Response $response, FakeDatabase $db): void
    {
        $expected = is_int($scenario['success'] ?? null) ? $scenario['success'] : 302;
        self::assertSame($expected, $response->status(), "$label : reussite attendue ($expected), obtenu {$response->status()}.");
        $location = $scenario['location'] ?? null;
        if (is_string($location)) {
            self::assertSame($location, $response->header('Location'), "$label : redirection de reussite attendue vers $location.");
        } elseif ($expected === 302) {
            self::assertNotSame('/login', $response->header('Location'), "$label : renvoye vers /login au lieu de reussir.");
        }
        $needle = $scenario['write'] ?? null;
        if (is_string($needle)) {
            $found = array_filter(HtmlRouteHarness::businessWrites($db), static fn (string $sql): bool => str_contains($sql, $needle));
            self::assertNotSame([], $found, "$label : l'ecriture metier « $needle » est attendue, aucune enregistree.");
        }
    }
}
