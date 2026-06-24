/*
 * Tests du composeur de menu slot-driven (P5 L2), node:test + jsdom.
 *
 * page-product-menu.js importe nav.js (qui touche le DOM au chargement) -> import
 * dynamique apres pose des globals jsdom. Cible : fonctions PURES buildComposerSteps,
 * buildMenuCartItem, selectionsComplete (logique slots -> etapes -> item panier).
 */
import { test, before } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

let buildComposerSteps, buildMenuCartItem, selectionsComplete, composerIsViable, optionLabel;

before(async () => {
    const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', { url: 'https://kiosk.test/products.html' });
    global.window = dom.window;
    global.document = dom.window.document;
    global.localStorage = dom.window.localStorage;
    ({ buildComposerSteps, buildMenuCartItem, selectionsComplete, composerIsViable, optionLabel } =
        await import('../../src/public/borne/assets/js/page-product-menu.js'));
});

const detail = () => ({
    id: 1,
    burger_product_id: 100,
    price_normal_cents: 880,
    price_maxi_cents: 1030,
    slots: [
        { id: 16, name: 'Accompagnement', slot_type: 'side', is_required: true, display_order: 2, option_product_ids: [22, 23] },
        { id: 1, name: 'Boisson', slot_type: 'drink', is_required: true, display_order: 1, option_product_ids: [14, 15, 999] },
        { id: 31, name: 'Sauce', slot_type: 'sauce', is_required: false, display_order: 3, option_product_ids: [47] },
    ],
});

const byId = () => ({
    100: { id: 100, nom: 'Le 280', prix: 0, image: 'b.png', type: 'produit', maxiNom: null },
    // Accompagnements : variante Maxi (maxiNom) renseignee -> agrandissable.
    22: { id: 22, nom: 'Moyenne Frite', prix: 0, image: 'f.png', type: 'produit', maxiNom: 'Grande Frite' },
    23: { id: 23, nom: 'Potatoes', prix: 0, image: 'p.png', type: 'produit', maxiNom: 'Grande Potatoes' },
    // Boissons : pas de variante Maxi (le menu Maxi n'agrandit pas la boisson).
    14: { id: 14, nom: 'Coca', prix: 0, image: 'c.png', type: 'produit', maxiNom: null },
    15: { id: 15, nom: 'Eau', prix: 0, image: 'e.png', type: 'produit', maxiNom: null },
    47: { id: 47, nom: 'Ketchup', prix: 0, image: 'k.png', type: 'produit', maxiNom: null },
});

const menu = { id: 1, nom: 'Menu Le 280', image: 'b.png', type: 'menu' };

/* --- buildComposerSteps -------------------------------------------------- */

test('buildComposerSteps: burger impose resolu, slots tries par display_order', () => {
    const m = buildComposerSteps(detail(), byId());
    assert.equal(m.burger.nom, 'Le 280');
    assert.equal(m.priceNormalCents, 880);
    assert.equal(m.priceMaxiCents, 1030);
    assert.deepEqual(m.slots.map(s => s.slotType), ['drink', 'side', 'sauce']); // par display_order 1,2,3
});

test('buildComposerSteps: option_product_ids resolus en produits, ids inconnus filtres', () => {
    const m = buildComposerSteps(detail(), byId());
    const drink = m.slots.find(s => s.slotType === 'drink');
    assert.deepEqual(drink.options.map(o => o.nom), ['Coca', 'Eau']); // 999 inconnu -> filtre
    assert.equal(drink.isRequired, true);
    assert.equal(m.slots.find(s => s.slotType === 'sauce').isRequired, false);
});

/* --- buildMenuCartItem --------------------------------------------------- */

test('buildMenuCartItem Normal: prix normal, pas de supplement, taille N, composition mappee', () => {
    const m = buildComposerSteps(detail(), byId());
    const item = buildMenuCartItem(menu, m, { size: 'N', selections: { 1: 14, 16: 22, 31: 47 } });
    assert.equal(item.type, 'menu');
    assert.equal(item.prix_cents, 880);
    assert.equal(item.supplement_cents, 0);
    assert.equal(item.format, 'normal'); // format explicite transporte
    assert.equal(item.composition.burger.libelle, 'Le 280');
    // Normal : l'accompagnement garde son nom de base (pas la variante Maxi).
    assert.deepEqual(item.composition.accompagnement, { id: 22, libelle: 'Moyenne Frite', taille: 'N' });
    assert.deepEqual(item.composition.boisson, { id: 14, libelle: 'Coca', taille: 'N' });
    assert.deepEqual(item.composition.sauce, { id: 47, libelle: 'Ketchup' });
});

test('buildMenuCartItem Maxi: supplement = maxi - normal, taille G sur side/drink', () => {
    const m = buildComposerSteps(detail(), byId());
    const item = buildMenuCartItem(menu, m, { size: 'M', selections: { 1: 14, 16: 22, 31: 47 } });
    assert.equal(item.prix_cents, 880);
    assert.equal(item.supplement_cents, 150); // 1030 - 880
    assert.equal(item.format, 'maxi'); // format explicite transporte
    assert.equal(item.composition.accompagnement.taille, 'G');
    assert.equal(item.composition.boisson.taille, 'G');
});

