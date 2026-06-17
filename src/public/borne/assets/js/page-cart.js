/*
 * page-cart.js — Shopping cart screen.
 *
 * Displays all cart lines with quantity controls and totals.
 * Handles two item shapes:
 *   - Simple product: { id, type, libelle, prix_cents, quantite, image }
 *   - Composed menu:  { ...above, composition: {...}, supplement_cents: number }
 *
 * Menu lines render a composition breakdown beneath the product name.
 * Simple product lines render as before (no composition block).
 *
 * TVA: 10% (taux normal restauration, France 2024 — simplification MVP).
 * TODO: verify exact applicable TVA rate with an accountant in P3.
 *       The real rate depends on sur-place vs a-emporter, alcohol content, etc.
 *
 * The total displayed is TTC (tax inclusive) because French consumer law
 * requires prices shown to end-consumers to include all taxes.
 */

import { getCart, removeFromCart, updateQuantity, getTotalCents, computeMenuLineCents, clearCart, formatPrice, escHtml } from './state.js';
import { refreshCartBadge } from './nav.js';

/* TVA rate used for display breakdown only — stored prices are already TTC */
const TVA_RATE = 0.10;

const cartList    = document.getElementById('cart-list');
const emptyBlock  = document.getElementById('cart-empty');
const summaryBlock= document.getElementById('cart-summary');
const totalTTC    = document.getElementById('total-ttc');
const totalHT     = document.getElementById('total-ht');
const totalTVA    = document.getElementById('total-tva');
const payBtn      = document.getElementById('pay-btn');
const abandonBtn  = document.getElementById('abandon-btn');

function renderCart() {
    const items = getCart();
    refreshCartBadge();

    if (!items.length) {
        cartList.innerHTML    = '';
        emptyBlock.hidden     = false;
        summaryBlock.hidden   = true;
        // pay-btn est un <a> : `.disabled` n'existe pas dessus, il faut piloter
        // aria-disabled (sinon le bouton reste annonce desactive panier rempli).
        if (payBtn) payBtn.setAttribute('aria-disabled', 'true');
        return;
    }

    emptyBlock.hidden   = true;
    summaryBlock.hidden = false;
    if (payBtn) payBtn.setAttribute('aria-disabled', 'false');

    cartList.innerHTML = '';
    items.forEach((item, index) => {
        const isMenu = item.type === 'menu';
        const lineTotalCents = isMenu
            ? computeMenuLineCents(item)
            : item.prix_cents * item.quantite;

        const row = document.createElement('li');
        row.className = 'cart-line';
        row.setAttribute('aria-label', `${item.libelle}, quantite ${item.quantite}`);

        row.innerHTML = `
            <img
                class="cart-line__image"
                src="${escHtml(item.image)}"
                alt="${escHtml(item.libelle)}"
                onerror="this.src='assets/images/ui/logo.png'; this.alt='Image non disponible';"
            >
            <div class="cart-line__info">
                <span class="cart-line__name">${escHtml(item.libelle)}</span>
                <span class="cart-line__unit-price">${formatPrice(item.prix_cents)} / unite${isMenu && (item.supplement_cents ?? 0) > 0 ? ` + ${formatPrice(item.supplement_cents)} suppl.` : ''}</span>
                ${isMenu && item.composition ? renderCompositionBlock(item) : ''}
            </div>
            <div class="cart-line__qty" role="group" aria-label="Quantite de ${escHtml(item.libelle)}">
                <button
                    class="qty-btn qty-btn--minus"
                    data-index="${index}"
                    aria-label="Diminuer la quantite de ${escHtml(item.libelle)}"
                    type="button"
                >-</button>
                <span class="qty-value" aria-live="polite">${item.quantite}</span>
                <button
                    class="qty-btn qty-btn--plus"
                    data-index="${index}"
                    aria-label="Augmenter la quantite de ${escHtml(item.libelle)}"
                    type="button"
                >+</button>
            </div>
            <span class="cart-line__total">${formatPrice(lineTotalCents)}</span>
            <button
                class="cart-line__remove"
                data-index="${index}"
                aria-label="Supprimer ${escHtml(item.libelle)} du panier"
                type="button"
            >
                <img src="assets/images/ui/trash.png" alt="" aria-hidden="true" width="24" height="24">
            </button>
        `;
        cartList.appendChild(row);
    });

    /* Attach event listeners after render */
    cartList.querySelectorAll('.qty-btn--minus').forEach(btn => {
        btn.addEventListener('click', () => {
            const idx = parseInt(btn.dataset.index, 10);
            const cart = getCart();
            updateQuantity(idx, cart[idx].quantite - 1);
            renderCart();
        });
    });

    cartList.querySelectorAll('.qty-btn--plus').forEach(btn => {
        btn.addEventListener('click', () => {
            const idx = parseInt(btn.dataset.index, 10);
            const cart = getCart();
            updateQuantity(idx, cart[idx].quantite + 1);
            renderCart();
        });
    });

    cartList.querySelectorAll('.cart-line__remove').forEach(btn => {
        btn.addEventListener('click', () => {
            const idx = parseInt(btn.dataset.index, 10);
            removeFromCart(idx);
            renderCart();
        });
    });

    /* Update totals */
    const ttcCents = getTotalCents();
    /* Back-calculate HT from TTC (prices assumed to be TTC already) */
    const htCents  = Math.round(ttcCents / (1 + TVA_RATE));
    const tvaCents = ttcCents - htCents;

    if (totalTTC) totalTTC.textContent = formatPrice(ttcCents);
    if (totalHT)  totalHT.textContent  = formatPrice(htCents);
    if (totalTVA) totalTVA.textContent = formatPrice(tvaCents);
}

/**
 * Builds the composition breakdown HTML for a menu cart line.
 * Renders burger (with personalisation options), accompagnement with taille,
 * boisson with taille, sauce, and the supplement summary if applicable.
 *
 * @param {Object} item — cart item with type === 'menu' and composition object
 * @returns {string} HTML string
 */
function renderCompositionBlock(item) {
    const c = item.composition;
    if (!c) return '';

    const burgerOpts = c.burger.options && c.burger.options.length
        ? ` (${c.burger.options.map(o => o === 'sans-oignon' ? 'sans oignon' : 'avec fromage').join(', ')})`
        : '';

    const accompTailleLabel = c.accompagnement.taille === 'G' ? ' grande' : ' normale';
    const boissonTailleLabel = c.boisson.taille === 'G' ? ' grande' : ' normale';

    const nbGrandes = (c.accompagnement.taille === 'G' ? 1 : 0) + (c.boisson.taille === 'G' ? 1 : 0);
    const supplTotal = item.supplement_cents ?? 0;

    return `
        <ul class="cart-line__composition" aria-label="Composition du menu">
            <li class="cart-line__comp-item">+ ${escHtml(c.burger.libelle)}${burgerOpts}</li>
            <li class="cart-line__comp-item">+ ${escHtml(c.accompagnement.libelle)}${accompTailleLabel}</li>
            <li class="cart-line__comp-item">+ ${escHtml(c.boisson.libelle)}${boissonTailleLabel}</li>
            <li class="cart-line__comp-item">+ ${escHtml(c.sauce.libelle)}</li>
            ${supplTotal > 0 ? `<li class="cart-line__comp-suppl">Supplement ${nbGrandes} grande(s) : +${formatPrice(supplTotal)}</li>` : ''}
        </ul>
    `;
}

if (abandonBtn) {
    abandonBtn.addEventListener('click', () => {
        clearCart();
        window.location.href = 'categories.html';
    });
}

document.addEventListener('DOMContentLoaded', renderCart);
