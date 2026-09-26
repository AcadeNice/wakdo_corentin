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
import formValidation from '../../src/public/admin/assets/js/form-validation.js';

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

test('init retire required des champs PIN masques : le navigateur ne bloque plus l envoi', () => {
    // Avant correctif (observe le 2026-09-23 dans Chromium sur l'ajustement de stock ;
    // meme balisage sur l'inventaire, l'annulation de commande et les suppressions de
    // produit et de menu) : ces champs etaient required ET masques. Le navigateur
    // refusait alors l'envoi AVANT l'evenement submit (champ invalide non focalisable),
    // et le modal ne s'ouvrait pas. Le modal controle desormais leur presence
    // lui-meme (« Email et PIN requis pour confirmer. »).
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body data-user-email="a@b.c">' +
        '<form id="f" method="post" action="/admin/ingredients/3/adjust">' +
        '  <input type="number" id="delta" name="delta" value="5" required>' +
        '  <fieldset id="pinfs">' +
        '    <input type="email" id="pin_email" name="pin_email" required>' +
        '    <input type="password" id="pin" name="pin" required>' +
        '  </fieldset>' +
        '  <button type="submit">Enregistrer</button>' +
        '</form></body></html>',
    );
    const doc = dom.window.document;
    pinModal.init(doc);

    assert.equal(doc.getElementById('pin_email').required, false);
    assert.equal(doc.getElementById('pin').required, false);
    assert.equal(doc.getElementById('delta').required, true, 'les champs visibles gardent leurs contraintes');
    assert.equal(doc.getElementById('f').checkValidity(), true);
});

test('l erreur PIN renvoyee par le serveur reste visible et s affiche dans le modal', () => {
    // Un PIN faux recharge la page avec son message DANS le bloc que le modal masque :
    // sans ce deplacement, l'equipier ne voyait aucune explication.
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body data-user-email="a@b.c">' +
        '<form id="f" method="post" action="/admin/ingredients/3/adjust">' +
        '  <input type="number" id="delta" name="delta" value="5">' +
        '  <fieldset id="pinfs">' +
        '    <input type="email" id="pin_email" name="pin_email" required>' +
        '    <input type="password" id="pin" name="pin" required>' +
        '    <p class="form-error" id="server-pin-error">PIN incorrect.</p>' +
        '  </fieldset>' +
        '  <button type="submit">Enregistrer</button>' +
        '</form></body></html>',
    );
    const doc = dom.window.document;
    pinModal.init(doc);

    const serverError = doc.getElementById('server-pin-error');
    assert.equal(serverError.closest('[hidden]'), null, 'hors du bloc masque');

    fireSubmit(dom, doc.getElementById('f'));
    const modalError = doc.querySelector('[data-pm-error]');
    assert.equal(modalError.hidden, false);
    assert.equal(modalError.textContent, 'PIN incorrect.');
});

/**
 * Formulaire produit (admin/products/form.php) : le serveur n'exige le PIN que si le
 * prix ou la TVA a change (ProductController::update, RG-T13/8.2). Le formulaire le
 * declare par data-pin-when-changed ; les tests ci-dessous verifient les trois cas.
 */
function setupConditional(serverPinError) {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body data-user-email="a@b.c">' +
        '<form id="f" method="post" action="/admin/products/9" data-pin-when-changed="price_cents,vat_rate">' +
        '  <input type="text" id="name" name="name" value="Burger">' +
        '  <input type="text" id="price_cents" name="price_cents" value="6,90">' +
        '  <select id="vat_rate" name="vat_rate"><option value="100" selected>10%</option><option value="55">5,5%</option></select>' +
        '  <fieldset id="pinfs">' +
        '    <input type="email" id="pin_email" name="pin_email">' +
        '    <input type="password" id="pin" name="pin">' +
        (serverPinError ? '    <p class="form-error" id="server-pin-error">Email ou PIN invalide.</p>' : '') +
        '  </fieldset>' +
        '  <button type="submit">Enregistrer</button>' +
        '</form></body></html>',
    );

    return dom;
}

test('confirmation conditionnelle : changer le nom ou la recette ne demande aucun code', () => {
    const dom = setupConditional(false);
    const doc = dom.window.document;
    pinModal.init(doc);
    const form = doc.getElementById('f');

    doc.getElementById('name').value = 'Burger maison';
    const event = new dom.window.Event('submit', { cancelable: true, bubbles: true });
    form.dispatchEvent(event);

    assert.equal(event.defaultPrevented, false, 'la soumission suit son cours');
    assert.equal(doc.querySelector('.pin-modal-overlay.open'), null, 'aucune fenetre ouverte');
});

