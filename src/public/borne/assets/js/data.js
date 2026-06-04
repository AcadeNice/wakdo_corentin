/*
 * data.js — Data loading layer for the Wakdo kiosk.
 *
 * P5 reads static JSON copies in /data/ (same origin).
 * In P4, swap the BASE_URL constants to point to REST API endpoints.
 * The function signatures and return shapes remain unchanged so that
 * page scripts need no modification when the data source changes.
 *
 * Category-to-slug mapping (mirrors data/categories.json id field):
 *   1=menus  2=boissons  3=burgers  4=frites  5=encas
 *   6=wraps  7=salades   8=desserts 9=sauces
 */

/* --- P4 swap point -------------------------------------------------------
 * TODO(P4): replace these two paths with API endpoints, e.g.:
 *   const CATEGORIES_URL = '/api/categories';
 *   const PRODUCTS_URL   = '/api/products';
 * The rest of this file is API-agnostic.
 * ----------------------------------------------------------------------- */
const CATEGORIES_URL = 'data/categories.json';
const PRODUCTS_URL   = 'data/produits.json';

/** @type {Array|null} — in-memory cache to avoid repeated fetches */
let _categoriesCache = null;

/** @type {Object|null} */
let _productsCache = null;

/**
 * Fetches and caches the categories list.
 * @returns {Promise<Array>}
 */
export async function loadCategories() {
    if (_categoriesCache) return _categoriesCache;
    const res = await fetch(CATEGORIES_URL);
    if (!res.ok) throw new Error(`Failed to load categories: HTTP ${res.status}`);
    _categoriesCache = await res.json();
    return _categoriesCache;
}

/**
 * Fetches and caches the full products object keyed by category slug.
 * @returns {Promise<Object>}
 */
export async function loadProducts() {
    if (_productsCache) return _productsCache;
    const res = await fetch(PRODUCTS_URL);
    if (!res.ok) throw new Error(`Failed to load products: HTTP ${res.status}`);
    _productsCache = await res.json();
    return _productsCache;
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
 * Finds a product by its numeric id, searching all category slates.
 * Returns null if not found.
 * @param {number} id
 * @returns {Promise<Object|null>}
 */
export async function findProduct(id) {
    const data = await loadProducts();
    for (const slug of Object.keys(data)) {
        const found = data[slug].find(p => p.id === id);
        if (found) return { ...found, categorie: slug };
    }
    return null;
}

/**
 * Maps a category id integer to its slug string.
 * Derived from data/categories.json — kept here as a convenience
 * so page scripts can convert query-string ids without an extra fetch.
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
