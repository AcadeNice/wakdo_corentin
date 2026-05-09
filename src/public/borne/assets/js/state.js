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
 * If an identical item (same id + type) already exists, increments quantity.
 * @param {{ id: number, type: string, categorie: string, libelle: string, prix_cents: number, quantite: number, image: string }} item
 */
export function addToCart(item) {
    const cart = getCart();
    const existing = cart.find(c => c.id === item.id && c.type === item.type);
    if (existing) {
        existing.quantite += item.quantite ?? 1;
    } else {
        cart.push({ quantite: 1, ...item });
    }
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
 * Returns the sum of (quantite * prix_cents) for all items in the cart.
 * Result is in integer centimes, TTC before any display formatting.
 * @returns {number}
 */
export function getTotalCents() {
    return getCart().reduce((sum, item) => sum + item.prix_cents * item.quantite, 0);
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
