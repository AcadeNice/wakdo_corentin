// Tests de securite -- controle d'acces horizontal et vertical (OWASP Top 10 2021 A01
// Broken Access Control ; OWASP ASVS 4.0 chapitre V4 Access Control).
//
// Base existante, non dupliquee ici : tests/e2e/rbac-demo.spec.js (page d'arrivee et refus
// 403 par compte de demo), tests/e2e/rbac-channel.spec.js (pages /counter et /drive
// croisees), tests/Integration/RouteMatrixRoleDbTest.php (grille role x permission contre
// une vraie base) et src/app/Health/captured-responses.json (refus reels sans session et
// sans permission, route par route).
//
// Ce fichier ajoute ce qui manquait :
//  - horizontal par l'API JSON ET par le HTML : une commande drive n'est ni lue ni modifiee
//    par le comptoir, et l'inverse ; l'etat de la commande est relu apres chaque refus ;
//  - cuisine : seul "prete" passe, toute autre ecriture est refusee ;
//  - responsable : pas de creation de compte (HTML + API) ;
//  - un compte ou un role desactive perd l'acces sur une session DEJA ouverte ;
//  - un changement de role d'un compte connecte (constat, test marque) ;
//  - pages d'administration sans session -> /login ;
//  - methodes non prevues -> 405 sans effet (etat relu).
//
// Serial : le beforeAll provisionne des comptes jetables (emails uniques par execution) via
// l'API d'administration, avec le PIN de l'admin de demo pose par /admin/profile/pin (meme
// PIN que tests/e2e/rbac-channel.spec.js). Aucun compte de demo n'est modifie.
const { test, expect, request: pwRequest } = require('@playwright/test');

const ADMIN = 'http://admin.wakdo.test';
const KIOSK = 'http://kiosk.wakdo.test';
const ADMIN_EMAIL = 'admin@wakdo.local';
const ADMIN_PASSWORD = 'WakdoAdmin2026!';
const ADMIN_PIN = '4729';
const DEMO = {
  comptoir: ['comptoir@wakdo.local', 'WakdoComptoir2026!'],
  drive: ['drive@wakdo.local', 'WakdoDrive2026!'],
  cuisine: ['cuisine@wakdo.local', 'WakdoCuisine2026!'],
  manager: ['manager@wakdo.local', 'WakdoManager2026!'],
};
const RUN = `${Date.now().toString(36)}${Math.floor(Math.random() * 1e6).toString(36)}`;
const TEMP_PASSWORD = 'SecAccess2026!x';

async function apiSession(email, password) {
  const ctx = await pwRequest.newContext();
  const res = await ctx.post(`${ADMIN}/admin/api/auth/login`, { data: { email, password } });
  if (res.status() !== 200) throw new Error(`connexion ${email} : ${res.status()} ${await res.text()}`);
  const { data } = await res.json();
  return { ctx, csrf: data.csrf_token, user: data.user };
}

function withCsrf(s, extra = {}) {
  return { 'X-CSRF-Token': s.csrf, ...extra };
}

async function ensurePin(s, password, pin) {
  const res = await s.ctx.post(`${ADMIN}/admin/profile/pin`, {
    form: { _csrf: s.csrf, current_password: password, pin, pin_confirm: pin },
    maxRedirects: 0,
  });
  expect(res.status(), 'PIN pose').toBe(302);
}

async function roleIds(admin) {
  const res = await admin.ctx.get(`${ADMIN}/admin/api/roles`);
  const { data } = await res.json();
  return Object.fromEntries(data.map((r) => [r.code, r.id]));
}

async function createUser(admin, roleId, label) {
  const email = `sec-${label}-${RUN}@wakdo.local`;
  const res = await admin.ctx.post(`${ADMIN}/admin/api/users`, {
    headers: withCsrf(admin),
    data: { email, first_name: 'Sec', last_name: label, role_id: roleId, password: TEMP_PASSWORD, pin_email: ADMIN_EMAIL, pin: ADMIN_PIN },
  });
  if (res.status() !== 201) throw new Error(`creation ${email} : ${res.status()} ${await res.text()}`);
  const { data } = await res.json();
  return { id: data.id, email };
}

