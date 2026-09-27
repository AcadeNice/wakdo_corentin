<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Catalogue\MenuRepository;
use App\Catalogue\ProductRepository;
use App\Core\Config;
use App\Core\Database;
use App\Order\OrderQueryRepository;
use App\Order\OrderRepository;

/**
 * Verifie contre une vraie MariaDB (schema migre + seede) le lot ADR-0020 (le
 * responsable lit et annule les commandes, en remplacement de la decision D5 sur
 * ce seul point) : migration 0018 + role_permission manager du seed 0001.
 *
 * Ce que ce fichier prouve :
 *  - la migration 0018 accorde bien order.read + order.cancel a un role manager
 *    qui ne les detient pas encore (installation en service, avant le lot) ;
 *  - rejouee sur un manager deja a jour, elle ne touche aucune ligne
 *    (idempotence) ;
 *  - elle n'ecrase jamais une description modifiee a la main depuis (garde par
 *    le texte francais EXACT pose par la migration 0012) ;
 *  - le garde compare octet par octet (COLLATE utf8mb4_bin) : une description
 *    qui ne differe du texte garde QUE par ses accents est une retouche a la
 *    main, et elle n'est pas ecrasee (la collation par defaut utf8mb4_unicode_ci,
 *    insensible aux accents, l'aurait ecrasee) ;
 *  - une installation NEUVE (seed 0001 seul) et une installation en service
 *    passee par la migration 0018 aboutissent EXACTEMENT au meme ensemble de
 *    permissions manager ;
 *  - le manager ne porte aucune ligne role_visible_source (verifie, pas
 *    suppose) : sa vue des commandes reste globale, comme admin ;
 *  - le manager REEL du seed de demonstration (0009) peut annuler une commande
 *    payee avec son PROPRE identifiant, et la ligne d'audit qui en resulte porte
 *    bien son nom -- via `OrderRepository::cancel()`, la meme methode de domaine
 *    qu'appellent `OrderAdminController::cancel()` (formulaire HTML) ET
 *    `OrderApiController::apiCancel()` (POST /admin/api/orders/{number}/cancel)
 *    une fois la garde de permission (`Authorizer::can`, prouvee identique pour
 *    les deux points d'entree et deja verifiee par
 *    `RouteMatrixRoleDbTest::testDemoAccountPermissionMatchesDocumentedMatrix`)
 *    et le PIN franchis.
 *
 * Auto-skip si WAKDO_DB_TESTS != 1 ou base injoignable (meme garde que les
 * autres tests de tests/Integration/).
 */
final class ManagerOrderCancelMigrationDbTest extends TestCase
{
    /** Texte francais exact pose par la migration 0012 -- garde de la migration 0018. */
    private const ORIGINAL_DESCRIPTION = 'Création et mise à jour du catalogue, gestion des ingrédients et du stock (réapprovisionnement et inventaire), statistiques. Ni administration des utilisateurs/rôles, ni annulation de commande.';

    /**
     * Ensemble de permissions manager attendu APRES le lot ADR-0020 (13 + les deux
     * nouvelles). Donnee brute dupliquee volontairement (meme convention que
     * RouteMatrixRoleDbTest::ROLE_PERMISSIONS) : pas de source partagee entre
     * fichiers de test.
     *
     * @var list<string>
     */
    private const EXPECTED_MANAGER_PERMISSIONS = [
        'product.create', 'product.read', 'product.update',
        'menu.create', 'menu.read', 'menu.update',
        'category.manage', 'ingredient.manage',
        'stock.read', 'stock.count', 'stock.manage',
        'order.read', 'order.cancel',
        'user.read',
        'stats.read',
    ];

    private Database $db;
    private int $managerRoleId = 0;

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

