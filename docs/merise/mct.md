# Model of Conceptual Treatments (MCT) — Wakdo

**Merise phase** : P1 - Conception, step 3 (after MCD)
**Version** : v0.2 — prod-like, 4-state machine
**Date** : 2026-06-04
**Branch** : `feat/p1-conception`
**Status** : prod-like — all D1-D8 + stock decisions applied (see `docs/notes/revue-alignement-p1.md` §7)
**Author** : BYAN (methodology layer)

---

## 1. Purpose

The MCT (Model of Conceptual Treatments) describes the **business operations** of the Wakdo
domain in the canonical Merise form: **triggering event -> operation -> emitted result**.

It answers the question: what happens in the domain, and when?
It does not answer: who does what, on which workstation, in which organisational order
(the MOT level is intentionally skipped — agile shortcut, consistent with the solo RNCP
framework).

The MCT covers:
- The order lifecycle end-to-end (kiosk, counter, drive)
- Catalogue management (manager / admin)
- User and role management (admin)
- Back-office authentication (all back-office actors)

**Identified actors**:

| Actor | Code | Interface |
|-------|------|-----------|
| Customer (kiosk) | CUSTOMER | Touch kiosk (public, unauthenticated) |
| Counter staff | COUNTER | Back-office, role `counter` |
| Drive staff | DRIVE | Back-office, role `drive` |
| Kitchen staff | KITCHEN | Back-office, role `kitchen` (read-only on orders) |
| Manager | MANAGER | Back-office, role `manager` |
| Administrator | ADMIN | Back-office, role `admin` |
| System | SYS | Internal API / PHP logic |

**MCD cross-reference**: each operation references entities from the MCD (section 14).
The MCT is consistent with the `customer_order.status` state machine:

```
pending_payment -> paid -> delivered
      |              |
      +--------------+-----------> cancelled (from any non-terminal state)
```

**Dropped states** (compared to v0.1): `preparing` and `ready` are removed.
Rationale: in a fast-food context the kitchen display (KDS) is a visual system; staff read
the ticket and act. The single staff gesture is "deliver". KPI is total time
`delivered_at - paid_at` (SLA approx. 10 min). KDS colour coding is computed from
`now - paid_at`; no additional stored state is required.

**Dropped operations** (compared to v0.1): `MARK_IN_PREPARATION` (`MARQUER_EN_PREPARATION`)
and `MARK_READY` (`MARQUER_PRETE`) are removed because their intermediate states no longer
exist. `DELIVER_ORDER` becomes the sole status-advancing action for counter/drive staff.

---

## 2. Representation conventions

### Operation format

```
[TRIGGERING EVENT(S)]
        |
        | [SYNCHRONISATION RULE / CONDITION]
        v
   ( OPERATION )
        |
        v
[EMITTED RESULT(S)]
```

**Synchronisations**:
- `AND`: all events must be present simultaneously to trigger the operation.
- `OR`: any one of the events is sufficient.

**Conditions**: expressed in square brackets `[condition]` on the incoming arc.

### Textual notation

For each operation the document provides:
- **Triggering event(s)**: what occurs and causes the operation.
- **Actor(s)**: who initiates (or validates).
- **Synchronisation**: `AND` / `OR` if multiple events, plus condition.
- **Operation**: name and description of what it does.
- **MCD entities touched**: read (R) or write (W).
- **Result(s)**: what is emitted or produced.

---

## 3. Domain 1 — Order lifecycle (kiosk)

### 3.1 LOAD_CATALOGUE

| Field | Value |
|-------|-------|
| **Triggering event** | Customer opens the kiosk (connection to the kiosk endpoint) |
| **Actor** | CUSTOMER |
| **Synchronisation** | None (single event) |
| **Condition** | The kiosk is in service (within business hours 10:00-01:00) |
| **Operation** | LOAD_CATALOGUE |
| **Description** | Retrieval of active categories, available products, and available menus (with their slots and eligible options) for display on the kiosk screen. |
| **MCD entities** | R: `category` (is_active=1), `product` (is_available=1), `menu` (is_available=1), `menu_slot`, `menu_slot_option`, `ingredient` (is_active=1), `allergen`, `ingredient_allergen` |
| **Result** | Catalogue loaded; kiosk displays the home screen |

---

### 3.2 COMPOSE_CART

