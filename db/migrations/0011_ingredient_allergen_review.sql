-- db/migrations/0011_ingredient_allergen_review.sql
-- =============================================================================
-- Wakdo - Migration 0011 : etat de revue des allergenes d'un ingredient
-- =============================================================================
-- Purpose : la table de liaison `ingredient_allergen` existe depuis 0001 mais elle
--           est restee VIDE. Une liaison vide est ambigue : elle ne distingue pas
--           "verifie, cet ingredient ne contient aucun des 14 allergenes INCO" de
--           "personne n'a encore regarde". Or les deux doivent produire un message
--           DIFFERENT a l'ecran : "aucun allergene" est une affirmation, "on ne sait
--           pas encore" doit renvoyer le client vers l'equipe.
--           Ces deux colonnes portent cette distinction, plus la provenance de la
--           donnee : un ingredient dont `allergens_reviewed_at` est NULL n'a pas ete
--           revu, et la borne le dit au lieu d'affirmer une absence.
-- Forme    : copiee sur la migration 0005 (nutrition_source + nutrition_fetched_at) --
--            meme couple provenance + horodatage sur la meme table, meme discipline.
-- Idempotence : garde information_schema (re-jouable). Les deux colonnes sont ajoutees
--               ensemble : l'existence de la premiere suffit a court-circuiter le groupe.
-- Target  : MariaDB 11.4 LTS, InnoDB, utf8mb4 / utf8mb4_unicode_ci.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ingredient'
      AND column_name = 'allergens_reviewed_at'
);

SET @ddl := IF(
    @col_exists = 0,
    'ALTER TABLE ingredient
        ADD COLUMN allergens_reviewed_at DATETIME     NULL AFTER nutrition_fetched_at,
        ADD COLUMN allergens_source      VARCHAR(120) NULL AFTER allergens_reviewed_at',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- allergens_reviewed_at : date de la derniere revue des allergenes de cet ingredient.
--   NULL = jamais revu -> la borne affiche "information non disponible, demandez a
--   l'equipe" plutot qu'une liste vide qui se lirait comme "sans allergene".
--   Renseignee par le seed de revue (0008) et par l'ecran back-office ingredient.
-- allergens_source : provenance de la revue (ex. "Table allergenes McDonald's France
--   (consultee le 2026-07-31)"). VARCHAR(120) comme nutrition_source. Sert la question
--   "d'ou vient cette information ?" directement depuis l'application, sans avoir a
--   ouvrir le fichier de seed.
--
-- Les deux colonnes sont nullables : un ingredient non revu reste parfaitement valide.
-- Aucune donnee existante n'est touchee -- la migration n'ecrit aucune ligne.
