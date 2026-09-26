/*
 * Tests de l'ecran produits de la borne (node:test + jsdom).
 *
 * page-products.js touche le DOM et fetch au chargement du module (DOMContentLoaded) :
 * import dynamique apres pose des globals jsdom, meme pattern que les autres pages.
 * Cible : customerCategoryTitle + categorySubtitle (PURES, audit maquette vs front
 * A6/A7), sans exercer le fetch reseau.
 */
import { test, before } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

let customerCategoryTitle, categorySubtitle;

before(async () => {
    const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
        url: 'https://kiosk.test/products.html?category=1',
    });
    global.window = dom.window;
    global.document = dom.window.document;
    global.localStorage = dom.window.localStorage;
    ({ customerCategoryTitle, categorySubtitle } =
        await import('../../src/public/borne/assets/js/page-products.js'));
});

/* --- customerCategoryTitle (A7) ------------------------------------------ */

test('customerCategoryTitle: minuscule le libelle capitalise en base ("Menus" -> "Nos menus")', () => {
    assert.equal(customerCategoryTitle('Menus'), 'Nos menus');
    assert.equal(customerCategoryTitle('Boissons'), 'Nos boissons');
});

test('customerCategoryTitle: laisse un libelle deja en minuscule inchange', () => {
    assert.equal(customerCategoryTitle('frites'), 'Nos frites');
});

/* --- categorySubtitle (A6) ------------------------------------------------ */

test('categorySubtitle: texte connu pour menus et boissons (releve maquette)', () => {
    assert.equal(categorySubtitle('menus'), 'Un sandwich, une friture ou une salade et une boisson');
    assert.equal(categorySubtitle('boissons'), 'Une petite soif, sucrée, légère, rafraîchissante');
});

test('categorySubtitle: null pour une categorie non couverte par la maquette (rien d invente)', () => {
    assert.equal(categorySubtitle('burgers'), null);
    assert.equal(categorySubtitle('sauces'), null);
});
