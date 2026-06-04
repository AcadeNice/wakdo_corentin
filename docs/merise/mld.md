# Logical Data Model (MLD) — Wakdo

**Merise phase** : P1 - Conception, step 5 (after MCD, MCT, MLT)
**Version** : v0.2 — prod-like, 19 tables
**Date** : 2026-06-04
**Branch** : `feat/p1-conception`
**Status** : prod-like — all D1-D8 + stock decisions applied (see `docs/notes/revue-alignement-p1.md` §7)
**Author** : BYAN (methodology layer)

---

## 1. Purpose of this document

The MLD transcribes the MCD into a formal relational schema: 1 entity -> 1 table, each
association translated according to its cardinality, referential constraints materialised,
indexes sized for frequent access patterns.

This is the step that transforms conceptual modelling into an implementable specification.
The DDL SQL (`db/migrations/0001_init_schema.sql`) will be derived directly from this
document at P2.

**Sources**:
- `docs/merise/dictionary.md` (v0.2 — types and constraints per attribute, source of truth)
- `docs/merise/mcd.md` (v0.2 — entities + cardinalities + deferred decisions)
- `docs/notes/revue-alignement-p1.md` §7 (decision table D1-D8 + stock)

**Target platform**:
- MariaDB 11.4 LTS (cf. `docker-compose.yml` service `wakdo-db`)
- Engine InnoDB (ACID, FK support, row-level locking, CHECK from 10.2.1)
- Charset `utf8mb4`, collation `utf8mb4_unicode_ci`

---

## 2. Notation conventions

### Relational notation

```
table_name (col1, col2, #col_fk, [col_nullable])

  PK  : col1
  UK  : col2
  FK  : col_fk -> other_table(id) ON DELETE <rule>
  IDX : (col_a, col_b)
  CHK : <expression>
```

| Symbol | Meaning |
|---|---|
| `col` | NOT NULL column |
| `[col]` | Nullable column |
| `#col` | FK column |

Notation follows Merise French usage (Nanci/Espinasse convention adapted for ASCII).

### Type summary

All exact types are defined in `dictionary.md` section 2. Conventions retained:
- `INT UNSIGNED AUTO_INCREMENT` for all technical PKs
- `INT UNSIGNED` for all monetary amounts in cents (anti-FLOAT, see dictionary note 1)
- `SMALLINT UNSIGNED` for `vat_rate` per-mille values (55 or 100)
- `ENUM(...)` for stable business values (see dictionary note 2)
- `DATETIME` for timestamps (not TIMESTAMP, which implicitly converts to UTC in MariaDB)

---

## 3. MCD -> MLD translation rules applied

### 3.1 Entity -> Table

Each MCD entity becomes one table. The conceptual identifier `id` becomes PK
`INT UNSIGNED AUTO_INCREMENT`. Attributes retain their names and types.

### 3.2 `(1,1) - (1,N)` association -> simple FK

The entity on the `(1,1)` side carries the FK toward the `(0,N)` or `(1,N)` entity.

### 3.3 `(0,N) - (0,N)` or `(1,N) - (1,N)` association -> join table

The association becomes its own table with a composite PK of the two FKs. Applied to:
`product_ingredient`, `menu_slot_option`, `ingredient_allergen`,
`role_visible_source`, `role_permission`.

### 3.4 Associative entity with own attributes -> join table with columns

When an N-N association carries its own attributes, it becomes a table with those attributes
in addition to the composite FK PK. Applied to `product_ingredient`.

### 3.5 Polymorphism -> 2 nullable FKs + discriminator + CHECK

`order_item` references either `product` or `menu`. Translated as 2 nullable FK columns +
1 discriminator ENUM + 1 CHECK constraint enforcing mutual exclusivity.

---

## 4. Relational schema (19 tables)

Tables are ordered by dependency (no-FK tables first, then tables that depend on them).

---

### 4.1 `category`

```
category (id, name, slug, [image_path], display_order, is_active, created_at, updated_at)

  PK  : id
  UK  : name
  UK  : slug
```

| Column | Type | NULL | Notes |
|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | NO | PK |
| `name` | VARCHAR(60) | NO | Unique display name (see dict 3.1) |
| `slug` | VARCHAR(60) | NO | URL slug, e.g. `burgers` |
| `image_path` | VARCHAR(255) | YES | Relative path from public root |
| `display_order` | SMALLINT UNSIGNED NOT NULL DEFAULT 0 | NO | Kiosk display order |
| `is_active` | TINYINT(1) NOT NULL DEFAULT 1 | NO | Soft deactivation |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | NO | Audit |
| `updated_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | NO | Audit |

No FK. Root table for the Catalogue sub-domain.

---

### 4.2 `product`

```
product (id, #category_id, name, [description], price_cents, vat_rate,
         [image_path], is_available, display_order, created_at, updated_at)

  PK  : id
  FK  : category_id -> category(id) ON DELETE RESTRICT
  IDX : (category_id, is_available, display_order)
  CHK : price_cents > 0
  CHK : vat_rate IN (55, 100)
