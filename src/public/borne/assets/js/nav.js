/*
 * nav.js — Shared navigation helpers loaded on every page.
 *
 * Responsibilities:
 *  - Inject the mode badge ("Sur place" / "A emporter") into any
 *    element with [data-mode-badge] on the page.
 *  - Sync the cart item count into any element with [data-cart-count].
 *  - Handle the mode query-string on page load (welcome -> categories handoff).
 *
 * Import this module in every page that has a header.
 */

import { getMode, setMode, getCartCount } from './state.js';

/**
 * Reads ?mode= from the current URL and persists it if present.
 * Called once on DOMContentLoaded so that the welcome -> categories
 * navigation stores the chosen mode before any render.
 */
function syncModeFromURL() {
    const params = new URLSearchParams(window.location.search);
    const modeParam = params.get('mode');
    if (modeParam === 'sur-place' || modeParam === 'a-emporter') {
        setMode(modeParam);
    }
}

/**
 * Renders the human-readable mode label into every [data-mode-badge] element.
 */
function renderModeBadge() {
    const mode = getMode();
    const label = mode === 'a-emporter' ? 'A emporter' : 'Sur place';
    document.querySelectorAll('[data-mode-badge]').forEach(el => {
        el.textContent = label;
    });
}

/**
 * Updates the cart item count badge in every [data-cart-count] element.
 * Called on load and after any cart mutation.
 */
export function refreshCartBadge() {
    const count = getCartCount();
    document.querySelectorAll('[data-cart-count]').forEach(el => {
        el.textContent = count > 0 ? String(count) : '';
        el.hidden = count === 0;
    });
}

/* Initialise on DOM ready */
document.addEventListener('DOMContentLoaded', () => {
    syncModeFromURL();
    renderModeBadge();
    refreshCartBadge();
});
