<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalogue;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use App\Catalogue\ImportBlockedException;
use App\Catalogue\ProductImportService;
use App\Tests\Support\FakeDatabase;

/**
 * Analyse + validation CSV (ProductImportService::preview/apply), contre un
 * FakeDatabase generique : les requetes de resolution PAR NOM (categorie,
 * ingredient, produit) n'ont pas de "bouton" dedie dans FakeDatabase et
 * retournent donc null (introuvable) -- ce qui simule exactement le cas
 * "catégorie/ingrédient inconnu" utile a une partie de ces tests. La resolution
 * PAR ID NUMERIQUE, elle, reutilise le bouton existant `$db->categoryRow` (meme
 * forme de requete `FROM category WHERE id = :id` que CategoryRepository::find).
 * Les scenarios qui exigent un produit/ingredient EXISTANT reconcilie (mise a
 * jour, unite coherente) sont donc laisses a l'integration reelle
 * (tests/Integration/ProductImportServiceDbTest.php, WAKDO_DB_TESTS=1) : ce
 * fichier couvre la logique d'analyse pure (encodage, delimiteur, guillemets,
 * prix, TVA, quantites, doublons, injection de formule, bornes de taille) et le
 * contrat "aucune ecriture en preview / tout ou rien en apply".
 */
final class ProductImportServiceTest extends TestCase
{
    private ProductImportService $service;

    protected function setUp(): void
    {
        $this->service = new ProductImportService();
    }

    private function header(): string
    {
        return implode(';', ProductImportService::COLUMNS);
    }

    private function db(): FakeDatabase
    {
        $db = new FakeDatabase();
        $db->categoryRow = ['id' => 3, 'name' => 'Burgers'];

        return $db;
    }

    public function testPreviewNeverWrites(): void
    {
        $db = $this->db();
        $csv = $this->header() . "\r\n3;Cheeseburger;;6,90;10;;oui;Pain;unite;1;non;non\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertSame([], $report['errors']);
        self::assertSame([], $db->writes);
        self::assertSame([], $db->transactionEvents);
    }

    public function testBomIsStrippedAndSemicolonDelimiterDetected(): void
    {
        $db = $this->db();
        $csv = "\xEF\xBB\xBF" . $this->header() . "\r\n3;Cheeseburger;Un burger;6,90;10;;oui;Pain;unite;1;non;non\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertSame([], $report['errors'], 'BOM + point-virgule doivent être acceptés sans erreur');
        self::assertCount(1, $report['productsToCreate']);
        self::assertSame('Cheeseburger', $report['productsToCreate'][0]['name']);
        self::assertSame(690, $report['productsToCreate'][0]['price_cents']);
    }

    public function testCommaDelimiterDetectedWhenMoreFrequentThanSemicolon(): void
    {
        $db = $this->db();
        $header = implode(',', ProductImportService::COLUMNS);
        // Prix "6.90" (point) : le separateur decimal anglais accompagne souvent
        // le separateur de colonnes virgule.
        $csv = $header . "\n3,Cheeseburger,,6.90,10,,oui,Pain,unite,1,non,non\n";

        $report = $this->service->preview($csv, $db);

        self::assertSame([], $report['errors']);
        self::assertSame(690, $report['productsToCreate'][0]['price_cents']);
    }

    public function testQuotedFieldWithEmbeddedDelimiterAndNewline(): void
    {
        $db = $this->db();
        $csv = $this->header() . "\r\n" . '3;Cheeseburger;"Steak, cheddar' . "\n" . 'et sauce maison";6,90;10;;oui;Pain;unite;1;non;non' . "\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertSame([], $report['errors']);
        self::assertSame("Steak, cheddar\net sauce maison", $report['productsToCreate'][0]['description']);
    }

    /**
     * @return array<string, array{0:string,1:int}>
     */
    public static function priceProvider(): array
    {
        return [
            'virgule' => ['6,80', 680],
            'point'   => ['6.80', 680],
        ];
    }

    #[DataProvider('priceProvider')]
    public function testPriceAcceptsCommaOrDotWithoutRoundingError(string $raw, int $expectedCents): void
    {
        $db = $this->db();
        $csv = $this->header() . "\r\n3;Boisson;;{$raw};10;;oui;;;;;\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertSame([], $report['errors']);
        self::assertSame($expectedCents, $report['productsToCreate'][0]['price_cents']);
    }

