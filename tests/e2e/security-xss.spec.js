// Tests de securite -- injection de script (XSS) stockee et reflechie. OWASP Top 10 2021
// A03 Injection (la XSS y est rattachee) ; OWASP ASVS 4.0 V5.3.3 (echappement selon le
// contexte de sortie).
//
// Methode : des libelles piegés sont crees par l'API d'administration (categorie, produits,
// ingredient) et par l'API publique de la borne (numero de chevalet d'une commande payee),
// puis chaque ecran qui les affiche est ouvert dans Chromium. Pour chaque ecran on verifie :
//  1. aucun script n'a tourne (window.__x reste indefini) ;
//  2. aucun element injecte n'existe dans le DOM (marqueurs <b id=sx..>, attributs on*) --
//     c'est la preuve de l'ECHAPPEMENT, independante de la CSP : la CSP (script-src 'self')
//     bloquerait aussi un gestionnaire inline, donc (1) seul ne suffirait pas ;
//  3. aucune violation CSP n'est journalisee (une violation voudrait dire qu'un element
//     injecte a ete interprete puis bloque par la CSP : l'echappement aurait echoue) ;
//  4. le libelle apparait en TEXTE litteral (il n'a pas ete filtre en silence).
//
// Serial : un seul jeu de donnees piegees par execution (noms uniques). Les produits piegés
// sont ranges dans la categorie "desserts" (id 8) : la borne associe ses pages produits aux
// categories du seed par une table fixe (CATEGORY_ID_TO_SLUG, borne/assets/js/data.js), une
// categorie creee au back-office n'y a donc pas de page produits. afterAll rend les
// produits indisponibles et desactive la categorie piegee, pour que la borne ne les affiche
// plus si d'autres specs suivent sur la meme pile.
const { test, expect, request: pwRequest } = require('@playwright/test');

const ADMIN = 'http://admin.wakdo.test';
const KIOSK = 'http://kiosk.wakdo.test';
const ADMIN_EMAIL = 'admin@wakdo.local';
const ADMIN_PASSWORD = 'WakdoAdmin2026!';
const RUN = `${Date.now().toString(36)}${Math.floor(Math.random() * 1e4).toString(36)}`;

const PAYLOAD = {
  category: `X${RUN} "><svg onload=__x=3><b id=sxc>c</b>`,
  productA: `A${RUN} <script>__x=1</script><b id=sxa>a</b>`,
  productB: `B${RUN} <img src=x onerror=__x=2><b id=sxb>b</b>'"`,
  description: `<img src=x onerror=__x=5><b id=sxd>d</b>`,
  imagePath: `x"onerror="__x=4`,
  ingredient: `I${RUN} <img src=x onerror=__x=6><b id=sxi>i</b>`,
  unit: `g<b id=sxu>u</b>`,
  packLabel: `"><svg onload=__x=7>`,
  serviceTag: `<b id=sxt>t</b>`,
};
const PRODUCT_CATEGORY = 8;
const MARKERS = '#sxa,#sxb,#sxc,#sxd,#sxi,#sxu,#sxt,#sxr,#sxh,#sxe';

async function apiSession(email, password) {
  const ctx = await pwRequest.newContext();
  const res = await ctx.post(`${ADMIN}/admin/api/auth/login`, { data: { email, password } });
  expect(res.status()).toBe(200);
  const { data } = await res.json();
  return { ctx, csrf: data.csrf_token };
}

async function post(s, path, data) {
  const res = await s.ctx.post(`${ADMIN}${path}`, { headers: { 'X-CSRF-Token': s.csrf }, data });
  if (res.status() !== 201) throw new Error(`${path} : ${res.status()} ${await res.text()}`);
  return (await res.json()).data;
}

async function loginPage(page, email, password) {
  await page.goto(`${ADMIN}/login`);
  await page.fill('#email', email);
  await page.fill('#password', password);
  await page.locator('form[action="/login"] button[type="submit"]').click();
  await expect(page.locator('#userMenuBtn')).toBeVisible();
}

function watchCsp(page) {
  const violations = [];
  page.on('console', (msg) => {
    if (/Content Security Policy/i.test(msg.text())) violations.push(msg.text());
  });
  page.on('dialog', async (d) => { violations.push(`dialog: ${d.message()}`); await d.dismiss(); });
  return violations;
}

