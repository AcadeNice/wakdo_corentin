-- db/migrations/0017_ingredient_family.sql
-- =============================================================================
-- Wakdo - Migration 0017 : classement des ingredients par famille
-- =============================================================================
-- Purpose : le selecteur d'ingredients du formulaire produit proposait les 50
--           ingredients a plat, sans filtre par categorie de produit : on se
--           voyait proposer "Brownie" ou "Gobelet" en composant un burger. Cette
--           migration ajoute une classification en dix familles (`ingredient.family`)
--           et une table de correspondance categorie -> familles autorisees
--           (`category_ingredient_family`), qui permettent au formulaire de filtrer
--           le selecteur sans jamais supprimer un ingredient : "Brownie" reste un
--           ingredient legitime (le produit Brownie en a 1x dans sa recette, c'est
--           ce qui decremente son stock), simplement classe en famille `dessert`.
--
--           Repond aussi a une limite de modelisation documentee dans
--           docs/adr/0015-allergenes-calcules-par-produit.md (consequences) :
--           `Gobelet` y est un ingredient de recette (pour le stock) alors que ce
--           n'est pas un aliment mais un materiau au contact des denrees (reglement
--           1935/2004). L'ADR proposait un drapeau `is_food` si d'autres emballages
--           entraient au catalogue ; la famille `contenant` couvre ce cas plus
--           generalement (et toute famille autre que `contenant` vaut aliment),
--           sans ajouter de colonne redondante.
--
-- Familles (dix, ordre canonique, source unique du cote applicatif :
--           App\Catalogue\IngredientFamily) : pain, viande, fromage, legume, sauce,
--           feculent, dose_boisson, contenant, dessert, dosette.
--
-- Deux effets, dans CETTE migration (elle s'execute AVANT tous les seeds -- voir
-- db/migrate-container.sh -- donc sur une base neuve, `category` est encore VIDE :
-- la correspondance categorie -> familles ne peut PAS etre posee ici, elle vit dans
-- le seed 0010_ingredient_families.sql, qui s'execute apres et fonctionne aussi bien
-- a l'init qu'en reprise) :
--
--   1. Colonne additive `ingredient.family` VARCHAR(32) NULL (comme 0005/0011).
--      NULL = non classe = visible dans toutes les categories (degradation sure :
--      un ingredient qui n'a pas encore ete classe ne disparait d'aucun formulaire).
--   2. Table `category_ingredient_family` (jointure pure, comme 0002 pin_throttle
--      pour la forme CREATE TABLE IF NOT EXISTS). Zero ligne pour une categorie =
--      aucun filtre pour cette categorie (ex. `menus`, qui traverse toutes les
--      familles par nature).
--   3. Reprise des 50 ingredients de demonstration deja en place (seed 0003) :
--      classes ici, PAS dans le seed, car un jeu de donnees deja applique
--      (seeds_applied) n'est jamais rejoue sur une installation existante -- seul
--      0003 lui-meme porte desormais la famille pour une installation NEUVE. Les
--      deux doivent classer IDENTIQUEMENT les 50 noms (verifie par
--      IngredientFamilyMigrationDbTest::testMigrationAndSeedAgreeOnAllFiftyIngredients).
--
-- Idempotence : colonne gardee par information_schema (comme 0005/0011) ; table
-- gardee par IF NOT EXISTS (comme 0002) ; reprise des 50 ingredients gardee par
-- `family IS NULL` -- un ingredient qui porte DEJA une famille (reprise precedente,
-- OU correction manuelle faite en production) n'est jamais reecrit. Rejouer ce
-- fichier ne modifie donc aucune ligne une fois applique une premiere fois, ET ne
-- peut pas ecraser une reclassification faite a la main.
-- Target  : MariaDB 11.4 LTS, InnoDB, utf8mb4 / utf8mb4_unicode_ci.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 1. ingredient.family (additive, nullable)
-- -----------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ingredient' AND column_name = 'family'
);