        $roleRow = $this->db->fetch("SELECT id FROM role WHERE code = 'manager'");
        if ($roleRow === null) {
            self::markTestSkipped("role 'manager' introuvable (seed 0001 non joue ?).");
        }
        $this->managerRoleId = (int) $roleRow['id'];
    }

    /**
     * @return list<string>
     */
    private function managerPermissionCodes(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT p.code FROM role_permission rp JOIN permission p ON p.id = rp.permission_id '
            . 'WHERE rp.role_id = :r ORDER BY p.code',
            ['r' => $this->managerRoleId],
        );

        return array_map(static fn (array $row): string => (string) $row['code'], $rows);
    }

    public function testMigrationGrantsTheTwoPermissionsWhenMissing(): void
    {
        // Simule l'installation en service que la migration 0018 doit corriger :
        // order.read/order.cancel absents du manager (etat d'avant le lot).
        $this->db->execute(
            "DELETE FROM role_permission WHERE role_id = :r AND permission_id IN "
            . "(SELECT id FROM permission WHERE code IN ('order.read', 'order.cancel'))",
            ['r' => $this->managerRoleId],
        );

        try {
            self::assertNotContains('order.read', $this->managerPermissionCodes(), 'precondition : order.read doit etre absent');
            self::assertNotContains('order.cancel', $this->managerPermissionCodes(), 'precondition : order.cancel doit etre absent');

            $affected = $this->executeSqlStatements($this->readMigrationSql('0018_manager_order_cancel.sql'));

            self::assertGreaterThanOrEqual(2, $affected, 'la migration doit inserer au moins les deux lignes role_permission');
            $codes = $this->managerPermissionCodes();
            self::assertContains('order.read', $codes);
            self::assertContains('order.cancel', $codes);
        } finally {
            // Restaure l'etat attendu par le reste de la suite (base partagee).
            $this->db->execute(
                'INSERT IGNORE INTO role_permission (role_id, permission_id) '
                . "SELECT :r, id FROM permission WHERE code IN ('order.read', 'order.cancel')",
                ['r' => $this->managerRoleId],
            );
        }
    }

    public function testMigrationReplayIsANoOpOnceGranted(): void
    {
        // Precondition : le manager detient deja les deux permissions (seed 0001)
        // et la description est deja au texte pose par CETTE migration (pas le
        // texte garde -- rejouer un normal etat post-migration ne doit rien faire).
        self::assertContains('order.read', $this->managerPermissionCodes(), 'precondition : seed 0001 doit deja accorder order.read');
        self::assertContains('order.cancel', $this->managerPermissionCodes(), 'precondition : seed 0001 doit deja accorder order.cancel');

        $affected = $this->executeSqlStatements($this->readMigrationSql('0018_manager_order_cancel.sql'));

        self::assertSame(0, $affected, 'rejouee sur un manager deja a jour, la migration ne doit toucher aucune ligne');
    }

    public function testMigrationDoesNotOverwriteADescriptionModifiedByHand(): void
    {
        $original = $this->db->fetch("SELECT description FROM role WHERE code = 'manager'");
        self::assertNotNull($original, "precondition : role 'manager' introuvable");

        $this->db->execute(
            "UPDATE role SET description = :d WHERE code = 'manager'",
            ['d' => 'Personnalise depuis le back-office en production'],
        );

        try {
            $affected = $this->executeSqlStatements($this->readMigrationSql('0018_manager_order_cancel.sql'));

            // Les deux grants sont idempotents (deja poses par le seed) : seule la
            // ligne de description aurait pu bouger, et elle ne doit pas.
            self::assertSame(0, $affected, 'une description personnalisee ne doit pas etre ecrasee par un rejeu');

            $customized = $this->db->fetch("SELECT description FROM role WHERE code = 'manager'");
            self::assertSame('Personnalise depuis le back-office en production', $customized['description'] ?? null);
        } finally {
            $this->db->execute(
                "UPDATE role SET description = :d WHERE code = 'manager'",
                ['d' => (string) $original['description']],
            );
        }
    }

    public function testGuardIsByteExactSoAnAccentOnlyEditIsNotOverwritten(): void
    {
        // La collation de la colonne, utf8mb4_unicode_ci, est insensible a la CASSE
        // et AUX ACCENTS : un garde `description = '...'` ferait correspondre une
        // description retouchee sur un seul accent, et l'ecraserait. Le garde de la
        // migration 0018 compare donc en COLLATE utf8mb4_bin, octet par octet.
        $original = $this->db->fetch("SELECT description FROM role WHERE code = 'manager'");
        self::assertNotNull($original, "precondition : role 'manager' introuvable");

        $accentStripped = strtr(self::ORIGINAL_DESCRIPTION, [
            'é' => 'e', 'è' => 'e', 'à' => 'a', 'ô' => 'o', 'û' => 'u', 'ç' => 'c',
        ]);
        self::assertNotSame($accentStripped, self::ORIGINAL_DESCRIPTION, 'precondition : la variante doit reellement differer par des accents');

        $this->db->execute("UPDATE role SET description = :d WHERE code = 'manager'", ['d' => $accentStripped]);

        try {
            $this->executeSqlStatements($this->readMigrationSql('0018_manager_order_cancel.sql'));

            $after = (string) ($this->db->fetch("SELECT description FROM role WHERE code = 'manager'")['description'] ?? '');
            self::assertSame(
                $accentStripped,
                $after,
                'une description retouchee sur ses seuls accents ne doit pas etre ecrasee : le garde doit comparer octet par octet',
            );
        } finally {
            $this->db->execute(
                "UPDATE role SET description = :d WHERE code = 'manager'",
                ['d' => (string) $original['description']],
            );
        }
    }

    public function testFreshInstallAndMigrationProduceTheSameManagerPermissionSet(): void
    {
        $expected = self::EXPECTED_MANAGER_PERMISSIONS;
        sort($expected);

        // 1. Installation NEUVE : l'etat actuel de la base de test vient du seed
        //    0001 seul (les migrations tournent sur des tables encore vides).
        $fromSeed = $this->managerPermissionCodes();
        self::assertSame($expected, $fromSeed, 'seed 0001 : ensemble de permissions manager inattendu');

        // 2. Installation EN SERVICE : on retire les deux permissions ADR-0020 pour
        //    simuler une base migree AVANT le lot, on rejoue la migration 0018, et
        //    on verifie qu'elle aboutit EXACTEMENT au meme ensemble que le seed.
        $this->db->execute(
            "DELETE FROM role_permission WHERE role_id = :r AND permission_id IN "
            . "(SELECT id FROM permission WHERE code IN ('order.read', 'order.cancel'))",
            ['r' => $this->managerRoleId],
        );

        try {
            $this->executeSqlStatements($this->readMigrationSql('0018_manager_order_cancel.sql'));
            $fromMigration = $this->managerPermissionCodes();
            self::assertSame($expected, $fromMigration, 'migration 0018 : ensemble de permissions manager different du seed 0001');
        } finally {
            $this->db->execute(
                'INSERT IGNORE INTO role_permission (role_id, permission_id) '
                . "SELECT :r, id FROM permission WHERE code IN ('order.read', 'order.cancel')",
                ['r' => $this->managerRoleId],
            );
        }
    }

    public function testManagerHasNoRoleVisibleSourceRowsGlobalView(): void
    {
        // Verifie, pas suppose (ADR-0020) : le manager ne porte aucune ligne
        // role_visible_source -- sa vue des commandes reste globale, comme admin.
        $rows = $this->db->fetchAll('SELECT source FROM role_visible_source WHERE role_id = :r', ['r' => $this->managerRoleId]);
        self::assertSame([], $rows, 'le manager ne doit porter aucune ligne role_visible_source (vue globale, ADR-0020)');

        // Confirmation au niveau du domaine reellement utilise par /admin/orders et
        // /kitchen/display (OrderQueryRepository::visibleSources()) : absence de
        // lignes -> les 3 sources, pas un filtre partiel.
        $orderQuery = new OrderQueryRepository($this->db);
        $sources = $orderQuery->visibleSources($this->managerRoleId);
        sort($sources);
        self::assertSame(['counter', 'drive', 'kiosk'], $sources);
    }

    public function testManagerCanCancelAPaidOrderWithOwnCodeAndAuditIsAttributedToThem(): void
    {
        // Compte de demonstration REEL (seed 0009) : email public, cf.
        // docs/demo/comptes-demo.md. Auto-skip si ce seed n'a pas ete rejoue.
        $account = $this->db->fetch(
            'SELECT u.id, u.role_id, r.code FROM user u JOIN role r ON r.id = u.role_id '
            . 'WHERE u.email = :email AND u.is_active = 1',
            ['email' => 'manager@wakdo.local'],
        );
        if ($account === null || (string) ($account['code'] ?? '') !== 'manager') {
            self::markTestSkipped("compte de demonstration 'manager@wakdo.local' introuvable (seed 0009 joue ?).");
        }
        $managerId = (int) $account['id'];
        $managerRoleId = (int) $account['role_id'];

        $suffix = bin2hex(random_bytes(4));
        $number = 'IT-' . $suffix . '-C';
        $this->db->execute(
            'INSERT INTO customer_order (order_number, idempotency_key, source, service_mode, status, '
            . 'total_ht_cents, total_vat_cents, total_ttc_cents) '
            . "VALUES (:num, :key, 'counter', 'takeaway', 'paid', 900, 100, 1000)",
            ['num' => $number, 'key' => $number . '-k'],
        );
        $orderId = (int) ($this->db->fetch('SELECT id FROM customer_order WHERE order_number = :n', ['n' => $number])['id'] ?? 0);
        self::assertGreaterThan(0, $orderId, 'precondition : la commande jetable doit etre inseree');

        try {
            // Meme methode de domaine que `OrderAdminController::cancel()` (formulaire
            // HTML, apres guard('order.cancel') + PIN) ET
            // `OrderApiController::apiCancel()` (POST /admin/api/orders/{number}/cancel,
            // apres guardApi('order.cancel') + PIN) invoquent l'une comme l'autre une
            // fois ces gardes franchies -- prouve ici avec l'identite REELLE du manager,
            // pas un acteur synthetique. La garde de permission elle-meme (identique
            // pour les deux points d'entree, `Authorizer::can`) est prouvee par
            // RouteMatrixRoleDbTest::testDemoAccountPermissionMatchesDocumentedMatrix
            // pour la route API ; ce test-ci ferme la boucle sur l'attribution d'audit.
            $repo = new OrderRepository($this->db, new ProductRepository($this->db), new MenuRepository($this->db));
            $result = $repo->cancel($number, $managerId, $managerRoleId);

            self::assertSame('cancelled', $result['status']);
            $status = (string) ($this->db->fetch('SELECT status FROM customer_order WHERE id = :id', ['id' => $orderId])['status'] ?? '');
            self::assertSame('cancelled', $status);

            $audit = $this->db->fetch(
                "SELECT actor_user_id, actor_role_id, action_code FROM audit_log "
                . "WHERE entity_type = 'customer_order' AND entity_id = :id AND action_code = 'order.cancel' "
                . 'ORDER BY id DESC LIMIT 1',
                ['id' => $orderId],
            );
            self::assertNotNull($audit, "l'annulation doit ecrire une ligne d'audit (RG-T14)");
            self::assertSame($managerId, (int) ($audit['actor_user_id'] ?? 0), "l'audit doit porter l'identifiant du manager, pas un autre acteur");
            self::assertSame($managerRoleId, (int) ($audit['actor_role_id'] ?? 0));
        } finally {
            $this->db->execute(
                "DELETE FROM audit_log WHERE entity_type = 'customer_order' AND entity_id = :id AND action_code = 'order.cancel'",
                ['id' => $orderId],
            );
            $this->db->execute('DELETE FROM customer_order WHERE id = :id', ['id' => $orderId]);
        }
    }

    private function readMigrationSql(string $filename): string
    {
        $path = __DIR__ . '/../../db/migrations/' . $filename;
        $sql = file_get_contents($path);
        self::assertNotFalse($sql, 'migration ' . $filename . ' introuvable a ' . $path);

        return $sql;
    }

    /**
     * Meme decoupage/execution que ReferenceDataFrenchDbTest /
     * IngredientFamilyMigrationDbTest (retire les commentaires -- ';' de
     * ponctuation francaise -- puis coupe sur ';' HORS chaine SQL). Duplique
     * volontairement (pas de trait partage pour ce petit runner de migration,
     * coherent avec le reste du projet).
     */
    private function executeSqlStatements(string $sql): int
    {
        $withoutComments = (string) preg_replace('/^[ \t]*--.*$/m', '', $sql);

        $affected = 0;
        foreach ($this->splitSqlStatements($withoutComments) as $statement) {
            $statement = trim($statement);
            if ($statement === '' || stripos($statement, 'SET NAMES') === 0) {
                continue;
            }
            $affected += $this->db->execute($statement);
        }

        return $affected;
    }

    /**
     * @return list<string>
     */
    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inString = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($char === "'") {
                if ($inString && ($sql[$i + 1] ?? '') === "'") {
                    $current .= "''";
                    $i++;
                    continue;
                }
                $inString = !$inString;
                $current .= $char;
                continue;
            }

            if ($char === ';' && !$inString) {
                $statements[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }
        if (trim($current) !== '') {
            $statements[] = $current;
        }

        return $statements;
    }
}
