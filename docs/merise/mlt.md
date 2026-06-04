# Model of Logical Treatments (MLT) — Wakdo

**Merise phase** : P1 - Conception, step 4 (derived from MCT)
**Version** : v0.2 — prod-like, 4-state machine
**Date** : 2026-06-04
**Branch** : `feat/p1-conception`
**Status** : prod-like — all D1-D8 + stock decisions applied (see `docs/notes/revue-alignement-p1.md` §7)
**Author** : BYAN (methodology layer)

---

## 1. Purpose

The MLT (Model of Logical Treatments) refines each MCT operation by specifying:
- **preconditions** — what must be true before execution
- **business rules** — validation, computation, business logic
- **postconditions** — the state guaranteed after success
- **outputs** — produced data or emitted events
- **error cases** — alternative outputs when a condition fails

It bridges the MCT (conceptual level) and the PHP/SQL implementation (physical level).
All entity/attribute references use the names from `docs/merise/dictionary.md` (English,
snake_case). All monetary amounts are in integer cents.

**Tag conventions**:
- `[PRE]` — precondition; must be satisfied for the operation to execute
- `[RG]` — business rule (regle de gestion); logic applied during execution
- `[POST]` — postcondition; database state guaranteed after success
- `[OUT]` — output; data or event produced
- `[ERR]` — error case; alternative output when a condition fails

---

## 2. Transverse business rules

These rules apply to multiple operations and are centralised here to avoid repetition.

| Rule code | Label | Operations concerned |
|-----------|-------|----------------------|
| **RG-T01** | CSRF token verified on every back-office POST/PUT/DELETE form | AUTH, all admin ops |
| **RG-T02** | Session active + `user.is_active = 1` verified on each authenticated request | All domains 3-10 |
| **RG-T03** | Permission verified via `role_permission` before executing operation | All domains 3-10 |
| **RG-T04** | All monetary amounts are manipulated in integer cents; EUR conversion at output only | 3.3, 4.1, 8.1, 8.4 |
| **RG-T05** | Snapshots (`label_snapshot`, `unit_price_cents_snapshot`, `vat_rate_snapshot`) on `order_item` are not modified after INSERT (historical integrity of placed orders — design guarantee) | 3.3, 4.1, 8.2, 8.5 |
| **RG-T06** | All SQL queries use PDO with prepared statements; no user data concatenated into SQL | All operations |
| **RG-T07** | Status transition UPDATE statements include `AND status = <expected_status>` in the WHERE clause (optimistic concurrency protection against double transition) | 6.1, 7.1 |
| **RG-T08** | Operations touching multiple tables execute in an atomic database transaction; partial failure triggers full rollback | 3.3, 4.1, 7.1, 8.4, 9.1, 9.2 |
| **RG-T09** | Cross-constraint on `customer_order`: `source = 'drive'` implies `service_mode = 'drive'`; verified at order creation. Materialisable as a MariaDB CHECK: `CHECK (source != 'drive' OR service_mode = 'drive')`. | 3.3, 4.1 |
| **RG-T10** | VAT computation is line-by-line: each `order_item` carries its own `vat_rate_snapshot` (per-mille integer snapshotted from `product.vat_rate`). Order totals (`total_ht_cents`, `total_vat_cents`, `total_ttc_cents`) are the sum of line-level amounts. | 3.3, 4.1 |
| **RG-T11** | Stock decrements at the `pending_payment -> paid` transition and re-credits at `paid -> cancelled` are within the same database transaction as the status update (no orphan decrement). | 3.3, 4.1, 7.1 |
| **RG-T12** | Dashboard filter by source: each role's visible sources are read from `role_visible_source`; the query uses `WHERE customer_order.source IN (role_visible_sources)`. | 6.1 |

---

## 3. Domain 1 — Order lifecycle (kiosk)

### 3.1 LOAD_CATALOGUE

**Corresponds to MCT section 3.1**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Request originates from the kiosk endpoint (public, no authentication required) |
| **[PRE-2]** | Current time is within the service window (10:00-01:00); outside the window the kiosk displays a closed message |
| **[RG-1]** | Read all `category` rows with `is_active = 1`, ordered by `category.display_order ASC` |
| **[RG-2]** | For each category, read `product` rows with `is_available = 1` and matching `category_id`, ordered by `product.display_order ASC` |
| **[RG-3]** | Read all `menu` rows with `is_available = 1`; for each menu, load `menu_slot` rows ordered by `menu_slot.display_order ASC`; for each slot, load eligible products via `menu_slot_option JOIN product` (where `product.is_available = 1`) |
| **[RG-4]** | For each product, compute allergens by joining `product_ingredient -> ingredient_allergen -> allergen` (no manual re-entry per product) |
| **[RG-5]** | For each product with `product_ingredient` rows, load `ingredient` composition (for the configurator) |
| **[RG-6]** | Prices are returned in integer cents; EUR conversion is performed client-side |
| **[POST-1]** | No database write; database state unchanged |
| **[OUT-1]** | JSON response: `{data: {categories: [...], products: {...}, menus: [{..., slots: [{..., options: [...]}]}]}}` |
| **[ERR-1]** | DB unreachable: response `{data: null, error: {code: "DB_ERROR"}}` and front-end falls back to static JSON |

---

### 3.2 COMPOSE_CART

