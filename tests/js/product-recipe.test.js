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
 * Catalogue et libelles pour les tests du filtre par famille (chantier
 * "recette filtree par categorie du produit"). Un ingredient de famille null
 * (Sauce mystere) sert a verifier qu'un ingredient non classe reste toujours
 * visible, quel que soit le filtre.
 */
const FAMILY_LABELS = { pain: 'Pain', viande: 'Viande', fromage: 'Fromage', legume: 'Légume' };
const FAMILY_INGREDIENTS = [
    { id: 101, name: 'Pain burger', unit: 'unité', family: 'pain' },
    { id: 102, name: 'Steak haché', unit: 'unité', family: 'viande' },
    { id: 103, name: 'Cheddar', unit: 'tranche', family: 'fromage' },
    { id: 104, name: 'Sauce mystère', unit: 'unité', family: null },
];

/**
 * Monte un document jsdom porteur du formulaire produit (SANS id de formulaire,
 * comme admin/products/form.php) avec le builder de recette. `composition`
 * pre-remplit le builder (edition) ; vide = aucune ligne (creation).
 *
 * `options` (toutes optionnelles, retro-compatible avec les appels a 2 arguments
 * des tests existants) :
 * - ingredients : catalogue a servir (defaut INGREDIENTS, sans famille)
 * - categoryOptions : options du select #category_id (defaut deux categories)
 * - selectedCategory : categorie deja choisie (valeur de #category_id)
 * - withCategorySelect : false pour omettre #category_id (formulaire sans categorie)
 * - categoryFamilies / ingredientFamilies : correspondances (contrat ProductController)
 * - categoryFamiliesRaw / ingredientFamiliesRaw : attribut brut, pour simuler une
 *   donnee illisible (JSON invalide) sans passer par JSON.stringify
 * - productCategoryId : pose data-product-category-id sur #recipe-builder EN PLUS
 *   de #category_id (sert uniquement au test de priorite entre les deux sources
 *   de categorie ; en production form.php ne pose jamais cet attribut)
 */
function setupProductForm(composition, canCreateIngredient, options) {
    const opts = options || {};
    const ingredients = opts.ingredients || INGREDIENTS;
    const categoryOptions = opts.categoryOptions || [
        { id: 1, name: 'Burgers' },
        { id: 2, name: 'Desserts' },
    ];
    const selectedCategory = opts.selectedCategory !== undefined && opts.selectedCategory !== null
        ? String(opts.selectedCategory)
        : '';
    const categoryFamiliesRaw = opts.categoryFamiliesRaw !== undefined
        ? opts.categoryFamiliesRaw
        : JSON.stringify(opts.categoryFamilies !== undefined ? opts.categoryFamilies : {});
    const ingredientFamiliesRaw = opts.ingredientFamiliesRaw !== undefined
        ? opts.ingredientFamiliesRaw
        : JSON.stringify(opts.ingredientFamilies !== undefined ? opts.ingredientFamilies : {});
    const withCategorySelect = opts.withCategorySelect !== false;
    const productCategoryIdAttr = opts.productCategoryId !== undefined
        ? ' data-product-category-id=\'' + String(opts.productCategoryId) + '\''
        : '';

    const categoryFieldHtml = withCategorySelect
        ? '  <div class="form-group">' +
          '    <select id="category_id" name="category_id">' +
          '      <option value="">-- choisir --</option>' +
          categoryOptions.map(function (c) {
              return '<option value="' + c.id + '"' + (String(c.id) === selectedCategory ? ' selected' : '') + '>' + c.name + '</option>';
          }).join('') +
          '    </select>' +
          '  </div>'
        : '';

    const dom = new JSDOM(
        '<!DOCTYPE html><html><body>' +
        '<form method="post" action="/admin/products">' +
        categoryFieldHtml +
        '  <fieldset>' +
        '    <div id="recipe-builder"' +
        '      data-ingredients=\'' + JSON.stringify(ingredients) + '\'' +
        '      data-composition=\'' + JSON.stringify(composition || []) + '\'' +
        '      data-can-create-ingredient="' + (canCreateIngredient ? '1' : '0') + '"' +
        '      data-category-families=\'' + categoryFamiliesRaw + '\'' +
        '      data-ingredient-families=\'' + ingredientFamiliesRaw + '\'' +
        productCategoryIdAttr +
        '    ></div>' +
        '    <button type="button" id="add-ingredient">Ajouter un ingrédient</button>' +
        '    <button type="button" id="add-new-ingredient">Créer un nouvel ingrédient</button>' +
        '  </fieldset>' +
        '  <input type="hidden" name="composition_json" id="composition_json" value="">' +
        '  <button type="submit">Enregistrer</button>' +
        '</form></body></html>',
    );
    return dom.window.document;
}

