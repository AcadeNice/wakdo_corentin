<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Core\DatabaseInterface;
use App\Core\Money;

/**
 * Import CSV de produits + leurs recettes (chantier "recette dans le formulaire
 * produit + import CSV"). Format LONG : une ligne par couple produit-ingredient,
 * les colonnes du produit repetees sur chaque ligne d'un meme produit ; une ligne
 * sans ingredient est autorisee (produit sans recette).
 *
 * Deux temps, comme le reste du back-office (RG-T18 revalidation serveur) :
 *  - preview()  : AUCUNE ecriture. Analyse + validation, renvoie un rapport
 *    (produits a creer/mettre a jour/inchanges, ingredients existants/a creer,
 *    erreurs ligne par ligne).
 *  - apply()    : rejoue preview() en interne (defense contre un rapport perime)
 *    et, si zero erreur, ecrit tout dans UNE SEULE transaction (tout ou rien).
 *
 * Regle de rapprochement d'un produit existant : nom + categorie (comparaison
 * insensible a la casse). Regle de recette (tranchee explicitement, 2026-09-26) :
 * pour un produit existant, la recette du CSV REMPLACE integralement la recette
 * actuelle (delete-and-reinsert, meme politique que ProductRepository::
 * setComposition) -- y compris la VIDER si le CSV ne porte aucune ligne
 * ingredient pour ce produit. Le rapport de preview() expose ce risque
 * (recipe_line_count = 0 sur un produit "a mettre a jour") pour que l'ecran
 * d'apercu puisse l'afficher en avertissement avant confirmation.
 *
 * Colonnes du gabarit (ordre fixe, exact) : categorie, produit, description,
 * prix_ttc, tva, taille_cl, disponible, ingredient, unite, quantite, retirable,
 * ajoutable. `tva` n'accepte que 5,5 et 10 (les deux seuls taux du modele reel,
 * chk_product_vat_rate CHECK (vat_rate IN (55, 100)) -- le besoin exprime listait
 * aussi 20%, absent du modele Wakdo ; ecart documente, une valeur 20 est rejetee
 * avec un message explicite plutot que silencieusement toleree.
 *
 * Hors perimetre (documente, pas un oubli) : variantes de taille/Maxi
 * (base_product_id/maxi_variant_product_id, F9-3) et image produit -- toutes deux
 * restent des gestes du formulaire HTML apres import. quantity_maxi et
 * extra_price_cents (product_ingredient) n'ont pas de colonne dediee : l'import
 * pose quantity_maxi = quantite (meme valeur que le format normal) et
 * extra_price_cents = 0 ; a affiner ensuite via la recette du formulaire produit
 * si un supplement ou une quantite Maxi differente est necessaire.
 */
final class ProductImportService
{
    /** Ordre exact et complet des colonnes attendues (le parseur refuse tout autre en-tete). */
    public const COLUMNS = [
        'categorie', 'produit', 'description', 'prix_ttc', 'tva', 'taille_cl',
        'disponible', 'ingredient', 'unite', 'quantite', 'retirable', 'ajoutable',
    ];

    /** Bornes de securite (RG-T18) : au-dela, le fichier est refuse avant toute analyse ligne a ligne. */
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_DATA_LINES = 2000;

    /**
     * Valeurs par defaut d'un ingredient cree depuis l'import (comme depuis le
     * formulaire produit, meme constantes que ProductController) : stock 0
     * (RG-CREATE-ING), pack_size 1 (aucune colonne dediee dans le CSV),
     * capacite 100 (reference "100 %" du modele de stock en pourcentage,
     * mcd 5.3), seuils par defaut du projet (low_stock_pct/critical_stock_pct,
     * memes valeurs que les colonnes DEFAULT de la table `ingredient`).
     */
    public const DEFAULT_PACK_SIZE = 1;
    public const DEFAULT_STOCK_CAPACITY = 100;
    public const DEFAULT_LOW_STOCK_PCT = 10;
    public const DEFAULT_CRITICAL_STOCK_PCT = 5;

    /**
     * Gabarit CSV telechargeable (bouton "Telecharger le modele CSV") : en-tete +
     * 2 exemples reels (un burger a plusieurs ingredients, une boisson). BOM UTF-8
     * en tete : Excel FR l'exige pour reconnaitre l'UTF-8 a l'ouverture (sans BOM,
     * les caracteres accentues s'affichent mal) ; l'IMPORT, lui, accepte le
     * fichier AVEC ou SANS BOM (parse() le detecte et le retire).
     */
    public static function templateCsv(): string
    {
        $rows = [
            self::COLUMNS,
            ['Burgers', 'Cheeseburger Wakdo', 'Steak hache, cheddar, sauce maison', '6,90', '10', '', 'oui', 'Pain burger', 'piece', '1', 'non', 'non'],
            ['Burgers', 'Cheeseburger Wakdo', 'Steak hache, cheddar, sauce maison', '6,90', '10', '', 'oui', 'Steak hache', 'piece', '1', 'non', 'non'],
            ['Burgers', 'Cheeseburger Wakdo', 'Steak hache, cheddar, sauce maison', '6,90', '10', '', 'oui', 'Cheddar', 'tranche', '1', 'oui', 'oui'],
            ['Boissons', 'Coca-Cola', '', '2,50', '5,5', '33', 'oui', 'Coca-Cola', 'canette', '1', 'non', 'non'],
        ];

        $lines = [];
        foreach ($rows as $row) {
            $lines[] = implode(';', array_map([self::class, 'csvField'], $row));
        }

        // BOM UTF-8 (EF BB BF) : uniquement sur le gabarit TELECHARGE (confort
        // Excel FR) ; parse() accepte les deux formes en import.
        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }

    private static function csvField(string $value): string
    {
        if ($value === '' || !preg_match('/[;"\r\n]/', $value)) {
            return $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }

    /**
     * Analyse + validation d'un contenu CSV, SANS AUCUNE ecriture (dry-run).
     *
     * @return array{
     *   errors: list<array{line:int, column:string, message:string}>,
     *   productsToCreate: list<array<string,mixed>>,
     *   productsToUpdate: list<array<string,mixed>>,
     *   productsUnchanged: list<array<string,mixed>>,
     *   ingredientsExisting: list<array<string,mixed>>,
     *   ingredientsToCreate: list<array<string,mixed>>,
     *   totalDataLines: int,
     *   hasPriceChange: bool,
     *   plan: array<string,mixed>,
     * }
     */
    public function preview(string $rawContent, DatabaseInterface $db): array
    {
        $errors = [];

        if (strlen($rawContent) > self::MAX_BYTES) {
            $errors[] = ['line' => 0, 'column' => 'fichier', 'message' => sprintf('Fichier trop volumineux (%d Mo maximum).', (int) (self::MAX_BYTES / (1024 * 1024)))];

            return $this->emptyReport($errors);
        }

        $content = $this->stripBom($rawContent);
        if (trim($content) === '') {
            $errors[] = ['line' => 0, 'column' => 'fichier', 'message' => 'Le fichier est vide.'];

            return $this->emptyReport($errors);
        }

        $delimiter = $this->detectDelimiter($content);
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            $errors[] = ['line' => 0, 'column' => 'fichier', 'message' => 'Lecture du fichier impossible.'];

            return $this->emptyReport($errors);
        }
        fwrite($stream, $content);
        rewind($stream);

        $header = fgetcsv($stream, 0, $delimiter, '"', '\\');
        if ($header === false) {
            fclose($stream);
            $errors[] = ['line' => 1, 'column' => 'en-tête', 'message' => 'En-tête introuvable.'];

            return $this->emptyReport($errors);
        }
        $header = array_map(static fn (?string $h): string => mb_strtolower(trim((string) $h)), $header);
        if ($header !== self::COLUMNS) {
            fclose($stream);
            $errors[] = [
                'line' => 1,
                'column' => 'en-tête',
                'message' => 'En-tête invalide. Colonnes attendues, dans cet ordre : ' . implode(', ', self::COLUMNS) . '.',
            ];

            return $this->emptyReport($errors);
        }

        /** @var list<array{line:int, values: array<string,string>}> $rows */
        $rows = [];
        $line = 1;
        $columnCount = count(self::COLUMNS);
        while (($fields = fgetcsv($stream, 0, $delimiter, '"', '\\')) !== false) {
            $line++;
            if ($fields === [null]) {
                continue; // ligne vide (fgetcsv la rend comme [null])
            }
            if (count($rows) >= self::MAX_DATA_LINES) {
                $errors[] = ['line' => $line, 'column' => 'fichier', 'message' => sprintf('Trop de lignes (%d maximum). Le fichier est refusé au-delà.', self::MAX_DATA_LINES)];
                break;
            }
            if (count($fields) !== $columnCount) {
                $errors[] = ['line' => $line, 'column' => 'colonnes', 'message' => sprintf('Nombre de colonnes incorrect (attendu %d, obtenu %d).', $columnCount, count($fields))];
                continue;
            }

            // Trim SANS la tabulation (charlist explicite, PAS le trim() par
            // defaut) : une tabulation de tete est un des marqueurs
            // d'injection de formule tableur detectes plus bas
            // (looksLikeFormula()) -- un trim() par defaut l'aurait effacee
            // avant meme la detection, ouvrant exactement le trou qu'elle est
            // censee fermer.
            $values = array_combine(self::COLUMNS, array_map(static fn (?string $v): string => trim((string) $v, " \n\r\0\x0B"), $fields));
            $rows[] = ['line' => $line, 'values' => $values];
        }
        fclose($stream);

        return $this->buildReport($rows, $errors, $db);
    }