| Field | Value |
|-------|-------|
| **Triggering event** | Customer selects a product or a menu on the kiosk |
| **Actor** | CUSTOMER |
| **Synchronisation** | Repeatable event (OR: add product, add menu, change quantity, remove item, choose menu slot, choose format Normal/Maxi, add/remove ingredient modifier) |
| **Condition** | The selected product or menu has `is_available=1` |
| **Operation** | COMPOSE_CART |
| **Description** | In-memory cart construction: add an item (standalone product or menu), select slot products (`order_item_selection`), optionally modify ingredients (`order_item_modifier`), choose Normal or Maxi format for menus, recalculate TTC total. The cart is a volatile client-side structure; no database write at this stage. |
| **MCD entities** | R: `product`, `menu`, `menu_slot`, `menu_slot_option`, `ingredient`, `product_ingredient` — W: none (volatile front-end state) |
| **Result** | Cart updated, total recalculated, summary displayed |

---

### 3.3 CREATE_ORDER

| Field | Value |
|-------|-------|
| **Triggering events** | 1. Customer confirms cart (presses "Validate") AND 2. Customer enters their order number (RNCP payment substitute) |
| **Actor** | CUSTOMER |
| **Synchronisation** | AND (both actions required) |
| **Condition** | Cart contains at least 1 item. The order number entered is non-empty. |
| **Operation** | CREATE_ORDER |
| **Description** | Atomic order creation: INSERT `customer_order` with status `pending_payment`, source `kiosk`, snapshot of HT/VAT/TTC totals (computed line by line using `vat_rate` snapshotted per item). INSERT `order_item` lines with `label_snapshot`, `unit_price_cents_snapshot`, `vat_rate_snapshot`. INSERT `order_item_selection` for each slot filled in a menu item. INSERT `order_item_modifier` for each ingredient modification. Decrement `ingredient.stock_quantity` for each ingredient consumed (adjusted by modifiers: remove => no decrement; add => extra decrement); INSERT one `stock_movement` row of type `sale` per affected ingredient unit. Stock decrements and order insert are within the same transaction. After the customer enters their order number, the status transitions `pending_payment -> paid` within the same transaction; `paid_at` is set. The system generates the order number in format `K-YYYY-MM-DD-NNN`. |
| **MCD entities** | R: `product`, `menu`, `ingredient`, `product_ingredient` (snapshot) — W: `customer_order` (INSERT status `pending_payment` then UPDATE status `paid`, `paid_at`), `order_item` (INSERT N lines), `order_item_selection` (INSERT per menu slot chosen), `order_item_modifier` (INSERT per modification), `ingredient` (UPDATE stock_quantity), `stock_movement` (INSERT type `sale` per unit) |
| **Result** | Order created (status `paid` at end of operation), order number displayed to customer, logical event ORDER_CREATED emitted toward the preparation domain |

---

### 3.4 DISPLAY_CONFIRMATION

| Field | Value |
|-------|-------|
| **Triggering event** | ORDER_CREATED (API response 201 after CREATE_ORDER) |
| **Actor** | SYS |
| **Synchronisation** | None |
| **Condition** | API response contains an id, an order_number and status `paid` |
| **Operation** | DISPLAY_CONFIRMATION |
| **Description** | Display of the confirmation screen on the kiosk with the order number. The kiosk then resets for the next customer. |
| **MCD entities** | R: none (data is in the API response) |
| **Result** | Confirmation screen displayed; kiosk available for next order |

---

## 4. Domain 2 — Order lifecycle (counter and drive)

### 4.1 CREATE_COUNTER_ORDER

| Field | Value |
|-------|-------|
| **Triggering event** | A counter or drive staff member initiates a new order from the back-office |
| **Actor** | COUNTER or DRIVE |
| **Synchronisation** | None |
| **Condition** | The actor is authenticated and holds permission `order.create`. The `source` is `counter` or `drive` (auto-tagged from `role.order_source`). |
| **Operation** | CREATE_COUNTER_ORDER |
| **Description** | Manual order composition via the back-office: select products and menus, choose service mode (`dine_in`/`takeaway`/`drive`), fill menu slots, add ingredient modifiers. Identical creation logic to CREATE_ORDER (snapshot, stock decrement in same transaction, atomic `pending_payment -> paid` transition). The `source` is auto-tagged from `role.order_source` (counter -> `counter`, drive -> `drive`). Order number format: `C-YYYY-MM-DD-NNN` (counter) or `D-YYYY-MM-DD-NNN` (drive). Cross-constraint: if `source = 'drive'` then `service_mode = 'drive'` (verified at creation). |
| **MCD entities** | R: `product`, `menu`, `menu_slot`, `menu_slot_option`, `ingredient`, `product_ingredient` — W: `customer_order` (INSERT status `pending_payment` then UPDATE status `paid`, `paid_at`), `order_item`, `order_item_selection`, `order_item_modifier`, `ingredient` (stock decrement), `stock_movement` (INSERT type `sale`) |
| **Result** | Order created (status `paid`), order number communicated to customer |