**Corresponds to MCT section 3.2**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Catalogue loaded into front-end memory (LOAD_CATALOGUE completed) |
| **[PRE-2]** | Selected item (product or menu) is present in the loaded catalogue with `is_available = 1` |
| **[RG-1]** | Cart is a JavaScript in-memory structure (array of items); no database persistence at this stage |
| **[RG-2]** | Each item contains: `type` (`product` or `menu`), `item_id`, `label`, `unit_price_cents` (snapshot from catalogue), `quantity`, `format` (`normal` or `maxi`, for menus), `slot_selections` (array of `{menu_slot_id, product_id, label}` for menu items), `modifiers` (array of `{ingredient_id, action, extra_price_cents}`) |
| **[RG-3]** | Format Normal/Maxi (menu items only): `normal` uses `menu.price_normal_cents`; `maxi` uses `menu.price_maxi_cents`. No individual component price change is stored; the price differential is at menu level. |
| **[RG-4]** | Ingredient modifier rules: `action = 'remove'` requires `is_removable = 1` on `product_ingredient` (free); `action = 'add'` requires `is_addable = 1` (may carry `extra_price_cents`). These constraints are verified at cart composition time against the loaded catalogue. |
| **[RG-5]** | If an item with the same `(type, item_id, format, slot_selections, modifiers)` already exists in the cart, its quantity is incremented rather than adding a new item |
| **[RG-6]** | Cart total recomputed after each change: `SUM(unit_price_cents * quantity + modifier_extras)` across all items |
| **[POST-1]** | No database write; cart in-memory state updated |
| **[OUT-1]** | Cart summary displayed with TTC total |
| **[ERR-1]** | If a product becomes `is_available = 0` between catalogue load and order submission, the server-side validation in CREATE_ORDER catches it |

---

### 3.3 CREATE_ORDER

**Corresponds to MCT section 3.3**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Cart contains at least 1 item (`items.length >= 1`) |
| **[PRE-2]** | Order number entered by customer is non-empty (front-end validation) |
| **[PRE-3]** | POST JSON body is valid (schema validation at API layer) |
| **[RG-1]** | Server-side availability check: for each item, verify `product.is_available = 1` or `menu.is_available = 1`. If any item is unavailable, reject with list of unavailable articles. |
| **[RG-2 — service_day]** | `service_day` for a given order is computed at query time as: `CASE WHEN HOUR(created_at) < 10 THEN DATE(created_at) - INTERVAL 1 DAY ELSE DATE(created_at) END`. Cutoff is 10:00. This is NOT stored as a column — computed at query time only. The v0.1 formula with `INTERVAL 4 HOUR 30 MINUTE` was incorrect and is dropped. |
| **[RG-3 — order number]** | Order number format: `K-YYYY-MM-DD-NNN` where NNN is the sequential counter for the current service_day for the `kiosk` source (SELECT COUNT + 1 with a table-level lock or serialised insert to avoid duplicate generation under concurrency). Source is `kiosk` (set by the kiosk endpoint, derived from the public entry point). |
| **[RG-4 — VAT by line]** | For each `order_item`: `vat_rate_snapshot` is copied from `product.vat_rate`. Line amounts: `unit_ttc = unit_price_cents_snapshot`; `unit_ht = ROUND(unit_ttc * 1000 / (1000 + vat_rate_snapshot))`; `unit_vat = unit_ttc - unit_ht`. Order totals: `total_ttc_cents = SUM(unit_ttc * quantity)` across all lines; `total_ht_cents = SUM(unit_ht * quantity)`; `total_vat_cents = total_ttc_cents - total_ht_cents`. Invariant: `total_ttc_cents = total_ht_cents + total_vat_cents` (verified before INSERT). |
| **[RG-5 — atomic transaction]** | All writes within one database transaction: (1) INSERT `customer_order` (status `pending_payment`, source `kiosk`, service_mode from cart, computed totals); (2) INSERT `order_item` rows (label_snapshot, unit_price_cents_snapshot, vat_rate_snapshot, quantity, format, item_type, product_id or menu_id); (3) INSERT `order_item_selection` rows for each slot filled in a menu item (order_item_id, menu_slot_id, product_id, label_snapshot); (4) INSERT `order_item_modifier` rows for each ingredient modification (order_item_id, ingredient_id, action, extra_price_cents snapshot); (5) for each ingredient consumed: compute units = `(order_item.format = 'maxi' ? product_ingredient.quantity_maxi : product_ingredient.quantity_normal) * order_item.quantity`, adjusted by modifiers (remove => no decrement for that ingredient; add => extra decrement); UPDATE `ingredient.stock_quantity -= units`; INSERT `stock_movement` (type `sale`, delta = -units, order_id, user_id = NULL for kiosk); (6) UPDATE `customer_order` SET status = `paid`, `paid_at = NOW()`. All six steps commit together or roll back entirely. |
| **[RG-6 — cross-constraint]** | Source `kiosk` implies no particular service_mode constraint; the customer selects `dine_in` or `takeaway`. The drive cross-constraint (RG-T09) does not apply to kiosk-originated orders. |
| **[RG-7 — immutability]** | After INSERT, `label_snapshot`, `unit_price_cents_snapshot`, and `vat_rate_snapshot` are not modified even if the source product is later renamed or repriced (see RG-T05). |
| **[POST-1]** | One `customer_order` row exists with `status = 'paid'`, `source = 'kiosk'`, all totals computed, `paid_at` set. The `pending_payment` phase is not observable outside the transaction. |
| **[POST-2]** | N `order_item` rows exist, each referencing either a `product_id` (item_type='product') or a `menu_id` (item_type='menu') — exclusivity constraint verified. |
| **[POST-3]** | `customer_order.order_number` is unique in the database (UNIQUE constraint). |
| **[POST-4]** | `ingredient.stock_quantity` decremented for each consumed ingredient unit; one `stock_movement` row of type `sale` per affected ingredient. |
| **[OUT-1]** | HTTP 201: `{data: {id: int, order_number: string, status: 'paid'}}` |
| **[OUT-2]** | Logical event ORDER_CREATED available for preparation domain (preparation display refreshes via polling or server push depending on implementation) |
| **[ERR-1]** | Empty cart: HTTP 422, `{error: {code: "EMPTY_CART"}}` |
| **[ERR-2]** | Unavailable item: HTTP 422, `{error: {code: "ITEM_UNAVAILABLE", items: [...]}}` |
| **[ERR-3]** | DB error / timeout: HTTP 500 with rollback, `{error: {code: "DB_ERROR"}}` |