    /**
     * Rejoue preview() puis, si zero erreur, ecrit TOUT dans une seule
     * transaction : ingredients nouveaux (stock 0), produits (creation ou mise a
     * jour), et recette de chaque produit (REMPLACEE, delete-and-reinsert). Une
     * ligne d'audit unique ('product.import') resume les compteurs.
     *
     * @throws ImportBlockedException si le rapport recalcule porte encore des erreurs
     * @return array{created:int, updated:int, unchanged:int, ingredients_created:int, price_changed:int, audit_summary:string}
     */
    public function apply(string $rawContent, DatabaseInterface $db, ?int $actorUserId, ?int $actorRoleId): array
    {
        $report = $this->preview($rawContent, $db);
        if ($report['errors'] !== []) {
            throw new ImportBlockedException('Le fichier contient des erreurs : importation refusée (rien n\'a été écrit).');
        }

        $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'ingredients_created' => 0, 'price_changed' => 0, 'audit_summary' => ''];

        $db->transaction(function (DatabaseInterface $tx) use ($report, &$result, $actorUserId, $actorRoleId): void {
            $ingredientRepo = new IngredientRepository($tx);
            $productRepo = new ProductRepository($tx);

            /** @var array<string, int> $newIngredientIds nom (clé normalisée) => id reel */
            $newIngredientIds = [];
            foreach ($report['ingredientsToCreate'] as $ing) {
                $ingredientRepo->create([
                    'name'               => $ing['name'],
                    'unit'               => $ing['unit'],
                    'stock_quantity'     => 0,
                    'stock_capacity'     => self::DEFAULT_STOCK_CAPACITY,
                    'pack_size'          => self::DEFAULT_PACK_SIZE,
                    'pack_label'         => null,
                    'low_stock_pct'      => self::DEFAULT_LOW_STOCK_PCT,
                    'critical_stock_pct' => self::DEFAULT_CRITICAL_STOCK_PCT,
                    'is_active'          => 1,
                ]);
                $newIngredientIds[self::normalize($ing['name'])] = $this->lastInsertId($tx);
                $result['ingredients_created']++;
            }

            $priceChangedNames = [];

            foreach ($report['plan']['products'] as $product) {
                $data = [
                    'category_id'             => $product['category_id'],
                    'name'                    => $product['name'],
                    'description'             => $product['description'],
                    'price_cents'             => $product['price_cents'],
                    'size_cl'                 => $product['size_cl'],
                    'base_product_id'         => null,
                    'maxi_variant_product_id' => null,
                    'vat_rate'                => $product['vat_rate'],
                    'image_path'              => $product['image_path'],
                    'is_available'            => $product['is_available'],
                    'display_order'           => $product['display_order'],
                ];

                if ($product['action'] === 'create') {
                    $productRepo->create($data);
                    $productId = $this->lastInsertId($tx);
                    $result['created']++;
                } else {
                    $productId = $product['existing_id'];
                    $productRepo->update($productId, $data);
                    if ($product['action'] === 'update') {
                        $result['updated']++;
                    } else {
                        $result['unchanged']++;
                    }
                    if ($product['price_changed']) {
                        $result['price_changed']++;
                        $priceChangedNames[] = $product['name'];
                    }
                }

                $lines = [];
                foreach ($product['recipe'] as $recipeLine) {
                    $ingredientId = $recipeLine['ingredient_id']
                        ?? ($newIngredientIds[self::normalize($recipeLine['ingredient_name'])] ?? null);
                    if ($ingredientId === null) {
                        continue; // garde defensive : ne devrait jamais arriver (preview a deja resolu chaque ligne)
                    }
                    $lines[] = [
                        'ingredient_id'     => $ingredientId,
                        'quantity_normal'   => $recipeLine['quantity'],
                        'quantity_maxi'     => $recipeLine['quantity'],
                        'is_removable'      => $recipeLine['is_removable'],
                        'is_addable'        => $recipeLine['is_addable'],
                        'extra_price_cents' => 0,
                    ];
                }
                // Remplacement INCONDITIONNEL (decision produit 2026-09-26) : y
                // compris vider la recette d'un produit existant si le CSV ne
                // porte aucune ligne ingredient pour lui.
                $productRepo->replaceCompositionWithin($tx, $productId, $lines);
            }

            $summary = sprintf(
                'Import CSV produits : %d créé(s), %d mis à jour (dont %d changement(s) de prix), %d inchangé(s), %d ingrédient(s) créé(s)',
                $result['created'],
                $result['updated'],
                $result['price_changed'],
                $result['unchanged'],
                $result['ingredients_created'],
            );
            if ($priceChangedNames !== []) {
                $summary .= ' [' . implode(', ', array_slice($priceChangedNames, 0, 10)) . ($priceChangedNames !== array_slice($priceChangedNames, 0, 10) ? ', ...' : '') . ']';
            }
            $summary = mb_substr($summary, 0, 255);
            $result['audit_summary'] = $summary;

            $tx->execute(
                'INSERT INTO audit_log (actor_user_id, actor_role_id, action_code, entity_type, entity_id, summary) '
                . 'VALUES (:uid, :rid, :code, :etype, :eid, :summary)',
                ['uid' => $actorUserId, 'rid' => $actorRoleId, 'code' => 'product.import', 'etype' => 'product', 'eid' => 0, 'summary' => $summary],
            );
        });

