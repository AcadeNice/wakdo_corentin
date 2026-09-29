// Tests de securite -- injection SQL. OWASP Top 10 2021 A03 Injection ; OWASP ASVS 4.0
// V5.3.4 (requetes parametrees).
//
// Le code n'assemble aucune requete par concatenation de saisie : App\Core\Database passe
// par des requetes preparees PDO (RG-T06), et les identifiants de chemin sont convertis en
// entier avant d'atteindre la base. Ce fichier le verifie de l'exterieur, sans lire le code :
// des charges classiques (guillemet + OR 1=1, UNION, commentaire, point-virgule, SLEEP)
// sont envoyees dans les parametres de chemin, de requete et de corps, sur l'API publique
// de la borne et sur l'API d'administration. Attendu a chaque fois :
//  - un refus propre (4xx) ou un traitement comme texte / comme entier, jamais un 500 ;
//  - aucune trace SQL dans la reponse (SQLSTATE, "syntax", nom du moteur, PDO) ;
//  - pas de delai : une charge SLEEP(3) executee se verrait au chronometre ;
//  - rien de modifie : les compteurs relus avant / apres ne bougent que de ce que le test
//    a cree lui-meme, et un libelle hostile est relu a l'identique (stocke comme du texte).
//
// Pas de sqlmap ni de scanner : les charges sont ecrites a la main, la liste est bornee.
const { test, expect, request: pwRequest } = require('@playwright/test');

const ADMIN = 'http://admin.wakdo.test';
const KIOSK = 'http://kiosk.wakdo.test';
const ADMIN_EMAIL = 'admin@wakdo.local';
const ADMIN_PASSWORD = 'WakdoAdmin2026!';
const RUN = `${Date.now().toString(36)}${Math.floor(Math.random() * 1e4).toString(36)}`;

const PAYLOADS = [
  "1' OR '1'='1",
  "1' OR 1=1 -- ",
  '1 OR 1=1',
  "1 UNION SELECT 1,2,3,4,5,6,7,8,9,10 -- ",
  "1' UNION SELECT password_hash FROM user -- ",
  '1/**/OR/**/1=1',
  '1; DROP TABLE product; --',
  '1 AND SLEEP(3)',
  "1' AND SLEEP(3) AND '1'='1",
  "' OR ''='",
  '-1',
  '0x31',
  '%00',
];
const SQL_TRACE = /SQLSTATE|syntax|MariaDB|MySQL|PDO|near '|mysqli|SELECT .* FROM/i;
const SLEEP_BUDGET_MS = 2500;

async function apiSession(email, password) {
  const ctx = await pwRequest.newContext();
  const res = await ctx.post(`${ADMIN}/admin/api/auth/login`, { data: { email, password } });
  expect(res.status()).toBe(200);
  const { data } = await res.json();
  return { ctx, csrf: data.csrf_token };
}

async function timed(fn) {
  const t0 = Date.now();
  const res = await fn();
  return { res, ms: Date.now() - t0, body: await res.text() };
}

function expectClean(where, { res, ms, body }) {
  expect(res.status(), `${where} : pas de 500`).toBeLessThan(500);
  expect(body, `${where} : aucune trace SQL`).not.toMatch(SQL_TRACE);
  expect(ms, `${where} : aucun delai (SLEEP non execute)`).toBeLessThan(SLEEP_BUDGET_MS);
}

async function total(ctx, path) {
  const { data } = await (await ctx.get(`${ADMIN}${path}`)).json();
  return data.length;
}

