/*
 * Tests du builder de recette du formulaire produit (back-office), node:test + jsdom.
 *
 * Chantier "recette dans le formulaire produit + import CSV" : le meme builder
 * (product-recipe.js) sert la page recette dediee (id="recipe-form") ET la
 * section "Composition" du formulaire produit (pas d'id de formulaire dedie,
 * d'ou closest('form') dans le module) -- les deux montages sont testes ici.
 *
 * product-recipe.js est du CommonJS (admin = racine CommonJS, comme
 * menu-form.js) : import par defaut, init(doc) appele sur un document jsdom
 * prepare.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

import productRecipe from '../../src/public/admin/assets/js/product-recipe.js';

const INGREDIENTS = [
    { id: 7, name: 'Cheddar', unit: 'tranche' },
    { id: 8, name: 'Cornichon', unit: 'unité' },
];

/**
 * Monte un document jsdom porteur du formulaire produit (SANS id de formulaire,
 * comme admin/products/form.php) avec le builder de recette. `composition`
 * pre-remplit le builder (edition) ; vide = aucune ligne (creation).
 */
function setupProductForm(composition, canCreateIngredient) {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body>' +
        '<form method="post" action="/admin/products">' +
        '  <fieldset>' +
        '    <div id="recipe-builder"' +
        '      data-ingredients=\'' + JSON.stringify(INGREDIENTS) + '\'' +
        '      data-composition=\'' + JSON.stringify(composition || []) + '\'' +
        '      data-can-create-ingredient="' + (canCreateIngredient ? '1' : '0') + '"></div>' +
        '    <button type="button" id="add-ingredient">Ajouter un ingrédient</button>' +
        '    <button type="button" id="add-new-ingredient">Créer un nouvel ingrédient</button>' +
        '  </fieldset>' +
        '  <input type="hidden" name="composition_json" id="composition_json" value="">' +
        '  <button type="submit">Enregistrer</button>' +
        '</form></body></html>',
    );
    return dom.window.document;
}

function submit(doc) {
    doc.querySelector('form').dispatchEvent(
        new doc.defaultView.Event('submit', { bubbles: true, cancelable: true }),
    );
}

test('recette vide (creation) : aucune ligne rendue, soumission serialise []', () => {
    const doc = setupProductForm([], true);
    productRecipe.init(doc);

    assert.equal(doc.querySelectorAll('.recipe-line').length, 0);
    submit(doc);
    assert.deepEqual(JSON.parse(doc.getElementById('composition_json').value), []);
});

test('composition initiale (edition) : une ligne par ingredient existant, pre-remplie', () => {
    const doc = setupProductForm([
        { ingredient_id: 7, quantity_normal: 2, quantity_maxi: 3, is_removable: 1, is_addable: 0, extra_price_cents: 50 },
    ], true);
    productRecipe.init(doc);

    assert.equal(doc.querySelectorAll('.recipe-line').length, 1);
    assert.equal(doc.querySelector('.recipe-ingredient').value, '7');
    assert.equal(doc.querySelector('.recipe-qn').value, '2');
    assert.equal(doc.querySelector('.recipe-qm').value, '3');
    assert.equal(doc.querySelector('.recipe-removable').checked, true);
});

test('bouton Ajouter un ingredient : ajoute une ligne "ingredient existant"', () => {
    const doc = setupProductForm([], true);
    productRecipe.init(doc);

    doc.getElementById('add-ingredient').click();

    assert.equal(doc.querySelectorAll('.recipe-line').length, 1);
    assert.ok(doc.querySelector('.recipe-line-existing'));
    assert.equal(doc.querySelectorAll('.recipe-ingredient option').length, 2);
});

