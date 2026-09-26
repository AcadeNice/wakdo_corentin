<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Core\Config;
use App\Core\Database;

/**
 * Verifie contre une vraie MariaDB (schema migre + seed de reference joues) que les
 * donnees de reference montrees aux equipiers sont en francais, capitalisees, et
 * factuellement a jour -- corrections F40 (section "Textes techniques ou en anglais"
 * de defauts-visibles.md) + E14 + E15 (audit schemas 6.3) + les accents des donnees
 * de demonstration (migration 0016 : allergenes lus par le CLIENT dans la modale de
 * la borne, noms de produits, noms et unites d'ingredients). Verifie aussi que les
 * migrations 0012/0013/0014/0015/0016 sont reellement idempotentes : rejouees, elles ne
 * touchent aucune ligne et ne peuvent pas ecraser une personnalisation faite en
 * production (relecture independante du 24/09, garde par la valeur ANGLAISE
 * D'ORIGINE sur chaque UPDATE).
 *
 * Ne cree ni ne nettoie aucune fixture pour les tests de contenu : lit les lignes
 * deja posees par les seeds (0001, 0002, 0007) puis mises a jour par les migrations.
 * Les tests de rejeu MODIFIENT temporairement une ligne partagee (base non isolee
 * par test) et la restaurent dans un `finally`, pour ne pas casser les autres tests
 * de ce fichier ni d'autres suites qui liraient la meme donnee.
 *
 * Auto-skip si WAKDO_DB_TESTS != 1 (meme garde que CatalogueReadDbTest).
 */