---

### 3.4 DISPLAY_CONFIRMATION

**Corresponds to MCT section 3.4**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | CREATE_ORDER returned HTTP 201 with `{id, order_number, status: 'paid'}` |
| **[RG-1]** | Order number displayed prominently on the confirmation screen |
| **[RG-2]** | After a configurable delay (suggestion: 15 seconds), the kiosk auto-resets for the next customer |
| **[POST-1]** | No database write |
| **[OUT-1]** | Confirmation screen displayed with order number |
| **[ERR-1]** | If API response is an error: generic error message displayed with option to retry |

---

## 4. Domain 2 — Order lifecycle (counter and drive)

### 4.1 CREATE_COUNTER_ORDER

**Corresponds to MCT section 4.1**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Actor is authenticated (valid session, `user.is_active = 1`) |
| **[PRE-2]** | Actor holds permission `order.create` (verified via `role_permission`) |
| **[PRE-3]** | Cart contains at least 1 item |
| **[RG-1]** | Creation logic identical to CREATE_ORDER (RG-1 through RG-7 apply), with the following differences: `source` is auto-tagged from `role.order_source` (counter role -> `counter`, drive role -> `drive`); `service_mode` is selected by the staff member (`dine_in` / `takeaway` / `drive`); `user_id` is set to the authenticated user's id in `stock_movement` rows (instead of NULL for kiosk). |
| **[RG-2 — cross-constraint]** | If `source = 'drive'` then `service_mode` must be `'drive'` (RG-T09); verified before INSERT. HTTP 422 if violated. |
| **[RG-3 — order number]** | Format: `C-YYYY-MM-DD-NNN` for counter source; `D-YYYY-MM-DD-NNN` for drive source. Sequential NNN counter is per source per service_day. |
| **[RG-4 — stock]** | Same stock decrement logic as CREATE_ORDER RG-5; `stock_movement.user_id` is set to the authenticated staff member's id. |
| **[POST-1]** | One `customer_order` row with `status = 'paid'`, `source = 'counter'` or `'drive'`, `paid_at` set. |
| **[POST-2]** | N `order_item` rows with snapshots. Slot selections and modifiers written identically to kiosk flow. |
| **[POST-3]** | Stock decremented; movements logged with actor `user_id`. |
| **[OUT-1]** | HTTP 201: `{data: {id: int, order_number: string, status: 'paid'}}`. Order number communicated to customer. |
| **[ERR-1]** | Same error cases as CREATE_ORDER (ERR-1, ERR-2, ERR-3) |
| **[ERR-2]** | Cross-constraint violation (`source = drive` but `service_mode != drive`): HTTP 422, `{error: {code: "INVALID_SERVICE_MODE"}}` |

---

## 5. Domain 3 — Preparation display (kitchen)

### 5.1 LIST_ORDERS_DISPLAY

**Corresponds to MCT section 5.1**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Actor is authenticated, `is_active = 1` |
| **[PRE-2]** | Actor holds permission `order.read` |
| **[RG-1 — source filter]** | Retrieve visible sources for the actor's role: `SELECT source FROM role_visible_source WHERE role_id = :role_id`. Kitchen sees all three; counter sees `kiosk` and `counter`; drive sees `drive`. |
| **[RG-2 — query]** | `SELECT customer_order.*, order_item.* FROM customer_order JOIN order_item ON order_item.order_id = customer_order.id WHERE customer_order.status = 'paid' AND customer_order.source IN (:visible_sources) ORDER BY customer_order.paid_at ASC` |
| **[RG-3 — item detail]** | For each order line of type `menu`, also load `order_item_selection` rows (slot choices). For all lines, load `order_item_modifier` rows (ingredient modifications). Display uses snapshots (`label_snapshot`, `quantity`, `format`); no re-join on `product` or `menu` tables needed. |
| **[RG-4 — KDS colour]** | Colour indicator computed at render time: `elapsed = NOW() - customer_order.paid_at`; green if elapsed < SLA threshold (configurable, approx. 10 min); amber if approaching; red if exceeded. Not stored; computed client-side or in PHP before response. |
| **[RG-5 — read only]** | Kitchen staff perform no status transition from this view. No UPDATE is issued by this operation. |
| **[POST-1]** | No database write |
| **[OUT-1]** | List of orders with status `paid`, filtered by role, sorted by `paid_at` ascending, with full item detail (selections, modifiers, KDS colour) |

---

## 6. Domain 4 — Delivery to customer

### 6.1 DELIVER_ORDER

