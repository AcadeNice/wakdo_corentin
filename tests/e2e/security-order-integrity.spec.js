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
//  - 51 articles au total (lignes individuellement valides) : 422 ORDER_TOO_LARGE ;
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
    expect(results.every(([, st]) => st === 201 || st === 422)).toBe(true);
    expect(results.map(([q, st]) => [q, st])).toEqual([[-5, 422], [0, 422], [-2147483648, 422]]);
  });

  test('quantite hors bornes de la base : 422 attendu, pas 500', async () => {
    const res = await order({ service_mode: 'takeaway', items: [line(fixture.productId, 70000)] });
    const status = res.status();
    expect([422, 500]).toContain(status);
    expect(status).toBe(422);
  });

  test('51 articles en une commande (somme des quantites, lignes individuellement valides) : 422 ORDER_TOO_LARGE', async () => {
    // Chaque ligne respecte MAX_QUANTITY_PER_LINE (<=20) et le nombre de lignes
    // respecte MAX_LINES_PER_ORDER (<=50) ; seule la SOMME des quantites (51) depasse
    // le plafond global d'articles par commande.
    const res = await order({
      service_mode: 'takeaway',
      items: [line(fixture.productId, 20), line(fixture.productId, 20), line(fixture.productId, 11)],
    });
    expect(res.status()).toBe(422);
    expect((await res.json()).error.code).toBe('ORDER_TOO_LARGE');
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
    expect(statuses.length).toBe(3);
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
    expect(stillOrderable).toBe(true);
  });
});