---

## 5. Domain 3 — Preparation display (kitchen)

### 5.1 LIST_ORDERS_DISPLAY

| Field | Value |
|-------|-------|
| **Triggering event** | Kitchen staff accesses or refreshes the preparation display |
| **Actor** | KITCHEN (or COUNTER, DRIVE, ADMIN) |
| **Synchronisation** | None |
| **Condition** | The actor is authenticated and holds permission `order.read`. |
| **Operation** | LIST_ORDERS_DISPLAY |
| **Description** | Read `customer_order` rows with status `paid`, filtered by sources visible to the actor's role (from `role_visible_source`): kitchen sees all sources; counter sees kiosk+counter; drive sees drive. Orders are sorted by `paid_at` ascending (oldest first). For each order, display: order number, source, content (`order_item` with `label_snapshot`, `quantity`, format, slot selections, ingredient modifiers). KDS colour is computed from `now - paid_at` against the SLA threshold (approx. 10 min), not stored. Kitchen staff performs no status transition — this is a read-only operation. |
| **MCD entities** | R: `customer_order` (status=`paid`), `order_item`, `order_item_selection`, `order_item_modifier`, `role_visible_source` |
| **Result** | Preparation display list shown, sorted by payment time ascending |

---

## 6. Domain 4 — Delivery to customer

### 6.1 DELIVER_ORDER

| Field | Value |
|-------|-------|
| **Triggering events** | 1. The order is at status `paid` AND 2. Counter or drive staff clicks "Delivered" |
| **Actor** | COUNTER or DRIVE |
| **Synchronisation** | AND |
| **Condition** | The order has status `paid`. The actor holds permission `order.deliver`. The actor's role is consistent with the order source (counter staff handles kiosk+counter orders; drive staff handles drive orders — filtered by role_visible_source). |
| **Operation** | DELIVER_ORDER |
| **Description** | Single-gesture transition `paid -> delivered`. Sets `delivered_at = NOW()`. The order moves to history. This operation replaces the v0.1 two-step sequence (mark-ready then deliver); the kitchen's visual confirmation (KDS) is sufficient before this action. |
| **MCD entities** | W: `customer_order` (UPDATE status `paid` -> `delivered`, `delivered_at = NOW()`) |
| **Result** | Order at status `delivered`, lifecycle complete |

---

## 7. Domain 5 — Cancellation

### 7.1 CANCEL_ORDER

| Field | Value |
|-------|-------|
| **Triggering event** | An authorised actor requests cancellation of an order |
| **Actor** | COUNTER, DRIVE, or ADMIN |
| **Synchronisation** | None |
| **Condition** | The order exists. `customer_order.status` is in `['pending_payment', 'paid']`. Terminal statuses `delivered` and `cancelled` cannot transition to `cancelled`. The actor holds permission `order.cancel`. |
| **Operation** | CANCEL_ORDER |
| **Description** | Transition from current status to `cancelled`. Sets `cancelled_at = NOW()`. The order is retained in the database for history and stats (no physical deletion). If the current status is `paid`, stock is re-credited: for each ingredient consumed by the order (accounting for modifiers), `ingredient.stock_quantity` is incremented; one `stock_movement` row of type `cancellation` is inserted per affected ingredient unit. Stock re-credit and status update are within the same transaction. |
| **MCD entities** | R: `order_item`, `order_item_modifier`, `ingredient`, `product_ingredient` — W: `customer_order` (UPDATE status -> `cancelled`, `cancelled_at = NOW()`), `ingredient` (UPDATE stock_quantity, conditional on status `paid`), `stock_movement` (INSERT type `cancellation`, conditional on status `paid`) |
| **Result** | Order at status `cancelled`, visible in admin history |

---

## 8. Domain 6 — Catalogue management

### 8.1 CREATE_PRODUCT

| Field | Value |
|-------|-------|
| **Triggering event** | Admin or manager submits the product creation form |
| **Actor** | ADMIN or MANAGER |
| **Synchronisation** | None |
| **Condition** | Actor holds permission `product.create`. Target category exists and `is_active=1`. `name` is non-empty. `price_cents > 0`. |
| **Operation** | CREATE_PRODUCT |
| **Description** | INSERT a new `product` with its category, name, price in cents, VAT rate in per-mille (`vat_rate`: 100=10%, 55=5.5%, default 100), optional image path. `is_available=1` by default. |
| **MCD entities** | R: `category` (FK validation) — W: `product` (INSERT) |
| **Result** | Product created, redirect to product list |

