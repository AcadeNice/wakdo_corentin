-- =============================================================================
-- Wakdo — Initial schema (DDL)
-- =============================================================================
-- Purpose : Create the 21-table relational schema for the Wakdo fast-food
--           ordering system (catalogue, ingredients/stock, orders, RBAC,
--           security-by-design layer).
-- Source  : docs/merise/mld.md (MLD v0.2 — prod-like, 21 tables) +
--           docs/merise/dictionary.md (data dictionary v0.2, types source of truth).
-- Phase   : P2 — generated from the validated Logical Data Model (P1 conception).
-- Target  : MariaDB 11.4 LTS, engine InnoDB, charset utf8mb4, collation
--           utf8mb4_unicode_ci.
--
-- Notes derived from the MLD:
--   - All technical PKs are INT UNSIGNED AUTO_INCREMENT.
--   - Monetary amounts are INT UNSIGNED in cents (anti-FLOAT, dict. note 1).
--   - vat_rate stored per-mille (55 = 5.5%, 100 = 10%).
--   - service_day is NOT a stored/generated column (decision D6): computed in
--     the application layer.
--   - No CREATE DATABASE / USE here: the target DB is chosen by the runner.
--   - No seed / INSERT data here (see db/seeds/0001_demo_data.sql).
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,NO_AUTO_VALUE_ON_ZERO';
SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- 4.1 category — root table for the Catalogue sub-domain (no FK)
-- -----------------------------------------------------------------------------
CREATE TABLE category (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name          VARCHAR(60)     NOT NULL,
    slug          VARCHAR(60)     NOT NULL,
    image_path    VARCHAR(255)        NULL,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active     TINYINT(1)      NOT NULL DEFAULT 1,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_category_name (name),
    UNIQUE KEY uk_category_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4.6 ingredient — root table for Ingredients & Stock (no FK)
-- -----------------------------------------------------------------------------
CREATE TABLE ingredient (
    id                 INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    name               VARCHAR(120)      NOT NULL,
    unit               VARCHAR(40)       NOT NULL,
    stock_quantity     INT               NOT NULL DEFAULT 0,
    stock_capacity     INT               NOT NULL,
    pack_size          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    pack_label         VARCHAR(80)           NULL,
    low_stock_pct      SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    critical_stock_pct SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    is_active          TINYINT(1)        NOT NULL DEFAULT 1,
    created_at         DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_ingredient_name (name),
    CONSTRAINT chk_ingredient_stock_capacity     CHECK (stock_capacity > 0),
    CONSTRAINT chk_ingredient_pack_size          CHECK (pack_size > 0),
    CONSTRAINT chk_ingredient_low_stock_pct      CHECK (low_stock_pct BETWEEN 0 AND 100),
    CONSTRAINT chk_ingredient_critical_stock_pct CHECK (critical_stock_pct BETWEEN 0 AND 100),
    CONSTRAINT chk_ingredient_critical_lt_low    CHECK (critical_stock_pct < low_stock_pct)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4.8 allergen — reference table (INCO EU 1169/2011), no FK
-- -----------------------------------------------------------------------------
CREATE TABLE allergen (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(30)  NOT NULL,
    name        VARCHAR(80)  NOT NULL,
    description TEXT             NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_allergen_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4.10 role — root table for RBAC (no FK)
-- -----------------------------------------------------------------------------
CREATE TABLE role (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code          VARCHAR(40)  NOT NULL,
    label         VARCHAR(80)  NOT NULL,
    description   TEXT             NULL,
    default_route VARCHAR(120)     NULL,
    order_source  ENUM('kiosk','counter','drive') NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_role_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4.13 permission — reference table, catalogue frozen at 23 codes (no FK)
-- -----------------------------------------------------------------------------
CREATE TABLE permission (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(60)  NOT NULL,
    label       VARCHAR(120) NOT NULL,
    description TEXT             NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_permission_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4.21 login_throttle — per-source-IP brute-force throttle (no FK)
-- -----------------------------------------------------------------------------
CREATE TABLE login_throttle (
    id                INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    ip_address        VARCHAR(45)       NOT NULL,
    failed_attempts   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lockout_until     DATETIME              NULL,
    last_attempt_at   DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_login_throttle_ip_address (ip_address),
    KEY idx_login_throttle_lockout_until (lockout_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4.2 product — depends on category
-- -----------------------------------------------------------------------------
CREATE TABLE product (
    id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    category_id   INT UNSIGNED      NOT NULL,
    name          VARCHAR(120)      NOT NULL,
    description   TEXT                  NULL,
    price_cents   INT UNSIGNED      NOT NULL,
    vat_rate      SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    image_path    VARCHAR(255)          NULL,
    is_available  TINYINT(1)        NOT NULL DEFAULT 1,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_product_category_available_order (category_id, is_available, display_order),
    CONSTRAINT fk_product_category_id FOREIGN KEY (category_id)
        REFERENCES category (id) ON DELETE RESTRICT,
    CONSTRAINT chk_product_price_cents CHECK (price_cents > 0),
    CONSTRAINT chk_product_vat_rate    CHECK (vat_rate IN (55, 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4.3 menu — depends on category, product
-- -----------------------------------------------------------------------------
CREATE TABLE menu (
    id                 INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    category_id        INT UNSIGNED      NOT NULL,
    burger_product_id  INT UNSIGNED      NOT NULL,
    name               VARCHAR(120)      NOT NULL,
    description        TEXT                  NULL,
    price_normal_cents INT UNSIGNED      NOT NULL,
    price_maxi_cents   INT UNSIGNED      NOT NULL,
    image_path         VARCHAR(255)          NULL,
    is_available       TINYINT(1)        NOT NULL DEFAULT 1,
    display_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at         DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_menu_category_available_order (category_id, is_available, display_order),
    CONSTRAINT fk_menu_category_id FOREIGN KEY (category_id)
        REFERENCES category (id) ON DELETE RESTRICT,
    CONSTRAINT fk_menu_burger_product_id FOREIGN KEY (burger_product_id)
        REFERENCES product (id) ON DELETE RESTRICT,
    CONSTRAINT chk_menu_price_normal_cents CHECK (price_normal_cents > 0),
    CONSTRAINT chk_menu_price_maxi_cents   CHECK (price_maxi_cents > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4.4 menu_slot — depends on menu (no audit fields)
-- -----------------------------------------------------------------------------
CREATE TABLE menu_slot (
    id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    menu_id       INT UNSIGNED      NOT NULL,
    name          VARCHAR(80)       NOT NULL,
    slot_type     ENUM('drink','side','sauce','dessert','extra') NOT NULL,
    is_required   TINYINT(1)        NOT NULL DEFAULT 1,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_menu_slot_menu_order (menu_id, display_order),
    CONSTRAINT fk_menu_slot_menu_id FOREIGN KEY (menu_id)
        REFERENCES menu (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4.5 menu_slot_option — pure join table, composite PK
--                        depends on menu_slot, product
-- -----------------------------------------------------------------------------
CREATE TABLE menu_slot_option (
    menu_slot_id INT UNSIGNED NOT NULL,
    product_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (menu_slot_id, product_id),
    KEY idx_menu_slot_option_product_id (product_id),
    CONSTRAINT fk_menu_slot_option_menu_slot_id FOREIGN KEY (menu_slot_id)
        REFERENCES menu_slot (id) ON DELETE CASCADE,
    CONSTRAINT fk_menu_slot_option_product_id FOREIGN KEY (product_id)
        REFERENCES product (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4.7 product_ingredient — join table with attributes, composite PK
--                          depends on product, ingredient
-- -----------------------------------------------------------------------------
CREATE TABLE product_ingredient (
    product_id        INT UNSIGNED      NOT NULL,
    ingredient_id     INT UNSIGNED      NOT NULL,
    quantity_normal   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    quantity_maxi     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    is_removable      TINYINT(1)        NOT NULL DEFAULT 1,
    is_addable        TINYINT(1)        NOT NULL DEFAULT 0,
    extra_price_cents INT UNSIGNED      NOT NULL DEFAULT 0,
    PRIMARY KEY (product_id, ingredient_id),
    KEY idx_product_ingredient_ingredient_id (ingredient_id),
    CONSTRAINT fk_product_ingredient_product_id FOREIGN KEY (product_id)
        REFERENCES product (id) ON DELETE CASCADE,
    CONSTRAINT fk_product_ingredient_ingredient_id FOREIGN KEY (ingredient_id)
        REFERENCES ingredient (id) ON DELETE RESTRICT,
    CONSTRAINT chk_product_ingredient_quantity_normal CHECK (quantity_normal > 0),
    CONSTRAINT chk_product_ingredient_quantity_maxi   CHECK (quantity_maxi >= quantity_normal),
    CONSTRAINT chk_product_ingredient_extra_price     CHECK (extra_price_cents >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4.9 ingredient_allergen — pure join table, composite PK
--                           depends on ingredient, allergen
-- -----------------------------------------------------------------------------
CREATE TABLE ingredient_allergen (
    ingredient_id INT UNSIGNED NOT NULL,
    allergen_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (ingredient_id, allergen_id),
    KEY idx_ingredient_allergen_allergen_id (allergen_id),
    CONSTRAINT fk_ingredient_allergen_ingredient_id FOREIGN KEY (ingredient_id)
        REFERENCES ingredient (id) ON DELETE CASCADE,
    CONSTRAINT fk_ingredient_allergen_allergen_id FOREIGN KEY (allergen_id)
        REFERENCES allergen (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4.11 user — depends on role
-- -----------------------------------------------------------------------------
CREATE TABLE user (
    id                        INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    email                     VARCHAR(254)      NOT NULL,
    password_hash             VARCHAR(255)      NOT NULL,
    pin_hash                  VARCHAR(255)          NULL,
    first_name                VARCHAR(60)       NOT NULL,
    last_name                 VARCHAR(60)       NOT NULL,
    role_id                   INT UNSIGNED      NOT NULL,
    is_active                 TINYINT(1)        NOT NULL DEFAULT 1,
    last_login_at             DATETIME              NULL,
    failed_login_attempts     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_failed_login_at      DATETIME              NULL,
    lockout_until             DATETIME              NULL,
    password_reset_token_hash VARCHAR(255)          NULL,
    password_reset_expires_at DATETIME              NULL,
    anonymized_at             DATETIME              NULL,
    created_at                DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_user_email (email),
    KEY idx_user_active_role (is_active, role_id),
    CONSTRAINT fk_user_role_id FOREIGN KEY (role_id)
        REFERENCES role (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4.12 role_visible_source — pure join table, composite PK
--                            depends on role
-- -----------------------------------------------------------------------------
CREATE TABLE role_visible_source (
    role_id INT UNSIGNED NOT NULL,
    source  ENUM('kiosk','counter','drive') NOT NULL,
    PRIMARY KEY (role_id, source),
    CONSTRAINT fk_role_visible_source_role_id FOREIGN KEY (role_id)
        REFERENCES role (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4.14 role_permission — pure join table, composite PK
--                        depends on role, permission
-- -----------------------------------------------------------------------------
CREATE TABLE role_permission (
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    KEY idx_role_permission_permission_id (permission_id),
    CONSTRAINT fk_role_permission_role_id FOREIGN KEY (role_id)
        REFERENCES role (id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permission_permission_id FOREIGN KEY (permission_id)
        REFERENCES permission (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4.15 customer_order — depends on user (acting_user_id)
-- -----------------------------------------------------------------------------
CREATE TABLE customer_order (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_number    VARCHAR(20)  NOT NULL,
    idempotency_key VARCHAR(36)      NULL,
    source          ENUM('kiosk','counter','drive') NOT NULL,
    acting_user_id  INT UNSIGNED     NULL,
    service_mode    ENUM('dine_in','takeaway','drive') NOT NULL,
    status          ENUM('pending_payment','paid','delivered','cancelled') NOT NULL DEFAULT 'pending_payment',
    total_ht_cents  INT UNSIGNED NOT NULL,
    total_vat_cents INT UNSIGNED NOT NULL,
    total_ttc_cents INT UNSIGNED NOT NULL,
    paid_at         DATETIME         NULL,
    delivered_at    DATETIME         NULL,
    cancelled_at    DATETIME         NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_customer_order_order_number (order_number),
    UNIQUE KEY uk_customer_order_idempotency_key (idempotency_key),
    KEY idx_customer_order_status_created (status, created_at),
    KEY idx_customer_order_source_created (source, created_at),
    KEY idx_customer_order_created (created_at),
    CONSTRAINT fk_customer_order_acting_user_id FOREIGN KEY (acting_user_id)
        REFERENCES user (id) ON DELETE SET NULL,
    CONSTRAINT chk_customer_order_total_ht       CHECK (total_ht_cents >= 0),
    CONSTRAINT chk_customer_order_total_vat      CHECK (total_vat_cents >= 0),
    CONSTRAINT chk_customer_order_total_ttc      CHECK (total_ttc_cents > 0),
    CONSTRAINT chk_customer_order_total_coherent CHECK (total_ttc_cents = total_ht_cents + total_vat_cents),
    CONSTRAINT chk_customer_order_drive_mode     CHECK (source <> 'drive' OR service_mode = 'drive')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4.16 order_item — depends on customer_order, product, menu
--                   polymorphic line (product XOR menu)
-- -----------------------------------------------------------------------------
CREATE TABLE order_item (
    id                        INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    order_id                  INT UNSIGNED      NOT NULL,
    item_type                 ENUM('product','menu') NOT NULL,
    product_id                INT UNSIGNED          NULL,
    menu_id                   INT UNSIGNED          NULL,
    format                    ENUM('normal','maxi') NOT NULL DEFAULT 'normal',
    label_snapshot            VARCHAR(120)      NOT NULL,
    unit_price_cents_snapshot INT UNSIGNED      NOT NULL,
    vat_rate_snapshot         SMALLINT UNSIGNED NOT NULL,
    quantity                  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at                DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_order_item_order_id (order_id),
    KEY idx_order_item_product_id (product_id),
    KEY idx_order_item_menu_id (menu_id),
    CONSTRAINT fk_order_item_order_id FOREIGN KEY (order_id)
        REFERENCES customer_order (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_item_product_id FOREIGN KEY (product_id)
        REFERENCES product (id) ON DELETE RESTRICT,
    CONSTRAINT fk_order_item_menu_id FOREIGN KEY (menu_id)
        REFERENCES menu (id) ON DELETE RESTRICT,
    CONSTRAINT chk_order_item_unit_price CHECK (unit_price_cents_snapshot > 0),
    CONSTRAINT chk_order_item_vat_rate   CHECK (vat_rate_snapshot IN (55, 100)),
    CONSTRAINT chk_order_item_quantity   CHECK (quantity > 0),
    CONSTRAINT chk_order_item_polymorphism CHECK (
        (item_type = 'product' AND product_id IS NOT NULL AND menu_id IS NULL)
        OR (item_type = 'menu' AND menu_id IS NOT NULL AND product_id IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4.17 order_item_selection — depends on order_item, menu_slot, product
-- -----------------------------------------------------------------------------
CREATE TABLE order_item_selection (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_item_id  INT UNSIGNED NOT NULL,
    menu_slot_id   INT UNSIGNED NOT NULL,
    product_id     INT UNSIGNED NOT NULL,
    label_snapshot VARCHAR(120) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_order_item_selection_order_item_id (order_item_id),
    KEY idx_order_item_selection_menu_slot_id (menu_slot_id),
    KEY idx_order_item_selection_product_id (product_id),
    CONSTRAINT fk_order_item_selection_order_item_id FOREIGN KEY (order_item_id)
        REFERENCES order_item (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_item_selection_menu_slot_id FOREIGN KEY (menu_slot_id)
        REFERENCES menu_slot (id) ON DELETE RESTRICT,
    CONSTRAINT fk_order_item_selection_product_id FOREIGN KEY (product_id)
        REFERENCES product (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4.18 order_item_modifier — depends on order_item, ingredient
-- -----------------------------------------------------------------------------
CREATE TABLE order_item_modifier (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_item_id     INT UNSIGNED NOT NULL,
    ingredient_id     INT UNSIGNED NOT NULL,
    action            ENUM('remove','add') NOT NULL,
    extra_price_cents INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_order_item_modifier_order_item_id (order_item_id),
    KEY idx_order_item_modifier_ingredient_id (ingredient_id),
    CONSTRAINT fk_order_item_modifier_order_item_id FOREIGN KEY (order_item_id)
        REFERENCES order_item (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_item_modifier_ingredient_id FOREIGN KEY (ingredient_id)
        REFERENCES ingredient (id) ON DELETE RESTRICT,
    CONSTRAINT chk_order_item_modifier_extra_price CHECK (extra_price_cents >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4.19 stock_movement — append-only audit log
--                       depends on ingredient, customer_order, user
-- -----------------------------------------------------------------------------
CREATE TABLE stock_movement (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ingredient_id INT UNSIGNED NOT NULL,
    movement_type ENUM('sale','cancellation','restock','inventory_correction') NOT NULL,
    delta         INT          NOT NULL,
    order_id      INT UNSIGNED     NULL,
    user_id       INT UNSIGNED     NULL,
    note          VARCHAR(255)     NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_stock_movement_ingredient_created (ingredient_id, created_at),
    KEY idx_stock_movement_type_created (movement_type, created_at),
    KEY idx_stock_movement_order_id (order_id),
    KEY idx_stock_movement_user_id (user_id),
    CONSTRAINT fk_stock_movement_ingredient_id FOREIGN KEY (ingredient_id)
        REFERENCES ingredient (id) ON DELETE RESTRICT,
    CONSTRAINT fk_stock_movement_order_id FOREIGN KEY (order_id)
        REFERENCES customer_order (id) ON DELETE SET NULL,
    CONSTRAINT fk_stock_movement_user_id FOREIGN KEY (user_id)
        REFERENCES user (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4.20 audit_log — append-only sensitive-action log
--                  depends on user, role
-- -----------------------------------------------------------------------------
CREATE TABLE audit_log (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id INT UNSIGNED     NULL,
    actor_role_id INT UNSIGNED     NULL,
    action_code   VARCHAR(60)  NOT NULL,
    entity_type   VARCHAR(40)      NULL,
    entity_id     INT UNSIGNED     NULL,
    summary       VARCHAR(255)     NULL,
    details       JSON             NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_log_actor_created (actor_user_id, created_at),
    KEY idx_audit_log_entity (entity_type, entity_id),
    KEY idx_audit_log_action_created (action_code, created_at),
    KEY idx_audit_log_actor_role_id (actor_role_id),
    CONSTRAINT fk_audit_log_actor_user_id FOREIGN KEY (actor_user_id)
        REFERENCES user (id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_log_actor_role_id FOREIGN KEY (actor_role_id)
        REFERENCES role (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- Restore session settings
-- =============================================================================
SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;
SET SQL_MODE = @OLD_SQL_MODE;