**Corresponds to MCT section 6.1**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Actor is authenticated, holds permission `order.deliver` |
| **[PRE-2]** | Targeted order exists and `status = 'paid'` |
| **[PRE-3]** | Order source is in the actor's visible sources (verified via `role_visible_source`) |
| **[RG-1]** | `UPDATE customer_order SET status = 'delivered', delivered_at = NOW(), updated_at = NOW() WHERE id = :id AND status = 'paid'` |
| **[RG-2 — concurrency]** | The `AND status = 'paid'` clause in the UPDATE protects against concurrent double-delivery: if two staff members click simultaneously, only the first succeeds (second receives 0 rows affected). |
| **[RG-3]** | `delivered` is a terminal status: no further transition is defined from this status (application constraint, not enforced as a DB trigger). |
| **[POST-1]** | `customer_order.status = 'delivered'`, `delivered_at` set, lifecycle complete. Order passes to history. |
| **[OUT-1]** | HTTP 200 with confirmation. Order disappears from the `paid` queue. |
| **[ERR-1]** | Invalid transition (status was not `paid` when UPDATE executed — concurrency): HTTP 409, `{error: {code: "INVALID_TRANSITION"}}` |
| **[ERR-2]** | Order source not in actor's visible sources: HTTP 403, `{error: {code: "FORBIDDEN"}}` |

---

## 7. Domain 5 — Cancellation

### 7.1 CANCEL_ORDER

**Corresponds to MCT section 7.1**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Actor is authenticated, holds permission `order.cancel` |
| **[PRE-2]** | Targeted order exists |
| **[PRE-3]** | `customer_order.status` is in `['pending_payment', 'paid']`. Terminal statuses `delivered` and `cancelled` cannot transition to `cancelled`. |
| **[RG-1 — status update]** | `UPDATE customer_order SET status = 'cancelled', cancelled_at = NOW(), updated_at = NOW() WHERE id = :id AND status IN ('pending_payment', 'paid')` |
| **[RG-2 — concurrency]** | The `AND status IN (...)` clause protects against concurrent cancellation (see RG-T07). |
| **[RG-3 — stock re-credit — conditional]** | Re-credit applies only if the order was at status `paid` before cancellation. Orders at `pending_payment` had not yet decremented stock (the decrement occurs at the `paid` transition). For each `order_item` line of a `paid` order, recompute ingredient units consumed: `(order_item.format = 'maxi' ? product_ingredient.quantity_maxi : product_ingredient.quantity_normal) * order_item.quantity`, adjusted by `order_item_modifier` rows (remove modifier -> ingredient was not decremented, so no re-credit; add modifier -> ingredient had extra decrement, so extra re-credit). UPDATE `ingredient.stock_quantity += units`. INSERT `stock_movement` (type `cancellation`, delta = +units, order_id, user_id of actor). |
| **[RG-4 — transaction]** | Status update and stock re-credit (when applicable) execute in the same database transaction (RG-T11). |
| **[RG-5 — history]** | Order is not physically deleted; retained for history and stats. Cancelled orders are excluded from revenue totals but included in volume counts in READ_STATS. `order_item` rows are not deleted (ON DELETE CASCADE is not triggered); they allow reconstruction of what was ordered. |
| **[POST-1]** | `customer_order.status = 'cancelled'`, `cancelled_at` set, terminal state. |
| **[POST-2]** | If prior status was `paid`: `ingredient.stock_quantity` re-credited; one `stock_movement` row of type `cancellation` per affected ingredient. |
| **[OUT-1]** | HTTP 200 with cancellation confirmation |
| **[ERR-1]** | Attempt to cancel a delivered or already cancelled order: HTTP 422, `{error: {code: "CANNOT_CANCEL_IN_STATE", current_status: "..."}}` |
| **[ERR-2]** | Concurrent cancellation (0 rows affected by UPDATE): HTTP 409, `{error: {code: "INVALID_TRANSITION"}}` |

---

## 8. Domain 6 — Catalogue management

### 8.1 CREATE_PRODUCT

**Corresponds to MCT section 8.1**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Actor authenticated, holds permission `product.create` |
| **[PRE-2]** | `category_id` references an existing category with `is_active = 1` |
| **[RG-1]** | Form validation: `name` non-empty, `price_cents > 0`, `category_id` valid, `vat_rate` in `(55, 100)` |
| **[RG-2]** | Image upload (optional): validate MIME type (JPEG, PNG, WEBP), max size configurable (suggestion: 2 MB), store under `UPLOAD_DIR/products/`, record relative path in `image_path` |
| **[RG-3]** | `is_available = 1` by default at INSERT |
| **[RG-4]** | `display_order` set to `MAX(display_order) + 1` for the target category, or 0 if first product |
| **[POST-1]** | One `product` row in the database with all valid fields |
| **[OUT-1]** | Redirect to category product list with success message |
| **[ERR-1]** | Validation failure: inline field errors displayed |
| **[ERR-2]** | Invalid image (type or size): specific error message |

---

### 8.2 UPDATE_PRODUCT

**Corresponds to MCT section 8.2**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Actor authenticated, holds permission `product.update` |
| **[PRE-2]** | Target `product.id` exists |
| **[RG-1]** | Same validations as CREATE_PRODUCT on modified fields |
| **[RG-2]** | If a new image is uploaded, the old image file is deleted from the filesystem (volume cleanup) |
| **[RG-3]** | `label_snapshot`, `unit_price_cents_snapshot`, `vat_rate_snapshot` in historical `order_item` rows are not modified (see RG-T05) |
| **[POST-1]** | `product` updated, `updated_at` refreshed |
| **[OUT-1]** | Redirect to product list with success message |

---

### 8.3 DELETE_PRODUCT

