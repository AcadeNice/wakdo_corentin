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

let buildComposerSteps, buildMenuCartItem, selectionsComplete, composerIsViable, optionLabel, openMenuComposer,
    formatCardSideImage, pickDefaultDrinkOption, formatCardDrinkImage, buildFormatCardVisual;

before(async () => {
    const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', { url: 'https://kiosk.test/products.html' });
    global.window = dom.window;
    global.document = dom.window.document;
    global.localStorage = dom.window.localStorage;
    global.requestAnimationFrame = (cb) => cb();
    ({
        buildComposerSteps, buildMenuCartItem, selectionsComplete, composerIsViable, optionLabel, openMenuComposer,
        formatCardSideImage, pickDefaultDrinkOption, formatCardDrinkImage, buildFormatCardVisual,
    } = await import('../../src/public/borne/assets/js/page-product-menu.js'));
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
    100: { id: 100, nom: 'Le 280', prix: 0, image: 'b.png', type: 'produit', maxiNom: null, maxiImage: null },
    // Accompagnements : variante Maxi (maxiNom + maxiImage, photo REELLE de la
    // migration 0006) -> agrandissable, avec son propre visuel (A3).
    22: { id: 22, nom: 'Moyenne Frite', prix: 0, image: 'f.png', type: 'produit', maxiNom: 'Grande Frite', maxiImage: 'grande-frite.png' },
    23: { id: 23, nom: 'Potatoes', prix: 0, image: 'p.png', type: 'produit', maxiNom: 'Grande Potatoes', maxiImage: null },
    // Boissons : pas de variante Maxi (le menu Maxi n'agrandit pas la boisson), mais
    // Coca Cola porte une dimension taille (R4, sizes) : 30 cl = elle-meme (id 14),
    // 50 cl = une AUTRE ligne produit (id 98) avec sa propre image.
    14: {
        id: 14, nom: 'Coca Cola', prix: 190, image: 'c30.png', type: 'produit', maxiNom: null, maxiImage: null,
        sizes: [
            { product_id: 14, size_cl: 30, price_cents: 190, label: '30 cl' },
            { product_id: 98, size_cl: 50, price_cents: 240, label: '50 cl' },
        ],
    },
    98: { id: 98, nom: 'Coca Cola 50cl', prix: 240, image: 'c50.png', type: 'produit' },
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
    assert.deepEqual(drink.options.map(o => o.nom), ['Coca Cola', 'Eau']); // 999 inconnu -> filtre
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
    assert.deepEqual(item.composition.boisson, { id: 14, libelle: 'Coca Cola', taille: 'N' });
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
    assert.equal(item.composition.boisson.libelle, 'Coca Cola');
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

/* --- formatCardSideImage (pur) : photo de l accompagnement du format --------- */

test('formatCardSideImage: Maxi prend la photo REELLE de la variante (maxiImage)', () => {
    const frite = { image: 'f.png', maxiImage: 'grande-frite.png' };
    assert.equal(formatCardSideImage(frite, 'M'), 'grande-frite.png');
    assert.equal(formatCardSideImage(frite, 'N'), 'f.png'); // Normal -> photo de base
});

test('formatCardSideImage: sans variante (maxiImage absent), garde la photo de base meme en Maxi', () => {
    const potatoes = { image: 'p.png', maxiImage: null };
    assert.equal(formatCardSideImage(potatoes, 'M'), 'p.png');
});

test('formatCardSideImage: option absente -> null, pas de plantage', () => {
    assert.equal(formatCardSideImage(null, 'M'), null);
});

/* --- pickDefaultDrinkOption (pur) : boisson representee sur la carte --------- */

test('pickDefaultDrinkOption: Coca Cola en priorite quand l emplacement le propose', () => {
    const options = [{ nom: 'Eau' }, { nom: 'Coca Cola' }, { nom: 'Fanta' }];
    assert.equal(pickDefaultDrinkOption(options).nom, 'Coca Cola');
});

test('pickDefaultDrinkOption: sans Coca Cola, la premiere option de l emplacement', () => {
    const options = [{ nom: 'Eau' }, { nom: 'Fanta' }];
    assert.equal(pickDefaultDrinkOption(options).nom, 'Eau');
});

test('pickDefaultDrinkOption: emplacement vide -> null', () => {
    assert.equal(pickDefaultDrinkOption([]), null);
});

/* --- formatCardDrinkImage (pur) : photo REELLE de la taille servie ----------- */

test('formatCardDrinkImage: resout la VARIANTE 50cl (son propre product_id) en Maxi', () => {
    const coca = byId()[14];
    const image = formatCardDrinkImage(coca, 'M', byId());
    assert.equal(image, 'c50.png'); // photo du produit id 98 (Coca Cola 50cl), pas celle de la base
});

test('formatCardDrinkImage: resout la base 30cl en Normal', () => {
    const coca = byId()[14];
    assert.equal(formatCardDrinkImage(coca, 'N', byId()), 'c30.png');
});

test('formatCardDrinkImage: boisson mono-taille (sizes vide, ex. bouteille) garde sa photo de base', () => {
    const eau = byId()[15]; // pas de champ sizes
    assert.equal(formatCardDrinkImage(eau, 'M', byId()), 'e.png');
});

test('formatCardDrinkImage: option absente -> null', () => {
    assert.equal(formatCardDrinkImage(null, 'M', byId()), null);
});

/* --- buildFormatCardVisual (pur) : bundle des trois photos ------------------- */

test('buildFormatCardVisual: Maxi -- burger + Grande Frite + Coca Cola 50cl', () => {
    const m = buildComposerSteps(detail(), byId());
    const v = buildFormatCardVisual(m, 'M', byId());
    assert.deepEqual(v, { burger: 'b.png', side: 'grande-frite.png', drink: 'c50.png' });
});

test('buildFormatCardVisual: Normal -- burger + Moyenne Frite + Coca Cola 30cl', () => {
    const m = buildComposerSteps(detail(), byId());
    const v = buildFormatCardVisual(m, 'N', byId());
    assert.deepEqual(v, { burger: 'b.png', side: 'f.png', drink: 'c30.png' });
});

test('buildFormatCardVisual: menu sans slot side/drink -> side/drink null, burger seul', () => {
    const d = { ...detail(), slots: [] };
    const m = buildComposerSteps(d, byId());
    const v = buildFormatCardVisual(m, 'N', byId());
    assert.deepEqual(v, { burger: 'b.png', side: null, drink: null });
});

/* --- openMenuComposer (jsdom + fetch stub) : etape Format (A2/A3) -------- */

test('openMenuComposer: etape Format -- cartes fixes centrees, Maxi a gauche, visuel compose (A3)', async () => {
    document.body.innerHTML = '';
    const responses = {
        '/api/categories': { data: [{ id: 1, name: 'Menus', slug: 'menus', image_path: null }] },
        '/api/products': { data: [
            { id: 100, category_id: 1, name: 'Le 280', price_cents: 0, image_path: 'burger.png' },
            { id: 22, category_id: 1, name: 'Moyenne Frite', price_cents: 0, image_path: 'frite.png', maxi_variant_image_path: 'grande-frite.png' },
            {
                id: 14, category_id: 1, name: 'Coca Cola', price_cents: 190, image_path: 'coca30.png',
                sizes: [
                    { product_id: 14, size_cl: 30, price_cents: 190, label: '30 cl' },
                    { product_id: 98, size_cl: 50, price_cents: 240, label: '50 cl' },
                ],
            },
            { id: 98, category_id: 1, name: 'Coca Cola 50cl', price_cents: 240, image_path: 'coca50.png' },
        ] },
        '/api/menus': { data: [] },
        '/api/menus/1': { data: {
            id: 1, burger_product_id: 100, price_normal_cents: 880, price_maxi_cents: 1030,
            slots: [
                { id: 16, name: 'Accompagnement', slot_type: 'side', is_required: 1, display_order: 1, option_product_ids: [22] },
                { id: 1, name: 'Boisson', slot_type: 'drink', is_required: 1, display_order: 2, option_product_ids: [14] },
            ],
        } },
    };
    global.fetch = async (url) => ({ ok: true, json: async () => responses[url] });

    await openMenuComposer({ id: 1, nom: 'Menu Le 280', image: 'burger.png' }, 'menus');

    const grid = document.querySelector('#format-grid');
    assert.ok(grid, 'la grille de format doit etre rendue');
    assert.ok(grid.classList.contains('composer-grid--format'), 'cartes fixes/centrees, pas auto-fill');
    const cards = grid.querySelectorAll('.composer-card');
    assert.equal(cards.length, 2);

    // Ordre maquette : Maxi a gauche (1re carte), Normal a droite (2e) -- l'ordre du
    // DOM porte aussi l'ordre de tabulation clavier.
    assert.equal(cards[0].dataset.size, 'M');
    assert.equal(cards[1].dataset.size, 'N');
    assert.match(cards[0].querySelector('.composer-card__name').textContent, /Menu Maxi Le 280/);
    assert.match(cards[1].querySelector('.composer-card__name').textContent, /^Menu Le 280$/);

    // Visuel compose : 3 photos REELLES par carte (burger devant, accompagnement +
    // boisson du format derriere), pas une image generique unique.
    const maxiVisual = cards[0].querySelector('.composer-card__visual');
    assert.equal(maxiVisual.querySelector('.composer-card__visual-burger').getAttribute('src'), 'burger.png');
    assert.equal(maxiVisual.querySelector('.composer-card__visual-side').getAttribute('src'), 'grande-frite.png');
    assert.equal(maxiVisual.querySelector('.composer-card__visual-drink').getAttribute('src'), 'coca50.png');
    const normalVisual = cards[1].querySelector('.composer-card__visual');
    assert.equal(normalVisual.querySelector('.composer-card__visual-side').getAttribute('src'), 'frite.png');
    assert.equal(normalVisual.querySelector('.composer-card__visual-drink').getAttribute('src'), 'coca30.png');
    // Decoratif : le libelle textuel porte deja l'information (alt vide).
    assert.equal(maxiVisual.querySelector('.composer-card__visual-burger').getAttribute('alt'), '');

    assert.match(document.querySelector('.composer-step__subtitle').textContent, /grosse faim/i); // A2
    assert.match(document.querySelector('.composer-step__hint').textContent, /supplément/i);       // A2
});
