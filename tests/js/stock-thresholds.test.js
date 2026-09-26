/*
 * Tests du reglage rapide des seuils de stock (F13) du back-office (node:test + jsdom).
 *
 * Couvre la logique testable du module : pre-remplissage de la modale depuis les
 * data-attributes du bouton + pointage de l'action POST sur l'id, ouverture/fermeture,
 * et le garde-fou client validate() (capacite >= 1, % 0-100, critique < alerte strict).
 * La modale est rendue serveur (VRAI form POST) ; le module n'ajoute pas de fetch.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

// stock-thresholds.js est du CommonJS (admin = racine CommonJS) ; import par defaut.
import stockThresholds from '../../src/public/admin/assets/js/stock-thresholds.js';

function setup() {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body>' +
        '<button data-threshold-open data-id="7" data-name="Buns" ' +
        '  data-capacity="200" data-low="15" data-critical="5">Régler les seuils</button>' +
        '<div class="pin-modal-overlay" data-threshold-modal>' +
        '  <form method="post" action="" data-threshold-form>' +
        '    <input type="hidden" name="_csrf" value="tok">' +
        '    <input type="number" id="th-capacity" name="stock_capacity">' +
        '    <input type="number" id="th-low" name="low_stock_pct">' +
        '    <input type="number" id="th-critical" name="critical_stock_pct">' +
        '    <p data-threshold-error hidden></p>' +
        '    <button type="button" data-threshold-cancel>Annuler</button>' +
        '    <button type="submit">Enregistrer les seuils</button>' +
        '  </form>' +
        '</div>' +
        '</body></html>',
    );
    return dom;
}

function fire(dom, el, type) {
    el.dispatchEvent(new dom.window.Event(type, { cancelable: true, bubbles: true }));
}

test('validate accepte une configuration coherente et rejette les cas evidents', () => {
    // Coherent.
    assert.equal(stockThresholds.validate('200', '15', '5'), null);
    // Capacite < 1.
    assert.match(stockThresholds.validate('0', '15', '5'), /capacité/i);
    // Pourcentage hors 0-100.
    assert.match(stockThresholds.validate('100', '120', '5'), /alerte/i);
    assert.match(stockThresholds.validate('100', '15', '200'), /critique/i);
    // Critique non strictement inferieur a l'alerte.
    assert.match(stockThresholds.validate('100', '10', '10'), /strictement inférieur/i);
    // Saisies non entieres refusees (miroir de ctype_digit cote serveur).
    assert.notEqual(stockThresholds.validate('', '15', '5'), null);
    assert.notEqual(stockThresholds.validate('10.5', '15', '5'), null);
});

test('le clic sur un bouton pre-remplit la modale et pointe l action POST sur l id', () => {
    const dom = setup();
    const doc = dom.window.document;
    stockThresholds.init(doc);

    fire(dom, doc.querySelector('[data-threshold-open]'), 'click');

    const overlay = doc.querySelector('[data-threshold-modal]');
    assert.equal(overlay.classList.contains('open'), true);
    assert.equal(doc.querySelector('[data-threshold-form]').getAttribute('action'), '/admin/ingredients/7/thresholds');
    assert.equal(doc.getElementById('th-capacity').value, '200');
    assert.equal(doc.getElementById('th-low').value, '15');
    assert.equal(doc.getElementById('th-critical').value, '5');
});

test('soumettre une configuration incoherente bloque le POST et affiche l erreur', () => {
    const dom = setup();
    const doc = dom.window.document;
    stockThresholds.init(doc);

    fire(dom, doc.querySelector('[data-threshold-open]'), 'click');
    // Critique >= alerte : le garde-fou client doit annuler la soumission.
    doc.getElementById('th-critical').value = '15';
    const form = doc.querySelector('[data-threshold-form]');
    const evt = new dom.window.Event('submit', { cancelable: true, bubbles: true });
    form.dispatchEvent(evt);

    assert.equal(evt.defaultPrevented, true);
    assert.equal(doc.querySelector('[data-threshold-error]').hidden, false);
});

test('soumettre une configuration coherente laisse le form POST partir', () => {
    const dom = setup();
    const doc = dom.window.document;
    stockThresholds.init(doc);

    fire(dom, doc.querySelector('[data-threshold-open]'), 'click');
    const form = doc.querySelector('[data-threshold-form]');
    const evt = new dom.window.Event('submit', { cancelable: true, bubbles: true });
    form.dispatchEvent(evt);

    // Valeurs pre-remplies coherentes (200/15/5) : pas de blocage client (POST reel).
    assert.equal(evt.defaultPrevented, false);
});

test('Annuler ferme la modale', () => {
    const dom = setup();
    const doc = dom.window.document;
    stockThresholds.init(doc);

    fire(dom, doc.querySelector('[data-threshold-open]'), 'click');
    assert.equal(doc.querySelector('[data-threshold-modal]').classList.contains('open'), true);

    fire(dom, doc.querySelector('[data-threshold-cancel]'), 'click');
    assert.equal(doc.querySelector('[data-threshold-modal]').classList.contains('open'), false);
});

test('init sans modale (role sans stock.manage) ne plante pas', () => {
    const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>');
    assert.doesNotThrow(() => stockThresholds.init(dom.window.document));
});

/*
 * Repere visuel "ligne modifiee" (lot 3, design-system.md §2.6). Deux moitiees testees
 * separement : la pose de la cle (soumission d'un formulaire porteur de data-row-key,
 * ou imbrique dans une ligne qui en porte une) et sa lecture (chargement suivant,
 * consommation a usage unique, application de la classe .row-highlight).
 */
