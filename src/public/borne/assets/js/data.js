/*
 * data.js — Data loading layer for the Wakdo kiosk.
 *
 * Source = REST API (P4). La borne (kiosk) consomme l'API catalogue en lecture
 * (docs/api/conventions.md section 5.2) : /api/categories, /api/products, /api/menus.
 * Les reponses sont enveloppees ({ data: [...], total }) et en forme CANONIQUE
 * (snake_case : name, price_cents, image_path...). Cette couche est le point unique
 * de rapprochement (section 8.3) : elle deballe l'enveloppe et traduit vers la forme
 * historique attendue par le reste de la borne (nom, prix, image, type ; objet
 * indexe par slug de categorie ; menus glisses sous la cle 'menus'). Les signatures
 * publiques et les formes de retour sont inchangees -> les pages n'ont pas bouge.
 *
 * Les allergenes restent un repli statique (data/allergens.json) : leur bascule
 * sur /api/allergens est un chunk ulterieur.
 */

const CATEGORIES_URL = '/api/categories';
const PRODUCTS_URL = '/api/products';
const MENUS_URL = '/api/menus';
/* Liste fixe des 14 allergenes INCO (info generale, modale borne). Repli statique
 * encore en place : bascule sur '/api/allergens' differee. */
const ALLERGENS_URL = 'data/allergens.json';

/* Memoisation par PROMESSE (pas par resultat) : N appelants concurrents au meme
 * chargement partagent UNE seule requete reseau (evite les fetch /api/* redondants
 * au DOMContentLoaded de products.html). Sur echec, la promesse est reinitialisee
 * pour autoriser un nouvel essai. */
let _categoriesPromise = null;
let _productsPromise = null;
let _allergensPromise = null;

/**
 * Recupere une collection enveloppee de l'API et renvoie le tableau `data`.
 * @param {string} url
 * @returns {Promise<Array>}
 */
async function fetchCollection(url) {
    const res = await fetch(url);
    if (!res.ok) throw new Error(`Failed to load ${url}: HTTP ${res.status}`);
    const body = await res.json();
    return Array.isArray(body?.data) ? body.data : [];
}

/**
 * Fetches and caches the categories list (forme borne : id, title, slug, image).
 * @returns {Promise<Array>}
 */
export function loadCategories() {
    if (!_categoriesPromise) {
        _categoriesPromise = fetchCollection(CATEGORIES_URL)
            .then(rows => rows.map(c => ({ id: c.id, title: c.name, slug: c.slug, image: c.image_path })))
            .catch(e => { _categoriesPromise = null; throw e; });
    }
    return _categoriesPromise;
}

/**
 * Fetches and caches the products object keyed by category slug. Les produits et
 * les menus sont regroupes par slug de leur categorie (les menus tombent sous
 * 'menus' via leur category_id) et ramenes a la forme borne. Le menu garde son
 * prix NORMAL (le supplement Maxi est gere par le composeur cote borne).
 * @returns {Promise<Object>}
 */
export function loadProducts() {
    if (_productsPromise) return _productsPromise;

    _productsPromise = Promise.all([
        loadCategories(),
        fetchCollection(PRODUCTS_URL),
        fetchCollection(MENUS_URL),
    ]).then(([categories, products, menus]) => {
        const slugByCategoryId = {};
        const bySlug = {};
        for (const cat of categories) {
            slugByCategoryId[cat.id] = cat.slug;
            bySlug[cat.slug] = [];
        }
        for (const p of products) {
            const slug = slugByCategoryId[p.category_id];
            if (slug === undefined) continue;
            bySlug[slug].push({ id: p.id, nom: p.name, prix: p.price_cents, image: p.image_path, type: 'produit' });
        }
        for (const m of menus) {
            const slug = slugByCategoryId[m.category_id];
            if (slug === undefined) continue;
            bySlug[slug].push({ id: m.id, nom: m.name, prix: m.price_normal_cents, image: m.image_path, type: 'menu' });
        }
        return bySlug;
    }).catch(e => { _productsPromise = null; throw e; });

    return _productsPromise;
}

/** @type {Promise|null} — index id->produit memoise (type 'produit' uniquement) */
let _productsByIdPromise = null;

