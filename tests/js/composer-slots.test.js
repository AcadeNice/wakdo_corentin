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
    formatCardSideImage, pickDefaultDrinkOption, formatCardDrinkImage, buildFormatCardVisual, reconcileSelectionsForSize;

before(async () => {
    const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', { url: 'https://kiosk.test/products.html' });
    global.window = dom.window;
    global.document = dom.window.document;
    global.localStorage = dom.window.localStorage;
    global.requestAnimationFrame = (cb) => cb();
    ({
        buildComposerSteps, buildMenuCartItem, selectionsComplete, composerIsViable, optionLabel, openMenuComposer,
        formatCardSideImage, pickDefaultDrinkOption, formatCardDrinkImage, buildFormatCardVisual, reconcileSelectionsForSize,
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

/* --- Disponibilite des options de slot (defaut #4, RG-T21/F2) ------------ */

test('buildComposerSteps: une option en rupture calculee (option_is_orderable) est marquee isOrderable=false', () => {
    const d = detail();
    d.slots[1].option_is_orderable = { 14: false, 15: true }; // slot Boisson (id 1)
    const m = buildComposerSteps(d, byId());
    const drink = m.slots.find(s => s.slotType === 'drink');
    assert.equal(drink.options.find(o => o.id === 14).isOrderable, false);
    assert.equal(drink.options.find(o => o.id === 15).isOrderable, true);
});

test('buildComposerSteps: option absente de option_is_orderable reste commandable (compat API anterieure)', () => {
    const m = buildComposerSteps(detail(), byId()); // aucun detail.slots[i].option_is_orderable
    const drink = m.slots.find(s => s.slotType === 'drink');
    assert.equal(drink.options.every(o => o.isOrderable !== false), true);
});

test('buildComposerSteps: option retiree du catalogue (absente de byId) reste AFFICHABLE via option_names', () => {
    // Cas reel du defaut #4 : un produit is_available=0 disparait de /api/products
    // (donc de byId), mais reste configure comme option de slot -- le composeur doit
    // pouvoir l'afficher (grisee), pas la perdre en silence comme avant ce correctif.
    const d = detail();
    d.slots[1].option_product_ids = [14, 999]; // 999 absent de byId()
    d.slots[1].option_is_orderable = { 14: true, 999: false };
    d.slots[1].option_names = { 14: 'Coca Cola', 999: 'Fanta' };
    const m = buildComposerSteps(d, byId());
    const drink = m.slots.find(s => s.slotType === 'drink');
    assert.equal(drink.options.length, 2); // 999 n'est PLUS filtre (avant : .filter(Boolean) le perdait)
    const fanta = drink.options.find(o => o.id === 999);
    assert.equal(fanta.nom, 'Fanta');
    assert.equal(fanta.isOrderable, false);
});

/* --- Disponibilite PAR FORMAT (contre-audit constat 1, RG-T21) ------------ */
// option_is_orderable_maxi : la variante REELLEMENT servie en Maxi peut etre en
// rupture alors que la base (option_is_orderable) ne l'est pas, et inversement. Le
// composeur doit lire le champ du FORMAT COURANT, jamais toujours option_is_orderable.

test('buildComposerSteps: option_is_orderable_maxi distinct de option_is_orderable -> isOrderableMaxi propre', () => {
    const d = detail();
    // Frites (22) : base disponible, mais sa variante Maxi (Grande Frite) est en
    // rupture -- doit rester choisissable en Normal, grisee en Maxi.
    d.slots[0].option_is_orderable = { 22: true, 23: true };
    d.slots[0].option_is_orderable_maxi = { 22: false, 23: true };
    const m = buildComposerSteps(d, byId());
    const side = m.slots.find(s => s.slotType === 'side');
    const frites = side.options.find(o => o.id === 22);
    assert.equal(frites.isOrderable, true);
    assert.equal(frites.isOrderableMaxi, false);
});

test('buildComposerSteps: option_is_orderable_maxi absent retombe sur la base (compat API anterieure)', () => {
    const d = detail();
    d.slots[0].option_is_orderable = { 22: false, 23: true }; // aucun ...option_is_orderable_maxi
    const m = buildComposerSteps(d, byId());
    const side = m.slots.find(s => s.slotType === 'side');
    assert.equal(side.options.find(o => o.id === 22).isOrderableMaxi, false); // egal a la base
    assert.equal(side.options.find(o => o.id === 23).isOrderableMaxi, true);
});

test('selectionsComplete: le 3e argument (size) selectionne le bon champ de disponibilite', () => {
    const d = detail();
    d.slots[0].option_is_orderable = { 22: true, 23: true };
    d.slots[0].option_is_orderable_maxi = { 22: false, 23: true }; // 22 en rupture SEULEMENT en Maxi
    const m = buildComposerSteps(d, byId());
    assert.equal(selectionsComplete(m, { 1: 14, 16: 22 }), true);         // defaut 'N' : 22 OK
    assert.equal(selectionsComplete(m, { 1: 14, 16: 22 }, 'N'), true);    // Normal explicite : 22 OK
    assert.equal(selectionsComplete(m, { 1: 14, 16: 22 }, 'M'), false);   // Maxi : 22 en rupture
    assert.equal(selectionsComplete(m, { 1: 14, 16: 23 }, 'M'), true);    // 23 OK dans les deux formats
});

test('composerIsViable: le 2e argument (size) selectionne le bon champ de disponibilite', () => {
    const d = detail();
    d.slots[0].option_is_orderable = { 22: true, 23: false };
    d.slots[0].option_is_orderable_maxi = { 22: false, 23: true }; // inverse en Maxi
    const m = buildComposerSteps(d, byId());
    assert.equal(composerIsViable(m, 'N'), true);  // 22 seul dispo en Normal
    assert.equal(composerIsViable(m, 'M'), true);  // 23 seul dispo en Maxi
});

test('reconcileSelectionsForSize: retire une selection devenue indisponible pour le format cible', () => {
    const d = detail();
    d.slots[0].option_is_orderable = { 22: true, 23: true };
    d.slots[0].option_is_orderable_maxi = { 22: false, 23: true }; // 22 : Maxi indisponible
    const m = buildComposerSteps(d, byId());
    const { selections, cleared } = reconcileSelectionsForSize(m, { 1: 14, 16: 22, 31: 47 }, 'M');
    assert.equal(selections[16], undefined, 'la selection Accompagnement doit etre retiree');
    assert.equal(selections[1], 14, 'les autres selections restent inchangees');
    assert.equal(selections[31], 47);
    assert.equal(cleared.length, 1);
    assert.equal(cleared[0].slotType, 'side');
});

test('reconcileSelectionsForSize: ne retire rien quand tout reste commandable dans le nouveau format', () => {
    const d = detail();
    d.slots[0].option_is_orderable = { 22: true, 23: true };
    d.slots[0].option_is_orderable_maxi = { 22: true, 23: true };
    const m = buildComposerSteps(d, byId());
    const { selections, cleared } = reconcileSelectionsForSize(m, { 1: 14, 16: 22 }, 'M');
    assert.equal(selections[16], 22);
    assert.equal(cleared.length, 0);
});

test('selectionsComplete: faux si l option selectionnee est indisponible (RG-T21)', () => {
    const d = detail();
    d.slots[0].option_is_orderable = { 22: false, 23: true }; // slot Accompagnement (id 16)
    const m = buildComposerSteps(d, byId());
    assert.equal(selectionsComplete(m, { 1: 14, 16: 22 }), false); // 22 indisponible
    assert.equal(selectionsComplete(m, { 1: 14, 16: 23 }), true);  // 23 commandable
});

test('composerIsViable: faux si TOUTES les options d un slot requis sont indisponibles', () => {
    const d = detail();
    d.slots[0].option_is_orderable = { 22: false, 23: false }; // Accompagnement : les 2 en rupture
    const m = buildComposerSteps(d, byId());
    assert.equal(composerIsViable(m), false);
});

test('composerIsViable: vrai si au moins UNE option d un slot requis reste commandable', () => {
    const d = detail();
    d.slots[0].option_is_orderable = { 22: false, 23: true };
    const m = buildComposerSteps(d, byId());
    assert.equal(composerIsViable(m), true);
});

test('buildMenuCartItem: une selection devenue indisponible est ignoree (garde-fou defensif)', () => {
    const d = detail();
    d.slots[0].option_is_orderable = { 22: false, 23: true };
    const m = buildComposerSteps(d, byId());
    const item = buildMenuCartItem(menu, m, { size: 'N', selections: { 1: 14, 16: 22, 31: 47 } });
    assert.equal(item.composition.accompagnement, undefined); // 22 rejete, pas de fallback errone
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

/* --- openMenuComposer (jsdom + fetch stub) : option indisponible (defaut #4) --- */

// NOTE cache : data.js memoise loadProductsById() par PROMESSE au niveau module
// (partagee par tous les tests de ce fichier, qui importent tous la MEME instance de
// page-product-menu.js/data.js). Le premier test openMenuComposer plus haut a deja
// rempli ce cache avec {100, 22, 14, 98} -- la reponse '/api/products' ci-dessous
// n'est donc PAS relue ici (elle documente l'intention, pour un lecteur qui ne
// connaitrait pas ce test). L'id 23 (Potatoes) n'a JAMAIS ete servi par aucun test de
// ce fichier : il reste absent de byId, ce qui simule exactement le cas reel (un
// produit is_available=0 disparait de /api/products) sans avoir a rejouer le fetch.
test('openMenuComposer: une option indisponible est grisee (disabled + aria-disabled + "Indisponible"), pas seulement une couleur', async () => {
    document.body.innerHTML = '';
    const responses = {
        '/api/categories': { data: [{ id: 1, name: 'Menus', slug: 'menus', image_path: null }] },
        '/api/products': { data: [
            { id: 100, category_id: 1, name: 'Le 280', price_cents: 0, image_path: 'burger.png' },
            { id: 22, category_id: 1, name: 'Moyenne Frite', price_cents: 0, image_path: 'frite.png' },
            // 23 (Potatoes) N'APPARAIT PAS ici : produit mis indisponible au back-office
            // (is_available=0) -> exclu de /api/products, comme en production.
        ] },
        '/api/menus': { data: [] },
        '/api/menus/1': { data: {
            id: 1, burger_product_id: 100, price_normal_cents: 880, price_maxi_cents: 1030,
            slots: [
                {
                    id: 16, name: 'Accompagnement', slot_type: 'side', is_required: 1, display_order: 1,
                    option_product_ids: [22, 23],
                    option_is_orderable: { 22: true, 23: false },
                    option_names: { 22: 'Moyenne Frite', 23: 'Potatoes' },
                },
            ],
        } },
    };
    global.fetch = async (url) => ({ ok: true, json: async () => responses[url] });

    await openMenuComposer({ id: 1, nom: 'Menu Le 280', image: 'burger.png' }, 'menus');
    document.querySelector('#composer-next').click(); // format -> slot Accompagnement

    const options = document.querySelectorAll('#slot-grid .composer-card');
    assert.equal(options.length, 2);
    const potatoes = Array.from(options).find(b => b.dataset.pid === '23');
    const frite = Array.from(options).find(b => b.dataset.pid === '22');

    assert.ok(potatoes.hasAttribute('disabled'), 'option indisponible doit etre disabled');
    assert.equal(potatoes.getAttribute('aria-disabled'), 'true');
    assert.match(potatoes.textContent, /Indisponible/);
    assert.match(potatoes.getAttribute('aria-label'), /indisponible/i); // accessible, pas que visuel
    assert.ok(!frite.hasAttribute('disabled'));

    // Pre-selection automatique : la PREMIERE option COMMANDABLE (22), jamais 23.
    assert.equal(frite.getAttribute('aria-pressed'), 'true');
    assert.equal(potatoes.getAttribute('aria-pressed'), 'false');

    // Un clic sur une option disabled ne doit rien selectionner (aucun listener posé).
    potatoes.click();
    assert.equal(potatoes.getAttribute('aria-pressed'), 'false');
    assert.equal(frite.getAttribute('aria-pressed'), 'true'); // selection inchangee
});

test('openMenuComposer: toutes les options d un slot requis indisponibles -> composeur ouvert quand meme, message clair pres du slot (pas de window.alert)', async () => {
    // Correctif suite a revue : une window.alert() bloquante (puis un refus muet avant
    // ouverture) ne convient pas sur une borne tactile en libre-service. Le composeur
    // s'ouvre desormais normalement ; le message clair vit PRES DU SLOT concerne
    // (role="alert", renderSlotStep), pas dans une boite de dialogue navigateur.
    document.body.innerHTML = '';
    const responses = {
        '/api/categories': { data: [{ id: 1, name: 'Menus', slug: 'menus', image_path: null }] },
        '/api/products': { data: [
            { id: 100, category_id: 1, name: 'Le 280', price_cents: 0, image_path: 'burger.png' },
        ] },
        '/api/menus': { data: [] },
        '/api/menus/1': { data: {
            id: 1, burger_product_id: 100, price_normal_cents: 880, price_maxi_cents: 1030,
            slots: [
                {
                    id: 16, name: 'Accompagnement', slot_type: 'side', is_required: 1, display_order: 1,
                    option_product_ids: [22, 23],
                    option_is_orderable: { 22: false, 23: false },
                    option_names: { 22: 'Moyenne Frite', 23: 'Potatoes' },
                },
            ],
        } },
    };
    global.fetch = async (url) => ({ ok: true, json: async () => responses[url] });
    let alerted = null;
    global.window.alert = (msg) => { alerted = msg; }; // ne doit JAMAIS etre appele

    await openMenuComposer({ id: 1, nom: 'Menu Le 280', image: 'burger.png' }, 'menus');

    assert.equal(alerted, null, 'window.alert ne doit plus etre utilise (borne tactile)');
    assert.ok(document.querySelector('.composer-overlay'), 'le composeur doit s ouvrir malgre l impasse');

    document.querySelector('#composer-next').click(); // format -> slot Accompagnement (seule etape)

    const notice = document.querySelector('.composer-step__alert');
    assert.ok(notice, 'un message role=alert doit apparaitre pres du slot concerne');
    assert.equal(notice.getAttribute('role'), 'alert');
    assert.match(notice.textContent, /aucune option/i);
    assert.match(notice.textContent, /Accompagnement/);

    // Les deux tuiles restent visibles mais grisees (le client voit POURQUOI).
    const tiles = document.querySelectorAll('#slot-grid .composer-card');
    assert.equal(tiles.length, 2);
    assert.ok(Array.from(tiles).every(t => t.hasAttribute('disabled')));

    // "Suivant" est visiblement desactive (pas seulement un clic sans effet).
    const nextBtn = document.querySelector('#composer-next');
    assert.ok(nextBtn.disabled, 'Suivant doit etre desactive sur un slot en impasse');
    assert.equal(nextBtn.getAttribute('aria-disabled'), 'true');
});

test('openMenuComposer: burger impose introuvable -> composeur non ouvert (catalogue rompu, pas une rupture de stock)', async () => {
    // Cas distinct de l impasse de slot ci-dessus : un burger introuvable n est pas une
    // rupture RG-T21 normale, c est une configuration catalogue rompue -- aucune tuile
    // de secours n a de sens, la borne n ouvre pas un composeur sans son burger impose.
    // id 777 : jamais servi par /api/products dans AUCUN test de ce fichier (loadProductsById
    // memoise par module -- un id deja vu ailleurs resterait resolu via le cache partage).
    document.body.innerHTML = '';
    const responses = {
        '/api/categories': { data: [{ id: 1, name: 'Menus', slug: 'menus', image_path: null }] },
        '/api/products': { data: [] }, // le burger 777 n existe pas
        '/api/menus': { data: [] },
        '/api/menus/777': { data: {
            id: 777, burger_product_id: 777, price_normal_cents: 880, price_maxi_cents: 1030,
            slots: [],
        } },
    };
    global.fetch = async (url) => ({ ok: true, json: async () => responses[url] });
    let alerted = null;
    global.window.alert = (msg) => { alerted = msg; };

    await openMenuComposer({ id: 777, nom: 'Menu Casse', image: 'burger.png' }, 'menus');

    assert.equal(alerted, null);
    assert.equal(document.querySelector('.composer-overlay'), null);
});

/* --- openMenuComposer (jsdom) : changement de FORMAT reconcilie les selections --- */
// Contre-audit (constat 1) : une option deja choisie qui devient indisponible dans le
// format nouvellement selectionne (ici : Accompagnement, disponible en Normal mais
// dont la variante Maxi est en rupture) doit etre DESELECTIONNEE, avec un message --
// pas laissee choisie pour se faire refuser en 422 OPTION_UNAVAILABLE au paiement.

test('openMenuComposer: passer en Maxi deselectionne une option dont la variante Maxi est en rupture, avec un message', async () => {
    document.body.innerHTML = '';
    const responses = {
        '/api/categories': { data: [{ id: 1, name: 'Menus', slug: 'menus', image_path: null }] },
        '/api/products': { data: [
            { id: 100, category_id: 1, name: 'Le 280', price_cents: 0, image_path: 'burger.png' },
            { id: 22, category_id: 1, name: 'Moyenne Frite', price_cents: 0, image_path: 'frite.png' },
            { id: 23, category_id: 1, name: 'Potatoes', price_cents: 0, image_path: 'p.png' },
        ] },
        '/api/menus': { data: [] },
        '/api/menus/300': { data: {
            id: 300, burger_product_id: 100, price_normal_cents: 880, price_maxi_cents: 1030,
            slots: [
                {
                    id: 16, name: 'Accompagnement', slot_type: 'side', is_required: 1, display_order: 1,
                    option_product_ids: [22, 23],
                    option_is_orderable: { 22: true, 23: true },
                    // La variante Maxi de Moyenne Frite (22, Grande Frite) est en rupture ;
                    // Potatoes (23) reste dispo dans les deux formats.
                    option_is_orderable_maxi: { 22: false, 23: true },
                    option_names: { 22: 'Moyenne Frite', 23: 'Potatoes' },
                },
            ],
        } },
    };
    global.fetch = async (url) => ({ ok: true, json: async () => responses[url] });

    await openMenuComposer({ id: 300, nom: 'Menu Le 280', image: 'burger.png' }, 'menus');

    // Pre-selection en Normal : la premiere option commandable (22, Moyenne Frite).
    document.querySelector('#composer-next').click(); // format -> slot Accompagnement
    const frite22 = document.querySelector('#slot-grid .composer-card[data-pid="22"]');
    assert.equal(frite22.getAttribute('aria-pressed'), 'true');
    document.querySelector('#composer-prev').click(); // retour a l'etape Format

    // Passage en Maxi : 22 (selectionne) devient indisponible dans CE format.
    document.querySelector('[data-size="M"]').click();

    const notice = document.querySelector('.composer-step__notice');
    assert.ok(notice, 'un message doit signaler la deselection');
    assert.equal(notice.hasAttribute('hidden'), false);
    assert.match(notice.textContent, /Accompagnement/);
    assert.match(notice.textContent, /indisponible|disponible/i);

    // La selection a bien ete retiree : au retour sur l'etape du slot, plus aucune
    // tuile n'est marquee selectionnee, et Potatoes (23, toujours dispo) est grisee
    // seulement si en rupture -- ici il reste commandable, seule 22 doit etre grisee.
    document.querySelector('#composer-next').click();
    const frite22Maxi = document.querySelector('#slot-grid .composer-card[data-pid="22"]');
    const potatoesMaxi = document.querySelector('#slot-grid .composer-card[data-pid="23"]');
    assert.equal(frite22Maxi.getAttribute('aria-pressed'), 'false', 'la selection precedente doit avoir ete retiree');
    assert.ok(frite22Maxi.hasAttribute('disabled'), '22 doit etre grisee en Maxi (variante en rupture)');
    assert.ok(!potatoesMaxi.hasAttribute('disabled'), '23 reste commandable en Maxi');
});

test('openMenuComposer: une selection qui reste commandable dans le nouveau format n est ni retiree ni signalee', async () => {
    document.body.innerHTML = '';
    const responses = {
        '/api/categories': { data: [{ id: 1, name: 'Menus', slug: 'menus', image_path: null }] },
        '/api/products': { data: [] }, // deja en cache (100, 22, 23) depuis le test precedent
        '/api/menus': { data: [] },
        '/api/menus/300': { data: {
            id: 300, burger_product_id: 100, price_normal_cents: 880, price_maxi_cents: 1030,
            slots: [
                {
                    id: 16, name: 'Accompagnement', slot_type: 'side', is_required: 1, display_order: 1,
                    option_product_ids: [22, 23],
                    option_is_orderable: { 22: true, 23: true },
                    option_is_orderable_maxi: { 22: false, 23: true },
                    option_names: { 22: 'Moyenne Frite', 23: 'Potatoes' },
                },
            ],
        } },
    };
    global.fetch = async (url) => ({ ok: true, json: async () => responses[url] });

    await openMenuComposer({ id: 300, nom: 'Menu Le 280', image: 'burger.png' }, 'menus');
    document.querySelector('#composer-next').click(); // format Normal -> slot Accompagnement

    // Choix EXPLICITE de Potatoes (23), commandable dans les deux formats.
    document.querySelector('#slot-grid .composer-card[data-pid="23"]').click();
    document.querySelector('#composer-prev').click(); // retour a l'etape Format

    document.querySelector('[data-size="M"]').click(); // passage en Maxi

    const notice = document.querySelector('.composer-step__notice');
    assert.ok(!notice || notice.hasAttribute('hidden'), 'aucune deselection quand la selection reste commandable dans le nouveau format');

    document.querySelector('#composer-next').click();
    const potatoesMaxi = document.querySelector('#slot-grid .composer-card[data-pid="23"]');
    assert.equal(potatoesMaxi.getAttribute('aria-pressed'), 'true', 'la selection de Potatoes doit persister au changement de format');
});
