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

ALTER TABLE customer_order
    ADD COLUMN service_tag VARCHAR(20) NULL AFTER service_mode;