---

### 8.2 UPDATE_PRODUCT

| Field | Value |
|-------|-------|
| **Triggering event** | Admin or manager submits the product update form |
| **Actor** | ADMIN or MANAGER |
| **Synchronisation** | None |
| **Condition** | Actor holds permission `product.update`. Product exists. New values respect constraints (`price_cents > 0`, non-empty name). |
| **Operation** | UPDATE_PRODUCT |
| **Description** | UPDATE modifiable columns (`name`, `description`, `price_cents`, `vat_rate`, `image_path`, `is_available`, `display_order`, `category_id`). Snapshots already stored in `order_item` are not affected (historical integrity guaranteed by design). |
| **MCD entities** | W: `product` (UPDATE) |
| **Result** | Product updated, product list refreshed |

---

### 8.3 DELETE_PRODUCT

| Field | Value |
|-------|-------|
| **Triggering event** | Admin confirms deletion of a product |
| **Actor** | ADMIN |
| **Synchronisation** | None |
| **Condition** | Actor holds permission `product.delete`. Product is not a slot option in any `menu_slot_option` (FK `ON DELETE RESTRICT`). Product is not referenced in any `order_item` historical line (FK `ON DELETE RESTRICT`). Preliminary check required. |
| **Operation** | DELETE_PRODUCT |
| **Description** | Physical deletion of the product if no FK constraint blocks. If the product is referenced in a menu slot or historical order line, deletion is blocked. The recommended alternative is to deactivate (`is_available=0`). Also blocks if the product is the `burger_product_id` of any `menu`. |
| **MCD entities** | W: `product` (DELETE — blocked if referenced in `menu_slot_option`, `order_item`, or `menu.burger_product_id`) |
| **Result** | Product deleted OR error "product in use" |

---

### 8.4 CREATE_MENU

| Field | Value |
|-------|-------|
| **Triggering event** | Admin or manager submits the menu creation form with its slot configuration |
| **Actor** | ADMIN or MANAGER |
| **Synchronisation** | None |
| **Condition** | Actor holds permission `menu.create`. `name` is non-empty. `price_normal_cents > 0`, `price_maxi_cents > 0`. `burger_product_id` references an existing product. At least one slot is defined with at least one option. |
| **Operation** | CREATE_MENU |
| **Description** | Transaction: INSERT `menu` (with `burger_product_id`, `price_normal_cents`, `price_maxi_cents`), then INSERT `menu_slot` rows (one per slot: drink, side, sauce...), then INSERT `menu_slot_option` rows (eligible products per slot). |
| **MCD entities** | R: `product` (burger FK validation, slot options validation), `category` — W: `menu` (INSERT), `menu_slot` (INSERT), `menu_slot_option` (INSERT) |
| **Result** | Menu created with its slot configuration, visible on the kiosk |

---

### 8.5 UPDATE_MENU

| Field | Value |
|-------|-------|
| **Triggering event** | Admin or manager submits the menu update form |
| **Actor** | ADMIN or MANAGER |
| **Synchronisation** | None |
| **Condition** | Actor holds permission `menu.update`. Menu exists. Updated configuration preserves at least one slot with at least one option. |
| **Operation** | UPDATE_MENU |
| **Description** | UPDATE `menu` columns. If slot configuration is modified: DELETE all `menu_slot_option` rows for this menu's slots, DELETE `menu_slot` rows, then re-INSERT (delete-and-reinsert pattern, atomic in transaction). Snapshots in `order_item` are not affected. |
| **MCD entities** | W: `menu` (UPDATE), `menu_slot` (DELETE + INSERT), `menu_slot_option` (DELETE + INSERT) |
| **Result** | Menu updated |

---

### 8.6 DELETE_MENU

| Field | Value |
|-------|-------|
| **Triggering event** | Admin confirms deletion of a menu |
| **Actor** | ADMIN |
| **Synchronisation** | None |
| **Condition** | Actor holds permission `menu.delete`. Menu is not referenced in any `order_item` historical line (FK `ON DELETE RESTRICT`). Preliminary check required. |
| **Operation** | DELETE_MENU |
| **Description** | If no `order_item` references this menu: DELETE `menu_slot_option` (CASCADE from `menu_slot`), DELETE `menu_slot` (CASCADE from `menu`), DELETE `menu`. If historical references exist, propose deactivation (`is_available=0`) instead. |
| **MCD entities** | W: `menu_slot_option` (DELETE CASCADE), `menu_slot` (DELETE CASCADE), `menu` (DELETE — blocked if referenced in `order_item`) |
| **Result** | Menu deleted OR error "menu present in historical orders" |