/**
 * Monte un document jsdom porteur de la page recette dediee (admin/products/
 * recipe.php, id="recipe-form") : PAS de #category_id (la categorie du produit
 * est deja fixee), transmise via data-product-category-id sur #recipe-builder.
 *
 * `options` : ingredients, categoryFamilies(Raw), ingredientFamilies(Raw) --
 * memes cles que setupProductForm. `productCategoryId` : categorie du produit
 * (omis = aucune categorie connue, comme ProductController::renderRecipe()
 * quand product.category_id vaut 0).
 */
function setupRecipePage(composition, options) {
    const opts = options || {};
    const ingredients = opts.ingredients || INGREDIENTS;
    const categoryFamiliesRaw = opts.categoryFamiliesRaw !== undefined
        ? opts.categoryFamiliesRaw
        : JSON.stringify(opts.categoryFamilies !== undefined ? opts.categoryFamilies : {});
    const ingredientFamiliesRaw = opts.ingredientFamiliesRaw !== undefined
        ? opts.ingredientFamiliesRaw
        : JSON.stringify(opts.ingredientFamilies !== undefined ? opts.ingredientFamilies : {});
    const productCategoryIdAttr = opts.productCategoryId !== undefined
        ? ' data-product-category-id=\'' + String(opts.productCategoryId) + '\''
        : '';

    const dom = new JSDOM(
        '<!DOCTYPE html><html><body>' +
        '<form method="post" action="/admin/products/5/recipe" id="recipe-form">' +
        '  <div id="recipe-builder"' +
        '    data-ingredients=\'' + JSON.stringify(ingredients) + '\'' +
        '    data-composition=\'' + JSON.stringify(composition || []) + '\'' +
        '    data-can-create-ingredient="1"' +
        '    data-category-families=\'' + categoryFamiliesRaw + '\'' +
        '    data-ingredient-families=\'' + ingredientFamiliesRaw + '\'' +
        productCategoryIdAttr +
        '  ></div>' +
        '  <button type="button" id="add-ingredient">Ajouter un ingrédient</button>' +
        '  <button type="button" id="add-new-ingredient">Créer un nouvel ingrédient</button>' +
        '  <input type="hidden" name="composition_json" id="composition_json" value="">' +
        '</form></body></html>',
    );
    return dom.window.document;
}

function submit(doc) {
    doc.querySelector('form').dispatchEvent(
        new doc.defaultView.Event('submit', { bubbles: true, cancelable: true }),
    );
}

/** Bascule une case a cocher et notifie le module par un evenement 'change'
 * explicite, plutot que de compter sur .click() (meme idiome que submit() : un
 * evenement DOM emis a la main, pas une simulation d'activation navigateur). */
function check(doc, checkbox, checked) {
    checkbox.checked = checked;
    checkbox.dispatchEvent(new doc.defaultView.Event('change', { bubbles: true }));
}

