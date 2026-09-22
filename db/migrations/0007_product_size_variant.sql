-- db/migrations/0007_product_size_variant.sql
-- =============================================================================
-- Wakdo - Migration 0007 : variante de TAILLE d'un produit (boisson 30/50 cl)
-- =============================================================================
-- Purpose : ajoute a `product` la dimension TAILLE des boissons fontaine (la
--           maquette borne propose 30 cl / 50 cl), modelisee comme des LIGNES
--           produit distinctes (meme approche que Moyenne/Grande Frite). Le
--           domaine commande facture deja par product_id : le flux de commande
--           reste inchange, la borne resout juste la taille choisie en product_id.
--
--           Grouping DEDIE (base_product_id), distinct de maxi_variant_product_id
--           (migration 0006) : base_product_id pilote la selection de taille A LA
--           CARTE (picker 30/50 cl) ; maxi_variant_product_id pilote la substitution
--           Maxi en MENU (resolveSelections). Les deux coexistent sur une boisson :
--           le seed 0006 pointe desormais chaque soda 30 cl vers sa variante 50 cl
--           pour qu'un menu Maxi serve la grande boisson (decision metier). Cet
--           "effet" est VOULU et ne s'applique qu'aux selections de menu au format
--           maxi ; une boisson 30 cl commandee a la carte (resolveLine type product)
--           ne consulte jamais maxi_variant_product_id et reste en 30 cl.
-- Target  : MariaDB 11.4 LTS, InnoDB, utf8mb4 / utf8mb4_unicode_ci.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Idempotence : meme garde information_schema que 0006 (re-jouable sans erreur).
-- On verifie l'absence de la colonne `size_cl` avant l'ALTER ; les deux colonnes
-- sont ajoutees ensemble, l'existence de l'une suffit donc a court-circuiter.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'product' AND column_name = 'size_cl'
);

SET @ddl := IF(
    @col_exists = 0,
    'ALTER TABLE product
        ADD COLUMN size_cl SMALLINT UNSIGNED NULL AFTER price_cents,
        ADD COLUMN base_product_id INT UNSIGNED NULL AFTER size_cl,
        ADD CONSTRAINT fk_product_base_product_id FOREIGN KEY (base_product_id)
            REFERENCES product (id) ON DELETE CASCADE',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- size_cl : volume en centilitres. NULL = le produit n'a pas de dimension taille
-- (bouteilles, produits non-boissons). La ligne de base (30) ET la variante (50)
-- portent toutes deux leur volume, pour que le picker affiche un libelle humain.
--
-- base_product_id : auto-reference vers la ligne de base. NULL = produit de base
-- ou autonome (visible dans le catalogue) ; NON NULL = variante de taille du
-- produit reference (masquee de la grille catalogue, atteinte via le picker).
--
-- ON DELETE CASCADE (et non SET NULL comme 0006) : une variante de taille n'a
-- AUCUN sens sans sa base (une "Coca Cola 50cl" orpheline n'est pas commercialisable),
-- alors que la substitution Maxi de 0006 est un confort optionnel survivant a la
-- perte de sa cible. Supprimer la base emporte donc ses variantes de taille. Les
-- commandes passees ne sont pas affectees (elles figent leurs snapshots, RG-T05).
