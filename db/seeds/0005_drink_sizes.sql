-- =============================================================================
-- Wakdo — Seed 0005 : tailles a la carte des boissons fontaine (30 / 50 cl)
-- =============================================================================
-- Purpose : cabler la dimension TAILLE (schema 0007) sur les boissons fontaine
--           seedees par 0002_catalogue.sql, sans toucher au code :
--             1. la ligne existante de chaque soda devient la BASE 30 cl ;
--             2. une ligne VARIANTE 50 cl est inseree par soda (base_product_id ->
--                la base, prix = base + 50c par defaut, +50 cl) ;
--             3. la recette (product_ingredient) de la base est dupliquee sur la
--                variante, pour que le decrement de stock (consumption) frappe
--                aussi la 50 cl.
--
-- Perimetre : seules les boissons fontaine ont deux tailles (Coca Cola, Coca Sans
-- Sucres, Fanta Orange, Ice Tea Peche, Ice Tea Citron). Les boissons en bouteille
-- (Eau, Jus d'Orange, Jus de Pommes Bio) restent mono-taille (size_cl laisse NULL,
-- aucune variante).
--
-- Phase   : R4 — depend du schema 0007 (product.size_cl + base_product_id) et du
--           catalogue 0002 (lignes boissons) ; la duplication de recette depend de
--           0003 (product_ingredient des sodas).
--
-- Conventions:
--   - Aucun id en dur : toutes les references sont resolues par sous-requete sur
--     le nom du produit (memes noms que 0002_catalogue.sql).
--   - IDEMPOTENT : UPDATE convergent (repositionne la meme valeur) ; INSERT gardes
--     par WHERE NOT EXISTS (re-jouer n'insere pas de doublon). La sous-requete qui
--     lit `product` dans un INSERT INTO product est enveloppee en table derivee
--     pour contourner l'erreur MariaDB 1093 (technique de 0004_menu_side_maxi.sql).
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 1. Marquer chaque soda fontaine comme BASE 30 cl. UPDATE convergent (rejouer
--    repose 30) -> idempotent. Le nom de base reste propre ("Coca Cola") : la
--    tuile catalogue garde le nom court, le picker affiche "30 cl" / "50 cl".
-- -----------------------------------------------------------------------------
UPDATE product
SET size_cl = 30
WHERE base_product_id IS NULL
  AND name IN ('Coca Cola', 'Coca Sans Sucres', 'Fanta Orange', 'Ice Tea Peche', 'Ice Tea Citron');

-- -----------------------------------------------------------------------------
-- 2. Inserer la VARIANTE 50 cl de chaque soda. category_id / vat_rate / image
--    copies de la base ; price = base + 50c (defaut sensible, a confirmer) ;
--    base_product_id -> id de la base ; size_cl = 50 ; is_available = 1.
--    L'INSERT lit ET ecrit `product` : la sous-requete est enveloppee en table
--    derivee (b) pour contourner l'erreur 1093. WHERE NOT EXISTS garde le doublon
--    a la re-execution (une variante 50 cl de cette base existe deja -> 0 ligne).
-- -----------------------------------------------------------------------------
INSERT INTO product (category_id, name, price_cents, size_cl, base_product_id, vat_rate, image_path, is_available, display_order)
SELECT b.category_id, b.name_50, b.price_cents + 50, 50, b.id, b.vat_rate, b.image_path, 1, b.display_order
FROM (
    SELECT id, category_id, CONCAT(name, ' 50cl') AS name_50, price_cents, vat_rate, image_path, display_order
    FROM product
    WHERE base_product_id IS NULL
      AND name IN ('Coca Cola', 'Coca Sans Sucres', 'Fanta Orange', 'Ice Tea Peche', 'Ice Tea Citron')
) b
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT base_product_id FROM product WHERE base_product_id IS NOT NULL) v
    WHERE v.base_product_id = b.id
);

-- -----------------------------------------------------------------------------
-- 3. Dupliquer la recette de chaque base 30 cl sur sa variante 50 cl, pour que
--    le decrement de stock frappe aussi la 50 cl. Memes ingredients / quantites
--    que la base (simplification assumee : R4 vise le flux de commande, pas une
--    consommation volumetrique exacte). Une base sans recette (ex. theorique) ne
--    produit aucune ligne pour sa variante.
--    PK composite (product_id, ingredient_id) : WHERE NOT EXISTS garde la
--    re-execution (les lignes de la variante existent deja -> 0 ligne inseree).
-- -----------------------------------------------------------------------------
INSERT INTO product_ingredient (product_id, ingredient_id, quantity_normal, quantity_maxi, is_removable, is_addable, extra_price_cents)
SELECT v.id, src.ingredient_id, src.quantity_normal, src.quantity_maxi, src.is_removable, src.is_addable, src.extra_price_cents
FROM product v
JOIN (
    SELECT pi.product_id AS base_id, pi.ingredient_id, pi.quantity_normal, pi.quantity_maxi,
           pi.is_removable, pi.is_addable, pi.extra_price_cents
    FROM product_ingredient pi
) src ON src.base_id = v.base_product_id
WHERE v.base_product_id IS NOT NULL
  AND v.size_cl = 50
  AND NOT EXISTS (
      SELECT 1 FROM (SELECT product_id, ingredient_id FROM product_ingredient) e
      WHERE e.product_id = v.id AND e.ingredient_id = src.ingredient_id
  );