---

### 8.7 MANAGE_CATEGORY

| Field | Value |
|-------|-------|
| **Triggering event** | Admin or manager creates, updates, or deactivates a category |
| **Actor** | ADMIN or MANAGER |
| **Synchronisation** | OR (create, update, deactivation) |
| **Condition** | Actor holds permission `category.manage`. For deactivation: products and menus in the category are not auto-deactivated in DB (no CASCADE on `is_active`); the application layer proposes deactivating child products/menus. |
| **Operation** | MANAGE_CATEGORY |
| **Description** | CRUD on `category`. Deactivation (`is_active=0`) hides the category and its products from the kiosk without physical deletion. Physical deletion is blocked if products or menus reference this category (FK `ON DELETE RESTRICT`). |
| **MCD entities** | W: `category` (INSERT / UPDATE / conditional DELETE) |
| **Result** | Category created / updated / deactivated |

---

### 8.8 MANAGE_INGREDIENT

| Field | Value |
|-------|-------|
| **Triggering event** | Admin or manager creates, updates, or deactivates an ingredient; or manages product composition (`product_ingredient`) or allergen mapping (`ingredient_allergen`) |
| **Actor** | ADMIN or MANAGER |
| **Synchronisation** | OR (create ingredient, update ingredient, update composition, update allergen mapping) |
| **Condition** | Actor holds permission `ingredient.manage`. |
| **Operation** | MANAGE_INGREDIENT |
| **Description** | CRUD on `ingredient` (name, unit, pack_size, pack_label, low_stock_threshold, is_active). Manage `product_ingredient` composition (quantity_normal, quantity_maxi, is_removable, is_addable, extra_price_cents) for any product. Manage `ingredient_allergen` mapping (14 EU regulated allergens). Deactivating an ingredient (`is_active=0`) hides it from the configurator without deletion. Physical deletion of `ingredient` is blocked if referenced in `product_ingredient` (FK `ON DELETE RESTRICT`) or `stock_movement` (FK `ON DELETE RESTRICT`). |
| **MCD entities** | R: `product` (FK validation), `allergen` (FK validation) — W: `ingredient` (INSERT/UPDATE/DELETE conditional), `product_ingredient` (INSERT/UPDATE/DELETE), `ingredient_allergen` (INSERT/DELETE) |
| **Result** | Ingredient / composition / allergen mapping updated |

---

## 9. Domain 7 — Stock management

### 9.1 RESTOCK

| Field | Value |
|-------|-------|
| **Triggering event** | Manager or admin records a delivery of ingredient packs |
| **Actor** | MANAGER or ADMIN |
| **Synchronisation** | None |
| **Condition** | Actor holds permission `stock.manage`. Ingredient exists and `is_active=1`. Number of packs `N >= 1`. |
| **Operation** | RESTOCK |
| **Description** | UPDATE `ingredient.stock_quantity += N * pack_size`. INSERT one `stock_movement` row: type `restock`, delta `+= N * pack_size`, `user_id` of the actor, optional `note` (e.g. delivery reference). Both writes are in the same transaction. |
| **MCD entities** | R: `ingredient` — W: `ingredient` (UPDATE stock_quantity), `stock_movement` (INSERT type `restock`) |
| **Result** | Stock incremented, movement logged |

---

### 9.2 INVENTORY_COUNT

| Field | Value |
|-------|-------|
| **Triggering event** | A staff member or manager records the result of a physical inventory count |
| **Actor** | KITCHEN, COUNTER, DRIVE, MANAGER, or ADMIN |
| **Synchronisation** | None |
| **Condition** | Actor holds permission `stock.count`. Ingredient exists. Physical count `actual_quantity >= 0`. |
| **Operation** | INVENTORY_COUNT |
| **Description** | Compute `delta = actual_quantity - ingredient.stock_quantity` (may be negative or positive). UPDATE `ingredient.stock_quantity = actual_quantity`. INSERT one `stock_movement` row: type `inventory_correction`, delta = computed discrepancy, `user_id` of the actor, optional `note`. Both writes in the same transaction. |
| **MCD entities** | R: `ingredient` (read current stock_quantity) — W: `ingredient` (UPDATE stock_quantity), `stock_movement` (INSERT type `inventory_correction`) |
| **Result** | Stock reconciled to physical count, discrepancy logged |

---

### 9.3 READ_STOCK