test('confirmation conditionnelle : changer le prix ouvre la fenetre de code', () => {
    const dom = setupConditional(false);
    const doc = dom.window.document;
    pinModal.init(doc);
    const form = doc.getElementById('f');
    let submitted = false;
    form.submit = () => { submitted = true; };

    doc.getElementById('price_cents').value = '7,50';
    fireSubmit(dom, form);

    assert.equal(doc.querySelector('.pin-modal-overlay').classList.contains('open'), true);
    assert.equal(submitted, false);
});

test('confirmation conditionnelle : changer la TVA ouvre aussi la fenetre', () => {
    const dom = setupConditional(false);
    const doc = dom.window.document;
    pinModal.init(doc);
    const form = doc.getElementById('f');
    form.submit = () => {};

    doc.getElementById('vat_rate').value = '55';
    fireSubmit(dom, form);

    assert.equal(doc.querySelector('.pin-modal-overlay').classList.contains('open'), true);
});

test('confirmation conditionnelle : un code deja refuse par le serveur reste exige', () => {
    // Le serveur a rejete le PIN et reaffiche la page AVEC le prix modifie : la
    // comparaison cliente le verrait "inchange". Le message du serveur arme quand
    // meme la fenetre -- la condition cliente ne peut pas laisser passer une action
    // que le serveur juge sensible.
    const dom = setupConditional(true);
    const doc = dom.window.document;
    pinModal.init(doc);
    const form = doc.getElementById('f');
    form.submit = () => {};

    fireSubmit(dom, form);

    assert.equal(doc.querySelector('.pin-modal-overlay').classList.contains('open'), true);
});

test('sans data-pin-when-changed, la fenetre s ouvre a chaque soumission (ecrans historiques)', () => {
    const dom = setup('a@b.c');
    const doc = dom.window.document;
    pinModal.init(doc);
    doc.getElementById('f').submit = () => {};

    fireSubmit(dom, doc.getElementById('f'));

    assert.equal(doc.querySelector('.pin-modal-overlay').classList.contains('open'), true);
});

test('un identifiant inconnu dans data-pin-when-changed est ignore sans casser le formulaire', () => {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body data-user-email="a@b.c">' +
        '<form id="f" method="post" action="/admin/products/9" data-pin-when-changed="price_cents, champ_absent ,">' +
        '  <input type="text" id="price_cents" name="price_cents" value="6,90">' +
        '  <fieldset id="pinfs">' +
        '    <input type="email" id="pin_email" name="pin_email">' +
        '    <input type="password" id="pin" name="pin">' +
        '  </fieldset>' +
        '</form></body></html>',
    );
    const doc = dom.window.document;
    pinModal.init(doc);

    assert.equal(pinModal.watchedFields(doc, doc.getElementById('f')).length, 1);
});

test('avec le controle de saisie charge avant lui, le modal retrouve bien le message du serveur', () => {
    // Ordre du gabarit admin/layout.php : form-validation.js puis pin-modal.js. Le
    // premier cree des zones de message vides (classe form-error--live) dans le bloc
    // PIN ; le modal doit sortir le message du SERVEUR, pas une de ces zones vides
    // (defaut trouve en contre-relecture le 2026-09-23).
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body data-user-email="a@b.c">' +
        '<form id="f" method="post" action="/admin/ingredients/3/adjust">' +
        '  <div class="form-group"><input type="number" id="delta" name="delta" value="5" required></div>' +
        '  <fieldset id="pinfs">' +
        '    <input type="email" id="pin_email" name="pin_email" required>' +
        '    <input type="password" id="pin" name="pin" required>' +
        '    <p class="form-error" id="server-pin-error">Email ou PIN invalide.</p>' +
        '  </fieldset>' +
        '  <button type="submit">Enregistrer</button>' +
        '</form></body></html>',
    );
    const doc = dom.window.document;
    formValidation.init(doc, { delay: 0, hideDelay: 0 });
    pinModal.init(doc);

    assert.equal(doc.getElementById('server-pin-error').closest('[hidden]'), null, 'message serveur visible');

    fireSubmit(dom, doc.getElementById('f'));
    const modalError = doc.querySelector('[data-pm-error]');
    assert.equal(modalError.hidden, false);
    assert.equal(modalError.textContent, 'Email ou PIN invalide.');
});
