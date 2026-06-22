-- db/migrations/0006_product_maxi_variant.sql
-- =============================================================================
-- Wakdo - Migration 0006 : variante Maxi d'un produit (accompagnement de menu)
-- =============================================================================
-- Purpose : ajoute a `product` une auto-reference nullable vers la variante
--           servie quand un menu est commande au format Maxi. L'accompagnement
--           de menu (slot_type='side') propose la version standard (ex. Moyenne
--           Frite, Potatoes) ; au format Maxi, le serveur substitue la variante
--           Grande (Grande Frite / Grande Potatoes) sans choix supplementaire.
--           Approche data-driven : la regle vit dans la donnee, pas dans le code,
--           et le decrement de stock (consumption()) frappe alors le bon produit.
-- Target  : MariaDB 11.4 LTS, InnoDB, utf8mb4 / utf8mb4_unicode_ci.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE product
    ADD COLUMN maxi_variant_product_id INT UNSIGNED NULL AFTER price_cents,
    ADD CONSTRAINT fk_product_maxi_variant_product_id FOREIGN KEY (maxi_variant_product_id)
        REFERENCES product (id) ON DELETE SET NULL;

-- maxi_variant_product_id : produit servi a la place de celui-ci quand le menu est
-- au format Maxi (ex. Moyenne Frite -> Grande Frite). Place AFTER price_cents :
-- regroupe avec les attributs de commercialisation du produit. Nullable : la
-- plupart des produits n'ont pas de variante Maxi (un produit sans variante reste
-- valide et n'est jamais substitue).
--
-- ON DELETE SET NULL (et non RESTRICT) : si la variante Grande est supprimee du
-- catalogue, le produit de base reste vendable, il perd seulement sa substitution
-- Maxi (degradation gracieuse). RESTRICT bloquerait la suppression d'une Grande
-- referencee, ce qui n'est pas souhaitable : la reference est un confort metier,
-- pas une integrite forte de commande (les commandes figent deja leurs snapshots).
