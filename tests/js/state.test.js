/*
 * Tests du panier client borne (state.js), node:test + jsdom (localStorage).
 *
 * Contre-audit (point ajoute par le coordinateur, suite au constat 1) : la borne
 * acceptait jusqu'a 99 par produit (product-options.js), alors que le serveur
 * refuse au-dela de 20 par ligne (OrderRepository::MAX_QUANTITY_PER_LINE,
 * INVALID_QUANTITY). Le plafond DOIT vivre ici, au coeur du panier (addToCart /
 * updateQuantity), pas seulement dans la modale d'ajout : un ajout repete du MEME
 * produit fusionne les quantites (addToCart), et le stepper du panneau de commande
 * (order-panel.js) passe par updateQuantity -- les DEUX chemins doivent respecter
 * la meme borne, sans quoi un panier compose pas-a-pas pourrait quand meme depasser
 * 20 et se faire refuser en bloc au paiement (INVALID_QUANTITY), sans que le client
 * comprenne pourquoi avant ce correctif (page-payment.js n'affichait alors qu'un
 * message generique).
 */
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

let addToCart, getCart, updateQuantity, MAX_LINE_QUANTITY;

const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', { url: 'https://kiosk.test/products.html' });
global.window = dom.window;
global.document = dom.window.document;
global.localStorage = dom.window.localStorage;

({ addToCart, getCart, updateQuantity, MAX_LINE_QUANTITY } = await import('../../src/public/borne/assets/js/state.js'));

beforeEach(() => {
    global.localStorage.clear();
});

test('MAX_LINE_QUANTITY est alignee sur la borne serveur (OrderRepository::MAX_QUANTITY_PER_LINE)', () => {
    assert.equal(MAX_LINE_QUANTITY, 20);
});

test('addToCart: une quantite unique au-dela de 20 est plafonnee a 20', () => {
    addToCart({ id: 1, type: 'produit', categorie: 'frites', libelle: 'Frites', prix_cents: 250, quantite: 30, image: '' });
    assert.equal(getCart()[0].quantite, 20);
});

test('addToCart: la FUSION (meme produit ajoute plusieurs fois) reste plafonnee a 20', () => {
    // Ajout repete du meme produit (tap successifs sur la meme tuile, ou modale
    // rouverte plusieurs fois) : la fusion NE DOIT PAS depasser 20 au total, meme
    // si chaque ajout individuel est valide.
    const item = (qty) => ({ id: 1, type: 'produit', categorie: 'frites', libelle: 'Frites', prix_cents: 250, quantite: qty, image: '' });
    addToCart(item(15));
    addToCart(item(10)); // 15 + 10 = 25 -> plafonne a 20
    assert.equal(getCart().length, 1);
    assert.equal(getCart()[0].quantite, 20);
});

test('updateQuantity: le stepper ne peut jamais depasser 20', () => {
    addToCart({ id: 1, type: 'produit', categorie: 'frites', libelle: 'Frites', prix_cents: 250, quantite: 19, image: '' });
    updateQuantity(0, 20);
    assert.equal(getCart()[0].quantite, 20);
    updateQuantity(0, 21); // au-dela : plafonne, jamais refuse en silence vers autre chose
    assert.equal(getCart()[0].quantite, 20);
});

test('addToCart / updateQuantity : une quantite dans les bornes reste inchangee', () => {
    addToCart({ id: 1, type: 'produit', categorie: 'frites', libelle: 'Frites', prix_cents: 250, quantite: 5, image: '' });
    assert.equal(getCart()[0].quantite, 5);
    updateQuantity(0, 12);
    assert.equal(getCart()[0].quantite, 12);
});

test('addToCart: un MENU (jamais fusionne) reste plafonne a 20 sur son propre ajout', () => {
    addToCart({
        id: 5, type: 'menu', categorie: 'menus', libelle: 'Menu Best Of', prix_cents: 990,
        quantite: 50, image: '', composition: {}, supplement_cents: 0,
    });
    assert.equal(getCart()[0].quantite, 20);
});