| Field | Value |
|-------|-------|
| **Triggering event** | An authorised actor accesses the stock view |
| **Actor** | KITCHEN, COUNTER, DRIVE, MANAGER, or ADMIN |
| **Synchronisation** | None |
| **Condition** | Actor holds permission `stock.read`. |
| **Operation** | READ_STOCK |
| **Description** | Read `ingredient` list with current `stock_quantity`, `low_stock_threshold`, `pack_size`, `pack_label`. Low-stock alert computed at display time: `stock_quantity <= low_stock_threshold`. Optional: read `stock_movement` history for a given ingredient, filtered by date range. |
| **MCD entities** | R: `ingredient`, `stock_movement` (optional history) |
| **Result** | Stock list displayed with low-stock indicators |

---

## 10. Domain 8 — User and role management (admin)

### 10.1 CREATE_USER

| Field | Value |
|-------|-------|
| **Triggering event** | Admin submits the user creation form |
| **Actor** | ADMIN |
| **Synchronisation** | None |
| **Condition** | Actor holds permission `user.create`. Email does not already exist in `user.email` (UNIQUE constraint). A valid and active `role_id` is selected. |
| **Operation** | CREATE_USER |
| **Description** | INSERT user with argon2id password hash. Email is unique. `role_id` is mandatory (FK NOT NULL). `is_active=1` by default. `last_login_at=NULL` at creation. |
| **MCD entities** | R: `role` (FK validation) — W: `user` (INSERT) |
| **Result** | User created, can log into the back-office |

---

### 10.2 UPDATE_USER

| Field | Value |
|-------|-------|
| **Triggering event** | Admin submits the user update form |
| **Actor** | ADMIN |
| **Synchronisation** | None |
| **Condition** | Actor holds permission `user.update`. User exists. If a new password is provided, it is re-hashed. |
| **Operation** | UPDATE_USER |
| **Description** | UPDATE modifiable fields (`first_name`, `last_name`, `email`, `role_id`, `is_active`). If a new password is supplied, it replaces the existing hash (argon2id rehash). |
| **MCD entities** | W: `user` (UPDATE) |
| **Result** | User updated |

---

### 10.3 DEACTIVATE_USER

| Field | Value |
|-------|-------|
| **Triggering event** | Admin clicks "Deactivate" for a user |
| **Actor** | ADMIN |
| **Synchronisation** | None |
| **Condition** | Actor holds permission `user.deactivate`. Admin cannot deactivate their own account (application-level protection). |
| **Operation** | DEACTIVATE_USER |
| **Description** | UPDATE `is_active=0`. The user's active session is invalidated on next access (middleware checks `is_active=1` on each authenticated request). User is not deleted; history remains traceable. |
| **MCD entities** | W: `user` (UPDATE is_active=0) |
| **Result** | User deactivated, back-office access blocked |

---

### 10.4 MANAGE_RBAC

| Field | Value |
|-------|-------|
| **Triggering event** | Admin modifies permission assignments for a role, or creates / updates a custom role |
| **Actor** | ADMIN |
| **Synchronisation** | OR (update role permissions, create custom role, update role attributes) |
| **Condition** | Actor holds permission `role.manage`. Selected permissions exist in the `permission` catalogue. |
| **Operation** | MANAGE_RBAC |
| **Description** | Update `role_permission` for a given role: DELETE existing assignments, INSERT new ones (delete-and-reinsert, atomic in transaction). Permissions themselves are static (declared in migration, not modifiable via UI). Also covers: CREATE/UPDATE custom `role` (code, label, description, default_route, order_source), UPDATE `role_visible_source` (visible dashboard sources for the role). RBAC architecture rule: application code tests permissions, not role names — adding a new role with correct permissions requires no code change. |
| **MCD entities** | R: `role`, `permission` — W: `role_permission` (DELETE + INSERT), `role` (INSERT/UPDATE for custom roles), `role_visible_source` (INSERT/DELETE) |
| **Result** | RBAC matrix updated, effective immediately for new requests of users bearing this role |

---

## 11. Domain 9 — Stats and KPI

### 11.1 READ_STATS

| Field | Value |
|-------|-------|
| **Triggering event** | Manager or admin accesses the stats dashboard |
| **Actor** | MANAGER or ADMIN |
| **Synchronisation** | None |
| **Condition** | Actor holds permission `stats.read`. |
| **Operation** | READ_STATS |
| **Description** | Aggregate queries on `customer_order` and `order_item`. Key aggregations: order count and revenue (TTC) by `service_day` (computed with CASE WHEN HOUR(created_at) < 10 THEN DATE(created_at) - INTERVAL 1 DAY ELSE DATE(created_at) END; cutoff at 10:00); top products by `label_snapshot` COUNT in `order_item`; cancellation rate; average delivery time `delivered_at - paid_at`; breakdown by `source` and `service_mode`. Queries exclude cancelled orders from revenue sums but include them in volume counts. No additional stored column for `service_day`; computation at query time. |
| **MCD entities** | R: `customer_order`, `order_item` |
| **Result** | Stats dashboard displayed |