    public function testInvalidVatRateIsRejectedWithClearMessage(): void
    {
        $db = $this->db();
        // 20% n'existe pas dans le modele Wakdo (seuls 5,5 et 10 -> chk_product_vat_rate).
        $csv = $this->header() . "\r\n3;Menu XL;;9,90;20;;oui;;;;;\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertNotSame([], $report['errors']);
        self::assertSame('tva', $report['errors'][0]['column']);
        self::assertStringContainsString('5,5', $report['errors'][0]['message']);
    }

    public function testUnknownCategoryNameIsReported(): void
    {
        $db = $this->db(); // categoryRow ne repond qu'a une recherche PAR ID
        $csv = $this->header() . "\r\nCategorieInexistante;Burger;;6,90;10;;oui;;;;;\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertNotSame([], $report['errors']);
        self::assertSame('categorie', $report['errors'][0]['column']);
        self::assertStringContainsString('inconnue', $report['errors'][0]['message']);
    }

    public function testNewIngredientWithoutUnitIsReported(): void
    {
        $db = $this->db();
        $csv = $this->header() . "\r\n3;Cheeseburger;;6,90;10;;oui;Sauce secrète;;2;non;non\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertNotSame([], $report['errors']);
        self::assertSame('unite', $report['errors'][0]['column']);
        self::assertStringContainsString('Unité requise', $report['errors'][0]['message']);
    }

    /**
     * Relecture adverse (2026-09-26) : sans ce controle, l'aperçu annonçait
     * "aucune erreur" pour un nom d'ingrédient de 200 caractères, puis
     * l'écriture (colonne `ingredient.name` VARCHAR(120)) échouait à la
     * confirmation -- une exception non prévue, pas un message lisible.
     */
    public function testNewIngredientNameLongerThan120CharsIsRejected(): void
    {
        $db = $this->db();
        $longName = str_repeat('a', 200);
        $csv = $this->header() . "\r\n3;Cheeseburger;;6,90;10;;oui;{$longName};g;2;non;non\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertNotSame([], $report['errors']);
        self::assertSame('ingredient', $report['errors'][0]['column']);
        self::assertStringContainsString('120 caractères max', $report['errors'][0]['message']);
    }

    public function testNewIngredientUnitLongerThan40CharsIsRejected(): void
    {
        $db = $this->db();
        $longUnit = str_repeat('u', 50);
        $csv = $this->header() . "\r\n3;Cheeseburger;;6,90;10;;oui;Sauce secrète;{$longUnit};2;non;non\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertNotSame([], $report['errors']);
        self::assertSame('unite', $report['errors'][0]['column']);
        self::assertStringContainsString('40 caractères max', $report['errors'][0]['message']);
    }

    /**
     * Relecture adverse n°2 (2026-09-26) : meme classe de defaut que le nom/
     * l'unite d'ingredient -- `product.description` (colonne TEXT, limite
     * MariaDB 65535 OCTETS) n'etait pas bornee a l'apercu : une description de
     * 70 000 caractères passait, puis l'écriture aurait échoué.
     */
    public function testDescriptionLongerThan65535BytesIsRejected(): void
    {
        $db = $this->db();
        $longDescription = str_repeat('a', 70000);
        $csv = $this->header() . "\r\n3;Cheeseburger;{$longDescription};6,90;10;;oui;;;;;\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertNotSame([], $report['errors']);
        self::assertSame('description', $report['errors'][0]['column']);
        self::assertStringContainsString('environ 65 000 caractères maximum', $report['errors'][0]['message']);
    }

    public function testDescriptionOfExactly65535BytesIsAccepted(): void
    {
        $db = $this->db();
        $description = str_repeat('a', 65535);
        $csv = $this->header() . "\r\n3;Cheeseburger;{$description};6,90;10;;oui;;;;;\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertSame([], $report['errors']);
    }

