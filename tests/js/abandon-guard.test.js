/*
 * abandon-guard.test.js — Garde de sortie du parcours (node:test + jsdom).
 *
 * Bug reproduit : quitter categories.html vers l'accueil (lien "Retour", ou bouton
 * Precedent du navigateur) avec un panier non vide n'affichait jamais de
 * confirmation ; la commande restait ensuite affichee au retour (aucun etat n'avait
 * ete efface). Couvre les deux chemins de sortie, plus le cas panier vide (rien a
 * confirmer) et le cas "Annuler" (on reste ou on etait).
 */
import { test, before, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

let installLeaveGuard, abandonOrder, setMode, getMode, addToCart, getCart;
let originalAddEventListener;
let popstateListeners = [];

function freshSessionStorage() {
    const s = { _s: {} };
    s.getItem = (k) => (k in s._s ? s._s[k] : null);
    s.setItem = (k, v) => { s._s[k] = String(v); };
    s.removeItem = (k) => { delete s._s[k]; };
    return s;
}

before(async () => {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body><a id="back-to-welcome" href="index.html">Retour</a></body></html>',
        { url: 'https://kiosk.test/categories.html' },
    );
    global.window = dom.window;
    global.document = dom.window.document;
    global.localStorage = dom.window.localStorage;
    global.history = dom.window.history;
    global.requestAnimationFrame = (cb) => cb();
    global.PopStateEvent = dom.window.PopStateEvent;
    originalAddEventListener = dom.window.addEventListener.bind(dom.window);
    ({ installLeaveGuard, abandonOrder } = await import('../../src/public/borne/assets/js/abandon-guard.js'));
    ({ setMode, getMode, addToCart, getCart } = await import('../../src/public/borne/assets/js/state.js'));
});

beforeEach(() => {
    global.localStorage.clear();
    global.sessionStorage = freshSessionStorage();
    document.body.innerHTML = '<a id="back-to-welcome" href="index.html">Retour</a>';
    // installLeaveGuard() attache un listener 'popstate' sur window a chaque appel ; sans
    // le retirer entre deux tests, les listeners des tests precedents restent actifs et
    // ouvrent chacun leur propre modale au prochain dispatchEvent('popstate').
    popstateListeners = [];
    window.addEventListener = (type, fn, opts) => {
        if (type === 'popstate') popstateListeners.push(fn);
        originalAddEventListener(type, fn, opts);
    };
});

afterEach(() => {
    popstateListeners.forEach(fn => window.removeEventListener('popstate', fn));
    popstateListeners = [];
});

/* --- Lien "Retour a l'accueil" -------------------------------------------- */

test('installLeaveGuard: panier vide -> clic sur Retour ne demande rien', () => {
    installLeaveGuard('#back-to-welcome');
    document.querySelector('#back-to-welcome').click();
    assert.equal(document.querySelector('.confirm-overlay'), null);
});

test('installLeaveGuard: panier non vide -> clic sur Retour demande confirmation', () => {
    addToCart({ id: 1, type: 'produit', prix_cents: 500, libelle: 'Big Tasty', quantite: 1 });
    setMode('sur-place');
    installLeaveGuard('#back-to-welcome');

    document.querySelector('#back-to-welcome').click();
    const overlay = document.querySelector('.confirm-overlay');
    assert.ok(overlay, 'une modale de confirmation doit apparaitre');
    // Rien n'est efface tant que l'utilisateur n'a pas confirme.
    assert.equal(getCart().length, 1);
    assert.equal(getMode(), 'sur-place');
});

test('installLeaveGuard: Annuler conserve le panier, le mode, et reste sur la page', () => {
    addToCart({ id: 1, type: 'produit', prix_cents: 500, libelle: 'Big Tasty', quantite: 1 });
    setMode('sur-place');
    installLeaveGuard('#back-to-welcome');

    document.querySelector('#back-to-welcome').click();
    document.querySelector('.confirm-modal__cancel').click();

    assert.equal(document.querySelector('.confirm-overlay'), null);
    assert.equal(getCart().length, 1);
    assert.equal(getMode(), 'sur-place');
});

