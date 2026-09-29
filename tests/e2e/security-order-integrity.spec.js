// Tests de securite -- integrite de la commande borne (API publique anonyme). OWASP Top 10
// 2021 A04 Insecure Design (limites metier) et A03 Injection / validation d'entree ;
// OWASP ASVS 4.0 V5.1 (validation des entrees) et V11.1 (logique metier).
//
// Base existante : tests/Unit/Order/OrderControllerTest.php, OrderRepositoryTest.php,
// OrderRepositoryReplaceTest.php et tests/Integration/OrderReplaceDbTest.php (idempotence,
// recalcul serveur du total, rupture calculee RG-T21).
//
// Ajoute ici, contre la pile jetable :
//  - cle d'idempotence : 36 caracteres acceptes, 37 refuses (422) ;
//  - prix : tout montant envoye par le client est ignore, le total est recalcule ;
//  - encaissement rejoue : le stock n'est debite qu'une fois ;
//  - quantite negative, nulle, enorme, et corps mal forme : refus propre attendu
//    (constats, tests marques) ;
//  - une seule commande anonyme ne doit pas pouvoir vider le stock d'un produit pour tous
//    (constat, test marque) -- joue sur un produit ET un ingredient jetables crees ici,
//    pour ne rendre indisponible aucun produit du catalogue de demonstration.
const { test, expect, request: pwRequest } = require('@playwright/test');

const ADMIN = 'http://admin.wakdo.test';
const KIOSK = 'http://kiosk.wakdo.test';
const ADMIN_EMAIL = 'admin@wakdo.local';
const ADMIN_PASSWORD = 'WakdoAdmin2026!';
const RUN = `${Date.now().toString(36)}${Math.floor(Math.random() * 1e4).toString(36)}`;

let admin;
let kiosk;
let fixture;

async function order(data) {
  return kiosk.post(`${KIOSK}/api/orders`, { data });
}

function line(productId, quantity = 1, extra = {}) {
  return { type: 'product', product_id: productId, quantity, ...extra };
}

async function stockOf(ingredientId) {
  return (await (await admin.ctx.get(`${ADMIN}/admin/api/ingredients/${ingredientId}`)).json()).data.stock_quantity;
}

async function orderable(productId) {
  return (await (await kiosk.get(`${KIOSK}/api/products/${productId}`)).json()).data?.is_orderable ?? null;
}

test.describe.configure({ mode: 'serial' });

