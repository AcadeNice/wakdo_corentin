/*
 * page-products.js — Products list screen.
 *
 * Reads ?category=<id> from the query string, maps to a slug via
 * CATEGORY_ID_TO_SLUG, then fetches the matching product array.
 * On product card click, opens an in-page modal (composer for a menu, options
 * for a simple product) above the grid ; the order panel reflects the addition.
 */

import { getProductsByCategory, getCategoryById, CATEGORY_ID_TO_SLUG, loadAllergens } from './data.js';
import { formatPrice, escHtml } from './state.js';
import { buildAllergenInfoButton, openProductAllergenModal } from './allergens.js';
import { openMenuComposer } from './page-product-menu.js';
import { openProductOptions } from './product-options.js';
import { buildMenuSection, upsertJsonLd, categoryPageTitle } from './seo.js';
import './img-fallback.js';

const params      = new URLSearchParams(window.location.search);
const categoryId  = parseInt(params.get('category'), 10) || 1;
const categorySlug = CATEGORY_ID_TO_SLUG[categoryId] ?? 'menus';

const grid       = document.getElementById('products-grid');
const heading    = document.getElementById('products-heading');
const subheading = document.getElementById('products-subheading');
const backBtn    = document.getElementById('back-to-categories');
const errorBlock = document.getElementById('products-error');

/*
 * A6 (audit maquette vs front) : phrase descriptive sous le titre, comme la
 * maquette. Le texte n'existe nulle part en base (`category` n'a pas de colonne
 * `description`) et la maquette ne couvre que 2 categories sur les 9 du
 * catalogue (les 7 autres n'apparaissent que comme onglets du bandeau, jamais en
 * ecran de liste) -- une table de correspondance cote front, plutot qu'une
 * migration de schema, est la solution la plus simple ici : ajouter une colonne
 * pour 2 valeurs texte fixes, non gerees par le back-office, serait une
 * complexite non justifiee (Rasoir d'Ockham). Categories non listees : pas de
 * sous-titre affiche (rien n'est invente).
 */
const CATEGORY_SUBTITLES = {
    menus: 'Un sandwich, une friture ou une salade et une boisson',
    boissons: 'Une petite soif, sucrée, légère, rafraîchissante',
};

/**
 * Titre client d'une categorie ("Nos menus") a partir du libelle brut de la base
 * ("Menus", capitalise par la migration 0013 pour le back-office). Pur (cible de
 * test), A7.
 * @param {string} rawName
 * @returns {string}
 */
export function customerCategoryTitle(rawName) {
    return `Nos ${rawName.charAt(0).toLowerCase() + rawName.slice(1)}`;
}

/**
 * Sous-titre connu pour un slug de categorie, ou null si la maquette ne le
 * couvre pas (rien n'est invente pour les 7 autres categories). Pur (cible de
 * test), A6.
 * @param {string} slug
 * @returns {string|null}
 */