    public function testNegativeQuantityIsRejected(): void
    {
        $db = $this->db();
        $csv = $this->header() . "\r\n3;Cheeseburger;;6,90;10;;oui;Pain;unite;-1;non;non\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertNotSame([], $report['errors']);
        self::assertSame('quantite', $report['errors'][0]['column']);
    }

    public function testNonNumericQuantityIsRejected(): void
    {
        $db = $this->db();
        $csv = $this->header() . "\r\n3;Cheeseburger;;6,90;10;;oui;Pain;unite;deux;non;non\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertNotSame([], $report['errors']);
        self::assertSame('quantite', $report['errors'][0]['column']);
    }

    public function testDuplicateIngredientForSameProductIsRejected(): void
    {
        $db = $this->db();
        $csv = $this->header()
            . "\r\n3;Cheeseburger;;6,90;10;;oui;Pain;unite;1;non;non"
            . "\r\n3;Cheeseburger;;6,90;10;;oui;Pain;unite;2;non;non\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertNotSame([], $report['errors']);
        self::assertSame('ingredient', $report['errors'][1]['column'] ?? $report['errors'][0]['column']);
        self::assertStringContainsString('doublon', implode(' ', array_column($report['errors'], 'message')));
    }

    public function testRowWithoutIngredientIsAllowedAndYieldsEmptyRecipe(): void
    {
        $db = $this->db();
        $csv = $this->header() . "\r\n3;Frites;;3,50;10;;oui;;;;;\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertSame([], $report['errors']);
        self::assertSame(0, $report['productsToCreate'][0]['recipe_line_count']);
    }

    /**
     * `=`, `@` et une tabulation de tete sont refuses QUELLE QUE SOIT la suite
     * (aucune saisie legitime de nos colonnes de texte ne commence ainsi).
     * `+`/`-` ne le sont QUE suivis d'un caractere qui prolongerait une
     * expression numerique/de reference (chiffre, operateur, parenthese) --
     * voir le boundary test juste apres pour le cote SAFE (lettre/espace).
     *
     * @return list<array{0:string}>
     */
    public static function formulaInjectionProvider(): array
    {
        return [["=CMD|'calc'"], ["+1+1"], ["-1+1"], ["@CMD|'calc'"], ["\tCMD|'calc'"]];
    }

    #[DataProvider('formulaInjectionProvider')]
    public function testFormulaInjectionPrefixIsRejectedInProductName(string $dangerousValue): void
    {
        $db = $this->db();
        $csv = $this->header() . "\r\n3;{$dangerousValue};;6,90;10;;oui;;;;;\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertNotSame([], $report['errors']);
        self::assertStringContainsString('interprètent comme un calcul', implode(' ', array_column($report['errors'], 'message')));
    }

    /**
     * `-`/`+` suivi d'une LETTRE (accentuee comprise), d'un ESPACE, ou seul en
     * fin de cellule : saisie de texte NORMALE (une description, un nom
     * d'ingredient), jamais une formule -- un tableur ne l'evalue pas et
     * l'affiche telle quelle. La rejeter serait bloquer une saisie legitime.
     *
     * @return list<array{0:string}>
     */
    public static function legitimateDashOrPlusProvider(): array
    {
        return [
            ['-Sans gluten'],
            ['- Sans gluten'],
            ['-Économique'],
            ['+Supplément inclus'],
            ['-'],
            ['+'],
        ];
    }

    #[DataProvider('legitimateDashOrPlusProvider')]
    public function testLeadingDashOrPlusFollowedByLetterOrSpaceIsNotRejected(string $legitimateValue): void
    {
        $db = $this->db();
        $csv = $this->header() . "\r\n3;Cheeseburger;{$legitimateValue};6,90;10;;oui;;;;;\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertSame([], $report['errors']);
    }