async function expectInert(page, where, violations) {
  const state = await page.evaluate((markers) => ({
    x: window.__x,
    markers: document.querySelectorAll(markers).length,
    handlers: [...document.querySelectorAll('*')]
      .filter((el) => [...el.attributes].some((a) => /^on/i.test(a.name))).map((el) => el.outerHTML.slice(0, 120)),
    // Scripts EXECUTABLES seulement. Deux ecrans recopient les noms dans un bloc de donnees
    // non execute : le JSON-LD de la borne (application/ld+json, seo.js::upsertJsonLd) et
    // le catalogue de la caisse (application/json, admin/counter/new.php, JSON_HEX_TAG).
    // Ces blocs sont verifies a part : aucun '<' brut, donc pas de sortie par </script>.
    scripts: [...document.scripts]
      .filter((s) => !/json/.test(s.type) && s.textContent.includes('__x')).length,
    jsonLdRawLt: [...document.querySelectorAll('script[type="application/ld+json"], script[type="application/json"]')]
      .filter((s) => s.textContent.includes('<')).length,
  }), MARKERS);
  expect(state.x, `${where} : aucun script execute`).toBeUndefined();
  expect(state.markers, `${where} : aucun element injecte`).toBe(0);
  expect(state.handlers, `${where} : aucun attribut on*`).toEqual([]);
  expect(state.scripts, `${where} : aucun <script> injecte`).toBe(0);
  expect(state.jsonLdRawLt, `${where} : blocs de donnees JSON sans '<' brut`).toBe(0);
  expect(violations, `${where} : aucune violation CSP`).toEqual([]);
}

test.describe.configure({ mode: 'serial' });