```

| Column | Type | NULL | Notes |
|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | NO | PK |
| `category_id` | INT UNSIGNED | NO | FK -> category |
| `name` | VARCHAR(120) | NO | Product label |
| `description` | TEXT | YES | Optional long description |
| `price_cents` | INT UNSIGNED | NO | A la carte price, incl. VAT, in cents |
| `vat_rate` | SMALLINT UNSIGNED | NO | Per-mille: 100 = 10%, 55 = 5.5% |
| `image_path` | VARCHAR(255) | YES | Relative path from public root |
| `is_available` | TINYINT(1) NOT NULL DEFAULT 1 | NO | Manual availability toggle |
| `display_order` | SMALLINT UNSIGNED NOT NULL DEFAULT 0 | NO | Within-category display order |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | NO | Audit |
| `updated_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | NO | Audit |

**ON DELETE RESTRICT** on `category_id`: a category with products cannot be deleted. Prevents
orphaned products.

---

### 4.3 `menu`

```
menu (id, #category_id, #burger_product_id, name, [description],
      price_normal_cents, price_maxi_cents, [image_path],
      is_available, display_order, created_at, updated_at)

  PK  : id
  FK  : category_id      -> category(id) ON DELETE RESTRICT
  FK  : burger_product_id -> product(id)  ON DELETE RESTRICT
  IDX : (category_id, is_available, display_order)
  CHK : price_normal_cents > 0
  CHK : price_maxi_cents > 0
```

