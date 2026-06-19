/*
 * Tests du bandeau categories (node:test + jsdom).
 *
 * Import dynamique apres pose des globals jsdom (le module enregistre un
 * DOMContentLoaded au chargement). Cible : buildStripModel (PUR) + renderStripInto
 * (DOM sans fetch).
 */
import { test, before } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

let buildStripModel, renderStripInto;

before(async () => {
    const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
        url: 'https://kiosk.test/products.html?category=3&mode=sur-place',
    });
    global.window = dom.window;
    global.document = dom.window.document;
    global.localStorage = dom.window.localStorage;
    ({ buildStripModel, renderStripInto } =
        await import('../../src/public/borne/assets/js/category-strip.js'));
});

const cats = () => ([
    { id: 1, title: 'menus', slug: 'menus', image: 'cat/menus.png' },
    { id: 3, title: 'burgers', slug: 'burgers', image: 'cat/burgers.png' },
    { id: 2, title: 'boissons', slug: 'boissons', image: 'cat/boissons.png' },
]);

/* --- buildStripModel (pur) ----------------------------------------------- */

test('buildStripModel: marque active par id, preserve les champs', () => {
    const m = buildStripModel(cats(), 3);
    assert.equal(m.length, 3);
    assert.deepEqual(m[1], { id: 3, slug: 'burgers', title: 'burgers', image: 'cat/burgers.png', active: true });
    assert.equal(m[0].active, false);
    assert.equal(m[2].active, false);
});

test('buildStripModel: aucun id correspondant -> tout inactif', () => {
    assert.equal(buildStripModel(cats(), 999).filter(c => c.active).length, 0);
});

/* --- renderStripInto (jsdom) --------------------------------------------- */

test('renderStripInto: une carte par categorie + 2 fleches', () => {
    const el = document.createElement('nav');
    renderStripInto(el, buildStripModel(cats(), 3), 'sur-place');
    assert.equal(el.querySelectorAll('.category-strip__item').length, 3);
    assert.ok(el.querySelector('.category-strip__arrow--prev'));
    assert.ok(el.querySelector('.category-strip__arrow--next'));
});

test('renderStripInto: la categorie active porte is-active + aria-current', () => {
    const el = document.createElement('nav');
    renderStripInto(el, buildStripModel(cats(), 3), null);
    const active = el.querySelectorAll('.category-strip__item.is-active');
    assert.equal(active.length, 1);
    assert.equal(active[0].getAttribute('aria-current'), 'true');
    assert.match(active[0].getAttribute('aria-label'), /Burgers/);
});

test('renderStripInto: href = categorie par id, mode propage', () => {
    const el = document.createElement('nav');
    renderStripInto(el, buildStripModel(cats(), 1), 'a-emporter');
    const first = el.querySelector('.category-strip__item');
    assert.match(first.getAttribute('href'), /products\.html\?category=1&mode=a-emporter/);
});

test('renderStripInto: sans mode, pas de &mode dans l href', () => {
    const el = document.createElement('nav');
    renderStripInto(el, buildStripModel(cats(), 1), null);
    assert.doesNotMatch(el.querySelector('.category-strip__item').getAttribute('href'), /mode=/);
});

test('renderStripInto: titre echappe (anti-XSS)', () => {
    const el = document.createElement('nav');
    renderStripInto(el, buildStripModel([{ id: 9, title: '<b>x</b>', slug: 'x', image: 'i.png' }], 9), null);
    assert.match(el.innerHTML, /&lt;b&gt;/);
    assert.equal(el.querySelectorAll('b').length, 0);
});
