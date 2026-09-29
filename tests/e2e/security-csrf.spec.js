// Tests de securite -- falsification de requete inter-sites (CSRF). OWASP Top 10 2021 A01
// Broken Access Control (la CSRF y est rattachee) ; OWASP ASVS 4.0 V4.2.2.
//
// Base existante : les refus SANS jeton sont deja captures route par route dans
// src/app/Health/captured-responses.json (cas "csrf") et testes unitairement
// (tests/Unit/Admin/HtmlRouteCsrfTest.php, tests/Unit/Auth/CsrfTest.php).
//
// Ajoute ici, contre la pile jetable, le cas que ces preuves ne couvrent pas : un jeton
// VALIDE mais appartenant a une AUTRE session (jeton de A + cookie de B), par le
// formulaire HTML et par l'API JSON ; le jeton d'avant connexion inutilisable apres la
// connexion ; et la relecture de l'etat apres chaque refus.
const { test, expect, request: pwRequest } = require('@playwright/test');

const ADMIN = 'http://admin.wakdo.test';
const ADMIN_EMAIL = 'admin@wakdo.local';
const ADMIN_PASSWORD = 'WakdoAdmin2026!';
const MANAGER_EMAIL = 'manager@wakdo.local';
const MANAGER_PASSWORD = 'WakdoManager2026!';
const RUN = `${Date.now().toString(36)}${Math.floor(Math.random() * 1e6).toString(36)}`;

async function apiSession(email, password) {
  const ctx = await pwRequest.newContext();
  const res = await ctx.post(`${ADMIN}/admin/api/auth/login`, { data: { email, password } });
  expect(res.status()).toBe(200);
  const { data } = await res.json();
  return { ctx, csrf: data.csrf_token };
}

async function categoryNames(ctx) {
  const { data } = await (await ctx.get(`${ADMIN}/admin/api/categories`)).json();
  return data.map((c) => c.name);
}

test.describe('CSRF : jeton lie a la session', () => {
  test('les jetons de deux sessions distinctes different', async () => {
    const a = await apiSession(ADMIN_EMAIL, ADMIN_PASSWORD);
    const b = await apiSession(ADMIN_EMAIL, ADMIN_PASSWORD);
    expect(a.csrf).toMatch(/^[0-9a-f]{64}$/);
    expect(b.csrf).not.toBe(a.csrf);
    await a.ctx.dispose();
    await b.ctx.dispose();
  });

  test('formulaire HTML : jeton de la session A avec le cookie de la session B -> 403, rien d ecrit', async () => {
    const a = await apiSession(MANAGER_EMAIL, MANAGER_PASSWORD);
    const b = await apiSession(ADMIN_EMAIL, ADMIN_PASSWORD);
    const name = `Csrf html ${RUN}`;
    const res = await b.ctx.post(`${ADMIN}/admin/categories`, {
      form: { _csrf: a.csrf, name, slug: `csrf-html-${RUN}`, display_order: '65535' },
      maxRedirects: 0,
    });
    expect(res.status()).toBe(403);
    expect(await categoryNames(b.ctx)).not.toContain(name);
    await a.ctx.dispose();
    await b.ctx.dispose();
  });

  test('API JSON : en-tete X-CSRF-Token de la session A avec le cookie de la session B -> 403 CSRF_INVALID, rien d ecrit', async () => {
    const a = await apiSession(MANAGER_EMAIL, MANAGER_PASSWORD);
    const b = await apiSession(ADMIN_EMAIL, ADMIN_PASSWORD);
    const name = `Csrf api ${RUN}`;
    const res = await b.ctx.post(`${ADMIN}/admin/api/categories`, {
      headers: { 'X-CSRF-Token': a.csrf },
      data: { name, slug: `csrf-api-${RUN}`, display_order: 65535 },
    });
    expect(res.status()).toBe(403);
    expect((await res.json()).error.code).toBe('CSRF_INVALID');
    expect(await categoryNames(b.ctx)).not.toContain(name);
    await a.ctx.dispose();
    await b.ctx.dispose();
  });

  test('le jeton passe dans le corps ou en parametre d URL n est pas accepte par l API JSON', async () => {
    const s = await apiSession(ADMIN_EMAIL, ADMIN_PASSWORD);
    const name = `Csrf corps ${RUN}`;
    const inBody = await s.ctx.post(`${ADMIN}/admin/api/categories`, {
      data: { _csrf: s.csrf, csrf_token: s.csrf, name, slug: `csrf-corps-${RUN}` },
    });
    expect(inBody.status()).toBe(403);
    const inQuery = await s.ctx.post(`${ADMIN}/admin/api/categories?_csrf=${s.csrf}`, { data: { name, slug: `csrf-corps-${RUN}` } });
    expect(inQuery.status()).toBe(403);
    expect(await categoryNames(s.ctx)).not.toContain(name);
    await s.ctx.dispose();
  });

  test('un jeton obtenu AVANT la connexion est invalide apres la connexion (rotation)', async () => {
    const ctx = await pwRequest.newContext();
    const html = await (await ctx.get(`${ADMIN}/login`)).text();
    const preLogin = html.match(/name="_csrf" value="([^"]+)"/)[1];
    const login = await ctx.post(`${ADMIN}/login`, { form: { _csrf: preLogin, email: ADMIN_EMAIL, password: ADMIN_PASSWORD }, maxRedirects: 0 });
    expect(login.status()).toBe(302);
    const name = `Csrf pre ${RUN}`;
    const res = await ctx.post(`${ADMIN}/admin/categories`, {
      form: { _csrf: preLogin, name, slug: `csrf-pre-${RUN}`, display_order: '65535' },
      maxRedirects: 0,
    });
    expect(res.status()).toBe(403);
    expect(await categoryNames(ctx)).not.toContain(name);
    await ctx.dispose();
  });

  test('la connexion JSON exige un corps application/json (un formulaire inter-sites simple est refuse)', async () => {
    // AuthApiController (ADR-0017) : pas de jeton synchroniseur sur la connexion JSON, la
    // protection est le Content-Type impose. Un <form> HTML ne peut emettre que
    // x-www-form-urlencoded, multipart ou text/plain.
    const ctx = await pwRequest.newContext();
    const form = await ctx.post(`${ADMIN}/admin/api/auth/login`, { form: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD } });
    expect(form.status()).toBe(415);
    const plain = await ctx.post(`${ADMIN}/admin/api/auth/login`, {
      headers: { 'Content-Type': 'text/plain' },
      data: JSON.stringify({ email: ADMIN_EMAIL, password: ADMIN_PASSWORD }),
    });
    expect(plain.status()).toBe(415);
    expect((await ctx.get(`${ADMIN}/admin/api/auth/me`)).status()).toBe(401);
    await ctx.dispose();
  });
});