test('installLeaveGuard: confirmer efface panier, mode ET la cle de paiement', () => {
    global.sessionStorage.setItem('wakdo_order_key', 'cle-en-cours');
    addToCart({ id: 1, type: 'produit', prix_cents: 500, libelle: 'Big Tasty', quantite: 1 });
    setMode('a-emporter');
    installLeaveGuard('#back-to-welcome');

    document.querySelector('#back-to-welcome').click();
    document.querySelector('.confirm-modal__confirm').click();

    assert.equal(getCart().length, 0);
    assert.equal(getMode(), null);
    assert.equal(global.sessionStorage.getItem('wakdo_order_key'), null);
});

/* --- Bouton Precedent du navigateur ---------------------------------------- */

test('installLeaveGuard: bouton Precedent avec panier non vide demande confirmation', () => {
    addToCart({ id: 2, type: 'produit', prix_cents: 300, libelle: 'Frite', quantite: 1 });
    installLeaveGuard('#back-to-welcome');

    window.dispatchEvent(new window.PopStateEvent('popstate'));

    assert.ok(document.querySelector('.confirm-overlay'));
    assert.equal(getCart().length, 1);
});

test('installLeaveGuard: bouton Precedent, Annuler -> le panier reste intact', () => {
    addToCart({ id: 2, type: 'produit', prix_cents: 300, libelle: 'Frite', quantite: 1 });
    installLeaveGuard('#back-to-welcome');

    window.dispatchEvent(new window.PopStateEvent('popstate'));
    document.querySelector('.confirm-modal__cancel').click();

    assert.equal(document.querySelector('.confirm-overlay'), null);
    assert.equal(getCart().length, 1);
});

test('installLeaveGuard: bouton Precedent, confirmer -> panier et mode effaces', () => {
    addToCart({ id: 2, type: 'produit', prix_cents: 300, libelle: 'Frite', quantite: 1 });
    setMode('sur-place');
    installLeaveGuard('#back-to-welcome');

    window.dispatchEvent(new window.PopStateEvent('popstate'));
    document.querySelector('.confirm-modal__confirm').click();

    assert.equal(getCart().length, 0);
    assert.equal(getMode(), null);
});

test('installLeaveGuard: panier vide des l installation -> pas de piege pose sur Precedent', () => {
    installLeaveGuard('#back-to-welcome');
    // Aucune entree d'historique factice n'a ete poussee : un popstate reel ne doit
    // declencher aucune modale (rien a perdre).
    window.dispatchEvent(new window.PopStateEvent('popstate'));
    assert.equal(document.querySelector('.confirm-overlay'), null);
});

/* --- abandonOrder (fonction partagee) --------------------------------------- */

/* --- Cablage CSP-safe (regression) ----------------------------------------- */

test('categories.html cable le garde-fou via un FICHIER externe, pas un script en ligne', () => {
    // Bug reproduit en conditions reelles (CSP script-src 'self' de la borne,
    // docker/apache/vhost.conf) : un <script type="module"> pose directement
    // dans la page est bloque par le navigateur ("Refused to execute inline
    // script..."), donc installLeaveGuard() n'etait jamais appele -- clic sur
    // "Retour" naviguait directement, sans aucune confirmation.
    const html = readFileSync(new URL('../../src/public/borne/categories.html', import.meta.url), 'utf8');
    assert.match(html, /assets\/js\/page-categories-leave-guard\.js/);
    // Aucun <script type="module"> SANS src (= inline, bloque par la CSP
    // script-src 'self' de la borne) sur la page.
    assert.doesNotMatch(html, /<script type="module">/);
});

test('abandonOrder: efface panier, mode et cle de paiement', () => {
    addToCart({ id: 3, type: 'produit', prix_cents: 100, libelle: 'Sauce', quantite: 1 });
    setMode('sur-place');
    global.sessionStorage.setItem('wakdo_order_key', 'k1');

    abandonOrder();

    assert.equal(getCart().length, 0);
    assert.equal(getMode(), null);
    assert.equal(global.sessionStorage.getItem('wakdo_order_key'), null);
});
