/*
 * Tests de checkout.js (P5 L4), node:test. checkout.js + state.js + data.js n'ont
 * aucun acces DOM au chargement -> import statique. Cible : traduction PURE
 * panier->contrat /api/orders (mapServiceMode, buildSelections, buildOrderItem,
 * buildOrderPayload) + submitOrder (fetch + localStorage mockes).
 */
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import {
    mapServiceMode, buildSelections, buildOrderItem, buildOrderPayload, submitOrder,
} from '../../src/public/borne/assets/js/checkout.js';

/* --- mapServiceMode ------------------------------------------------------ */

test('mapServiceMode: borne -> contrat (dine_in / takeaway), inconnu -> null', () => {
    assert.equal(mapServiceMode('sur-place'), 'dine_in');
    assert.equal(mapServiceMode('a-emporter'), 'takeaway');
    assert.equal(mapServiceMode(null), null);
});

/* --- buildSelections ----------------------------------------------------- */

const slots = () => ([
    { id: 1, option_product_ids: [14, 15] },   // boisson
    { id: 16, option_product_ids: [22, 23] },  // accompagnement
    { id: 31, option_product_ids: [47] },      // sauce
]);

test('buildSelections: mappe les produits choisis a leur slot', () => {
    const comp = { accompagnement: { id: 22 }, boisson: { id: 14 }, sauce: { id: 47 } };
    assert.deepEqual(buildSelections(comp, slots()), [
        { menu_slot_id: 16, product_id: 22 },
        { menu_slot_id: 1, product_id: 14 },
        { menu_slot_id: 31, product_id: 47 },
    ]);
});

test('buildSelections: produit hors slots ignore ; composition vide -> []', () => {
    assert.deepEqual(buildSelections({ boisson: { id: 999 } }, slots()), []);
    assert.deepEqual(buildSelections(undefined, slots()), []);
});

/* --- buildOrderItem ------------------------------------------------------ */

test('buildOrderItem: produit simple', () => {
    assert.deepEqual(buildOrderItem({ id: 14, type: 'produit', quantite: 3 }, {}), {
        type: 'product', product_id: 14, quantity: 3,
    });
});

test('buildOrderItem: menu normal vs maxi (format + selections)', () => {
    const menuItem = { id: 1, type: 'menu', quantite: 1, supplement_cents: 0,
        composition: { accompagnement: { id: 22 }, boisson: { id: 14 } } };
    const normal = buildOrderItem(menuItem, { 1: slots() });
    assert.equal(normal.format, 'normal');
    assert.equal(normal.menu_id, 1);
    assert.equal(normal.selections.length, 2);

    const maxi = buildOrderItem({ ...menuItem, supplement_cents: 150 }, { 1: slots() });
    assert.equal(maxi.format, 'maxi');
});

/* --- buildOrderPayload --------------------------------------------------- */

test('buildOrderPayload: dine_in inclut service_tag ; takeaway l omet', () => {
    const cart = [{ id: 14, type: 'produit', quantite: 1 }];
    const din = buildOrderPayload(cart, 'sur-place', '261', {}, 'key-1');
    assert.equal(din.service_mode, 'dine_in');
    assert.equal(din.service_tag, '261');
    assert.equal(din.idempotency_key, 'key-1');
    assert.equal(din.items.length, 1);

    const take = buildOrderPayload(cart, 'a-emporter', '', {}, 'key-2');
    assert.equal(take.service_mode, 'takeaway');
    assert.equal('service_tag' in take, false);
});

/* --- submitOrder (mocks) ------------------------------------------------- */

function stubEnv(cart, mode) {
    const store = { wakdo_cart: JSON.stringify(cart), wakdo_mode: mode };
    global.localStorage = {
        getItem: (k) => (k in store ? store[k] : null),
        setItem: (k, v) => { store[k] = String(v); },
        removeItem: (k) => { delete store[k]; },
    };
}

test('submitOrder: re-fetch slots, POST create puis pay, renvoie order_number', async () => {
    stubEnv([{ id: 1, type: 'menu', quantite: 1, supplement_cents: 0, composition: { boisson: { id: 14 }, accompagnement: { id: 22 } } }], 'sur-place');
    const calls = [];
    global.fetch = async (url, opts) => {
        calls.push({ url, method: opts?.method, body: opts?.body ? JSON.parse(opts.body) : null });
        if (url === '/api/menus/1') return { ok: true, json: async () => ({ data: { slots: slots() } }) };
        if (url === '/api/orders') return { ok: true, json: async () => ({ data: { order_number: 'K12', total_ttc_cents: 800, status: 'pending_payment' } }) };
        if (url === '/api/orders/K12/pay') return { ok: true, json: async () => ({ data: { order_number: 'K12', total_ttc_cents: 800, status: 'paid' } }) };
        throw new Error(`URL inattendue: ${url}`);
    };

    const res = await submitOrder({ serviceTag: '261' });
    assert.equal(res.order_number, 'K12');
    assert.equal(res.total_ttc_cents, 800);

    const create = calls.find(c => c.url === '/api/orders');
    assert.equal(create.method, 'POST');
    assert.equal(create.body.service_mode, 'dine_in');
    assert.equal(create.body.service_tag, '261');
    assert.equal(create.body.items[0].type, 'menu');
    assert.equal(create.body.items[0].selections.length, 2);
    assert.ok(calls.some(c => c.url === '/api/orders/K12/pay' && c.method === 'POST'));
});

test('submitOrder: panier vide -> jette EMPTY_CART', async () => {
    stubEnv([], 'a-emporter');
    await assert.rejects(() => submitOrder(), /EMPTY_CART/);
});