final class ReferenceDataFrenchDbTest extends TestCase
{
    private Database $db;

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
    }

    public function testRoleLabelsAndDescriptionsAreFrench(): void
    {
        $rows = $this->db->fetchAll('SELECT code, label, description FROM role ORDER BY id');
        self::assertNotEmpty($rows, 'seed 0001 doit avoir pose les 5 roles');

        $byCode = [];
        foreach ($rows as $row) {
            $byCode[(string) $row['code']] = $row;
        }

        self::assertSame('Administrateur', $byCode['admin']['label'] ?? null);
        self::assertSame('Responsable', $byCode['manager']['label'] ?? null);
        self::assertSame('Équipier cuisine', $byCode['kitchen']['label'] ?? null);
        self::assertSame('Équipier comptoir', $byCode['counter']['label'] ?? null);
        self::assertSame('Équipier drive', $byCode['drive']['label'] ?? null);

        // Aucune trace des libelles anglais d'origine (regression F40, section
        // "Textes techniques ou en anglais" de defauts-visibles.md).
        foreach ($rows as $row) {
            self::assertNotSame('Administrator', $row['label']);
            self::assertNotSame('Kitchen Staff', $row['label']);
            self::assertNotSame('Counter Staff', $row['label']);
            self::assertNotSame('Drive Staff', $row['label']);
        }

        // E14 : la description kitchen reflete la capacite reelle (avance l'etat de
        // preparation via order.read), pas l'ancienne affirmation fausse "ne fait
        // aucune transition de statut".
        $kitchenDescription = (string) ($byCode['kitchen']['description'] ?? '');
        self::assertStringContainsString('order.read', $kitchenDescription);
        self::assertStringContainsString('préparation', $kitchenDescription);
        self::assertStringNotContainsString('no order status transition', $kitchenDescription);
        self::assertStringNotContainsString('aucune transition', $kitchenDescription);
    }

    public function testCategoryNamesAreCapitalized(): void
    {
        $rows = $this->db->fetchAll(
            "SELECT name, slug FROM category WHERE slug IN ('menus', 'boissons', 'burgers', 'frites', 'encas', 'wraps', 'salades', 'desserts', 'sauces')",
        );
        self::assertNotEmpty($rows, 'seed 0002 doit avoir pose les 9 categories de demonstration');

        foreach ($rows as $row) {
            $name = (string) $row['name'];
            $slug = (string) $row['slug'];
            // slug reste en minuscules (identifiant technique, routage) ; name est le
            // libelle affiche, capitalise (regression F40, section "Textes techniques
            // ou en anglais" de defauts-visibles.md : "menus" en minuscules).
            self::assertSame(mb_strtolower($slug), $slug, 'slug ' . $slug . ' inchange');
            self::assertSame(mb_strtoupper(mb_substr($name, 0, 1)), mb_substr($name, 0, 1), 'name ' . $name . ' capitalise');
            self::assertNotSame(mb_strtolower($name), $name, 'name ' . $name . ' ne doit plus etre tout en minuscules');
        }
    }

    public function testAllergenLabelsAndDescriptionsAreAccented(): void
    {
        // Migration 0016 : le texte des allergenes est LU PAR LE CLIENT dans la modale
        // de la borne (preuve DOM produits-modale-allergenes.html) ; il etait saisi
        // sans accents ("Cereales", "Oeufs", "Graines de sesame", "superieure").
        $rows = $this->db->fetchAll('SELECT code, name, description FROM allergen ORDER BY id');
        self::assertCount(14, $rows, 'seed 0001 doit avoir pose les 14 allergenes INCO');

        $byCode = [];
        foreach ($rows as $row) {
            $byCode[(string) $row['code']] = $row;
        }

        self::assertSame('Crustacés', $byCode['crustaceans']['name'] ?? null);
        self::assertSame('Œufs', $byCode['eggs']['name'] ?? null);
        self::assertSame('Céleri', $byCode['celery']['name'] ?? null);
        self::assertSame('Graines de sésame', $byCode['sesame']['name'] ?? null);
        self::assertSame('Fruits à coque', $byCode['nuts']['name'] ?? null);

        self::assertStringContainsString('Céréales', (string) ($byCode['gluten']['description'] ?? ''));
        self::assertStringContainsString('épeautre', (string) ($byCode['gluten']['description'] ?? ''));
        self::assertStringContainsString('pécan', (string) ($byCode['nuts']['description'] ?? ''));
        self::assertStringContainsString('supérieure', (string) ($byCode['sulphites']['description'] ?? ''));

        // Aucune trace des formes non accentuees, name comme description. La
        // comparaison PHP est sensible aux accents (contrairement a la collation
        // utf8mb4_unicode_ci de la base), donc ce garde mord vraiment.
        foreach ($rows as $row) {
            $texte = (string) $row['name'] . ' ' . (string) ($row['description'] ?? '');
            foreach (['Cereales', 'Oeufs', 'Crustaces', 'Celeri', 'sesame et produits', 'Fruits a coque', 'a base de', 'superieure', 'pecan', 'Bresil'] as $fauteur) {
                self::assertStringNotContainsString($fauteur, $texte, 'allergene ' . $row['code'] . ' : "' . $fauteur . '" sans accent');
            }
        }

        // allergen.code reste l'identifiant technique en anglais, non affiche.
        self::assertSame('eggs', $byCode['eggs']['code'] ?? null);
    }

    public function testDemoProductAndIngredientNamesAreAccented(): void
    {
        // Migration 0016 : noms affiches sur la borne (produits) et dans la page
        // stock (ingredients). La variante 50 cl est derivee du nom de base par le
        // seed 0005 (CONCAT(name, ' 50cl')) : elle portait donc la meme faute.
        // La collation utf8mb4_unicode_ci est insensible aux accents : une lecture par
        // nom ramenerait la ligne fautive aussi. On relit donc le `name` REEL et on le
        // compare en PHP, ou l'accent compte.
        foreach (['Ice Tea Pêche', 'Ice Tea Pêche 50cl', 'César Classic', 'MC Wrap Chèvre', 'Ptit Wrap Chèvre'] as $nom) {
            $row = $this->db->fetch('SELECT name FROM product WHERE name = :name', ['name' => $nom]);
            self::assertNotNull($row, 'produit "' . $nom . '" introuvable');
            self::assertSame($nom, (string) $row['name'], 'produit "' . $nom . '" doit porter ses accents en base');
        }

        foreach (['Dose Ice Tea Pêche', "Dose Jus d'Orange", 'Filet de poulet pané', 'Fromage de chèvre', 'Pain sésame', 'Steak haché'] as $nom) {
            $row = $this->db->fetch('SELECT name FROM ingredient WHERE name = :name', ['name' => $nom]);
            self::assertNotNull($row, 'ingredient "' . $nom . '" introuvable');
            self::assertSame($nom, (string) $row['name'], 'ingredient "' . $nom . '" doit porter ses accents en base');
        }
    }

    public function testIngredientUnitsAreDisplayableFrench(): void
    {
        // ingredient.unit est du texte libre AFFICHE tel quel a cote du stock
        // (Views/admin/ingredients/adjust.php) et le formulaire propose deja
        // "pièce" accentué en exemple : la donnee de demonstration doit suivre.
        $units = array_column($this->db->fetchAll('SELECT DISTINCT unit FROM ingredient ORDER BY unit'), 'unit');
        self::assertNotEmpty($units, 'seed 0003 doit avoir pose les ingredients');

        self::assertContains('pièce', $units);
        self::assertNotContains('piece', $units);
    }

    public function testOrderCancelPermissionDescriptionMatchesTheDomain(): void
    {
        // E15 (audit schemas 6.3) : le domaine (OrderRepository::cancel) accepte
        // pending_payment, paid, preparing ET ready -- la description ne doit plus
        // se limiter a "pending or paid".
        $row = $this->db->fetch("SELECT description FROM permission WHERE code = 'order.cancel'");
        self::assertNotNull($row, 'seed 0001 doit avoir pose la permission order.cancel');

        $description = (string) ($row['description'] ?? '');
        self::assertStringContainsString('preparing', $description);
        self::assertStringContainsString('ready', $description);
        self::assertStringNotContainsString('Cancel a pending or paid order (restocks', $description);
    }

    public function testMenuSlotOrderMatchesTheMaquette(): void
    {
        // A10 (audit maquette vs front) : la maquette enchaine Format -> Accompagnement
        // -> Boisson -> (Sauce). "Menu Le 280" est le tout premier menu du seed 0002 ;
        // ses slots doivent suivre cet ordre une fois le seed + la migration 0015 joues.
        $menuId = $this->db->fetch("SELECT id FROM menu WHERE name = 'Menu Le 280'")['id'] ?? null;
        self::assertNotNull($menuId, 'seed 0002 doit avoir pose le menu "Menu Le 280"');

        $slots = $this->db->fetchAll(
            'SELECT slot_type, display_order FROM menu_slot WHERE menu_id = :id ORDER BY display_order, id',
            ['id' => $menuId],
        );
        self::assertSame(['side', 'drink', 'sauce'], array_column($slots, 'slot_type'));
    }

    public function testMigration0012ReplayIsANoOpOnceRolesAreFrench(): void
    {
        $affected = $this->executeSqlStatements($this->readMigrationSql('0012_role_labels_fr.sql'));

        self::assertSame(0, $affected, 'rejouee sur des roles deja en francais, la migration ne doit toucher aucune ligne');
    }

    public function testMigration0013ReplayIsANoOpOnceCategoriesAreCapitalized(): void
    {
        $affected = $this->executeSqlStatements($this->readMigrationSql('0013_category_names_titlecase.sql'));

        self::assertSame(0, $affected, 'rejouee sur des categories deja capitalisees, la migration ne doit toucher aucune ligne');
    }

    public function testMigration0014ReplayIsANoOpOnceTheDescriptionIsUpToDate(): void
    {
        $affected = $this->executeSqlStatements($this->readMigrationSql('0014_permission_cancel_description_fix.sql'));

        self::assertSame(0, $affected, 'rejouee sur une description deja a jour, la migration ne doit toucher aucune ligne');
    }

    public function testMigration0015ReplayIsANoOpOnceSlotsAreInMaquetteOrder(): void
    {
        $affected = $this->executeSqlStatements($this->readMigrationSql('0015_menu_slot_order_maquette.sql'));

        self::assertSame(0, $affected, 'rejouee sur des slots deja dans l ordre maquette, la migration ne doit toucher aucune ligne');
    }

    public function testMigration0016ReplayIsANoOpOnceTheDemoDataIsAccented(): void
    {
        $affected = $this->executeSqlStatements($this->readMigrationSql('0016_demo_data_accents.sql'));

        // La collation utf8mb4_unicode_ci etant insensible aux accents, les gardes
        // matchent encore les lignes DEJA corrigees -- mais l'UPDATE y ecrit alors la
        // valeur identique, et MariaDB compte 0 ligne CHANGEE. Le rejeu est donc bien
        // un non-evenement, mesurable de la meme facon que pour 0012/0013/0014/0015.
        self::assertSame(0, $affected, 'rejouee sur des donnees deja accentuees, la migration ne doit changer aucune ligne');
    }

    public function testMigration0016DoesNotOverwriteAProductRenamedInProduction(): void
    {
        // Meme garde que 0012 : un responsable a pu renommer un produit depuis le
        // back-office. Le garde porte sur le nom NON ACCENTUE d'origine, qui ne
        // matche plus -- la personnalisation survit au rejeu.
        $original = $this->db->fetch("SELECT id, name FROM product WHERE name = 'César Classic'");
        self::assertNotNull($original, 'precondition : seed 0002 + migration 0016 deja joues');
        $id = (int) $original['id'];

        $this->db->execute('UPDATE product SET name = :name WHERE id = :id', ['name' => 'Salade du chef', 'id' => $id]);

        try {
            $affected = $this->executeSqlStatements($this->readMigrationSql('0016_demo_data_accents.sql'));
            self::assertSame(0, $affected, 'un renommage fait en production ne doit pas etre ecrase par un rejeu');

            $renamed = $this->db->fetch('SELECT name FROM product WHERE id = :id', ['id' => $id]);
            self::assertSame('Salade du chef', $renamed['name'] ?? null);
        } finally {
            // Base MariaDB partagee entre les tests : on restaure l'etat attendu.
            $this->db->execute('UPDATE product SET name = :name WHERE id = :id', ['name' => (string) $original['name'], 'id' => $id]);
        }
    }

    public function testMigration0012DoesNotOverwriteALabelCustomizedInProduction(): void
    {
        // Relecture independante du 24/09 : un admin a pu renommer un role depuis le
        // back-office avant que cette migration ne tourne (ou avant un rejeu) -- elle
        // ne doit alors PLUS matcher (garde sur le libelle/la description ANGLAIS
        // D'ORIGINE) et ne doit surtout pas ecraser la personnalisation.
        $original = $this->db->fetch("SELECT label, description FROM role WHERE code = 'admin'");
        self::assertNotNull($original);
        self::assertSame('Administrateur', $original['label'] ?? null, 'precondition : seed 0001 deja en francais');

        $this->db->execute(
            "UPDATE role SET label = :label, description = :description WHERE code = 'admin'",
            ['label' => 'Grand Chef', 'description' => 'Personnalise depuis le back-office en production'],
        );

        try {
            $affected = $this->executeSqlStatements($this->readMigrationSql('0012_role_labels_fr.sql'));
            self::assertSame(0, $affected, 'une personnalisation ne doit pas etre ecrasee par un rejeu de la migration');

            $customized = $this->db->fetch("SELECT label, description FROM role WHERE code = 'admin'");
            self::assertSame('Grand Chef', $customized['label'] ?? null);
            self::assertSame('Personnalise depuis le back-office en production', $customized['description'] ?? null);
        } finally {
            // Restaure l'etat attendu par les autres tests de ce fichier et par le
            // reste de la suite (base MariaDB partagee, pas une fixture isolee).
            $this->db->execute(
                'UPDATE role SET label = :label, description = :description WHERE code = \'admin\'',
                ['label' => $original['label'], 'description' => $original['description']],
            );
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
     * Execute chaque instruction d'un fichier de migration et renvoie le total de
     * lignes affectees. Les lignes de commentaire (--) sont retirees AVANT le
     * decoupage : le texte de ces migrations contient des points-virgules de
     * ponctuation francaise dans ses commentaires ET dans ses valeurs (ex. "actives
     * ; fait avancer" dans la description du role kitchen), qu'un decoupage naif
     * sur ';' couperait au mauvais endroit -- splitSqlStatements() ne coupe que les
     * ';' HORS chaine SQL.
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
     * Decoupe un script SQL en instructions sur ';', en ignorant tout ';' a
     * l'INTERIEUR d'une chaine litterale ('...'), y compris quand cette chaine
     * contient une apostrophe echappee (''). Un decoupage naif sur le fichier brut
     * casserait en deux une valeur comme 'Écran cuisine ... actives ; fait avancer...'.
     *
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
                    // Apostrophe echappee ('') : reste DANS la chaine, ne la ferme pas.
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
