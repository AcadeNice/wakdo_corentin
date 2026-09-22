-- db/migrations/0009_order_prep_states.sql
-- =============================================================================
-- Wakdo - Migration 0009 : etats de preparation de commande (preparing, ready)
-- =============================================================================
-- Purpose : retour oral #8 -- entre 'paid' et 'delivered', deux etats intermediaires
--           VISIBLES au KDS : 'preparing' (prise en charge en cuisine) et 'ready'
--           (prete a remettre). L'equipe voit et avance le statut, au lieu d'un seul
--           seau 'paid' jusqu'a la remise. Ajoute aussi les horodatages preparing_at /
--           ready_at (memes conventions que paid_at / delivered_at).
--
--           Machine a etats : pending_payment -> paid -> preparing -> ready -> delivered
--           (preparing et ready optionnels : ready accepte aussi 'paid' en direct ;
--           deliver accepte paid|preparing|ready ; cancel accepte tout etat non terminal).
-- Idempotence : meme garde information_schema que 0006/0007 (re-jouable sans erreur) ;
--               on verifie l'absence de preparing_at avant l'ALTER combine (MODIFY enum +
--               ADD des deux colonnes), l'existence de l'une suffit a court-circuiter.
-- Target  : MariaDB 11.4 LTS, InnoDB, utf8mb4 / utf8mb4_unicode_ci.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'customer_order' AND column_name = 'preparing_at'
);

SET @ddl := IF(
    @col_exists = 0,
    "ALTER TABLE customer_order
        MODIFY status ENUM('pending_payment','paid','preparing','ready','delivered','cancelled') NOT NULL DEFAULT 'pending_payment',
        ADD COLUMN preparing_at DATETIME NULL AFTER paid_at,
        ADD COLUMN ready_at DATETIME NULL AFTER preparing_at",
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