test.describe('Injection SQL', () => {
  test('API publique : parametres de chemin (produit, menu, commande)', async () => {
    const ctx = await pwRequest.newContext();
    const product1 = await (await ctx.get(`${KIOSK}/api/products/1`)).text();
    const menu1 = await (await ctx.get(`${KIOSK}/api/menus/1`)).text();
    for (const p of PAYLOADS) {
      const enc = encodeURIComponent(p);
      const prod = await timed(() => ctx.get(`${KIOSK}/api/products/${enc}`));
      expectClean(`/api/products/${p}`, prod);
      // Traitement comme ENTIER : soit introuvable, soit exactement le produit 1 -- jamais
      // une liste ni un autre enregistrement.
      if (prod.res.status() === 200) expect(prod.body, p).toBe(product1);
      else expect(prod.res.status(), p).toBe(404);

      const menu = await timed(() => ctx.get(`${KIOSK}/api/menus/${enc}`));
      expectClean(`/api/menus/${p}`, menu);
      if (menu.res.status() === 200) expect(menu.body, p).toBe(menu1);
      else expect(menu.res.status(), p).toBe(404);

      const order = await timed(() => ctx.get(`${KIOSK}/api/orders/K${enc}`));
      expectClean(`/api/orders/K${p}`, order);
      expect(order.res.status(), p).toBe(404);

      const pay = await timed(() => ctx.post(`${KIOSK}/api/orders/K${enc}/pay`));
      expectClean(`/api/orders/K${p}/pay`, pay);
      expect(pay.res.status(), p).toBe(404);
    }
    await ctx.dispose();
  });

  test('API publique : parametres de requete ignores ou traites comme texte', async () => {
    const ctx = await pwRequest.newContext();
    const reference = await (await ctx.get(`${KIOSK}/api/products`)).text();
    for (const p of PAYLOADS) {
      const enc = encodeURIComponent(p);
      for (const path of [`/api/products?category_id=${enc}`, `/api/products?id=${enc}&sort=${enc}`, `/api/categories?q=${enc}`, `/api/menus?category=${enc}`]) {
        const r = await timed(() => ctx.get(`${KIOSK}${path}`));
        expectClean(path, r);
        expect(r.res.status(), path).toBe(200);
      }
      // Le catalogue rendu est le meme qu'sans parametre : aucun filtre n'a ete "ouvert".
      expect(await (await ctx.get(`${KIOSK}/api/products?category_id=${enc}`)).text()).toBe(reference);
    }
    await ctx.dispose();
  });

  test('API publique : corps de commande (identifiants, cle d idempotence, chevalet)', async () => {
    const ctx = await pwRequest.newContext();
    const { data: products } = await (await ctx.get(`${KIOSK}/api/products`)).json();
    const target = products.find((p) => p.is_orderable);
    for (const p of PAYLOADS) {
      const r = await timed(() => ctx.post(`${KIOSK}/api/orders`, {
        data: { service_mode: 'takeaway', items: [{ type: 'product', product_id: p, quantity: 1 }] },
      }));
      expectClean(`product_id=${p}`, r);
      // Soit refus (422 PRODUCT_UNAVAILABLE), soit l'entier en tete de chaine (PHP (int)) :
      // dans ce cas le total est celui d'UN produit du catalogue, calcule par le serveur.
      expect([201, 422], p).toContain(r.res.status());
    }
    // La cle d'idempotence et le chevalet sont stockes comme du texte.
    const key = `' OR '1'='1 ${RUN}`.slice(0, 36);
    const tag = "'; DROP TABLE x;--";
    const a = await ctx.post(`${KIOSK}/api/orders`, {
      data: { idempotency_key: key, service_mode: 'dine_in', service_tag: tag, items: [{ type: 'product', product_id: target.id, quantity: 1 }] },
    });
    expect(a.status()).toBe(201);
    const first = (await a.json()).data;
    expect(first.total_ttc_cents).toBe(target.price_cents);
    // Meme cle : la MEME commande revient (la cle est comparee comme une valeur, pas comme du SQL).
    const b = await ctx.post(`${KIOSK}/api/orders`, {
      data: { idempotency_key: key, service_mode: 'dine_in', service_tag: tag, items: [{ type: 'product', product_id: target.id, quantity: 1 }] },
    });
    expect((await b.json()).data.order_number).toBe(first.order_number);
    await ctx.dispose();
  });

  test('connexion et mot de passe oublie : l e-mail hostile ne connecte personne', async () => {
    const ctx = await pwRequest.newContext({ extraHTTPHeaders: { 'X-Forwarded-For': '198.51.100.201' } });
    for (const email of ["admin@wakdo.local' OR '1'='1", "' OR 1=1 -- ", "admin@wakdo.local'-- ", "x' UNION SELECT id, password_hash, role_id FROM user -- "]) {
      const r = await timed(() => ctx.post(`${ADMIN}/admin/api/auth/login`, { data: { email, password: "' OR '1'='1" } }));
      expectClean(`login ${email}`, r);
      expect([401, 422], email).toContain(r.res.status());
    }
    expect((await ctx.get(`${ADMIN}/admin/api/auth/me`)).status()).toBe(401);

    const html = await (await ctx.get(`${ADMIN}/forgot_password`)).text();
    const csrf = html.match(/name="_csrf" value="([^"]+)"/)[1];
    const r = await timed(() => ctx.post(`${ADMIN}/forgot_password`, { form: { _csrf: csrf, email: "x' OR '1'='1" } }));
    expectClean('forgot_password', r);
    expect(r.res.status()).toBe(200);
    await ctx.dispose();
  });

  test('API d administration : chemins et corps hostiles, libelles relus a l identique, rien d autre ne bouge', async () => {
    const s = await apiSession(ADMIN_EMAIL, ADMIN_PASSWORD);
    const before = {
      categories: await total(s.ctx, '/admin/api/categories'),
      products: await total(s.ctx, '/admin/api/products'),
      users: await total(s.ctx, '/admin/api/users'),
    };

    for (const p of PAYLOADS) {
      const enc = encodeURIComponent(p);
      for (const path of [`/admin/api/products/${enc}`, `/admin/api/categories/${enc}`, `/admin/api/users/${enc}`, `/admin/api/orders/${enc}`, `/admin/api/ingredients/${enc}/movements`]) {
        const r = await timed(() => s.ctx.get(`${ADMIN}${path}`));
        expectClean(path, r);
      }
    }

    // Deux creations au libelle hostile : stockees et relues comme du texte.
    const catName = `Sq ${RUN}'); DROP TABLE category;--`.slice(0, 60);
    const cat = await s.ctx.post(`${ADMIN}/admin/api/categories`, {
      headers: { 'X-CSRF-Token': s.csrf }, data: { name: catName, slug: `sec-sqli-${RUN}`, display_order: 65535 },
    });
    expect(cat.status()).toBe(201);
    const catId = (await cat.json()).data.id;
    const prodName = `Sq ${RUN}' UNION SELECT email, password_hash FROM user --`;
    const prod = await s.ctx.post(`${ADMIN}/admin/api/products`, {
      headers: { 'X-CSRF-Token': s.csrf },
      data: { category_id: catId, name: prodName, description: "1' OR '1'='1", price_cents: 100, vat_rate: 100, is_available: false, display_order: 65535 },
    });
    expect(prod.status()).toBe(201);
    const prodId = (await prod.json()).data.id;
    const readBack = (await (await s.ctx.get(`${ADMIN}/admin/api/products/${prodId}`)).json()).data;
    expect(readBack.name).toBe(prodName);
    expect(readBack.description).toBe("1' OR '1'='1");
    expect((await (await s.ctx.get(`${ADMIN}/admin/api/categories/${catId}`)).json()).data.name).toBe(catName);

    // Un identifiant de chemin hostile ne touche pas la ligne visee ni les autres.
    const upd = await timed(() => s.ctx.put(`${ADMIN}/admin/api/products/${encodeURIComponent(`${prodId} OR 1=1`)}`, {
      headers: { 'X-CSRF-Token': s.csrf },
      data: { category_id: catId, name: `ecrase ${RUN}`, price_cents: 100, vat_rate: 100, is_available: false, display_order: 65535 },
    }));
    expectClean('PUT produit chemin hostile', upd);

    const after = {
      categories: await total(s.ctx, '/admin/api/categories'),
      products: await total(s.ctx, '/admin/api/products'),
      users: await total(s.ctx, '/admin/api/users'),
    };
    expect(after).toEqual({ categories: before.categories + 1, products: before.products + 1, users: before.users });
    // Le seul produit eventuellement modifie par le PUT est celui dont l'id ouvre la chaine.
    const all = (await (await s.ctx.get(`${ADMIN}/admin/api/products`)).json()).data;
    expect(all.filter((x) => x.name === `ecrase ${RUN}`).map((x) => x.id)).toEqual(upd.res.status() === 200 ? [prodId] : []);

    await s.ctx.post(`${ADMIN}/admin/api/categories/${catId}/toggle`, { headers: { 'X-CSRF-Token': s.csrf } });
    await s.ctx.dispose();
  });
});