test('buildMenuCartItem Maxi: l accompagnement prend sa variante (Grande Frite), pas le nom de base', () => {
    const m = buildComposerSteps(detail(), byId());
    const item = buildMenuCartItem(menu, m, { size: 'M', selections: { 1: 14, 16: 22, 31: 47 } });
    assert.equal(item.composition.accompagnement.libelle, 'Grande Frite'); // pas "Moyenne Frite"
    // Boisson sans maxiNom : garde son nom de base meme en Maxi (cas bouteille).
    assert.equal(item.composition.boisson.libelle, 'Coca');
});

test('buildMenuCartItem Maxi: la boisson AVEC variante (50cl) prend son nom agrandi', () => {
    // Apres le seed 0006, une boisson fontaine porte maxiNom (ex. "Coca Cola 50cl") :
    // en Maxi, le libelle et la taille refletent la grande boisson (meme regle que
    // l'accompagnement). Aucune logique borne specifique : maxiNom suffit.
    const byIdDrinkVariant = { ...byId(), 14: { id: 14, nom: 'Coca Cola', prix: 0, image: 'c.png', type: 'produit', maxiNom: 'Coca Cola 50cl' } };
    const m = buildComposerSteps(detail(), byIdDrinkVariant);
    const item = buildMenuCartItem(menu, m, { size: 'M', selections: { 1: 14, 16: 22, 31: 47 } });
    assert.equal(item.composition.boisson.libelle, 'Coca Cola 50cl');
    assert.equal(item.composition.boisson.taille, 'G');
});

test('buildMenuCartItem Normal: l accompagnement garde "Moyenne Frite" (pas de variante)', () => {
    const m = buildComposerSteps(detail(), byId());
    const item = buildMenuCartItem(menu, m, { size: 'N', selections: { 1: 14, 16: 22, 31: 47 } });
    assert.equal(item.composition.accompagnement.libelle, 'Moyenne Frite');
});

/* --- optionLabel (pur) : libelle affiche au CHOIX selon le format -------- */

test('optionLabel: Maxi affiche la variante quand elle existe, sinon le nom de base', () => {
    const frite = { nom: 'Moyenne Frite', maxiNom: 'Grande Frite' };
    const coca = { nom: 'Coca', maxiNom: null };
    assert.equal(optionLabel(frite, 'M'), 'Grande Frite');
    assert.equal(optionLabel(frite, 'N'), 'Moyenne Frite');
    assert.equal(optionLabel(coca, 'M'), 'Coca'); // pas de variante -> nom de base
    assert.equal(optionLabel(coca, 'N'), 'Coca');
});

test('buildMenuCartItem: slot optionnel non choisi -> champ absent de composition', () => {
    const m = buildComposerSteps(detail(), byId());
    const item = buildMenuCartItem(menu, m, { size: 'N', selections: { 1: 14, 16: 22 } }); // pas de sauce
    assert.equal(item.composition.sauce, undefined);
    assert.ok(item.composition.accompagnement);
    assert.ok(item.composition.boisson);
});

/* --- selectionsComplete -------------------------------------------------- */

test('selectionsComplete: vrai si tous les slots REQUIS sont choisis (sauce optionnelle ignoree)', () => {
    const m = buildComposerSteps(detail(), byId());
    assert.equal(selectionsComplete(m, { 1: 14, 16: 22 }), true);          // requis ok, sauce absente
    assert.equal(selectionsComplete(m, { 1: 14 }), false);                 // accompagnement requis manquant
    assert.equal(selectionsComplete(m, { 1: 14, 16: 999 }), false);        // id hors options du slot
});

/* --- garde-fous (findings revue L2) -------------------------------------- */

test('buildComposerSteps: ignore les slot_type hors {drink,side,sauce} (anti-perte silencieuse)', () => {
    const d = detail();
    d.slots.push({ id: 99, name: 'Dessert', slot_type: 'dessert', is_required: true, display_order: 4, option_product_ids: [22] });
    const m = buildComposerSteps(d, byId());
    assert.deepEqual(m.slots.map(s => s.slotType), ['drink', 'side', 'sauce']); // dessert exclu
});

test('composerIsViable: vrai pour un modele complet', () => {
    assert.equal(composerIsViable(buildComposerSteps(detail(), byId())), true);
});

test('composerIsViable: faux si un slot requis n a aucune option resolue', () => {
    const d = detail();
    d.slots = [{ id: 1, name: 'Boisson', slot_type: 'drink', is_required: true, display_order: 1, option_product_ids: [999, 888] }];
    assert.equal(composerIsViable(buildComposerSteps(d, byId())), false);
});

test('composerIsViable: faux si le burger impose est introuvable', () => {
    const d = detail();
    d.burger_product_id = 12345;
    assert.equal(composerIsViable(buildComposerSteps(d, byId())), false);
});
