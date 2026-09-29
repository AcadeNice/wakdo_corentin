// Tests de securite -- base de donnees arretee (OWASP Top 10 2021 A05 ; OWASP ASVS 4.0
// V7.4.1 message d'erreur generique ; "fail-closed" de l'authentification).
//
// Phase 3 de tests/e2e/run-security.sh : le lanceur arrete le conteneur de base de la pile
// JETABLE puis joue ce fichier (SEC_PHASE=db-down), avec APP_DEBUG=false comme en
// production. Hors de cette phase, le fichier est saute.
//
// Complete le cas "boom" de src/app/Health/captured-responses.json (une route) : ici, les
// deux hotes, l'API publique, la connexion et la demande de reinitialisation.
const { test, expect, request: pwRequest } = require('@playwright/test');

const KIOSK = 'http://kiosk.wakdo.test';
const ADMIN = 'http://admin.wakdo.test';
const LEAK = /SQLSTATE|PDO|wakdo-db|Connection refused|getaddrinfo|php_network|\/var\/www|Stack trace|\.php:\d+|\.php on line|Warning:|Fatal/i;

test.describe('Base de donnees indisponible', () => {
  test.skip(process.env.SEC_PHASE !== 'db-down', 'phase "db-down" de tests/e2e/run-security.sh uniquement');

  test('API publique : 500 JSON generique, sans detail de connexion', async () => {
    const ctx = await pwRequest.newContext();
    for (const url of [`${KIOSK}/api/categories`, `${KIOSK}/api/products/1`, `${ADMIN}/api/menus`]) {
      const res = await ctx.get(url);
      const body = await res.text();
      expect(res.status(), url).toBe(500);
      expect(body, url).not.toMatch(LEAK);
      expect(JSON.parse(body).error.code, url).toBe('INTERNAL_ERROR');
    }
    const order = await ctx.post(`${KIOSK}/api/orders`, { data: { service_mode: 'takeaway', items: [{ type: 'product', product_id: 1 }] } });
    expect(order.status()).toBe(500);
    expect(await order.text()).not.toMatch(LEAK);
    await ctx.dispose();
  });

  test('sonde /api/health : etat degrade annonce, aucun detail de connexion', async () => {
    const ctx = await pwRequest.newContext();
    const res = await ctx.get(`${ADMIN}/api/health`);
    const body = await res.text();
    expect(body).not.toMatch(LEAK);
    expect(JSON.parse(body).status).toBe('degraded');
    await ctx.dispose();
  });

  test('connexion : echec ferme (aucune session), message generique', async () => {
    const ctx = await pwRequest.newContext({ extraHTTPHeaders: { 'X-Forwarded-For': '198.51.100.99' } });
    const api = await ctx.post(`${ADMIN}/admin/api/auth/login`, { data: { email: 'admin@wakdo.local', password: 'WakdoAdmin2026!' } });
    expect(api.status()).toBe(401);
    expect(await api.text()).not.toMatch(LEAK);

    const page = await ctx.get(`${ADMIN}/login`);
    const html = await page.text();
    const csrf = (html.match(/name="_csrf" value="([^"]+)"/) || [])[1] || '';
    const form = await ctx.post(`${ADMIN}/login`, { form: { _csrf: csrf, email: 'admin@wakdo.local', password: 'WakdoAdmin2026!' }, maxRedirects: 0 });
    expect(form.status()).not.toBe(302);
    expect(await form.text()).not.toMatch(LEAK);

    const guarded = await ctx.get(`${ADMIN}/admin/dashboard`, { maxRedirects: 0 });
    expect(guarded.status()).not.toBe(200);
    expect(await guarded.text()).not.toMatch(LEAK);
    await ctx.dispose();
  });

  test('mot de passe oublie : reponse neutre, meme base arretee', async () => {
    const ctx = await pwRequest.newContext();
    const page = await (await ctx.get(`${ADMIN}/forgot_password`)).text();
    const csrf = page.match(/name="_csrf" value="([^"]+)"/)[1];
    const res = await ctx.post(`${ADMIN}/forgot_password`, { form: { _csrf: csrf, email: 'manager@wakdo.local' } });
    expect(res.status()).toBe(200);
    const body = await res.text();
    expect(body).toContain('Si un compte correspond à cet email');
    expect(body).not.toMatch(LEAK);
    await ctx.dispose();
  });
});
