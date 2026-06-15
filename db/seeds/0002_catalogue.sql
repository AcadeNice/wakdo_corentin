-- =============================================================================
-- Wakdo — Seed 0002 : Catalogue (reference / demo data)
-- =============================================================================
-- Purpose : Populate the Catalogue sub-domain (category, product, menu,
--           menu_slot, menu_slot_option) from the school JSON sources.
-- Sources : docs/merise/_sources/categories.json (9 categories)
--           docs/merise/_sources/produits.json   (menus + 53 products)
--           src/public/borne/data/produits.json  (cents prices + clean paths,
--                                                  used for cross-check)
-- Phase   : P2 — demo seed, assumes a fresh schema (0001_init_schema.sql).
--
-- Conventions:
--   - Monetary amounts are INT in CENTS (euros float x 100, rounded).
--   - vat_rate is per-mille: 100 = 10% (default), 55 = 5.5% for products in
--     resealable containers (bottled water, bottled juices) — dictionary note 9.
--   - image_path is a relative path under the public root, normalised to
--     assets/images/produits/<category>/<file>.png (dictionary note 8).
--   - Menus go to the `menu` table (NOT `product`); every other category goes
--     to `product`. The "burgers" category items are the anchor products that
--     menus reference via burger_product_id.
--   - price_maxi_cents = price_normal_cents + 150 (Maxi format, +1.50 EUR).
--   - Foreign keys are resolved by subquery on natural keys (slug / name)
--     rather than hardcoded ids.
--   - Insertion order respects FK dependencies:
--       category -> product -> menu -> menu_slot -> menu_slot_option.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 1. category (9) — root table, source order = display_order
-- -----------------------------------------------------------------------------
INSERT INTO category (name, slug, image_path, display_order, is_active) VALUES
  ('menus',    'menus',    'assets/images/categories/menus.png',    1, 1),
  ('boissons', 'boissons', 'assets/images/categories/boissons.png', 2, 1),
  ('burgers',  'burgers',  'assets/images/categories/burgers.png',  3, 1),
  ('frites',   'frites',   'assets/images/categories/frites.png',   4, 1),
  ('encas',    'encas',    'assets/images/categories/encas.png',    5, 1),
  ('wraps',    'wraps',    'assets/images/categories/wraps.png',    6, 1),
  ('salades',  'salades',  'assets/images/categories/salades.png',  7, 1),
  ('desserts', 'desserts', 'assets/images/categories/desserts.png', 8, 1),
  ('sauces',   'sauces',   'assets/images/categories/sauces.png',   9, 1);

