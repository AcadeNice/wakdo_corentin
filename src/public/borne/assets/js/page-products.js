/*
 * page-products.js — Products list screen.
 *
 * Reads ?category=<id> from the query string, maps to a slug via
 * CATEGORY_ID_TO_SLUG, then fetches the matching product array.
 * On product card click, navigates to product.html?id=<id>&category=<slug>.
 */

import { getProductsByCategory, getCategoryById, CATEGORY_ID_TO_SLUG, loadAllergens } from './data.js';
import { formatPrice, escHtml } from './state.js';
import { buildAllergenInfoButton, openAllergenModal } from './allergens.js';
import { openMenuComposer } from './page-product-menu.js';
import { openProductOptions } from './product-options.js';

const params      = new URLSearchParams(window.location.search);
const categoryId  = parseInt(params.get('category'), 10) || 1;
const categorySlug = CATEGORY_ID_TO_SLUG[categoryId] ?? 'menus';

const grid       = document.getElementById('products-grid');
const heading    = document.getElementById('products-heading');
const backBtn    = document.getElementById('back-to-categories');
const errorBlock = document.getElementById('products-error');

/* Build back URL preserving mode query param if present */
const modeParam = params.get('mode');

function buildBackURL() {
    const base = 'categories.html';
    return modeParam ? `${base}?mode=${modeParam}` : base;
}

if (backBtn) {
    backBtn.href = buildBackURL();
}

async function renderProducts() {
    try {
        const [products, category] = await Promise.all([
            getProductsByCategory(categorySlug),
            getCategoryById(categoryId)
        ]);

        if (heading && category) {
            /* Capitalize first letter of the category title */
            const title = category.title.charAt(0).toUpperCase() + category.title.slice(1);
            heading.textContent = `Nos ${title}`;
            document.title = `Wakdo - ${title}`;
        }

        if (!products.length) {
            grid.innerHTML = '<p class="products-empty">Aucun produit disponible dans cette categorie.</p>';
            return;
        }

        // Liste generale des allergenes (modale "i"). Chargee une fois, partagee par
        // toutes les cartes ; un echec ne doit pas casser l'affichage produits.
        let allergens = [];
        try {
            allergens = await loadAllergens();
        } catch (e) {
            console.error('loadAllergens error:', e);
        }

        grid.innerHTML = '';
        products.forEach(product => {
            const card = document.createElement('a');
            card.className = 'product-card';
            card.href = `product.html?id=${product.id}&category=${categorySlug}`;
            card.setAttribute('aria-label', `${product.nom} - ${formatPrice(product.prix)}`);

            card.innerHTML = `
                <div class="product-card__image-wrap">
                    <img
                        class="product-card__image"
                        src="${escHtml(product.image)}"
                        alt="${escHtml(product.nom)}"
                        loading="lazy"
                        onerror="this.src='assets/images/ui/logo.png'; this.alt='Image non disponible';"
                    >
                </div>
                <div class="product-card__body">
                    <span class="product-card__name">${escHtml(product.nom)}</span>
                    <span class="product-card__price">${formatPrice(product.prix)}</span>
                </div>
            `;

            // Bouton "i" allergenes superpose a l'image ; son clic ouvre la modale
            // generale et ne declenche pas la navigation de la carte (stopPropagation).
            const infoBtn = buildAllergenInfoButton(() => openAllergenModal(allergens));
            card.querySelector('.product-card__image-wrap').appendChild(infoBtn);

            // Clic produit -> modale au-dessus de la grille (paradigme maquette) au lieu
            // de naviguer vers product.html : menu -> composeur (L2), produit -> options
            // (L3). Le <a href> reste un repli (lien direct / sans JS).
            card.addEventListener('click', (e) => {
                e.preventDefault();
                if (product.type === 'menu') openMenuComposer(product, categorySlug);
                else openProductOptions(product, categorySlug);
            });

            grid.appendChild(card);
        });

    } catch (err) {
        if (errorBlock) {
            errorBlock.hidden = false;
            errorBlock.textContent = 'Impossible de charger les produits. Veuillez reessayer.';
        }
        console.error('renderProducts error:', err);
    }
}

document.addEventListener('DOMContentLoaded', renderProducts);