    /**
     * Decision assumee (relecture adverse n°2, 2026-09-26) : une remise ecrite
     * en chiffres avec un tiret de tete ("-30% aujourd'hui") est REFUSEE, pas
     * autorisee -- le tiret y est suivi d'un CHIFFRE, la forme d'une charge
     * utile d'injection reelle (ex. "-2+3+cmd|'/c calc'!A0"), pas d'une lettre
     * ou d'un espace. Documente dans docs/api/import-produits.md section 2.
     * Le message doit VRAIMENT suggerer la reformulation (relecture n°3,
     * 2026-09-26 : la doc le pretait au message sans que celui-ci le fasse).
     */
    public function testDiscountWithLeadingDashFollowedByDigitIsRejected(): void
    {
        $db = $this->db();
        $csv = $this->header() . "\r\n3;Cheeseburger;-30% aujourd'hui;6,90;10;;oui;;;;;\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertNotSame([], $report['errors']);
        self::assertSame('description', $report['errors'][0]['column']);
        self::assertStringContainsString('interprètent comme un calcul', $report['errors'][0]['message']);
        self::assertStringContainsString('Écrivez plutôt "30 % de réduction".', $report['errors'][0]['message']);
    }

    /**
     * Contre-exemple de la reformulation ci-dessus : "=CMD"/"@CMD"/tabulation
     * n'ont rien a voir avec une remise -- suggerer "30 % de réduction" n'y
     * aurait aucun sens et desservirait le message. Reserve a "+"/"-".
     */
    public function testFormulaMessageDoesNotSuggestDiscountRewordingForEqualsSignPrefix(): void
    {
        $db = $this->db();
        $csv = $this->header() . "\r\n3;Cheeseburger;=CMD|'calc';6,90;10;;oui;;;;;\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertNotSame([], $report['errors']);
        self::assertStringContainsString('interprètent comme un calcul', $report['errors'][0]['message']);
        self::assertStringNotContainsString('30 % de réduction', $report['errors'][0]['message']);
    }

    /**
     * Non bloquant mais peu coûteux (relecture adverse n°2) : des caracteres
     * invisibles ou quasi invisibles (espace insecable, espace fine, espaces
     * de largeur nulle, marque de gauche a droite) places AVANT un "=" ne
     * doivent pas laisser passer une formule masquee. Le signe egal PLEINE
     * CHASSE ("＝", U+FF1D) n'est pas invisible mais un homoglyphe de "=",
     * traite a l'identique.
     *
     * Etendu en relecture adverse n°3 (2026-09-26) : l'ancienne enumeration a
     * 6 entrees en laissait passer d'autres (espace cadratin, assembleur de
     * mots...), et creait une asymetrie selon l'ordre des caracteres -- deux
     * entrees dediees le prouvent explicitement ci-dessous.
     *
     * @return array<string, array{0:string}>
     */
    public static function invisiblePrefixProvider(): array
    {
        return [
            'espace insécable (U+00A0)' => ["\u{00A0}=CMD"],
            'espace fine insécable (U+202F)' => ["\u{202F}=CMD"],
            'espace fine (U+2009)' => ["\u{2009}=CMD"],
            'espace de largeur nulle (U+200B)' => ["\u{200B}=CMD"],
            'espace de largeur nulle insécable (U+FEFF)' => ["\u{FEFF}=CMD"],
            'marque de gauche à droite (U+200E)' => ["\u{200E}=CMD"],
            'empilés (NBSP + ZWSP + LRM)' => ["\u{00A0}\u{200B}\u{200E}=CMD"],
            'signe égal pleine chasse (U+FF1D)' => ["\u{FF1D}CMD"],
            'espace cadratin, hors ancienne liste (U+2002)' => ["\u{2002}=CMD"],
            'espace idéographique, hors ancienne liste (U+3000)' => ["\u{3000}=CMD"],
            'assembleur de mots, hors ancienne liste (U+2060)' => ["\u{2060}=CMD"],
            'marque de droite à gauche, hors ancienne liste (U+200F)' => ["\u{200F}=CMD"],
            'asymétrie : NBSP puis espace ordinaire' => ["\u{00A0} =CMD"],
            'asymétrie inverse : espace ordinaire puis NBSP' => [" \u{00A0}=CMD"],
        ];
    }

    #[DataProvider('invisiblePrefixProvider')]
    public function testInvisibleOrHomoglyphPrefixDoesNotBypassFormulaDetection(string $maskedValue): void
    {
        $db = $this->db();
        $csv = $this->header() . "\r\n3;{$maskedValue};;6,90;10;;oui;;;;;\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertNotSame([], $report['errors']);
        self::assertStringContainsString('interprètent comme un calcul', implode(' ', array_column($report['errors'], 'message')));
    }

