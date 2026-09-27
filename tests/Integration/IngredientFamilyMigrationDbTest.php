<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use App\Catalogue\IngredientFamily;
use App\Core\Config;
use App\Core\Database;

/**
 * Verifie contre une vraie MariaDB (schema migre + seede) le classement des
 * ingredients par famille (migration 0017 + seed 0010) :
 *  - la migration est rejouable sans effet de bord (deuxieme passage = 0 ligne) ;
 *  - elle ne reclasse jamais un ingredient qui porte deja une famille (une
 *    installation reelle a pu etre corrigee a la main) ;
 *  - sa classification des 50 ingredients de demonstration est IDENTIQUE a celle
 *    posee par le seed 0003 sur une installation neuve (les deux doivent
 *    s'accorder : le seed ne sert qu'a l'init, la migration classe une base deja
 *    en place, et rien ne les recoupe automatiquement en dehors de ce test) ;
 *  - chaque famille utilisee par category_ingredient_family (seed 0010) figure
 *    dans la liste canonique, et reciproquement aucune famille canonique n'y est
 *    orpheline ;
 *  - la correspondance couvre les 8 categories restreintes et laisse "menus" libre ;
 *  - supprimer une categorie emporte ses lignes category_ingredient_family (CASCADE).
 *
 * Auto-skip si WAKDO_DB_TESTS != 1 (meme garde que les autres tests Integration).
 */
