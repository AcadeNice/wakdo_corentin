/*
 * page-payment.js — Ecran de paiement : recap + soumission REELLE de la commande (L4).
 *
 * Remplace l'ancienne simulation (redirection directe). Au choix d'un mode de
 * paiement : en sur-place, ouvre la modale chevalet (saisie du numero de table,
 * maquette ecran 9) puis soumet ; en a-emporter, soumet directement. La soumission
 * (checkout.submitOrder) cree la commande puis l'encaisse (decrement stock RG-T20).
 * Succes -> numero de commande memorise (sessionStorage) + cap sur la confirmation.
 * Echec -> message, et on reste sur la page.
 */

import { getTotalCents, formatPrice, getCart, getMode, clearCart, escHtml } from './state.js';
import { submitOrder } from './checkout.js';

const recap   = document.getElementById('payment-recap');
const errorEl = document.getElementById('payment-error');
const cardBtn = document.getElementById('pay-card');
const cashBtn = document.getElementById('pay-cash');

/** Message utilisateur (generique) a partir d'un code d'erreur API. */
function messageFor(code) {
    if (code === 'PRODUCT_UNAVAILABLE' || code === 'MENU_UNAVAILABLE') {
        return 'Un article de votre commande n\'est plus disponible. Modifiez votre panier.';
    }
    if (code === 'EMPTY_CART' || code === 'EMPTY_ORDER') {
        return 'Votre panier est vide.';
    }
    return 'Le paiement n\'a pas pu aboutir. Veuillez reessayer.';
}

function showError(msg) {
    if (!errorEl) return;
    errorEl.textContent = msg;
    errorEl.hidden = false;
}

function setBusy(busy) {
    [cardBtn, cashBtn].forEach(b => { if (b) b.disabled = busy; });
}

/* Anti double-clic : une seule tentative de checkout a la fois (sinon, en sur-place,
 * on pourrait empiler plusieurs modales chevalet). Relache sur erreur / annulation. */
let checkingOut = false;

async function doSubmit(serviceTag) {
    if (errorEl) errorEl.hidden = true;
    setBusy(true);
    try {
        const res = await submitOrder({ serviceTag });
        sessionStorage.setItem('wakdo_last_order', JSON.stringify(res));
        clearCart();
        window.location.href = 'confirmation.html';
    } catch (e) {
        checkingOut = false;
        setBusy(false);
        showError(messageFor(e.message));
        console.error('checkout error:', e);
    }
}

function startCheckout() {
    if (checkingOut) return;
    checkingOut = true;
    if (getMode() === 'sur-place') {
        openChevalet(tag => doSubmit(tag), () => { checkingOut = false; });
    } else {
        doSubmit('');
    }
}

/* --- Modale chevalet (sur-place) : saisie du numero de table -------------- */

function openChevalet(onValidate, onDismiss) {
    const overlay = document.createElement('div');
    overlay.className = 'composer-overlay';
    overlay.innerHTML = `
        <div class="composer-container chevalet" role="dialog" aria-modal="true" aria-labelledby="chevalet-title">
            <div class="composer-header">
                <h2 class="composer-title" id="chevalet-title">Pour etre servi a table</h2>
            </div>
            <div class="composer-body">
                <p class="chevalet__hint">Recuperez un chevalet et indiquez ici le numero inscrit dessus.</p>
                <input class="chevalet__input" id="chevalet-input" inputmode="numeric" pattern="[0-9]*"
                       maxlength="4" aria-label="Numero du chevalet" autocomplete="off">
                <p class="chevalet__error" id="chevalet-error" role="alert" hidden>Indiquez le numero du chevalet.</p>
            </div>
            <div class="composer-footer">
                <div class="composer-footer__row">
                    <button class="btn btn--secondary" type="button" id="chevalet-cancel">Annuler</button>
                    <button class="btn btn--primary" type="button" id="chevalet-ok">Enregistrer le numero</button>
                </div>
            </div>
        </div>
    `;
    const prevOverflow = document.body.style.overflow;
    document.body.appendChild(overlay);
    document.body.style.overflow = 'hidden';
    const bg = Array.from(document.body.children).filter(el => el !== overlay);
    bg.forEach(el => el.setAttribute('aria-hidden', 'true'));

    const input = overlay.querySelector('#chevalet-input');
    const errBox = overlay.querySelector('#chevalet-error');

    const teardown = () => {
        document.removeEventListener('keydown', esc);
        bg.forEach(el => el.removeAttribute('aria-hidden'));
        overlay.remove();
        document.body.style.overflow = prevOverflow;
    };
    const dismiss = () => { teardown(); if (onDismiss) onDismiss(); };
    const esc = (e) => { if (e.key === 'Escape') dismiss(); };
    document.addEventListener('keydown', esc);

    // Focus-trap : Tab/Shift+Tab cyclent dans la modale (coherent L2/L3).
    overlay.addEventListener('keydown', (e) => {
        if (e.key !== 'Tab') return;
        const f = Array.from(overlay.querySelectorAll('input, button:not([disabled])'));
        if (!f.length) return;
        const first = f[0];
        const last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });

    overlay.querySelector('#chevalet-cancel').addEventListener('click', dismiss);
    overlay.querySelector('#chevalet-ok').addEventListener('click', () => {
        const tag = (input.value || '').trim();
        if (!/^[0-9]{1,4}$/.test(tag)) {
            errBox.hidden = false;
            input.focus();
            return;
        }
        teardown();
        onValidate(tag);
    });

    requestAnimationFrame(() => input.focus());
}

/* --- Init ---------------------------------------------------------------- */

document.addEventListener('DOMContentLoaded', () => {
    // Nouvelle visite paiement = nouvelle commande : on repart d'une cle d'idempotence
    // neuve (les retries d'une meme tentative la reutilisent, cf. checkout.checkoutKey).
    try { sessionStorage.removeItem('wakdo_order_key'); } catch { /* noop */ }

    const items = getCart();
    if (!items.length) {
        window.location.href = 'cart.html';
        return;
    }
    if (recap) {
        const modeLabel = getMode() === 'a-emporter' ? 'A emporter' : 'Sur place';
        recap.innerHTML = `
            <p class="payment-recap__mode">${escHtml(modeLabel)}</p>
            <p class="payment-recap__items">${items.length} article${items.length > 1 ? 's' : ''}</p>
            <p class="payment-recap__total">Total : <strong>${formatPrice(getTotalCents())}</strong></p>
        `;
    }
    if (cardBtn) cardBtn.addEventListener('click', startCheckout);
    if (cashBtn) cashBtn.addEventListener('click', startCheckout);
});
