/*
 * Tests de l'ecran de paiement borne (page-payment.js), node:test + jsdom.
 *
 * Cible : messageFor (PUR), la traduction d'un code d'erreur API en message
 * client lisible. page-payment.js lit des elements DOM au chargement du module
 * (payment-recap, payment-error...) ; absents dans cette coquille minimale, ils
 * restent null sans consequence (non utilises par messageFor, ni par aucun
 * handler tant qu'aucun evenement DOMContentLoaded/click n'est declenche).
 *
 * Contre-audit (point ajoute par le coordinateur) : avant ce correctif,
 * INVALID_QUANTITY / TOO_MANY_ITEMS / ORDER_TOO_LARGE / OPTION_UNAVAILABLE
 * retombaient tous sur le message generique de repli -- le client ne recevait
 * aucune information exploitable quand le serveur refusait sa commande pour une
 * de ces raisons (ex. quantite > 20 apres le plafond cote panier).
 */
import { test, before } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

let messageFor;

before(async () => {
    const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', { url: 'https://kiosk.test/payment.html' });
    global.window = dom.window;
    global.document = dom.window.document;
    global.sessionStorage = dom.window.sessionStorage;
    global.localStorage = dom.window.localStorage;
    ({ messageFor } = await import('../../src/public/borne/assets/js/page-payment.js'));
});

test('messageFor: INVALID_QUANTITY -> message precis (bornes 1-20), pas le repli generique', () => {
    const msg = messageFor('INVALID_QUANTITY');
    assert.match(msg, /1.*20/);
    assert.notEqual(msg, 'Le paiement n\'a pas pu aboutir. Veuillez réessayer.');
});

test('messageFor: TOO_MANY_ITEMS -> message precis, pas le repli generique', () => {
    const msg = messageFor('TOO_MANY_ITEMS');
    assert.match(msg, /articles/i);
    assert.notEqual(msg, 'Le paiement n\'a pas pu aboutir. Veuillez réessayer.');
});

test('messageFor: ORDER_TOO_LARGE -> message precis (50 articles), pas le repli generique', () => {
    const msg = messageFor('ORDER_TOO_LARGE');
    assert.match(msg, /50/);
    assert.notEqual(msg, 'Le paiement n\'a pas pu aboutir. Veuillez réessayer.');
});

test('messageFor: OPTION_UNAVAILABLE -> message precis, pas le repli generique', () => {
    const msg = messageFor('OPTION_UNAVAILABLE');
    assert.match(msg, /menu/i);
    assert.notEqual(msg, 'Le paiement n\'a pas pu aboutir. Veuillez réessayer.');
});

test('messageFor: codes deja geres restent inchanges (non-regression)', () => {
    assert.match(messageFor('PRODUCT_UNAVAILABLE'), /disponible/);
    assert.match(messageFor('MENU_UNAVAILABLE'), /disponible/);
    assert.match(messageFor('EMPTY_CART'), /vide/);
    assert.match(messageFor('EMPTY_ORDER'), /vide/);
});

test('messageFor: code inconnu -> message generique de repli', () => {
    assert.equal(messageFor('SOMETHING_ELSE'), 'Le paiement n\'a pas pu aboutir. Veuillez réessayer.');
});
