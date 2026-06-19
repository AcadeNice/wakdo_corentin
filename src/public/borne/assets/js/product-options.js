/*
 * product-options.js — Modale d'options produit (P5 L3).
 *
 * Remplace la navigation vers product.html : cliquer un produit simple ouvre une
 * modale (image, prix unitaire, stepper de quantite, total) au-dessus de la grille,
 * facon maquette ("Une petite soif ?"). A l'ajout, le panneau de commande persistant
 * (L1) est re-rendu pour refleter immediatement la commande -> pas de navigation.
 *
 * Note : la taille (30/50 Cl de la maquette) n'est PAS dans le modele produit actuel
 * (un seul price_cents par produit) -> differee (necessite des variantes produit cote
 * API). Ce lot couvre quantite + ajout.
 *
 * A11y : role=dialog, aria-modal, focus-trap, ESC, fond aria-hidden.
 */

import { addToCart, formatPrice, escHtml } from './state.js';
import { refreshCartBadge } from './nav.js';
import { renderOrderPanel } from './order-panel.js';

const QTY_MAX = 99;

/**
 * Construit l'item panier d'un produit simple pour une quantite donnee. Pur.
 * Quantite bornee a [1, QTY_MAX]. categorie = celle du produit, sinon le slug courant.
 * @param {Object} product — forme borne {id, nom, prix, image, categorie?}
 * @param {string} categorySlug
 * @param {number} qty
 * @returns {Object} item panier
 */
export function productCartItem(product, categorySlug, qty) {
    const quantite = Math.min(QTY_MAX, Math.max(1, Math.floor(qty) || 1));
    return {
        id: product.id,
        type: 'produit',
        categorie: product.categorie ?? categorySlug,
        libelle: product.nom,
        prix_cents: product.prix,
        quantite,
        image: product.image,
    };
}

/** Re-rend le panneau de commande persistant (s'il est present sur la page). */
function refreshOrderPanel() {
    document.querySelectorAll('[data-order-panel]').forEach(renderOrderPanel);
}

/**
 * Ouvre la modale d'options pour un produit simple.
 * @param {Object} product — forme borne {id, nom, prix, image, categorie?}
 * @param {string} categorySlug — slug de la categorie courante (categorie de repli)
 */
export function openProductOptions(product, categorySlug) {
    let qty = 1;

    const overlay = document.createElement('div');
    overlay.className = 'composer-overlay';
    overlay.hidden = true;
    overlay.innerHTML = `
        <div class="composer-container" role="dialog" aria-modal="true" aria-labelledby="po-title">
            <div class="composer-header">
                <h2 class="composer-title" id="po-title">${escHtml(product.nom)}</h2>
            </div>
            <div class="composer-body">
                <div class="product-options">
                    <img class="product-options__image" src="${escHtml(product.image)}"
                         alt="${escHtml(product.nom)}" onerror="this.src='assets/images/ui/logo.png';">
                    <p class="product-options__unit">${formatPrice(product.prix)} / unite</p>
                    <div class="qty-control" role="group" aria-label="Quantite">
                        <button class="qty-btn qty-btn--minus" type="button" aria-label="Diminuer la quantite">-</button>
                        <span class="qty-value" id="po-qty" aria-live="polite">1</span>
                        <button class="qty-btn qty-btn--plus" type="button" aria-label="Augmenter la quantite">+</button>
                    </div>
                    <p class="product-options__total" aria-live="polite" aria-atomic="true">Total : <strong id="po-total">${formatPrice(product.prix)}</strong></p>
                </div>
            </div>
            <div class="composer-footer">
                <div class="composer-footer__row">
                    <button class="btn btn--secondary" type="button" id="po-cancel">Annuler</button>
                    <button class="btn btn--primary" type="button" id="po-add">Ajouter a ma commande</button>
                </div>
            </div>
        </div>
    `;

    const prevOverflow = document.body.style.overflow;
    document.body.appendChild(overlay);
    overlay.removeAttribute('hidden');
    document.body.style.overflow = 'hidden';
    const bgSiblings = Array.from(document.body.children).filter(el => el !== overlay);
    bgSiblings.forEach(el => el.setAttribute('aria-hidden', 'true'));

    const qtyEl = overlay.querySelector('#po-qty');
    const totalEl = overlay.querySelector('#po-total');
    const sync = () => {
        qtyEl.textContent = String(qty);
        totalEl.textContent = formatPrice(product.prix * qty);
    };
    overlay.querySelector('.qty-btn--minus').addEventListener('click', () => { qty = Math.max(1, qty - 1); sync(); });
    overlay.querySelector('.qty-btn--plus').addEventListener('click', () => { qty = Math.min(QTY_MAX, qty + 1); sync(); });

    const close = () => {
        document.removeEventListener('keydown', escHandler);
        bgSiblings.forEach(el => el.removeAttribute('aria-hidden'));
        overlay.remove();
        document.body.style.overflow = prevOverflow;
    };
    const escHandler = (e) => { if (e.key === 'Escape') close(); };
    document.addEventListener('keydown', escHandler);

    overlay.querySelector('#po-cancel').addEventListener('click', close);
    overlay.querySelector('#po-add').addEventListener('click', () => {
        addToCart(productCartItem(product, categorySlug, qty));
        refreshCartBadge();
        refreshOrderPanel();
        close();
    });

    trapFocus(overlay);
    requestAnimationFrame(() => {
        const first = overlay.querySelector('button:not([disabled])');
        if (first) first.focus();
    });
}

/** Piege Tab/Shift+Tab a l'interieur de la modale. */
function trapFocus(overlay) {
    overlay.addEventListener('keydown', (e) => {
        if (e.key !== 'Tab') return;
        const focusable = Array.from(overlay.querySelectorAll('button:not([disabled])'));
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
}
