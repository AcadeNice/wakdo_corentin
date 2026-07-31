<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalogue;

use App\Catalogue\IngredientRepository;
use App\Tests\Support\FakeDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Ecriture des allergenes declares d'un ingredient (F11b). Quatre effets sont
 * indissociables et doivent tenir dans UNE transaction : purge des liaisons, pose des
 * nouvelles, marquage de la revue, trace d'audit. Un effet applique sans les autres
 * laisserait la base dans un etat qui MENT -- par exemple des liaisons a jour sans
 * date de revue, donc invisibles a la borne, ou une date de revue sans les liaisons,
 * donc une absence affirmee a tort.
 */
final class IngredientRepositoryAllergensTest extends TestCase
{
    /**
     * @return list<array{id: int, name: string}>
     */
    private function twoAllergens(): array
    {
        return [['id' => 1, 'name' => 'Gluten'], ['id' => 7, 'name' => 'Lait']];
    }

    private function writeSql(FakeDatabase $db, string $needle): ?string
    {
        foreach ($db->writes as $write) {
            if (str_contains($write['sql'], $needle)) {
                return $write['sql'];
            }
        }

        return null;
    }

    /**
     * @return array<string|int, mixed>|null
     */
    private function writeParams(FakeDatabase $db, string $needle): ?array
    {
        foreach ($db->writes as $write) {
            if (str_contains($write['sql'], $needle)) {
                return $write['params'];
            }
        }

        return null;
    }

    public function testWritesEverythingInsideASingleTransaction(): void
    {
        $db = new FakeDatabase();

        (new IngredientRepository($db))->setAllergens(5, $this->twoAllergens(), 'Fiche fournisseur', 3, 2);

        self::assertSame(['begin', 'commit'], $db->transactionEvents);
    }

    public function testPurgesBeforeInsertingTheNewSet(): void
    {
        $db = new FakeDatabase();

        (new IngredientRepository($db))->setAllergens(5, $this->twoAllergens(), 'Fiche fournisseur', 3, 2);

        // L'ordre compte : un INSERT avant le DELETE heurterait la PK composite sur un
        // allergene deja lie et ferait echouer une revue par ailleurs valide.
        $sqls = array_map(static fn (array $w): string => $w['sql'], $db->writes);
        self::assertStringContainsString('DELETE FROM ingredient_allergen', $sqls[0]);
        self::assertStringContainsString('INSERT INTO ingredient_allergen', $sqls[1]);
        self::assertStringContainsString('INSERT INTO ingredient_allergen', $sqls[2]);
    }

    public function testInsertsOneRowPerRetainedAllergen(): void
    {
        $db = new FakeDatabase();

        (new IngredientRepository($db))->setAllergens(5, $this->twoAllergens(), 'Fiche fournisseur', 3, 2);

        $inserts = array_values(array_filter(
            $db->writes,
            static fn (array $w): bool => str_contains($w['sql'], 'INSERT INTO ingredient_allergen'),
        ));
        self::assertCount(2, $inserts);
        self::assertSame(['ing' => 5, 'alg' => 1], $inserts[0]['params']);
        self::assertSame(['ing' => 5, 'alg' => 7], $inserts[1]['params']);
    }

    public function testStampsTheReviewMarkerAndTheSource(): void
    {
        $db = new FakeDatabase();

        (new IngredientRepository($db))->setAllergens(5, $this->twoAllergens(), 'Table allergenes fournisseur', 3, 2);

        // C'est ce marquage qui rend la liste affirmable cote borne. NOW() est pose par
        // SQL, jamais par PHP : l'horloge de reference est celle de la base.
        $sql = $this->writeSql($db, 'UPDATE ingredient SET allergens_reviewed_at');
        self::assertNotNull($sql);
        self::assertStringContainsString('allergens_reviewed_at = NOW()', $sql);
        self::assertSame(
            ['src' => 'Table allergenes fournisseur', 'id' => 5],
            $this->writeParams($db, 'UPDATE ingredient SET allergens_reviewed_at'),
        );
    }

    public function testAnEmptySetIsAnExplicitDeclarationOfAbsence(): void
    {
        $db = new FakeDatabase();

        (new IngredientRepository($db))->setAllergens(5, [], 'Fiche fournisseur', 3, 2);

        // Cocher aucune case n'est PAS un non-geste : ca declare "verifie, aucun des
        // 14". Le marqueur de revue doit donc etre pose meme sans liaison.
        self::assertNotNull($this->writeSql($db, 'UPDATE ingredient SET allergens_reviewed_at'));
        self::assertNull($this->writeSql($db, 'INSERT INTO ingredient_allergen'));
    }

    public function testAuditRowNamesTheAllergensAndTheActor(): void
    {
        $db = new FakeDatabase();

        (new IngredientRepository($db))->setAllergens(5, $this->twoAllergens(), 'Fiche fournisseur', 3, 2);

        $params = $this->writeParams($db, 'INSERT INTO audit_log');
        self::assertNotNull($params);
        self::assertSame('ingredient.allergens', $params['code']);
        self::assertSame('ingredient', $params['etype']);
        self::assertSame(5, $params['eid']);
        self::assertSame(3, $params['uid']);
        self::assertSame(2, $params['rid']);
        // La trace se lit sans requete complementaire : des libelles, pas des ids.
        self::assertSame('Allergenes revus : Gluten, Lait (source: Fiche fournisseur)', $params['summary']);
    }

    public function testAuditSummarySaysAucunWhenNothingIsDeclared(): void
    {
        $db = new FakeDatabase();

        (new IngredientRepository($db))->setAllergens(5, [], 'Fiche fournisseur', 3, 2);

        $params = $this->writeParams($db, 'INSERT INTO audit_log');
        self::assertNotNull($params);
        // "aucun des 14" est une AFFIRMATION tracee, distincte d'un silence.
        self::assertSame('Allergenes revus : aucun des 14 (source: Fiche fournisseur)', $params['summary']);
    }

    public function testAuditSummaryStaysWithinTheColumnLength(): void
    {
        $db = new FakeDatabase();
        // 14 libelles longs : la colonne summary fait 255 caracteres, la troncature est
        // une garde et ne doit jamais faire echouer une revue legitime.
        $many = [];
        for ($i = 1; $i <= 14; $i++) {
            $many[] = ['id' => $i, 'name' => 'Allergene au libelle deliberement tres long numero ' . $i];
        }

        (new IngredientRepository($db))->setAllergens(5, $many, 'Une source au nom lui aussi tres long', 3, 2);

        $params = $this->writeParams($db, 'INSERT INTO audit_log');
        self::assertNotNull($params);
        self::assertLessThanOrEqual(255, mb_strlen((string) $params['summary']));
    }

    public function testActorMayBeNullWithoutBreakingTheTrace(): void
    {
        $db = new FakeDatabase();

        (new IngredientRepository($db))->setAllergens(5, $this->twoAllergens(), 'Seed de revue', null, null);

        // Cas du seed de revue : personne n'a clique, la trace reste ecrite sans acteur
        // (memes colonnes nullables que order.expire).
        $params = $this->writeParams($db, 'INSERT INTO audit_log');
        self::assertNotNull($params);
        self::assertNull($params['uid']);
        self::assertNull($params['rid']);
    }
}
