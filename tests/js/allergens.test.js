/*
 * Tests du module allergens du front borne (node:test + jsdom).
 *
 * Couvre : la construction du bouton "i", la modale GENERALE (ouverture, listing,
 * fermeture par bouton/overlay/Escape, idempotence) et le chargement via l'API
 * (loadAllergens consomme /api/allergens et ramene la forme borne). Les cas de
 * rendu utilisent une fixture INLINE pour rester independants de la source de
 * donnees. DOM simule par jsdom : aucun navigateur requis.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

import {
    buildAllergenInfoButton,
    openAllergenModal,
    closeAllergenModal,
} from '../../src/public/borne/assets/js/allergens.js';

let _seq = 0;

/* Fixture INLINE : un echantillon des 14 allergenes INCO a la forme borne
 * { id, name, description }. Suffisant pour couvrir le rendu de la modale sans
 * dependre d'un fichier de donnees. */
function allergensFixture() {
    return [
        { id: 1, name: 'Cereales contenant du gluten', description: 'Ble, seigle, orge, avoine.' },
        { id: 5, name: 'Arachides', description: "Et produits a base d'arachides." },
        { id: 6, name: 'Soja', description: 'Et produits a base de soja.' },
        { id: 7, name: 'Lait', description: 'Et produits a base de lait.' },
        { id: 14, name: 'Mollusques', description: 'Et produits a base de mollusques.' },
    ];
}

function setupDom() {
    const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>');
    global.window = dom.window;
    global.document = dom.window.document;
    return dom;
}

test('buildAllergenInfoButton cree un bouton "i" qui declenche onOpen', () => {
    setupDom();
    let opened = 0;
    const btn = buildAllergenInfoButton(() => { opened += 1; });

    assert.equal(btn.tagName, 'BUTTON');
    assert.equal(btn.type, 'button');
    assert.ok(btn.className.includes('allergen-info-btn'));
    assert.ok(btn.getAttribute('aria-label'));

    btn.click();
    assert.equal(opened, 1, 'le clic ouvre la modale');
});

test('openAllergenModal affiche une modale listant les allergenes fournis', () => {
    setupDom();
    const list = allergensFixture();
    const overlay = openAllergenModal(list);

    assert.ok(document.body.contains(overlay));
    assert.equal(overlay.getAttribute('role'), 'dialog');
    assert.equal(overlay.getAttribute('aria-modal'), 'true');
    const items = overlay.querySelectorAll('.allergen-modal-list li');
    assert.equal(items.length, list.length);
    assert.ok(overlay.textContent.toLowerCase().includes('lait'));
});

test('openAllergenModal affiche la description quand elle est fournie', () => {
    setupDom();
    const overlay = openAllergenModal([{ id: 7, name: 'Lait', description: 'Et produits a base de lait.' }]);
    const desc = overlay.querySelector('.allergen-desc');
    assert.ok(desc, 'la description doit etre rendue');
    assert.ok(desc.textContent.toLowerCase().includes('lait'));
});

test('la modale se ferme via le bouton de fermeture', () => {
    setupDom();
    openAllergenModal(allergensFixture());
    document.querySelector('.allergen-modal-close').click();
    assert.equal(document.querySelector('.allergen-modal-overlay'), null);
});

test('la modale se ferme par clic sur l overlay (hors contenu)', () => {
    const dom = setupDom();
    const overlay = openAllergenModal(allergensFixture());
    overlay.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
    assert.equal(document.querySelector('.allergen-modal-overlay'), null);
});

test('la modale se ferme avec la touche Echap', () => {
    const dom = setupDom();
    openAllergenModal(allergensFixture());
    document.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: 'Escape' }));
    assert.equal(document.querySelector('.allergen-modal-overlay'), null);
});

test('ouvrir deux fois ne duplique pas la modale (idempotent)', () => {
    setupDom();
    const list = allergensFixture();
    openAllergenModal(list);
    openAllergenModal(list);
    assert.equal(document.querySelectorAll('.allergen-modal-overlay').length, 1);
    closeAllergenModal();
    assert.equal(document.querySelector('.allergen-modal-overlay'), null);
});

test('loadAllergens consomme /api/allergens, deballe {data} et ramene la forme borne', async () => {
    const calls = [];
    // Reponse canonique de l'API : enveloppe { data, total }, entrees id/code/name/description.
    const apiRows = [
        { id: 1, code: 'gluten', name: 'Cereales contenant du gluten', description: 'Ble, seigle, orge.' },
        { id: 7, code: 'lait', name: 'Lait', description: 'Et produits a base de lait.' },
    ];
    global.fetch = async (url) => {
        calls.push(url);
        if (url !== '/api/allergens') throw new Error(`fetch inattendu: ${url}`);
        return { ok: true, status: 200, json: async () => ({ data: apiRows, total: apiRows.length }) };
    };

    const { loadAllergens } = await import(`../../src/public/borne/assets/js/data.js?case=allergens${_seq++}`);
    const list = await loadAllergens();

    assert.ok(calls.includes('/api/allergens'), 'doit fetch /api/allergens');
    assert.equal(list.length, 2);
    // Forme borne : name + description presents, code ignore.
    assert.deepEqual(list[0], { id: 1, name: 'Cereales contenant du gluten', description: 'Ble, seigle, orge.' });
    assert.equal(list[1].name, 'Lait');
    assert.equal(list[1].description, 'Et produits a base de lait.');
});