**Corresponds to MCT section 8.3**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Actor authenticated, holds permission `product.delete` |
| **[PRE-2]** | Target `product.id` exists |
| **[RG-1]** | Pre-check (PHP): is the product referenced in `menu_slot_option.product_id`? If yes, display blocking message listing the menus. |
| **[RG-2]** | Pre-check (PHP): is the product the `burger_product_id` of any `menu`? If yes, block with message to delete or reassign the menu first. |
| **[RG-3]** | Pre-check (PHP): is the product referenced in `order_item.product_id` (historical orders)? FK `ON DELETE RESTRICT` blocks at DB level. Recommended response: propose deactivation (`is_available=0`) rather than deletion. |
| **[RG-4]** | FK constraints (`menu_slot_option.product_id ON DELETE RESTRICT`, `order_item.product_id ON DELETE RESTRICT`) enforce the constraint even if the PHP check is bypassed. |
| **[POST-1]** | Product deleted if no FK constraint was blocking |
| **[OUT-1]** | Redirect to product list with success message |
| **[ERR-1]** | Product in menu slot: HTTP 422 or inline message with blocking menu list |
| **[ERR-2]** | Product in historical orders: message proposing deactivation instead |

---

### 8.4 CREATE_MENU

**Corresponds to MCT section 8.4**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Actor authenticated, holds permission `menu.create` |
| **[PRE-2]** | `burger_product_id` references an existing, available product |
| **[PRE-3]** | At least one `menu_slot` is defined with at least one `menu_slot_option` |
| **[RG-1]** | Validation: `name` non-empty, `price_normal_cents > 0`, `price_maxi_cents > 0`, `burger_product_id` valid, all `product_id` values in slot options exist |
| **[RG-2]** | Transaction: INSERT `menu`, then INSERT `menu_slot` rows (name, slot_type, is_required, display_order), then INSERT `menu_slot_option` rows (menu_slot_id, product_id) |
| **[RG-3]** | Valid `slot_type` values (from dictionary ENUM): `drink`, `side`, `sauce`, `dessert`, `extra` |
| **[POST-1]** | One `menu` row, N `menu_slot` rows, M `menu_slot_option` rows in the database |
| **[OUT-1]** | Redirect to menu list with success message |
| **[ERR-1]** | Invalid configuration (no slot, no option): business error message |
| **[ERR-2]** | Slot option product unavailable: warning (menu can be created; product availability is checked at order time) |

---

### 8.5 UPDATE_MENU

**Corresponds to MCT section 8.5**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Actor authenticated, holds permission `menu.update` |
| **[PRE-2]** | Target `menu.id` exists |
| **[RG-1]** | Same validations as CREATE_MENU on modified fields |
| **[RG-2]** | If slot configuration is modified: `DELETE FROM menu_slot_option WHERE menu_slot_id IN (SELECT id FROM menu_slot WHERE menu_id = :id)`, then `DELETE FROM menu_slot WHERE menu_id = :id`, then re-INSERT (delete-and-reinsert pattern, atomic in transaction) |
| **[RG-3]** | `label_snapshot` values in historical `order_item_selection` rows are not affected (see RG-T05) |
| **[POST-1]** | `menu` updated; `menu_slot` and `menu_slot_option` rebuilt |
| **[OUT-1]** | Redirect with success message |

---

### 8.6 DELETE_MENU

**Corresponds to MCT section 8.6**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Actor authenticated, holds permission `menu.delete` |
| **[PRE-2]** | Target `menu.id` exists |
| **[RG-1]** | Pre-check (PHP): is the menu referenced in `order_item.menu_id`? FK `ON DELETE RESTRICT`. If yes, propose deactivation (`is_available=0`) instead of deletion. |
| **[RG-2]** | If no historical reference: DELETE `menu` triggers CASCADE to `menu_slot` (which cascades to `menu_slot_option`) |
| **[POST-1]** | `menu`, its `menu_slot` rows, and its `menu_slot_option` rows deleted |
| **[OUT-1]** | Redirect with success message |
| **[ERR-1]** | Menu in historical orders: message proposing deactivation instead |

---

### 8.7 MANAGE_CATEGORY

**Corresponds to MCT section 8.7**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Actor authenticated, holds permission `category.manage` |
| **[RG-CREATE]** | `name` and `slug` non-empty and unique in the database; `display_order` set to MAX + 1 |
| **[RG-UPDATE]** | UPDATE `name`, `slug`, `image_path`, `display_order`, `is_active` |
| **[RG-DEACTIVATE]** | Deactivation (`is_active=0`) does not auto-deactivate child products/menus in the DB (no CASCADE on `is_active`). PHP layer proposes to the admin to also deactivate child products/menus, or the kiosk filter on `category.is_active = 1` implicitly hides them. |
| **[RG-DELETE]** | Physical deletion blocked if `product.category_id` or `menu.category_id` references this category (FK `ON DELETE RESTRICT`). Propose deactivation. |
| **[POST-CREATE]** | New `category` row in database |
| **[POST-UPDATE]** | `category` updated, `updated_at` refreshed |
| **[OUT-1]** | Confirmation, redirect to category list |

---

### 8.8 MANAGE_INGREDIENT

