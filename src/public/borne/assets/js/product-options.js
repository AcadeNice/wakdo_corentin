/*
 * product-options.js — Modale d'options produit (P5 L3, taille R4).
 *
 * Ouvre une modale d'options au clic produit, au lieu d'une navigation : cliquer un
 * produit simple ouvre une modale (image, prix unitaire, stepper de quantite, total)
 * au-dessus de la grille, facon maquette ("Une petite soif ?"). A l'ajout, le panneau
 * de commande persistant (L1) est re-rendu pour refleter immediatement la commande.
 *
 * Taille (R4) : la dimension 30/50 cl de la maquette existe desormais en base sous
 * forme de LIGNES produit distinctes (product.sizes : [{product_id, size_cl,
 * price_cents, label}]). Quand un produit porte plus d'une taille, la modale affiche
 * un selecteur ; la taille choisie resout le product_id ET le prix de l'item panier.
 * Un produit sans taille (sizes vide ou unique) garde l'ajout direct.
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
 *
 * Taille (R4) : si `size` est fournie (entree de product.sizes), c'est SON product_id,
 * SON prix et SON libelle (nom + " - <label>") qui sont poses -> le domaine commande
 * facture cette ligne produit, sans logique de taille. Sans size, comportement inchange.
 *
 * @param {Object} product — forme borne {id, nom, prix, image, categorie?, sizes?}
 * @param {string} categorySlug
 * @param {number} qty
 * @param {Object|null} [size] — entree de sizes {product_id, size_cl, price_cents, label}
 * @returns {Object} item panier
 */
export function productCartItem(product, categorySlug, qty, size = null) {
    const quantite = Math.min(QTY_MAX, Math.max(1, Math.floor(qty) || 1));
    return {
        id: size ? size.product_id : product.id,
        type: 'produit',
        categorie: product.categorie ?? categorySlug,
        libelle: size ? `${product.nom} - ${size.label}` : product.nom,
        prix_cents: size ? size.price_cents : product.prix,
        quantite,
        image: product.image,
    };
}

/**
 * Tailles utilisables d'un produit : tableau de sizes seulement s'il en porte plus
 * d'une (un picker n'a de sens qu'avec un choix). Sinon [] (ajout direct). Pur.
 * @param {Object} product
 * @returns {Array}
 */
export function productSizes(product) {
    return Array.isArray(product.sizes) && product.sizes.length > 1 ? product.sizes : [];
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

    // Tailles (R4) : si le produit en porte plus d'une, le picker pilote prix + product_id.
    // La plus petite (sizes deja trie par volume cote API) est le defaut.
    const sizes = productSizes(product);
    let selectedSize = sizes.length ? sizes[0] : null;
    const unitPrice = () => (selectedSize ? selectedSize.price_cents : product.prix);

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
                         alt="${escHtml(product.nom)}" data-fallback="logo">
                    <div class="product-options__sizes" role="group" aria-label="Taille"></div>
                    <p class="product-options__unit" id="po-unit">${formatPrice(unitPrice())} / unite</p>
                    <div class="qty-control" role="group" aria-label="Quantite">
                        <button class="qty-btn qty-btn--minus" type="button" aria-label="Diminuer la quantite">-</button>
                        <span class="qty-value" id="po-qty" aria-live="polite">1</span>
                        <button class="qty-btn qty-btn--plus" type="button" aria-label="Augmenter la quantite">+</button>
                    </div>
                    <p class="product-options__total" aria-live="polite" aria-atomic="true">Total : <strong id="po-total">${formatPrice(unitPrice())}</strong></p>
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
    const unitEl = overlay.querySelector('#po-unit');
    const sync = () => {
        qtyEl.textContent = String(qty);
        unitEl.textContent = `${formatPrice(unitPrice())} / unite`;
        totalEl.textContent = formatPrice(unitPrice() * qty);
    };

    // Picker de taille : boutons radio-like construits par createElement (CSP-safe,
    // pas de handler inline). Sans tailles multiples, le conteneur reste vide.
    const sizesWrap = overlay.querySelector('.product-options__sizes');
    if (sizes.length) {
        sizes.forEach((size) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'size-btn';
            btn.dataset.productId = String(size.product_id);
            btn.setAttribute('role', 'radio');
            const isDefault = size === selectedSize;
            btn.setAttribute('aria-checked', isDefault ? 'true' : 'false');
            if (isDefault) btn.classList.add('size-btn--selected');
            btn.textContent = size.label;
            btn.addEventListener('click', () => {
                selectedSize = size;
                sizesWrap.querySelectorAll('.size-btn').forEach((b) => {
                    const on = b === btn;
                    b.classList.toggle('size-btn--selected', on);
                    b.setAttribute('aria-checked', on ? 'true' : 'false');
                });
                sync();
            });
            sizesWrap.appendChild(btn);
        });
    }

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
        addToCart(productCartItem(product, categorySlug, qty, selectedSize));
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