async function orderableProductId() {
  const ctx = await pwRequest.newContext();
  const { data } = await (await ctx.get(`${KIOSK}/api/products`)).json();
  await ctx.dispose();
  const p = data.find((x) => x.is_orderable && x.price_cents > 0);
  if (!p) throw new Error('aucun produit commandable');
  return p.id;
}

async function staffOrder(s, productId, serviceMode = 'takeaway') {
  const res = await s.ctx.post(`${ADMIN}/admin/api/orders`, {
    headers: withCsrf(s),
    data: { service_mode: serviceMode, items: [{ type: 'product', product_id: productId, quantity: 1 }] },
  });
  if (res.status() !== 201) throw new Error(`commande : ${res.status()} ${await res.text()}`);
  return (await res.json()).data;
}

async function orderStatus(s, number) {
  const res = await s.ctx.get(`${ADMIN}/admin/api/orders/${number}`);
  expect(res.status()).toBe(200);
  return (await res.json()).data.status;
}

test.describe.configure({ mode: 'serial' });

test.describe('Controle d acces horizontal et vertical', () => {
  let admin;
  let roles;
  let productId;
  let counterOrder;
  let driveOrder;
  const sessions = {};

  test.beforeAll(async () => {
    admin = await apiSession(ADMIN_EMAIL, ADMIN_PASSWORD);
    await ensurePin(admin, ADMIN_PASSWORD, ADMIN_PIN);
    roles = await roleIds(admin);
    productId = await orderableProductId();
    for (const [key, [email, password]] of Object.entries(DEMO)) {
      sessions[key] = await apiSession(email, password);
    }
    counterOrder = await staffOrder(sessions.comptoir, productId);
    driveOrder = await staffOrder(sessions.drive, productId, 'drive');
    expect(counterOrder.order_number).toMatch(/^C\d+$/);
    expect(driveOrder.order_number).toMatch(/^D\d+$/);
  });

  test.afterAll(async () => {
    for (const s of Object.values(sessions)) await s.ctx.dispose();
    if (admin) await admin.ctx.dispose();
  });

  test('comptoir : ne lit ni ne modifie une commande drive (API)', async () => {
    const s = sessions.comptoir;
    const n = driveOrder.order_number;
    const before = await orderStatus(admin, n);

    expect((await s.ctx.get(`${ADMIN}/admin/api/orders/${n}`)).status()).toBe(403);
    for (const action of ['ready', 'deliver']) {
      expect((await s.ctx.post(`${ADMIN}/admin/api/orders/${n}/${action}`, { headers: withCsrf(s) })).status(), action).toBe(403);
    }
    // L'annulation est refusee AVANT le PIN : meme un PIN juste ne change rien.
    const cancel = await s.ctx.post(`${ADMIN}/admin/api/orders/${n}/cancel`, {
      headers: withCsrf(s), data: { pin_email: DEMO.comptoir[0], pin: '3030' },
    });
    expect(cancel.status()).toBe(403);

    const list = await (await s.ctx.get(`${ADMIN}/admin/api/orders`)).text();
    expect(list).not.toContain(`"${n}"`);
    expect(await orderStatus(admin, n)).toBe(before);
  });

  test('drive : ne lit ni ne modifie une commande comptoir (API)', async () => {
    const s = sessions.drive;
    const n = counterOrder.order_number;
    const before = await orderStatus(admin, n);

    expect((await s.ctx.get(`${ADMIN}/admin/api/orders/${n}`)).status()).toBe(403);
    for (const action of ['ready', 'deliver']) {
      expect((await s.ctx.post(`${ADMIN}/admin/api/orders/${n}/${action}`, { headers: withCsrf(s) })).status(), action).toBe(403);
    }
    const cancel = await s.ctx.post(`${ADMIN}/admin/api/orders/${n}/cancel`, {
      headers: withCsrf(s), data: { pin_email: DEMO.drive[0], pin: '4040' },
    });
    expect(cancel.status()).toBe(403);
    const list = await (await s.ctx.get(`${ADMIN}/admin/api/orders`)).text();
    expect(list).not.toContain(`"${n}"`);
    expect(await orderStatus(admin, n)).toBe(before);
  });

  test('comptoir et drive : memes refus par les pages HTML (liste, confirmation, envoi)', async () => {
    const cases = [
      [sessions.comptoir, driveOrder.order_number],
      [sessions.drive, counterOrder.order_number],
    ];
    for (const [s, n] of cases) {
      const before = await orderStatus(admin, n);
      const listHtml = await (await s.ctx.get(`${ADMIN}/admin/orders`)).text();
      expect(listHtml).not.toContain(`>${n}<`);
      expect((await s.ctx.get(`${ADMIN}/admin/orders/${n}/cancel`, { maxRedirects: 0 })).status()).toBe(403);
      for (const action of ['ready', 'deliver', 'cancel']) {
        const res = await s.ctx.post(`${ADMIN}/admin/orders/${n}/${action}`, { form: { _csrf: s.csrf }, maxRedirects: 0 });
        expect(res.status(), `${n} ${action}`).toBe(403);
      }
      expect(await orderStatus(admin, n)).toBe(before);
    }
  });

  test('cuisine : peut marquer une commande prete, rien d autre en ecriture', async () => {
    const s = sessions.cuisine;
    const n = counterOrder.order_number;

    const denied = [
      ['POST', `/admin/api/orders/${n}/deliver`, {}],
      ['POST', `/admin/api/orders/${n}/cancel`, { pin_email: DEMO.cuisine[0], pin: '2020' }],
      ['POST', '/admin/api/orders', { service_mode: 'takeaway', items: [{ type: 'product', product_id: productId, quantity: 1 }] }],
      ['POST', '/admin/api/categories', { name: `Sec ${RUN}`, slug: `sec-${RUN}` }],
      ['PUT', `/admin/api/products/${productId}`, { name: 'x' }],
      ['POST', '/admin/api/products', { name: 'x' }],
      ['POST', '/admin/api/menus', { name: 'x' }],
      ['POST', '/admin/api/ingredients', { name: 'x' }],
      ['POST', '/admin/api/ingredients/1/restock', { packs: 1 }],
      ['PUT', '/admin/api/ingredients/1/thresholds', { stock_capacity: 1 }],
      ['POST', '/admin/api/users', { email: 'x@wakdo.local' }],
      ['POST', '/admin/api/roles', { code: 'x' }],
    ];
    for (const [method, path, data] of denied) {
      const res = await s.ctx.fetch(`${ADMIN}${path}`, { method, headers: withCsrf(s), data });
      expect(res.status(), `${method} ${path}`).toBe(403);
    }
    // HTML : meme refus sur un formulaire d'ecriture du catalogue.
    const html = await s.ctx.post(`${ADMIN}/admin/categories`, { form: { _csrf: s.csrf, name: 'x', slug: 'x' }, maxRedirects: 0 });
    expect(html.status()).toBe(403);

    // La seule ecriture de commande autorisee : "prete".
    const ready = await s.ctx.post(`${ADMIN}/admin/api/orders/${n}/ready`, { headers: withCsrf(s) });
    expect(ready.status()).toBe(200);
    expect(await orderStatus(admin, n)).toBe('ready');
  });

  test('responsable : ne cree pas de compte (page, formulaire, API) ni de role', async () => {
    const s = sessions.manager;
    expect((await s.ctx.get(`${ADMIN}/admin/users/new`, { maxRedirects: 0 })).status()).toBe(403);
    const form = await s.ctx.post(`${ADMIN}/admin/users`, {
      form: { _csrf: s.csrf, email: `sec-mgr-${RUN}@wakdo.local`, first_name: 'a', last_name: 'b', role_id: String(roles.admin), password: TEMP_PASSWORD },
      maxRedirects: 0,
    });
    expect(form.status()).toBe(403);
    const api = await s.ctx.post(`${ADMIN}/admin/api/users`, {
      headers: withCsrf(s),
      data: { email: `sec-mgr-${RUN}@wakdo.local`, first_name: 'a', last_name: 'b', role_id: roles.admin, password: TEMP_PASSWORD, pin_email: DEMO.manager[0], pin: '1010' },
    });
    expect(api.status()).toBe(403);
    const role = await s.ctx.post(`${ADMIN}/admin/api/roles`, { headers: withCsrf(s), data: { code: `sec_${RUN}` } });
    expect(role.status()).toBe(403);
    // Le compte n'existe pas apres coup.
    const users = await (await admin.ctx.get(`${ADMIN}/admin/api/users`)).text();
    expect(users).not.toContain(`sec-mgr-${RUN}@wakdo.local`);
  });

  test('un compte desactive perd l acces des la requete suivante, sur une session deja ouverte', async () => {
    const u = await createUser(admin, roles.counter, 'deact');
    const s = await apiSession(u.email, TEMP_PASSWORD);
    expect((await s.ctx.get(`${ADMIN}/counter/orders`, { maxRedirects: 0 })).status()).toBe(200);

    const off = await admin.ctx.delete(`${ADMIN}/admin/api/users/${u.id}`, {
      headers: withCsrf(admin), data: { pin_email: ADMIN_EMAIL, pin: ADMIN_PIN },
    });
    expect(off.status()).toBe(200);

    const page = await s.ctx.get(`${ADMIN}/counter/orders`, { maxRedirects: 0 });
    expect(page.status()).toBe(302);
    expect(page.headers()['location']).toBe('/login');
    expect((await s.ctx.get(`${ADMIN}/admin/api/auth/me`)).status()).toBe(401);
    // Et il ne peut pas se reconnecter.
    const relog = await s.ctx.post(`${ADMIN}/admin/api/auth/login`, { data: { email: u.email, password: TEMP_PASSWORD } });
    expect(relog.status()).toBe(401);
    await s.ctx.dispose();
  });

  test('un role desactive perd ses droits sur une session deja ouverte', async () => {
    const managerRole = await (await admin.ctx.get(`${ADMIN}/admin/api/roles/${roles.manager}`)).json();
    const code = `sec_${RUN}`.slice(0, 40);
    const body = {
      code,
      label: `Sec ${RUN}`,
      description: 'Role jetable des tests de securite',
      default_route: '/admin/stats',
      order_source: '',
      permission_ids: managerRole.data.permission_ids,
      visible_sources: [],
      pin_email: ADMIN_EMAIL,
      pin: ADMIN_PIN,
    };
    const created = await admin.ctx.post(`${ADMIN}/admin/api/roles`, { headers: withCsrf(admin), data: body });
    expect(created.status(), await created.text()).toBe(201);
    const roleId = (await created.json()).data.id;

    const u = await createUser(admin, roleId, 'role');
    const s = await apiSession(u.email, TEMP_PASSWORD);
    expect((await s.ctx.get(`${ADMIN}/admin/stats`, { maxRedirects: 0 })).status()).toBe(200);

    const off = await admin.ctx.put(`${ADMIN}/admin/api/roles/${roleId}`, { headers: withCsrf(admin), data: { ...body, is_active: false } });
    expect(off.status(), await off.text()).toBe(200);

    expect((await s.ctx.get(`${ADMIN}/admin/stats`, { maxRedirects: 0 })).status()).toBe(403);
    expect((await s.ctx.get(`${ADMIN}/admin/api/stats`)).status()).toBe(403);
    await s.ctx.dispose();
  });

  test('changement de role d un compte connecte : les droits de l ancien role cessent sans reconnexion', async () => {
    const u = await createUser(admin, roles.manager, 'demote');
    const s = await apiSession(u.email, TEMP_PASSWORD);
    expect((await s.ctx.get(`${ADMIN}/admin/stats`, { maxRedirects: 0 })).status()).toBe(200);

    const demote = await admin.ctx.put(`${ADMIN}/admin/api/users/${u.id}`, {
      headers: withCsrf(admin),
      data: { email: u.email, first_name: 'Sec', last_name: 'demote', role_id: roles.kitchen, pin_email: ADMIN_EMAIL, pin: ADMIN_PIN },
    });
    expect(demote.status(), await demote.text()).toBe(200);
    try {
      // Le role cuisine n'a pas stats.read : la page doit etre refusee.
      expect((await s.ctx.get(`${ADMIN}/admin/stats`, { maxRedirects: 0 })).status()).toBe(403);
    } finally {
      await s.ctx.dispose();
    }
  });

  test('pages d administration sans session : redirection vers /login, aucun contenu', async () => {
    const paths = [
      '/admin/dashboard', '/admin/stats', '/admin/health', '/admin/orders', '/admin/orders/K1/cancel',
      '/kitchen/display', '/counter/orders', '/counter/orders/new', '/drive/orders', '/drive/orders/new',
      '/admin/users', '/admin/users/new', '/admin/users/1/edit', '/admin/users/1/deactivate', '/admin/users/1/reset-pin', '/admin/users/1/erase',
      '/admin/roles', '/admin/roles/new', '/admin/roles/1/edit',
      '/admin/categories', '/admin/categories/new', '/admin/categories/1/edit',
      '/admin/profile/pin', '/admin/privacy',
      '/admin/products', '/admin/products/by-category', '/admin/products/new', '/admin/products/1/edit', '/admin/products/1/delete', '/admin/products/1/recipe',
      '/admin/products/import', '/admin/products/import/template',
      '/admin/menus', '/admin/menus/new', '/admin/menus/1/edit', '/admin/menus/1/delete',
      '/admin/ingredients', '/admin/ingredients/new', '/admin/ingredients/1/edit', '/admin/ingredients/1/delete',
      '/admin/ingredients/1/restock', '/admin/ingredients/1/inventory', '/admin/ingredients/1/adjust', '/admin/ingredients/1/movements',
    ];
    const anon = await pwRequest.newContext();
    for (const path of paths) {
      const res = await anon.get(`${ADMIN}${path}`, { maxRedirects: 0 });
      expect(res.status(), path).toBe(302);
      expect(res.headers()['location'], path).toBe('/login');
      expect(await res.text(), path).toBe('');
    }
    // /admin/me (JSON) : 401, pas de redirection.
    expect((await anon.get(`${ADMIN}/admin/me`)).status()).toBe(401);
    await anon.dispose();
  });

  test('methodes non prevues : 405, sans effet sur la ressource', async () => {
    const before = await (await admin.ctx.get(`${ADMIN}/admin/api/categories/1`)).text();
    const cases = [
      ['PUT', '/admin/categories/1'],
      ['DELETE', '/admin/categories/1'],
      ['PATCH', '/admin/api/categories/1'],
      ['DELETE', '/admin/api/roles/1'],
      ['PUT', '/admin/api/orders'],
      ['DELETE', '/admin/products/1'],
      ['PATCH', '/admin/users/1'],
    ];
    for (const [method, path] of cases) {
      const res = await admin.ctx.fetch(`${ADMIN}${path}`, {
        method, headers: withCsrf(admin), form: { _csrf: admin.csrf, name: 'Modifie', is_active: '0' }, maxRedirects: 0,
      });
      expect(res.status(), `${method} ${path}`).toBe(405);
    }
    const anon = await pwRequest.newContext();
    for (const [method, path] of [['PUT', '/api/orders'], ['DELETE', '/api/products/1'], ['PATCH', '/api/menus/1']]) {
      expect((await anon.fetch(`${KIOSK}${path}`, { method })).status(), `${method} ${path}`).toBe(405);
    }
    await anon.dispose();
    expect(await (await admin.ctx.get(`${ADMIN}/admin/api/categories/1`)).text()).toBe(before);
  });

  test('methode TRACE refusee par Apache sur les deux hotes', async () => {
    const anon = await pwRequest.newContext();
    const results = [];
    for (const url of [`${KIOSK}/`, `${ADMIN}/api/categories`]) {
      results.push([url, (await anon.fetch(url, { method: 'TRACE' })).status()]);
    }
    for (const [url, status] of results) {
      expect(status, url).toBe(405);
    }
    await anon.dispose();
  });
});
