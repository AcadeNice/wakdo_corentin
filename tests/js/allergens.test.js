/*
 * Tests du module allergens du front borne (node:test + jsdom).
 *
 * F11b : la modale est passee d'une liste GENERALE des 14 categories INCO a
 * l'information REELLE du produit consulte. Ce que ces tests verrouillent en
 * priorite, c'est l'honnetete des trois etats possibles, qui ne doivent jamais se
 * confondre :
 *   - revu avec allergenes  -> on les nomme ;
 *   - revu sans allergene   -> on AFFIRME l'absence ;
 *   - non revu              -> on n'affirme rien et on renvoie vers l'equipe.
 *
 * Confondre les deux derniers, c'est annoncer "sans gluten" a quelqu'un a qui
 * personne n'a verifie quoi que ce soit. DOM simule par jsdom : aucun navigateur.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

import {
    buildAllergenInfoButton,
    openProductAllergenModal,
    closeAllergenModal,
} from '../../src/public/borne/assets/js/allergens.js';

let _seq = 0;

/* Reference INLINE : les descriptions INCO servies par /api/allergens. La modale
 * s'en sert pour expliquer un allergene sans que l'API produit ait a repeter ces
 * textes sur chaque ligne. */
function referenceFixture() {
    return [
        { id: 1, name: 'Gluten', description: 'Ble, seigle, orge, avoine.' },
        { id: 5, name: 'Arachides', description: "Et produits a base d'arachides." },
        { id: 7, name: 'Lait', description: 'Et produits a base de lait.' },
    ];
}

