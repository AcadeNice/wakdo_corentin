// Tests de securite -- affectation de masse (RG-T16). OWASP Top 10 2021 A01 Broken Access
// Control / A04 Insecure Design ; OWASP ASVS 4.0 V5.1.2 (protection contre l'affectation
// de masse).
//
// Chaque controleur ne lit qu'une liste FERMEE de champs (scalarForm / fieldString /
// fieldInt de App\Controllers\Admin\Api\JsonApiTrait, validate() des controleurs HTML).
// Ce fichier envoie, en plus des champs attendus, des champs que la route n'autorise pas
// (id, is_active, role_id, price_cents, stock_quantity, password_hash, pin_hash, status,
// source, total) et relit ensuite l'enregistrement pour prouver qu'ils ont ete ignores.
const { test, expect, request: pwRequest } = require('@playwright/test');

const ADMIN = 'http://admin.wakdo.test';
const KIOSK = 'http://kiosk.wakdo.test';
const ADMIN_EMAIL = 'admin@wakdo.local';
const ADMIN_PASSWORD = 'WakdoAdmin2026!';
const ADMIN_PIN = '4729';
const RUN = `${Date.now().toString(36)}${Math.floor(Math.random() * 1e6).toString(36)}`;
const TEMP_PASSWORD = 'SecMass2026!x';
// Hash argon2id d'un AUTRE mot de passe, que l'attaquant voudrait imposer.
const FOREIGN_HASH = '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHRzb21lc2FsdA$Wm9vYmFyYmF6cXV4cXV1eHF1dXhxdXV4cXV1eA';

let admin;
let roles;

async function apiSession(email, password) {
  const ctx = await pwRequest.newContext();
  const res = await ctx.post(`${ADMIN}/admin/api/auth/login`, { data: { email, password } });
  if (res.status() !== 200) throw new Error(`connexion ${email} : ${res.status()}`);
  const { data } = await res.json();
  return { ctx, csrf: data.csrf_token };
}

async function call(s, method, path, data) {
  return s.ctx.fetch(`${ADMIN}${path}`, { method, headers: { 'X-CSRF-Token': s.csrf }, data });
}

async function read(s, path) {
  return (await (await s.ctx.get(`${ADMIN}${path}`)).json()).data;
}

test.describe.configure({ mode: 'serial' });