---

## 12. Domain 10 — Back-office authentication

### 12.1 AUTHENTICATE_USER

| Field | Value |
|-------|-------|
| **Triggering event** | An actor submits the login form |
| **Actor** | COUNTER / DRIVE / KITCHEN / MANAGER / ADMIN |
| **Synchronisation** | None |
| **Condition** | Email exists in database. Password matches argon2id hash. User `is_active=1`. |
| **Operation** | AUTHENTICATE_USER |
| **Description** | Credential verification. If valid: session ID regeneration (protection against session fixation), storage of `user_id` and `role_id` in session, UPDATE `last_login_at`. Idle timeout: 4h. Absolute timeout: 10h. Redirect to `role.default_route`. |
| **MCD entities** | R: `user` (verification), `role` (load permissions, default_route), `role_permission` — W: `user` (UPDATE last_login_at) |
| **Result** | Session opened, redirect to role-specific default view |

---

### 12.2 LOGOUT_USER

| Field | Value |
|-------|-------|
| **Triggering event** | Actor clicks "Logout" OR session expires |
| **Actor** | COUNTER / DRIVE / KITCHEN / MANAGER / ADMIN / SYS (expiry) |
| **Synchronisation** | OR |
| **Condition** | A valid session is open |
| **Operation** | LOGOUT_USER |
| **Description** | PHP session destruction (`session_destroy()`). Session deleted server-side. Session cookie invalidated. |
| **MCD entities** | No database write (session management is in PHP native, outside DB for this project) |
| **Result** | Session destroyed, redirect to login page |

---

## 13. State machine — customer_order.status

Summary of transitions covered by MCT operations.

```
               [CUSTOMER / COUNTER / DRIVE]
               CREATE_ORDER
               CREATE_COUNTER_ORDER
                      |
                      v
           [ pending_payment ]  (order composed, payment pending)
                      |
    [CUSTOMER / COUNTER / DRIVE] payment confirmed
    (atomic within CREATE_ORDER / CREATE_COUNTER_ORDER)
                      |
                      v
                 [ paid ]
                      |
      [COUNTER / DRIVE] DELIVER_ORDER
                      |
                      v
               [ delivered ]  (terminal, cannot be cancelled)


  From pending_payment / paid:
  [COUNTER, DRIVE, or ADMIN] CANCEL_ORDER
                      |
                      v
               [ cancelled ]  (terminal)
```

**Note on the `pending_payment -> paid` transition**: in the RNCP context, payment is
replaced by the customer entering their order number (kiosk) or by staff validation
(counter/drive). The transition is atomic within CREATE_ORDER and CREATE_COUNTER_ORDER.
The `pending_payment` status is not observable outside the transaction.

**Dropped from v0.1**: `preparing` and `ready` states; `MARK_IN_PREPARATION` and `MARK_READY`
operations. Kitchen staff have a read-only view of `paid` orders (LIST_ORDERS_DISPLAY). The
single delivery action (DELIVER_ORDER) collapses the v0.1 three-step sequence into one gesture.

---

## 14. Operations summary table

