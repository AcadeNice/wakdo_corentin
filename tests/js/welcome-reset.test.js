/*
 * welcome-reset.test.js — Nettoyage silencieux de l'accueil (node:test + jsdom).
 *
 * Bug reproduit : apres un rechargement (ou une coupure/redemarrage) survenu en
 * plein parcours, revenir sur l'accueil affichait ensuite une commande "fantome"
 * (panier residuel repris silencieusement des le choix suivant de mode). L'accueil
 * doit toujours repartir d'une session vide, sans demander confirmation (aucun
 * client n'est en train de "quitter" activement en arrivant sur cet ecran).
 */
import { test, before, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

let resetWelcomeSession, setMode, getMode, addToCart, getCart;

function freshSessionStorage() {
    const s = { _s: {} };
    s.getItem = (k) => (k in s._s ? s._s[k] : null);
    s.setItem = (k, v) => { s._s[k] = String(v); };
    s.removeItem = (k) => { delete s._s[k]; };
    return s;
}

before(async () => {
    const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', { url: 'https://kiosk.test/index.html' });
    global.window = dom.window;
    global.document = dom.window.document;
    global.localStorage = dom.window.localStorage;
    ({ resetWelcomeSession } = await import('../../src/public/borne/assets/js/welcome-reset.js'));
    ({ setMode, getMode, addToCart, getCart } = await import('../../src/public/borne/assets/js/state.js'));
});

beforeEach(() => {
    global.localStorage.clear();
    global.sessionStorage = freshSessionStorage();
});

test('resetWelcomeSession: panier et mode residuels sont effaces', () => {
    addToCart({ id: 1, type: 'produit', prix_cents: 500, libelle: 'Big Tasty', quantite: 1 });
    setMode('sur-place');

    resetWelcomeSession();

    assert.equal(getCart().length, 0);
    assert.equal(getMode(), null);
});

test('resetWelcomeSession: libere aussi la cle de paiement en cours', () => {
    global.sessionStorage.setItem('wakdo_order_key', 'cle-residuelle');

    resetWelcomeSession();

    assert.equal(global.sessionStorage.getItem('wakdo_order_key'), null);
});

test('resetWelcomeSession: session deja propre -> aucun effet de bord genant', () => {
    resetWelcomeSession();
    assert.equal(getCart().length, 0);
    assert.equal(getMode(), null);
});

test('index.html charge welcome-reset.js (sinon la commande fantome reapparait)', async () => {
    const { readFileSync } = await import('node:fs');
    const html = readFileSync(new URL('../../src/public/borne/index.html', import.meta.url), 'utf8');
    assert.match(html, /assets\/js\/welcome-reset\.js/);
});
