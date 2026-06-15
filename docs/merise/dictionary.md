# Data Dictionary — Wakdo

**Merise phase** : P1 - Conception, step 1 (data dictionary first, mantra #33)
**Version** : v0.2 — prod-like, 21 entities (19 prod-like + security-by-design layer, incl. the new `login_throttle` entity)
**Date** : 2026-06-04 (security-by-design additions 2026-06-11)
**Branch** : `feat/p1-conception`
**Status** : prod-like — all D1-D8 + stock decisions applied (see `docs/notes/revue-alignement-p1.md` §7); security-by-design layer in progress (see note 13)
**Author** : BYAN (methodology layer)

---

## 1. Purpose

This dictionary lists **all data entities** identified for Wakdo, with their attributes,
types, constraints, and sources. It serves as the basis for the MCD (entities + relations),
then the MLD (relational mapping), then the DDL (SQL CREATE TABLE).

**Methodology**: bottom-up derivation from available sources:
- **School source**: `docs/merise/_sources/categories.json` + `produits.json`
  (66 products, 9 categories)
- **Business brief**: `docs/PROJECT_CONTEXT.md` (menu composition, order flow, RBAC,
  service modes)
- **Mockup**: `docs/design/maquette-borne.pdf` (kiosk UX, visible screens)

All deviations between school source and final model are documented in the
"Modeling notes" section at the bottom of this document.

For the entity-relationship diagram and cardinality justifications, see [`mcd.md`](mcd.md).
This dictionary does not duplicate that view to avoid diverging sources of truth.

---

## 2. General conventions

### Naming

- **Tables**: `snake_case`, singular (e.g., `category`, `product`, `customer_order`).
  Singular reflects the perspective "1 row = 1 instance of the entity" (standard relational
  convention). Application code (PHP, JS) uses these names as-is via ORM mapping.
- **Columns**: `snake_case`. Typical suffixes: `_id` (FK), `_at` (timestamp),
  `_cents` (monetary amount in integer cents), `_path` (file path), `_rate` (rate or
  fraction stored as per-mille integer).
- **Primary keys**: column `id` (INT UNSIGNED AUTO_INCREMENT). No composite PK except
  on pure join tables.
- **Foreign keys**: `<referenced_table>_id` (e.g., `category_id` in `product`).
- **ENUM values**: English, snake_case (e.g., `pending_payment`, `dine_in`, `kiosk`).
- **Code-facing strings** (ENUM, permission codes, role codes): English only, consistent
  across DB, PHP, and JSON API.

### Default types

| Category | MariaDB type | Justification |
|---|---|---|
| Identifiers | `INT UNSIGNED AUTO_INCREMENT` | 4 billion ids — sufficient for this project |
| Short labels | `VARCHAR(120)` | Covers most product names (max observed: 41 chars in school source) |
| Descriptions | `TEXT` | Variable length, no strict limit |
| Monetary amounts | `INT UNSIGNED` (cents) | Avoids FLOAT rounding bugs (see note 1) |
| Booleans | `TINYINT(1)` | MariaDB convention for `BOOLEAN` (alias) |
| Timestamps | `DATETIME` | Human-readable, timezone handled at app layer |
| Enumerations | `ENUM('a','b','c')` | DBMS-level constraint, readable (see note 2) |
| File paths | `VARCHAR(255)` | Standard POSIX path length limit |

### Charset and collation

- **Charset**: `utf8mb4` (RFC 3629 — real 4-byte UTF-8, supports emoji and Asian characters).
  MariaDB handles `utf8mb4` natively.
- **Collation**: `utf8mb4_unicode_ci` (case-insensitive, Unicode-compliant comparison).

### Audit fields (present on all business tables except pure join tables)

| Column | Type | Default | Role |
|---|---|---|---|
| `created_at` | `DATETIME` | `CURRENT_TIMESTAMP` | Creation timestamp, written once at insert |
| `updated_at` | `DATETIME` | `CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | Last modification timestamp, auto-updated |

### Soft delete

No generalized soft delete. Entities that can be temporarily deactivated carry an
`is_active` or `is_available` boolean column. Hard `DELETE` remains possible but is
reserved for admin operations with prior backup.

---

## 3. Entities

### 3.1 `category`

Business grouping of products and menus for display on the kiosk.

| Attribute | Type | NULL | Default | Constraint | School source | Notes |
|---|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | `id` (1-9) | same as source |
| `name` | VARCHAR(60) | NO | — | UNIQUE | `title` | renamed from `title` |
| `slug` | VARCHAR(60) | NO | — | UNIQUE | derived from `title` (kebab-case lowercase) | used for URL `/api/categories/burgers` |
| `image_path` | VARCHAR(255) | YES | NULL | — | `image` | relative path, see note 8 |
| `display_order` | SMALLINT UNSIGNED | NO | 0 | — | (added) | display order on kiosk, adjustable from admin |
| `is_active` | TINYINT(1) | NO | 1 | — | (added) | deactivate without deleting |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | — | audit |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | — | — | audit |

**Examples**: `menus`, `drinks`, `burgers`, `fries`, `snacks`, `wraps`, `salads`,
`desserts`, `sauces`. Volume: 9 rows at init (seed from `categories.json`).

---

### 3.2 `product`

A single sellable item, available a la carte or as a component in a menu slot.

| Attribute | Type | NULL | Default | Constraint | School source | Notes |
|---|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | `id` | same as source |
| `category_id` | INT UNSIGNED | NO | — | FK -> `category(id)`, ON DELETE RESTRICT | (derived from JSON object key) | |
| `name` | VARCHAR(120) | NO | — | INDEX | `nom` | renamed from `nom` |
| `description` | TEXT | YES | NULL | — | (added) | populated later via admin |
| `price_cents` | INT UNSIGNED | NO | — | CHECK > 0 | `prix` (FLOAT) | FLOAT -> INT cents conversion at seed (see note 1) |
| `vat_rate` | SMALLINT UNSIGNED | NO | 100 | CHECK IN (55, 100) | (added) | VAT rate in per-mille: 100 = 10%, 55 = 5.5%. Default 10%. See note 9 |
| `image_path` | VARCHAR(255) | YES | NULL | — | `image` | relative path, see note 8 |
| `is_available` | TINYINT(1) | NO | 1 | — | (added) | manual availability toggle from admin |
| `display_order` | SMALLINT UNSIGNED | NO | 0 | — | (added) | display order within category |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | — | audit |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | — | — | audit |

**Volume**: ~53 rows at init (66 rows in `produits.json` minus 13 menus moved to `menu`).

---

### 3.3 `menu`

Fixed-price combo built around a specific burger, with customer-selectable slots
(drink, side, sauce). Two price tiers: Normal and Maxi.

| Attribute | Type | NULL | Default | Constraint | School source | Notes |
|---|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | `id` (1-13 in `menus` category) | |
| `category_id` | INT UNSIGNED | NO | — | FK -> `category(id)`, ON DELETE RESTRICT | implicit (category `menus`) | |
| `burger_product_id` | INT UNSIGNED | NO | — | FK -> `product(id)`, ON DELETE RESTRICT | (added) | the fixed burger that anchors this menu; drives ingredient customization |
| `name` | VARCHAR(120) | NO | — | INDEX | `nom` | e.g., "Menu Le 280" |
| `description` | TEXT | YES | NULL | — | (added) | |
| `price_normal_cents` | INT UNSIGNED | NO | — | CHECK > 0 | `prix` | Normal format price. Replaces single `prix_ttc_cents`. |
| `price_maxi_cents` | INT UNSIGNED | NO | — | CHECK > 0 | (added) | Maxi format price (~+150 cents vs normal; see note 7) |
| `image_path` | VARCHAR(255) | YES | NULL | — | `image` | typically reuses the burger image |
| `is_available` | TINYINT(1) | NO | 1 | — | (added) | |
| `display_order` | SMALLINT UNSIGNED | NO | 0 | — | (added) | |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | — | audit |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | — | — | audit |

**Volume**: 13 rows at init. Replaces the old fixed-composition `menu_produit` model.

---

### 3.4 `menu_slot`

A selectable slot within a menu (e.g., "drink slot", "side slot", "sauce slot").
Each slot constrains which products the customer can choose from, expressed via
the join table `menu_slot_option`.

| Attribute | Type | NULL | Default | Constraint | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `menu_id` | INT UNSIGNED | NO | — | FK -> `menu(id)`, ON DELETE CASCADE | a slot belongs to exactly one menu |
| `name` | VARCHAR(80) | NO | — | — | e.g., "Drink", "Side", "Sauce" |
| `slot_type` | ENUM('drink','side','sauce','dessert','extra') | NO | — | — | semantic role of this slot |
| `is_required` | TINYINT(1) | NO | 1 | — | whether the customer must fill this slot |
| `display_order` | SMALLINT UNSIGNED | NO | 0 | — | order of display in the menu builder |

**No audit fields**: a slot is part of menu definition; created and updated with the menu.
**Composite index**: `(menu_id, display_order)`.

---

### 3.5 `menu_slot_option`

Eligible products for a given menu slot. Pure join table.

| Attribute | Type | NULL | Default | Constraint | Notes |
|---|---|---|---|---|---|
| `menu_slot_id` | INT UNSIGNED | NO | — | FK -> `menu_slot(id)`, ON DELETE CASCADE | |
| `product_id` | INT UNSIGNED | NO | — | FK -> `product(id)`, ON DELETE RESTRICT | RESTRICT: removing a product must not silently break menus |

**Primary key**: composite `(menu_slot_id, product_id)`.

**Volume**: ~3-5 options per slot, ~3 slots per menu, 13 menus = ~120-200 rows at init.

---

### 3.6 `ingredient`

Elementary ingredient used in product composition. Carries stock data.

| Attribute | Type | NULL | Default | Constraint | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `name` | VARCHAR(120) | NO | — | UNIQUE | e.g., "Sesame Bun", "Cheddar Slice", "Ketchup Portion" |
| `unit` | VARCHAR(40) | NO | — | — | packaging unit label: piece / portion / sachet 1kg / pot / bottle (free-form label, not an ENUM — units vary per ingredient) |
| `stock_quantity` | INT (signed) | NO | 0 | — | current stock in units. Signed INT with no `CHECK >= 0`: it MAY go negative when sales outrun counted stock (oversell magnitude, surfaced to managers). The system does not block an order on stock. |
| `stock_capacity` | INT | NO | — | CHECK > 0 | reference "full" level in units = the 100% used to compute the stock percentage. The `CHECK > 0` also guards the percentage division against divide-by-zero |
| `pack_size` | SMALLINT UNSIGNED | NO | 1 | CHECK > 0 | units per restocking pack (e.g., 100 for a bag of 100 portions) |
| `pack_label` | VARCHAR(80) | YES | NULL | — | human label of the pack (e.g., "Sac 100 portions") |
| `low_stock_pct` | SMALLINT UNSIGNED | NO | 10 | CHECK BETWEEN 0 AND 100 | warning band, percent of capacity: `stock_quantity <= stock_capacity * low_stock_pct/100` triggers the low-stock indicator |
| `critical_stock_pct` | SMALLINT UNSIGNED | NO | 5 | CHECK BETWEEN 0 AND 100 | auto-out-of-stock floor, percent of capacity: `stock_quantity <= stock_capacity * critical_stock_pct/100` makes the product computed out-of-stock |
| `is_active` | TINYINT(1) | NO | 1 | — | deactivate obsolete ingredients without deleting |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | audit |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | — | audit |

**Table-level CHECK**: `critical_stock_pct < low_stock_pct` (the critical floor sits below the warning band).

**Stock decrement rule**: at the `paid` transition, each ingredient is decremented by
`product_ingredient.quantity_normal` or `quantity_maxi` (selected by `order_item.format`)
multiplied by `order_item.quantity`, then adjusted by `order_item_modifier` rows. See note 7.
**Restocking rule**: `stock_quantity += N * pack_size` (restocked in full packs).
**Cancellation rule**: stock is re-credited when a `paid` order is cancelled.
**Stock model (percentage-based, three bands)**: the absolute alert threshold is replaced by a
percentage model anchored on `stock_capacity` (the 100% reference). The stock percentage is
computed, not stored: `stock_pct = ROUND(stock_quantity / stock_capacity * 100)`. The
`CHECK > 0` on `stock_capacity` guards this division against divide-by-zero. Three bands:
- **Normal** — above the low band: nothing flagged.
- **Low** — `stock_quantity <= stock_capacity * low_stock_pct/100`: orderable + manager alert.
  The manager either pulls the product via `product.is_available=0`, or restocks to clear the alert.
- **Critical** — `stock_quantity <= stock_capacity * critical_stock_pct/100`: the product
  auto-goes out-of-stock (computed availability, see rule RG-T21 in `mlt.md`); no extra stored column.

---

### 3.7 `product_ingredient`

Default composition of a product (burger, wrap, etc.) in terms of ingredients.
Carries customization metadata for the ingredient configurator.

| Attribute | Type | NULL | Default | Constraint | Notes |
|---|---|---|---|---|---|
| `product_id` | INT UNSIGNED | NO | — | FK -> `product(id)`, ON DELETE CASCADE | |
| `ingredient_id` | INT UNSIGNED | NO | — | FK -> `ingredient(id)`, ON DELETE RESTRICT | RESTRICT: cannot remove an ingredient still referenced in a product recipe |
| `quantity_normal` | SMALLINT UNSIGNED | NO | 1 | CHECK > 0 | units consumed in Normal format (e.g., 2 for double cheese) |
| `quantity_maxi` | SMALLINT UNSIGNED | NO | 1 | CHECK > 0 | units consumed in Maxi format. Equals `quantity_normal` for format-invariant ingredients (burger, sauce); higher for side and drink ingredients (Maxi enlarges side + drink only). See note 7. |
| `is_removable` | TINYINT(1) | NO | 1 | — | customer can remove this ingredient at no cost |
| `is_addable` | TINYINT(1) | NO | 0 | — | customer can add an extra unit of this ingredient |
| `extra_price_cents` | INT UNSIGNED | NO | 0 | CHECK >= 0 | surcharge in cents when `is_addable=1` and customer adds it (0 = free extra) |

**Primary key**: composite `(product_id, ingredient_id)`.

**Volume**: ~5-10 ingredients per product, ~53 products = ~300-500 rows at seed.

---

### 3.8 `allergen`

Catalogue of the 14 regulated allergens (INCO Regulation (EU) 1169/2011).

| Attribute | Type | NULL | Default | Constraint | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `code` | VARCHAR(30) | NO | — | UNIQUE | machine-readable code, e.g., `gluten`, `milk`, `nuts` |
| `name` | VARCHAR(80) | NO | — | — | display name, e.g., "Gluten", "Lait", "Fruits a coque" |
| `description` | TEXT | YES | NULL | — | optional guidance for staff |

**Volume**: 14 rows at seed (fixed by EU regulation 1169/2011, list confirmed at seed time).
Allergens for a product are **computed** by joining `product_ingredient` ->
`ingredient_allergen` -> `allergen`; no manual re-entry per product.

---

### 3.9 `ingredient_allergen`

Maps which allergens each ingredient contains. Pure join table.

| Attribute | Type | NULL | Default | Constraint | Notes |
|---|---|---|---|---|---|
| `ingredient_id` | INT UNSIGNED | NO | — | FK -> `ingredient(id)`, ON DELETE CASCADE | |
| `allergen_id` | INT UNSIGNED | NO | — | FK -> `allergen(id)`, ON DELETE RESTRICT | |

**Primary key**: composite `(ingredient_id, allergen_id)`.

---

### 3.10 `customer_order`

Customer transaction: 1 order = 1 validated cart at a point in time.
(Table name rationale: see modeling note 3.)

| Attribute | Type | NULL | Default | Constraint | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `order_number` | VARCHAR(20) | NO | — | UNIQUE | human-readable format: `K`/`C`/`D`-YYYY-MM-DD-NNN. Prefix by channel: K=kiosk, C=counter, D=drive. See note 4. |
| `idempotency_key` | VARCHAR(36) | YES | NULL | UNIQUE | client-generated UUID to deduplicate a retried `POST /api/orders` (anti-double-charge). UNIQUE rejects duplicates; multiple NULLs allowed. Security-by-design, see note 13 |
| `source` | ENUM('kiosk','counter','drive') | NO | — | INDEX | input channel (who entered the order). Values in English, see note 5. |
| `acting_user_id` | INT UNSIGNED | YES | NULL | FK -> `user(id)`, ON DELETE SET NULL | back-office staff (counter/drive) who created the order, captured under PIN. NULL for `kiosk` (anonymous). Targeted accountability without forcing per-person login on the kiosk. See note 13 |
| `service_mode` | ENUM('dine_in','takeaway','drive') | NO | — | — | consumption mode, retained for stats/KPI only. No fiscal role (see note 9). `drive` source implies `drive` service_mode (cross-constraint enforced at app layer). |
| `status` | ENUM('pending_payment','paid','delivered','cancelled') | NO | 'pending_payment' | INDEX | 4-state machine: `pending_payment -> paid -> delivered` (+ `cancelled`). See note 6. |
| `total_ht_cents` | INT UNSIGNED | NO | — | CHECK >= 0 | ex-VAT total, snapshot at order validation |
| `total_vat_cents` | INT UNSIGNED | NO | — | CHECK >= 0 | VAT amount, snapshot |
| `total_ttc_cents` | INT UNSIGNED | NO | — | CHECK > 0 | incl.-VAT total; must equal total_ht_cents + total_vat_cents (verified at MLT layer) |
| `paid_at` | DATETIME | YES | NULL | — | timestamp of transition to `paid` (NULL before payment) |
| `delivered_at` | DATETIME | YES | NULL | — | timestamp of transition to `delivered` (NULL before delivery) |
| `cancelled_at` | DATETIME | YES | NULL | — | timestamp of cancellation (NULL if not cancelled) |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | INDEX | used for live stats aggregations; also serves as `service_day` base |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | — | audit |

**Dropped from v0.1**: `tva_taux_pourmille` (moved to line level — `order_item.vat_rate_snapshot`),
`paye_a` (renamed `paid_at`). Machine states `preparing` and `ready` dropped (see note 6).

**`service_day` computation** (KPI grouping):
```
CASE WHEN HOUR(created_at) < 10 THEN DATE(created_at) - INTERVAL 1 DAY ELSE DATE(created_at) END
```
Computed at query time, not stored as a column (the generated-column formula with `INTERVAL 4 HOUR
30 MINUTE` in v0.1 MLD was incorrect and is dropped). Cutoff: 10:00.

**Volume**: ~100-300 orders/day at peak, ~10k rows over a 6-month demo.

---

### 3.11 `order_item`

Line of an order: a single product or a menu, with price, label, and VAT rate
snapshotted at transaction time.

| Attribute | Type | NULL | Default | Constraint | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `order_id` | INT UNSIGNED | NO | — | FK -> `customer_order(id)`, ON DELETE CASCADE | |
| `item_type` | ENUM('product','menu') | NO | — | — | discriminator |
| `product_id` | INT UNSIGNED | YES | NULL | FK -> `product(id)`, ON DELETE RESTRICT | non-null if `item_type = 'product'` |
| `menu_id` | INT UNSIGNED | YES | NULL | FK -> `menu(id)`, ON DELETE RESTRICT | non-null if `item_type = 'menu'` |
| `format` | ENUM('normal','maxi') | NO | 'normal' | — | applies to menu items (Normal / Maxi). For standalone products, value is `normal` (no individual upsizing in this model). See note 7. |
| `label_snapshot` | VARCHAR(120) | NO | — | — | label at time of order (preserved if product is renamed) |
| `unit_price_cents_snapshot` | INT UNSIGNED | NO | — | CHECK > 0 | unit price incl. VAT at time of order |
| `vat_rate_snapshot` | SMALLINT UNSIGNED | NO | — | CHECK IN (55, 100) | VAT rate in per-mille at time of order (snapshotted from `product.vat_rate`) |
| `quantity` | SMALLINT UNSIGNED | NO | 1 | CHECK > 0 | quantity ordered (e.g., 3 Cocas = 1 line with quantity=3) |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | audit |

**CHECK constraint** (applicative or MariaDB CHECK >= 10.2):
`(item_type='product' AND product_id IS NOT NULL AND menu_id IS NULL)
OR (item_type='menu' AND menu_id IS NOT NULL AND product_id IS NULL)`

**Volume**: ~3-5 lines per order -> 30k-50k rows over 6 months.

---

### 3.12 `order_item_selection`

The actual choices made by the customer for each slot of a menu line.
1 row = 1 slot filled for 1 order_item of type `menu`.

| Attribute | Type | NULL | Default | Constraint | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `order_item_id` | INT UNSIGNED | NO | — | FK -> `order_item(id)`, ON DELETE CASCADE | must reference an order_item with item_type='menu' |
| `menu_slot_id` | INT UNSIGNED | NO | — | FK -> `menu_slot(id)`, ON DELETE RESTRICT | which slot was filled |
| `product_id` | INT UNSIGNED | NO | — | FK -> `product(id)`, ON DELETE RESTRICT | product chosen by the customer for this slot |
| `label_snapshot` | VARCHAR(120) | NO | — | — | product label at time of order |

**Volume**: ~2-3 selections per menu line.
**KPI use**: enables analysis of which drink/side combinations are most chosen.

---

### 3.13 `order_item_modifier`

Ingredient-level modifications applied by the customer to a product or to the fixed
burger of a menu: removal (free) or addition (with optional surcharge).

| Attribute | Type | NULL | Default | Constraint | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `order_item_id` | INT UNSIGNED | NO | — | FK -> `order_item(id)`, ON DELETE CASCADE | the order line being modified (product or menu) |
| `ingredient_id` | INT UNSIGNED | NO | — | FK -> `ingredient(id)`, ON DELETE RESTRICT | the ingredient being modified |
| `action` | ENUM('remove','add') | NO | — | — | `remove` = free removal; `add` = extra unit (may have surcharge) |
| `extra_price_cents` | INT UNSIGNED | NO | 0 | CHECK >= 0 | snapshot of `product_ingredient.extra_price_cents` at time of order (0 for removals) |

**Modifier attachment rule** (see modeling note 10):
- For a standalone product (`item_type='product'`): the modifier targets the product
  directly via `order_item_id`.
- For a menu (`item_type='menu'`): the modifier targets the menu line's fixed burger
  via the same `order_item_id`. The burger is identified by `menu.burger_product_id`,
  allowing the kitchen display to resolve which ingredients are modified without ambiguity.
  No additional FK is needed: given `order_item_id`, the burger is
  `order_item.menu_id -> menu.burger_product_id`.

**Stock impact**: each modifier affects ingredient stock at `paid` transition
(`remove` -> no decrement for that ingredient; `add` -> extra decrement).

---

### 3.14 `user`

Back-office user (admin, manager, kitchen staff, counter, drive). Kiosk customers
are not authenticated and have no row here.

| Attribute | Type | NULL | Default | Constraint | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `email` | VARCHAR(254) | NO | — | UNIQUE | max length per RFC 5321 |
| `password_hash` | VARCHAR(255) | NO | — | — | argon2id hash (see `PASSWORD_ALGO` in `.env`); typical length 96 chars, margin to 255 |
| `first_name` | VARCHAR(60) | NO | — | — | |
| `last_name` | VARCHAR(60) | NO | — | — | |
| `role_id` | INT UNSIGNED | NO | — | FK -> `role(id)`, ON DELETE RESTRICT | a user cannot exist without a role |
| `is_active` | TINYINT(1) | NO | 1 | — | deactivation without deletion |
| `last_login_at` | DATETIME | YES | NULL | — | useful for audit and dormant account detection |
| `pin_hash` | VARCHAR(255) | YES | NULL | — | argon2id hash of the per-staff PIN that authorises sensitive actions (price/RBAC/user/cancel/inventory). NULL = no PIN set. Security-by-design, see note 13 |
| `failed_login_attempts` | SMALLINT UNSIGNED | NO | 0 | — | consecutive failed logins; drives degressive throttling (note 13) |
| `last_failed_login_at` | DATETIME | YES | NULL | — | timestamp of the last failed login |
| `lockout_until` | DATETIME | YES | NULL | — | end of the current throttling window (degressive backoff, not a hard indefinite lock) |
| `password_reset_token_hash` | VARCHAR(255) | YES | NULL | — | hash of the reset token (not the raw token); NULL when no reset pending |
| `password_reset_expires_at` | DATETIME | YES | NULL | — | expiry of the reset token |
| `anonymized_at` | DATETIME | YES | NULL | — | RGPD tombstone marker: when set, PII columns are nulled/replaced (note 13). The row is kept for referential integrity |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | audit |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | — | audit |

**Volume**: 5-20 rows (restaurant team + 1-2 admins).

RFC 5321 email length: local-part <= 64, domain <= 255, total <= 254 (including `@`).
VARCHAR(254) is the spec-compliant value.

**PII columns**: `email`, `first_name`, `last_name`. Subject to RGPD anonymisation
(see note 13). `password_hash` and `pin_hash` are credentials, kept out of logs and
API responses.

---

### 3.15 `role`

Back-office roles (RBAC). Creatable / modifiable / deactivatable from admin UI.
Seed provides 5 roles; custom roles (e.g., "chef-patissier") can be added without deployment.

| Attribute | Type | NULL | Default | Constraint | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `code` | VARCHAR(40) | NO | — | UNIQUE | machine code, e.g., `admin`, `manager`, `kitchen`, `counter`, `drive` |
| `label` | VARCHAR(80) | NO | — | — | display name, e.g., `Administrator`, `Kitchen Staff` |
| `description` | TEXT | YES | NULL | — | |
| `default_route` | VARCHAR(120) | YES | NULL | — | landing screen for this role (e.g., `/admin/orders`, `/kitchen/display`). Makes routing dynamic — no hardcoded role names in front-end routing. |
| `order_source` | ENUM('kiosk','counter','drive') | YES | NULL | — | auto-tagged `source` when this role creates an order (NULL for admin/manager who can create on behalf of any channel) |
| `is_active` | TINYINT(1) | NO | 1 | — | deactivation preserves history of users who held this role |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | audit |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | — | audit |

**Seed roles**:
| Code | `default_route` | `order_source` |
|---|---|---|
| `admin` | `/admin/dashboard` | NULL |
| `manager` | `/admin/stats` | NULL |
| `kitchen` | `/kitchen/display` | NULL |
| `counter` | `/counter/orders` | `counter` |
| `drive` | `/drive/orders` | `drive` |

**RBAC architecture rule (P2)**: application code tests permissions, not role names.
Adding a new role with the right permissions requires no code change (permission-driven,
not role-name-driven — per Sandhu/NIST RBAC model).

---

### 3.16 `role_visible_source`

Defines which order sources are visible on the preparation dashboard for a given role.
Pure join table.

| Attribute | Type | NULL | Default | Constraint | Notes |
|---|---|---|---|---|---|
| `role_id` | INT UNSIGNED | NO | — | FK -> `role(id)`, ON DELETE CASCADE | |
| `source` | ENUM('kiosk','counter','drive') | NO | — | — | source visible to this role on the kitchen/counter/drive display |

**Primary key**: composite `(role_id, source)`.

**Seed data**:
| Role | Visible sources |
|---|---|
| `kitchen` | kiosk, counter, drive (all) |
| `counter` | kiosk, counter |
| `drive` | drive |

---

### 3.17 `permission`

Granular permissions assignable to roles. Catalogue is fixed at seed (no UI creation).

| Attribute | Type | NULL | Default | Constraint | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `code` | VARCHAR(60) | NO | — | UNIQUE | format `<resource>.<action>` |
| `label` | VARCHAR(120) | NO | — | — | display name |
| `description` | TEXT | YES | NULL | — | |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | audit |

**Fixed permission catalogue** (23 codes — frozen before DDL):

| Code | Granted to (seed default) |
|---|---|
| `product.create` | admin, manager |
| `product.read` | admin, manager, kitchen, counter, drive |
| `product.update` | admin, manager |
| `product.delete` | admin |
| `menu.create` | admin, manager |
| `menu.read` | admin, manager, kitchen, counter, drive |
| `menu.update` | admin, manager |
| `menu.delete` | admin |
| `category.manage` | admin, manager |
| `ingredient.manage` | admin, manager |
| `stock.read` | admin, manager, kitchen, counter, drive |
| `stock.count` | admin, manager, kitchen, counter, drive |
| `stock.manage` | admin, manager |
| `order.read` | admin, manager, kitchen, counter, drive |
| `order.create` | admin, counter, drive |
| `order.deliver` | admin, counter, drive |
| `order.cancel` | admin, counter, drive |
| `user.create` | admin |
| `user.read` | admin, manager |
| `user.update` | admin |
| `user.deactivate` | admin |
| `role.manage` | admin |
| `stats.read` | admin, manager |

**Volume**: 23 rows at seed.

---

### 3.18 `role_permission`

N-N mapping between roles and permissions. Pure join table.

| Attribute | Type | NULL | Default | Constraint | Notes |
|---|---|---|---|---|---|
| `role_id` | INT UNSIGNED | NO | — | FK -> `role(id)`, ON DELETE CASCADE | |
| `permission_id` | INT UNSIGNED | NO | — | FK -> `permission(id)`, ON DELETE CASCADE | |

**Primary key**: composite `(role_id, permission_id)`.

**Volume**: ~50-100 rows at seed (admin covers all; others cover a subset).

---

### 3.19 `stock_movement`

Append-only audit log of all stock changes per ingredient.
1 row per movement (sale, cancellation, restock, inventory correction).

| Attribute | Type | NULL | Default | Constraint | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `ingredient_id` | INT UNSIGNED | NO | — | FK -> `ingredient(id)`, ON DELETE RESTRICT | ingredient affected |
| `movement_type` | ENUM('sale','cancellation','restock','inventory_correction') | NO | — | INDEX | nature of the movement |
| `delta` | INT | NO | — | — | signed change: negative for consumption (sale), positive for restock/cancellation/correction |
| `order_id` | INT UNSIGNED | YES | NULL | FK -> `customer_order(id)`, ON DELETE SET NULL | linked order for `sale` and `cancellation` movements; NULL for restock/correction |
| `user_id` | INT UNSIGNED | YES | NULL | FK -> `user(id)`, ON DELETE SET NULL | user who triggered the movement (NULL for automated sale decrements) |
| `note` | VARCHAR(255) | YES | NULL | — | optional human note (e.g., reason for correction, pack reference) |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | INDEX | immutable timestamp |

**Immutability**: no UPDATE or DELETE on this table. Corrections are new rows with
`movement_type='inventory_correction'` and a signed delta.

**Automatic movements** (triggered at status transitions):
- `paid` transition: 1 `sale` row per ingredient unit consumed (accounting for modifiers).
- `cancelled` (from `paid`): 1 `cancellation` row per ingredient unit re-credited.

**Manual movements**:
- `restock`: manager or admin records a delivery (`+= N * pack_size`).
- `inventory_correction`: morning/evening physical count; system records the discrepancy
  (delta = actual - theoretical).

**Volume**: ~5-15 movements per order across all ingredients; index on
`(ingredient_id, created_at)` is recommended for per-ingredient history queries.

---

### 3.20 `audit_log`

Append-only log of **sensitive back-office actions**, for accountability where it matters
(insider threat, money handling, RBAC changes). Complements `stock_movement` (which is
stock-specific); covers catalogue/price, user, role/permission, and order cancellation events.
Security-by-design addition (see note 13).

| Attribute | Type | NULL | Default | Constraint | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `actor_user_id` | INT UNSIGNED | YES | NULL | FK -> `user(id)`, ON DELETE SET NULL | staff who performed the action, captured via PIN for sensitive operations. NULL if not attributable to an individual |
| `actor_role_id` | INT UNSIGNED | YES | NULL | FK -> `role(id)`, ON DELETE SET NULL | role context at action time (denormalised so the trail survives user anonymisation) |
| `action_code` | VARCHAR(60) | NO | — | INDEX | MCT operation / permission code, e.g. `product.update`, `order.cancel`, `role.manage`, `user.deactivate` |
| `entity_type` | VARCHAR(40) | YES | NULL | — | affected table name, e.g. `product`, `customer_order`, `role`, `user` |
| `entity_id` | INT UNSIGNED | YES | NULL | — | PK of the affected row |
| `summary` | VARCHAR(255) | YES | NULL | — | short non-personal description, e.g. "price_cents 880 -> 920", "added permission stock.manage" |
| `details` | JSON | YES | NULL | — | optional before/after diff. For user-targeted actions, stores changed **field names**, not PII values |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | INDEX | immutable timestamp |

**Immutability**: no UPDATE or DELETE at application layer (same discipline as `stock_movement`).
**Indexes**: `(actor_user_id, created_at)`, `(entity_type, entity_id)`, `(action_code, created_at)`.
**Retention**: own window (~12 months, legitimate-interest / fiscal traceability), decoupled
from user PII lifecycle (note 13). A scheduled purge (cron) removes rows past the window.

**Logged operations** (sensitive set): `UPDATE_PRODUCT` (8.2, incl. price), `DELETE_PRODUCT`
(8.3), `DELETE_MENU` (8.6), `CANCEL_ORDER` (7.1), `RESTOCK` (9.1), `INVENTORY_COUNT` (9.2),
`CREATE_USER` / `UPDATE_USER` / `DEACTIVATE_USER` (10.1-10.3), `MANAGE_RBAC` (10.4).

**Volume**: low (~10-50 sensitive actions/day) — orders of magnitude below `stock_movement`.

---

### 3.21 `login_throttle`

Per-source-IP brute-force throttle. Complements the per-account counter already on `user`
(`failed_login_attempts` / `lockout_until`), one row per source IP. Security-by-design addition
(see note 13).

| Attribute | Type | NULL | Default | Constraint | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `ip_address` | VARCHAR(45) | NO | — | UNIQUE | source IP, one row per IP, upserted; 45 chars holds a full IPv6 literal |
| `failed_attempts` | SMALLINT UNSIGNED | NO | 0 | — | consecutive failed logins from this IP in the current window |
| `window_started_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | start of the current counting window |
| `lockout_until` | DATETIME | YES | NULL | — | end of the degressive backoff window; NULL = not throttled |
| `last_attempt_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | timestamp of the last failed attempt |

**No FK**: an IP is not a modelled entity. Rows are appended/upserted by IP; the window resets
when expired. A daily cron purges rows with no active lockout whose `last_attempt_at` is older
than 24h.

---

## 4. Modeling notes

### Note 1 — Why `INT UNSIGNED` in cents for prices

Storing a price as `FLOAT` or `DECIMAL(10,2)` is technically valid but introduces two risks:

1. **FLOAT rounding**: `0.1 + 0.2 = 0.30000000000000004` in IEEE 754 floating-point.
   Summing 100 order lines can produce cent-level discrepancies vs business reality.
2. **FLOAT-to-string conversion**: different PHP/MariaDB driver versions may serialize floats
   with variable precision.

Storing as `INT UNSIGNED` (cents: 880 for EUR 8.80) eliminates these risks. Conversion to EUR
for display is done in PHP at output: `number_format($cents / 100, 2)`.

Reference: David Goldberg, *What Every Computer Scientist Should Know About Floating-Point
Arithmetic*, ACM Computing Surveys, 1991.

### Note 2 — Why `ENUM` rather than a reference table

ENUMs (`service_mode`, `status`, `item_type`, `action`, `slot_type`) could have been reference
tables. Choice retained: ENUM.

Advantages in this context:
- Values are stable and limited (3-7 values max), unlikely to evolve frequently.
- DBMS-level constraint instead of runtime FK; simpler queries.
- Directly readable in SQL: `WHERE status = 'paid'`.

Cost of a future change: `ALTER TABLE ... MODIFY COLUMN ... ENUM(...)` to add a value.
Acceptable given changes are expected to be rare.

If these ENUMs later require multilingual labels or descriptions, they will be migrated to
reference tables. Not in scope for this iteration.

### Note 3 — Why `customer_order` and not `order`

`ORDER` is an SQL reserved word (used in `ORDER BY`). Three approaches exist:
- Quote the name everywhere: `` `order` `` — requires quoting in every SQL statement,
  error-prone and non-portable across DBMS dialects.
- Use an alias at ORM level: possible but adds a mapping layer.
- Rename: `customer_order` (chosen) — unambiguous, self-documenting, no quoting required.

Alternative considered and rejected: `purchase` (less domain-specific),
`transaction` (also reserved or ambiguous). `customer_order` matches the domain language
and avoids all conflicts.

`order_item` is retained as the line table name: `item` is not reserved, and the
`order_` prefix makes the parent relationship clear.

### Note 4 — Order number prefix by channel

Format: `K`/`C`/`D`-YYYY-MM-DD-NNN (kiosk / counter / drive).

Rationale: the prefix encodes the channel, which is useful for rapid visual identification
by kitchen and counter staff without querying the `source` column. The sequential counter NNN
restarts daily per channel. Collision-free within a day given expected volume.

Alternative rejected: neutral prefix `W-` for all channels (simpler, but loses channel
readability for staff).

### Note 5 — `source` vs `service_mode` (channel vs consumption mode)

Two distinct dimensions, kept separate:

| | `source` | `service_mode` |
|---|---|---|
| Nature | input channel (who entered the order) | consumption mode (where the customer eats) |
| Values | kiosk, counter, drive | dine_in, takeaway, drive |
| Used for | authentication, analytics, permission filtering | KPI, capacity (no fiscal role) |

The two dimensions are independent for `kiosk` and `counter` (a kiosk customer can choose
`dine_in` or `takeaway`). `drive` is the only case where both dimensions align:
`source=drive` implies `service_mode=drive`. This cross-constraint is verified at app layer.

### Note 6 — Reduced 4-state machine

v0.1 had 6 states (`pending_payment`, `paid`, `preparing`, `ready`, `delivered`, `cancelled`).
v0.2 reduces to 4 states: `pending_payment -> paid -> delivered` (+ `cancelled`).

Rationale (Decision 4 from `revue-alignement-p1.md` §7): in a fast-food context, the kitchen
display (KDS) is a visual system — staff see the ticket and act. `preparing` and `ready` were
intermediate states that added complexity without proportional business value. The single
kitchen action is `deliver` (counter/drive staff hands over the order), collapsing
`preparing + ready + delivered` into one gesture. KPI is total time: `delivered_at - paid_at`
(SLA ~10 min). KDS color coding is computed from `now - paid_at`, no extra stored state.

**Dropped states and timestamps**: `preparing_at`, `ready_at` are not stored.

### Note 7 — Normal / Maxi format cascade

The Maxi format enlarges the side and the drink only. The burger is unchanged and the sauce
portion is unchanged (a sauce pot is the same in both formats). This scope is explicit so the
stock model stays faithful.

**Price side** — not modeled at individual component price level:
- `menu` carries two prices: `price_normal_cents` and `price_maxi_cents`.
- `order_item.format` records which format the customer chose (`normal` or `maxi`).
- `order_item.unit_price_cents_snapshot` captures the actual price paid (Normal or Maxi).
- No individual price per slot component is stored; the price differential is a menu-level
  attribute, consistent with how fast-food menus tend to be priced in practice.

**Stock side** — modeled via a format multiplier on the recipe:
- `product_ingredient` carries `quantity_normal` and `quantity_maxi`.
- At the `paid` transition, the decrement uses `quantity_maxi` when `order_item.format='maxi'`,
  otherwise `quantity_normal`.
- For burger and sauce ingredients, `quantity_maxi = quantity_normal` (format-invariant).
- For side and drink ingredients, `quantity_maxi > quantity_normal` (Maxi consumes more).
- The format cascades from the menu line (`order_item.format`) to its slot selections; a
  standalone product line defaults to `normal`.
- Single product per choice (e.g., one `Fries` product), not separate medium/large products.

Calibration: the Maxi surcharge is in the ~1.50 EUR range for this model (derived from
internal data; cross-checked against market magnitude for plausibility. Wakdo is a fictional
pastiche so exact prices are not copied from a real chain).

Calibration: the Maxi surcharge is in the ~1.50 EUR range for this model (derived from
internal data; cross-checked against market magnitude for plausibility. Wakdo is a fictional
pastiche so exact prices are not copied from a real chain).

### Note 8 — Image storage: path in VARCHAR vs BLOB in DB

`image_path` columns (`category`, `product`, `menu`) store a **relative path** from the
public root (e.g., `/uploads/products/classic-burger.jpg`), not an absolute server path.
PHP resolves via a prefix from `.env` (`UPLOAD_DIR=public/uploads`).

BLOB storage was considered and rejected:

| Criterion | `image_path` VARCHAR (chosen) | BLOB in DB |
|---|---|---|
| Kiosk performance | Apache serves files in ms (OS cache) | PHP reads DB + streams, multiplied latency |
| HTTP caching | ETag, Last-Modified, browser cache, CDN native | must be reimplemented in PHP |
| DB backup size | Megabytes (paths only) | Gigabytes (66 products x ~200 KB + responsive variants) |
| Image pipeline | `convert`, `webp`, optimization = standard filesystem tools | must be reinvented in PHP |

Sources: OWASP File Upload Cheat Sheet; MariaDB Knowledge Base — LONGBLOB performance;
Apache HTTP Server documentation — serving static content.

### Note 9 — VAT rule in French fast-food (fact-checked)

```
FACT-CHECK
Claim audited : "TVA 10% sur place / 5,5% a emporter" (dictionary v0.1 note 9)
Domain         : compliance (fiscal)
Verdict        : CLAIM INEXACT — superseded
Source         : BOFiP BOI-ANNX-000495 + BOI-TVA-LIQ-30-10-10 (official doctrine impots.gouv.fr)
Actual rule    : 10% for immediate consumption (dine-in OR hot takeaway);
                 5.5% for products in resealable containers (bottle, can) / deferred consumption
Confidence     : 95% (L1, official text)
```

**Model consequence**: VAT rate is an attribute of the `product` (`vat_rate` in per-mille:
100 = 10%, 55 = 5.5%), not of the order or the service mode. Default: 100 (10%).
The 5.5% rate applies to products in resealable containers (bottled water, juice bottles).
VAT is computed line by line; the rate is snapshotted on `order_item.vat_rate_snapshot`
at transaction time to preserve historical integrity if legislation changes.

`service_mode` is retained on `customer_order` for stats and KPI only (capacity planning,
per-mode revenue breakdown). It has no fiscal computation role.

### Note 10 — Ingredient configurator and modifier attachment

`order_item_modifier` attaches to an `order_item` row via `order_item_id`, regardless of
whether the line is a standalone product or a menu.

For a **standalone product** (`item_type='product'`): `order_item_id` directly identifies
the product being modified.

For a **menu** (`item_type='menu'`): the modifiable product is the fixed burger, identified
via `order_item.menu_id -> menu.burger_product_id`. The kitchen display resolves:
`modifier.order_item_id -> order_item -> menu -> menu.burger_product_id -> product.name`.
No additional FK column is needed on `order_item_modifier`. This keeps the modifier table
simple and avoids a nullable `target_product_id` column that would only be populated for
menu lines.

Constraint enforced at app layer: `order_item_modifier` rows for a menu line reference
only ingredients belonging to `menu.burger_product_id` via `product_ingredient`.

### Note 11 — `menu_slot` eligibility: category filter vs explicit product list

Two options were considered:
- **Category filter**: `menu_slot.category_id` points to a category; all products in that
  category are eligible. Simple, but a category may contain products not offered in this slot
  (e.g., a premium drink added to the "drinks" category should not automatically appear in
  all menu slots).
- **Explicit product list** `menu_slot_option(menu_slot_id, product_id)` (chosen): each
  eligible product is listed explicitly per slot. More verbose at seed time but precise —
  no accidental eligibility when the catalogue grows. Enables per-slot pricing overrides
  in the future without structural change.

The explicit list adds one entity (`menu_slot_option`, entity 3.5) but eliminates a class
of correctness bugs. Consistent with the prod-like ambition of this model.

### Note 12 — `commande_event` dropped

v0.1 carried a `commande_event` append-only audit table (event sourcing pattern).
Dropped in v0.2 (Decision 1, `revue-alignement-p1.md` §7).

Rationale: in a restaurant context, the back-office account is shared per workstation, not
individual. Per-person attribution of a state transition has no business value. The actual
need (phase durations, time-of-day stats) is covered by phase timestamps on `customer_order`
(`paid_at`, `delivered_at`, `cancelled_at`) without the complexity of an event store.

The 4-state machine combined with 3 phase timestamps provides all KPI data needed:
- Time-to-deliver: `delivered_at - paid_at`
- Cancellation rate and timing: `cancelled_at - created_at`
- Volume by hour: `HOUR(created_at)` / `service_day` computation

For stock audit, `stock_movement` (entity 3.19) provides the append-only audit trail
where it is genuinely needed (inventory reconciliation).

### Note 13 — Security-by-design data additions (2026-06-11)

These additions extend the prod-like model with a security-by-design layer. They do not
replace any v0.2 decision; they add accountability, auth lifecycle, and abuse resistance.

**Accountability — hybrid shared-account + PIN.** Back-office sessions stay shared per
workstation for the routine flow (a fast-food terminal is shared, `equipiers` rotate). A
per-staff PIN (`user.pin_hash`, argon2id) authorises a defined set of **sensitive actions**
(price/menu edits 8.2/8.3/8.6, order cancellation 7.1, inventory correction 9.2, user
management 10.1-10.3, RBAC 10.4). Those actions write the acting `user_id` into `audit_log`
(3.20). This resolves the circular justification that dropped `commande_event` in v0.1
(events were considered useless because accounts were shared): accountability is recorded
where it matters, at near-zero friction for the routine 95%. `customer_order.acting_user_id`
captures the staff for counter/drive orders taken under PIN; kiosk orders stay anonymous.

**Auth lifecycle.** `password_reset_token_hash` + `password_reset_expires_at` enable a reset
path (the token is stored hashed, the raw token is e-mailed once). Brute-force resistance uses
degressive throttling rather than a hard indefinite lock: `failed_login_attempts` +
`lockout_until` implement an exponential backoff per (account + source IP), so a fat-finger
streak does not lock out a whole kitchen mid-service (15 h continuous). Failed logins are
written to `audit_log`.

**RGPD anonymisation vs audit retention.** `user` PII (`email`, `first_name`, `last_name`)
is subject to the right to erasure (Cr 3.d). Erasure **anonymises** rather than hard-deletes:
the row is kept, `email` becomes a non-identifying unique placeholder (`anon-<id>@wakdo.invalid`,
RFC 2606 reserved domain), names are cleared, `password_hash`/`pin_hash` are invalidated, and
`anonymized_at` is set. The `audit_log` retains its own retention window (~12 months,
legitimate-interest / fiscal traceability) and keeps pointing at the anonymised principal, so
erasure and accountability coexist without breaking referential integrity.

**Abuse resistance on the anonymous kiosk.** `customer_order.idempotency_key` (client UUID,
UNIQUE) deduplicates a retried `POST /api/orders` so a network retry does not create a
duplicate paid order. Stock is decremented with a single atomic statement
(`UPDATE ingredient SET stock_quantity = stock_quantity - :units WHERE id = :id`): no operation
gates on a stock read, so the row self-locks for the duration of the write — no lost update and
no deadlock-ordering concern. This replaces the earlier pessimistic `SELECT ... FOR UPDATE`
approach (treatment-layer rule, see `mlt.md`); it adds no column here.

**Percentage stock model + computed availability.** `ingredient` carries `stock_capacity` (the
100% reference), `low_stock_pct` (warning band) and `critical_stock_pct` (auto-out-of-stock
floor) — see 3.6. `stock_quantity` is signed and may go negative (oversell magnitude surfaced to
managers); the system does not block an order on stock. Effective product orderability is
computed (rule RG-T21 in `mlt.md`): `product.is_available = 1` AND each non-removable
(`is_removable=0`) ingredient of its `product_ingredient` has
`stock_quantity > stock_capacity * critical_stock_pct/100`. At the critical band a product
auto-goes out-of-stock with no write and no cascade; a manual pull (`product.is_available=0`) is
a hard override; restock above the critical band makes the product orderable again on its own.

**Per-IP brute-force throttle.** `login_throttle` (3.21) tracks `failed_attempts` and
`lockout_until` per source IP (one upserted row per IP), complementing the per-account counter
on `user`. This adds a second throttling dimension so a single IP hammering many accounts is
slowed independently of any one account's counter. A daily cron purges idle, non-locked rows.

References: `docs/notes/revue-alignement-p1.md` §7 (D-decisions), security-by-design impact
map (2026-06-11). Threat model and data-classification matrix: `PROJECT_CONTEXT.md` §19 (to come).

---

## 5. Entity count summary

| # | Entity | Type | Replaces / new |
|---|---|---|---|
| 1 | `category` | business | v0.1 `categorie` (renamed + translated) |
| 2 | `product` | business | v0.1 `produit` (+ `vat_rate`) |
| 3 | `menu` | business | v0.1 `menu` (+ burger FK, 2 prices) |
| 4 | `menu_slot` | business | new — replaces `menu_produit` fixed composition |
| 5 | `menu_slot_option` | join | new — eligibility list per slot |
| 6 | `ingredient` | business | new — ingredient configurator + stock |
| 7 | `product_ingredient` | join | new — recipe + customization metadata |
| 8 | `allergen` | reference | new — INCO 1169/2011 |
| 9 | `ingredient_allergen` | join | new — maps allergens to ingredients |
| 10 | `customer_order` | business | v0.1 `commande` (renamed, 4-state machine, phase timestamps) |
| 11 | `order_item` | business | v0.1 `ligne_commande` (+ format, vat_rate_snapshot) |
| 12 | `order_item_selection` | business | new — customer menu slot choices |
| 13 | `order_item_modifier` | business | new — ingredient-level modifications |
| 14 | `user` | business | v0.1 `user` (translated field names) |
| 15 | `role` | business | v0.1 `role` (+ default_route, order_source) |
| 16 | `role_visible_source` | join | new — per-role dashboard filter |
| 17 | `permission` | reference | v0.1 `permission` (translated, catalogue frozen) |
| 18 | `role_permission` | join | v0.1 `role_permission` (unchanged) |
| 19 | `stock_movement` | audit | new — append-only stock audit log |
| 20 | `audit_log` | audit | new (security-by-design) — append-only sensitive-action log |
| 21 | `login_throttle` | security | new (security-by-design) - per-IP brute-force throttle |

**Dropped from v0.1**: `commande_event` (replaced by phase timestamps on `customer_order`),
`menu_produit` (replaced by `menu_slot` + `menu_slot_option` model).

**Total: 21 entities** (19 prod-like v0.2 + `audit_log` and `login_throttle` from the
security-by-design layer).

Security-by-design also adds columns (beyond the two new entities): `user` auth-lifecycle +
`pin_hash` + `anonymized_at` (3.14), `customer_order.acting_user_id` + `idempotency_key` (3.10),
and the percentage stock model on `ingredient` (3.6) — `stock_capacity`, `critical_stock_pct`,
plus the rename of `low_stock_threshold` to `low_stock_pct`. `login_throttle` (3.21) is the 21st
entity. See note 13.

---

*For the ER diagram and cardinality justifications, see [`mcd.md`](mcd.md) — the diagram is
the single source of truth for graphical representation.*
