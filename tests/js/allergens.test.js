/*
 * Tests du module allergens du front borne (node:test + jsdom).
 *
 * Couvre le contrat de PR-C : la liste fixe des 14 allergenes INCO (data borne,
 * se branchera sur /api/allergens au swap P4), la construction du bouton "i", et
 * la modale GENERALE (ouverture, listing des 14, fermeture par bouton/overlay/
 * Escape, idempotence). DOM simule par jsdom : aucun navigateur requis.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { JSDOM } from 'jsdom';

import {
    buildAllergenInfoButton,
    openAllergenModal,
    closeAllergenModal,
} from '../../src/public/borne/assets/js/allergens.js';

const here = dirname(fileURLToPath(import.meta.url));
const allergensJsonPath = join(here, '../../src/public/borne/data/allergens.json');

function setupDom() {
    const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>');
    global.window = dom.window;
    global.document = dom.window.document;
    return dom;
}

function loadAllergensFixture() {
    return JSON.parse(readFileSync(allergensJsonPath, 'utf8'));
}

test('data/allergens.json liste exactement les 14 allergenes INCO', () => {
    const list = loadAllergensFixture();
    assert.ok(Array.isArray(list));
    assert.equal(list.length, 14);
    for (const a of list) {
        assert.equal(typeof a.id, 'number');
        assert.equal(typeof a.name, 'string');
        assert.ok(a.name.trim().length > 0);
    }
    const names = list.map((a) => a.name);
    assert.equal(new Set(names).size, 14, 'noms uniques');
    // Quelques jalons de la liste reglementaire (UE INCO 1169/2011 annexe II).
    const joined = names.join(' | ').toLowerCase();
    for (const expected of ['gluten', 'lait', 'arachide', 'soja', 'mollusque']) {
        assert.ok(joined.includes(expected), `attendu: ${expected}`);
    }
});

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

test('openAllergenModal affiche une modale listant les 14 allergenes', () => {
    setupDom();
    const list = loadAllergensFixture();
    const overlay = openAllergenModal(list);

    assert.ok(document.body.contains(overlay));
    assert.equal(overlay.getAttribute('role'), 'dialog');
    assert.equal(overlay.getAttribute('aria-modal'), 'true');
    const items = overlay.querySelectorAll('.allergen-modal-list li');
    assert.equal(items.length, 14);
    assert.ok(overlay.textContent.toLowerCase().includes('lait'));
});

test('la modale se ferme via le bouton de fermeture', () => {
    setupDom();
    openAllergenModal(loadAllergensFixture());
    document.querySelector('.allergen-modal-close').click();
    assert.equal(document.querySelector('.allergen-modal-overlay'), null);
});

test('la modale se ferme par clic sur l overlay (hors contenu)', () => {
    const dom = setupDom();
    const overlay = openAllergenModal(loadAllergensFixture());
    overlay.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
    assert.equal(document.querySelector('.allergen-modal-overlay'), null);
});

test('la modale se ferme avec la touche Echap', () => {
    const dom = setupDom();
    openAllergenModal(loadAllergensFixture());
    document.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: 'Escape' }));
    assert.equal(document.querySelector('.allergen-modal-overlay'), null);
});

test('ouvrir deux fois ne duplique pas la modale (idempotent)', () => {
    setupDom();
    const list = loadAllergensFixture();
    openAllergenModal(list);
    openAllergenModal(list);
    assert.equal(document.querySelectorAll('.allergen-modal-overlay').length, 1);
    closeAllergenModal();
    assert.equal(document.querySelector('.allergen-modal-overlay'), null);
});