export function categorySubtitle(slug) {
    return CATEGORY_SUBTITLES[slug] ?? null;
}

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
            heading.textContent = customerCategoryTitle(category.title);
            document.title = categoryPageTitle(heading.textContent);

            if (subheading) {
                const sub = categorySubtitle(categorySlug);
                subheading.textContent = sub ?? '';
                subheading.hidden = !sub;
            }
        }

        if (!products.length) {
            grid.innerHTML = '<li class="products-empty">Aucun produit disponible dans cette catégorie.</li>';
            return;
        }

        // Reference INCO (les 14 descriptions) pour la modale "i". Chargee une fois,
        // partagee par toutes les cartes ; un echec ne doit pas casser l'affichage
        // produits NI masquer les allergenes : les noms viennent de l'API produit, la
        // reference n'ajoute que les explications (F11b).
        let allergenReference = [];
        try {
            allergenReference = await loadAllergens();
        } catch (e) {
            console.error('loadAllergens error:', e);
        }

        grid.innerHTML = '';
        products.forEach(product => {
            // commandable : false = rupture de stock (RG-T21). La tuile reste visible
            // (le client voit le produit de la carte) mais grisee et non cliquable.
            const orderable = product.commandable !== false;
            const card = document.createElement('a');
            card.className = orderable ? 'product-card' : 'product-card product-card--unavailable';
            // Le <a> reste pour le focus/clavier (a11y) ; href='#' inerte, le handler
            // click ci-dessous fait foi (preventDefault + ouverture de la modale).
            card.href = '#';
            const label = `${product.nom} - ${formatPrice(product.prix)}${orderable ? '' : ' - indisponible'}`;
            card.setAttribute('aria-label', label);
            // Cr 1.e.7 : title reprenant l'intitule du lien, comme l'aria-label.
            card.setAttribute('title', label);
            if (!orderable) card.setAttribute('aria-disabled', 'true');

            card.innerHTML = `
                <div class="product-card__image-wrap">
                    <img
                        class="product-card__image"
                        src="${escHtml(product.image)}"
                        alt="${escHtml(product.nom)}"
                        loading="lazy"
                        data-fallback="logo" data-fallback-alt="Image non disponible"
                    >
                    ${orderable ? '' : '<span class="product-card__badge">Indisponible</span>'}
                </div>
                <div class="product-card__body">
                    <span class="product-card__name">${escHtml(product.nom)}</span>
                    <span class="product-card__price">${formatPrice(product.prix)}</span>
                </div>
            `;

            // Bouton "i" allergenes : frere de la carte, JAMAIS dans le <a> (un
            // element interactif ne peut pas descendre d'un lien — regle HTML verifiee
            // au validateur W3C). Superpose au coin de l'image via CSS. La modale porte
            // les allergenes de CE produit, pas la liste des 14 (F11b).
            const infoBtn = buildAllergenInfoButton(() => openProductAllergenModal(product, allergenReference));

            // Clic produit -> modale au-dessus de la grille (paradigme maquette) :
            // menu -> composeur (L2), produit -> options (L3). Le panneau de droite est
            // l'unique vue panier ; pas de navigation au clic. Une tuile en rupture ne
            // fait rien (ni navigation ni modale).
            card.addEventListener('click', (e) => {
                e.preventDefault();
                if (!orderable) return;
                if (product.type === 'menu') openMenuComposer(product, categorySlug);
                else openProductOptions(product, categorySlug);
            });

            // Carte + bouton "i" enveloppes dans un <li> : seul enfant valide d'un
            // <ul>. Le <li> ancre le bouton allergene superpose (CSS position:relative).
            const cell = document.createElement('li');
            cell.className = 'product-card-cell';
            cell.appendChild(card);
            cell.appendChild(infoBtn);
            grid.appendChild(cell);
        });

        // Donnees structurees de la section affichee (Cr 1.e.3) : remplacent le bloc de
        // depart de products.html par la categorie reelle et ses produits. Adresses
        // calculees depuis le lien canonique de la page, comme les @id des blocs fixes :
        // le graphe reste relie quel que soit l'hote qui sert la page (production, test).
        // URL sans le mode de consommation : la section est la meme sur place ou a emporter.
        const canonical = document.querySelector('link[rel="canonical"]');
        const base = canonical ? canonical.href : document.baseURI;
        upsertJsonLd(document, 'ld-menu-section', buildMenuSection({
            name: heading ? heading.textContent : 'Produits de la carte Wakdo',
            url: new URL(`products.html?category=${categoryId}`, base).href,
            products,
            baseUrl: base,
        }));

    } catch (err) {
        if (errorBlock) {
            errorBlock.hidden = false;
            errorBlock.textContent = 'Impossible de charger les produits. Veuillez réessayer.';
        }
        console.error('renderProducts error:', err);
    }
}

document.addEventListener('DOMContentLoaded', renderProducts);
