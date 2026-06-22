-- db/migrations/0005_ingredient_nutrition.sql
-- =============================================================================
-- Wakdo - Migration 0005 : enrichissement nutritionnel depuis une API EXTERNE
-- =============================================================================
-- Purpose : ajoute a `ingredient` des colonnes nullables pour stocker des donnees
--           nutritionnelles importees depuis une API TIERCE (OpenFoodFacts), a la
--           demande d'un manager/admin (action explicite, pas au runtime borne).
--           Demontre l'exploitation, DANS LE MODELE de donnees, d'informations
--           externes provenant d'une API (Cr 3.a.3). Egress maitrise et opt-in :
--           aucun appel automatique ; la passerelle (App\Catalogue\
--           OpenFoodFactsGateway) est invoquee seulement par IngredientController::enrich.
-- Target  : MariaDB 11.4 LTS, InnoDB, utf8mb4 / utf8mb4_unicode_ci.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE ingredient
    ADD COLUMN energy_kcal_100g     SMALLINT UNSIGNED NULL AFTER pack_label,
    ADD COLUMN nutrition_source     VARCHAR(120)      NULL AFTER energy_kcal_100g,
    ADD COLUMN nutrition_fetched_at DATETIME          NULL AFTER nutrition_source;

-- energy_kcal_100g : apport energetique pour 100 g (SMALLINT UNSIGNED suffit ; les
-- valeurs reelles restent < 1000). nutrition_source : provenance ("OpenFoodFacts").
-- nutrition_fetched_at : horodatage de l'import, pour tracer la fraicheur. Toutes
-- nullables : un ingredient non enrichi reste valide (donnee optionnelle).
