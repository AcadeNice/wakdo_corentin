/*
 * nav.js — Shared navigation helpers loaded on every page.
 *
 * Responsibilities:
 *  - Inject the mode badge ("Sur place" / "A emporter") into any
 *    element with [data-mode-badge] on the page.
 *  - Sync the cart item count into any element with [data-cart-count].
 *  - Handle the mode query-string on page load (welcome -> categories handoff).
 *  - Guard : une page au-dela de l'accueil EXIGE un mode de consommation. Sans
 *    mode (ex. localStorage vide en cours de session), la borne POSTerait
 *    service_mode:null et la commande est rejetee en 422. Sur une page profonde
 *    sans mode, on renvoie vers l'accueil pour que le mode soit (re)choisi.
 *
 * Import this module in every page that has a header.
 */

import { getMode, setMode, getCartCount } from './state.js';

const VALID_MODES = ['sur-place', 'a-emporter'];

/** Libelle humain d'un mode ; chaine vide si aucun mode valide (ne ment pas). */
export function modeLabel(mode) {
    if (mode === 'a-emporter') return 'A emporter';
    if (mode === 'sur-place') return 'Sur place';
    return '';
}

/**
 * Faut-il renvoyer vers l'accueil ? Vrai hors de l'ecran d'accueil quand aucun
 * mode de consommation valide n'est memorise. Pur (teste sans DOM). Sans cette
 * garde, atteindre une page de commande sans mode mene a service_mode:null -> 422.
 * @param {string} pathname window.location.pathname
 * @param {string|null} mode mode memorise
 * @returns {boolean}
 */
export function needsModeRedirect(pathname, mode) {
    const onWelcome = pathname === '/' || pathname.endsWith('/index.html');
    return !onWelcome && !VALID_MODES.includes(mode);
}

/**
 * Reads ?mode= from the current URL and persists it if present.
 * Called once on DOMContentLoaded so that the welcome -> categories
 * navigation stores the chosen mode before any render.
 */
function syncModeFromURL() {
    const params = new URLSearchParams(window.location.search);
    const modeParam = params.get('mode');
    if (VALID_MODES.includes(modeParam)) {
        setMode(modeParam);
    }
}

/**
 * Renders the human-readable mode label into every [data-mode-badge] element.
 */
function renderModeBadge() {
    const label = modeLabel(getMode());
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

/* Initialise on DOM ready. Garde derriere typeof document pour rester importable
 * en test pur (node sans jsdom) : modeLabel/needsModeRedirect n'ont alors aucun effet de bord. */
if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        syncModeFromURL();
        // Mode absent sur une page profonde -> retour accueil (evite le 422 service_mode:null).
        if (needsModeRedirect(window.location.pathname, getMode())) {
            window.location.replace('index.html');
            return;
        }
        renderModeBadge();
        refreshCartBadge();
    });
}