        return $result;
    }

    /**
     * @param list<array{line:int, values: array<string,string>}> $rows
     * @param list<array{line:int, column:string, message:string}> $errors
     * @return array<string,mixed>
     */
    private function buildReport(array $rows, array $errors, DatabaseInterface $db): array
    {
        // --- 1. Controles ligne a ligne (injection de formule, colonnes de
        //        TEXTE seulement -- prix/TVA/quantite/taille sont numeriques et
        //        valides plus bas par leurs propres bornes, un texte n'y a de
        //        toute facon aucun sens). ---
        $textColumns = ['categorie', 'produit', 'description', 'ingredient', 'unite'];
        foreach ($rows as $row) {
            foreach ($textColumns as $col) {
                $value = $row['values'][$col];
                if ($value !== '' && $this->looksLikeFormula($value)) {
                    $errors[] = ['line' => $row['line'], 'column' => $col, 'message' => $this->formulaRejectionMessage($value)];
                }
            }
        }

        // --- 2. Regroupement par produit (nom + categorie), controle de coherence. ---
        /** @var array<string, array{line:int, categorie:string, produit:string, description:string, prix_ttc:string, tva:string, taille_cl:string, disponible:string, rows: list<array{line:int, values: array<string,string>}>}> $byProduct */
        $byProduct = [];
        $productOrder = [];
        foreach ($rows as $row) {
            $v = $row['values'];
            $key = self::normalize($v['categorie']) . '|' . self::normalize($v['produit']);
            if (!isset($byProduct[$key])) {
                $byProduct[$key] = [
                    'line' => $row['line'],
                    'categorie' => $v['categorie'], 'produit' => $v['produit'], 'description' => $v['description'],
                    'prix_ttc' => $v['prix_ttc'], 'tva' => $v['tva'], 'taille_cl' => $v['taille_cl'], 'disponible' => $v['disponible'],
                    'rows' => [],
                ];
                $productOrder[] = $key;
            } else {
                foreach (['categorie', 'produit', 'description', 'prix_ttc', 'tva', 'taille_cl', 'disponible'] as $col) {
                    if ($v[$col] !== $byProduct[$key][$col]) {
                        $errors[] = ['line' => $row['line'], 'column' => $col, 'message' => sprintf('Valeur incohérente avec la ligne %d pour le même produit (colonne "%s").', $byProduct[$key]['line'], $col)];
                    }
                }
            }
            $byProduct[$key]['rows'][] = $row;
        }

        // --- 3. Ingredients : dedup global par nom, unite coherente entre occurrences. ---
        /** @var array<string, array{name:string, unit:string, line:int}> $ingredientDecl */
        $ingredientDecl = [];
        foreach ($rows as $row) {
            $ing = $row['values']['ingredient'];
            if ($ing === '') {
                continue;
            }
            $normalized = self::normalize($ing);
            $unit = $row['values']['unite'];
            if (!isset($ingredientDecl[$normalized])) {
                $ingredientDecl[$normalized] = ['name' => $ing, 'unit' => $unit, 'line' => $row['line']];
            } elseif ($unit !== '' && $ingredientDecl[$normalized]['unit'] !== '' && self::normalize($unit) !== self::normalize($ingredientDecl[$normalized]['unit'])) {
                $errors[] = ['line' => $row['line'], 'column' => 'unite', 'message' => sprintf('Unité incohérente avec la ligne %d pour le même ingrédient "%s".', $ingredientDecl[$normalized]['line'], $ing)];
            } elseif ($ingredientDecl[$normalized]['unit'] === '' && $unit !== '') {
                $ingredientDecl[$normalized]['unit'] = $unit; // complete l'unite si la premiere occurrence l'avait laissee vide
            }
        }

        // Resolution DB des ingredients declares : existants vs a creer.
        $ingredientsExisting = [];
        $ingredientsToCreate = [];
        /** @var array<string, array{id:?int, unit:string}> $ingredientResolved */
        $ingredientResolved = [];
        foreach ($ingredientDecl as $normalized => $decl) {
            $existing = $db->fetch('SELECT id, unit FROM ingredient WHERE LOWER(name) = LOWER(:name) LIMIT 1', ['name' => $decl['name']]);
            if ($existing !== null) {
                $existingUnit = (string) $existing['unit'];
                if ($decl['unit'] !== '' && self::normalize($decl['unit']) !== self::normalize($existingUnit)) {
                    $errors[] = ['line' => $decl['line'], 'column' => 'unite', 'message' => sprintf('Unité "%s" différente de l\'unité existante ("%s") pour l\'ingrédient "%s".', $decl['unit'], $existingUnit, $decl['name'])];
                }
                $ingredientResolved[$normalized] = ['id' => (int) $existing['id'], 'unit' => $existingUnit];
                $ingredientsExisting[] = ['line' => $decl['line'], 'name' => $decl['name'], 'id' => (int) $existing['id'], 'unit' => $existingUnit];
            } else {
                // Memes bornes ET memes messages que la ligne "nouvel ingredient"
                // du formulaire produit (ProductController::parseCompositionLines) :
                // sans ce controle ICI, l'apercu annoncait "aucune erreur" puis
                // l'ecriture echouait (colonne ingredient.name/unit VARCHAR(120/40),
                // exception non prevue jusqu'a la confirmation).
                if (mb_strlen($decl['name']) > 120) {
                    $errors[] = ['line' => $decl['line'], 'column' => 'ingredient', 'message' => 'Le nom du nouvel ingrédient est requis (120 caractères max).'];
                }
                if ($decl['unit'] === '') {
                    $errors[] = ['line' => $decl['line'], 'column' => 'unite', 'message' => sprintf('Unité requise : l\'ingrédient "%s" n\'existe pas encore et sera créé.', $decl['name'])];
                } elseif (mb_strlen($decl['unit']) > 40) {
                    $errors[] = ['line' => $decl['line'], 'column' => 'unite', 'message' => 'L\'unité du nouvel ingrédient est requise (40 caractères max).'];
                }
                $ingredientResolved[$normalized] = ['id' => null, 'unit' => $decl['unit']];
                $ingredientsToCreate[] = ['name' => $decl['name'], 'unit' => $decl['unit'], 'first_line' => $decl['line']];
            }
        }

        // --- 4. Par produit : categorie, champs, doublons d'ingredient, recette. ---
        $productsToCreate = [];
        $productsToUpdate = [];
        $productsUnchanged = [];
        $planProducts = [];
        $hasPriceChange = false;

        foreach ($productOrder as $key) {
            $p = $byProduct[$key];
            $categoryId = null;
            $categoryName = $p['categorie'];
            if ($categoryName === '') {
                $errors[] = ['line' => $p['line'], 'column' => 'categorie', 'message' => 'Catégorie requise.'];
            } else {
                // Placeholders DISTINCTS (name_match / slug_match) portant la
                // meme valeur : en prepare NATIVE (Database, ATTR_EMULATE_PREPARES
                // = false), un meme nom de placeholder ne peut PAS apparaitre deux
                // fois dans une requete, sinon MariaDB rejette en SQLSTATE HY093
                // (Invalid parameter number) -- meme regle deja documentee sur
                // ProductRepository::sizesForProduct().
                $category = ctype_digit($categoryName)
                    ? $db->fetch('SELECT id, name FROM category WHERE id = :id', ['id' => (int) $categoryName])
                    : $db->fetch(
                        'SELECT id, name FROM category WHERE LOWER(name) = LOWER(:name_match) OR LOWER(slug) = LOWER(:slug_match) LIMIT 1',
                        ['name_match' => $categoryName, 'slug_match' => $categoryName],
                    );
                if ($category === null) {
                    $errors[] = ['line' => $p['line'], 'column' => 'categorie', 'message' => sprintf('Catégorie inconnue : "%s".', $categoryName)];
                } else {
                    $categoryId = (int) $category['id'];
                }
            }

            if (trim($p['produit']) === '' || mb_strlen($p['produit']) > 120) {
                $errors[] = ['line' => $p['line'], 'column' => 'produit', 'message' => 'Nom de produit requis (120 caractères maximum).'];
            }

            // `product.description` est une colonne TEXT (pas VARCHAR) : sa
            // limite MariaDB est en OCTETS (65535), pas en caracteres -- d'ou
            // strlen() (comptage octets) et non mb_strlen() (comptage
            // caracteres) ici, a la difference du nom de produit ci-dessus
            // (VARCHAR(120), limite en caracteres). Meme classe de defaut que
            // le nom/l'unite d'ingredient (relecture adverse, 2026-09-26) :
            // sans ce controle, l'apercu annoncait "aucune erreur" pour une
            // description de 70000 caracteres, puis l'ecriture echouait.
            if (strlen($p['description']) > 65535) {
                $errors[] = ['line' => $p['line'], 'column' => 'description', 'message' => 'La description est trop longue (environ 65 000 caractères maximum, moins si le texte contient beaucoup de caractères spéciaux).'];
            }

            $priceCents = Money::parseEurosToCents($p['prix_ttc']);
            if ($priceCents === null) {
                $errors[] = ['line' => $p['line'], 'column' => 'prix_ttc', 'message' => sprintf('Montant invalide : "%s" (exemple attendu : 6,90).', $p['prix_ttc'])];
            }

            $vatRate = match (str_replace(',', '.', trim($p['tva']))) {
                '5.5' => 55,
                '10' => 100,
                default => null,
            };
            if ($vatRate === null) {
                $errors[] = ['line' => $p['line'], 'column' => 'tva', 'message' => sprintf('TVA invalide : "%s". Seules les valeurs 5,5 et 10 existent dans Wakdo.', $p['tva'])];
            }

            $sizeCl = null;
            if ($p['taille_cl'] !== '') {
                if (!ctype_digit($p['taille_cl']) || (int) $p['taille_cl'] > 65535) {
                    $errors[] = ['line' => $p['line'], 'column' => 'taille_cl', 'message' => 'La taille (cl) doit être un entier entre 0 et 65535, ou vide.'];
                } else {
                    $sizeCl = (int) $p['taille_cl'];
                }
            }

            $isAvailable = match (self::normalize($p['disponible'])) {
                '', 'oui' => 1,
                'non' => 0,
                default => null,
            };
            if ($isAvailable === null) {
                $errors[] = ['line' => $p['line'], 'column' => 'disponible', 'message' => sprintf('Valeur invalide : "%s" (attendu "oui" ou "non", vide = oui).', $p['disponible'])];
                $isAvailable = 1;
            }

            // Recette du produit : dedup par ingredient, quantite/flags par ligne.
            $seenIngredients = [];
            $recipe = [];
            foreach ($p['rows'] as $row) {
                $ingName = $row['values']['ingredient'];
                if ($ingName === '') {
                    continue; // ligne sans ingredient : autorisee, ne contribue aucune ligne de recette
                }
                $normalizedIng = self::normalize($ingName);
                if (isset($seenIngredients[$normalizedIng])) {
                    $errors[] = ['line' => $row['line'], 'column' => 'ingredient', 'message' => sprintf('Ingrédient "%s" en doublon pour ce produit.', $ingName)];
                    continue;
                }
                $seenIngredients[$normalizedIng] = true;

                $quantityRaw = $row['values']['quantite'];
                $quantity = ctype_digit($quantityRaw) ? (int) $quantityRaw : null;
                if ($quantity === null || $quantity < 1 || $quantity > 65535) {
                    $errors[] = ['line' => $row['line'], 'column' => 'quantite', 'message' => sprintf('Quantité invalide : "%s" (entier entre 1 et 65535 attendu).', $quantityRaw)];
                    continue;
                }

                $removable = match (self::normalize($row['values']['retirable'])) {
                    '', 'non' => 0,
                    'oui' => 1,
                    default => null,
                };
                $addable = match (self::normalize($row['values']['ajoutable'])) {
                    '', 'non' => 0,
                    'oui' => 1,
                    default => null,
                };
                if ($removable === null) {
                    $errors[] = ['line' => $row['line'], 'column' => 'retirable', 'message' => sprintf('Valeur invalide : "%s" (attendu "oui" ou "non", vide = non).', $row['values']['retirable'])];
                }
                if ($addable === null) {
                    $errors[] = ['line' => $row['line'], 'column' => 'ajoutable', 'message' => sprintf('Valeur invalide : "%s" (attendu "oui" ou "non", vide = non).', $row['values']['ajoutable'])];
                }

                $resolved = $ingredientResolved[$normalizedIng] ?? ['id' => null, 'unit' => ''];
                $recipe[] = [
                    'ingredient_id'   => $resolved['id'],
                    'ingredient_name' => $ingName,
                    'quantity'        => $quantity,
                    'is_removable'    => $removable ?? 0,
                    'is_addable'      => $addable ?? 0,
                ];
            }

            // Produit existant ? (nom + categorie, insensible a la casse).
            $existingId = null;
            $priceChanged = false;
            $action = 'create';
            if ($categoryId !== null) {
                $existing = $db->fetch(
                    'SELECT id, price_cents FROM product WHERE category_id = :cat AND LOWER(name) = LOWER(:name) LIMIT 1',
                    ['cat' => $categoryId, 'name' => $p['produit']],
                );
                if ($existing !== null) {
                    $existingId = (int) $existing['id'];
                    $priceChanged = $priceCents !== null && $priceCents !== (int) $existing['price_cents'];
                    $action = 'unchanged';
                }
            }

            $descNormalized = $p['description'] !== '' ? $p['description'] : null;

            if ($existingId !== null) {
                $current = $db->fetch(
                    'SELECT description, price_cents, vat_rate, size_cl, is_available, display_order, image_path '
                    . 'FROM product WHERE id = :id',
                    ['id' => $existingId],
                );
                $changed = $current !== null && (
                    ($current['description'] ?? null) !== $descNormalized
                    || (int) ($current['price_cents'] ?? 0) !== $priceCents
                    || (int) ($current['vat_rate'] ?? 0) !== $vatRate
                    || ($current['size_cl'] !== null ? (int) $current['size_cl'] : null) !== $sizeCl
                    || (int) ($current['is_available'] ?? 0) !== $isAvailable
                );
                $action = $changed ? 'update' : 'unchanged';
                $displayOrder = (int) ($current['display_order'] ?? 0);
                $imagePath = $current['image_path'] ?? null;
            } else {
                $displayOrder = 0;
                $imagePath = null;
            }

            $entry = [
                'line'               => $p['line'],
                'name'               => $p['produit'],
                'category_name'      => $categoryName,
                'category_id'        => $categoryId,
                'description'        => $descNormalized,
                'price_cents'        => $priceCents,
                'vat_rate'           => $vatRate,
                'size_cl'            => $sizeCl,
                'is_available'       => $isAvailable,
                'display_order'      => $displayOrder,
                'image_path'         => $imagePath,
                'action'             => $action,
                'existing_id'        => $existingId,
                'price_changed'      => $priceChanged,
                'recipe_line_count'  => count($recipe),
                'recipe'             => $recipe,
            ];

            if ($priceChanged) {
                $hasPriceChange = true;
            }
            match ($action) {
                'create' => $productsToCreate[] = $entry,
                'update' => $productsToUpdate[] = $entry,
                default => $productsUnchanged[] = $entry,
            };
            $planProducts[] = $entry;
        }

        return [
            'errors'              => $errors,
            'productsToCreate'    => $productsToCreate,
            'productsToUpdate'    => $productsToUpdate,
            'productsUnchanged'   => $productsUnchanged,
            'ingredientsExisting' => $ingredientsExisting,
            'ingredientsToCreate' => $ingredientsToCreate,
            'totalDataLines'      => count($rows),
            'hasPriceChange'      => $hasPriceChange,
            'plan'                => ['products' => $planProducts],
        ];
    }

    /**
     * @param list<array{line:int, column:string, message:string}> $errors
     * @return array<string,mixed>
     */
    private function emptyReport(array $errors): array
    {
        return [
            'errors' => $errors,
            'productsToCreate' => [], 'productsToUpdate' => [], 'productsUnchanged' => [],
            'ingredientsExisting' => [], 'ingredientsToCreate' => [],
            'totalDataLines' => 0, 'hasPriceChange' => false,
            'plan' => ['products' => []],
        ];
    }

    private function stripBom(string $content): string
    {
        return str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
    }

    /**
     * Compte ';' vs ',' sur la premiere ligne physique : Excel FR exporte en
     * ';' (la virgule est le separateur decimal francais), Excel/Google Sheets EN
     * exportent en ','. Egalite ou aucun des deux trouve -> ';' par defaut
     * (convention Wakdo, coherente avec le gabarit telechargeable).
     */
    private function detectDelimiter(string $content): string
    {
        $firstLine = strtok($content, "\n") ?: $content;
        $semi = substr_count($firstLine, ';');
        $comma = substr_count($firstLine, ',');

        return $comma > $semi ? ',' : ';';
    }

    /**
     * Detection de l'injection de formule tableur (OWASP "CSV Injection"),
     * REVUE pour ne pas refuser une saisie de texte normale.
     *
     * `=`, `@` et une tabulation de tete sont refuses INCONDITIONNELLEMENT :
     * un tableur (Excel, LibreOffice, Google Sheets) evalue tout contenu qui
     * commence par `=` comme une formule quel que soit ce qui suit, et un `@`
     * de tete declenche l'operateur d'intersection implicite d'Excel -- aucune
     * saisie legitime de nos colonnes de texte (nom, description, ingredient,
     * unite) ne commence normalement par l'un de ces caracteres.
     *
     * `+`/`-` de tete sont un cas different : une description commencant par
     * "- Sans gluten", ou un ingredient "-Économique" ou "+Supplément inclus",
     * sont des saisies COURANTES, pas des formules -- un tableur n'evalue un
     * "+"/"-" de tete que si la SUITE compose une expression numerique/de
     * reference (un chiffre, un autre operateur, une parenthese...) ; suivi
     * d'une LETTRE (accentuee comprise) ou d'un ESPACE, il reste affiche
     * comme du texte litteral, jamais recalcule. La regle ne refuse donc
     * `+`/`-` que dans ce dernier cas [HYPOTHESIS, comportement observe des
     * tableurs courants -- pas une specification ecrite, a revalider si un cas
     * limite remonte].
     *
     * Consequence ASSUMEE (releve par relecture adverse, 2026-09-26) : une
     * remise ecrite en chiffres avec un tiret de tete, par exemple "-30%
     * aujourd'hui", est REFUSEE -- le tiret y est suivi d'un CHIFFRE, exactement
     * la forme qu'un tableur peut prolonger en expression numerique (et la
     * forme de tete d'une charge utile d'injection reelle, ex. "-2+3+cmd|'/c
     * calc'!A0"). Il n'existe pas de regle simple et sure qui distinguerait
     * "-30%" (bareme benin) de "-2+3+cmd|..." (charge utile) en ne regardant
     * que les premiers caracteres : le cout d'un contournement de securite
     * l'emporte sur le confort de saisir une remise sous cette forme precise.
     * L'equipier reformule ("30% de réduction", "moins 30%") ; le message
     * d'erreur le dit explicitement (retirer le signe en tete de cellule).
     */
    /**
     * Caracteres separateurs Unicode (categorie Z : espace normal, espace
     * insecable, espace fine, espace cadratin, espace ideographique...) et
     * caracteres de mise en forme invisibles (categorie Cf : largeur nulle,
     * BOM, marques directionnelles, assembleur de mots...), retires en TETE DE
     * CELLULE SEULEMENT (pas dans tout le texte : un espace insecable au
     * milieu d'une phrase n'a rien de suspect), de proche en proche -- une
     * charge utile pourrait en empiler plusieurs, dans n'importe quel ordre.
     *
     * Une CLASSE Unicode plutot qu'une enumeration figee : une liste de
     * caracteres invisibles connus en laisse toujours passer un qui n'y
     * figure pas, et cree une asymetrie selon l'ordre -- releve par relecture
     * adverse n°3 (2026-09-26) sur l'ancienne enumeration a 6 entrees : un
     * caractere hors-liste (par exemple l'espace cadratin U+2002, ou un simple
     * espace ordinaire U+0020) arretait la boucle avant d'atteindre un "="
     * plus loin, MEME quand un caractere de la liste le precedait ("NBSP puis
     * espace ordinaire puis =" passait, alors que "espace puis NBSP puis ="
     * etait intercepte -- pur hasard d'ordre, pas une regle). La classe
     * `\p{Z}`/`\p{Cf}` couvre tout separateur/format Unicode d'un coup, quel
     * que soit le caractere ou l'ordre : plus d'enumeration a tenir a jour, et
     * plus d'asymetrie possible par construction.
     */
    private function stripLeadingInvisibleChars(string $value): string
    {
        while ($value !== '' && preg_match('/^[\p{Z}\p{Cf}]/u', $value) === 1) {
            $value = mb_substr($value, 1);
        }

        return $value;
    }

    /**
     * Homoglyphes visuels des 4 caracteres declencheurs, ramenes a leur
     * equivalent ASCII avant test -- sinon ils contournent la detection a
     * l'identique visuel pres. Table etendue en relecture adverse n°3
     * (2026-09-26) : seul "=" pleine chasse (U+FF1D) etait couvert ; "+"/"-"
     * pleine chasse, le signe moins mathematique et "@" pleine chasse ne
     * l'etaient pas.
     */
    private const HOMOGLYPH_MAP = [
        "\u{FF1D}" => '=', // "＝" signe egal pleine chasse
        "\u{FF0B}" => '+', // "＋" signe plus pleine chasse
        "\u{FF0D}" => '-', // "－" signe moins pleine chasse
        "\u{2212}" => '-', // "−" signe moins mathematique
        "\u{FE63}" => '-', // "﹣" petit trait d'union-moins
        "\u{FF20}" => '@', // "＠" arobase pleine chasse
    ];

    private function looksLikeFormula(string $value): bool
    {
        $value = $this->stripLeadingInvisibleChars($value);

        $first = mb_substr($value, 0, 1);
        $first = self::HOMOGLYPH_MAP[$first] ?? $first;
        if ($first === '=' || $first === '@' || $first === "\t") {
            return true;
        }
        if ($first !== '+' && $first !== '-') {
            return false;
        }

        $second = mb_substr($value, 1, 1);

        return !($second === '' || $second === ' ' || preg_match('/^\p{L}$/u', $second) === 1);
    }

    /**
     * Le message de base convient a "=CMD"/"@CMD"/tabulation de tete : rien a
     * voir avec une remise. La demi-phrase de reformulation n'est ajoutee que
     * pour "+"/"-" (le cas "-30% aujourd'hui") -- la doc pretait deja cette
     * suggestion au message avant ce correctif (relecture adverse n°3,
     * 2026-09-26) ; elle est maintenant reellement dedans, uniquement quand
     * elle a un sens.
     */
    private function formulaRejectionMessage(string $value): string
    {
        $stripped = $this->stripLeadingInvisibleChars($value);
        $first = mb_substr($stripped, 0, 1);
        $first = self::HOMOGLYPH_MAP[$first] ?? $first;

        $message = 'Cette cellule commence par un caractère que les tableurs interprètent comme un calcul. Retirez ce signe en début de cellule.';
        if ($first === '+' || $first === '-') {
            $message .= ' Écrivez plutôt "30 % de réduction".';
        }

        return $message;
    }

    private static function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function lastInsertId(DatabaseInterface $db): int
    {
        return (int) ($db->fetch('SELECT LAST_INSERT_ID() AS id')['id'] ?? 0);
    }
}
