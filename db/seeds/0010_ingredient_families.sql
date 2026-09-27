-- =============================================================================
-- Wakdo — Seed 0010 : correspondance categorie -> familles d'ingredients
-- =============================================================================
-- Peuple `category_ingredient_family` (migration 0017), qui restreint le
-- selecteur d'ingredients du formulaire produit aux familles pertinentes pour la
-- categorie du produit en cours d'edition. Une categorie ABSENTE de ce jeu de
-- donnees (ex. `menus`) n'a AUCUNE ligne ici : le formulaire ne filtre alors rien
-- pour elle (un menu peut construire son burger impose avec n'importe quelle
-- famille d'ingredient).
--
-- Fichier VOLONTAIREMENT hors migrations : les migrations s'executent AVANT tous
-- les seeds (voir db/migrate-container.sh), donc sur une installation neuve,
-- `category` serait encore vide au moment ou une migration s'executerait -- la
-- correspondance ne pourrait reference aucun category_id. Ce fichier, lui,
-- s'execute apres le seed 0002 (qui pose les 9 categories), sur une installation
-- neuve COMME sur une installation existante (son nom de fichier n'est pas dans
-- seeds_applied, donc rien n'empeche de le rejouer si besoin).
--
-- Resolution par SLUG (pas par id) : le slug est l'identifiant technique stable
-- de la categorie (voir dictionary.md 3.1) ; l'id AUTO_INCREMENT ne l'est pas.
-- INSERT IGNORE : idempotent (PK composite (category_id, family), meme discipline
-- que le reste du seeding par sous-requete, convention seed 0002/0003).
--
-- Familles canoniques (ordre + slugs exacts) : App\Catalogue\IngredientFamily.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- burgers : pain, viande, fromage, legume, sauce
INSERT IGNORE INTO category_ingredient_family (category_id, family)
SELECT id, family FROM category
CROSS JOIN (
    SELECT 'pain' AS family UNION ALL SELECT 'viande' UNION ALL SELECT 'fromage'
    UNION ALL SELECT 'legume' UNION ALL SELECT 'sauce'
) AS f
WHERE category.slug = 'burgers';

-- wraps : pain, viande, fromage, legume, sauce (meme correspondance que burgers)
INSERT IGNORE INTO category_ingredient_family (category_id, family)
SELECT id, family FROM category
CROSS JOIN (
    SELECT 'pain' AS family UNION ALL SELECT 'viande' UNION ALL SELECT 'fromage'
    UNION ALL SELECT 'legume' UNION ALL SELECT 'sauce'
) AS f
WHERE category.slug = 'wraps';

-- salades : legume, fromage, viande, sauce (pas de pain)
INSERT IGNORE INTO category_ingredient_family (category_id, family)
SELECT id, family FROM category
CROSS JOIN (
    SELECT 'legume' AS family UNION ALL SELECT 'fromage' UNION ALL SELECT 'viande'
    UNION ALL SELECT 'sauce'
) AS f
WHERE category.slug = 'salades';

-- frites : feculent, dosette
INSERT IGNORE INTO category_ingredient_family (category_id, family)
SELECT id, family FROM category
CROSS JOIN (
    SELECT 'feculent' AS family UNION ALL SELECT 'dosette'
) AS f
WHERE category.slug = 'frites';

-- encas : viande, sauce, dosette
INSERT IGNORE INTO category_ingredient_family (category_id, family)
SELECT id, family FROM category
CROSS JOIN (
    SELECT 'viande' AS family UNION ALL SELECT 'sauce' UNION ALL SELECT 'dosette'
) AS f
WHERE category.slug = 'encas';

-- boissons : dose_boisson, contenant
INSERT IGNORE INTO category_ingredient_family (category_id, family)
SELECT id, family FROM category
CROSS JOIN (
    SELECT 'dose_boisson' AS family UNION ALL SELECT 'contenant'
) AS f
WHERE category.slug = 'boissons';

-- desserts : dessert
INSERT IGNORE INTO category_ingredient_family (category_id, family)
SELECT id, 'dessert' FROM category WHERE slug = 'desserts';

-- sauces : dosette
INSERT IGNORE INTO category_ingredient_family (category_id, family)
SELECT id, 'dosette' FROM category WHERE slug = 'sauces';

-- menus : AUCUNE ligne (pas de restriction) -- absence volontaire, pas un oubli.