function product(overrides = {}) {
    return {
        nom: 'Cheeseburger',
        allergenes: [{ id: 1, code: 'gluten', name: 'Gluten' }, { id: 7, code: 'milk', name: 'Lait' }],
        allergenesComplets: true,
        ...overrides,
    };
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

test('la modale nomme le produit consulte', () => {
    setupDom();
    const overlay = openProductAllergenModal(product(), referenceFixture());

    assert.ok(document.body.contains(overlay));
    assert.equal(overlay.getAttribute('role'), 'dialog');
    assert.equal(overlay.getAttribute('aria-modal'), 'true');
    // Le client doit voir DE QUOI on parle : deux tuiles voisines n'ont pas les
    // memes allergenes, une modale anonyme serait ambigue.
    assert.ok(overlay.textContent.includes('Cheeseburger'));
});

test('un produit revu liste ses allergenes, et eux seuls', () => {
    setupDom();
    const overlay = openProductAllergenModal(product(), referenceFixture());

    const items = overlay.querySelectorAll('.allergen-modal-list li');
    assert.equal(items.length, 2);
    const text = overlay.textContent;
    assert.ok(text.includes('Gluten'));
    assert.ok(text.includes('Lait'));
    // Les arachides sont dans la reference mais PAS dans ce produit : les afficher
    // ferait renoncer a un produit sans raison.
    assert.ok(!text.includes('Arachides'));
});

test('la description INCO est reprise de la reference, pas de la ligne produit', () => {
    setupDom();
    const overlay = openProductAllergenModal(
        { nom: 'X', allergenes: [{ id: 7, code: 'milk', name: 'Lait' }], allergenesComplets: true },
        referenceFixture(),
    );

    const desc = overlay.querySelector('.allergen-desc');
    assert.ok(desc, 'la description doit etre rendue');
    assert.ok(desc.textContent.includes('produits a base de lait'));
});

test('un allergene absent de la reference reste affiche, sans description', () => {
    setupDom();
    // Robustesse : la reference peut echouer a charger (elle est best-effort cote
    // page). Le NOM vient de l'API produit, il ne depend pas d'elle -- l'information
    // vitale passe meme en mode degrade.
    const overlay = openProductAllergenModal(
        { nom: 'X', allergenes: [{ id: 99, code: 'inconnu', name: 'Moutarde' }], allergenesComplets: true },
        [],
    );

    assert.ok(overlay.textContent.includes('Moutarde'));
    assert.equal(overlay.querySelector('.allergen-desc'), null);
});

test('un produit revu SANS allergene affirme l absence', () => {
    setupDom();
    const overlay = openProductAllergenModal(
        product({ allergenes: [], allergenesComplets: true }),
        referenceFixture(),
    );

    const text = overlay.textContent.toLowerCase();
    assert.ok(text.includes('aucun'), 'une absence verifiee doit etre dite comme telle');
    assert.equal(overlay.querySelector('.allergen-modal-incomplete'), null);
});

test('un produit NON revu n affirme rien et renvoie vers l equipe', () => {
    setupDom();
    const overlay = openProductAllergenModal(
        product({ allergenes: [], allergenesComplets: false }),
        referenceFixture(),
    );

    const notice = overlay.querySelector('.allergen-modal-incomplete');
    assert.ok(notice, 'l etat non revu doit etre signale explicitement');
    assert.equal(notice.getAttribute('role'), 'alert');
    const text = notice.textContent.toLowerCase();
    assert.ok(text.includes('equipe'), 'le client doit etre renvoye vers un humain');
    // Le piege a eviter : dire "aucun allergene" alors que personne n'a verifie.
    assert.ok(!overlay.textContent.toLowerCase().includes('aucun des 14'));
});

test('un produit non revu qui porte deja des allergenes les montre ET avertit', () => {
    setupDom();
    const overlay = openProductAllergenModal(product({ allergenesComplets: false }), referenceFixture());

    // Partiel n'est pas rien : ce qui est connu est affiche (utile), et l'avertissement
    // dit que la liste peut etre incomplete (honnete).
    assert.equal(overlay.querySelectorAll('.allergen-modal-list li').length, 2);
    assert.ok(overlay.querySelector('.allergen-modal-incomplete'));
});

test('l avertissement de traces est toujours present', () => {
    setupDom();
    const overlay = openProductAllergenModal(product(), referenceFixture());

    // Une cuisine de restauration rapide manipule tous ces allergenes sur le meme
    // plan de travail. L'avertissement vaut dans les trois etats, y compris quand
    // le produit est revu et sans allergene.
    assert.ok(overlay.querySelector('.allergen-modal-traces'));
    assert.ok(overlay.textContent.toLowerCase().includes('cuisine'));
});

test('un produit sans champ allergene ne casse pas la modale', () => {
    setupDom();
    // Compat : une reponse d'API anterieure au lot ne porte ni allergenes ni drapeau.
    // Par defaut on considere l'information NON acquise, pas acquise-et-vide.
    const overlay = openProductAllergenModal({ nom: 'Ancien' }, referenceFixture());

    assert.ok(overlay);
    assert.ok(overlay.querySelector('.allergen-modal-incomplete'));
});

test('la modale se ferme via le bouton de fermeture', () => {
    setupDom();
    openProductAllergenModal(product(), referenceFixture());
    document.querySelector('.allergen-modal-close').click();
    assert.equal(document.querySelector('.allergen-modal-overlay'), null);
});

test('la modale se ferme par clic sur l overlay (hors contenu)', () => {
    const dom = setupDom();
    const overlay = openProductAllergenModal(product(), referenceFixture());
    overlay.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
    assert.equal(document.querySelector('.allergen-modal-overlay'), null);
});

test('la modale se ferme avec la touche Echap', () => {
    const dom = setupDom();
    openProductAllergenModal(product(), referenceFixture());
    document.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: 'Escape' }));
    assert.equal(document.querySelector('.allergen-modal-overlay'), null);
});

test('ouvrir deux fois ne duplique pas la modale (idempotent)', () => {
    setupDom();
    openProductAllergenModal(product(), referenceFixture());
    openProductAllergenModal(product(), referenceFixture());
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
