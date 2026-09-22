/*
 * Tests du builder de slots du formulaire menu (back-office), node:test + jsdom.
 *
 * F12 : les options proposees dans un slot sont filtrees par le type de slot via le
 * mapping slot_type -> categories. Cible : init(doc) (rendu jsdom des slots + filtrage
 * dynamique au changement de type) et le predicat pur productAllowed.
 *
 * menu-form.js est du CommonJS (admin = racine CommonJS, comme pin-modal.js) :
 * import par defaut, init(doc) appele sur un document jsdom prepare.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

import menuForm from '../../src/public/admin/assets/js/menu-form.js';

// Catalogue minimal (base-only) avec categorie par produit, comme baseOptionsWithCategory.
const PRODUCTS = [
    { id: 14, name: 'Coca Cola', category: 'boissons' },
    { id: 15, name: 'Eau', category: 'boissons' },
    { id: 22, name: 'Moyenne Frite', category: 'frites' },
    { id: 30, name: 'Nuggets x4', category: 'encas' },
    { id: 40, name: 'Cesar Classic', category: 'salades' },
    { id: 47, name: 'Ketchup', category: 'sauces' },
    { id: 50, name: 'Brownie', category: 'desserts' },
    { id: 60, name: 'MC Wrap Chevre', category: 'wraps' },
    { id: 70, name: 'Le 280', category: 'burgers' },
];

// Mapping identique a MenuController::SLOT_CATEGORIES (source unique cote serveur).
const SLOT_CATEGORIES = {
    drink: ['boissons'],
    sauce: ['sauces'],
    dessert: ['desserts'],
    side: ['frites', 'encas', 'salades'],
    extra: ['boissons', 'frites', 'encas', 'wraps', 'salades', 'desserts', 'sauces'],
};

const SLOT_TYPES = ['drink', 'side', 'sauce', 'dessert', 'extra'];

// Monte un document jsdom porteur du formulaire menu, avec les data-* attendus par
// init(). `slots` pre-remplit le builder (edition) ; vide = un slot vierge (creation).
function setup(slots) {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body>' +
        '<form id="menu-form" method="post" action="/admin/menus">' +
        '  <div id="slot-builder"' +
        '    data-products=\'' + JSON.stringify(PRODUCTS) + '\'' +
        '    data-slot-types=\'' + JSON.stringify(SLOT_TYPES) + '\'' +
        '    data-slot-categories=\'' + JSON.stringify(SLOT_CATEGORIES) + '\'' +
        '    data-slots=\'' + JSON.stringify(slots || []) + '\'></div>' +
        '  <button type="button" id="add-slot">Ajouter un slot</button>' +
        '  <input type="hidden" name="slots_json" id="slots_json" value="">' +
        '  <button type="submit">Enregistrer</button>' +
        '</form></body></html>',
    );
    return dom.window.document;
}

// Noms des options affichees dans le 1er bloc slot (ordre du catalogue).
function optionNames(doc) {
    const block = doc.querySelector('.slot-block');
    return Array.prototype.map.call(block.querySelectorAll('.slot-option'), (cb) => {
        const id = Number(cb.value);
        return (PRODUCTS.find((p) => p.id === id) || {}).name;
    });
}

/* --- productAllowed (pur) ------------------------------------------------- */

test('productAllowed: un drink n accepte que les boissons', () => {
    assert.equal(menuForm.productAllowed({ category: 'boissons' }, SLOT_CATEGORIES, 'drink'), true);
    assert.equal(menuForm.productAllowed({ category: 'sauces' }, SLOT_CATEGORIES, 'drink'), false);
});

test('productAllowed: extra accepte tout sauf menus et burgers', () => {
    assert.equal(menuForm.productAllowed({ category: 'burgers' }, SLOT_CATEGORIES, 'extra'), false);
    assert.equal(menuForm.productAllowed({ category: 'menus' }, SLOT_CATEGORIES, 'extra'), false);
    assert.equal(menuForm.productAllowed({ category: 'wraps' }, SLOT_CATEGORIES, 'extra'), true);
    assert.equal(menuForm.productAllowed({ category: 'boissons' }, SLOT_CATEGORIES, 'extra'), true);
});