    /**
     * Un espace insécable au MILIEU d'un texte normal (pas en tête) n'a rien
     * de suspect et ne doit pas être refusé.
     */
    public function testNonBreakingSpaceInTheMiddleOfTextIsNotRejected(): void
    {
        $db = $this->db();
        $csv = $this->header() . "\r\n3;Cheeseburger;Menu\u{00A0}enfant;6,90;10;;oui;;;;;\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertSame([], $report['errors']);
    }

    /**
     * Homoglyphes de "=" et "@" (releve par relecture adverse n°3,
     * 2026-09-26 : seul le signe egal pleine chasse etait couvert) : refuses
     * QUELLE QUE SOIT la suite, comme leurs equivalents ASCII.
     *
     * @return array<string, array{0:string}>
     */
    public static function homoglyphAlwaysDangerousProvider(): array
    {
        return [
            'arobase pleine chasse (U+FF20)' => ["\u{FF20}CMD"],
            'signe égal pleine chasse suivi de texte (U+FF1D)' => ["\u{FF1D}SUM(A1)"],
        ];
    }

    #[DataProvider('homoglyphAlwaysDangerousProvider')]
    public function testHomoglyphOfEqualsOrAtIsRejectedRegardlessOfSuffix(string $maskedValue): void
    {
        $db = $this->db();
        $csv = $this->header() . "\r\n3;{$maskedValue};;6,90;10;;oui;;;;;\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertNotSame([], $report['errors']);
        self::assertStringContainsString('interprètent comme un calcul', implode(' ', array_column($report['errors'], 'message')));
    }

    /**
     * Homoglyphes de "+"/"-" (releve par relecture adverse n°3, 2026-09-26 :
     * U+FF0B, U+FF0D, U+2212, U+FE63 manquaient a la table) : memes regles que
     * leurs equivalents ASCII -- refuses seulement suivis d'un chiffre.
     *
     * @return array<string, array{0:string}>
     */
    public static function homoglyphDashOrPlusFollowedByDigitProvider(): array
    {
        return [
            'plus pleine chasse (U+FF0B) + chiffre' => ["\u{FF0B}30% aujourd'hui"],
            'moins pleine chasse (U+FF0D) + chiffre' => ["\u{FF0D}30% aujourd'hui"],
            'signe moins mathématique (U+2212) + chiffre' => ["\u{2212}30% aujourd'hui"],
            'petit trait d\'union-moins (U+FE63) + chiffre' => ["\u{FE63}30% aujourd'hui"],
        ];
    }

    #[DataProvider('homoglyphDashOrPlusFollowedByDigitProvider')]
    public function testHomoglyphOfDashOrPlusFollowedByDigitIsRejected(string $dangerousValue): void
    {
        $db = $this->db();
        $csv = $this->header() . "\r\n3;Cheeseburger;{$dangerousValue};6,90;10;;oui;;;;;\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertNotSame([], $report['errors']);
        self::assertSame('description', $report['errors'][0]['column']);
        self::assertStringContainsString('interprètent comme un calcul', $report['errors'][0]['message']);
    }

    /**
     * Meme homoglyphes, mais suivis d'une lettre/d'un espace/rien : saisie
     * normale, pas une formule -- doivent rester acceptes (symetrie avec
     * "-"/"+" ASCII, testee par legitimateDashOrPlusProvider ci-dessus).
     *
     * @return array<string, array{0:string}>
     */
    public static function homoglyphDashOrPlusFollowedByLetterProvider(): array
    {
        return [
            'plus pleine chasse (U+FF0B) + lettre' => ["\u{FF0B}Supplément inclus"],
            'moins pleine chasse (U+FF0D) + lettre' => ["\u{FF0D}Sans gluten"],
            'signe moins mathématique (U+2212) + espace' => ["\u{2212} Sans gluten"],
            'petit trait d\'union-moins (U+FE63) seul' => ["\u{FE63}"],
        ];
    }

