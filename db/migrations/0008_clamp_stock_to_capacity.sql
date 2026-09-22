-- db/migrations/0008_clamp_stock_to_capacity.sql
-- =============================================================================
-- Wakdo - Migration 0008 : stock plafonne a la capacite (capacite = plafond strict)
-- =============================================================================
-- Purpose : decision metier (retour oral) -- la capacite de reference (stock_capacity,
--           qui vaut 100 %) est un PLAFOND : stock_quantity ne doit jamais la depasser.
--           Avant ce lot, restock() ajoutait des packs sans plafond -> un ingredient
--           pouvait afficher > 100 % (ex. 350 / 300 = 117 %). Le clamp est desormais
--           applique a l'ECRITURE cote applicatif (IngredientRepository::clampToCapacity,
--           appele par create/restock/inventoryCount). Cette migration NORMALISE les
--           lignes deja aberrantes en base (issues d'un restock pre-clamp).
--
--           Borne HAUTE seulement : un stock NEGATIF (survente assumee, ventes >
--           stock compte) reste intact -- c'est un signal au manager, pas une anomalie.
-- Idempotence : UPDATE conditionnel (stock_quantity > stock_capacity) ; re-jouable
--               sans effet une fois les lignes normalisees. Aucun DDL.
-- Target  : MariaDB 11.4 LTS, InnoDB, utf8mb4 / utf8mb4_unicode_ci.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

UPDATE ingredient
   SET stock_quantity = stock_capacity
 WHERE stock_quantity > stock_capacity;
