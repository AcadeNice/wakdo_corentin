/*
 * Tests du module a11y du front borne (node:test + jsdom).
 *
 * Couvre la bascule de police adaptee aux dyslexiques (RGAA Cr 1.c.2) : lecture de
 * la preference persistee, application de la classe .dys-font sur la racine,
 * injection du bouton (idempotente, aria-pressed reflete l'etat), et le cycle de
 * clic (flip de l'etat + persistance). DOM simule par jsdom : aucun navigateur requis.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

import {
    STORAGE_KEY,
    ROOT_CLASS,
    isDyslexiaEnabled,
    applyDyslexiaPreference,
    initDyslexiaToggle,
} from '../../src/public/borne/assets/js/a11y.js';

function setupDom() {
    const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>');
    global.window = dom.window;
    global.document = dom.window.document;
    return dom;
}

/** Storage en memoire, contrat compatible localStorage. */
function fakeStorage(initial = {}) {
    const m = new Map(Object.entries(initial));
    return {
        getItem: (k) => (m.has(k) ? m.get(k) : null),
        setItem: (k, v) => { m.set(k, String(v)); },
        removeItem: (k) => { m.delete(k); },
        _dump: () => Object.fromEntries(m),
    };
}

test('isDyslexiaEnabled : vrai seulement si la cle vaut "1"', () => {
    assert.equal(isDyslexiaEnabled(fakeStorage({ [STORAGE_KEY]: '1' })), true);
    assert.equal(isDyslexiaEnabled(fakeStorage({ [STORAGE_KEY]: '0' })), false);
    assert.equal(isDyslexiaEnabled(fakeStorage()), false);
    assert.equal(isDyslexiaEnabled(null), false);
});

test('isDyslexiaEnabled : un storage qui jette renvoie false (mode prive)', () => {
    const throwing = { getItem() { throw new Error('denied'); } };
    assert.equal(isDyslexiaEnabled(throwing), false);
});

test('applyDyslexiaPreference : ajoute/retire la classe sur la racine', () => {
    setupDom();
    const root = document.documentElement;
    applyDyslexiaPreference(true, root);
    assert.ok(root.classList.contains(ROOT_CLASS));
    applyDyslexiaPreference(false, root);
    assert.ok(!root.classList.contains(ROOT_CLASS));
});

test('initDyslexiaToggle : applique la preference persistee et reflete aria-pressed', () => {
    setupDom();
    const storage = fakeStorage({ [STORAGE_KEY]: '1' });
    const btn = initDyslexiaToggle({ storage, root: document.documentElement, container: document.body });

    assert.ok(btn, 'un bouton est injecte');
    assert.equal(btn.getAttribute('aria-pressed'), 'true');
    assert.ok(document.documentElement.classList.contains(ROOT_CLASS), 'classe appliquee au chargement');
    assert.ok(btn.getAttribute('aria-label'));
});

test('initDyslexiaToggle : defaut = police de base (pas de preference)', () => {
    setupDom();
    const storage = fakeStorage();
    const btn = initDyslexiaToggle({ storage, root: document.documentElement, container: document.body });

    assert.equal(btn.getAttribute('aria-pressed'), 'false');
    assert.ok(!document.documentElement.classList.contains(ROOT_CLASS));
});

test('initDyslexiaToggle : idempotent (pas de doublon de bouton)', () => {
    setupDom();
    const storage = fakeStorage();
    initDyslexiaToggle({ storage, root: document.documentElement, container: document.body });
    const second = initDyslexiaToggle({ storage, root: document.documentElement, container: document.body });

    assert.equal(second, null, 'le second appel ne reinjecte pas');
    assert.equal(document.querySelectorAll('[data-a11y-dys-toggle]').length, 1);
});

test('clic sur le bouton : bascule l etat, la classe et persiste', () => {
    setupDom();
    const storage = fakeStorage();
    const btn = initDyslexiaToggle({ storage, root: document.documentElement, container: document.body });

    // Activation.
    btn.click();
    assert.equal(btn.getAttribute('aria-pressed'), 'true');
    assert.ok(document.documentElement.classList.contains(ROOT_CLASS));
    assert.equal(storage.getItem(STORAGE_KEY), '1');

    // Desactivation.
    btn.click();
    assert.equal(btn.getAttribute('aria-pressed'), 'false');
    assert.ok(!document.documentElement.classList.contains(ROOT_CLASS));
    assert.equal(storage.getItem(STORAGE_KEY), '0');
});
