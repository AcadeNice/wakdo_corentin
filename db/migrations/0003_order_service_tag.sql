-- db/migrations/0003_order_service_tag.sql
-- =============================================================================
-- Wakdo - Migration 0003 : service_tag (numero de chevalet) sur customer_order
-- =============================================================================
-- Purpose : numero de chevalet pour le service EN SALLE (mode dine_in / sur place).
--           Saisi a la borne quand le client choisit "sur place" ; permet au
--           service d'apporter la commande a la bonne table (B4). NULL pour
--           takeaway / drive. Colonne additive nullable (aucune donnee existante
--           a retro-remplir). Le runner applique *.sql dans l'ordre lexicographique
--           via schema_migrations.
-- Target  : MariaDB 11.4 LTS, InnoDB, utf8mb4 / utf8mb4_unicode_ci.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Idempotence : meme garde information_schema que 0006/0007 (re-jouable sans
-- erreur). On verifie l'absence de la colonne `service_tag` avant l'ALTER ;
-- si elle existe deja, on execute un no-op (DO 0). Le schema resultant est
-- inchange : seul l'ajout de la colonne (si absente) est joue.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'customer_order' AND column_name = 'service_tag'
);

SET @ddl := IF(
    @col_exists = 0,
    'ALTER TABLE customer_order
        ADD COLUMN service_tag VARCHAR(20) NULL AFTER service_mode',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
