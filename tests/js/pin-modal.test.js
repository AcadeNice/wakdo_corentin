/*
 * Tests du modal de re-autorisation PIN du back-office (node:test + jsdom).
 *
 * Couvre : masquage du fieldset inline, ouverture du modal a la soumission d'un
 * formulaire d'action sensible (pas de soumission reelle), pre-remplissage de
 * l'email depuis <body data-user-email>, et la confirmation qui reinjecte
 * email + PIN dans les champs caches puis soumet. DOM simule par jsdom.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

// pin-modal.js est du CommonJS (admin = racine CommonJS) ; import par defaut.
import pinModal from '../../src/public/admin/assets/js/pin-modal.js';

function setup(email) {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body data-user-email="' + email + '">' +
        '<form id="f" method="post" action="/admin/roles/1/update">' +
        '  <fieldset id="pinfs">' +
        '    <input type="email" id="pin_email" name="pin_email">' +
        '    <input type="password" id="pin" name="pin">' +
        '  </fieldset>' +
        '  <button type="submit">Enregistrer</button>' +
        '</form></body></html>',
    );
    return dom;
}

function fireSubmit(dom, el) {
    el.dispatchEvent(new dom.window.Event('submit', { cancelable: true, bubbles: true }));
}

test('init masque le fieldset inline et insere un modal ferme', () => {
    const dom = setup('a@b.c');
    pinModal.init(dom.window.document);
    const doc = dom.window.document;
    assert.equal(doc.getElementById('pinfs').hidden, true);
    assert.ok(doc.querySelector('.pin-modal-overlay'));
    assert.equal(doc.querySelector('.pin-modal-overlay.open'), null);
});

test('soumettre le formulaire ouvre le modal (sans soumission reelle) et pre-remplit l email', () => {
    const dom = setup('manager@wakdo.local');
    const doc = dom.window.document;
    pinModal.init(doc);
    const form = doc.getElementById('f');
    let submitted = false;
    form.submit = () => { submitted = true; };

    fireSubmit(dom, form);

    assert.equal(doc.querySelector('.pin-modal-overlay').classList.contains('open'), true);
    assert.equal(submitted, false);
    assert.equal(doc.getElementById('pm-email').value, 'manager@wakdo.local');
});

test('confirmer reinjecte email + PIN et soumet ; refuse si champ vide', () => {
    const dom = setup('a@b.c');
    const doc = dom.window.document;
    pinModal.init(doc);
    const form = doc.getElementById('f');
    let submitted = false;
    form.submit = () => { submitted = true; };

    fireSubmit(dom, form);
    const modalForm = doc.querySelector('[data-pm-form]');

    // PIN vide -> pas de soumission, erreur affichee.
    doc.getElementById('pm-pin').value = '';
    fireSubmit(dom, modalForm);
    assert.equal(submitted, false);
    assert.equal(doc.querySelector('[data-pm-error]').hidden, false);

    // Email + PIN -> reinjection + soumission.
    doc.getElementById('pm-email').value = 'valid@wakdo.local';
    doc.getElementById('pm-pin').value = '4729';
    fireSubmit(dom, modalForm);
    assert.equal(doc.getElementById('pin_email').value, 'valid@wakdo.local');
    assert.equal(doc.getElementById('pin').value, '4729');
    assert.equal(submitted, true);
    assert.equal(doc.querySelector('.pin-modal-overlay').classList.contains('open'), false);
});