**Corresponds to MCT section 8.8**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Actor authenticated, holds permission `ingredient.manage` |
| **[RG-CREATE-ING]** | `name` non-empty and UNIQUE; `unit` non-empty; `pack_size >= 1`; `low_stock_threshold >= 0`; `stock_quantity` defaults to 0 at creation |
| **[RG-UPDATE-ING]** | UPDATE `name`, `unit`, `pack_size`, `pack_label`, `low_stock_threshold`, `is_active` |
| **[RG-DEACTIVATE-ING]** | `is_active=0` hides ingredient from configurator. Physical deletion blocked if referenced in `product_ingredient` (FK `ON DELETE RESTRICT`) or `stock_movement` (FK `ON DELETE RESTRICT`). |
| **[RG-COMPOSITION]** | UPDATE `product_ingredient`: for each ingredient in a product's recipe, set `quantity_normal`, `quantity_maxi`, `is_removable`, `is_addable`, `extra_price_cents`. Delete-and-reinsert pattern within transaction. |
| **[RG-ALLERGEN]** | Manage `ingredient_allergen`: INSERT or DELETE `(ingredient_id, allergen_id)` pairs. Allergen list is read-only (14 rows fixed by EU regulation 1169/2011). |
| **[POST-1]** | `ingredient` / `product_ingredient` / `ingredient_allergen` rows updated |
| **[OUT-1]** | Confirmation, redirect to ingredient list or product composition form |

---

## 9. Domain 7 — Stock management

### 9.1 RESTOCK

**Corresponds to MCT section 9.1**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Actor authenticated, holds permission `stock.manage` |
| **[PRE-2]** | Target ingredient exists and `is_active = 1` |
| **[PRE-3]** | Number of packs `N >= 1` |
| **[RG-1]** | `delta = N * ingredient.pack_size` |
| **[RG-2]** | Transaction: `UPDATE ingredient SET stock_quantity = stock_quantity + :delta WHERE id = :id`; INSERT `stock_movement` (ingredient_id, movement_type=`restock`, delta=+delta, order_id=NULL, user_id=actor, note=optional) |
| **[RG-3]** | `stock_movement` is append-only: no UPDATE or DELETE on this table (corrections are new rows) |
| **[POST-1]** | `ingredient.stock_quantity` incremented by `delta`. One `stock_movement` row of type `restock` inserted. |
| **[OUT-1]** | Confirmation with new stock level displayed |

---

### 9.2 INVENTORY_COUNT

**Corresponds to MCT section 9.2**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Actor authenticated, holds permission `stock.count` |
| **[PRE-2]** | Target ingredient exists |
| **[PRE-3]** | `actual_quantity >= 0` (physical count is non-negative) |
| **[RG-1]** | `delta = actual_quantity - ingredient.stock_quantity` (may be negative if actual < theoretical) |
| **[RG-2]** | Transaction: `UPDATE ingredient SET stock_quantity = :actual_quantity WHERE id = :id`; INSERT `stock_movement` (ingredient_id, movement_type=`inventory_correction`, delta=computed, order_id=NULL, user_id=actor, note=optional) |
| **[RG-3]** | `delta = 0` is a valid correction (physical count matches theoretical); a movement row is still inserted for audit completeness |
| **[POST-1]** | `ingredient.stock_quantity = actual_quantity`. One `stock_movement` row of type `inventory_correction` inserted. |
| **[OUT-1]** | Confirmation with reconciled stock level and discrepancy displayed |

---

### 9.3 READ_STOCK

**Corresponds to MCT section 9.3**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Actor authenticated, holds permission `stock.read` |
| **[RG-1]** | `SELECT * FROM ingredient WHERE is_active = 1 ORDER BY name ASC` |
| **[RG-2]** | Low-stock alert computed at render time: `stock_quantity <= low_stock_threshold` -> flag `low_stock: true` in response. Not stored as a column. |
| **[RG-3]** | Optional movement history for a given ingredient: `SELECT * FROM stock_movement WHERE ingredient_id = :id ORDER BY created_at DESC LIMIT :n` |
| **[POST-1]** | No database write |
| **[OUT-1]** | Ingredient list with `stock_quantity`, `low_stock_threshold`, `pack_size`, `pack_label`, `low_stock` flag |

---

## 10. Domain 8 — User and role management

### 10.1 CREATE_USER

**Corresponds to MCT section 10.1**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Actor authenticated, holds permission `user.create` |
| **[PRE-2]** | Email does not already exist in `user.email` (UNIQUE constraint) |
| **[PRE-3]** | `role_id` references an existing, active role |
| **[RG-1]** | Validation: `email` conforms to RFC 5321 (PHP `FILTER_VALIDATE_EMAIL`), `first_name` and `last_name` non-empty, `role_id` valid |
| **[RG-2]** | Password hash: `password_hash($password, PASSWORD_ARGON2ID)`. Minimum password length: 8 characters. |
| **[RG-3]** | `is_active = 1` by default; `last_login_at = NULL` at creation |
| **[POST-1]** | One `user` row with argon2id `password_hash`, valid `role_id` |
| **[OUT-1]** | Redirect to user list with success message |
| **[ERR-1]** | Duplicate email: message "This email is already in use" |
| **[ERR-2]** | Password too short: inline validation message |

---

### 10.2 UPDATE_USER

**Corresponds to MCT section 10.2**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Actor authenticated, holds permission `user.update` |
| **[PRE-2]** | Target `user.id` exists |
| **[RG-1]** | If a new password is supplied (non-empty field): rehash via `PASSWORD_ARGON2ID` and replace existing hash |
| **[RG-2]** | If password field is empty: existing hash is preserved unchanged |
| **[RG-3]** | Email update subject to UNIQUE constraint (pre-check before UPDATE) |
| **[POST-1]** | `user` updated, `updated_at` refreshed |
| **[OUT-1]** | Redirect with success message |

---

### 10.3 DEACTIVATE_USER