/** Change la categorie choisie et notifie le module (meme idiome que check()). */
function chooseCategory(doc, value) {
    const select = doc.getElementById('category_id');
    select.value = value;
    select.dispatchEvent(new doc.defaultView.Event('change', { bubbles: true }));
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

/*
 * Filtre du picker d'ingredients par famille, selon la categorie choisie
 * (#category_id). Filtre SOUPLE (jamais une interdiction) : une case a cocher
 * "Afficher tous les ingredients" le desactive a tout moment, et une ligne de
 * recette deja presente n'est jamais retiree au nom du filtre.
 */

test('filtre par famille : une categorie restreinte ne propose que ses familles autorisees (+ les non classes)', () => {
    const doc = setupProductForm([], true, {
        ingredients: FAMILY_INGREDIENTS,
        ingredientFamilies: FAMILY_LABELS,
        categoryFamilies: { 1: ['viande'] },
        selectedCategory: 1,
    });
    productRecipe.init(doc);

    doc.getElementById('add-ingredient').click();

    const values = Array.from(doc.querySelectorAll('.recipe-ingredient option')).map((o) => o.value).sort();
    // viande (102) + l'ingredient de famille null (104, toujours visible) ; pain (101)
    // et fromage (103) sont hors de la famille autorisee pour cette categorie.
    assert.deepEqual(values, ['102', '104']);
});

test('categorie absente de la correspondance categoryFamilies : aucun filtre, tout le catalogue est propose', () => {
    const doc = setupProductForm([], true, {
        ingredients: FAMILY_INGREDIENTS,
        ingredientFamilies: FAMILY_LABELS,
        categoryFamilies: { 1: ['pain'] }, // la categorie 2 n'a pas d'entree
        selectedCategory: 2,
    });
    productRecipe.init(doc);

    doc.getElementById('add-ingredient').click();

    const values = Array.from(doc.querySelectorAll('.recipe-ingredient option')).map((o) => o.value).sort();
    assert.deepEqual(values, ['101', '102', '103', '104']);
});

test('aucune categorie encore choisie : aucun filtre, tout le catalogue est propose', () => {
    const doc = setupProductForm([], true, {
        ingredients: FAMILY_INGREDIENTS,
        ingredientFamilies: FAMILY_LABELS,
        categoryFamilies: { 1: ['pain'] },
        selectedCategory: '',
    });
    productRecipe.init(doc);

    doc.getElementById('add-ingredient').click();

    const values = Array.from(doc.querySelectorAll('.recipe-ingredient option')).map((o) => o.value).sort();
    assert.deepEqual(values, ['101', '102', '103', '104']);
});

test('ingredient de famille null : toujours visible, meme quand aucune autre famille ne correspond', () => {
    const doc = setupProductForm([], true, {
        ingredients: FAMILY_INGREDIENTS,
        ingredientFamilies: FAMILY_LABELS,
        categoryFamilies: { 1: ['dessert'] }, // aucun ingredient du catalogue n'est 'dessert'
        selectedCategory: 1,
    });
    productRecipe.init(doc);

    doc.getElementById('add-ingredient').click();

    const values = Array.from(doc.querySelectorAll('.recipe-ingredient option')).map((o) => o.value);
    assert.deepEqual(values, ['104']);
});

test('changement de categorie : le picker deja affiche se recalcule sans rechargement de page', () => {
    const doc = setupProductForm([], true, {
        ingredients: FAMILY_INGREDIENTS,
        ingredientFamilies: FAMILY_LABELS,
        categoryFamilies: { 1: ['viande'] },
        selectedCategory: '', // aucune categorie au depart : aucun filtre
    });
    productRecipe.init(doc);
    doc.getElementById('add-ingredient').click();

    let values = Array.from(doc.querySelectorAll('.recipe-ingredient option')).map((o) => o.value).sort();
    assert.deepEqual(values, ['101', '102', '103', '104']);

    chooseCategory(doc, '1');

    values = Array.from(doc.querySelectorAll('.recipe-ingredient option')).map((o) => o.value).sort();
    assert.deepEqual(values, ['102', '104']);
});

test('case "Afficher tous les ingredients" : desactive le filtre, puis le reactive une fois redecochee', () => {
    const doc = setupProductForm([], true, {
        ingredients: FAMILY_INGREDIENTS,
        ingredientFamilies: FAMILY_LABELS,
        categoryFamilies: { 1: ['viande'] },
        selectedCategory: 1,
    });
    productRecipe.init(doc);
    doc.getElementById('add-ingredient').click();

    let values = Array.from(doc.querySelectorAll('.recipe-ingredient option')).map((o) => o.value).sort();
    assert.deepEqual(values, ['102', '104']);

    check(doc, doc.getElementById('recipe-show-all-ingredients'), true);
    values = Array.from(doc.querySelectorAll('.recipe-ingredient option')).map((o) => o.value).sort();
    assert.deepEqual(values, ['101', '102', '103', '104']);

    check(doc, doc.getElementById('recipe-show-all-ingredients'), false);
    values = Array.from(doc.querySelectorAll('.recipe-ingredient option')).map((o) => o.value).sort();
    assert.deepEqual(values, ['102', '104']);
});

test('libelles de familles absents ou illisibles : aucun filtre, jamais de liste vide, pas de commande de filtre affichee', () => {
    const doc = setupProductForm([], true, {
        ingredients: FAMILY_INGREDIENTS,
        ingredientFamiliesRaw: '{ceci-n-est-pas-du-json',
        categoryFamilies: { 1: ['viande'] },
        selectedCategory: 1,
    });
    productRecipe.init(doc);

    doc.getElementById('add-ingredient').click();

    const values = Array.from(doc.querySelectorAll('.recipe-ingredient option')).map((o) => o.value).sort();
    assert.deepEqual(values, ['101', '102', '103', '104']);
    // Sans taxonomie lisible, rien a filtrer : la commande de filtre ne s'affiche pas.
    assert.equal(doc.getElementById('recipe-show-all-ingredients'), null);
    assert.equal(doc.getElementById('recipe-filter-count'), null);
});

test('correspondance categorie -> famille illisible : aucune restriction appliquee, le regroupement reste actif', () => {
    const doc = setupProductForm([], true, {
        ingredients: FAMILY_INGREDIENTS,
        ingredientFamilies: FAMILY_LABELS,
        categoryFamiliesRaw: 'pas-du-json-valide',
        selectedCategory: 1,
    });
    productRecipe.init(doc);

    doc.getElementById('add-ingredient').click();

    const values = Array.from(doc.querySelectorAll('.recipe-ingredient option')).map((o) => o.value).sort();
    assert.deepEqual(values, ['101', '102', '103', '104']);
    assert.ok(doc.querySelector('.recipe-ingredient optgroup'));
});

test('une ligne de recette existante dont l ingredient sort du filtre de sa categorie est conservee', () => {
    const doc = setupProductForm([
        { ingredient_id: 103, quantity_normal: 1, quantity_maxi: 1, is_removable: 0, is_addable: 0, extra_price_cents: 0 },
    ], true, {
        ingredients: FAMILY_INGREDIENTS,
        ingredientFamilies: FAMILY_LABELS,
        categoryFamilies: { 1: ['viande'] }, // fromage (103) n'est pas autorise pour cette categorie
        selectedCategory: 1,
    });
    productRecipe.init(doc);

    // La ligne existe toujours, avec son ingredient d'origine selectionne.
    assert.equal(doc.querySelectorAll('.recipe-line').length, 1);
    assert.equal(doc.querySelector('.recipe-ingredient').value, '103');
    assert.ok(doc.querySelector('.recipe-ingredient option[value="103"]'));

    // Aucune erreur, aucune perte a la soumission.
    submit(doc);
    const payload = JSON.parse(doc.getElementById('composition_json').value);
    assert.equal(payload.length, 1);
    assert.equal(payload[0].ingredient_id, 103);
});

test('le compteur annonce combien d ingredients sont montres sur combien', () => {
    const doc = setupProductForm([], true, {
        ingredients: FAMILY_INGREDIENTS,
        ingredientFamilies: FAMILY_LABELS,
        categoryFamilies: { 1: ['viande'] },
        selectedCategory: 1,
    });
    productRecipe.init(doc);

    const counter = doc.getElementById('recipe-filter-count');
    assert.ok(counter, 'le compteur doit exister des lors qu une taxonomie de familles est fournie');
    assert.equal(counter.textContent, '2 ingrédient(s) affiché(s) sur 4, filtrés selon la catégorie.');

    check(doc, doc.getElementById('recipe-show-all-ingredients'), true);
    assert.equal(counter.textContent, '4 ingrédient(s) affiché(s), aucun filtre.');
});

test('regroupement : les options du picker sont classees par famille, les non classes en fin de liste', () => {
    const doc = setupProductForm([], true, {
        ingredients: FAMILY_INGREDIENTS,
        ingredientFamilies: FAMILY_LABELS, // Légume n'a aucun ingredient : groupe absent
        categoryFamilies: {},
        selectedCategory: '',
    });
    productRecipe.init(doc);
    doc.getElementById('add-ingredient').click();

    const groups = Array.from(doc.querySelectorAll('.recipe-ingredient optgroup')).map((g) => g.label);
    assert.deepEqual(groups, ['Pain', 'Viande', 'Fromage', 'Ingrédients non classés']);
});

test('accessibilite du filtre : la case a cocher est entouree d une etiquette, le compteur est une region live discrete', () => {
    const doc = setupProductForm([], true, {
        ingredients: FAMILY_INGREDIENTS,
        ingredientFamilies: FAMILY_LABELS,
        categoryFamilies: { 1: ['viande'] },
        selectedCategory: 1,
    });
    productRecipe.init(doc);

    const checkbox = doc.getElementById('recipe-show-all-ingredients');
    assert.ok(checkbox.closest('label'), 'la case a cocher doit etre entouree d une etiquette reelle');

    const counter = doc.getElementById('recipe-filter-count');
    assert.equal(counter.getAttribute('role'), 'status');
    assert.equal(counter.getAttribute('aria-live'), 'polite');
});

test('sans #category_id dans la page (ex. recipe.php) : aucune erreur, le picker reste utilisable', () => {
    // Reproduit le montage de la page recette dediee, qui n'a pas de champ
    // categorie : le filtre n'a rien a lire, il ne doit pas faire planter le
    // reste du builder (ajout de ligne, soumission).
    const doc = setupProductForm([], true, {
        ingredients: FAMILY_INGREDIENTS,
        ingredientFamilies: FAMILY_LABELS,
        categoryFamilies: { 1: ['viande'] },
        withCategorySelect: false,
    });

    assert.doesNotThrow(() => productRecipe.init(doc));

    doc.getElementById('add-ingredient').click();
    const values = Array.from(doc.querySelectorAll('.recipe-ingredient option')).map((o) => o.value).sort();
    assert.deepEqual(values, ['101', '102', '103', '104']);
});

/*
 * Page recette dediee (admin/products/recipe.php) : pas de #category_id (la
 * categorie du produit est deja fixee), transmise via data-product-category-id
 * sur #recipe-builder. Meme filtre souple que le formulaire produit, mais rien
 * a ecouter -- un seul calcul au chargement.
 */

test('page recette dediee : le filtre s applique a la categorie du produit (data-product-category-id)', () => {
    const doc = setupRecipePage([], {
        ingredients: FAMILY_INGREDIENTS,
        ingredientFamilies: FAMILY_LABELS,
        categoryFamilies: { 3: ['viande'] },
        productCategoryId: 3,
    });
    productRecipe.init(doc);

    doc.getElementById('add-ingredient').click();

    const values = Array.from(doc.querySelectorAll('.recipe-ingredient option')).map((o) => o.value).sort();
    assert.deepEqual(values, ['102', '104']); // viande + famille null, comme sur le formulaire produit
});

test('page recette dediee : data-product-category-id absent alors que les libelles sont la => groupes affiches, aucun filtre', () => {
    const doc = setupRecipePage([], {
        ingredients: FAMILY_INGREDIENTS,
        ingredientFamilies: FAMILY_LABELS,
        categoryFamilies: { 3: ['viande'] },
        // productCategoryId omis : comme un produit dont category_id vaut 0.
    });
    productRecipe.init(doc);

    doc.getElementById('add-ingredient').click();

    const values = Array.from(doc.querySelectorAll('.recipe-ingredient option')).map((o) => o.value).sort();
    assert.deepEqual(values, ['101', '102', '103', '104'], 'aucun filtre : le catalogue complet reste propose');
    assert.ok(doc.querySelector('.recipe-ingredient optgroup'), 'le regroupement par famille reste actif');

    const counter = doc.getElementById('recipe-filter-count');
    assert.ok(counter, 'la commande de filtre doit exister : les libelles sont fournis');
    assert.equal(counter.textContent, '4 ingrédient(s) affiché(s), aucun filtre.');
});

test('#category_id et data-product-category-id tous deux presents : le selecteur du formulaire produit gagne', () => {
    // Scenario de priorite (ne se produit pas en production : form.php ne pose
    // jamais data-product-category-id) -- verifie explicitement l ordre impose.
    const doc = setupProductForm([], true, {
        ingredients: FAMILY_INGREDIENTS,
        ingredientFamilies: FAMILY_LABELS,
        categoryFamilies: { 1: ['viande'], 9: ['fromage'] },
        selectedCategory: 1,
        productCategoryId: 9,
    });
    productRecipe.init(doc);

    doc.getElementById('add-ingredient').click();

    const values = Array.from(doc.querySelectorAll('.recipe-ingredient option')).map((o) => o.value).sort();
    // Si data-product-category-id (9, fromage) avait gagne : ['103','104'].
    // Le selecteur (1, viande) doit l'emporter.
    assert.deepEqual(values, ['102', '104']);
});

test('page recette dediee : la case "Afficher tous les ingredients" fonctionne aussi', () => {
    const doc = setupRecipePage([], {
        ingredients: FAMILY_INGREDIENTS,
        ingredientFamilies: FAMILY_LABELS,
        categoryFamilies: { 3: ['viande'] },
        productCategoryId: 3,
    });
    productRecipe.init(doc);
    doc.getElementById('add-ingredient').click();

    let values = Array.from(doc.querySelectorAll('.recipe-ingredient option')).map((o) => o.value).sort();
    assert.deepEqual(values, ['102', '104']);

    check(doc, doc.getElementById('recipe-show-all-ingredients'), true);
    values = Array.from(doc.querySelectorAll('.recipe-ingredient option')).map((o) => o.value).sort();
    assert.deepEqual(values, ['101', '102', '103', '104']);

    check(doc, doc.getElementById('recipe-show-all-ingredients'), false);
    values = Array.from(doc.querySelectorAll('.recipe-ingredient option')).map((o) => o.value).sort();
    assert.deepEqual(values, ['102', '104']);
});

test('page recette dediee : une ligne existante dont l ingredient sort du filtre est conservee', () => {
    const doc = setupRecipePage([
        { ingredient_id: 103, quantity_normal: 1, quantity_maxi: 1, is_removable: 0, is_addable: 0, extra_price_cents: 0 },
    ], {
        ingredients: FAMILY_INGREDIENTS,
        ingredientFamilies: FAMILY_LABELS,
        categoryFamilies: { 3: ['viande'] }, // fromage (103) n'est pas autorise pour cette categorie
        productCategoryId: 3,
    });
    productRecipe.init(doc);

    assert.equal(doc.querySelectorAll('.recipe-line').length, 1);
    assert.equal(doc.querySelector('.recipe-ingredient').value, '103');
    assert.ok(doc.querySelector('.recipe-ingredient option[value="103"]'));

    doc.getElementById('recipe-form').dispatchEvent(
        new doc.defaultView.Event('submit', { bubbles: true, cancelable: true }),
    );
    const payload = JSON.parse(doc.getElementById('composition_json').value);
    assert.equal(payload.length, 1);
    assert.equal(payload[0].ingredient_id, 103);
});