| Column | Type | NULL | Notes |
|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | NO | PK |
| `category_id` | INT UNSIGNED | NO | FK -> category (typically the `menus` category) |
| `burger_product_id` | INT UNSIGNED | NO | FK -> product — the fixed burger that anchors this menu |
| `name` | VARCHAR(120) | NO | e.g. "Menu Le 280" |
| `description` | TEXT | YES | Optional |
| `price_normal_cents` | INT UNSIGNED | NO | Normal format price in cents |
| `price_maxi_cents` | INT UNSIGNED | NO | Maxi format price in cents (~+150 cents) |
| `image_path` | VARCHAR(255) | YES | Typically reuses the burger image |
| `is_available` | TINYINT(1) NOT NULL DEFAULT 1 | NO | Availability toggle |
| `display_order` | SMALLINT UNSIGNED NOT NULL DEFAULT 0 | NO | Display order |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | NO | Audit |
| `updated_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | NO | Audit |

**ON DELETE RESTRICT** on both FKs: prevents deletion of a category or burger product that
is still referenced by a menu definition.

---

### 4.4 `menu_slot`

```
menu_slot (id, #menu_id, name, slot_type, is_required, display_order)

  PK  : id
  FK  : menu_id -> menu(id) ON DELETE CASCADE
  IDX : (menu_id, display_order)
```

| Column | Type | NULL | Notes |
|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | NO | PK |
| `menu_id` | INT UNSIGNED | NO | FK -> menu |
| `name` | VARCHAR(80) | NO | e.g. "Drink", "Side", "Sauce" |
| `slot_type` | ENUM('drink','side','sauce','dessert','extra') | NO | Semantic role |
| `is_required` | TINYINT(1) NOT NULL DEFAULT 1 | NO | Whether the customer must fill this slot |
| `display_order` | SMALLINT UNSIGNED NOT NULL DEFAULT 0 | NO | Display order within menu builder |

**No audit fields**: a slot is part of menu definition; created and updated together with
the menu.

**ON DELETE CASCADE** on `menu_id`: if a menu is deleted, its slots are deleted with it.

---

### 4.5 `menu_slot_option`

Pure join table. Composite PK.

```
menu_slot_option (#menu_slot_id, #product_id)

  PK  : (menu_slot_id, product_id)
  FK  : menu_slot_id -> menu_slot(id) ON DELETE CASCADE
  FK  : product_id   -> product(id)   ON DELETE RESTRICT
```

| Column | Type | NULL | Notes |
|---|---|---|---|
| `menu_slot_id` | INT UNSIGNED | NO | FK -> menu_slot |
| `product_id` | INT UNSIGNED | NO | FK -> product |

**ON DELETE CASCADE** on `menu_slot_id`: if a slot is deleted, its eligibility list goes with it.
**ON DELETE RESTRICT** on `product_id`: a product listed as eligible in a slot cannot be
deleted without first removing it from the slot options. Prevents silent breakage of menus.

No timestamps. Pure join table.

---

### 4.6 `ingredient`

```
ingredient (id, name, unit, stock_quantity, pack_size, [pack_label],
            low_stock_threshold, is_active, created_at, updated_at)

  PK  : id
  UK  : name
  CHK : stock_quantity >= 0
  CHK : pack_size > 0
  CHK : low_stock_threshold >= 0
```

| Column | Type | NULL | Notes |
|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | NO | PK |
| `name` | VARCHAR(120) | NO | Unique name, e.g. "Sesame Bun" |
| `unit` | VARCHAR(40) | NO | Packaging unit label (free-form, not ENUM) |
| `stock_quantity` | INT NOT NULL DEFAULT 0 | NO | Current stock. Signed INT to detect negative (alert) |
| `pack_size` | SMALLINT UNSIGNED NOT NULL DEFAULT 1 | NO | Units per restocking pack |
| `pack_label` | VARCHAR(80) | YES | Human label of the pack |
| `low_stock_threshold` | SMALLINT UNSIGNED NOT NULL DEFAULT 0 | NO | Alert threshold |
| `is_active` | TINYINT(1) NOT NULL DEFAULT 1 | NO | Deactivate obsolete ingredients |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | NO | Audit |
| `updated_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | NO | Audit |

No FK. Root table for the Ingredients & Stock sub-domain.

---

### 4.7 `product_ingredient`

Associative table carrying recipe and customisation metadata. Composite PK.

```
product_ingredient (#product_id, #ingredient_id, quantity_normal, quantity_maxi,
                    is_removable, is_addable, extra_price_cents)

  PK  : (product_id, ingredient_id)
  FK  : product_id    -> product(id)    ON DELETE CASCADE
  FK  : ingredient_id -> ingredient(id) ON DELETE RESTRICT
  CHK : quantity_normal > 0
  CHK : quantity_maxi >= quantity_normal
  CHK : extra_price_cents >= 0
```

| Column | Type | NULL | Notes |
|---|---|---|---|
| `product_id` | INT UNSIGNED | NO | FK -> product |
| `ingredient_id` | INT UNSIGNED | NO | FK -> ingredient |
| `quantity_normal` | SMALLINT UNSIGNED NOT NULL DEFAULT 1 | NO | Units consumed in Normal format |
| `quantity_maxi` | SMALLINT UNSIGNED NOT NULL DEFAULT 1 | NO | Units consumed in Maxi format; equals `quantity_normal` for burger/sauce (format-invariant), higher for side/drink |
| `is_removable` | TINYINT(1) NOT NULL DEFAULT 1 | NO | Customer may remove at no cost |
| `is_addable` | TINYINT(1) NOT NULL DEFAULT 0 | NO | Customer may add an extra unit |
| `extra_price_cents` | INT UNSIGNED NOT NULL DEFAULT 0 | NO | Surcharge if `is_addable=1` and customer adds it |

**ON DELETE CASCADE** on `product_id`: if a product is deleted, its recipe rows are deleted.
**ON DELETE RESTRICT** on `ingredient_id`: cannot delete an ingredient still referenced in a
recipe. Admin must remove the product-ingredient link first.

No timestamps. Join table with attributes.

---

### 4.8 `allergen`

```
allergen (id, code, name, [description])

  PK  : id
  UK  : code
```

| Column | Type | NULL | Notes |
|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | NO | PK |
| `code` | VARCHAR(30) | NO | Machine code, e.g. `gluten`, `milk` |
| `name` | VARCHAR(80) | NO | Display name |
| `description` | TEXT | YES | Optional guidance |

No FK. Reference table; 14 rows at seed (INCO Regulation (EU) 1169/2011).
No `updated_at`: allergen catalogue is considered stable (additions require a migration, not a UI action).

---

### 4.9 `ingredient_allergen`

Pure join table. Composite PK.

```
ingredient_allergen (#ingredient_id, #allergen_id)

  PK  : (ingredient_id, allergen_id)
  FK  : ingredient_id -> ingredient(id) ON DELETE CASCADE
  FK  : allergen_id   -> allergen(id)   ON DELETE RESTRICT
```

| Column | Type | NULL | Notes |
|---|---|---|---|
| `ingredient_id` | INT UNSIGNED | NO | FK -> ingredient |
| `allergen_id` | INT UNSIGNED | NO | FK -> allergen |

**ON DELETE CASCADE** on `ingredient_id`: if an ingredient is deleted, its allergen links go with it.
**ON DELETE RESTRICT** on `allergen_id`: an allergen in the regulated catalogue cannot be deleted.

No timestamps. Pure join table.

---

### 4.10 `role`

Placed before `user` because `user` depends on `role`.

```
role (id, code, label, [description], [default_route], [order_source],
      is_active, created_at, updated_at)

  PK  : id
  UK  : code
```

| Column | Type | NULL | Notes |
|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | NO | PK |
| `code` | VARCHAR(40) | NO | Machine code: `admin`, `manager`, `kitchen`, `counter`, `drive` |
| `label` | VARCHAR(80) | NO | Display name |
| `description` | TEXT | YES | Optional |
| `default_route` | VARCHAR(120) | YES | Landing screen, e.g. `/admin/dashboard` |
| `order_source` | ENUM('kiosk','counter','drive') | YES | Auto-tagged source when this role creates an order; NULL for admin/manager |
| `is_active` | TINYINT(1) NOT NULL DEFAULT 1 | NO | Deactivation preserves history |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | NO | Audit |
| `updated_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | NO | Audit |

No FK. Root table for RBAC.

---

### 4.11 `user`

```
user (id, email, password_hash, first_name, last_name, #role_id,
      is_active, [last_login_at], created_at, updated_at)

  PK  : id
  UK  : email
  FK  : role_id -> role(id) ON DELETE RESTRICT
  IDX : (is_active, role_id)
```

| Column | Type | NULL | Notes |
|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | NO | PK |
| `email` | VARCHAR(254) | NO | RFC 5321 max length |
| `password_hash` | VARCHAR(255) | NO | argon2id hash |
| `first_name` | VARCHAR(60) | NO | |
| `last_name` | VARCHAR(60) | NO | |
| `role_id` | INT UNSIGNED | NO | FK -> role |
| `is_active` | TINYINT(1) NOT NULL DEFAULT 1 | NO | Deactivation without deletion |
| `last_login_at` | DATETIME | YES | Audit, dormant account detection |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | NO | Audit |
| `updated_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | NO | Audit |

**ON DELETE RESTRICT** on `role_id`: a role cannot be deleted while users hold it.
Deactivate the role first (`is_active = 0`), then reassign users before deleting.

---

### 4.12 `role_visible_source`

Pure join table. Composite PK.

```
role_visible_source (#role_id, source)

  PK  : (role_id, source)
  FK  : role_id -> role(id) ON DELETE CASCADE
```

| Column | Type | NULL | Notes |
|---|---|---|---|
| `role_id` | INT UNSIGNED | NO | FK -> role |
| `source` | ENUM('kiosk','counter','drive') | NO | Order source visible on dashboard |

**ON DELETE CASCADE** on `role_id`: if a role is deleted, its dashboard source filters go with it.

No timestamps. Pure join table.

Seed data:
- `kitchen`: kiosk, counter, drive
- `counter`: kiosk, counter
- `drive`: drive
- `admin`, `manager`: no rows (global view, no source filter)

---

### 4.13 `permission`

```
permission (id, code, label, [description], created_at)

  PK  : id
  UK  : code
```

| Column | Type | NULL | Notes |
|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | NO | PK |
| `code` | VARCHAR(60) | NO | Format `<resource>.<action>` |
| `label` | VARCHAR(120) | NO | Display name |
| `description` | TEXT | YES | Optional |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | NO | Audit |

No `updated_at`: permissions are declared in migration and not modified via UI.
Catalogue is frozen at 23 codes (see dictionary section 3.17).

---

### 4.14 `role_permission`

Pure join table. Composite PK.

```
role_permission (#role_id, #permission_id)

  PK  : (role_id, permission_id)
  FK  : role_id       -> role(id)       ON DELETE CASCADE
  FK  : permission_id -> permission(id) ON DELETE CASCADE
  IDX : permission_id
```

| Column | Type | NULL | Notes |
|---|---|---|---|
| `role_id` | INT UNSIGNED | NO | FK -> role |
| `permission_id` | INT UNSIGNED | NO | FK -> permission |

**ON DELETE CASCADE** on both FKs: deleting a role or a permission removes its mappings.
The secondary index on `permission_id` supports the reverse query "which roles have this
permission?" without scanning the full table.

No timestamps. Pure join table.

---

### 4.15 `customer_order`

```
customer_order (id, order_number, source, service_mode, status,
                total_ht_cents, total_vat_cents, total_ttc_cents,
                [paid_at], [delivered_at], [cancelled_at],
                created_at, updated_at)

  PK  : id
  UK  : order_number
  IDX : (status, created_at)
  IDX : (source, created_at)
  IDX : created_at
  CHK : total_ht_cents >= 0
  CHK : total_vat_cents >= 0
  CHK : total_ttc_cents > 0
  CHK : total_ttc_cents = total_ht_cents + total_vat_cents
  CHK : source != 'drive' OR service_mode = 'drive'
```

| Column | Type | NULL | Notes |
|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | NO | PK |
| `order_number` | VARCHAR(20) | NO | Format `K/C/D-YYYY-MM-DD-NNN` by channel |
| `source` | ENUM('kiosk','counter','drive') | NO | Input channel |
| `service_mode` | ENUM('dine_in','takeaway','drive') | NO | Consumption mode (stats only, no fiscal role) |
| `status` | ENUM('pending_payment','paid','delivered','cancelled') NOT NULL DEFAULT 'pending_payment' | NO | 4-state machine |
| `total_ht_cents` | INT UNSIGNED | NO | Ex-VAT total snapshot |
| `total_vat_cents` | INT UNSIGNED | NO | VAT amount snapshot |
| `total_ttc_cents` | INT UNSIGNED | NO | Incl.-VAT total; must equal HT + VAT |
| `paid_at` | DATETIME | YES | Timestamp of transition to `paid` |
| `delivered_at` | DATETIME | YES | Timestamp of transition to `delivered` |
| `cancelled_at` | DATETIME | YES | Timestamp of cancellation |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | NO | Used as `service_day` base |
| `updated_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | NO | Audit |

No FK toward `user`: staff attribution is not stored on the order. Operational accountability
is covered by `stock_movement.user_id` for stock actions.

**4-state machine**: `pending_payment -> paid -> delivered` (+ `cancelled`). States `preparing`
and `ready` are dropped (decision D4). KPI: `delivered_at - paid_at` (target SLA ~10 min).

**`service_day` computation** (used in stats queries — NOT a stored column):
```sql
CASE WHEN HOUR(created_at) < 10
     THEN DATE(created_at) - INTERVAL 1 DAY
     ELSE DATE(created_at)
END
```
Cutoff: 10:00. The generated-column formula with `INTERVAL 4 HOUR 30 MINUTE` from v0.1 was
incorrect and is dropped (decision D6).

**VAT calculation**: totals on `customer_order` are the sum of line-level calculations.
Line-level VAT: `unit_price_cents_snapshot * quantity` is the TTC amount per line;
HT = `ROUND(ttc_cents * 100 / (100 + vat_rate_per_cent))` where `vat_rate_per_cent`
is `vat_rate_snapshot / 10`. Computed at application layer at cart validation.

**`source = 'drive' => service_mode = 'drive'`**: the CHECK enforces this at DB level.

---

### 4.16 `order_item`

```
order_item (id, #order_id, item_type, [#product_id], [#menu_id], format,
            label_snapshot, unit_price_cents_snapshot, vat_rate_snapshot,
            quantity, created_at)

  PK  : id
  FK  : order_id   -> customer_order(id) ON DELETE CASCADE
  FK  : product_id -> product(id)        ON DELETE RESTRICT
  FK  : menu_id    -> menu(id)           ON DELETE RESTRICT
  IDX : order_id
  CHK : unit_price_cents_snapshot > 0
  CHK : vat_rate_snapshot IN (55, 100)
  CHK : quantity > 0
  CHK : (item_type = 'product' AND product_id IS NOT NULL AND menu_id IS NULL)
        OR (item_type = 'menu' AND menu_id IS NOT NULL AND product_id IS NULL)
```

| Column | Type | NULL | Notes |
|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | NO | PK |
| `order_id` | INT UNSIGNED | NO | FK -> customer_order |
| `item_type` | ENUM('product','menu') | NO | Discriminator |
| `product_id` | INT UNSIGNED | YES | Non-null if `item_type = 'product'`, NULL otherwise |
| `menu_id` | INT UNSIGNED | YES | Non-null if `item_type = 'menu'`, NULL otherwise |
| `format` | ENUM('normal','maxi') NOT NULL DEFAULT 'normal' | NO | Menu format. For standalone products, value is `normal` |
| `label_snapshot` | VARCHAR(120) | NO | Label at time of order |
| `unit_price_cents_snapshot` | INT UNSIGNED | NO | Unit price incl. VAT at time of order |
| `vat_rate_snapshot` | SMALLINT UNSIGNED | NO | VAT rate per-mille at time of order |
| `quantity` | SMALLINT UNSIGNED NOT NULL DEFAULT 1 | NO | Quantity (e.g. 3 drinks = 1 line, quantity=3) |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | NO | Audit |

**ON DELETE CASCADE** on `order_id`: lines are deleted with the order.
**ON DELETE RESTRICT** on `product_id` and `menu_id`: a product or menu referenced in an
historical order line cannot be deleted. The snapshot makes the FK reference non-critical
for display, but RESTRICT avoids silent orphaning of the relational structure.

**Polymorphism exclusivity CHECK**: MariaDB 10.2+ enforces this at INSERT/UPDATE time.

---

### 4.17 `order_item_selection`

Customer's choice for one slot of a menu order line.

```
order_item_selection (id, #order_item_id, #menu_slot_id, #product_id, label_snapshot)

  PK  : id
  FK  : order_item_id -> order_item(id) ON DELETE CASCADE
  FK  : menu_slot_id  -> menu_slot(id)  ON DELETE RESTRICT
  FK  : product_id    -> product(id)    ON DELETE RESTRICT
  IDX : order_item_id
```

| Column | Type | NULL | Notes |
|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | NO | PK |
| `order_item_id` | INT UNSIGNED | NO | FK -> order_item (must be a menu-type line) |
| `menu_slot_id` | INT UNSIGNED | NO | FK -> menu_slot (which slot was filled) |
| `product_id` | INT UNSIGNED | NO | FK -> product (chosen by customer) |
| `label_snapshot` | VARCHAR(120) | NO | Product label at time of order |

**ON DELETE CASCADE** on `order_item_id`: if the parent order line is deleted, its slot
selections go with it.
**ON DELETE RESTRICT** on `menu_slot_id` and `product_id`: historical slot choice records
must not be silently broken by catalogue changes.

Note: the business constraint that `order_item_id` references a line with `item_type='menu'`
is enforced at application layer (not in MariaDB without a trigger or deferred constraint).

---

### 4.18 `order_item_modifier`

Ingredient-level modification applied by the customer to a product or the fixed burger of a menu.

```
order_item_modifier (id, #order_item_id, #ingredient_id, action, extra_price_cents)

  PK  : id
  FK  : order_item_id -> order_item(id)   ON DELETE CASCADE
  FK  : ingredient_id -> ingredient(id)   ON DELETE RESTRICT
  IDX : order_item_id
  CHK : extra_price_cents >= 0
```

| Column | Type | NULL | Notes |
|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | NO | PK |
| `order_item_id` | INT UNSIGNED | NO | FK -> order_item |
| `ingredient_id` | INT UNSIGNED | NO | FK -> ingredient |
| `action` | ENUM('remove','add') | NO | `remove` = free removal; `add` = extra unit |
| `extra_price_cents` | INT UNSIGNED NOT NULL DEFAULT 0 | NO | Snapshot of surcharge at time of order (0 for removals) |

**ON DELETE CASCADE** on `order_item_id`: if the order line is deleted, its modifiers go with it.
**ON DELETE RESTRICT** on `ingredient_id`: an ingredient referenced in a historical modifier
cannot be deleted.

**Modifier attachment for menu lines**: the modifiable product is the fixed burger, resolved
via `order_item.menu_id -> menu.burger_product_id`. No additional FK column is needed on
this table (see dictionary note 10).

---

### 4.19 `stock_movement`

Append-only audit log of all stock changes per ingredient.

```
stock_movement (id, #ingredient_id, movement_type, delta,
                [#order_id], [#user_id], [note], created_at)

  PK  : id
  FK  : ingredient_id -> ingredient(id)      ON DELETE RESTRICT
  FK  : order_id      -> customer_order(id)  ON DELETE SET NULL
  FK  : user_id       -> user(id)            ON DELETE SET NULL
  IDX : (ingredient_id, created_at)
  IDX : (movement_type, created_at)
```

| Column | Type | NULL | Notes |
|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | NO | PK |
| `ingredient_id` | INT UNSIGNED | NO | FK -> ingredient |
| `movement_type` | ENUM('sale','cancellation','restock','inventory_correction') | NO | Nature of movement |
| `delta` | INT | NO | Signed change: negative for consumption, positive for restock/cancellation/correction |
| `order_id` | INT UNSIGNED | YES | FK -> customer_order; non-null for `sale` and `cancellation` |
| `user_id` | INT UNSIGNED | YES | FK -> user; null for automated sale decrements |
| `note` | VARCHAR(255) | YES | Optional human note |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | NO | Immutable timestamp |

**ON DELETE RESTRICT** on `ingredient_id`: an ingredient with a movement history cannot be
deleted. Admin must archive the ingredient (`is_active = 0`) instead.
**ON DELETE SET NULL** on `order_id`: if an order is purged from the system, its movement
records remain with `order_id = NULL`. The audit log is preserved; only the order link is lost.
**ON DELETE SET NULL** on `user_id`: if a user is deleted, movement records remain with
`user_id = NULL`. Audit is preserved; individual attribution is lost.

**Immutability rule**: no UPDATE or DELETE at application layer. Corrections are new rows
with `movement_type = 'inventory_correction'` and a signed `delta`.

No `updated_at`. Immutable append-only table.

---

## 5. Referential integrity summary

| FK column | References | ON DELETE | Rationale |
|---|---|---|---|
| `product.category_id` | `category(id)` | RESTRICT | No orphaned product |
| `menu.category_id` | `category(id)` | RESTRICT | Same |
| `menu.burger_product_id` | `product(id)` | RESTRICT | Menu definition requires its anchor burger |
| `menu_slot.menu_id` | `menu(id)` | CASCADE | Slots have no meaning without their menu |
| `menu_slot_option.menu_slot_id` | `menu_slot(id)` | CASCADE | Eligibility list disappears with the slot |
| `menu_slot_option.product_id` | `product(id)` | RESTRICT | Removing a product must not silently break menus |
| `product_ingredient.product_id` | `product(id)` | CASCADE | Recipe disappears with the product |
| `product_ingredient.ingredient_id` | `ingredient(id)` | RESTRICT | Cannot remove ingredient still in a recipe |
| `ingredient_allergen.ingredient_id` | `ingredient(id)` | CASCADE | Allergen links disappear with the ingredient |
| `ingredient_allergen.allergen_id` | `allergen(id)` | RESTRICT | Regulated allergen catalogue is immutable |
| `user.role_id` | `role(id)` | RESTRICT | A user cannot exist without a role |
| `role_visible_source.role_id` | `role(id)` | CASCADE | Dashboard filters disappear with the role |
| `role_permission.role_id` | `role(id)` | CASCADE | Permission mappings disappear with the role |
| `role_permission.permission_id` | `permission(id)` | CASCADE | Permission mappings disappear with the permission |
| `order_item.order_id` | `customer_order(id)` | CASCADE | Lines disappear with the order |
| `order_item.product_id` | `product(id)` | RESTRICT | Historical reference must not be silently orphaned |
| `order_item.menu_id` | `menu(id)` | RESTRICT | Same |
| `order_item_selection.order_item_id` | `order_item(id)` | CASCADE | Slot choices disappear with the line |
| `order_item_selection.menu_slot_id` | `menu_slot(id)` | RESTRICT | Historical slot record preserved |
| `order_item_selection.product_id` | `product(id)` | RESTRICT | Historical choice record preserved |
| `order_item_modifier.order_item_id` | `order_item(id)` | CASCADE | Modifiers disappear with the line |
| `order_item_modifier.ingredient_id` | `ingredient(id)` | RESTRICT | Historical modifier record preserved |
| `stock_movement.ingredient_id` | `ingredient(id)` | RESTRICT | Ingredient with history cannot be deleted |
| `stock_movement.order_id` | `customer_order(id)` | SET NULL | Audit preserved, order link lost |
| `stock_movement.user_id` | `user(id)` | SET NULL | Audit preserved, user attribution lost |

**Key used**: CASCADE = child has no meaning without parent; RESTRICT = parent deletion
blocked while children exist; SET NULL = child is preserved, only the link is severed.

---

## 6. CHECK constraints summary

| Table | CHECK expression | Purpose |
|---|---|---|
| `product` | `price_cents > 0` | Zero or negative price is a bug |
| `product` | `vat_rate IN (55, 100)` | Only two legal VAT rates for this model |
| `menu` | `price_normal_cents > 0` | Same as product |
| `menu` | `price_maxi_cents > 0` | Same |
| `ingredient` | `stock_quantity >= 0` | Negative stock is an alert, not a valid state |
| `ingredient` | `pack_size > 0` | Pack size of zero makes restock logic incoherent |
| `ingredient` | `low_stock_threshold >= 0` | Threshold cannot be negative |
| `product_ingredient` | `quantity_normal > 0` | Recipe quantity of zero is meaningless |
| `product_ingredient` | `quantity_maxi >= quantity_normal` | Maxi consumes at least as much as Normal (side/drink more, burger/sauce equal) |
| `product_ingredient` | `extra_price_cents >= 0` | No negative surcharge |
| `customer_order` | `total_ht_cents >= 0` | Zero is allowed (edge case during cart building) |
| `customer_order` | `total_vat_cents >= 0` | Same |
| `customer_order` | `total_ttc_cents > 0` | A validated order must have a positive total |
| `customer_order` | `total_ttc_cents = total_ht_cents + total_vat_cents` | Arithmetic invariant; defence-in-depth vs application bugs |
| `customer_order` | `source != 'drive' OR service_mode = 'drive'` | Cross-dimension constraint (dict. note 5) |
| `order_item` | `unit_price_cents_snapshot > 0` | Non-zero price at transaction time |
| `order_item` | `vat_rate_snapshot IN (55, 100)` | Snapshot must match allowed rates |
| `order_item` | `quantity > 0` | Non-zero quantity |
| `order_item` | `(item_type='product' AND product_id IS NOT NULL AND menu_id IS NULL) OR (item_type='menu' AND menu_id IS NOT NULL AND product_id IS NULL)` | Polymorphism: exactly one FK populated per discriminator value |
| `order_item_modifier` | `extra_price_cents >= 0` | Snapshot of surcharge; cannot be negative |

---

## 7. Recommended indexes (beyond PK / UK / FK auto-indexes)

MariaDB InnoDB creates an index automatically for each FK declaration (if no usable index
exists). The following additional indexes target frequent query patterns identified in the
MCT / MLT.

| Table | Index columns | Query pattern |
|---|---|---|
| `product` | `(category_id, is_available, display_order)` | Kiosk catalogue load: filter by category + availability, sort by order |
| `menu` | `(category_id, is_available, display_order)` | Same pattern for menus |
| `menu_slot` | `(menu_id, display_order)` | Menu builder: load all slots of a menu in order |
| `customer_order` | `(status, created_at)` | Active orders queue: pending/paid orders sorted by time |
| `customer_order` | `(source, created_at)` | Per-channel analytics and order filtering |
| `customer_order` | `created_at` | Time-range aggregations (hourly stats, `service_day`) |
| `order_item` | `order_id` | Retrieve all lines of an order |
| `order_item_selection` | `order_item_id` | Retrieve slot choices for a menu line |
| `order_item_modifier` | `order_item_id` | Retrieve ingredient modifications for a line |
| `stock_movement` | `(ingredient_id, created_at)` | Per-ingredient stock history (dict. section 3.19) |
| `stock_movement` | `(movement_type, created_at)` | Stats: cancellations per week, restocks per month |
| `role_permission` | `permission_id` | Reverse query: "which roles have this permission?" |
| `user` | `(is_active, role_id)` | Login check + permission resolution |

**Indexes not added** (intentional):
- `customer_order.order_number`: UK index is sufficient; no range query expected on this column.
- `customer_order.service_mode`: low cardinality (3 values); full scan on the status index
  with a `service_mode` filter is acceptable at expected volume.
- `customer_order.paid_at`: NULL for most in-flight rows; sparse index provides limited benefit.

---

## 8. Cross-validation MLD <-> MCD

Verification that all 19 MCD entities map to a table, and that all tables trace to the MCD.

| MCD entity | MLD table | Mapping type | Notes |
|---|---|---|---|
| `category` (C1) | `category` (4.1) | 1:1 entity | |
| `product` (C2) | `product` (4.2) | 1:1 entity | |
| `menu` (C3) | `menu` (4.3) | 1:1 entity | New: `burger_product_id`, `price_normal_cents`, `price_maxi_cents` |
| `menu_slot` (C4) | `menu_slot` (4.4) | 1:1 entity | New entity (v0.2) |
| `menu_slot_option` (C5) | `menu_slot_option` (4.5) | Join table (composite PK) | New entity (v0.2) |
| `ingredient` (C6) | `ingredient` (4.6) | 1:1 entity | New entity (v0.2) |
| `product_ingredient` (C7) | `product_ingredient` (4.7) | Join table with attributes | New entity (v0.2) |
| `allergen` (C8) | `allergen` (4.8) | 1:1 entity | New entity (v0.2) |
| `ingredient_allergen` (C9) | `ingredient_allergen` (4.9) | Join table (composite PK) | New entity (v0.2) |
| `role` (C10) | `role` (4.10) | 1:1 entity | New: `default_route`, `order_source` |
| `user` (C11) | `user` (4.11) | 1:1 entity | Columns renamed to English |
| `role_visible_source` (C12) | `role_visible_source` (4.12) | Join table (composite PK) | New entity (v0.2) |
| `permission` (C13) | `permission` (4.13) | 1:1 entity | |
| `role_permission` (C14) | `role_permission` (4.14) | Join table (composite PK) | |
| `customer_order` (C15) | `customer_order` (4.15) | 1:1 entity | Renamed from `commande`; 4-state machine; phase timestamps |
| `order_item` (C16) | `order_item` (4.16) | 1:1 entity | New: `format`, `vat_rate_snapshot`; polymorphism CHECK |
| `order_item_selection` (C17) | `order_item_selection` (4.17) | 1:1 entity | New entity (v0.2) |
| `order_item_modifier` (C18) | `order_item_modifier` (4.18) | 1:1 entity | New entity (v0.2) |
| `stock_movement` (C19) | `stock_movement` (4.19) | 1:1 entity | New entity (v0.2) |

**Result**: 19/19 entities mapped. No entity without a table; no table outside the MCD.

**Dropped from v0.1**: `commande_event` (replaced by `paid_at`, `delivered_at`, `cancelled_at`
phase timestamps on `customer_order` — decision 2.A); `menu_produit` fixed-composition model
(replaced by `menu_slot` + `menu_slot_option` — decision D1).

---

## 9. Volume estimation (6 months)

| Table | Rows at 6 months | Avg row size | Est. size |
|---|---|---|---|
| `category` | ~10 | 200 bytes | < 1 KB |
| `product` | ~55 | 400 bytes | ~22 KB |
| `menu` | ~13 | 450 bytes | ~6 KB |
| `menu_slot` | ~40 | 150 bytes | ~6 KB |
| `menu_slot_option` | ~150 | 30 bytes | ~5 KB |
| `ingredient` | ~100 | 300 bytes | ~30 KB |
| `product_ingredient` | ~400 | 40 bytes | ~16 KB |
| `allergen` | 14 | 200 bytes | ~3 KB |
| `ingredient_allergen` | ~200 | 20 bytes | ~4 KB |
| `role` | ~5 | 200 bytes | ~1 KB |
| `user` | ~20 | 500 bytes | ~10 KB |
| `role_visible_source` | ~7 | 15 bytes | < 1 KB |
| `permission` | 23 | 250 bytes | ~6 KB |
| `role_permission` | ~80 | 15 bytes | ~2 KB |
| `customer_order` | ~30k | 300 bytes | ~9 MB |
| `order_item` | ~150k | 250 bytes | ~37 MB |
| `order_item_selection` | ~300k | 150 bytes | ~45 MB |
| `order_item_modifier` | ~150k | 80 bytes | ~12 MB |
| `stock_movement` | ~500k | 180 bytes | ~90 MB |

**Estimated total**: ~190 MB data + ~60-80 MB for indexes = ~250-270 MB over 6 months.
Manageable on the MariaDB container (`wakdo_db_data` named volume in `docker-compose.yml`).

`stock_movement` is the highest-volume table (~5-15 rows per order across all ingredients).
The `(ingredient_id, created_at)` index is the primary query path for per-ingredient
history; it will carry meaningful write amplification at scale.

---

## 10. Decisions deferred to DDL and P2

1. **MariaDB generated column** for `service_day`: a `VIRTUAL GENERATED` column is technically
   possible in MariaDB 5.7+ syntax. If stats queries prove burdensome without a materialised
   column, a `STORED GENERATED` column could be added as a migration. For this model, the
   applicative CASE expression is retained (simpler, avoids generated-column edge cases).
2. **Partitioning**: `stock_movement` could be partitioned by month if volume exceeds
   estimates. Not in scope for the initial DDL.
3. **Triggers**: stock decrement on `paid` transition and re-credit on `cancelled` (from `paid`)
   could be implemented as MariaDB triggers or as application-layer logic. To be decided at P2.
4. **Collation**: `utf8mb4_unicode_ci` retained (Unicode-compliant, case-insensitive).
   If strict French alphabetical sort is needed, `utf8mb4_fr_0900_ai_ci` is available in
   MySQL 8 but not MariaDB; `unicode_ci` is the portable choice.
5. **Migration tooling**: Phinx, Doctrine Migrations, or a plain PHP script. Decision at P2.
6. **`order_item_id` constraint for selections**: the business rule that
   `order_item_selection.order_item_id` must reference a line with `item_type='menu'`
   is enforced at application layer. A MariaDB trigger could reinforce this at DB level if
   needed.

---

## 11. Next steps (DDL + Seed)

1. **DDL** (`db/migrations/0001_init_schema.sql`): transcribe this MLD into executable
   `CREATE TABLE` statements, in dependency order:
   - `category` -> `product`, `ingredient`, `allergen`, `role`
   - `menu` (depends on `category`, `product`)
   - `menu_slot` (depends on `menu`), `menu_slot_option` (depends on `menu_slot`, `product`)
   - `product_ingredient` (depends on `product`, `ingredient`)
   - `ingredient_allergen` (depends on `ingredient`, `allergen`)
   - `user` (depends on `role`), `role_visible_source` (depends on `role`)
   - `permission`, `role_permission` (depends on `role`, `permission`)
   - `customer_order`
   - `order_item` (depends on `customer_order`, `product`, `menu`)
   - `order_item_selection` (depends on `order_item`, `menu_slot`, `product`)
   - `order_item_modifier` (depends on `order_item`, `ingredient`)
   - `stock_movement` (depends on `ingredient`, `customer_order`, `user`)

2. **Seed** (`db/seeds/0001_demo_data.sql`):
   - 9 categories + 53 products + 13 menus from JSON sources (`docs/merise/_sources/`)
   - 13 menus with slots and slot options
   - 14 allergens (INCO EU 1169/2011)
   - Sample ingredient catalogue with recipes
   - 5 roles with `role_permission` matrix and `role_visible_source` data
   - 1 admin user
   - Sample orders for demo

3. **Fallback JSON export** (`scripts/export-fallback.{sh|php}`): extract seed data to
   `src/public/borne/data/*.json` for isolated kiosk mode (Bloc 1 without DB).

4. **DDL validation tests**: confirm CHECK constraints trigger as expected; confirm
   ON DELETE CASCADE / RESTRICT / SET NULL behaviours match specification.
