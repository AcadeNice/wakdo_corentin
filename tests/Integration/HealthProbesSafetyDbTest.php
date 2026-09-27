<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Health\Probes;

/**
 * Preuve d'innocuite des sept sondes de `App\Health\Probes` (contrat section 4)
 * contre la VRAIE pile : vrai `Router` charge avec les vraies routes
 * (`src/app/Core/routes.php`, chantier A) et les vrais controleurs (aucune
 * sous-classe de test), une vraie MariaDB jetable (migree + seedee par
 * php-tests.sh -- "base factice" au sens d'une base jetable, pas d'un double en
 * memoire : le Router instancie les controleurs lui-meme avec un
 * `App\Core\Database` CONCRET, ce qui exclut par construction d'y glisser un
 * double de `DatabaseInterface`).
 *
 * L'identite/session est portee directement par $_SESSION (pas par un
 * SessionManager en mode test) : c'est la seule maniere de faire passer une
 * session par les VRAIS controleurs, qui instancient toujours
 * `new SessionManager($this->config)` (mode production) -- sans appeler
 * `SessionManager::start()`, qui ouvrirait une vraie session PHP (interdit sous
 * `beStrictAboutOutputDuringTests`). $_SESSION est restaure a sa valeur
 * d'origine en tearDown.
 *
 * Pour chaque sonde, prouve DEUX choses : (1) le statut/code d'erreur annonce
 * par la sonde est bien celui que la pile reelle renvoie ; (2) AUCUNE commande
 * d'ecriture SQL (INSERT/UPDATE/DELETE/REPLACE, formes simples ou
 * *_SELECT/*_MULTI) n'a ete executee -- mesure par les compteurs cumulatifs de
 * MariaDB (`information_schema.GLOBAL_STATUS`), pas par un comptage de lignes
 * (qui laisserait passer une ecriture sans effet net, ex. un UPDATE qui remet
 * la meme valeur).
 */
final class HealthProbesSafetyDbTest extends TestCase
{
    private const ROUTES_FILE = __DIR__ . '/../../src/app/Core/routes.php';

    private Database $db;
    private Router $router;
    private int $userId = 0;
    private int $roleManageId = 0;
    private string $csrfToken = '';

    /** @var array<string, mixed>|null */
    private ?array $sessionBackup = null;

    /** @var list<string> */
    private array $touchedKeys = [];

    protected function setUp(): void
    {
        if (getenv('WAKDO_DB_TESTS') !== '1') {
            self::markTestSkipped('Tests DB desactives (definir WAKDO_DB_TESTS=1 + DB_*).');
        }

        $this->db = new Database(new Config());
        try {
            $this->db->fetch('SELECT 1');
        } catch (Throwable $exception) {
            self::markTestSkipped('Base injoignable: ' . $exception->getMessage());
        }

        if (!is_file(self::ROUTES_FILE)) {
            self::fail(
                "src/app/Core/routes.php est attendu par ce test (contrat commun, chantier A section 1) "
                . 'et est absent de cette copie : ce test ne peut pas encore etre execute.',
            );
        }

        $roleRow = $this->db->fetch("SELECT id FROM role WHERE code = 'admin'");
        self::assertNotNull($roleRow, "role 'admin' introuvable (seed 0001_rbac_and_reference.sql joue ?).");
        $this->roleManageId = (int) $roleRow['id'];

        $userRow = $this->db->fetch('SELECT id FROM user WHERE is_active = 1 LIMIT 1');
        self::assertNotNull($userRow, 'Aucun utilisateur actif en base (seed 0009_demo_accounts.sql joue ?).');
        $this->userId = (int) $userRow['id'];

        $this->setEnv('SESSION_LIFETIME_IDLE', '14400');
        $this->setEnv('SESSION_LIFETIME_ABSOLUTE', '36000');

        $this->sessionBackup = isset($_SESSION) ? $_SESSION : null;
        $this->csrfToken = bin2hex(random_bytes(32));

        $config = new Config();
        $this->router = new Router($config, new Database($config));
        (require self::ROUTES_FILE)($this->router);
    }

    protected function tearDown(): void
    {
        if ($this->sessionBackup !== null) {
            $_SESSION = $this->sessionBackup;
        } else {
            unset($_SESSION);
        }

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

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function probeProvider(): iterable
    {
        foreach (Probes::all() as $probe) {
            yield $probe['id'] => [$probe];
        }
    }

    /**
     * @param array<string, mixed> $probe
     */
    #[DataProvider('probeProvider')]
    public function testProbeIsSafeAgainstTheRealStack(array $probe): void
    {
        $this->applySession((string) $probe['credentials']);

        $headers = [];
        if ($probe['contentType'] !== null) {
            $headers['content-type'] = (string) $probe['contentType'];
        }
        if ($probe['sendCsrf'] === true) {
            $headers['x-csrf-token'] = $this->csrfToken;
        }

        $request = new Request(
            (string) $probe['method'],
            (string) $probe['url'],
            [],
            $headers,
            (string) ($probe['body'] ?? ''),
        );

        $writesBefore = $this->writeCommandTotal();
        $response = $this->router->dispatch($request);
        $writesAfter = $this->writeCommandTotal();

        self::assertSame(
            $probe['expect'],
            $response->status(),
            "Sonde '{$probe['id']}' : statut inattendu (corps recu : {$response->body()}).",
        );

        if ($probe['expectCode'] !== null) {
            $payload = json_decode($response->body(), true);
            self::assertIsArray($payload, "Sonde '{$probe['id']}' : corps de reponse non-JSON.");
            self::assertSame(
                $probe['expectCode'],
                $payload['error']['code'] ?? null,
                "Sonde '{$probe['id']}' : code d'erreur inattendu (corps recu : {$response->body()}).",
            );
        }

        self::assertSame(
            $writesBefore,
            $writesAfter,
            "Sonde '{$probe['id']}' : une requete d'ecriture (INSERT/UPDATE/DELETE/REPLACE) a ete executee alors qu'aucune n'est attendue.",
        );
    }

    private function applySession(string $credentials): void
    {
        if ($credentials === 'omit') {
            $_SESSION = [];

            return;
        }

        $now = time();
        $_SESSION = [
            'user_id'       => $this->userId,
            'role_id'       => $this->roleManageId,
            'logged_in_at'  => $now - 100,
            'last_activity' => $now - 50,
            '_csrf'         => $this->csrfToken,
        ];
    }

    /**
     * Somme des compteurs cumulatifs (depuis le demarrage du serveur) des
     * commandes d'ecriture SQL, lus dans `information_schema.GLOBAL_STATUS` :
     * la preuve la plus directe qu'aucune commande INSERT/UPDATE/DELETE/REPLACE
     * n'a ete executee entre deux releves, quel que soit son effet net sur les
     * lignes (contrairement a un comptage `COUNT(*)` avant/apres, qui laisserait
     * passer une ecriture sans effet observable).
     */
    private function writeCommandTotal(): int
    {
        $rows = $this->db->fetchAll(
            'SELECT VARIABLE_NAME, VARIABLE_VALUE FROM information_schema.GLOBAL_STATUS '
            . "WHERE VARIABLE_NAME IN ('COM_INSERT', 'COM_INSERT_SELECT', 'COM_REPLACE', "
            . "'COM_REPLACE_SELECT', 'COM_UPDATE', 'COM_UPDATE_MULTI', 'COM_DELETE', 'COM_DELETE_MULTI')",
        );

        $total = 0;
        foreach ($rows as $row) {
            $total += (int) ($row['VARIABLE_VALUE'] ?? 0);
        }

        return $total;
    }
}