test.describe('XSS stockee et reflechie', () => {
  let admin;
  const ids = {};

  test.beforeAll(async () => {
    admin = await apiSession(ADMIN_EMAIL, ADMIN_PASSWORD);
    ids.category = (await post(admin, '/admin/api/categories', {
      name: PAYLOAD.category, slug: `sec-xss-${RUN}`, display_order: 65535,
    })).id;
    const product = (name, extra = {}) => post(admin, '/admin/api/products', {
      category_id: PRODUCT_CATEGORY, name, price_cents: 150, vat_rate: 100, is_available: true, display_order: 65535, ...extra,
    });
    ids.productA = (await product(PAYLOAD.productA, { description: PAYLOAD.description, image_path: PAYLOAD.imagePath })).id;
    ids.productB = (await product(PAYLOAD.productB)).id;
    ids.ingredient = (await post(admin, '/admin/api/ingredients', {
      name: PAYLOAD.ingredient, unit: PAYLOAD.unit, pack_size: 10, pack_label: PAYLOAD.packLabel,
      stock_capacity: 1000, low_stock_pct: 30, critical_stock_pct: 10,
    })).id;

    // Commande borne anonyme avec un chevalet piege, puis encaissee : elle part en cuisine.
    const kiosk = await pwRequest.newContext();
    const created = await kiosk.post(`${KIOSK}/api/orders`, {
      data: {
        idempotency_key: `sec-xss-${RUN}`, service_mode: 'dine_in', service_tag: PAYLOAD.serviceTag,
        items: [{ type: 'product', product_id: ids.productA, quantity: 1 }, { type: 'product', product_id: ids.productB, quantity: 1 }],
      },
    });
    expect(created.status(), await created.text()).toBe(201);
    ids.order = (await created.json()).data.order_number;
    expect((await kiosk.post(`${KIOSK}/api/orders/${ids.order}/pay`)).status()).toBe(200);
    await kiosk.dispose();
  });

  test.afterAll(async () => {
    if (!admin) return;
    for (const [id, name] of [[ids.productA, PAYLOAD.productA], [ids.productB, PAYLOAD.productB]]) {
      if (!id) continue;
      await admin.ctx.put(`${ADMIN}/admin/api/products/${id}`, {
        headers: { 'X-CSRF-Token': admin.csrf },
        data: { category_id: PRODUCT_CATEGORY, name, price_cents: 150, vat_rate: 100, is_available: false, display_order: 65535 },
      });
    }
    if (ids.category) await admin.ctx.post(`${ADMIN}/admin/api/categories/${ids.category}/toggle`, { headers: { 'X-CSRF-Token': admin.csrf } });
    await admin.ctx.dispose();
  });

  test('l API renvoie les libelles tels quels (stockes comme du texte, ni filtres ni transformes)', async () => {
    const res = await admin.ctx.get(`${ADMIN}/admin/api/products/${ids.productA}`);
    const { data } = await res.json();
    expect(data.name).toBe(PAYLOAD.productA);
    expect(data.description).toBe(PAYLOAD.description);
    expect(res.headers()['content-type']).toContain('application/json');
    expect(res.headers()['x-content-type-options']).toBe('nosniff');
  });

  test('borne : grille des categories, grille des produits, modale d options, panneau de commande, recapitulatif de paiement', async ({ page }) => {
    const violations = watchCsp(page);
    await page.goto(`${KIOSK}/categories.html?mode=sur-place`);
    const card = page.locator(`a[href="products.html?category=${ids.category}"]`);
    await expect(card).toBeVisible();
    await expect(card).toContainText('<b id=sxc>c</b>');
    await expectInert(page, 'borne categories', violations);

    await page.goto(`${KIOSK}/products.html?category=${PRODUCT_CATEGORY}&mode=sur-place`);
    const productCard = page.locator('#products-grid a.product-card', { hasText: `A${RUN}` });
    await expect(productCard).toBeVisible();
    await expect(page.locator('#products-grid')).toContainText('<script>__x=1</script>');
    await expect(page.locator('#products-grid')).toContainText('<img src=x onerror=__x=2>');
    await expectInert(page, 'borne produits', violations);

    await productCard.click();
    await expect(page.locator('#po-title')).toHaveText(PAYLOAD.productA);
    await expectInert(page, 'borne modale produit', violations);
    await page.locator('#po-add').click();
    const panel = page.locator('[data-order-panel]');
    await expect(panel).toContainText(`A${RUN}`);
    await expectInert(page, 'borne panneau de commande', violations);

    await panel.locator('.order-panel__pay').click();
    await expect(page).toHaveURL(/payment\.html/);
    // Le recapitulatif de paiement n'affiche que le nombre d'articles et le total.
    await expect(page.locator('body')).toContainText('1,50');
    await expectInert(page, 'borne paiement', violations);
  });

  test('back-office : listes, fiches et ecran cuisine', async ({ page }) => {
    const violations = watchCsp(page);
    await loginPage(page, ADMIN_EMAIL, ADMIN_PASSWORD);
    const screens = [
      ['/admin/categories', '<b id=sxc>c</b>'],
      [`/admin/categories/${ids.category}/edit`, null],
      ['/admin/products', '<script>__x=1</script>'],
      ['/admin/products/by-category', '<img src=x onerror=__x=2>'],
      [`/admin/products/${ids.productA}/edit`, null],
      [`/admin/products/${ids.productA}/recipe`, '<script>__x=1</script>'],
      [`/admin/products/${ids.productA}/delete`, null],
      ['/admin/ingredients', '<img src=x onerror=__x=6>'],
      [`/admin/ingredients/${ids.ingredient}/edit`, null],
      [`/admin/ingredients/${ids.ingredient}/movements`, '<b id=sxi>i</b>'],
      [`/admin/ingredients/${ids.ingredient}/restock`, null],
      ['/admin/orders', '<b id=sxt>t</b>'],
      [`/admin/orders/${ids.order}/cancel`, null],
      ['/kitchen/display', '<script>__x=1</script>'],
      ['/counter/orders/new', null],
      ['/admin/dashboard', null],
      ['/admin/stats', null],
    ];
    for (const [path, literal] of screens) {
      const res = await page.goto(`${ADMIN}${path}`);
      expect(res.status(), path).toBe(200);
      if (literal) await expect(page.locator('body'), path).toContainText(literal);
      await expectInert(page, path, violations);
    }
    // Les champs de saisie gardent la valeur exacte (echappee dans l'attribut value).
    await page.goto(`${ADMIN}/admin/products/${ids.productA}/edit`);
    await expect(page.locator('input[name="name"]')).toHaveValue(PAYLOAD.productA);
    await page.goto(`${ADMIN}/admin/ingredients/${ids.ingredient}/edit`);
    await expect(page.locator('input[name="pack_label"]')).toHaveValue(PAYLOAD.packLabel);
  });

  test('XSS reflechie : parametres d URL repris dans une page (jeton de reinitialisation, surlignage comptoir)', async ({ page }) => {
    const violations = watchCsp(page);
    const reflected = encodeURIComponent('"><b id=sxr>r</b><svg onload=__x=8>');
    await page.goto(`${ADMIN}/reset_password?token=${reflected}`);
    await expectInert(page, '/reset_password?token=', violations);
    await expect(page.locator('input[name="token"]')).toHaveValue('"><b id=sxr>r</b><svg onload=__x=8>');

    await loginPage(page, 'comptoir@wakdo.local', 'WakdoComptoir2026!');
    await page.goto(`${ADMIN}/counter/orders?highlight=${reflected}`);
    await expectInert(page, '/counter/orders?highlight=', violations);

    // Une adresse inconnue n'est pas reprise brute dans la page 404.
    const res = await page.goto(`${ADMIN}/admin/${encodeURIComponent('<b id=sxh>h</b>')}`);
    expect(res.status()).toBe(404);
    await expectInert(page, 'page 404', violations);
  });

  test('message d erreur de formulaire qui reprend la saisie : valeur echappee', async ({ page }) => {
    const violations = watchCsp(page);
    await loginPage(page, ADMIN_EMAIL, ADMIN_PASSWORD);
    await page.goto(`${ADMIN}/admin/categories/new`);
    const hostile = `E${RUN} "><b id=sxe>e</b><svg onload=__x=9>`;
    await page.fill('input[name="name"]', hostile);
    // Slug invalide : le serveur refuse et re-affiche le formulaire avec la saisie.
    await page.fill('input[name="slug"]', 'SLUG INVALIDE');
    await page.evaluate(() => { const f = document.querySelector('input[name="slug"]').form; f.noValidate = true; });
    await page.locator('input[name="slug"]').evaluate((el) => el.removeAttribute('pattern'));
    await page.evaluate(() => document.querySelector('input[name="slug"]').form.submit());
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('input[name="name"]')).toHaveValue(hostile);
    await expect(page.locator('body')).toContainText('Référence requise');
    await expectInert(page, 'formulaire categorie en erreur', violations);
  });
});
