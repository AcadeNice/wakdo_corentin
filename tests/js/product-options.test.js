/*
 * Tests de la modale d'options produit (P5 L3), node:test + jsdom.
 *
 * product-options.js importe order-panel.js + nav.js (DOM au chargement) -> import
 * dynamique apres globals jsdom. Cible : productCartItem (PUR) + openProductOptions
 * (rendu jsdom : stepper quantite, ajout au panier, fermeture).
 */
import { test, before, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

let productCartItem, openProductOptions, productSizes;

before(async () => {
    const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', { url: 'https://kiosk.test/products.html' });
    global.window = dom.window;
    global.document = dom.window.document;
    global.localStorage = dom.window.localStorage;
    global.requestAnimationFrame = (cb) => cb();
    ({ productCartItem, openProductOptions, productSizes } =
        await import('../../src/public/borne/assets/js/product-options.js'));
});

beforeEach(() => {
    global.localStorage.clear();
    document.body.innerHTML = '';
});

const product = { id: 14, nom: 'Coca', prix: 190, image: 'c.png', categorie: 'boissons' };

/* --- productCartItem (pur) ----------------------------------------------- */

test('productCartItem: forme item, quantite et categorie du produit', () => {
    const it = productCartItem(product, 'boissons', 3);
    assert.deepEqual(it, {
        id: 14, type: 'produit', categorie: 'boissons', libelle: 'Coca',
        prix_cents: 190, quantite: 3, image: 'c.png',
    });
});

test('productCartItem: quantite bornee a [1,99], categorie de repli = slug', () => {
    assert.equal(productCartItem({ id: 1, nom: 'X', prix: 100, image: 'x.png' }, 'frites', 0).quantite, 1);
    assert.equal(productCartItem(product, 'boissons', 9999).quantite, 99);
    assert.equal(productCartItem({ id: 1, nom: 'X', prix: 100, image: 'x.png' }, 'frites', 2).categorie, 'frites');
});

/* --- openProductOptions (jsdom) ------------------------------------------ */

test('openProductOptions: rend la modale (dialog) avec total = prix unitaire', () => {
    openProductOptions(product, 'boissons');
    const dialog = document.querySelector('.composer-overlay [role="dialog"]');
    assert.ok(dialog);
    assert.match(document.querySelector('#po-total').textContent, /1,90/);
    assert.equal(document.querySelector('#po-qty').textContent, '1');
});

test('openProductOptions: le stepper met a jour quantite et total', () => {
    openProductOptions(product, 'boissons');
    document.querySelector('.qty-btn--plus').click();
    document.querySelector('.qty-btn--plus').click();
    assert.equal(document.querySelector('#po-qty').textContent, '3');
    assert.match(document.querySelector('#po-total').textContent, /5,70/); // 1,90 x 3
    document.querySelector('.qty-btn--minus').click();
    assert.equal(document.querySelector('#po-qty').textContent, '2');
});

test('openProductOptions: quantite plancher a 1', () => {
    openProductOptions(product, 'boissons');
    const minus = document.querySelector('.qty-btn--minus');
    minus.click(); minus.click(); minus.click();
    assert.equal(document.querySelector('#po-qty').textContent, '1');
});

test('openProductOptions: Ajouter met l item (avec quantite) au panier et ferme la modale', () => {
    openProductOptions(product, 'boissons');
    document.querySelector('.qty-btn--plus').click(); // qty 2
    document.querySelector('#po-add').click();
    const cart = JSON.parse(localStorage.getItem('wakdo_cart'));
    assert.equal(cart.length, 1);
    assert.equal(cart[0].id, 14);
    assert.equal(cart[0].quantite, 2);
    assert.equal(document.querySelector('.composer-overlay'), null); // modale fermee
});

test('openProductOptions: Annuler ferme sans rien ajouter', () => {
    openProductOptions(product, 'boissons');
    document.querySelector('#po-cancel').click();
    assert.equal(document.querySelector('.composer-overlay'), null);
    assert.equal(localStorage.getItem('wakdo_cart'), null);
});

/* --- Tailles a la carte (R4) --------------------------------------------- */

// Boisson a deux tailles : 30 cl = ligne de base (id du produit), 50 cl = variante.
const soda = {
    id: 14, nom: 'Coca', prix: 190, image: 'c.png', categorie: 'boissons',
    sizes: [
        { product_id: 14, size_cl: 30, price_cents: 190, label: '30 cl' },
        { product_id: 99, size_cl: 50, price_cents: 240, label: '50 cl' },
    ],
};

test('productSizes: renvoie les tailles seulement si plus d une', () => {
    assert.equal(productSizes(soda).length, 2);
    assert.deepEqual(productSizes(product), []);                          // pas de sizes
    assert.deepEqual(productSizes({ ...product, sizes: [soda.sizes[0]] }), []); // une seule taille
});

test('productCartItem: avec une taille, prend SON product_id, prix et libelle', () => {
    const it = productCartItem(soda, 'boissons', 1, soda.sizes[1]); // 50 cl
    assert.equal(it.id, 99);
    assert.equal(it.prix_cents, 240);
    assert.equal(it.libelle, 'Coca - 50 cl');
});

test('openProductOptions: rend un picker, defaut = plus petite taille (prix 30 cl)', () => {
    openProductOptions(soda, 'boissons');
    const btns = document.querySelectorAll('.product-options__sizes .size-btn');
    assert.equal(btns.length, 2);
    assert.equal(btns[0].textContent, '30 cl');
    assert.equal(btns[0].getAttribute('aria-checked'), 'true');   // 30 cl par defaut
    assert.equal(btns[1].getAttribute('aria-checked'), 'false');
    assert.match(document.querySelector('#po-total').textContent, /1,90/); // prix 30 cl
});

test('openProductOptions: changer de taille met a jour le prix affiche', () => {
    openProductOptions(soda, 'boissons');
    const btns = document.querySelectorAll('.product-options__sizes .size-btn');
    btns[1].click(); // 50 cl
    assert.equal(btns[1].getAttribute('aria-checked'), 'true');
    assert.equal(btns[0].getAttribute('aria-checked'), 'false');
    assert.match(document.querySelector('#po-total').textContent, /2,40/);  // prix 50 cl
    assert.match(document.querySelector('#po-unit').textContent, /2,40/);
});

test('openProductOptions: ajout avec la taille choisie -> item porte le product_id de la taille', () => {
    openProductOptions(soda, 'boissons');
    const btns = document.querySelectorAll('.product-options__sizes .size-btn');
    btns[1].click();                                        // 50 cl
    document.querySelector('.qty-btn--plus').click();       // qty 2
    document.querySelector('#po-add').click();

    const cart = JSON.parse(localStorage.getItem('wakdo_cart'));
    assert.equal(cart.length, 1);
    assert.equal(cart[0].id, 99);                           // product_id de la 50 cl
    assert.equal(cart[0].prix_cents, 240);
    assert.equal(cart[0].quantite, 2);
    assert.equal(cart[0].libelle, 'Coca - 50 cl');
});

test('openProductOptions: produit sans taille -> aucun picker, ajout direct (product_id de base)', () => {
    openProductOptions(product, 'boissons');                // product sans sizes
    assert.equal(document.querySelectorAll('.product-options__sizes .size-btn').length, 0);
    document.querySelector('#po-add').click();
    const cart = JSON.parse(localStorage.getItem('wakdo_cart'));
    assert.equal(cart[0].id, 14);
    assert.equal(cart[0].prix_cents, 190);
    assert.equal(cart[0].libelle, 'Coca');                  // pas de suffixe de taille
});