**Corresponds to MCT section 10.3**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Actor authenticated, holds permission `user.deactivate` |
| **[PRE-2]** | Actor is not targeting their own account (`$targetUserId !== $currentUserId`) |
| **[RG-1]** | `UPDATE user SET is_active = 0, updated_at = NOW() WHERE id = :id` |
| **[RG-2]** | The user's potentially active session is invalidated on next request: middleware checks `user.is_active = 1` on each authenticated request |
| **[POST-1]** | `user.is_active = 0`; user cannot log in; history remains intact |
| **[OUT-1]** | Redirect with success message |
| **[ERR-1]** | Self-deactivation attempt: HTTP 403, `{error: {code: "SELF_DEACTIVATION_FORBIDDEN"}}` |

---

### 10.4 MANAGE_RBAC

**Corresponds to MCT section 10.4**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Actor authenticated, holds permission `role.manage` |
| **[PRE-2]** | Target `role.id` exists (for permission update) or role fields are valid (for role creation) |
| **[PRE-3]** | All submitted `permission_id` values exist in the `permission` catalogue |
| **[RG-1 — permissions]** | Transaction: `DELETE FROM role_permission WHERE role_id = :id`; INSERT new `(role_id, permission_id)` pairs for each selected permission |
| **[RG-2]** | Permissions are not modifiable via this operation: they are read-only to populate the selection form. Permission catalogue is frozen at seed. |
| **[RG-3]** | Effect is immediate for new requests; sessions of users bearing this role see the change on the next permission check (sessions store `role_id`; permissions are reloaded from DB on each check). |
| **[RG-4 — custom role]** | Creating a custom role: INSERT `role` (code UNIQUE, label, description, default_route nullable, order_source nullable); INSERT `role_visible_source` rows as needed. |
| **[RG-5 — order_source]** | `role.order_source` controls the auto-tagging of `customer_order.source` when this role creates an order. NULL for admin and manager (they can create on behalf of any channel). |
| **[POST-1]** | `role_permission` reflects exactly the selected permissions for this role |
| **[OUT-1]** | Redirect with success message |

---

## 11. Domain 9 — Stats and KPI

### 11.1 READ_STATS

**Corresponds to MCT section 11.1**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Actor authenticated, holds permission `stats.read` |
| **[RG-1 — service_day]** | `service_day` expression used in all stats aggregations: `CASE WHEN HOUR(customer_order.created_at) < 10 THEN DATE(customer_order.created_at) - INTERVAL 1 DAY ELSE DATE(customer_order.created_at) END`. Cutoff at 10:00. No stored column. The v0.1 formula with `INTERVAL 4 HOUR 30 MINUTE` is dropped. |
| **[RG-2 — revenue]** | Revenue queries filter `status != 'cancelled'`; they sum `total_ttc_cents` from `customer_order`. Cancelled orders are excluded from revenue but appear in volume counts with `status = 'cancelled'` filter. |
| **[RG-3 — top products]** | `SELECT label_snapshot, SUM(quantity) AS total_sold FROM order_item JOIN customer_order ON ... WHERE customer_order.status != 'cancelled' GROUP BY label_snapshot ORDER BY total_sold DESC LIMIT 10` |
| **[RG-4 — delivery time KPI]** | Average delivery time: `AVG(TIMESTAMPDIFF(SECOND, paid_at, delivered_at))` on orders with `status = 'delivered'`. SLA reference approx. 10 min (configurable). |
| **[RG-5 — breakdown]** | Breakdowns available by `source` (kiosk/counter/drive) and `service_mode` (dine_in/takeaway/drive) for capacity planning. `service_mode` carries no fiscal role (see dictionary note 9). |
| **[POST-1]** | No database write |
| **[OUT-1]** | Stats dashboard data: revenue by service_day, order counts, top products, cancellation rate, average delivery time, breakdown by source/service_mode |

---

## 12. Domain 10 — Back-office authentication

### 12.1 AUTHENTICATE_USER

**Corresponds to MCT section 12.1**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Login form submitted with email and password |
| **[PRE-2]** | CSRF token of the form is valid (anti-CSRF protection) |
| **[RG-1]** | Lookup: `SELECT * FROM user WHERE email = :email AND is_active = 1 LIMIT 1` |
| **[RG-2]** | Password verification: `password_verify($password, $user->password_hash)`. On failure: same generic error whether the email does not exist or the password is wrong (protection against email enumeration). |
| **[RG-3]** | On success: `session_regenerate(true)` (session ID regeneration, protection against session fixation) |
| **[RG-4]** | Session storage: `$_SESSION['user_id']`, `$_SESSION['role_id']`, `$_SESSION['logged_in_at']` |
| **[RG-5]** | UPDATE: `UPDATE user SET last_login_at = NOW() WHERE id = :id` |
| **[RG-6]** | Session timeouts: idle timeout 4h (detection via last-activity timestamp in session); absolute timeout 10h (detection via `logged_in_at`) |
| **[RG-7]** | Redirect target is `role.default_route` (dynamic; no hardcoded role name in routing logic) |
| **[POST-1]** | PHP session open with `user_id` and `role_id`; `user.last_login_at` updated |
| **[OUT-1]** | Redirect to `role.default_route` |
| **[ERR-1]** | Incorrect credentials or inactive account: generic message "Email or password incorrect" (no distinction to prevent enumeration) |
| **[ERR-2]** | Invalid CSRF token: HTTP 403 |

---

### 12.2 LOGOUT_USER

**Corresponds to MCT section 12.2**

