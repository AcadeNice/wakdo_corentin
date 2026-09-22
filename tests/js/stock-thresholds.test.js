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
        '  data-capacity="200" data-low="15" data-critical="5">Regler les seuils</button>' +
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
    assert.match(stockThresholds.validate('0', '15', '5'), /capacite/i);
    // Pourcentage hors 0-100.
    assert.match(stockThresholds.validate('100', '120', '5'), /alerte/i);
    assert.match(stockThresholds.validate('100', '15', '200'), /critique/i);
    // Critique non strictement inferieur a l'alerte.
    assert.match(stockThresholds.validate('100', '10', '10'), /strictement inferieur/i);
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
