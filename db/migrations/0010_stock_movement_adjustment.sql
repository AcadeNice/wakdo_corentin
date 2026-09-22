-- db/migrations/0010_stock_movement_adjustment.sql
-- =============================================================================
-- Wakdo - Migration 0010 : type de mouvement 'adjustment' (ajustement libre)
-- =============================================================================
-- Purpose : retour oral #6 -- "saisie libre" du stock (Ajuster) = correction delta
--           SIGNEE, PIN equipier OBLIGATOIRE (RG-T13, comme l'inventaire : une baisse
--           de stock non attribuee masquerait de la demarque, risque R9). Nouveau type
--           de mouvement 'adjustment' dans le journal append-only stock_movement,
--           distinct de 'inventory_correction' (comptage absolu PIN) et 'restock'
--           (hausse en packs, sans PIN).
-- Idempotence : garde information_schema (re-jouable) -- on n'ALTER que si l'ENUM ne
--               contient pas deja 'adjustment' (COLUMN_TYPE testee par LIKE).
-- Target  : MariaDB 11.4 LTS, InnoDB, utf8mb4 / utf8mb4_unicode_ci.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @has_adjustment := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'stock_movement'
      AND column_name = 'movement_type' AND column_type LIKE '%adjustment%'
);

SET @ddl := IF(
    @has_adjustment = 0,
    "ALTER TABLE stock_movement
        MODIFY movement_type ENUM('sale','cancellation','restock','inventory_correction','adjustment') NOT NULL",
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