/**
 * Index des PRODUITS par id (type 'produit' seulement : exclut les menus, dont
 * l'espace d'id est distinct -> pas de collision id produit/menu). Sert au composeur
 * de menu (L2) pour resoudre les option_product_ids des slots /api/menus en produits
 * affichables. Derive de loadProducts() : aucune requete reseau supplementaire.
 * @returns {Promise<Object<number, Object>>}
 */
export function loadProductsById() {
    if (!_productsByIdPromise) {
        _productsByIdPromise = loadProducts().then(bySlug => {
            const byId = {};
            for (const slug of Object.keys(bySlug)) {
                for (const item of bySlug[slug]) {
                    if (item.type === 'produit') byId[item.id] = item;
                }
            }
            return byId;
        }).catch(e => { _productsByIdPromise = null; throw e; });
    }
    return _productsByIdPromise;
}

/**
 * Charge le detail d'un menu avec ses slots depuis GET /api/menus/{id}. Renvoie la
 * forme canonique de l'API (snake_case) telle quelle : { id, burger_product_id,
 * price_normal_cents, price_maxi_cents, name, image_path, slots: [{ id, name,
 * slot_type, is_required, display_order, option_product_ids }] }. Le composeur (L2)
 * la traduit en etapes. Pas de cache : un menu est compose ponctuellement.
 * @param {number} id
 * @returns {Promise<Object|null>}
 */
export async function loadMenu(id) {
    const res = await fetch(`${MENUS_URL}/${id}`);
    if (!res.ok) throw new Error(`Failed to load menu ${id}: HTTP ${res.status}`);
    const body = await res.json();
    return body && typeof body === 'object' ? (body.data ?? null) : null;
}

/**
 * Fetches and caches the 14 INCO allergens (general info modal). Repli statique :
 * la reponse est un tableau nu (pas d'enveloppe), conserve tel quel.
 * @returns {Promise<Array>}
 */
export function loadAllergens() {
    if (!_allergensPromise) {
        _allergensPromise = fetch(ALLERGENS_URL)
            .then(res => {
                if (!res.ok) throw new Error(`Failed to load allergens: HTTP ${res.status}`);
                return res.json();
            })
            .catch(e => { _allergensPromise = null; throw e; });
    }
    return _allergensPromise;
}

/**
 * Returns the array of products for a given category slug.
 * Returns [] if the slug is not found.
 * @param {string} slug — e.g. "burgers", "menus"
 * @returns {Promise<Array>}
 */
export async function getProductsByCategory(slug) {
    const data = await loadProducts();
    return data[slug] ?? [];
}

/**
 * Returns the category object for the given id.
 * @param {number} id
 * @returns {Promise<Object|null>}
 */
export async function getCategoryById(id) {
    const cats = await loadCategories();
    return cats.find(c => c.id === id) ?? null;
}

/**
 * Finds a product/menu by id. product et menu sont deux espaces d'id DISTINCTS
 * (tables auto-increment separees) : un meme id peut designer a la fois un produit
 * et un menu. categorySlug (le slug de la categorie d'ou vient l'appel) leve
 * l'ambiguite -- dans une categorie donnee, l'id est unique. Sans categorySlug, on
 * retombe sur un scan global (best-effort, potentiellement ambigu en cas de
 * collision d'id). Renvoie null si introuvable.
 * @param {number} id
 * @param {string|null} [categorySlug]
 * @returns {Promise<Object|null>}
 */
export async function findProduct(id, categorySlug = null) {
    const data = await loadProducts();

    if (categorySlug !== null && Array.isArray(data[categorySlug])) {
        const found = data[categorySlug].find(p => p.id === id);
        return found ? { ...found, categorie: categorySlug } : null;
    }

    for (const slug of Object.keys(data)) {
        const found = data[slug].find(p => p.id === id);
        if (found) return { ...found, categorie: slug };
    }
    return null;
}

/**
 * Maps a category id integer to its slug string.
 * Derived from the seed catalogue — kept here as a convenience so page scripts can
 * convert query-string ids without an extra fetch.
 */
export const CATEGORY_ID_TO_SLUG = {
    1: 'menus',
    2: 'boissons',
    3: 'burgers',
    4: 'frites',
    5: 'encas',
    6: 'wraps',
    7: 'salades',
    8: 'desserts',
    9: 'sauces'
};

/**
 * Inverse of the above: slug -> id.
 */
export const CATEGORY_SLUG_TO_ID = Object.fromEntries(
    Object.entries(CATEGORY_ID_TO_SLUG).map(([id, slug]) => [slug, Number(id)])
);
