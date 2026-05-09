/*
 * page-product.js — Product detail screen.
 *
 * Reads ?id=<int>&category=<slug> from the query string.
 *
 * Branch on product type:
 *   - type === 'menu'   → open the multi-step composer modal (page-product-menu.js).
 *                         The standard detail layout is bypassed because a menu
 *                         cannot be added to the cart without composition choices.
 *   - type === 'produit' → render the standard detail card with "Ajouter au panier".
 *
 * After "Ajouter au panier" (simple product):
 *  1. Item added to cart via state.addToCart()
 *  2. Button changes to "Ajoute !" for 1 second (visual feedback)
 *  3. Redirect to products.html?category=<slug>
 */

import { findProduct } from './data.js';
import { addToCart, formatPrice } from './state.js';
import { refreshCartBadge } from './nav.js';
import { openMenuComposer } from './page-product-menu.js';

const params       = new URLSearchParams(window.location.search);
const productId    = parseInt(params.get('id'), 10);
const categorySlug = params.get('category') ?? 'menus';

const container  = document.getElementById('product-detail');
const errorBlock = document.getElementById('product-error');
const backBtn    = document.getElementById('back-to-products');

if (backBtn) {
    backBtn.href = `products.html?category=${categorySlug}`;
}

async function renderProduct() {
    if (!productId) {
        showError('Produit introuvable.');
        return;
    }

    try {
        const product = await findProduct(productId);
        if (!product) {
            showError('Ce produit n\'existe pas.');
            return;
        }

        document.title = `Wakdo - ${product.nom}`;

        if (product.type === 'menu') {
            /* Hide the standard product detail area; the composer will overlay the page.
             * The container stays in the DOM so the skeleton does not flash. */
            container.hidden = true;
            await openMenuComposer(product, categorySlug);
            return;
        }

        container.innerHTML = `
            <div class="product-detail__image-wrap">
                <img
                    class="product-detail__image"
                    src="${product.image}"
                    alt="${product.nom}"
                    onerror="this.src='assets/images/ui/logo.png'; this.alt='Image non disponible';"
                >
            </div>
            <div class="product-detail__info">
                <h1 class="product-detail__name">${product.nom}</h1>
                <p class="product-detail__price">${formatPrice(product.prix)}</p>
                <button
                    class="btn btn--primary btn--large product-detail__add"
                    id="add-to-cart-btn"
                    aria-label="Ajouter ${product.nom} au panier"
                    type="button"
                >
                    Ajouter au panier
                </button>
            </div>
        `;

        document.getElementById('add-to-cart-btn').addEventListener('click', () => {
            addToCart({
                id:          product.id,
                type:        product.type,
                categorie:   product.categorie ?? categorySlug,
                libelle:     product.nom,
                prix_cents:  product.prix,
                quantite:    1,
                image:       product.image
            });
            refreshCartBadge();

            const btn = document.getElementById('add-to-cart-btn');
            btn.textContent = 'Ajoute !';
            btn.disabled = true;

            /* Redirect after brief confirmation pause */
            setTimeout(() => {
                window.location.href = `products.html?category=${categorySlug}`;
            }, 1000);
        });

    } catch (err) {
        showError('Erreur lors du chargement du produit.');
        console.error('renderProduct error:', err);
    }
}

function showError(msg) {
    if (errorBlock) {
        errorBlock.hidden = false;
        errorBlock.textContent = msg;
    }
    if (container) {
        container.hidden = true;
    }
}

document.addEventListener('DOMContentLoaded', renderProduct);
