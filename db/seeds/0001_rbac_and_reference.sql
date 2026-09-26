-- =============================================================================
-- Wakdo — Seed 0001 : RBAC + reference data + admin user
-- =============================================================================
-- Purpose : Seed the foundational rows the back-office cannot boot without:
--           the 5 RBAC roles, the frozen catalogue of 23 permissions, the
--           default role/permission matrix, per-role visible order sources,
--           the 14 EU INCO allergens, and a single bootstrap admin user.
-- Source  : docs/merise/dictionary.md (3.8 allergen, 3.15 role, 3.16
--           role_visible_source, 3.17 permission catalogue + default grants,
--           3.18 role_permission), docs/merise/mct.md (operations 1-28),
--           docs/PROJECT_CONTEXT.md section 7 (role responsibilities) and
--           decision D5 (admin gets order.create / order.deliver ; manager
--           does NOT get order.cancel).
-- Phase   : P2 — demo/reference seed, applied AFTER db/migrations/0001_init_schema.sql.
-- Target  : MariaDB 11.4 LTS. Fed by db/seed.sh into the already-selected DB.
--
-- Notes:
--   - Statements are ordered so every FK resolves: role and permission first,
--     then role_permission / role_visible_source, then user (FK -> role).
--   - role_permission rows use subqueries on role.code and permission.code so
--     no surrogate ids are hardcoded (robust to AUTO_INCREMENT gaps).
--   - admin/manager get no role_visible_source rows: they have a global view of
--     all sources (the absence of rows means "no source filter applied").
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 1. role (5) — dictionary.md 3.15
--    order_source: counter/drive auto-tag their own source; admin/manager NULL
--    (they may create on behalf of any channel); kitchen NULL (read-only on
--    orders, never creates one).
-- -----------------------------------------------------------------------------
-- Libelles et descriptions en FRANCAIS (F40 point 6 : equipiers francophones, pas
-- d'anglais dans l'interface). La description kitchen reflete la capacite REELLE
-- (avance l'etat de preparation via order.read, KDS retour oral #8 / E14 : la
-- premiere redaction anglaise "Performs no order status transition" etait fausse
-- une fois cette capacite ajoutee ; on n'en reconduit pas la traduction litterale).
-- code, default_route et order_source restent des identifiants techniques (non
-- affiches en clair aux equipiers), donc inchanges.
INSERT INTO role (code, label, description, default_route, order_source, is_active) VALUES
    ('admin',   'Administrateur',       'Accès complet au back-office : gestion CRUD complète du catalogue (y compris les suppressions), gestion des utilisateurs, rôles et permissions (RBAC), stock, statistiques, création/remise/annulation de commande.', '/admin/dashboard', NULL,      1),
    ('manager', 'Responsable',          'Création et mise à jour du catalogue, gestion des ingrédients et du stock (réapprovisionnement et inventaire), statistiques. Ni administration des utilisateurs/rôles, ni annulation de commande.', '/admin/stats',     NULL,      1),
    ('kitchen', 'Équipier cuisine',     'Écran cuisine (KDS) des commandes actives ; fait avancer l''état de préparation (en préparation puis prête) via order.read, et effectue l''inventaire. N''effectue pas la remise finale (order.deliver).', '/kitchen/display', NULL,      1),
    ('counter', 'Équipier comptoir',    'Prend les commandes au comptoir, les remet au client, peut annuler. Effectue l''inventaire. Source de commande taguée automatiquement comptoir.', '/counter/orders',  'counter', 1),
    ('drive',   'Équipier drive',       'Prend les commandes au drive (interphone et casque), les remet au client, peut annuler. Effectue l''inventaire. Source de commande taguée automatiquement drive.', '/drive/orders',    'drive',   1);

-- -----------------------------------------------------------------------------
-- 2. permission (23) — frozen catalogue, dictionary.md 3.17.
--    code format <resource>.<action>. The catalogue is fixed at the seed and
--    never created through the UI (only assigned to roles via MANAGE_RBAC).
-- -----------------------------------------------------------------------------
INSERT INTO permission (code, label, description) VALUES
    ('product.create',    'Create product',            'Create a new catalogue product.'),
    ('product.read',      'Read products',             'View products in the back-office and on order screens.'),
    ('product.update',    'Update product',            'Edit an existing product (name, price, VAT, availability, etc.).'),
    ('product.delete',    'Delete product',            'Permanently delete a product when no FK references block it.'),
    ('menu.create',       'Create menu',               'Create a new menu with its slot configuration.'),
    ('menu.read',         'Read menus',                'View menus, slots and slot options.'),
    ('menu.update',       'Update menu',               'Edit an existing menu and its slot configuration.'),
    ('menu.delete',       'Delete menu',               'Permanently delete a menu when no historical order references it.'),
    ('category.manage',   'Manage categories',         'Create, update or deactivate product/menu categories.'),
    ('ingredient.manage', 'Manage ingredients',        'Manage ingredients, product composition and allergen mapping.'),
    ('stock.read',        'Read stock',                'View ingredient stock levels and movement history.'),
    ('stock.count',       'Count stock',               'Record a physical inventory count (inventory correction).'),
    ('stock.manage',      'Manage stock',              'Record restocks (pack deliveries) and manage stock parameters.'),
    ('order.read',        'Read orders',               'View orders and the preparation display.'),
    ('order.create',      'Create order',              'Create an order at the counter or drive-thru.'),
    ('order.deliver',     'Deliver order',             'Mark a paid order as delivered (single-gesture handover).'),
    -- E15 (audit schemas 6.3) : le domaine (OrderRepository::cancel, migration 0009)
    -- accepte aussi les etats de cuisine preparing/ready, pas seulement pending_payment
    -- et paid ; cette description le reconduisait faux depuis l'ajout de ces etats.
    ('order.cancel',      'Cancel order',              'Cancel a non-terminal order (pending, paid, preparing or ready ; restocks ingredients if it was paid).'),
    ('user.create',       'Create user',               'Create a new back-office user.'),
    ('user.read',         'Read users',                'View the list and details of back-office users.'),
    ('user.update',       'Update user',               'Edit a back-office user (incl. password reset, RGPD anonymisation).'),
    ('user.deactivate',   'Deactivate user',           'Deactivate a back-office user without deleting the row.'),
    ('role.manage',       'Manage roles and RBAC',     'Manage roles, role/permission assignments and visible sources.'),
    ('stats.read',        'Read statistics',           'Access the statistics / KPI dashboard.');

-- -----------------------------------------------------------------------------
-- 3. role_permission — default matrix, dictionary.md 3.17 grants + PROJECT_CONTEXT
--    section 7 + decision D5. Subqueries on role.code / permission.code avoid
--    hardcoded ids.
-- -----------------------------------------------------------------------------

-- admin: ALL 23 permissions (cross join the admin role with the whole catalogue).
INSERT INTO role_permission (role_id, permission_id)
SELECT r.id, p.id
FROM role r
CROSS JOIN permission p
WHERE r.code = 'admin';

-- manager: catalogue create/update + category/ingredient + full stock + stats.
--          NO order.* (incl. no order.cancel per D5), NO user/role admin.
INSERT INTO role_permission (role_id, permission_id)
SELECT r.id, p.id
FROM role r
JOIN permission p ON p.code IN (
    'product.create', 'product.read', 'product.update',
    'menu.create',    'menu.read',    'menu.update',
    'category.manage', 'ingredient.manage',
    'stock.read', 'stock.count', 'stock.manage',
    'user.read',
    'stats.read'
)
WHERE r.code = 'manager';

-- kitchen: read-only orders + read-only catalogue + inventory (read + count).
INSERT INTO role_permission (role_id, permission_id)
SELECT r.id, p.id
FROM role r
JOIN permission p ON p.code IN (
    'product.read', 'menu.read',
    'stock.read', 'stock.count',
    'order.read'
)
WHERE r.code = 'kitchen';

-- counter: read catalogue + full order lifecycle (read/create/deliver/cancel)
--          + inventory (read + count).
INSERT INTO role_permission (role_id, permission_id)
SELECT r.id, p.id
FROM role r
JOIN permission p ON p.code IN (
    'product.read', 'menu.read',
    'stock.read', 'stock.count',
    'order.read', 'order.create', 'order.deliver', 'order.cancel'
)
WHERE r.code = 'counter';

-- drive: identical grant set to counter (read catalogue + full order lifecycle
--        + inventory). The source differs (auto-tagged drive), not the rights.
INSERT INTO role_permission (role_id, permission_id)
SELECT r.id, p.id
FROM role r
JOIN permission p ON p.code IN (
    'product.read', 'menu.read',
    'stock.read', 'stock.count',
    'order.read', 'order.create', 'order.deliver', 'order.cancel'
)
WHERE r.code = 'drive';

-- -----------------------------------------------------------------------------
-- 4. role_visible_source — dictionary.md 3.16.
--    kitchen sees all 3 sources; counter sees kiosk+counter; drive sees drive.
--    admin/manager: no rows -> global view (no source filter).
-- -----------------------------------------------------------------------------
INSERT INTO role_visible_source (role_id, source)
SELECT r.id, s.source
FROM role r
JOIN (
    SELECT 'kitchen' AS role_code, 'kiosk'   AS source UNION ALL
    SELECT 'kitchen',                'counter'         UNION ALL
    SELECT 'kitchen',                'drive'           UNION ALL
    SELECT 'counter',                'kiosk'           UNION ALL
    SELECT 'counter',                'counter'         UNION ALL
    SELECT 'drive',                  'drive'
) s ON s.role_code = r.code;

-- -----------------------------------------------------------------------------
-- 5. allergen (14) — EU INCO Regulation (EU) No 1169/2011, Annex II.
--    dictionary.md 3.8. code = machine code (en), name = French display label.
-- -----------------------------------------------------------------------------
INSERT INTO allergen (code, name, description) VALUES
    ('gluten',      'Gluten',                    'Céréales contenant du gluten (blé, seigle, orge, avoine, épeautre, kamut) et produits à base de ces céréales.'),
    ('crustaceans', 'Crustacés',                 'Crustacés et produits à base de crustacés.'),
    ('eggs',        'Œufs',                      'Œufs et produits à base d''œufs.'),
    ('fish',        'Poisson',                   'Poissons et produits à base de poissons.'),
    ('peanuts',     'Arachides',                 'Arachides et produits à base d''arachides.'),
    ('soybeans',    'Soja',                      'Soja et produits à base de soja.'),
    ('milk',        'Lait',                      'Lait et produits à base de lait (y compris le lactose).'),
    ('nuts',        'Fruits à coque',            'Fruits à coque : amandes, noisettes, noix, noix de cajou, de pécan, du Brésil, pistaches, noix de Macadamia.'),
    ('celery',      'Céleri',                    'Céleri et produits à base de céleri.'),
    ('mustard',     'Moutarde',                  'Moutarde et produits à base de moutarde.'),
    ('sesame',      'Graines de sésame',         'Graines de sésame et produits à base de graines de sésame.'),
    ('sulphites',   'Anhydride sulfureux et sulfites', 'Anhydride sulfureux et sulfites en concentration supérieure à 10 mg/kg ou 10 mg/l (exprimés en SO2).'),
    ('lupin',       'Lupin',                     'Lupin et produits à base de lupin.'),
    ('molluscs',    'Mollusques',                'Mollusques et produits à base de mollusques.');

-- -----------------------------------------------------------------------------
-- 6. user (1) — bootstrap administrator. dictionary.md 3.14.
--    role_id resolved from role.code = 'admin'. pin_hash NULL (no PIN set yet).
--
--    DEV password: WakdoAdmin2026!  (argon2id hash below, generated via
--    `docker exec wakdo-app php -r 'echo password_hash("WakdoAdmin2026!",
--    PASSWORD_ARGON2ID);'`). MUST be changed in production — this is a known
--    demo credential and must never reach a real deployment as-is.
-- -----------------------------------------------------------------------------
INSERT INTO user (email, password_hash, pin_hash, first_name, last_name, role_id, is_active)
SELECT
    'admin@wakdo.local',
    '$argon2id$v=19$m=65536,t=4,p=1$V3dVMi55cDVBYVZPMU1TRw$8iMoNyfC12t7V2CU+YgqwvEb3xNywm7PUSIoNMgRdvc',
    NULL,
    'Wakdo',
    'Admin',
    r.id,
    1
FROM role r
WHERE r.code = 'admin';