-- -----------------------------------------------------------------------------
-- 2. product — every non-menu item (53 rows)
--    category_id resolved via subquery on category.slug.
--    display_order follows source order within each category.
--    vat_rate defaults to 100; 55 only for resealable-container drinks
--    (Eau, Jus d'Orange, Jus de Pommes Bio) per dictionary note 9.
-- -----------------------------------------------------------------------------

-- 2.a burgers (anchor products for menus)
INSERT INTO product (category_id, name, price_cents, vat_rate, image_path, is_available, display_order) VALUES
  ((SELECT id FROM category WHERE slug='burgers'), 'Le 280',                   680,  100, 'assets/images/produits/burgers/280.png',                              1, 1),
  ((SELECT id FROM category WHERE slug='burgers'), 'Big Tasty',                860,  100, 'assets/images/produits/burgers/big-tasty-1-viande.png',               1, 2),
  ((SELECT id FROM category WHERE slug='burgers'), 'Big Tasty Bacon',          890,  100, 'assets/images/produits/burgers/big-tasty-bacon-1-viande.png',         1, 3),
  ((SELECT id FROM category WHERE slug='burgers'), 'Big Mac',                  600,  100, 'assets/images/produits/burgers/bigmac.png',                           1, 4),
  ((SELECT id FROM category WHERE slug='burgers'), 'CBO',                      890,  100, 'assets/images/produits/burgers/cbo.png',                              1, 5),
  ((SELECT id FROM category WHERE slug='burgers'), 'MC Chicken',               730,  100, 'assets/images/produits/burgers/mcchicken.png',                        1, 6),
  ((SELECT id FROM category WHERE slug='burgers'), 'MC Crispy',                530,  100, 'assets/images/produits/burgers/mccrispy.png',                         1, 7),
  ((SELECT id FROM category WHERE slug='burgers'), 'MC Fish',                  485,  100, 'assets/images/produits/burgers/mcfish.png',                           1, 8),
  ((SELECT id FROM category WHERE slug='burgers'), 'Royal Bacon',              510,  100, 'assets/images/produits/burgers/royalbacon.png',                       1, 9),
  ((SELECT id FROM category WHERE slug='burgers'), 'Royal Cheese',             440,  100, 'assets/images/produits/burgers/royalcheese.png',                      1, 10),
  ((SELECT id FROM category WHERE slug='burgers'), 'Royal Deluxe',             540,  100, 'assets/images/produits/burgers/royaldeluxe.png',                      1, 11),
  ((SELECT id FROM category WHERE slug='burgers'), 'Signature BBQ Beef 2 viandes', 1140, 100, 'assets/images/produits/burgers/signature-bbq-beef-2-viandes.png', 1, 12),
  ((SELECT id FROM category WHERE slug='burgers'), 'Signature Beef BBQ',       1030, 100, 'assets/images/produits/burgers/signature-beef-bbq-burger-1-viande.png', 1, 13);

-- 2.b boissons (Eau + the two bottled juices are resealable-container = vat_rate 55)
INSERT INTO product (category_id, name, price_cents, vat_rate, image_path, is_available, display_order) VALUES
  ((SELECT id FROM category WHERE slug='boissons'), 'Coca Cola',          190, 100, 'assets/images/produits/boissons/coca-cola.png',                   1, 1),
  ((SELECT id FROM category WHERE slug='boissons'), 'Coca Sans Sucres',   190, 100, 'assets/images/produits/boissons/coca-sans-sucres.png',            1, 2),
  ((SELECT id FROM category WHERE slug='boissons'), 'Eau',                100,  55, 'assets/images/produits/boissons/eau.png',                         1, 3),
  ((SELECT id FROM category WHERE slug='boissons'), 'Fanta Orange',       190, 100, 'assets/images/produits/boissons/fanta.png',                       1, 4),
  ((SELECT id FROM category WHERE slug='boissons'), 'Ice Tea Peche',      190, 100, 'assets/images/produits/boissons/ice-tea-peche.png',               1, 5),
  ((SELECT id FROM category WHERE slug='boissons'), 'Ice Tea Citron',     190, 100, 'assets/images/produits/boissons/the-vert-citron-sans-sucres.png', 1, 6),
  ((SELECT id FROM category WHERE slug='boissons'), 'Jus d''Orange',      210,  55, 'assets/images/produits/boissons/jus-orange.png',                  1, 7),
  ((SELECT id FROM category WHERE slug='boissons'), 'Jus de Pommes Bio',  230,  55, 'assets/images/produits/boissons/jus-pomme-bio.png',               1, 8);

-- 2.c frites
INSERT INTO product (category_id, name, price_cents, vat_rate, image_path, is_available, display_order) VALUES
  ((SELECT id FROM category WHERE slug='frites'), 'Petite Frite',    145, 100, 'assets/images/produits/frites/petite-frite.png',    1, 1),
  ((SELECT id FROM category WHERE slug='frites'), 'Moyenne Frite',   275, 100, 'assets/images/produits/frites/moyenne-frite.png',   1, 2),
  ((SELECT id FROM category WHERE slug='frites'), 'Grande Frite',    350, 100, 'assets/images/produits/frites/grande-frite.png',    1, 3),
  ((SELECT id FROM category WHERE slug='frites'), 'Potatoes',        215, 100, 'assets/images/produits/frites/potatoes.png',        1, 4),
  ((SELECT id FROM category WHERE slug='frites'), 'Grande Potatoes', 340, 100, 'assets/images/produits/frites/grande-potatoes.png', 1, 5);

-- 2.d encas
INSERT INTO product (category_id, name, price_cents, vat_rate, image_path, is_available, display_order) VALUES
  ((SELECT id FROM category WHERE slug='encas'), 'Cheeseburger', 260,  100, 'assets/images/produits/encas/cheeseburger.png', 1, 1),
  ((SELECT id FROM category WHERE slug='encas'), 'Croc MCdo',    320,  100, 'assets/images/produits/encas/croc-mc-do.png',   1, 2),
  ((SELECT id FROM category WHERE slug='encas'), 'Nuggets x4',   420,  100, 'assets/images/produits/encas/nuggets-4.png',    1, 3),
  ((SELECT id FROM category WHERE slug='encas'), 'Nuggets x20',  1300, 100, 'assets/images/produits/encas/nuggets-20.png',   1, 4);

-- 2.e wraps
INSERT INTO product (category_id, name, price_cents, vat_rate, image_path, is_available, display_order) VALUES
  ((SELECT id FROM category WHERE slug='wraps'), 'MC Wrap Chevre',       310, 100, 'assets/images/produits/wraps/mcwrap-chevre.png',       1, 1),
  ((SELECT id FROM category WHERE slug='wraps'), 'MC Wrap Poulet Bacon', 330, 100, 'assets/images/produits/wraps/mcwrap-poulet-bacon.png', 1, 2),
  ((SELECT id FROM category WHERE slug='wraps'), 'Ptit Wrap Chevre',     260, 100, 'assets/images/produits/wraps/ptit-wrap-chevre.png',    1, 3),
  ((SELECT id FROM category WHERE slug='wraps'), 'Ptit Wrap Ranch',      260, 100, 'assets/images/produits/wraps/ptit-wrap-ranch.png',     1, 4);

-- 2.f salades
INSERT INTO product (category_id, name, price_cents, vat_rate, image_path, is_available, display_order) VALUES
  ((SELECT id FROM category WHERE slug='salades'), 'Petite Salade',   330, 100, 'assets/images/produits/salades/petite-salade.png',         1, 1),
  ((SELECT id FROM category WHERE slug='salades'), 'Cesar Classic',   880, 100, 'assets/images/produits/salades/salade-classic-caesar.png', 1, 2),
  ((SELECT id FROM category WHERE slug='salades'), 'Italienne Mozza', 880, 100, 'assets/images/produits/salades/salade-italian-mozza.png',  1, 3);

-- 2.g desserts
INSERT INTO product (category_id, name, price_cents, vat_rate, image_path, is_available, display_order) VALUES
  ((SELECT id FROM category WHERE slug='desserts'), 'Brownie',                  260, 100, 'assets/images/produits/desserts/brownies.png',                   1, 1),
  ((SELECT id FROM category WHERE slug='desserts'), 'Cheesecake chocolat M&M''S', 310, 100, 'assets/images/produits/desserts/cheesecake-choconuts-m&m-s.png', 1, 2),
  ((SELECT id FROM category WHERE slug='desserts'), 'Cheesecake Fraise',        310, 100, 'assets/images/produits/desserts/cheesecake-fraise.png',          1, 3),
  ((SELECT id FROM category WHERE slug='desserts'), 'Cookie',                   320, 100, 'assets/images/produits/desserts/cookie.png',                     1, 4),
  ((SELECT id FROM category WHERE slug='desserts'), 'Donut',                    260, 100, 'assets/images/produits/desserts/doghnut.png',                    1, 5),
  ((SELECT id FROM category WHERE slug='desserts'), 'Macarons',                 270, 100, 'assets/images/produits/desserts/macarons.png',                   1, 6),
  ((SELECT id FROM category WHERE slug='desserts'), 'MC Fleury',                440, 100, 'assets/images/produits/desserts/mcfleury.png',                   1, 7),
  ((SELECT id FROM category WHERE slug='desserts'), 'Muffin',                   360, 100, 'assets/images/produits/desserts/muffin.png',                     1, 8),
  ((SELECT id FROM category WHERE slug='desserts'), 'Sunday',                   100, 100, 'assets/images/produits/desserts/sunday.png',                     1, 9);

-- 2.h sauces
INSERT INTO product (category_id, name, price_cents, vat_rate, image_path, is_available, display_order) VALUES
  ((SELECT id FROM category WHERE slug='sauces'), 'Classic Barbecue', 70, 100, 'assets/images/produits/sauces/classic-barbecue.png',   1, 1),
  ((SELECT id FROM category WHERE slug='sauces'), 'Classic Moutarde', 70, 100, 'assets/images/produits/sauces/classic-moutarde.png',   1, 2),
  ((SELECT id FROM category WHERE slug='sauces'), 'Creamy Deluxe',    70, 100, 'assets/images/produits/sauces/cremy-deluxe.png',       1, 3),
  ((SELECT id FROM category WHERE slug='sauces'), 'Ketchup',          70, 100, 'assets/images/produits/sauces/ketchup.png',            1, 4),
  ((SELECT id FROM category WHERE slug='sauces'), 'Chinoise',         70, 100, 'assets/images/produits/sauces/sauce-chinoise.png',     1, 5),
  ((SELECT id FROM category WHERE slug='sauces'), 'Curry',            70, 100, 'assets/images/produits/sauces/sauce-curry.png',        1, 6),
  ((SELECT id FROM category WHERE slug='sauces'), 'Pommes Frites',    70, 100, 'assets/images/produits/sauces/sauce-pommes-frite.png', 1, 7);

-- -----------------------------------------------------------------------------
-- 3. menu (13) — the "menus" category items.
--    category_id   = the menus category.
--    burger_product_id resolved by matching the anchor burger name
--      ("Menu Le 280" -> product "Le 280", etc.).
--    price_normal_cents from source; price_maxi_cents = normal + 150.
-- -----------------------------------------------------------------------------
INSERT INTO menu (category_id, burger_product_id, name, price_normal_cents, price_maxi_cents, image_path, is_available, display_order) VALUES
  ((SELECT id FROM category WHERE slug='menus'), (SELECT id FROM product WHERE name='Le 280'),                     'Menu Le 280',                      880,  1030, 'assets/images/produits/burgers/280.png',                              1, 1),
  ((SELECT id FROM category WHERE slug='menus'), (SELECT id FROM product WHERE name='Big Tasty'),                  'Menu Big Tasty',                   1060, 1210, 'assets/images/produits/burgers/big-tasty-1-viande.png',               1, 2),
  ((SELECT id FROM category WHERE slug='menus'), (SELECT id FROM product WHERE name='Big Tasty Bacon'),            'Menu Big Tasty Bacon',             1090, 1240, 'assets/images/produits/burgers/big-tasty-bacon-1-viande.png',         1, 3),
  ((SELECT id FROM category WHERE slug='menus'), (SELECT id FROM product WHERE name='Big Mac'),                    'Menu Big Mac',                     800,  950,  'assets/images/produits/burgers/bigmac.png',                           1, 4),
  ((SELECT id FROM category WHERE slug='menus'), (SELECT id FROM product WHERE name='CBO'),                        'Menu CBO',                         1090, 1240, 'assets/images/produits/burgers/cbo.png',                              1, 5),
  ((SELECT id FROM category WHERE slug='menus'), (SELECT id FROM product WHERE name='MC Chicken'),                 'Menu MC Chicken',                  930,  1080, 'assets/images/produits/burgers/mcchicken.png',                        1, 6),
  ((SELECT id FROM category WHERE slug='menus'), (SELECT id FROM product WHERE name='MC Crispy'),                  'Menu MC Crispy',                   720,  870,  'assets/images/produits/burgers/mccrispy.png',                         1, 7),
  ((SELECT id FROM category WHERE slug='menus'), (SELECT id FROM product WHERE name='MC Fish'),                    'Menu MC Fish',                     720,  870,  'assets/images/produits/burgers/mcfish.png',                           1, 8),
  ((SELECT id FROM category WHERE slug='menus'), (SELECT id FROM product WHERE name='Royal Bacon'),                'Menu Royal Bacon',                 705,  855,  'assets/images/produits/burgers/royalbacon.png',                       1, 9),
  ((SELECT id FROM category WHERE slug='menus'), (SELECT id FROM product WHERE name='Royal Cheese'),               'Menu Royal Cheese',                640,  790,  'assets/images/produits/burgers/royalcheese.png',                      1, 10),
  ((SELECT id FROM category WHERE slug='menus'), (SELECT id FROM product WHERE name='Royal Deluxe'),               'Menu Royal Deluxe',                740,  890,  'assets/images/produits/burgers/royaldeluxe.png',                      1, 11),
  ((SELECT id FROM category WHERE slug='menus'), (SELECT id FROM product WHERE name='Signature BBQ Beef 2 viandes'), 'Menu Signature BBQ Beef 2 viandes', 1350, 1500, 'assets/images/produits/burgers/signature-bbq-beef-2-viandes.png',   1, 12),
  ((SELECT id FROM category WHERE slug='menus'), (SELECT id FROM product WHERE name='Signature Beef BBQ'),         'Menu Signature Beef BBQ',          1190, 1340, 'assets/images/produits/burgers/signature-beef-bbq-burger-1-viande.png', 1, 13);

-- -----------------------------------------------------------------------------
-- 4. menu_slot — three standard slots per menu:
--      drink (required), side (required), sauce (optional).
--    One INSERT per slot_type, fanning out over all 13 menus via SELECT.
-- -----------------------------------------------------------------------------
INSERT INTO menu_slot (menu_id, name, slot_type, is_required, display_order)
SELECT m.id, 'Boisson', 'drink', 1, 1
FROM menu m
JOIN category c ON c.id = m.category_id AND c.slug = 'menus';

INSERT INTO menu_slot (menu_id, name, slot_type, is_required, display_order)
SELECT m.id, 'Accompagnement', 'side', 1, 2
FROM menu m
JOIN category c ON c.id = m.category_id AND c.slug = 'menus';

INSERT INTO menu_slot (menu_id, name, slot_type, is_required, display_order)
SELECT m.id, 'Sauce', 'sauce', 0, 3
FROM menu m
JOIN category c ON c.id = m.category_id AND c.slug = 'menus';

-- -----------------------------------------------------------------------------
-- 5. menu_slot_option — eligible products per slot:
--      drink slot -> all products in category 'boissons'
--      side  slot -> all products in category 'frites'
--      sauce slot -> all products in category 'sauces'
--    Composite PK (menu_slot_id, product_id) is naturally satisfied: each
--    (slot, product) pair is unique because slots are unique per menu.
-- -----------------------------------------------------------------------------
INSERT INTO menu_slot_option (menu_slot_id, product_id)
SELECT ms.id, p.id
FROM menu_slot ms
JOIN product p ON p.category_id = (SELECT id FROM category WHERE slug='boissons')
WHERE ms.slot_type = 'drink';

INSERT INTO menu_slot_option (menu_slot_id, product_id)
SELECT ms.id, p.id
FROM menu_slot ms
JOIN product p ON p.category_id = (SELECT id FROM category WHERE slug='frites')
WHERE ms.slot_type = 'side';

INSERT INTO menu_slot_option (menu_slot_id, product_id)
SELECT ms.id, p.id
FROM menu_slot ms
JOIN product p ON p.category_id = (SELECT id FROM category WHERE slug='sauces')
WHERE ms.slot_type = 'sauce';
