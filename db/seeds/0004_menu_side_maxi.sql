-- =============================================================================
-- Wakdo — Seed 0004 : accompagnement de menu = variante Maxi automatique
-- =============================================================================
-- Purpose : cabler la regle metier "accompagnement Maxi" sur les donnees seedees
--           par 0002_catalogue.sql, sans toucher au code :
--             1. lier chaque accompagnement standard a sa variante Grande
--                (Moyenne Frite -> Grande Frite, Potatoes -> Grande Potatoes) ;
--             2. restreindre les options du slot 'side' des menus aux deux seuls
--                choix conformes a la maquette (ecran 4) : Moyenne Frite + Potatoes.
-- Phase   : P4 — depend du schema 0006 (product.maxi_variant_product_id) et du
--           catalogue 0002 (produits frites + menu_slot 'side').
--
-- Etat initial (0002_catalogue.sql, section 5) : le slot 'side' recoit TOUS les
-- produits de la categorie 'frites', soit les 5 : Petite Frite, Moyenne Frite,
-- Grande Frite, Potatoes, Grande Potatoes. Ce seed retire Petite Frite, Grande
-- Frite et Grande Potatoes des options de menu (elles restent a la carte dans la
-- categorie frites) : le DELETE n'est donc PAS un no-op sur une base 0002.
--
-- Conventions:
--   - Aucun id en dur : toutes les references sont resolues par sous-requete sur
--     le nom du produit / le type de slot (memes noms que 0002_catalogue.sql).
--   - IDEMPOTENT : UPDATE convergent (repositionne la meme valeur) et DELETE par
--     appartenance (re-supprimer des options deja absentes ne fait rien) ; rejouer
--     ce seed laisse la base dans le meme etat.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 1. Lier chaque accompagnement standard a sa variante Grande.
--    Le SELECT cible la table `product`, que l'UPDATE modifie aussi : MariaDB/
--    MySQL interdit de lire et d'ecrire la meme table dans une seule requete
--    sans niveau de derivation. La sous-requete est donc enveloppee dans une
--    table derivee (SELECT ... FROM (SELECT ...) x) qui materialise l'id avant
--    l'UPDATE, contournant l'erreur "can't specify target table for update".
-- -----------------------------------------------------------------------------
UPDATE product
SET maxi_variant_product_id = (
    SELECT id FROM (SELECT id FROM product WHERE name = 'Grande Frite') x
)
WHERE name = 'Moyenne Frite';

UPDATE product
SET maxi_variant_product_id = (
    SELECT id FROM (SELECT id FROM product WHERE name = 'Grande Potatoes') x
)
WHERE name = 'Potatoes';

-- -----------------------------------------------------------------------------
-- 2. Restreindre les options du slot 'side' des menus aux deux choix de la
--    maquette. On supprime des slots 'side' toute option qui n'est ni Moyenne
--    Frite ni Potatoes (Petite Frite, Grande Frite, Grande Potatoes). Les autres
--    slots (drink, sauce) et les produits a la carte ne sont pas touches.
--    Idempotent : sur une base deja restreinte, ces lignes n'existent plus, le
--    DELETE affecte 0 ligne.
-- -----------------------------------------------------------------------------
DELETE FROM menu_slot_option
WHERE menu_slot_id IN (SELECT id FROM menu_slot WHERE slot_type = 'side')
  AND product_id IN (
      SELECT id FROM product WHERE name IN ('Petite Frite', 'Grande Frite', 'Grande Potatoes')
  );
