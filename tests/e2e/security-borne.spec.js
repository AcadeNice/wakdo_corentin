// Tests de securite -- surface de la borne (hote kiosk). OWASP Top 10 2021 A01 Broken
// Access Control (isolement du back-office), A05 Security Misconfiguration (CORS),
// A02 Cryptographic Failures / exposition de donnees sensibles (fichiers servis, stockage
// du navigateur) ; OWASP ASVS 4.0 V14.5.3 (CORS : origine autorisee exacte).
//
// Base existante : tests/Unit/Core/CorsTest.php (politique CORS du middleware, en
// unitaire). Ce fichier prouve de bout en bout :
//  - l'hote borne ne sert aucune page ni aucune API du back-office (docker/apache/vhost.conf :
//    seul /api/* est relaye, le reste est du statique) ;
//  - aucun secret ni donnee personnelle dans les fichiers JavaScript et JSON servis ;
//  - apres un parcours de commande complet, le stockage du navigateur ne contient ni
//    identifiant, ni jeton de session, ni jeton CSRF ;
//  - CORS : une origine etrangere n'obtient aucun en-tete Access-Control-*, l'origine
//    autorisee obtient sa valeur EXACTE, jamais "*", jamais Allow-Credentials.
const { test, expect, request: pwRequest } = require('@playwright/test');

const KIOSK = 'http://kiosk.wakdo.test';
const ADMIN = 'http://admin.wakdo.test';
// CORS_ALLOWED_ORIGIN de .env.example, que copient les deux lanceurs de pile jetable.
const ALLOWED_ORIGIN = process.env.SEC_CORS_ORIGIN || 'http://kiosk.localhost:8080';
const EVIL_ORIGIN = 'https://attaquant.example';