test('soumission : une ligne ingredient existant se serialise avec ingredient_id', () => {
    const doc = setupProductForm([], true);
    productRecipe.init(doc);
    doc.getElementById('add-ingredient').click();
    doc.querySelector('.recipe-ingredient').value = '8';
    doc.querySelector('.recipe-qn').value = '4';
    doc.querySelector('.recipe-qm').value = '4';
    doc.querySelector('.recipe-addable').checked = true;

    submit(doc);

    const payload = JSON.parse(doc.getElementById('composition_json').value);
    assert.equal(payload.length, 1);
    assert.equal(payload[0].ingredient_id, 8);
    assert.equal(payload[0].quantity_normal, 4);
    assert.equal(payload[0].is_addable, 1);
    assert.equal(payload[0].new_ingredient, undefined);
});

test('bouton Creer un nouvel ingredient : absent quand la permission manque', () => {
    const doc = setupProductForm([], false);
    productRecipe.init(doc);

    doc.getElementById('add-new-ingredient').click();

    // canCreateIngredient=false : aucun listener attache, le clic ne cree aucune ligne.
    assert.equal(doc.querySelectorAll('.recipe-line').length, 0);
});

test('bouton Creer un nouvel ingredient : ajoute une ligne "nouvel ingredient" quand autorise', () => {
    const doc = setupProductForm([], true);
    productRecipe.init(doc);

    doc.getElementById('add-new-ingredient').click();

    assert.equal(doc.querySelectorAll('.recipe-line').length, 1);
    assert.ok(doc.querySelector('.recipe-line-new'));
});

test('soumission : une ligne "nouvel ingredient" complete se serialise en new_ingredient', () => {
    const doc = setupProductForm([], true);
    productRecipe.init(doc);
    doc.getElementById('add-new-ingredient').click();
    doc.querySelector('.recipe-new-name').value = 'Sauce maison';
    doc.querySelector('.recipe-new-unit').value = 'g';
    doc.querySelector('.recipe-new-pack').value = '1000';
    doc.querySelector('.recipe-qn').value = '20';
    doc.querySelector('.recipe-qm').value = '20';

    submit(doc);

    const payload = JSON.parse(doc.getElementById('composition_json').value);
    assert.equal(payload.length, 1);
    assert.equal(payload[0].ingredient_id, undefined);
    assert.equal(payload[0].new_ingredient.name, 'Sauce maison');
    assert.equal(payload[0].new_ingredient.unit, 'g');
    assert.equal(payload[0].new_ingredient.pack_size, 1000);
    assert.equal(payload[0].quantity_normal, 20);
});

test('soumission : une ligne "nouvel ingredient" laissee sans nom est ignoree', () => {
    const doc = setupProductForm([], true);
    productRecipe.init(doc);
    doc.getElementById('add-new-ingredient').click();
    // Nom laisse vide.

    submit(doc);

    assert.deepEqual(JSON.parse(doc.getElementById('composition_json').value), []);
});

test('bouton Retirer : supprime la ligne du DOM et de la serialisation', () => {
    const doc = setupProductForm([
        { ingredient_id: 7, quantity_normal: 1, quantity_maxi: 1, is_removable: 0, is_addable: 0, extra_price_cents: 0 },
    ], true);
    productRecipe.init(doc);

    assert.equal(doc.querySelectorAll('.recipe-line').length, 1);
    doc.querySelector('.recipe-remove').click();
    assert.equal(doc.querySelectorAll('.recipe-line').length, 0);

    submit(doc);
    assert.deepEqual(JSON.parse(doc.getElementById('composition_json').value), []);
});

test('supplement : saisi en euros a l ecran, serialise en centimes pour le serveur', () => {
    // L'equipier compte en euros, comme dans le champ "Prix" du formulaire produit ;
    // le serveur, lui, ne connait que des centiemes d'euro entiers.
    const doc = setupProductForm([], true);
    productRecipe.init(doc);
    doc.getElementById('add-ingredient').click();
    doc.querySelector('.recipe-extra').value = '1,50';

    submit(doc);

    assert.equal(JSON.parse(doc.getElementById('composition_json').value)[0].extra_price_cents, 150);
});