| Tag | Content |
|-----|---------|
| **[PRE-1]** | Valid session open (`session_id()` non-empty, `$_SESSION['user_id']` present) |
| **[RG-1]** | `$_SESSION = []` (clear session data) |
| **[RG-2]** | If session cookie exists, expire it: `setcookie(session_name(), '', time() - 3600, '/', '', true, true)` |
| **[RG-3]** | `session_destroy()` |
| **[POST-1]** | PHP session destroyed; no authenticated access possible with the old cookie |
| **[OUT-1]** | Redirect to login page |

---

## 13. Automated treatments — Crons (outside user interactions)

These treatments are executed by the `wakdo-cron` service container in the maintenance
window 01:30-09:30 (outside active service). They are outside the MCT scope (technical
treatments, no user trigger) but are documented here for consistency with PROJECT_CONTEXT.

### 13.1 Stats aggregation (cron 04:30)

| Tag | Content |
|-----|---------|
| **[TRIGGER]** | Cron: `30 4 * * *` |
| **[RG-1]** | `service_day` to aggregate: computed per order (see RG-1 of READ_STATS). At 04:30 the service_day in progress is the previous calendar day. |
| **[RG-2]** | Aggregations by `service_day`: order count, TTC revenue (sum `total_ttc_cents` where `status != 'cancelled'`), top products (by `label_snapshot`, COUNT in `order_item`) |
| **[POST-1]** | Stats available for admin dashboard (direct queries on `customer_order` filtered by `service_day`, or an aggregation table if implemented) |

### 13.2 Expired sessions purge (cron every 15 min)

| Tag | Content |
|-----|---------|
| **[TRIGGER]** | Cron: `*/15 * * * *` |
| **[RG-1]** | File-based sessions (default): `find /tmp/sessions -mmin +240 -delete` |
| **[RG-2]** | DB-based sessions (option): `DELETE FROM php_sessions WHERE updated_at < NOW() - INTERVAL 4 HOUR` |
| **[POST-1]** | Expired sessions deleted; users inactive for more than 4h are forced to re-login |

### 13.3 DB backup (cron 03:00)

| Tag | Content |
|-----|---------|
| **[TRIGGER]** | Cron: `0 3 * * *` |
| **[RG-1]** | `mysqldump` of the `wakdo` database to a dated file in the backup volume |
| **[RG-2]** | Retention: keep the last 7 dumps; delete older ones |
| **[POST-1]** | SQL dump available for restoration |

---

## 14. State machine — consistency recap (MLT)

Summary of `customer_order.status` transitions covered in the MLT, with corresponding
operations, SQL condition, concurrency protection, and phase timestamp set.

| Transition | MLT operation | SQL condition | Concurrency protection | Phase timestamp set |
|------------|--------------|---------------|------------------------|---------------------|
| `-> pending_payment` (creation) | CREATE_ORDER (3.3), CREATE_COUNTER_ORDER (4.1) | INSERT with status `pending_payment` | Atomic transaction | `created_at` |
| `pending_payment -> paid` | CREATE_ORDER (3.3), CREATE_COUNTER_ORDER (4.1) | UPDATE in same transaction | Atomic transaction | `paid_at` |
| `paid -> delivered` | DELIVER_ORDER (6.1) | `WHERE status = 'paid'` | AND status in WHERE | `delivered_at` |
| `pending_payment/paid -> cancelled` | CANCEL_ORDER (7.1) | `WHERE status IN ('pending_payment', 'paid')` | AND status IN WHERE | `cancelled_at` |

Terminal statuses (no further transition defined from these states): `delivered`, `cancelled`.

**Dropped from v0.1**:
- `paid -> preparing` and `preparing -> ready` transitions — intermediate states removed.
- MARQUER_EN_PREPARATION (v0.1 MLT section 4.2) — dropped.
- MARQUER_PRETE (v0.1 MLT section 4.3) — dropped.
- `preparing` and `ready` in the cancellable state set — the cancellable set is now
  `['pending_payment', 'paid']` only.
- `commande_event` table and v0.1 RG-T10 — replaced by phase timestamps on `customer_order`.

---

## 15. Residual notes and open points

### 15.1 `service_day` — not materialised as a column

The `service_day` computation is documented (RG-2 of CREATE_ORDER, RG-1 of READ_STATS):
`CASE WHEN HOUR(created_at) < 10 THEN DATE(created_at) - INTERVAL 1 DAY ELSE DATE(created_at) END`
(cutoff 10:00). It is computed at query time, not stored. For high-frequency stats queries,
a MariaDB generated column `VIRTUAL` or `STORED` could be added at DDL time to avoid
per-row recomputation, but this is not a blocker for the RNCP scope.
The v0.1 formula with `INTERVAL 4 HOUR 30 MINUTE` was incorrect and is dropped.

### 15.2 `order_item_modifier` for menu items

For a menu line (`item_type='menu'`), modifiers target the fixed burger identified via
`order_item.menu_id -> menu.burger_product_id`. The constraint that modifiers reference
only ingredients belonging to the burger's `product_ingredient` is enforced at the
application layer, not at the DB FK layer (see dictionary note 10). This is a known
trade-off: a multi-column FK or a DB trigger would be needed to enforce it at DB level.
Documenting it as an application invariant is the retained approach for this project scope.

### 15.3 Order number NNN counter — concurrency

The sequential NNN counter per `(source, service_day)` could produce duplicates under
high concurrency if implemented naively as `SELECT COUNT + 1`. The recommended
implementation at DDL/code time is either: (a) a table-level advisory lock around the
count-and-insert sequence; or (b) a dedicated sequence table with an atomic increment.
The UNIQUE constraint on `order_number` provides the last-resort guard (INSERT would fail
and the application retries). This is not a blocker for the RNCP demo volume.