/* --- filtrage des options selon le type de slot --------------------------- */

test('slot drink (edition) : n affiche que les boissons', () => {
    const doc = setup([{ name: 'Boisson', slot_type: 'drink', is_required: 1, options: [14] }]);
    menuForm.init(doc);
    assert.deepEqual(optionNames(doc), ['Coca Cola', 'Eau']); // pas de frite/sauce/etc.
    // L option deja cochee (14) reste cochee.
    const checked = doc.querySelector('.slot-option:checked');
    assert.equal(checked.value, '14');
});

test('slot side : affiche frites + encas + salades, pas les boissons ni sauces', () => {
    const doc = setup([{ name: 'Accompagnement', slot_type: 'side', is_required: 1, options: [22] }]);
    menuForm.init(doc);
    assert.deepEqual(optionNames(doc), ['Moyenne Frite', 'Nuggets x4', 'Cesar Classic']);
});

test('slot extra : affiche tout sauf burgers (et menus, absent du catalogue de test)', () => {
    const doc = setup([{ name: 'Extra', slot_type: 'extra', is_required: 0, options: [] }]);
    menuForm.init(doc);
    const names = optionNames(doc);
    assert.ok(!names.includes('Le 280')); // burger exclu
    assert.ok(names.includes('Coca Cola') && names.includes('Ketchup') && names.includes('MC Wrap Chevre'));
});

/* --- re-filtrage dynamique au changement de type -------------------------- */

test('changer le type d un slot re-filtre les options proposees', () => {
    const doc = setup([{ name: 'Boisson', slot_type: 'drink', is_required: 1, options: [14] }]);
    menuForm.init(doc);
    assert.deepEqual(optionNames(doc), ['Coca Cola', 'Eau']);

    const typeSelect = doc.querySelector('.slot-type');
    typeSelect.value = 'sauce';
    typeSelect.dispatchEvent(new doc.defaultView.Event('change', { bubbles: true }));

    // Apres bascule en 'sauce', seules les sauces restent affichees.
    assert.deepEqual(optionNames(doc), ['Ketchup']);
    // L ancienne option boisson (14), non eligible en sauce, a disparu de la liste.
    assert.equal(doc.querySelector('.slot-option[value="14"]'), null);
});

test('changer de type conserve les options cochees encore eligibles', () => {
    // drink -> extra : extra inclut boissons, donc les boissons cochees restent cochees.
    const doc = setup([{ name: 'Boisson', slot_type: 'drink', is_required: 1, options: [14, 15] }]);
    menuForm.init(doc);

    const typeSelect = doc.querySelector('.slot-type');
    typeSelect.value = 'extra';
    typeSelect.dispatchEvent(new doc.defaultView.Event('change', { bubbles: true }));

    const checkedValues = Array.prototype.map.call(
        doc.querySelectorAll('.slot-option:checked'), (cb) => cb.value,
    ).sort();
    assert.deepEqual(checkedValues, ['14', '15']); // toujours cochees apres bascule
});

/* --- serialisation a la soumission ---------------------------------------- */

test('soumission : serialise les slots (options cochees) dans #slots_json', () => {
    const doc = setup([{ name: 'Boisson', slot_type: 'drink', is_required: 1, options: [14] }]);
    menuForm.init(doc);
    doc.getElementById('menu-form').dispatchEvent(
        new doc.defaultView.Event('submit', { bubbles: true, cancelable: true }),
    );
    const payload = JSON.parse(doc.getElementById('slots_json').value);
    assert.equal(payload.length, 1);
    assert.equal(payload[0].slot_type, 'drink');
    assert.deepEqual(payload[0].options, [14]);
});
