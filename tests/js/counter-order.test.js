/*
 * Tests du POS tactile de commande comptoir/drive (counter-order.js). node:test + jsdom.
 * Couvre la logique pure (serialisation du panier dans #items_json, calcul prix/total,
 * onglets categories) et l'UI a tuiles :
 *  - tap d'une tuile produit simple -> item {type:'product', quantity}, fusion sur re-tap
 *  - tap d'une tuile produit a modificateurs -> modale -> modifiers:[...]
 *  - tap d'une tuile menu -> modale (slots + format Maxi + modificateurs burger)
 *  - stepper +/- du panneau commande (ajuste qty, retire a 0)
 *  - slot requis non choisi -> message inline (pas d'ajout muet)
 *  - menu non configurable (slot_type non gere) ignore (anti-perte silencieuse)
 *
 * Le serveur revalide la forme (RG-T18), revalide chaque modificateur (resolveModifiers)
 * et recalcule les prix (RG-T16) : on n'asserte que la FORME emise. Le prix affiche
 * cote client (total + libelle du bouton) est INDICATIF : on verrouille seulement
 * l'affichage local (somme price + surcouts), pas une verite metier serveur.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

// counter-order.js est du CommonJS (admin = racine CommonJS) ; import par defaut.
import counterOrder from '../../src/public/admin/assets/js/counter-order.js';

const PRODUCTS = [
    {
        id: 12, name: 'Cheeseburger', price: 890, image: '', category_id: 1, category_name: 'Burgers',
        modifiers: [
            { ingredient_id: 3, name: 'Oignon', is_removable: 1, is_addable: 0, extra_price_cents: 0 },
            { ingredient_id: 8, name: 'Bacon', is_removable: 0, is_addable: 1, extra_price_cents: 50 },
        ],
    },
    { id: 22, name: 'Frites', price: 250, image: '', category_id: 2, category_name: 'Accompagnements', modifiers: [] },
    { id: 14, name: 'Coca', price: 200, image: '', category_id: 3, category_name: 'Boissons', modifiers: [] },
    { id: 47, name: 'Ketchup', price: 0, image: '', category_id: 2, category_name: 'Accompagnements', modifiers: [] },
];

const MENUS = [
    {
        id: 5,
        name: 'Menu Cheeseburger',
        price_normal: 990,
        price_maxi: 1190,
        image: '',
        category_id: 4,
        category_name: 'Menus',
        burger_modifiers: [
            { ingredient_id: 3, name: 'Oignon', is_removable: 1, is_addable: 0, extra_price_cents: 0 },
            { ingredient_id: 8, name: 'Bacon', is_removable: 0, is_addable: 1, extra_price_cents: 50 },
        ],
        slots: [
            { id: 16, name: 'Accompagnement', slot_type: 'side', is_required: 1, display_order: 2, option_product_ids: [22] },
            { id: 1, name: 'Boisson', slot_type: 'drink', is_required: 1, display_order: 1, option_product_ids: [14] },
            { id: 31, name: 'Sauce', slot_type: 'sauce', is_required: 0, display_order: 3, option_product_ids: [47] },
        ],
    },
];

function setup(products = PRODUCTS, menus = MENUS) {
    // Le catalogue est embarque dans deux scripts JSON inertes (CSP-safe), lus par le JS.
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body>' +
        '<form id="counter-order-form" method="post" action="/counter/orders">' +
        '  <input type="hidden" name="items_json" id="items_json" value="">' +
        `  <script type="application/json" id="pos-products">${JSON.stringify(products)}</script>` +
        `  <script type="application/json" id="pos-menus">${JSON.stringify(menus)}</script>` +
        '  <div class="pos__main">' +
        '    <div class="pos__catalogue">' +
        '      <div class="pos__tabs" id="pos-tabs" role="tablist"></div>' +
        '      <div class="pos__grid" id="pos-grid" role="tabpanel" tabindex="0"></div>' +
        '    </div>' +
        '    <aside class="pos__panel">' +
        '      <select id="service_mode" name="service_mode"><option value="dine_in" selected>Sur place</option><option value="takeaway">A emporter</option></select>' +
        '      <div id="service_tag_group"><input type="text" id="service_tag" name="service_tag"></div>' +
        '      <ul class="order-cart" id="order-cart"><li class="order-cart__empty" id="order-cart-empty">Panier vide.</li></ul>' +
        '      <p id="order-total">Total <span id="order-total-value">0,00 EUR</span></p>' +
        '      <button type="submit" id="order-submit">Encaisser 0,00 EUR</button>' +
        '      <span class="sr-only" id="pos-announce" role="status" aria-live="polite"></span>' +
        '    </aside>' +
        '  </div>' +
        '</form>' +
        '<div id="menu-composer-modal" hidden></div>' +
        '</body></html>',
    );
    return dom;
}

function fireSubmit(dom) {
    const form = dom.window.document.getElementById('counter-order-form');
    form.dispatchEvent(new dom.window.Event('submit', { cancelable: true, bubbles: true }));
}

function itemsJson(dom) {
    return JSON.parse(dom.window.document.getElementById('items_json').value || '[]');
}

function click(dom, node) {
    node.dispatchEvent(new dom.window.Event('click', { bubbles: true }));
}

// Active l'onglet d'une categorie par son libelle (les tuiles d'une seule categorie sont
// rendues a la fois). Renvoie la liste des tuiles affichees apres activation.
function activateCategory(dom, label) {
    const doc = dom.window.document;
    const tab = Array.prototype.find.call(
        doc.querySelectorAll('.pos__tab'),
        t => t.textContent === label,
    );
    assert.ok(tab, 'onglet "' + label + '" present');
    click(dom, tab);
    return Array.prototype.slice.call(doc.querySelectorAll('.pos-tile'));
}

// Tuile par nom de produit/menu (dans la grille de la categorie active).
function tileByName(dom, name) {
    const doc = dom.window.document;
    return Array.prototype.find.call(
        doc.querySelectorAll('.pos-tile'),
        t => t.querySelector('.pos-tile__name') && t.querySelector('.pos-tile__name').textContent === name,
    );
}

test('onglets categories : un onglet par categorie distincte (produits + menus)', () => {
    const dom = setup();
    counterOrder.init(dom.window.document);

    const labels = Array.prototype.map.call(
        dom.window.document.querySelectorAll('.pos__tab'),
        t => t.textContent,
    );
    assert.deepEqual(labels, ['Burgers', 'Accompagnements', 'Boissons', 'Menus']);
});

test('tuile produit simple : tap ajoute {type:product, quantity:1} ; re-tap fusionne (qty 2)', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    activateCategory(dom, 'Accompagnements'); // Frites (22), Ketchup (47)
    const frites = tileByName(dom, 'Frites');
    click(dom, frites);
    assert.ok(doc.querySelector('.order-cart__line'));

    click(dom, frites); // re-tap -> fusion (qty 2), pas une 2e ligne.
    assert.equal(doc.querySelectorAll('.order-cart__line').length, 1);

    fireSubmit(dom);
    assert.deepEqual(itemsJson(dom), [{ type: 'product', product_id: 22, quantity: 2, modifiers: [] }]);
});

test('tuile produit a modificateurs : tap ouvre la modale (pas d ajout direct)', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    activateCategory(dom, 'Burgers'); // Cheeseburger (12) a modificateurs
    click(dom, tileByName(dom, 'Cheeseburger'));

    const modal = doc.getElementById('menu-composer-modal');
    assert.equal(modal.hasAttribute('hidden'), false); // modale ouverte
    assert.equal(doc.querySelector('.order-cart__line'), null); // rien ajoute sans validation
});

test('personnalisation produit (retrait + ajout) -> items_json porte modifiers:[remove, add]', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    activateCategory(dom, 'Burgers');
    click(dom, tileByName(dom, 'Cheeseburger'));
    const modal = doc.getElementById('menu-composer-modal');
    assert.equal(modal.hasAttribute('hidden'), false);

    // Coche "sans Oignon" (retrait, ingredient 3) et "extra Bacon" (ajout, ingredient 8).
    const removeBox = modal.querySelector('.menu-composer__modifier-remove[data-ingredient-id="3"]');
    removeBox.checked = true;
    removeBox.dispatchEvent(new dom.window.Event('change', { bubbles: true }));

    const addBox = modal.querySelector('.menu-composer__modifier-add[data-ingredient-id="8"]');
    addBox.checked = true;
    addBox.dispatchEvent(new dom.window.Event('change', { bubbles: true }));

    click(dom, modal.querySelector('.menu-composer__add'));

    assert.equal(modal.hasAttribute('hidden'), true);
    assert.ok(doc.querySelector('.order-cart__line'));

    fireSubmit(dom);
    const items = itemsJson(dom);
    assert.equal(items.length, 1);
    assert.equal(items[0].type, 'product');
    assert.equal(items[0].product_id, 12);
    assert.equal(items[0].quantity, 1);
    assert.deepEqual(items[0].modifiers, [
        { ingredient_id: 3, action: 'remove' },
        { ingredient_id: 8, action: 'add' },
    ]);
});

test('panier vide -> items_json serialise []', () => {
    const dom = setup();
    counterOrder.init(dom.window.document);

    fireSubmit(dom);
    assert.deepEqual(itemsJson(dom), []);
});

test('stepper +/- : + incremente, - decremente, 0 retire la ligne', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    activateCategory(dom, 'Accompagnements');
    click(dom, tileByName(dom, 'Frites'));

    const inc = doc.querySelector('.order-cart__qty-btn[aria-label^="Augmenter"]');
    click(dom, inc); // qty 1 -> 2
    assert.equal(doc.querySelector('.order-cart__qty-value').textContent, '2');

    const dec = doc.querySelector('.order-cart__qty-btn[aria-label^="Diminuer"]');
    click(dom, dec); // 2 -> 1
    assert.equal(doc.querySelector('.order-cart__qty-value').textContent, '1');

    click(dom, doc.querySelector('.order-cart__qty-btn[aria-label^="Diminuer"]')); // 1 -> 0 = retrait
    assert.equal(doc.querySelector('.order-cart__line'), null);
    fireSubmit(dom);
    assert.deepEqual(itemsJson(dom), []);
});

test('retirer une ligne via le bouton Retirer', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    activateCategory(dom, 'Accompagnements');
    click(dom, tileByName(dom, 'Frites'));
    assert.ok(doc.querySelector('.order-cart__line'));

    click(dom, doc.querySelector('.order-cart__remove'));
    assert.equal(doc.querySelector('.order-cart__line'), null);
});

test('configuration menu (format Maxi + slots) -> items_json contient {type:menu, format:maxi, selections}', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    activateCategory(dom, 'Menus');
    click(dom, tileByName(dom, 'Menu Cheeseburger'));

    const modal = doc.getElementById('menu-composer-modal');
    assert.equal(modal.hasAttribute('hidden'), false);

    // Passe en Maxi.
    const maxiRadio = Array.prototype.find.call(
        modal.querySelectorAll('.menu-composer__format-input'),
        r => r.value === 'maxi',
    );
    maxiRadio.checked = true;
    maxiRadio.dispatchEvent(new dom.window.Event('change', { bubbles: true }));

    // Slots requis (side/drink) sont pre-selectionnes (1er choix) ; on ajoute la sauce.
    const sauceSelect = Array.prototype.find.call(
        modal.querySelectorAll('.menu-composer__slot-select'),
        s => s.dataset.slotId === '31',
    );
    sauceSelect.value = '47';
    sauceSelect.dispatchEvent(new dom.window.Event('change', { bubbles: true }));

    click(dom, modal.querySelector('.menu-composer__add'));

    // Modale fermee, panier recap mis a jour.
    assert.equal(modal.hasAttribute('hidden'), true);
    assert.ok(doc.querySelector('.order-cart__line'));

    fireSubmit(dom);
    const items = itemsJson(dom);
    assert.equal(items.length, 1);
    assert.equal(items[0].type, 'menu');
    assert.equal(items[0].menu_id, 5);
    assert.equal(items[0].format, 'maxi');
    assert.equal(items[0].quantity, 1);
    // Selections : slots tries par display_order (drink=1, side=2, sauce=3).
    assert.deepEqual(items[0].selections, [
        { menu_slot_id: 1, product_id: 14 },
        { menu_slot_id: 16, product_id: 22 },
        { menu_slot_id: 31, product_id: 47 },
    ]);
});

test('quantite MENU : stepper + sur une ligne menu -> items_json porte quantity:2, un seul jeu de selections', () => {
    // G : la quantite d'une ligne menu est ajustable au panneau (stepper) et serialisee
    // dans quantity ; les selections de slot ne sont PAS dupliquees par la quantite.
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    activateCategory(dom, 'Menus');
    click(dom, tileByName(dom, 'Menu Cheeseburger'));
    const modal = doc.getElementById('menu-composer-modal');
    click(dom, modal.querySelector('.menu-composer__add')); // ajoute le menu (requis pre-selectionnes)

    // Stepper + sur la ligne menu : qty 1 -> 2.
    click(dom, doc.querySelector('.order-cart__qty-btn[aria-label^="Augmenter"]'));
    assert.equal(doc.querySelector('.order-cart__qty-value').textContent, '2');

    fireSubmit(dom);
    const items = itemsJson(dom);
    assert.equal(items.length, 1);
    assert.equal(items[0].type, 'menu');
    assert.equal(items[0].quantity, 2);
    // Un SEUL jeu de selections (requis : drink + side), pas duplique par la quantite.
    assert.deepEqual(items[0].selections, [
        { menu_slot_id: 1, product_id: 14 },
        { menu_slot_id: 16, product_id: 22 },
    ]);
});

test('total : menu Maxi (11,90) x2 -> 23,80 EUR (quantite multipliee)', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    activateCategory(dom, 'Menus');
    click(dom, tileByName(dom, 'Menu Cheeseburger'));
    const modal = doc.getElementById('menu-composer-modal');
    const maxiRadio = Array.prototype.find.call(
        modal.querySelectorAll('.menu-composer__format-input'),
        r => r.value === 'maxi',
    );
    maxiRadio.checked = true;
    maxiRadio.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
    click(dom, modal.querySelector('.menu-composer__add'));

    click(dom, doc.querySelector('.order-cart__qty-btn[aria-label^="Augmenter"]')); // x2

    assert.equal(doc.querySelector('.order-cart__price').textContent, '23,80 EUR');
    assert.equal(doc.getElementById('order-total-value').textContent, '23,80 EUR');
});

test('menu Normal sans la sauce optionnelle -> selections ne contient que les requis', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    activateCategory(dom, 'Menus');
    click(dom, tileByName(dom, 'Menu Cheeseburger'));
    const modal = doc.getElementById('menu-composer-modal');

    // Laisse la sauce a "Sans" (valeur vide) ; ajoute directement.
    const sauceSelect = Array.prototype.find.call(
        modal.querySelectorAll('.menu-composer__slot-select'),
        s => s.dataset.slotId === '31',
    );
    sauceSelect.value = '';
    sauceSelect.dispatchEvent(new dom.window.Event('change', { bubbles: true }));

    click(dom, modal.querySelector('.menu-composer__add'));
    fireSubmit(dom);

    const items = itemsJson(dom);
    assert.equal(items[0].format, 'normal');
    assert.deepEqual(items[0].selections, [
        { menu_slot_id: 1, product_id: 14 },
        { menu_slot_id: 16, product_id: 22 },
    ]);
});

test('produit + menu combines -> items_json contient les deux lignes', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    // Frites (22) sans modificateur -> ajout direct par tap.
    activateCategory(dom, 'Accompagnements');
    click(dom, tileByName(dom, 'Frites'));

    activateCategory(dom, 'Menus');
    click(dom, tileByName(dom, 'Menu Cheeseburger'));
    const modal = doc.getElementById('menu-composer-modal');
    click(dom, modal.querySelector('.menu-composer__add'));

    fireSubmit(dom);
    const items = itemsJson(dom);
    assert.equal(items.length, 2);
    assert.equal(items.filter(i => i.type === 'product').length, 1);
    assert.equal(items.filter(i => i.type === 'menu').length, 1);
});

test('configuration menu avec modificateur burger -> item menu porte modifiers:[remove]', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    activateCategory(dom, 'Menus');
    click(dom, tileByName(dom, 'Menu Cheeseburger'));
    const modal = doc.getElementById('menu-composer-modal');

    // Retire l'oignon du burger (ingredient 3, is_removable).
    const removeBox = modal.querySelector('.menu-composer__modifier-remove[data-ingredient-id="3"]');
    removeBox.checked = true;
    removeBox.dispatchEvent(new dom.window.Event('change', { bubbles: true }));

    click(dom, modal.querySelector('.menu-composer__add'));
    fireSubmit(dom);

    const items = itemsJson(dom);
    assert.equal(items[0].type, 'menu');
    assert.deepEqual(items[0].modifiers, [{ ingredient_id: 3, action: 'remove' }]);
});

test('total + bouton : produit simple (Frites 2,50 x2) -> 5,00 EUR affiche', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    activateCategory(dom, 'Accompagnements');
    const frites = tileByName(dom, 'Frites'); // 250c
    click(dom, frites);
    click(dom, frites); // qty 2

    assert.equal(doc.getElementById('order-total-value').textContent, '5,00 EUR');
    assert.equal(doc.getElementById('order-submit').textContent, 'Encaisser 5,00 EUR');
});

test('total : produit personnalise avec ajout (Cheeseburger 8,90 + Bacon 0,50) -> 9,40 EUR', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    activateCategory(dom, 'Burgers');
    click(dom, tileByName(dom, 'Cheeseburger'));
    const modal = doc.getElementById('menu-composer-modal');
    const addBox = modal.querySelector('.menu-composer__modifier-add[data-ingredient-id="8"]');
    addBox.checked = true;
    addBox.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
    click(dom, modal.querySelector('.menu-composer__add'));

    // Prix de ligne affiche dans le panier.
    assert.equal(doc.querySelector('.order-cart__price').textContent, '9,40 EUR');
    assert.equal(doc.getElementById('order-total-value').textContent, '9,40 EUR');
});

test('total : menu Maxi (11,90) inclus dans le total de ligne', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    activateCategory(dom, 'Menus');
    click(dom, tileByName(dom, 'Menu Cheeseburger'));
    const modal = doc.getElementById('menu-composer-modal');
    const maxiRadio = Array.prototype.find.call(
        modal.querySelectorAll('.menu-composer__format-input'),
        r => r.value === 'maxi',
    );
    maxiRadio.checked = true;
    maxiRadio.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
    click(dom, modal.querySelector('.menu-composer__add'));

    assert.equal(doc.querySelector('.order-cart__price').textContent, '11,90 EUR');
    assert.equal(doc.getElementById('order-total-value').textContent, '11,90 EUR');
});

test('numero de table : masque hors sur place, visible en sur place (toggle service_mode)', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    const group = doc.getElementById('service_tag_group');
    const select = doc.getElementById('service_mode');

    // Init : dine_in pre-selectionne -> visible.
    assert.equal(group.hasAttribute('hidden'), false);

    select.value = 'takeaway';
    select.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
    assert.equal(group.hasAttribute('hidden'), true);

    select.value = 'dine_in';
    select.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
    assert.equal(group.hasAttribute('hidden'), false);
});

test('modale menu : slot requis non choisi -> message inline, pas d ajout muet', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    activateCategory(dom, 'Menus');
    click(dom, tileByName(dom, 'Menu Cheeseburger'));
    const modal = doc.getElementById('menu-composer-modal');

    // Vide un slot requis (drink, slot 1) : un slot requis n'a pas d'option Sans, mais
    // jsdom autorise l'affectation d'une value vide -> change supprime la selection.
    const drinkSelect = Array.prototype.find.call(
        modal.querySelectorAll('.menu-composer__slot-select'),
        s => s.dataset.slotId === '1',
    );
    drinkSelect.value = '';
    drinkSelect.dispatchEvent(new dom.window.Event('change', { bubbles: true }));

    // Le <p role=alert> est present des l'ouverture (vide), avant toute erreur.
    const errAtOpen = modal.querySelector('.menu-composer__error');
    assert.ok(errAtOpen);
    assert.equal(errAtOpen.getAttribute('role'), 'alert');
    assert.equal(errAtOpen.textContent, '');
    assert.equal(errAtOpen.hasAttribute('hidden'), false); // present en permanence (a11y)

    click(dom, modal.querySelector('.menu-composer__add'));

    // Modale encore ouverte, message inline renseigne (textContent), aucune ligne.
    assert.equal(modal.hasAttribute('hidden'), false);
    assert.notEqual(errAtOpen.textContent, '');
    assert.equal(doc.querySelector('.order-cart__line'), null);
});

test('RG-T21 : tuile non commandable (rupture) -> grisee, aria-disabled, badge Indisponible, tap n ajoute rien', () => {
    // commandable:false = rupture de stock calculee cote serveur (parite borne). La
    // tuile reste visible mais desactivee ; un tap ne doit RIEN ajouter au panier.
    const RUPTURE = [
        { id: 70, name: 'Frites', price: 250, image: '', category_id: 2, category_name: 'Accompagnements', modifiers: [], commandable: false },
    ];
    const dom = setup(RUPTURE, []);
    const doc = dom.window.document;
    counterOrder.init(doc);

    activateCategory(dom, 'Accompagnements');
    const tile = tileByName(dom, 'Frites');
    assert.ok(tile);
    // Etat desactive : classe modifier + aria-disabled (echo UX de la rupture).
    assert.equal(tile.classList.contains('pos-tile--unavailable'), true);
    assert.equal(tile.getAttribute('aria-disabled'), 'true');
    // Badge "Indisponible" present (parite borne product-card__badge).
    const badge = tile.querySelector('.pos-tile__badge--unavailable');
    assert.ok(badge);
    assert.equal(badge.textContent, 'Indisponible');
    // L'aria-label porte l'etat indisponible (annonce lecteur d'ecran).
    assert.match(tile.getAttribute('aria-label'), /indisponible/);

    // Tap : aucune ligne n'est creee, items_json reste vide.
    click(dom, tile);
    assert.equal(doc.querySelector('.order-cart__line'), null);
    fireSubmit(dom);
    assert.deepEqual(itemsJson(dom), []);
});

test('RG-T21 : menu non commandable (burger en rupture) -> tap n ouvre pas la modale', () => {
    // Un menu dont le burger impose est en rupture (commandable:false) est grise et son
    // tap ne doit pas ouvrir le composeur (granularite burger seul, parite borne).
    const menuRupture = [{
        id: 9,
        name: 'Menu Indispo',
        price_normal: 990,
        price_maxi: 1190,
        image: '',
        category_id: 4,
        category_name: 'Menus',
        commandable: false,
        burger_modifiers: [],
        slots: [
            { id: 1, name: 'Boisson', slot_type: 'drink', is_required: 1, display_order: 1, option_product_ids: [14] },
        ],
    }];
    const dom = setup([{ id: 14, name: 'Coca', price: 200, image: '', category_id: 3, category_name: 'Boissons', modifiers: [] }], menuRupture);
    const doc = dom.window.document;
    counterOrder.init(doc);

    activateCategory(dom, 'Menus');
    const tile = tileByName(dom, 'Menu Indispo');
    assert.ok(tile);
    assert.equal(tile.classList.contains('pos-tile--unavailable'), true);
    assert.equal(tile.getAttribute('aria-disabled'), 'true');
    // Une tuile en rupture n'annonce pas l'intention de composer (pas d aria-haspopup).
    assert.equal(tile.hasAttribute('aria-haspopup'), false);

    click(dom, tile);
    const modal = doc.getElementById('menu-composer-modal');
    assert.equal(modal.hasAttribute('hidden'), true); // modale non ouverte
});

test('tuile : pastille de repli quand aucune image (image vide)', () => {
    const dom = setup();
    counterOrder.init(dom.window.document);

    activateCategory(dom, 'Burgers');
    const tile = tileByName(dom, 'Cheeseburger');
    // image vide -> aucune <img>, une pastille (initiale C) a la place.
    assert.equal(tile.querySelector('.pos-tile__image'), null);
    assert.equal(tile.querySelector('.pos-tile__pastille').textContent, 'C');
});

test('tuile : image rendue quand image fournie', () => {
    const withImg = [{ id: 99, name: 'Special', price: 500, image: '/img/special.png', category_id: 1, category_name: 'Burgers', modifiers: [] }];
    const dom = setup(withImg, []);
    counterOrder.init(dom.window.document);

    activateCategory(dom, 'Burgers');
    const img = tileByName(dom, 'Special').querySelector('.pos-tile__image');
    assert.ok(img);
    assert.equal(img.getAttribute('src'), '/img/special.png');
});

test('modale : focus restaure sur la tuile declencheuse a la fermeture', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    activateCategory(dom, 'Menus');
    const trigger = tileByName(dom, 'Menu Cheeseburger');
    trigger.focus();
    assert.equal(doc.activeElement, trigger);

    click(dom, trigger);
    const modal = doc.getElementById('menu-composer-modal');
    // Le focus est entre dans la modale (plus sur la tuile declencheuse).
    assert.notEqual(doc.activeElement, trigger);

    doc.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    // Ferme -> focus restaure sur la tuile.
    assert.equal(doc.activeElement, trigger);
});

test('modale : panel porte role=dialog, aria-modal et aria-labelledby (titre)', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    activateCategory(dom, 'Menus');
    click(dom, tileByName(dom, 'Menu Cheeseburger'));
    const panel = doc.querySelector('.menu-composer');
    assert.equal(panel.getAttribute('role'), 'dialog');
    assert.equal(panel.getAttribute('aria-modal'), 'true');
    const labelledby = panel.getAttribute('aria-labelledby');
    assert.ok(labelledby);
    const title = doc.getElementById(labelledby);
    assert.ok(title);
    assert.equal(title.classList.contains('menu-composer__title'), true);
});

test('total : separateur de milliers aligne sur PHP (1 234,50 EUR)', () => {
    // Produit a 617,25 EUR (61725c) x2 = 1 234,50 EUR -> espace separateur de milliers.
    const PRICEY = [{ id: 99, name: 'Plateau', price: 61725, image: '', category_id: 1, category_name: 'Plateaux', modifiers: [] }];
    const dom = setup(PRICEY, []);
    const doc = dom.window.document;
    counterOrder.init(doc);

    activateCategory(dom, 'Plateaux');
    const tile = tileByName(dom, 'Plateau');
    click(dom, tile);
    click(dom, tile); // qty 2

    assert.equal(doc.getElementById('order-total-value').textContent, '1 234,50 EUR');
    assert.equal(doc.getElementById('order-submit').textContent, 'Encaisser 1 234,50 EUR');
});

test('modale : touche Echap ferme la modale', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    activateCategory(dom, 'Menus');
    click(dom, tileByName(dom, 'Menu Cheeseburger'));
    const modal = doc.getElementById('menu-composer-modal');
    assert.equal(modal.hasAttribute('hidden'), false);

    doc.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    assert.equal(modal.hasAttribute('hidden'), true);
});

test('buildCategoryTabs: une entree par categorie, comptage cumule produits+menus', () => {
    const tabs = counterOrder.buildCategoryTabs(PRODUCTS, MENUS);
    assert.deepEqual(tabs.map(t => t.name), ['Burgers', 'Accompagnements', 'Boissons', 'Menus']);
    // Accompagnements regroupe Frites + Ketchup.
    assert.equal(tabs.find(t => t.name === 'Accompagnements').count, 2);
    assert.equal(tabs.find(t => t.name === 'Menus').count, 1);
});

test('composerSteps: slot_type non gere (dessert) ignore, slots tries par display_order', () => {
    const productById = {};
    PRODUCTS.forEach(p => { productById[p.id] = p; });
    const menu = {
        id: 9,
        slots: [
            { id: 99, name: 'Dessert', slot_type: 'dessert', is_required: 1, display_order: 4, option_product_ids: [22] },
            ...MENUS[0].slots,
        ],
    };
    const steps = counterOrder.composerSteps(menu, productById);
    assert.deepEqual(steps.map(s => s.slotType), ['drink', 'side', 'sauce']); // dessert exclu, tri display_order
});

test('A : changer d onglet conserve le focus clavier sur l onglet actif (pas de retour vers body)', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    const tabs = doc.querySelectorAll('.pos__tab');
    const second = tabs[1]; // Accompagnements
    second.focus();
    assert.equal(doc.activeElement, second);

    click(dom, second);
    // Le bouton n'est PAS detruit (pas de reconstruction de la barre) : focus preserve.
    assert.equal(doc.activeElement, second);
    assert.equal(second.classList.contains('is-active'), true);
    // Les autres onglets existent encore (memes references, simplement mutees).
    assert.equal(doc.querySelectorAll('.pos__tab').length, tabs.length);
});

test('B : roving tabindex (actif=0, autres=-1) et aria-selected coherents', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    const tabs = Array.prototype.slice.call(doc.querySelectorAll('.pos__tab'));
    // Au depart : 1er onglet actif (tabindex 0), les autres -1.
    assert.equal(tabs[0].tabIndex, 0);
    assert.equal(tabs[0].getAttribute('aria-selected'), 'true');
    tabs.slice(1).forEach(t => {
        assert.equal(t.tabIndex, -1);
        assert.equal(t.getAttribute('aria-selected'), 'false');
    });

    // Apres activation du 3e : le roving tabindex suit.
    click(dom, tabs[2]);
    assert.equal(tabs[2].tabIndex, 0);
    assert.equal(tabs[2].getAttribute('aria-selected'), 'true');
    assert.equal(tabs[0].tabIndex, -1);
});

test('B : Fleche droite/gauche deplace le focus ET active l onglet (cyclique)', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    const tabs = Array.prototype.slice.call(doc.querySelectorAll('.pos__tab'));
    tabs[0].focus();

    // L'event remonte (bubbles) jusqu'au conteneur tablist ; event.target est l'onglet
    // focalise. On dispatche depuis l'element actif pour refleter le focus clavier reel.
    function arrowFromActive(key) {
        doc.activeElement.dispatchEvent(
            new dom.window.KeyboardEvent('keydown', { key, bubbles: true }),
        );
    }

    arrowFromActive('ArrowRight'); // 0 -> 1
    assert.equal(doc.activeElement, tabs[1]);
    assert.equal(tabs[1].getAttribute('aria-selected'), 'true');

    arrowFromActive('ArrowLeft'); // 1 -> 0
    assert.equal(doc.activeElement, tabs[0]);

    arrowFromActive('ArrowLeft'); // 0 -> dernier (cyclique)
    assert.equal(doc.activeElement, tabs[tabs.length - 1]);

    arrowFromActive('Home'); // -> premier
    assert.equal(doc.activeElement, tabs[0]);

    arrowFromActive('End'); // -> dernier
    assert.equal(doc.activeElement, tabs[tabs.length - 1]);
});

test('B : onglets relies au tabpanel (aria-controls vers la grille, grille labellisee par l onglet actif)', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    const grid = doc.getElementById('pos-grid');
    const tabs = Array.prototype.slice.call(doc.querySelectorAll('.pos__tab'));
    tabs.forEach(t => assert.equal(t.getAttribute('aria-controls'), 'pos-grid'));

    // La grille (tabpanel) est libellee par l'onglet actif.
    assert.equal(grid.getAttribute('aria-labelledby'), tabs[0].id);
    click(dom, tabs[1]);
    assert.equal(grid.getAttribute('aria-labelledby'), tabs[1].id);
});

test('C : region live concise mise a jour a chaque mutation (total + nombre d articles)', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);
    const announce = doc.getElementById('pos-announce');

    // Init : panier vide.
    assert.equal(announce.textContent, 'Panier vide');

    activateCategory(dom, 'Accompagnements');
    const frites = tileByName(dom, 'Frites'); // 250c
    click(dom, frites);
    assert.equal(announce.textContent, 'Total 2,50 EUR, 1 article');

    click(dom, frites); // qty 2
    assert.equal(announce.textContent, 'Total 5,00 EUR, 2 articles');
});

test('C : ni #order-cart ni #pos-grid ne portent aria-live (eviter la verbosite)', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    assert.equal(doc.getElementById('order-cart').hasAttribute('aria-live'), false);
    assert.equal(doc.getElementById('pos-grid').hasAttribute('aria-live'), false);
});

test('D : tuile qui ouvre la modale porte aria-haspopup=dialog et l intention dans l aria-label', () => {
    const dom = setup();
    counterOrder.init(dom.window.document);

    activateCategory(dom, 'Burgers');
    const burger = tileByName(dom, 'Cheeseburger'); // a modificateurs -> modale
    assert.equal(burger.getAttribute('aria-haspopup'), 'dialog');
    assert.match(burger.getAttribute('aria-label'), /a composer/);

    activateCategory(dom, 'Menus');
    const menu = tileByName(dom, 'Menu Cheeseburger');
    assert.equal(menu.getAttribute('aria-haspopup'), 'dialog');
    assert.match(menu.getAttribute('aria-label'), /menu a composer/);
});

test('D : tuile produit simple n a PAS aria-haspopup (ajout direct au tap)', () => {
    const dom = setup();
    counterOrder.init(dom.window.document);

    activateCategory(dom, 'Accompagnements');
    const frites = tileByName(dom, 'Frites'); // sans modificateur
    assert.equal(frites.hasAttribute('aria-haspopup'), false);
});

test('E : quantite invalide dans la modale produit -> ramenee a 1 et reaffichee dans l input', () => {
    const dom = setup();
    const doc = dom.window.document;
    counterOrder.init(doc);

    activateCategory(dom, 'Burgers');
    click(dom, tileByName(dom, 'Cheeseburger'));
    const modal = doc.getElementById('menu-composer-modal');
    const qtyInput = modal.querySelector('#composer-product-qty');

    qtyInput.value = '0';
    qtyInput.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
    assert.equal(qtyInput.value, '1'); // valeur corrigee reaffichee

    qtyInput.value = '';
    qtyInput.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
    assert.equal(qtyInput.value, '1');
});
