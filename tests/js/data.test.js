/*
 * Tests de la couche data.js du front borne (node:test, sans DOM).
 *
 * Couvre le swap P4 : data.js consomme l'API REST (/api/categories|products|menus),
 * deballe l'enveloppe {data}, et traduit la forme canonique (snake_case, name,
 * price_cents, image_path) vers la forme attendue par la borne (nom, prix, image,
 * type, objet indexe par slug, menus sous la cle 'menus'). fetch est mocke ; chaque
 * cas reimporte data.js avec une query unique pour repartir d'un cache vide.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

let _seq = 0;

/**
 * Installe un mock de fetch route par URL et reimporte data.js avec un cache neuf.
 * @param {Record<string, unknown>} routes  reponses JSON par URL
 * @param {string[]} [calls]  collecteur des URLs appelees (optionnel)
 */
async function freshData(routes, calls) {
    global.fetch = async (url) => {
        if (calls) calls.push(url);
        if (!(url in routes)) throw new Error(`fetch inattendu: ${url}`);
        return { ok: true, status: 200, json: async () => routes[url] };
    };

    return import(`../../src/public/borne/assets/js/data.js?case=${_seq++}`);
}

function fixtures() {
    return {
        '/api/categories': {
            data: [
                { id: 1, name: 'Menus', slug: 'menus', image_path: 'assets/images/categories/menus.png', display_order: 1 },
                { id: 3, name: 'Burgers', slug: 'burgers', image_path: 'assets/images/categories/burgers.png', display_order: 3 },
            ],
            total: 2,
        },
        '/api/products': {
            data: [
                { id: 10, category_id: 3, name: 'Big Mac', description: 'Pain, steak, cheddar', price_cents: 600, image_path: 'assets/images/produits/burgers/bigmac.png', display_order: 4 },
            ],
            total: 1,
        },
        '/api/menus': {
            data: [
                { id: 1, category_id: 1, burger_product_id: 10, name: 'Menu Big Mac', description: null, price_normal_cents: 800, price_maxi_cents: 950, image_path: 'assets/images/produits/burgers/bigmac.png', display_order: 1 },
            ],
            total: 1,
        },
    };
}

test('loadCategories appelle /api/categories, deballe {data} et mappe name->title, image_path->image', async () => {
    const calls = [];
    const { loadCategories } = await freshData(fixtures(), calls);

    const cats = await loadCategories();
    assert.ok(Array.isArray(cats));
    assert.equal(cats.length, 2);
    assert.deepEqual(cats[0], { id: 1, title: 'Menus', slug: 'menus', image: 'assets/images/categories/menus.png' });
    assert.ok(calls.includes('/api/categories'), 'doit fetch /api/categories');
});

test('loadProducts groupe les produits par slug a la forme borne (type produit)', async () => {
    const { loadProducts } = await freshData(fixtures());

    const data = await loadProducts();
    assert.deepEqual(data.burgers, [
        { id: 10, nom: 'Big Mac', prix: 600, image: 'assets/images/produits/burgers/bigmac.png', type: 'produit' },
    ]);
});

test('loadProducts glisse les menus sous la cle menus (type menu, prix = price_normal_cents)', async () => {
    const { loadProducts } = await freshData(fixtures());

    const data = await loadProducts();
    assert.deepEqual(data.menus, [
        { id: 1, nom: 'Menu Big Mac', prix: 800, image: 'assets/images/produits/burgers/bigmac.png', type: 'menu' },
    ]);
});

test('loadProducts consomme bien les trois endpoints /api/*', async () => {
    const calls = [];
    const { loadProducts } = await freshData(fixtures(), calls);

    await loadProducts();
    for (const url of ['/api/categories', '/api/products', '/api/menus']) {
        assert.ok(calls.includes(url), `doit fetch ${url}`);
    }
});

test('getProductsByCategory derive de loadProducts (forme borne), [] si slug inconnu', async () => {
    const { getProductsByCategory } = await freshData(fixtures());

    const burgers = await getProductsByCategory('burgers');
    assert.equal(burgers.length, 1);
    assert.equal(burgers[0].nom, 'Big Mac');
    assert.deepEqual(await getProductsByCategory('inexistant'), []);
});

test('findProduct trouve un produit et l enrichit de sa categorie (slug)', async () => {
    const { findProduct } = await freshData(fixtures());

    const product = await findProduct(10);
    assert.equal(product.nom, 'Big Mac');
    assert.equal(product.prix, 600);
    assert.equal(product.type, 'produit');
    assert.equal(product.categorie, 'burgers');
});

test('findProduct trouve un menu (type menu, categorie menus, prix normal)', async () => {
    const { findProduct } = await freshData(fixtures());

    const menu = await findProduct(1);
    assert.equal(menu.nom, 'Menu Big Mac');
    assert.equal(menu.type, 'menu');
    assert.equal(menu.categorie, 'menus');
    assert.equal(menu.prix, 800);
});

test('un statut HTTP non-ok fait rejeter le chargement', async () => {
    global.fetch = async () => ({ ok: false, status: 500, json: async () => ({}) });
    const { loadCategories } = await import(`../../src/public/borne/assets/js/data.js?case=err${_seq++}`);

    await assert.rejects(() => loadCategories());
});

test('findProduct desambigue par categorie quand un produit et un menu partagent un id', async () => {
    // product et menu sont deux tables auto-increment distinctes : l'id 4 designe a
    // la fois le burger Big Mac (product) et le Menu Big Mac (menu). Sans categorie,
    // un scan global renverrait le menu (scanne avant burgers) -> mauvais produit.
    const colliding = {
        '/api/categories': {
            data: [
                { id: 1, name: 'Menus', slug: 'menus', image_path: 'm.png', display_order: 1 },
                { id: 3, name: 'Burgers', slug: 'burgers', image_path: 'b.png', display_order: 3 },
            ],
        },
        '/api/products': {
            data: [
                { id: 4, category_id: 3, name: 'Big Mac', description: null, price_cents: 600, image_path: 'bigmac.png', display_order: 4 },
            ],
        },
        '/api/menus': {
            data: [
                { id: 4, category_id: 1, burger_product_id: 4, name: 'Menu Big Mac', description: null, price_normal_cents: 800, price_maxi_cents: 950, image_path: 'bigmac.png', display_order: 1 },
            ],
        },
    };
    const { findProduct } = await freshData(colliding);

    const burger = await findProduct(4, 'burgers');
    assert.equal(burger.type, 'produit', 'la categorie burgers doit donner le produit, pas le menu');
    assert.equal(burger.nom, 'Big Mac');
    assert.equal(burger.categorie, 'burgers');

    const menu = await findProduct(4, 'menus');
    assert.equal(menu.type, 'menu');
    assert.equal(menu.nom, 'Menu Big Mac');
    assert.equal(menu.categorie, 'menus');
});

test('findProduct renvoie null si l id est absent de la categorie ciblee', async () => {
    const { findProduct } = await freshData(fixtures());
    assert.equal(await findProduct(999, 'burgers'), null);
});