| # | Operation | Domain | Actor | W Entities | R Entities |
|---|-----------|--------|-------|------------|------------|
| 1 | LOAD_CATALOGUE | Order kiosk | CUSTOMER | — | category, product, menu, menu_slot, menu_slot_option, ingredient, allergen, ingredient_allergen |
| 2 | COMPOSE_CART | Order kiosk | CUSTOMER | — (volatile) | product, menu, menu_slot, menu_slot_option, ingredient, product_ingredient |
| 3 | CREATE_ORDER | Order kiosk | CUSTOMER | customer_order, order_item, order_item_selection, order_item_modifier, ingredient, stock_movement | product, menu, ingredient, product_ingredient |
| 4 | DISPLAY_CONFIRMATION | Order kiosk | SYS | — | — |
| 5 | CREATE_COUNTER_ORDER | Order counter/drive | COUNTER/DRIVE | customer_order, order_item, order_item_selection, order_item_modifier, ingredient, stock_movement | product, menu, menu_slot, menu_slot_option, ingredient, product_ingredient |
| 6 | LIST_ORDERS_DISPLAY | Preparation | KITCHEN/COUNTER/DRIVE/ADMIN | — | customer_order, order_item, order_item_selection, order_item_modifier, role_visible_source |
| 7 | DELIVER_ORDER | Delivery | COUNTER/DRIVE | customer_order | — |
| 8 | CANCEL_ORDER | Cancellation | COUNTER/DRIVE/ADMIN | customer_order, ingredient, stock_movement | order_item, order_item_modifier, ingredient, product_ingredient |
| 9 | CREATE_PRODUCT | Catalogue | ADMIN/MANAGER | product | category |
| 10 | UPDATE_PRODUCT | Catalogue | ADMIN/MANAGER | product | — |
| 11 | DELETE_PRODUCT | Catalogue | ADMIN | product | menu_slot_option, order_item, menu |
| 12 | CREATE_MENU | Catalogue | ADMIN/MANAGER | menu, menu_slot, menu_slot_option | product, category |
| 13 | UPDATE_MENU | Catalogue | ADMIN/MANAGER | menu, menu_slot, menu_slot_option | — |
| 14 | DELETE_MENU | Catalogue | ADMIN | menu_slot_option, menu_slot, menu | order_item |
| 15 | MANAGE_CATEGORY | Catalogue | ADMIN/MANAGER | category | product, menu |
| 16 | MANAGE_INGREDIENT | Catalogue | ADMIN/MANAGER | ingredient, product_ingredient, ingredient_allergen | product, allergen |
| 17 | RESTOCK | Stock | MANAGER/ADMIN | ingredient, stock_movement | ingredient |
| 18 | INVENTORY_COUNT | Stock | KITCHEN/COUNTER/DRIVE/MANAGER/ADMIN | ingredient, stock_movement | ingredient |
| 19 | READ_STOCK | Stock | KITCHEN/COUNTER/DRIVE/MANAGER/ADMIN | — | ingredient, stock_movement |
| 20 | CREATE_USER | RBAC | ADMIN | user | role |
| 21 | UPDATE_USER | RBAC | ADMIN | user | — |
| 22 | DEACTIVATE_USER | RBAC | ADMIN | user | — |
| 23 | MANAGE_RBAC | RBAC | ADMIN | role_permission, role, role_visible_source | role, permission |
| 24 | READ_STATS | Stats | MANAGER/ADMIN | — | customer_order, order_item |
| 25 | AUTHENTICATE_USER | Auth | ALL BACK | user | user, role, role_permission |
| 26 | LOGOUT_USER | Auth | ALL BACK | — | — |

**Total: 26 operations** covering the complete Wakdo business lifecycle.

---

## 15. MCT -> MCD cross-validation (mantra #34)

Verification that each MCD entity participates in at least one MCT operation.

| MCD entity | Operations that read | Operations that write | Coverage |
|------------|---------------------|----------------------|----------|
| `category` | 1, 9, 12, 15 | 15 | OK |
| `product` | 1, 2, 3, 5, 9, 11, 12 | 9, 10, 11 | OK |
| `menu` | 1, 2, 3, 5, 12, 14 | 12, 13, 14 | OK |
| `menu_slot` | 1, 2, 5 | 12, 13, 14 | OK |
| `menu_slot_option` | 1, 2, 5, 11 | 12, 13, 14 | OK |
| `ingredient` | 1, 2, 3, 5, 8, 16, 17, 18, 19 | 3, 5, 8, 16, 17, 18 | OK |
| `product_ingredient` | 2, 3, 5, 8 | 16 | OK |
| `allergen` | 1 | — (static seed) | OK (*) |
| `ingredient_allergen` | 1 | 16 | OK |
| `customer_order` | 6, 8, 24 | 3, 5, 7, 8 | OK |
| `order_item` | 6, 8, 14, 24 | 3, 5 | OK |
| `order_item_selection` | 6 | 3, 5 | OK |
| `order_item_modifier` | 6, 8 | 3, 5 | OK |
| `user` | 25 | 20, 21, 22, 25 | OK |
| `role` | 20, 23, 25 | 23 | OK |
| `role_visible_source` | 6 | 23 | OK |
| `permission` | 23 | — (static seed) | OK (*) |
| `role_permission` | 25 | 23 | OK |
| `stock_movement` | 19 | 3, 5, 8, 17, 18 | OK |

(*) `allergen` and `permission` are read-only at the MCT level: their values are declared
in seed migrations and are not modifiable via the UI. `allergen` is managed indirectly
via `ingredient_allergen` in MANAGE_INGREDIENT.

**Conclusion**: 19/19 entities covered. MCT <-> MCD consistency validated.