test.describe('Integrite de la commande borne', () => {
  test.beforeAll(async () => {
    kiosk = await pwRequest.newContext();
    const ctx = await pwRequest.newContext();
    const login = await ctx.post(`${ADMIN}/admin/api/auth/login`, { data: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD } });
    expect(login.status()).toBe(200);
    admin = { ctx, csrf: (await login.json()).data.csrf_token };
    const h = { 'X-CSRF-Token': admin.csrf };

    // Ingredient jetable, 100 000 unites en stock, seuil critique a 10 %.
    const ing = await ctx.post(`${ADMIN}/admin/api/ingredients`, {
      headers: h, data: { name: `Oi ${RUN}`, unit: 'g', pack_size: 1000, stock_capacity: 100000, low_stock_pct: 30, critical_stock_pct: 10 },
    });
    expect(ing.status(), await ing.text()).toBe(201);
    const ingredientId = (await ing.json()).data.id;
    const restock = await ctx.post(`${ADMIN}/admin/api/ingredients/${ingredientId}/restock`, { headers: h, data: { packs: 100 } });
    expect(restock.status(), await restock.text()).toBe(200);

    // Produit jetable (desserts, en fin de liste), 2 unites d'ingredient par produit.
    const prod = await ctx.post(`${ADMIN}/admin/api/products`, {
      headers: h, data: { category_id: 8, name: `Oi ${RUN}`, price_cents: 200, vat_rate: 100, is_available: true, display_order: 65535 },
    });
    expect(prod.status(), await prod.text()).toBe(201);
    const productId = (await prod.json()).data.id;
    const recipe = await ctx.put(`${ADMIN}/admin/api/products/${productId}/recipe`, {
      headers: h, data: { composition: [{ ingredient_id: ingredientId, quantity_normal: 2, quantity_maxi: 2, extra_price_cents: 0 }] },
    });
    expect(recipe.status(), await recipe.text()).toBe(200);
    fixture = { ingredientId, productId };
    expect(await stockOf(ingredientId)).toBe(100000);
    expect(await orderable(productId)).toBe(true);
  });

  test.afterAll(async () => {
    if (admin && fixture) {
      await admin.ctx.put(`${ADMIN}/admin/api/products/${fixture.productId}`, {
        headers: { 'X-CSRF-Token': admin.csrf },
        data: { category_id: 8, name: `Oi ${RUN}`, price_cents: 200, vat_rate: 100, is_available: false, display_order: 65535 },
      });
    }
    if (admin) await admin.ctx.dispose();
    if (kiosk) await kiosk.dispose();
  });

  test('cle d idempotence : 36 caracteres acceptes, 37 refuses (422)', async () => {
    const ok = await order({ idempotency_key: `k${RUN}`.padEnd(36, 'x'), service_mode: 'takeaway', items: [line(fixture.productId)] });
    expect(ok.status()).toBe(201);
    const tooLong = await order({ idempotency_key: `l${RUN}`.padEnd(37, 'x'), service_mode: 'takeaway', items: [line(fixture.productId)] });
    expect(tooLong.status()).toBe(422);
    expect((await tooLong.json()).error.code).toBe('INVALID_IDEMPOTENCY_KEY');
  });

  test('prix calcule par le serveur : prix, total et remise envoyes par le client ignores', async () => {
    const res = await order({
      service_mode: 'takeaway', total_ttc_cents: 1, discount_cents: 190,
      items: [line(fixture.productId, 3, { price_cents: 1, unit_price_cents: 1, unit_ttc: 1, extra_price_cents: -500 })],
    });
    expect(res.status()).toBe(201);
    const created = (await res.json()).data;
    expect(created.total_ttc_cents).toBe(3 * 200);
    const paid = await kiosk.post(`${KIOSK}/api/orders/${created.order_number}/pay`, { data: { amount_cents: 1, total_ttc_cents: 1 } });
    expect(paid.status()).toBe(200);
    expect((await paid.json()).data.total_ttc_cents).toBe(600);
  });

  test('encaissement rejoue : le stock n est debite qu une fois', async () => {
    const created = (await (await order({ service_mode: 'takeaway', items: [line(fixture.productId, 5)] })).json()).data;
    const before = await stockOf(fixture.ingredientId);
    const first = await kiosk.post(`${KIOSK}/api/orders/${created.order_number}/pay`);
    expect(first.status()).toBe(200);
    const second = await kiosk.post(`${KIOSK}/api/orders/${created.order_number}/pay`);
    expect([200, 409]).toContain(second.status());
    expect(await stockOf(fixture.ingredientId)).toBe(before - 5 * 2);
  });

  test('quantite negative ou nulle : refus attendu', async () => {
    const results = [];
    for (const quantity of [-5, 0, -2147483648]) {
      const res = await order({ service_mode: 'takeaway', items: [line(fixture.productId, quantity)] });
      results.push([quantity, res.status(), (await res.json()).data?.total_ttc_cents]);
    }
    // CONSTAT (mineur) : OrderRepository::resolveLine() ramene la quantite a 1
    // (max(1, (int) ...)) au lieu de la refuser : -5 devient une commande d'UN produit
    // (201). Pas d'argent en jeu (le total reste positif et calcule par le serveur), mais
    // une saisie invalide est acceptee en silence.
    expect(results.every(([, st]) => st === 201 || st === 422)).toBe(true);
    test.fail(true, 'quantite <= 0 ramenee a 1 au lieu d un refus (src/app/Order/OrderRepository.php, resolveLine)');
    expect(results.map(([q, st]) => [q, st])).toEqual([[-5, 422], [0, 422], [-2147483648, 422]]);
  });

  test('quantite hors bornes de la base : 422 attendu, pas 500', async () => {
    const res = await order({ service_mode: 'takeaway', items: [line(fixture.productId, 70000)] });
    const status = res.status();
    // CONSTAT (important) : aucune borne haute cote serveur. 70 000 depasse SMALLINT
    // UNSIGNED (order_item.quantity) : l'exception PDO remonte en 500 ; avec APP_DEBUG=true
    // le message SQL est renvoye au client ("Out of range value for column 'quantity'").
    expect([422, 500]).toContain(status);
    test.fail(true, 'aucune borne haute de quantite (src/app/Order/OrderRepository.php, resolveLine)');
    expect(status).toBe(422);
  });

  test('corps mal forme (ligne qui n est pas un objet, cle en tableau) : 422 attendu', async () => {
    const shapes = [
      { service_mode: 'takeaway', items: ['pas-un-objet'] },
      { service_mode: 'takeaway', items: [42] },
      { service_mode: 'takeaway', idempotency_key: ['a', 'b'], items: [line(fixture.productId)] },
    ];
    const statuses = [];
    for (const data of shapes) {
      const res = await kiosk.post(`${KIOSK}/api/orders`, { data });
      statuses.push(res.status());
    }
    // CONSTAT (mineur) : une ligne scalaire provoque un TypeError (500) dans
    // OrderRepository::resolveAndTotal() (array_map type array) ; une cle d'idempotence
    // tableau est convertie en la chaine "Array" (OrderRepository::idempotencyKey(), cast
    // (string)) : avec APP_DEBUG=true l'avertissement PHP, chemin du fichier compris, part
    // dans la reponse ; sinon la commande est creee sous la cle partagee "Array".
    expect(statuses.length).toBe(3);
    test.fail(true, 'forme des lignes et de la cle non verifiee (src/app/Order/OrderRepository.php, idempotencyKey / resolveAndTotal)');
    expect(statuses).toEqual([422, 422, 422]);
  });

  test('une commande anonyme ne peut pas vider le stock et rendre un produit indisponible pour tous', async () => {
    const created = await order({ service_mode: 'takeaway', items: [line(fixture.productId, 65535)] });
    expect([201, 422]).toContain(created.status());
    if (created.status() === 201) {
      const number = (await created.json()).data.order_number;
      expect((await kiosk.post(`${KIOSK}/api/orders/${number}/pay`)).status()).toBe(200);
    }
    const stock = await stockOf(fixture.ingredientId);
    const stillOrderable = await orderable(fixture.productId);
    test.info().annotations.push({ type: 'stock apres la commande', description: String(stock) });
    // CONSTAT (important) : 65 535 unites passent (201), l'encaissement anonyme aussi
    // (POST /api/orders/{n}/pay, sans session), et debite 131 070 unites : le stock devient
    // negatif et la rupture calculee (RG-T21) retire le produit de la borne pour TOUS les
    // clients. Deux requetes anonymes suffisent, sur chaque produit du catalogue.
    test.fail(true, 'quantite sans plafond + encaissement anonyme : deni de service sur le catalogue (OrderRepository::resolveLine, OrderController::pay)');
    expect(stillOrderable).toBe(true);
  });
});