final class IngredientFamilyMigrationDbTest extends TestCase
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

    /**
     * Classement attendu des 50 ingredients de demonstration, EXACTEMENT comme
     * le contrat fige de la tache et comme les deux fichiers (migration 0017,
     * seed 0003) doivent le dire. Source unique de ce test -- si un fichier
     * diverge de cette liste (ou les deux entre eux), ce test le releve.
     *
     * @return array<string, string>
     */
    private function expectedFamilies(): array
    {
        return [
            'Pain burger' => 'pain', 'Pain sésame' => 'pain', 'Pain signature' => 'pain', 'Tortilla' => 'pain',
            'Steak haché' => 'viande', 'Filet de poulet pané' => 'viande', 'Galette de poisson' => 'viande',
            'Tranche de bacon' => 'viande', 'Nugget de poulet' => 'viande', 'Jambon' => 'viande',
            'Cheddar' => 'fromage', 'Fromage de chèvre' => 'fromage', 'Mozzarella' => 'fromage', 'Emmental' => 'fromage',
            'Salade' => 'legume', 'Tomate' => 'legume', 'Oignon' => 'legume', 'Cornichon' => 'legume', 'Roquette' => 'legume',
            'Sauce Big Mac' => 'sauce', 'Sauce ranch' => 'sauce', 'Sauce barbecue' => 'sauce', 'Sauce deluxe' => 'sauce',
            'Pomme de terre frite' => 'feculent', 'Galette de pomme de terre' => 'feculent',
            'Dose Coca' => 'dose_boisson', 'Dose Coca Zero' => 'dose_boisson', 'Dose Eau' => 'dose_boisson',
            'Dose Fanta' => 'dose_boisson', 'Dose Ice Tea Pêche' => 'dose_boisson', 'Dose Ice Tea Citron' => 'dose_boisson',
            "Dose Jus d'Orange" => 'dose_boisson', 'Dose Jus de Pomme' => 'dose_boisson',
            'Gobelet' => 'contenant',
            'Brownie' => 'dessert', 'Cheesecake' => 'dessert', 'Cookie' => 'dessert', 'Donut' => 'dessert',
            'Macaron' => 'dessert', 'Glace McFleury' => 'dessert', 'Muffin' => 'dessert', 'Glace sundae' => 'dessert',
            'Topping chocolat' => 'dessert',
            'Dosette Barbecue' => 'dosette', 'Dosette Moutarde' => 'dosette', 'Dosette Deluxe' => 'dosette',
            'Dosette Ketchup' => 'dosette', 'Dosette Chinoise' => 'dosette', 'Dosette Curry' => 'dosette',
            'Dosette Pommes Frites' => 'dosette',
        ];
    }

    public function testAllFiftyKnownIngredientsExistWithTheExpectedFamily(): void
    {
        // Sur la base de test (migrations puis TOUS les seeds joues, voir
        // php-tests.sh), la classification en place a ce point vient du seed 0003
        // (les migrations tournent sur une table `ingredient` encore vide). Ce
        // test verrouille donc d'abord que le seed classe bien les 50 noms
        // attendus -- la coherence avec la migration est verifiee separement
        // par testMigrationReclassificationMatchesTheSeed().
        $expected = $this->expectedFamilies();
        self::assertCount(50, $expected, 'precondition : la liste de reference doit couvrir les 50 ingredients');

        foreach ($expected as $name => $family) {
            $row = $this->db->fetch('SELECT family FROM ingredient WHERE name = :name', ['name' => $name]);
            self::assertNotNull($row, 'ingredient "' . $name . '" introuvable (seed 0003 non joue ?)');
            self::assertSame($family, $row['family'] ?? null, 'ingredient "' . $name . '" : famille inattendue');
        }
    }

    public function testMigrationReclassificationMatchesTheSeed(): void
    {
        // Simule l'installation reelle que la migration 0017 doit corriger : les 50
        // ingredients existent (poses par le seed) mais SANS famille (etat d'avant
        // le lot). On efface `family`, on rejoue la migration, et on verifie
        // qu'elle reclasse CHAQUE ingredient EXACTEMENT comme le seed l'a fait --
        // preuve que les deux fichiers s'accordent, comme l'exige la tache.
        $expected = $this->expectedFamilies();
        $names = array_keys($expected);
        $placeholders = implode(',', array_fill(0, count($names), '?'));

        $originalRows = $this->db->fetchAll(
            'SELECT name, family FROM ingredient WHERE name IN (' . $placeholders . ')',
            $names,
        );
        self::assertCount(50, $originalRows, 'precondition : les 50 ingredients doivent exister');

        try {
            $this->db->execute('UPDATE ingredient SET family = NULL WHERE name IN (' . $placeholders . ')', $names);

            // Verifie la precondition du test suivant (idempotence) tout en prouvant
            // le coeur de CE test : la migration reclasse depuis l'etat NULL.
            $this->executeSqlStatements($this->readMigrationSql('0017_ingredient_family.sql'));

            foreach ($expected as $name => $family) {
                $row = $this->db->fetch('SELECT family FROM ingredient WHERE name = :name', ['name' => $name]);
                self::assertSame($family, $row['family'] ?? null, 'migration 0017 : "' . $name . '" mal reclasse (doit matcher le seed 0003)');
            }
        } finally {
            // Restaure l'etat exact d'avant test (base MariaDB partagee entre tests).
            foreach ($originalRows as $row) {
                $this->db->execute(
                    'UPDATE ingredient SET family = :family WHERE name = :name',
                    ['family' => $row['family'], 'name' => $row['name']],
                );
            }
        }
    }

    public function testMigrationReplayIsANoOpOnceIngredientsAreClassified(): void
    {
        // Precondition : les 50 sont deja classes (seed 0003). Rejouer 0017 dans
        // cet etat (le cas normal, une base deja migree) ne doit toucher aucune ligne.
        $affected = $this->executeSqlStatements($this->readMigrationSql('0017_ingredient_family.sql'));

        self::assertSame(0, $affected, 'rejouee sur des ingredients deja classes, la migration ne doit toucher aucune ligne');
    }

    public function testMigrationDoesNotOverwriteAFamilyCorrectedByHand(): void
    {
        // Un manager a pu recorriger la famille d'un ingredient depuis le
        // back-office (ex. "Pain signature" range par erreur en dessert) AVANT un
        // rejeu de la migration -- le garde `family IS NULL` doit laisser cette
        // correction intacte, meme si elle diverge de la classification de reference.
        $original = $this->db->fetch("SELECT id, family FROM ingredient WHERE name = 'Pain signature'");
        self::assertNotNull($original, 'precondition : seed 0003 doit avoir pose "Pain signature"');
        $id = (int) $original['id'];

        $this->db->execute('UPDATE ingredient SET family = :f WHERE id = :id', ['f' => 'dessert', 'id' => $id]);

        try {
            $affected = $this->executeSqlStatements($this->readMigrationSql('0017_ingredient_family.sql'));
            self::assertSame(0, $affected, 'une correction manuelle ne doit pas etre ecrasee par un rejeu');

            $corrected = $this->db->fetch('SELECT family FROM ingredient WHERE id = :id', ['id' => $id]);
            self::assertSame('dessert', $corrected['family'] ?? null);
        } finally {
            $this->db->execute('UPDATE ingredient SET family = :f WHERE id = :id', ['f' => (string) $original['family'], 'id' => $id]);
        }
    }

    public function testCategoryIngredientFamilyCoversTheEightRestrictedCategoriesAndLeavesMenusFree(): void
    {
        $expected = [
            'burgers'  => ['pain', 'viande', 'fromage', 'legume', 'sauce'],
            'wraps'    => ['pain', 'viande', 'fromage', 'legume', 'sauce'],
            'salades'  => ['legume', 'fromage', 'viande', 'sauce'],
            'frites'   => ['feculent', 'dosette'],
            'encas'    => ['viande', 'sauce', 'dosette'],
            'boissons' => ['dose_boisson', 'contenant'],
            'desserts' => ['dessert'],
            'sauces'   => ['dosette'],
        ];

        foreach ($expected as $slug => $families) {
            $categoryId = (int) ($this->db->fetch('SELECT id FROM category WHERE slug = :slug', ['slug' => $slug])['id'] ?? 0);
            self::assertGreaterThan(0, $categoryId, 'categorie "' . $slug . '" introuvable (seed 0002 non joue ?)');

            $rows = $this->db->fetchAll(
                'SELECT family FROM category_ingredient_family WHERE category_id = :id ORDER BY family',
                ['id' => $categoryId],
            );
            $actual = array_column($rows, 'family');
            sort($families);
            sort($actual);
            self::assertSame($families, $actual, 'correspondance inattendue pour la categorie "' . $slug . '"');
        }

        // "menus" : AUCUNE ligne (pas de restriction), decision explicite du contrat.
        $menusId = (int) ($this->db->fetch("SELECT id FROM category WHERE slug = 'menus'")['id'] ?? 0);
        self::assertGreaterThan(0, $menusId, 'categorie "menus" introuvable');
        $menusRows = $this->db->fetchAll('SELECT family FROM category_ingredient_family WHERE category_id = :id', ['id' => $menusId]);
        self::assertSame([], $menusRows, '"menus" ne doit porter aucune restriction de famille');
    }

    public function testEveryFamilyUsedByTheCorrespondenceIsCanonicalAndNoneIsOrphaned(): void
    {
        $rows = $this->db->fetchAll('SELECT DISTINCT family FROM category_ingredient_family ORDER BY family');
        $used = array_column($rows, 'family');

        foreach ($used as $family) {
            self::assertTrue(IngredientFamily::isValid($family), 'famille "' . $family . '" utilisee par la correspondance mais absente de la liste canonique');
        }

        // Reciproque, assumee et testee comme telle (voir contrat) : les 8
        // categories restreintes couvrent a elles seules les DIX familles
        // canoniques -- aucune n'est orpheline (jamais utilisee par aucune categorie).
        $canonical = IngredientFamily::slugs();
        sort($canonical);
        sort($used);
        self::assertSame($canonical, $used, 'la correspondance doit couvrir les dix familles canoniques, sans orpheline');
    }

    public function testDeletingACategoryCascadesItsFamilyRows(): void
    {
        $name = 'it-cat-family-' . bin2hex(random_bytes(4));
        $this->db->execute(
            'INSERT INTO category (name, slug, display_order, is_active) VALUES (:name, :slug, 999, 1)',
            ['name' => $name, 'slug' => $name],
        );
        $categoryId = (int) ($this->db->fetch('SELECT id FROM category WHERE name = :name', ['name' => $name])['id'] ?? 0);
        self::assertGreaterThan(0, $categoryId);

        $this->db->execute(
            'INSERT INTO category_ingredient_family (category_id, family) VALUES (:id, :f)',
            ['id' => $categoryId, 'f' => 'dessert'],
        );
        self::assertNotNull($this->db->fetch('SELECT category_id FROM category_ingredient_family WHERE category_id = :id', ['id' => $categoryId]));

        $this->db->execute('DELETE FROM category WHERE id = :id', ['id' => $categoryId]);

        self::assertNull(
            $this->db->fetch('SELECT category_id FROM category_ingredient_family WHERE category_id = :id', ['id' => $categoryId]),
            'la suppression de la categorie doit emporter ses lignes category_ingredient_family (FK CASCADE)',
        );
    }

    private function readMigrationSql(string $filename): string
    {
        $path = __DIR__ . '/../../db/migrations/' . $filename;
        $sql = file_get_contents($path);
        self::assertNotFalse($sql, 'migration ' . $filename . ' introuvable a ' . $path);

        return $sql;
    }

    /**
     * Meme decoupage/execution que ReferenceDataFrenchDbTest (retire les
     * commentaires -- ';' de ponctuation francaise -- puis coupe sur ';' HORS
     * chaine SQL). Duplique volontairement (pas de trait partage pour deux
     * fichiers) : coherent avec le reste du projet, qui ne factorise pas ce
     * petit runner de migration en dehors de son unique appelant jusqu'ici.
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