// Defaut #4 (important, metier) : une option de menu en rupture (retrait manuel
// is_available=0, ou rupture calculee RG-T21) reste commandable avant ce correctif --
// resolveSelections() ne verifiait que l'appartenance au slot, jamais la disponibilite.
// Fixture entierement JETABLE (burger + 2 sauces + 1 menu crees ici) pour ne rendre
// indisponible aucun produit ni menu du catalogue de demonstration.
test.describe('Option de menu indisponible (defaut #4)', () => {
  let admin2;
  let kiosk2;
  let fx;

  test.beforeAll(async () => {
    const ctx = await pwRequest.newContext();
    const login = await ctx.post(`${ADMIN}/admin/api/auth/login`, { data: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD } });
    expect(login.status()).toBe(200);
    admin2 = { ctx, csrf: (await login.json()).data.csrf_token };
    kiosk2 = await pwRequest.newContext();
    const h = { 'X-CSRF-Token': admin2.csrf };

    // Burger jetable (categorie burgers = 3), produit de base (F9-2).
    const burger = await ctx.post(`${ADMIN}/admin/api/products`, {
      headers: h, data: { category_id: 3, name: `OiBurger ${RUN}`, price_cents: 600, vat_rate: 100, is_available: true, display_order: 65535 },
    });
    expect(burger.status(), await burger.text()).toBe(201);
    const burgerId = (await burger.json()).data.id;

    // Deux sauces jetables (categorie sauces = 9, F12), options du MEME slot.
    const sauceA = await ctx.post(`${ADMIN}/admin/api/products`, {
      headers: h, data: { category_id: 9, name: `OiSauceA ${RUN}`, price_cents: 30, vat_rate: 100, is_available: true, display_order: 65535 },
    });
    expect(sauceA.status(), await sauceA.text()).toBe(201);
    const sauceAId = (await sauceA.json()).data.id;
    const sauceB = await ctx.post(`${ADMIN}/admin/api/products`, {
      headers: h, data: { category_id: 9, name: `OiSauceB ${RUN}`, price_cents: 30, vat_rate: 100, is_available: true, display_order: 65535 },
    });
    expect(sauceB.status(), await sauceB.text()).toBe(201);
    const sauceBId = (await sauceB.json()).data.id;

    // Menu jetable, UN slot Sauce REQUIS a deux options (categorie menus = 1).
    const menuRes = await ctx.post(`${ADMIN}/admin/api/menus`, {
      headers: h,
      data: {
        category_id: 1, burger_product_id: burgerId, name: `OiMenu ${RUN}`,
        price_normal_cents: 500, price_maxi_cents: 600, is_available: true, display_order: 65535,
        slots: [{ name: 'Sauce', slot_type: 'sauce', is_required: true, options: [sauceAId, sauceBId] }],
      },
    });
    expect(menuRes.status(), await menuRes.text()).toBe(201);
    const menuId = (await menuRes.json()).data.id;

    fx = { burgerId, sauceAId, sauceBId, menuId };
  });

  test.afterAll(async () => {
    if (admin2 && fx) {
      const h = { 'X-CSRF-Token': admin2.csrf };
      await admin2.ctx.put(`${ADMIN}/admin/api/menus/${fx.menuId}`, {
        headers: h, data: {
          category_id: 1, burger_product_id: fx.burgerId, name: `OiMenu ${RUN}`,
          price_normal_cents: 500, price_maxi_cents: 600, is_available: false, display_order: 65535,
          slots: [{ name: 'Sauce', slot_type: 'sauce', is_required: true, options: [fx.sauceAId, fx.sauceBId] }],
        },
      });
      await admin2.ctx.put(`${ADMIN}/admin/api/products/${fx.burgerId}`, { headers: h, data: { category_id: 3, name: `OiBurger ${RUN}`, price_cents: 600, vat_rate: 100, is_available: false, display_order: 65535 } });
      await admin2.ctx.put(`${ADMIN}/admin/api/products/${fx.sauceAId}`, { headers: h, data: { category_id: 9, name: `OiSauceA ${RUN}`, price_cents: 30, vat_rate: 100, is_available: false, display_order: 65535 } });
      await admin2.ctx.put(`${ADMIN}/admin/api/products/${fx.sauceBId}`, { headers: h, data: { category_id: 9, name: `OiSauceB ${RUN}`, price_cents: 30, vat_rate: 100, is_available: false, display_order: 65535 } });
    }
    if (admin2) await admin2.ctx.dispose();
    if (kiosk2) await kiosk2.dispose();
  });

  test('produit mis indisponible au back-office : option grisee sur la borne, refusee (422) en acces direct', async () => {
    const h = { 'X-CSRF-Token': admin2.csrf };

    await test.step('back-office : retrait manuel de la sauce B', async () => {
      const off = await admin2.ctx.put(`${ADMIN}/admin/api/products/${fx.sauceBId}`, {
        headers: h, data: { category_id: 9, name: `OiSauceB ${RUN}`, price_cents: 30, vat_rate: 100, is_available: false, display_order: 65535 },
      });
      expect(off.status(), await off.text()).toBe(200);
    });

    let slot;
    await test.step('GET /api/menus/{id} : la sauce B est marquee non commandable', async () => {
      const detail = await (await kiosk2.get(`${KIOSK}/api/menus/${fx.menuId}`)).json();
      slot = detail.data.slots[0];
      expect(slot.option_is_orderable[String(fx.sauceBId)]).toBe(false);
      expect(slot.option_is_orderable[String(fx.sauceAId)]).toBe(true);
    });

    await test.step('POST direct /api/orders avec la sauce B : refuse (422 OPTION_UNAVAILABLE)', async () => {
      const res = await kiosk2.post(`${KIOSK}/api/orders`, {
        data: {
          service_mode: 'takeaway',
          items: [{
            type: 'menu', menu_id: fx.menuId, quantity: 1, format: 'normal',
            selections: [{ menu_slot_id: slot.id, product_id: fx.sauceBId }],
          }],
        },
      });
      expect(res.status()).toBe(422);
      expect((await res.json()).error.code).toBe('OPTION_UNAVAILABLE');
    });
  });

  test('navigateur : le composeur borne grise l option indisponible', async ({ page }) => {
    // ?mode= est lu et memorise par nav.js sur N'IMPORTE QUELLE page (pas seulement
    // categories.html) : sans mode de consommation memorise, une page au-dela de
    // l'accueil renvoie vers l'ecran de bienvenue (garde nav.js::needsModeRedirect).
    await page.goto(`/products.html?category=1&mode=a-emporter`);
    const tile = page.locator('.product-card', { hasText: `OiMenu ${RUN}` });
    await expect(tile).toBeVisible();
    await tile.click();

    await expect(page.locator('.composer-overlay [role="dialog"]')).toBeVisible();
    // Etape Format (0) -> etape du slot Sauce (1), seul slot du menu jetable.
    await page.locator('#composer-next').click();

    const bad = page.locator(`#slot-grid .composer-card[data-pid="${fx.sauceBId}"]`);
    const good = page.locator(`#slot-grid .composer-card[data-pid="${fx.sauceAId}"]`);
    await expect(bad).toBeVisible();
    await expect(bad).toBeDisabled();
    await expect(bad).toHaveAttribute('aria-disabled', 'true');
    await expect(bad).toContainText('Indisponible');
    await expect(good).not.toBeDisabled();
    // La premiere option COMMANDABLE (sauce A) est pre-selectionnee, jamais la sauce B.
    await expect(good).toHaveAttribute('aria-pressed', 'true');
    await expect(bad).toHaveAttribute('aria-pressed', 'false');
  });
});