    #[DataProvider('homoglyphDashOrPlusFollowedByLetterProvider')]
    public function testHomoglyphOfDashOrPlusFollowedByLetterOrSpaceIsNotRejected(string $legitimateValue): void
    {
        $db = $this->db();
        $csv = $this->header() . "\r\n3;Cheeseburger;{$legitimateValue};6,90;10;;oui;;;;;\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertSame([], $report['errors']);
    }

    public function testFormulaInjectionPrefixIsRejectedInIngredientName(): void
    {
        $db = $this->db();
        $csv = $this->header() . "\r\n3;Cheeseburger;;6,90;10;;oui;=SUM(A1:A9);unite;1;non;non\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertNotSame([], $report['errors']);
        self::assertStringContainsString('interprètent comme un calcul', implode(' ', array_column($report['errors'], 'message')));
    }

    public function testOversizedFileIsRejectedBeforeParsing(): void
    {
        $db = $this->db();
        $csv = $this->header() . "\r\n" . str_repeat('x', ProductImportService::MAX_BYTES + 10);

        $report = $this->service->preview($csv, $db);

        self::assertNotSame([], $report['errors']);
        self::assertStringContainsString('volumineux', $report['errors'][0]['message']);
    }

    public function testTooManyDataLinesIsRejected(): void
    {
        $db = $this->db();
        $line = "3;Produit;;1,00;10;;oui;;;;;\r\n";
        $csv = $this->header() . "\r\n" . str_repeat($line, ProductImportService::MAX_DATA_LINES + 5);

        $report = $this->service->preview($csv, $db);

        self::assertNotSame([], $report['errors']);
        self::assertStringContainsString('Trop de lignes', implode(' ', array_column($report['errors'], 'message')));
    }

    public function testInvalidHeaderIsRejected(): void
    {
        $db = $this->db();
        $csv = "colonne_a;colonne_b\r\nx;y\r\n";

        $report = $this->service->preview($csv, $db);

        self::assertNotSame([], $report['errors']);
        self::assertSame('en-tête', $report['errors'][0]['column']);
    }

    public function testInconsistentProductFieldsAcrossRepeatedRowsIsRejected(): void
    {
        $db = $this->db();
        $csv = $this->header()
            . "\r\n3;Cheeseburger;;6,90;10;;oui;Pain;unite;1;non;non"
            . "\r\n3;Cheeseburger;;7,90;10;;oui;Steak;unite;1;non;non\r\n"; // prix divergent

        $report = $this->service->preview($csv, $db);

        self::assertNotSame([], $report['errors']);
        self::assertSame('prix_ttc', $report['errors'][0]['column']);
    }

    public function testApplyRefusesWhenReportStillHasErrors(): void
    {
        $db = $this->db();
        $csv = $this->header() . "\r\n3;Menu XL;;9,90;20;;oui;;;;;\r\n"; // TVA invalide

        $this->expectException(ImportBlockedException::class);
        $this->service->apply($csv, $db, 1, 1);
    }

    public function testApplyIsAllOrNothingOnFirstWriteFailure(): void
    {
        $db = $this->db();
        $db->failOnExecute = new \RuntimeException('panne simulée');
        $csv = $this->header() . "\r\n3;Cheeseburger;;6,90;10;;oui;Pain;unite;1;non;non\r\n";

        try {
            $this->service->apply($csv, $db, 1, 1);
            self::fail('Une exception aurait dû être propagée.');
        } catch (\RuntimeException $exception) {
            self::assertSame('panne simulée', $exception->getMessage());
        }

        self::assertSame(['begin', 'rollback'], $db->transactionEvents);
        self::assertSame([], $db->writes, 'Rien ne doit rester ecrit apres un rollback (tout ou rien)');
    }

    public function testTemplateCsvHasExpectedHeaderAndTwoExamples(): void
    {
        $csv = ProductImportService::templateCsv();

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $withoutBom = substr($csv, 3);
        $lines = explode("\r\n", trim($withoutBom));
        self::assertSame(implode(';', ProductImportService::COLUMNS), $lines[0]);
        // En-tete + au moins 2 lignes d'exemple pour un burger multi-ingredients (3
        // lignes produit_ingredient) + une boisson (1 ligne) = 5 lignes de donnees.
        self::assertGreaterThanOrEqual(3, count($lines) - 1);
    }
}
