/*
 * Tests du panneau de commande persistant (node:test + jsdom).
 *
 * order-panel.js touche le DOM au chargement (auto-montage DOMContentLoaded) et
 * importe nav.js (idem) -> import DYNAMIQUE apres avoir pose les globals jsdom,
 * sinon le module jette a l'import en environnement Node nu.
 *
 * Cible principale : les fonctions PURES (lineCents, compositionLabels,
 * buildPanelModel). Plus un test de rendu via jsdom (etat vide, lignes, Payer
 * desactive panier vide, retrait de ligne).
 */
import { test, before, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

let lineCents, compositionLabels, buildPanelModel, renderOrderPanel;

before(async () => {
    const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
        url: 'https://kiosk.test/products.html', // une origine active localStorage
    });
    global.window = dom.window;
    global.document = dom.window.document;
    global.localStorage = dom.window.localStorage;
    ({ lineCents, compositionLabels, buildPanelModel, renderOrderPanel } =
        await import('../../src/public/borne/assets/js/order-panel.js'));
});

beforeEach(() => {
    global.localStorage.clear();
});

const simple = (over = {}) => ({
    id: 3, type: 'produit', categorie: 'burgers', libelle: 'Big Tasty',
    prix_cents: 890, quantite: 1, image: 'x.png', ...over,
});

const menu = (over = {}) => ({
    id: 1, type: 'menu', libelle: 'Menu Best Of', prix_cents: 750, quantite: 1,
    supplement_cents: 50, image: 'm.png',
    composition: {
        burger: { libelle: 'Big Mac', options: ['sans-oignon', 'avec-fromage'] },
        accompagnement: { libelle: 'Frites', taille: 'G' },
        boisson: { libelle: 'Coca', taille: 'M' },
        sauce: { libelle: 'Ketchup' },
    },
    ...over,
});

/* --- lineCents (pur) ----------------------------------------------------- */

test('lineCents: produit simple = prix * quantite', () => {
    assert.equal(lineCents(simple({ prix_cents: 890, quantite: 3 })), 2670);
});

test('lineCents: menu = (prix + supplement) * quantite', () => {
    assert.equal(lineCents(menu({ prix_cents: 750, supplement_cents: 50, quantite: 2 })), 1600);
});

/* --- compositionLabels (pur) --------------------------------------------- */

test('compositionLabels: undefined -> []', () => {
    assert.deepEqual(compositionLabels(undefined), []);
});

test('compositionLabels: liste burger(options)/accompagnement(taille)/boisson/sauce', () => {
    const labels = compositionLabels(menu().composition);
    assert.deepEqual(labels, [
        'Big Mac (sans oignon, avec fromage)',
        'Frites grande',
        'Coca',
        'Ketchup',
    ]);
});

test('compositionLabels: composants absents ignores sans jeter', () => {
    assert.deepEqual(compositionLabels({ sauce: { libelle: 'Mayo' } }), ['Mayo']);
});

/* --- buildPanelModel (pur) ----------------------------------------------- */

test('buildPanelModel: panier vide', () => {
    const m = buildPanelModel([]);
    assert.equal(m.empty, true);
    assert.equal(m.totalCents, 0);
    assert.equal(m.count, 0);
    assert.deepEqual(m.lines, []);
});

test('buildPanelModel: total = somme des lignes, count = somme des quantites', () => {
    const m = buildPanelModel([
        simple({ prix_cents: 890, quantite: 2 }),       // 1780
        menu({ prix_cents: 750, supplement_cents: 50, quantite: 1 }), // 800
    ]);
    assert.equal(m.empty, false);
    assert.equal(m.totalCents, 2580);
    assert.equal(m.count, 3);
    assert.equal(m.lines.length, 2);
    assert.equal(m.lines[0].lineCents, 1780);
    assert.equal(m.lines[0].options.length, 0);          // produit simple : pas d'options
    assert.equal(m.lines[1].lineCents, 800);
    assert.equal(m.lines[1].options.length, 4);          // menu : 4 puces
    assert.equal(m.lines[1].index, 1);                   // index preserve pour le retrait
});

/* --- renderOrderPanel (jsdom) -------------------------------------------- */

test('renderOrderPanel: panier vide -> message + Payer desactive', () => {
    const el = document.createElement('aside');
    renderOrderPanel(el);
    assert.match(el.innerHTML, /vide/i);
    assert.equal(el.querySelector('.order-panel__pay').getAttribute('aria-disabled'), 'true');
});

test('renderOrderPanel: lignes rendues + Payer actif + total affiche', () => {
    localStorage.setItem('wakdo_cart', JSON.stringify([simple({ prix_cents: 890, quantite: 2 })]));
    const el = document.createElement('aside');
    renderOrderPanel(el);
    assert.equal(el.querySelectorAll('.order-panel__line').length, 1);
    assert.equal(el.querySelector('.order-panel__pay').getAttribute('aria-disabled'), 'false');
    assert.match(el.querySelector('.order-panel__total-value').textContent, /17,80/);
});

test('renderOrderPanel: clic corbeille retire la ligne et re-rend', () => {
    localStorage.setItem('wakdo_cart', JSON.stringify([simple(), menu()]));
    const el = document.createElement('aside');
    renderOrderPanel(el);
    assert.equal(el.querySelectorAll('.order-panel__line').length, 2);
    el.querySelector('.order-panel__remove').click();   // retire la 1re ligne
    assert.equal(el.querySelectorAll('.order-panel__line').length, 1);
    assert.equal(JSON.parse(localStorage.getItem('wakdo_cart')).length, 1);
});

test('renderOrderPanel: libelle de ligne echappe (anti-XSS RG-T15)', () => {
    localStorage.setItem('wakdo_cart', JSON.stringify([simple({ libelle: '<img src=x onerror=alert(1)>' })]));
    const el = document.createElement('aside');
    renderOrderPanel(el);
    assert.match(el.innerHTML, /&lt;img/);
    assert.equal(el.querySelectorAll('img[onerror]').length, 0);
});
