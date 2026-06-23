/*
 * state.js — Global client-side state for the Wakdo kiosk.
 *
 * Persists via localStorage so that navigation between pages does not
 * lose the cart or the consumption mode.
 *
 * Price convention: all values stored and computed in INTEGER CENTIMES.
 * Formatting for display is handled by formatPrice().
 *
 * TVA note: 10% applied at display time in cart/payment pages only.
 * This is a simplified rate for restaurant consumption (France 2024).
 * TODO: verify exact applicable rate with an accountant in P3 — the real
 * rate depends on sur-place vs a-emporter, alcohol content, etc.
 */

const STORAGE_KEY_MODE = 'wakdo_mode';
const STORAGE_KEY_CART = 'wakdo_cart';

/* --- Consumption mode ---------------------------------------------------- */

/**
 * Returns the stored consumption mode string or null if not yet chosen.
 * @returns {'sur-place'|'a-emporter'|null}
 */
export function getMode() {
    return localStorage.getItem(STORAGE_KEY_MODE);
}

/**
 * Persists the consumption mode chosen on the welcome screen.
 * @param {'sur-place'|'a-emporter'} mode
 */
export function setMode(mode) {
    localStorage.setItem(STORAGE_KEY_MODE, mode);
}

/* --- Cart state ---------------------------------------------------------- */

/**
 * Returns the current cart array.
 * Each item shape:
 *   { id, type: 'produit'|'menu', categorie, libelle, prix_cents, quantite, image }
 * @returns {Array}
 */
export function getCart() {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY_CART)) || [];
    } catch {
        return [];
    }
}

/**
 * Replaces the entire cart.
 * @param {Array} items
 */
export function setCart(items) {
    localStorage.setItem(STORAGE_KEY_CART, JSON.stringify(items));
}

/**
 * Appends a product or menu to the cart.
 *
 * For simple products (type !== 'menu'), merges with an existing line
 * of the same id — matching real kiosk behavior where two identical
 * sandwiches become one line with qty 2.
 *
 * For composed menus, each call always creates a new line because two
 * menus with identical base id may have different compositions (different
 * burger options, sizes, sauces). This prevents silent composition loss.
 *
 * Item shapes:
 *   Simple:  { id, type, categorie, libelle, prix_cents, quantite, image }
 *   Menu:    { ...above, composition: {...}, supplement_cents: number }
 *
 * @param {Object} item
 */
export function addToCart(item) {
    const cart = getCart();
    if (item.type !== 'menu') {
        const existing = cart.find(c => c.id === item.id && c.type === item.type);
        if (existing) {
            existing.quantite += item.quantite ?? 1;
            setCart(cart);
            return;
        }
    }
    cart.push({ quantite: 1, ...item });
    setCart(cart);
}

/**
 * Removes the item at the given index from the cart.
 * @param {number} index
 */
export function removeFromCart(index) {
    const cart = getCart();
    cart.splice(index, 1);
    setCart(cart);
}

/**
 * Sets the quantity for the item at the given index.
 * If qty reaches 0, the item is removed.
 * @param {number} index
 * @param {number} qty
 */
export function updateQuantity(index, qty) {
    const cart = getCart();
    if (qty <= 0) {
        cart.splice(index, 1);
    } else {
        cart[index].quantite = qty;
    }
    setCart(cart);
}

/**
 * Empties the cart completely.
 */
export function clearCart() {
    localStorage.removeItem(STORAGE_KEY_CART);
}

/* --- Totals -------------------------------------------------------------- */

/**
 * Computes the line total in centimes for a menu item including size supplements.
 * For simple product items the caller should use (prix_cents * quantite) directly.
 * @param {{ prix_cents: number, supplement_cents: number, quantite: number }} item
 * @returns {number}
 */
export function computeMenuLineCents(item) {
    return (item.prix_cents + (item.supplement_cents ?? 0)) * item.quantite;
}

/**
 * Returns the sum of all line totals in centimes.
 * Menu items include their size supplements; simple items do not carry supplements.
 * @returns {number}
 */
export function getTotalCents() {
    return getCart().reduce((sum, item) => {
        if (item.type === 'menu') {
            return sum + computeMenuLineCents(item);
        }
        return sum + item.prix_cents * item.quantite;
    }, 0);
}

/* --- Formatting helpers -------------------------------------------------- */

/**
 * Formats a centimes integer into a French locale price string.
 * Example: 490 -> "4,90 EUR"
 * @param {number} cents
 * @returns {string}
 */
export function formatPrice(cents) {
    const euros = cents / 100;
    return euros.toLocaleString('fr-FR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }) + ' EUR';
}

/**
 * Returns the item count (sum of all quantities) in the cart.
 * Used to show a badge on the cart button.
 * @returns {number}
 */
export function getCartCount() {
    return getCart().reduce((sum, item) => sum + item.quantite, 0);
}

/* --- HTML escaping ------------------------------------------------------- */

/**
 * Minimal HTML escaping for data-derived strings (product names, image paths,
 * libelles) injected into innerHTML. RG-T15 (anti-XSS) requires every catalogue
 * value rendered as HTML to be escaped. Centralised here so all page modules
 * that build markup from data escape identically; mirrors the helper that was
 * local to page-product-menu.js.
 * @param {*} str
 * @returns {string}
 */
export function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