test('supplement : le point decimal et une valeur vide sont acceptes', () => {
    const doc = setupProductForm([], true);
    productRecipe.init(doc);
    doc.getElementById('add-ingredient').click();
    doc.querySelector('.recipe-extra').value = '0.80';
    submit(doc);
    assert.equal(JSON.parse(doc.getElementById('composition_json').value)[0].extra_price_cents, 80);

    doc.querySelector('.recipe-extra').value = '';
    submit(doc);
    assert.equal(JSON.parse(doc.getElementById('composition_json').value)[0].extra_price_cents, 0);
});

test('supplement : une ligne existante reaffiche son montant en euros', () => {
    const doc = setupProductForm([
        { ingredient_id: 7, quantity_normal: 1, quantity_maxi: 1, is_removable: 0, is_addable: 1, extra_price_cents: 50 },
    ], true);
    productRecipe.init(doc);

    assert.equal(doc.querySelector('.recipe-extra').value, '0,5');

    // Aller-retour sans toucher au champ : le montant d'origine est conserve.
    submit(doc);
    assert.equal(JSON.parse(doc.getElementById('composition_json').value)[0].extra_price_cents, 50);
});

test('systeme de design : aucune ligne ne porte de style en ligne, les classes du lot 0 sont posees', () => {
    // Le builder posait ses couleurs et ses largeurs en dur (#ddd, #999, 7rem) hors
    // du systeme de design ; elles vivent desormais dans admin.css.
    const doc = setupProductForm([], true);
    productRecipe.init(doc);
    doc.getElementById('add-ingredient').click();
    doc.getElementById('add-new-ingredient').click();

    for (const noeud of doc.querySelectorAll('.recipe-line, .recipe-line *')) {
        assert.equal(noeud.getAttribute('style'), null, `style en ligne sur ${noeud.tagName}`);
    }
    assert.equal(doc.querySelectorAll('.recipe-line__fields').length, 2);
    assert.ok(doc.querySelector('.recipe-field--wide'), 'le choix de l ingredient occupe la largeur restante');
    assert.ok(doc.querySelector('.recipe-note'), 'la note du nouvel ingredient utilise sa propre classe');
});

test('accessibilite : chaque controle d une ligne est dans une etiquette qui l entoure', () => {
    const doc = setupProductForm([
        { ingredient_id: 7, quantity_normal: 1, quantity_maxi: 1, is_removable: 0, is_addable: 0, extra_price_cents: 0 },
    ], true);
    productRecipe.init(doc);

    const ligne = doc.querySelector('.recipe-line');
    for (const controle of ligne.querySelectorAll('input, select')) {
        assert.ok(controle.closest('label'), `controle sans etiquette : ${controle.className}`);
    }
    // Les deux cases a cocher portent .recipe-check, qui leur donne le plancher de
    // 24x24px exige par WCAG 2.2 (critere 2.5.8).
    assert.equal(ligne.querySelectorAll('label.recipe-check input[type="checkbox"]').length, 2);
});

test('meme builder sur la page recette dediee (id="recipe-form", sans bouton nouvel ingredient conditionne)', () => {
    // Reproduit recipe.php : formulaire avec id dedie, closest('form') doit
    // quand meme le retrouver (pas de regression sur l'ecran historique).
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body>' +
        '<form method="post" action="/admin/products/5/recipe" id="recipe-form">' +
        '  <div id="recipe-builder" data-ingredients=\'' + JSON.stringify(INGREDIENTS) + '\' data-composition=\'[]\' data-can-create-ingredient="1"></div>' +
        '  <button type="button" id="add-ingredient">Ajouter un ingrédient</button>' +
        '  <input type="hidden" name="composition_json" id="composition_json" value="">' +
        '</form></body></html>',
    );
    const doc = dom.window.document;
    productRecipe.init(doc);

    doc.getElementById('add-ingredient').click();
    doc.querySelector('.recipe-ingredient').value = '7';

    doc.getElementById('recipe-form').dispatchEvent(
        new doc.defaultView.Event('submit', { bubbles: true, cancelable: true }),
    );

    const payload = JSON.parse(doc.getElementById('composition_json').value);
    assert.equal(payload.length, 1);
    assert.equal(payload[0].ingredient_id, 7);
});