// sessionStorage n'est expose par jsdom que sur une origine non-opaque : chaque JSDOM
// ci-dessous recoit donc une url (comme le navigateur reel, jamais about:blank).
const ORIGIN = { url: 'http://admin.wakdo.test/' };

test.describe('initRowHighlight - pose de la cle en sessionStorage a la soumission', () => {
    test('un formulaire portant data-row-key depose la cle avant de naviguer', () => {
        const dom = new JSDOM(
            '<!DOCTYPE html><html><body>' +
            '<form data-row-key="ingredient:42"><button type="submit">Go</button></form>' +
            '</body></html>',
            ORIGIN,
        );
        const doc = dom.window.document;
        stockThresholds.initRowHighlight(doc);

        const evt = new dom.window.Event('submit', { cancelable: true, bubbles: true });
        doc.querySelector('form').dispatchEvent(evt);

        assert.equal(dom.window.sessionStorage.getItem('wakdo-row-highlight'), 'ingredient:42');
    });

    test('un formulaire IMBRIQUE dans une ligne porteuse de data-row-key depose la cle de la ligne (cas du bouton Desactiver/Reactiver, imbrique dans .stock-list__row)', () => {
        const dom = new JSDOM(
            '<!DOCTYPE html><html><body>' +
            '<li data-row-key="ingredient:7">' +
            '  <form class="stock-list__inline-form"><button type="submit">Désactiver</button></form>' +
            '</li>' +
            '</body></html>',
            ORIGIN,
        );
        const doc = dom.window.document;
        stockThresholds.initRowHighlight(doc);

        const evt = new dom.window.Event('submit', { cancelable: true, bubbles: true });
        doc.querySelector('form').dispatchEvent(evt);

        assert.equal(dom.window.sessionStorage.getItem('wakdo-row-highlight'), 'ingredient:7');
    });

    test('un formulaire hors de toute ligne ne depose rien (pas de plantage)', () => {
        const dom = new JSDOM(
            '<!DOCTYPE html><html><body><form><button type="submit">Go</button></form></body></html>',
            ORIGIN,
        );
        const doc = dom.window.document;
        stockThresholds.initRowHighlight(doc);

        assert.doesNotThrow(() => {
            doc.querySelector('form').dispatchEvent(new dom.window.Event('submit', { cancelable: true, bubbles: true }));
        });
        assert.equal(dom.window.sessionStorage.getItem('wakdo-row-highlight'), null);
    });
});

test.describe('initRowHighlight - lecture au chargement suivant', () => {
    test('une cle valide en attente met .row-highlight sur CHAQUE ligne correspondante, puis est consommee (usage unique)', () => {
        const dom = new JSDOM(
            '<!DOCTYPE html><html><body>' +
            '<div data-row-key="ingredient:42" class="stock-card"></div>' +
            '<li data-row-key="ingredient:42" class="stock-list__row"></li>' +
            '<li data-row-key="ingredient:99" class="stock-list__row"></li>' +
            '</body></html>',
            ORIGIN,
        );
        const doc = dom.window.document;
        dom.window.sessionStorage.setItem('wakdo-row-highlight', 'ingredient:42');

        stockThresholds.initRowHighlight(doc);

        const matched = doc.querySelectorAll('[data-row-key="ingredient:42"]');
        assert.equal(matched.length, 2);
        matched.forEach((el) => assert.equal(el.classList.contains('row-highlight'), true));
        assert.equal(doc.querySelector('[data-row-key="ingredient:99"]').classList.contains('row-highlight'), false);
        // Usage unique : la cle est effacee, un rechargement ulterieur ne re-applique rien.
        assert.equal(dom.window.sessionStorage.getItem('wakdo-row-highlight'), null);
    });

    test('aucune cle en attente : aucune ligne marquee, ne plante pas', () => {
        // <tr> reel, dans un <table> (une balise de ligne isolee du <body> serait
        // deplacee par l'algorithme de construction d'arbre HTML5 - pas un cas reel).
        const dom = new JSDOM(
            '<!DOCTYPE html><html><body><table><tbody><tr data-row-key="product:5"></tr></tbody></table></body></html>',
            ORIGIN,
        );
        const doc = dom.window.document;
        assert.doesNotThrow(() => stockThresholds.initRowHighlight(doc));
        assert.equal(doc.querySelector('[data-row-key="product:5"]').classList.contains('row-highlight'), false);
    });

    test('cle presente mais aucune ligne ne correspond (ex. produit supprime) : aucun plantage', () => {
        const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', ORIGIN);
        dom.window.sessionStorage.setItem('wakdo-row-highlight', 'product:404');
        assert.doesNotThrow(() => stockThresholds.initRowHighlight(dom.window.document));
    });

    test('cle malformee (injection de selecteur CSS) : rejetee sans etre utilisee comme selecteur', () => {
        const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', ORIGIN);
        dom.window.sessionStorage.setItem('wakdo-row-highlight', 'ingredient:1"][data-x="y');
        assert.doesNotThrow(() => stockThresholds.initRowHighlight(dom.window.document));
    });

    test('sessionStorage indisponible (navigation privee) : ne plante pas', () => {
        const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>');
        Object.defineProperty(dom.window, 'sessionStorage', {
            get() { throw new Error('SecurityError'); },
        });
        assert.doesNotThrow(() => stockThresholds.initRowHighlight(dom.window.document));
    });
});
