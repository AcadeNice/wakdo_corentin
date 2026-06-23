/*
 * page-confirmation.js — Ecran de commande confirmee.
 *
 * Affiche le numero de commande REEL et le montant regle, transmis par l'ecran de
 * paiement via sessionStorage ('wakdo_last_order' = { order_number, total_ttc_cents }
 * pose par checkout.submitOrder). Repli (visite directe sans commande) : numero
 * genere localement + total du panier. Vide le panier au chargement.
 */

import { clearCart, getTotalCents, formatPrice } from './state.js';

const orderNumberEl = document.getElementById('order-number');
const orderTotalEl  = document.getElementById('order-total');
const newOrderBtn   = document.getElementById('new-order-btn');

/** Repli si on arrive ici sans commande soumise (visite directe). */
function fallbackOrderNumber() {
    return 'WK-' + Date.now().toString(36).toUpperCase();
}

document.addEventListener('DOMContentLoaded', () => {
    let order = null;
    try {
        order = JSON.parse(sessionStorage.getItem('wakdo_last_order'));
    } catch {
        order = null;
    }

    const totalCents = order && order.total_ttc_cents != null ? order.total_ttc_cents : getTotalCents();
    if (orderTotalEl) {
        orderTotalEl.textContent = formatPrice(totalCents);
    }
    if (orderNumberEl) {
        orderNumberEl.textContent = order && order.order_number ? order.order_number : fallbackOrderNumber();
    }

    /* La commande est passee : on nettoie le panier et la trace de commande. */
    sessionStorage.removeItem('wakdo_last_order');
    clearCart();
});

if (newOrderBtn) {
    newOrderBtn.addEventListener('click', () => {
        clearCart();
        window.location.href = 'index.html';
    });
}
