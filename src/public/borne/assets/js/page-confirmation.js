/*
 * page-confirmation.js — Order confirmation screen.
 *
 * Generates a short order number: "WK-" + Date.now() encoded in base 36.
 * This is session-unique and human-readable at the counter.
 *
 * Clears the cart on load so that "Nouvelle commande" starts fresh.
 */

import { clearCart, getTotalCents, formatPrice } from './state.js';

const orderNumberEl = document.getElementById('order-number');
const orderTotalEl  = document.getElementById('order-total');
const newOrderBtn   = document.getElementById('new-order-btn');

function generateOrderNumber() {
    return 'WK-' + Date.now().toString(36).toUpperCase();
}

document.addEventListener('DOMContentLoaded', () => {
    /* Capture total before clearing */
    const totalCents = getTotalCents();

    if (orderTotalEl) {
        orderTotalEl.textContent = formatPrice(totalCents);
    }

    if (orderNumberEl) {
        orderNumberEl.textContent = generateOrderNumber();
    }

    /* Clear cart immediately — order is confirmed */
    clearCart();
});

if (newOrderBtn) {
    newOrderBtn.addEventListener('click', () => {
        /* clearCart() already called on DOMContentLoaded, but guard anyway */
        clearCart();
        window.location.href = 'index.html';
    });
}
