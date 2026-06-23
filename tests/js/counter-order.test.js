/*
 * Tests du composeur de commande comptoir/drive (counter-order.js, sous-lot 3c).
 * node:test + jsdom. Couvre la serialisation du panier dans #items_json :
 *  - ajout produit (champ quantite) -> item {type:'product', ...}
 *  - personnalisation produit (retrait + ajout d'ingredients) -> modifiers:[...]
 *  - configuration menu (slots + format Maxi + modificateurs burger) -> item menu
 *  - menu non configurable (slot_type non gere) ignore (anti-perte silencieuse)
 *
 * Le serveur revalide la forme (RG-T18), revalide chaque modificateur (resolveModifiers)
 * et recalcule les prix (RG-T16) : on n'asserte que la FORME emise, pas un prix.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

// counter-order.js est du CommonJS (admin = racine CommonJS) ; import par defaut.
import counterOrder from '../../src/public/admin/assets/js/counter-order.js';

const PRODUCTS = [
    {
        id: 12, name: 'Cheeseburger', price: 890,
        modifiers: [
            { ingredient_id: 3, name: 'Oignon', is_removable: 1, is_addable: 0, extra_price_cents: 0 },
            { ingredient_id: 8, name: 'Bacon', is_removable: 0, is_addable: 1, extra_price_cents: 50 },
        ],
    },
    { id: 22, name: 'Frites', price: 250, modifiers: [] },
    { id: 14, name: 'Coca', price: 200, modifiers: [] },
    { id: 47, name: 'Ketchup', price: 0, modifiers: [] },
];

const MENUS = [
    {
        id: 5,
        name: 'Menu Cheeseburger',
        price_normal: 990,
        price_maxi: 1190,
        burger_modifiers: [
            { ingredient_id: 3, name: 'Oignon', is_removable: 1, is_addable: 0, extra_price_cents: 0 },
            { ingredient_id: 8, name: 'Bacon', is_removable: 0, is_addable: 1, extra_price_cents: 50 },
        ],
        slots: [
            { id: 16, name: 'Accompagnement', slot_type: 'side', is_required: 1, display_order: 2, option_product_ids: [22] },
            { id: 1, name: 'Boisson', slot_type: 'drink', is_required: 1, display_order: 1, option_product_ids: [14] },
            { id: 31, name: 'Sauce', slot_type: 'sauce', is_required: 0, display_order: 3, option_product_ids: [47] },
        ],
    },
];

function setup(menus = MENUS) {
    const menuItems = menus
        .map(m => `<li><button class="menu-configure" type="button" data-menu-id="${m.id}">Configurer</button></li>`)
        .join('');
    // qty_<id> pour tous les produits (repli sans JS) ; bouton "Personnaliser" pour
    // ceux dont la recette offre des modificateurs (calque la vue new.php).
    const productRows = PRODUCTS
        .map(p => {
            const configure = (p.modifiers && p.modifiers.length)
                ? `<button class="product-configure" type="button" data-product-id="${p.id}">Personnaliser</button>`
                : '';
            return `<input class="order-qty" type="number" id="qty_${p.id}" name="qty_${p.id}" data-product-id="${p.id}" value="0">${configure}`;
        })
        .join('');
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body>' +
        '<form id="counter-order-form" method="post" action="/counter/orders" ' +
        `      data-products='${JSON.stringify(PRODUCTS)}' data-menus='${JSON.stringify(menus)}'>` +
        '  <input type="hidden" name="items_json" id="items_json" value="">' +
        productRows +
        '  <ul id="menu-list">' + menuItems + '</ul>' +
        '  <ul id="order-cart"><li id="order-cart-empty">Panier vide.</li></ul>' +
        '  <button type="submit">Encaisser</button>' +
        '</form>' +
        '<div id="menu-composer-modal" hidden></div>' +
        '</body></html>',
    );
    return dom;
}

function fireSubmit(dom) {
    const form = dom.window.document.getElementById('counter-order-form');
    form.dispatchEvent(new dom.window.Event('submit', { cancelable: true, bubbles: true }));
}

function itemsJson(dom) {
    return JSON.parse(dom.window.document.getElementById('items_json').value || '[]');
}

test('ajout produit sans modificateur (quantite) -> items_json contient {type:product}', () => {
    const dom = setup();
    counterOrder.init(dom.window.document);

    // Frites (22) n'a pas de modificateur -> pas de bouton Personnaliser -> chemin qty_<id>.
    const qty = dom.window.document.getElementById('qty_22');
    qty.value = '2';
    fireSubmit(dom);

    const items = itemsJson(dom);
    assert.deepEqual(items, [{ type: 'product', product_id: 22, quantity: 2 }]);
});

test('produit personnalisable : qty directe ignoree (route par la modale, anti-double-comptage)', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    // Cheeseburger (12) porte un bouton Personnaliser : son qty_<id> est ignore par le
    // JS pour eviter le double comptage avec la ligne configuree.
    doc.getElementById('qty_12').value = '3';
    fireSubmit(dom);

    assert.deepEqual(itemsJson(dom), []);
});

test('personnalisation produit (retrait + ajout) -> items_json porte modifiers:[remove, add]', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    // Ouvre la modale du produit 12 (Cheeseburger).
    doc.querySelector('.product-configure[data-product-id="12"]').dispatchEvent(new dom.window.Event('click', { bubbles: true }));
    const modal = doc.getElementById('menu-composer-modal');
    assert.equal(modal.hasAttribute('hidden'), false);

    // Coche "sans Oignon" (retrait, ingredient 3) et "extra Bacon" (ajout, ingredient 8).
    const removeBox = modal.querySelector('.menu-composer__modifier-remove[data-ingredient-id="3"]');
    removeBox.checked = true;
    removeBox.dispatchEvent(new dom.window.Event('change', { bubbles: true }));

    const addBox = modal.querySelector('.menu-composer__modifier-add[data-ingredient-id="8"]');
    addBox.checked = true;
    addBox.dispatchEvent(new dom.window.Event('change', { bubbles: true }));

    modal.querySelector('.menu-composer__add').dispatchEvent(new dom.window.Event('click', { bubbles: true }));

    assert.equal(modal.hasAttribute('hidden'), true);
    assert.ok(doc.querySelector('.order-cart__line'));

    fireSubmit(dom);
    const items = itemsJson(dom);
    assert.equal(items.length, 1);
    assert.equal(items[0].type, 'product');
    assert.equal(items[0].product_id, 12);
    assert.equal(items[0].quantity, 1);
    assert.deepEqual(items[0].modifiers, [
        { ingredient_id: 3, action: 'remove' },
        { ingredient_id: 8, action: 'add' },
    ]);
});

test('quantite 0 ignoree -> panier vide serialise []', () => {
    const dom = setup();
    counterOrder.init(dom.window.document);

    fireSubmit(dom);
    assert.deepEqual(itemsJson(dom), []);
});

test('configuration menu (format Maxi + slots) -> items_json contient {type:menu, format:maxi, selections}', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    // Ouvre la modale du menu 5.
    doc.querySelector('.menu-configure[data-menu-id="5"]').dispatchEvent(new dom.window.Event('click', { bubbles: true }));

    const modal = doc.getElementById('menu-composer-modal');
    assert.equal(modal.hasAttribute('hidden'), false);

    // Passe en Maxi.
    const maxiRadio = Array.prototype.find.call(
        modal.querySelectorAll('.menu-composer__format-input'),
        r => r.value === 'maxi',
    );
    maxiRadio.checked = true;
    maxiRadio.dispatchEvent(new dom.window.Event('change', { bubbles: true }));

    // Slots requis (side/drink) sont pre-selectionnes (1er choix) ; on ajoute la sauce.
    const sauceSelect = Array.prototype.find.call(
        modal.querySelectorAll('.menu-composer__slot-select'),
        s => s.dataset.slotId === '31',
    );
    sauceSelect.value = '47';
    sauceSelect.dispatchEvent(new dom.window.Event('change', { bubbles: true }));

    modal.querySelector('.menu-composer__add').dispatchEvent(new dom.window.Event('click', { bubbles: true }));

    // Modale fermee, panier recap mis a jour.
    assert.equal(modal.hasAttribute('hidden'), true);
    assert.ok(doc.querySelector('.order-cart__line'));

    fireSubmit(dom);
    const items = itemsJson(dom);
    assert.equal(items.length, 1);
    assert.equal(items[0].type, 'menu');
    assert.equal(items[0].menu_id, 5);
    assert.equal(items[0].format, 'maxi');
    assert.equal(items[0].quantity, 1);
    // Selections : slots tries par display_order (drink=1, side=2, sauce=3).
    assert.deepEqual(items[0].selections, [
        { menu_slot_id: 1, product_id: 14 },
        { menu_slot_id: 16, product_id: 22 },
        { menu_slot_id: 31, product_id: 47 },
    ]);
});

test('menu Normal sans la sauce optionnelle -> selections ne contient que les requis', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    doc.querySelector('.menu-configure[data-menu-id="5"]').dispatchEvent(new dom.window.Event('click', { bubbles: true }));
    const modal = doc.getElementById('menu-composer-modal');

    // Laisse la sauce a "Sans" (valeur vide) ; ajoute directement.
    const sauceSelect = Array.prototype.find.call(
        modal.querySelectorAll('.menu-composer__slot-select'),
        s => s.dataset.slotId === '31',
    );
    sauceSelect.value = '';
    sauceSelect.dispatchEvent(new dom.window.Event('change', { bubbles: true }));

    modal.querySelector('.menu-composer__add').dispatchEvent(new dom.window.Event('click', { bubbles: true }));
    fireSubmit(dom);

    const items = itemsJson(dom);
    assert.equal(items[0].format, 'normal');
    assert.deepEqual(items[0].selections, [
        { menu_slot_id: 1, product_id: 14 },
        { menu_slot_id: 16, product_id: 22 },
    ]);
});

test('produit + menu combines -> items_json contient les deux lignes', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    // Frites (22) sans modificateur -> chemin qty_<id>.
    doc.getElementById('qty_22').value = '1';

    doc.querySelector('.menu-configure[data-menu-id="5"]').dispatchEvent(new dom.window.Event('click', { bubbles: true }));
    const modal = doc.getElementById('menu-composer-modal');
    modal.querySelector('.menu-composer__add').dispatchEvent(new dom.window.Event('click', { bubbles: true }));

    fireSubmit(dom);
    const items = itemsJson(dom);
    assert.equal(items.length, 2);
    assert.equal(items.filter(i => i.type === 'product').length, 1);
    assert.equal(items.filter(i => i.type === 'menu').length, 1);
});

test('configuration menu avec modificateur burger -> item menu porte modifiers:[remove]', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    doc.querySelector('.menu-configure[data-menu-id="5"]').dispatchEvent(new dom.window.Event('click', { bubbles: true }));
    const modal = doc.getElementById('menu-composer-modal');

    // Retire l'oignon du burger (ingredient 3, is_removable).
    const removeBox = modal.querySelector('.menu-composer__modifier-remove[data-ingredient-id="3"]');
    removeBox.checked = true;
    removeBox.dispatchEvent(new dom.window.Event('change', { bubbles: true }));

    modal.querySelector('.menu-composer__add').dispatchEvent(new dom.window.Event('click', { bubbles: true }));
    fireSubmit(dom);

    const items = itemsJson(dom);
    assert.equal(items[0].type, 'menu');
    assert.deepEqual(items[0].modifiers, [{ ingredient_id: 3, action: 'remove' }]);
});

test('composerSteps: slot_type non gere (dessert) ignore, slots tries par display_order', () => {
    const productById = {};
    PRODUCTS.forEach(p => { productById[p.id] = p; });
    const menu = {
        id: 9,
        slots: [
            { id: 99, name: 'Dessert', slot_type: 'dessert', is_required: 1, display_order: 4, option_product_ids: [22] },
            ...MENUS[0].slots,
        ],
    };
    const steps = counterOrder.composerSteps(menu, productById);
    assert.deepEqual(steps.map(s => s.slotType), ['drink', 'side', 'sauce']); // dessert exclu, tri display_order
});