test.describe('Affectation de masse : champs non prevus ignores', () => {
  test.beforeAll(async () => {
    admin = await apiSession(ADMIN_EMAIL, ADMIN_PASSWORD);
    const pin = await admin.ctx.post(`${ADMIN}/admin/profile/pin`, {
      form: { _csrf: admin.csrf, current_password: ADMIN_PASSWORD, pin: ADMIN_PIN, pin_confirm: ADMIN_PIN }, maxRedirects: 0,
    });
    expect(pin.status()).toBe(302);
    const list = await read(admin, '/admin/api/roles');
    roles = Object.fromEntries(list.map((r) => [r.code, r.id]));
  });

  test.afterAll(async () => {
    if (admin) await admin.ctx.dispose();
  });

  test('categorie : id et is_active du corps ignores a la creation', async () => {
    const res = await call(admin, 'POST', '/admin/api/categories', {
      name: `Ma ${RUN}`, slug: `sec-ma-${RUN}`, display_order: 65535, id: 999999, is_active: false, created_at: '2000-01-01',
    });
    expect(res.status()).toBe(201);
    const { data } = await res.json();
    expect(data.id).not.toBe(999999);
    expect(data.is_active).toBe(true);
    expect((await admin.ctx.get(`${ADMIN}/admin/api/categories/999999`)).status()).toBe(404);
    await call(admin, 'POST', `/admin/api/categories/${data.id}/toggle`, {});
  });

  test('produit : id impose ignore ; prix non modifiable par la route de deplacement', async () => {
    const res = await call(admin, 'POST', '/admin/api/products', {
      category_id: 8, name: `Ma ${RUN}`, price_cents: 250, vat_rate: 100, is_available: false, display_order: 65535, id: 999998, is_orderable: true,
    });
    expect(res.status()).toBe(201);
    const product = (await res.json()).data;
    expect(product.id).not.toBe(999998);

    const move = await call(admin, 'POST', `/admin/api/products/${product.id}/move`, { direction: 'up', price_cents: 1, vat_rate: 55, name: 'renomme' });
    expect(move.status()).toBeLessThan(500);
    const after = await read(admin, `/admin/api/products/${product.id}`);
    expect(after.price_cents).toBe(250);
    expect(after.vat_rate).toBe(100);
    expect(after.name).toBe(`Ma ${RUN}`);
  });

  test('ingredient : ni les seuils ni le reapprovisionnement n ecrivent stock_quantity directement', async () => {
    const created = await call(admin, 'POST', '/admin/api/ingredients', {
      name: `Ma ${RUN}`, unit: 'g', pack_size: 10, stock_capacity: 1000, low_stock_pct: 30, critical_stock_pct: 10, stock_quantity: 999999, id: 999997,
    });
    expect(created.status(), await created.text()).toBe(201);
    const ing = (await created.json()).data;
    expect(ing.id).not.toBe(999997);
    expect(ing.stock_quantity).not.toBe(999999);
    const start = ing.stock_quantity;

    const th = await call(admin, 'PUT', `/admin/api/ingredients/${ing.id}/thresholds`, {
      stock_capacity: 1000, low_stock_pct: 30, critical_stock_pct: 10, stock_quantity: 999999, is_active: false,
    });
    expect(th.status(), await th.text()).toBe(200);
    expect((await read(admin, `/admin/api/ingredients/${ing.id}`)).stock_quantity).toBe(start);

    const restock = await call(admin, 'POST', `/admin/api/ingredients/${ing.id}/restock`, { packs: 1, stock_quantity: 999999, pack_size: 5000 });
    expect(restock.status(), await restock.text()).toBe(200);
    // +1 pack de 10 (valeur en base), ni le stock ni la taille de pack imposes par le corps.
    expect((await read(admin, `/admin/api/ingredients/${ing.id}`)).stock_quantity).toBe(start + 10);
  });

  test('compte : password_hash, pin_hash et is_active du corps ignores a la creation et a la modification', async () => {
    const email = `sec-ma-${RUN}@wakdo.local`;
    const res = await call(admin, 'POST', '/admin/api/users', {
      email, first_name: 'Sec', last_name: 'Mass', role_id: roles.counter, password: TEMP_PASSWORD,
      password_hash: FOREIGN_HASH, pin_hash: FOREIGN_HASH, is_active: false, id: 999996, failed_login_attempts: 0,
      pin_email: ADMIN_EMAIL, pin: ADMIN_PIN,
    });
    expect(res.status(), await res.text()).toBe(201);
    const user = (await res.json()).data;
    expect(user.id).not.toBe(999996);
    expect(user.is_active).toBe(true);
    // Le mot de passe effectif est celui du champ "password", hache par le serveur.
    const probe = await pwRequest.newContext();
    expect((await probe.post(`${ADMIN}/admin/api/auth/login`, { data: { email, password: TEMP_PASSWORD } })).status()).toBe(200);
    await probe.dispose();

    const upd = await call(admin, 'PUT', `/admin/api/users/${user.id}`, {
      email, first_name: 'Sec', last_name: 'Mass', role_id: roles.counter, password_hash: FOREIGN_HASH, pin_hash: FOREIGN_HASH,
      pin_email: ADMIN_EMAIL, pin: ADMIN_PIN,
    });
    expect(upd.status(), await upd.text()).toBe(200);
    const again = await pwRequest.newContext();
    expect((await again.post(`${ADMIN}/admin/api/auth/login`, { data: { email, password: TEMP_PASSWORD } })).status()).toBe(200);
    await again.dispose();
  });

  test('profil : un equipier ne change ni son role ni son etat par le formulaire de PIN', async () => {
    const email = `sec-ma2-${RUN}@wakdo.local`;
    const res = await call(admin, 'POST', '/admin/api/users', {
      email, first_name: 'Sec', last_name: 'Self', role_id: roles.counter, password: TEMP_PASSWORD, pin_email: ADMIN_EMAIL, pin: ADMIN_PIN,
    });
    expect(res.status()).toBe(201);
    const id = (await res.json()).data.id;

    const me = await apiSession(email, TEMP_PASSWORD);
    const post = await me.ctx.post(`${ADMIN}/admin/profile/pin`, {
      form: {
        _csrf: me.csrf, current_password: TEMP_PASSWORD, pin: '5791', pin_confirm: '5791',
        role_id: String(roles.admin), is_active: '1', email: ADMIN_EMAIL, user_id: '1', password_hash: FOREIGN_HASH,
      },
      maxRedirects: 0,
    });
    expect(post.status()).toBe(302);
    const row = await read(admin, `/admin/api/users/${id}`);
    expect(row.role_id).toBe(roles.counter);
    expect(row.email).toBe(email);
    // Aucun droit gagne, meme apres reconnexion.
    await me.ctx.dispose();
    const fresh = await apiSession(email, TEMP_PASSWORD);
    expect((await fresh.ctx.get(`${ADMIN}/admin/users`, { maxRedirects: 0 })).status()).toBe(403);
    await fresh.ctx.dispose();
    // Le compte admin n'a pas ete touche.
    expect((await read(admin, '/admin/api/users/1')).email).toBe(ADMIN_EMAIL);
  });

  test('commande borne : statut, canal, total et numero du corps ignores', async () => {
    const kiosk = await pwRequest.newContext();
    const { data: products } = await (await kiosk.get(`${KIOSK}/api/products`)).json();
    const target = products.find((p) => p.is_orderable);
    const res = await kiosk.post(`${KIOSK}/api/orders`, {
      data: {
        service_mode: 'takeaway', items: [{ type: 'product', product_id: target.id, quantity: 1, price_cents: 1, unit_price_cents: 1, label: 'x' }],
        status: 'delivered', source: 'counter', total_ttc_cents: 1, order_number: 'C1', id: 1, acting_user_id: 1, paid_at: '2000-01-01',
      },
    });
    expect(res.status()).toBe(201);
    const { data } = await res.json();
    expect(data.status).toBe('pending_payment');
    expect(data.order_number).toMatch(/^K\d+$/);
    expect(data.total_ttc_cents).toBe(target.price_cents);
    await kiosk.dispose();
  });

  test('commande equipier : un role a canal fixe ne choisit pas son canal par le corps', async () => {
    const s = await apiSession('comptoir@wakdo.local', 'WakdoComptoir2026!');
    const kiosk = await pwRequest.newContext();
    const { data: products } = await (await kiosk.get(`${KIOSK}/api/products`)).json();
    await kiosk.dispose();
    const res = await call(s, 'POST', '/admin/api/orders', {
      service_mode: 'takeaway', source: 'drive', status: 'delivered', items: [{ type: 'product', product_id: products.find((p) => p.is_orderable).id, quantity: 1 }],
    });
    expect(res.status()).toBe(201);
    const { data } = await res.json();
    expect(data.source).toBe('counter');
    expect(data.order_number).toMatch(/^C\d+$/);
    expect(data.status).not.toBe('delivered');
    await s.ctx.dispose();
  });
});