SET @ddl := IF(
    @col_exists = 0,
    'ALTER TABLE ingredient ADD COLUMN family VARCHAR(32) NULL AFTER unit',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2. category_ingredient_family — jointure pure, cle composite
--    depends on category (ON DELETE CASCADE : la correspondance n'a pas de sens
--    sans la categorie qu'elle restreint ; supprimer une categorie doit purger
--    ses lignes, pas laisser une reference morte).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS category_ingredient_family (
    category_id INT UNSIGNED NOT NULL,
    family      VARCHAR(32)  NOT NULL,
    PRIMARY KEY (category_id, family),
    CONSTRAINT fk_category_ingredient_family_category_id FOREIGN KEY (category_id)
        REFERENCES category (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. Reprise des 50 ingredients existants (installation deja en place)
-- -----------------------------------------------------------------------------
-- Garde `family IS NULL` sur chaque UPDATE : un ingredient deja classe (rejeu, ou
-- reclassification manuelle en production) n'est jamais reecrit. Les noms sont
-- ceux REELLEMENT en base a ce point de l'ordre de migration (0016 a deja corrige
-- les accents avant que 0017 ne s'execute) : la comparaison SQL est donc exacte
-- ici, meme si la collation utf8mb4_unicode_ci est en general insensible aux
-- accents (verifie en PHP dans les tests, pas en SQL).

UPDATE ingredient SET family = 'pain' WHERE family IS NULL AND name IN (
    'Pain burger', 'Pain sésame', 'Pain signature', 'Tortilla'
);

UPDATE ingredient SET family = 'viande' WHERE family IS NULL AND name IN (
    'Steak haché', 'Filet de poulet pané', 'Galette de poisson', 'Tranche de bacon',
    'Nugget de poulet', 'Jambon'
);

UPDATE ingredient SET family = 'fromage' WHERE family IS NULL AND name IN (
    'Cheddar', 'Fromage de chèvre', 'Mozzarella', 'Emmental'
);

UPDATE ingredient SET family = 'legume' WHERE family IS NULL AND name IN (
    'Salade', 'Tomate', 'Oignon', 'Cornichon', 'Roquette'
);

UPDATE ingredient SET family = 'sauce' WHERE family IS NULL AND name IN (
    'Sauce Big Mac', 'Sauce ranch', 'Sauce barbecue', 'Sauce deluxe'
);

UPDATE ingredient SET family = 'feculent' WHERE family IS NULL AND name IN (
    'Pomme de terre frite', 'Galette de pomme de terre'
);

UPDATE ingredient SET family = 'dose_boisson' WHERE family IS NULL AND name IN (
    'Dose Coca', 'Dose Coca Zero', 'Dose Eau', 'Dose Fanta', 'Dose Ice Tea Pêche',
    'Dose Ice Tea Citron', 'Dose Jus d''Orange', 'Dose Jus de Pomme'
);

UPDATE ingredient SET family = 'contenant' WHERE family IS NULL AND name IN (
    'Gobelet'
);

UPDATE ingredient SET family = 'dessert' WHERE family IS NULL AND name IN (
    'Brownie', 'Cheesecake', 'Cookie', 'Donut', 'Macaron', 'Glace McFleury',
    'Muffin', 'Glace sundae', 'Topping chocolat'
);

UPDATE ingredient SET family = 'dosette' WHERE family IS NULL AND name IN (
    'Dosette Barbecue', 'Dosette Moutarde', 'Dosette Deluxe', 'Dosette Ketchup',
    'Dosette Chinoise', 'Dosette Curry', 'Dosette Pommes Frites'
);

-- Note : la correspondance categorie -> familles autorisees (category_ingredient_family)
-- N'EST PAS peuplee ici -- `category` est vide a ce point sur une installation
-- neuve (les migrations s'executent AVANT tous les seeds). Voir
-- db/seeds/0010_ingredient_families.sql, qui la peuple par slug de categorie et
-- fonctionne aussi bien a l'init qu'en reprise (fichier hors seeds_applied).