const SECRET_IN_CODE = /(password|passwd|mot_de_passe)\s*[:=]\s*['"][^'"]+['"]|api[_-]?key\s*[:=]|secret\s*[:=]\s*['"]|Bearer\s+[A-Za-z0-9._-]{10,}|BEGIN (RSA |EC )?PRIVATE KEY|@wakdo\.local|WakdoAdmin|Wakdo\w*2026!|DB_(PASSWORD|USER|HOST)|mysql:host|AKIA[0-9A-Z]{16}|sk_live_|WAKDO_SID|\$argon2/i;

test.describe('Surface de la borne', () => {
  test('l hote borne ne sert ni les pages ni l API du back-office', async () => {
    const ctx = await pwRequest.newContext();
    const paths = [
      '/login', '/forgot_password', '/reset_password?token=x', '/admin/dashboard', '/admin/users', '/admin/health',
      '/kitchen/display', '/counter/orders', '/drive/orders', '/admin/me', '/admin/api/auth/me', '/admin/api/categories',
      '/admin/api/users', '/admin/api/health', '/admin/products/import/template',
    ];
    for (const path of paths) {
      const res = await ctx.get(`${KIOSK}${path}`, { maxRedirects: 0 });
      const body = await res.text();
      expect(res.headers()['content-type'] || '', path).not.toContain('application/json');
      expect(body, path).not.toContain('name="_csrf"');
      expect(body, path).not.toContain('name="password"');
      expect(body, path).not.toContain('csrf_token');
      expect(body, path).not.toContain('Wakdo Admin');
    }
    // Ecritures : la connexion du back-office n'existe pas cote borne.
    for (const path of ['/login', '/admin/api/auth/login']) {
      const res = await ctx.post(`${KIOSK}${path}`, { data: { email: 'admin@wakdo.local', password: 'WakdoAdmin2026!' }, maxRedirects: 0 });
      expect(res.status(), path).not.toBe(302);
      expect(await res.text(), path).not.toContain('csrf_token');
    }
    // Et aucune session ouverte par ces essais ne donne acces au back-office.
    const cookies = (await ctx.storageState()).cookies.filter((c) => c.name === 'WAKDO_SID');
    for (const c of cookies) {
      const probe = await pwRequest.newContext({ extraHTTPHeaders: { Cookie: `WAKDO_SID=${c.value}` } });
      expect((await probe.get(`${ADMIN}/admin/api/auth/me`)).status()).toBe(401);
      await probe.dispose();
    }
    await ctx.dispose();
  });

  test('fichiers JavaScript et JSON servis : aucun secret ni donnee personnelle', async ({ page }) => {
    const scanned = new Map();
    page.on('response', async (res) => {
      const type = res.request().resourceType();
      if ((type === 'script' || type === 'fetch' || type === 'xhr') && res.url().startsWith(KIOSK)) {
        try { scanned.set(res.url(), await res.text()); } catch { /* reponse sans corps */ }
      }
    });
    for (const path of ['/index.html', '/categories.html?mode=sur-place', '/products.html?category=1', '/products.html?category=2', '/payment.html', '/confirmation.html']) {
      await page.goto(`${KIOSK}${path}`);
      await page.waitForLoadState('networkidle');
    }
    expect(scanned.size).toBeGreaterThan(10);
    for (const [url, body] of scanned) {
      expect(body, url).not.toMatch(SECRET_IN_CODE);
    }
  });

  test('stockage du navigateur apres une commande complete : rien de sensible', async ({ page }) => {
    await page.goto(`${KIOSK}/index.html`);
    await page.locator('a[href*="categories.html?mode=sur-place"]').click();
    await page.locator('a[href="products.html?category=2"]').click();
    await page.locator('#products-grid a.product-card:not(.product-card--unavailable)').first().click();
    await page.locator('#po-add').click();
    await page.locator('[data-order-panel] .order-panel__pay').click();
    await expect(page).toHaveURL(/payment\.html/);
    await page.locator('#pay-card').click();
    await page.locator('#chevalet-input').fill('12');
    await page.locator('#chevalet-ok').click();
    await expect(page).toHaveURL(/confirmation\.html/);

    const storage = await page.evaluate(() => {
      const dump = (s) => Object.fromEntries(Object.keys(s).map((k) => [k, s.getItem(k)]));
      return { local: dump(localStorage), session: dump(sessionStorage), cookie: document.cookie };
    });
    const all = JSON.stringify(storage);
    test.info().annotations.push({ type: 'cles stockees', description: [...Object.keys(storage.local), ...Object.keys(storage.session)].join(', ') });
    expect(all).not.toMatch(/@|password|WAKDO_SID|csrf|pin|\$argon2|Bearer/i);
    // Aucun jeton hexadecimal de 64 caracteres (forme du jeton CSRF / de reinitialisation).
    expect(all).not.toMatch(/[0-9a-f]{64}/);
    expect(storage.cookie).toBe('');
  });

  test('CORS : une origine etrangere n obtient aucun en-tete Access-Control-*', async () => {
    const ctx = await pwRequest.newContext();
    for (const base of [KIOSK, ADMIN]) {
      const get = await ctx.get(`${base}/api/categories`, { headers: { Origin: EVIL_ORIGIN } });
      expect(get.status()).toBe(200);
      const h = get.headers();
      expect(h['access-control-allow-origin'], base).toBeUndefined();
      expect(h['access-control-allow-credentials'], base).toBeUndefined();

      const pre = await ctx.fetch(`${base}/api/orders`, {
        method: 'OPTIONS',
        headers: { Origin: EVIL_ORIGIN, 'Access-Control-Request-Method': 'POST', 'Access-Control-Request-Headers': 'content-type' },
      });
      expect(pre.status(), `${base} preflight`).not.toBe(204);
      expect(pre.headers()['access-control-allow-origin'], `${base} preflight`).toBeUndefined();
      expect(pre.headers()['access-control-allow-methods'], `${base} preflight`).toBeUndefined();

      // Origine qui CONTIENT l'origine autorisee : refusee (egalite stricte, pas de prefixe).
      const lookalike = await ctx.get(`${base}/api/categories`, { headers: { Origin: `${ALLOWED_ORIGIN}.attaquant.example` } });
      expect(lookalike.headers()['access-control-allow-origin']).toBeUndefined();
      const nul = await ctx.get(`${base}/api/categories`, { headers: { Origin: 'null' } });
      expect(nul.headers()['access-control-allow-origin']).toBeUndefined();
    }
    await ctx.dispose();
  });

  test('CORS : l origine autorisee obtient sa valeur exacte, jamais "*", jamais Allow-Credentials, et seulement sur /api/', async () => {
    const ctx = await pwRequest.newContext();
    const get = await ctx.get(`${ADMIN}/api/categories`, { headers: { Origin: ALLOWED_ORIGIN } });
    expect(get.headers()['access-control-allow-origin']).toBe(ALLOWED_ORIGIN);
    expect(get.headers()['access-control-allow-credentials']).toBeUndefined();
    expect(get.headers()['vary'] || '').toMatch(/Origin/);

    const pre = await ctx.fetch(`${ADMIN}/api/orders`, {
      method: 'OPTIONS',
      headers: { Origin: ALLOWED_ORIGIN, 'Access-Control-Request-Method': 'POST', 'Access-Control-Request-Headers': 'content-type' },
    });
    expect(pre.status()).toBe(204);
    expect(pre.headers()['access-control-allow-origin']).toBe(ALLOWED_ORIGIN);
    expect(pre.headers()['access-control-allow-methods']).toBe('GET, POST, OPTIONS');
    expect(pre.headers()['access-control-allow-credentials']).toBeUndefined();

    // L'API d'administration n'est pas ouverte, meme a l'origine autorisee.
    const adminApi = await ctx.get(`${ADMIN}/admin/api/categories`, { headers: { Origin: ALLOWED_ORIGIN } });
    expect(adminApi.headers()['access-control-allow-origin']).toBeUndefined();
    const me = await ctx.get(`${ADMIN}/admin/me`, { headers: { Origin: ALLOWED_ORIGIN } });
    expect(me.headers()['access-control-allow-origin']).toBeUndefined();
    await ctx.dispose();
  });

  test('suivi public d une commande : canal kiosk seulement, ni total ni identifiant', async () => {
    const ctx = await pwRequest.newContext();
    const { data: products } = await (await ctx.get(`${KIOSK}/api/products`)).json();
    const created = await ctx.post(`${KIOSK}/api/orders`, {
      data: { service_mode: 'takeaway', items: [{ type: 'product', product_id: products.find((p) => p.is_orderable).id, quantity: 1 }] },
    });
    const number = (await created.json()).data.order_number;
    const show = await (await ctx.get(`${KIOSK}/api/orders/${number}`)).json();
    expect(Object.keys(show.data).sort()).toEqual(['order_number', 'status']);
    // Les numeros comptoir/drive voisins repondent comme un numero inconnu (anti-enumeration).
    const id = Number(number.slice(1));
    for (const guess of [`C${id}`, `D${id}`, `C${id - 1}`, `D${id - 1}`, 'C1', 'D1']) {
      const res = await ctx.get(`${KIOSK}/api/orders/${guess}`);
      expect(res.status(), guess).toBe(404);
      expect((await res.json()).error.code).toBe('ORDER_NOT_FOUND');
    }
    await ctx.dispose();
  });
});
