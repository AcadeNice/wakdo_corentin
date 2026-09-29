// Tests de securite -- cookie et cycle de vie de la session (OWASP Top 10 2021 A07
// Identification and Authentication Failures ; OWASP ASVS 4.0 chapitre V3 Session
// Management).
//
// Ce qui est prouve contre la pile jetable :
//  - attributs du cookie WAKDO_SID : HttpOnly, SameSite=Strict, Secure uniquement quand la
//    requete arrive en HTTPS (X-Forwarded-Proto pose par Traefik, ADR-0010,
//    App\Auth\SessionManager::cookieSecure) ;
//  - anti-fixation : identifiant renouvele a la connexion, identifiant impose par le client
//    refuse (session.use_strict_mode = 1, docker/php-fpm/php.ini) ;
//  - deconnexion : l'ancien cookie ne donne plus acces (session detruite cote serveur) ;
//  - l'hote borne ne devrait pas ouvrir de session (constat, voir le test marque).
//
// Comptes : identifiants publics de la demo (db/seeds/0001, docs/demo/comptes-demo.md),
// comme les autres specs. Chaque test cree ses propres contextes HTTP (jarres de cookies
// separees) : aucun etat partage entre tests.
const { test, expect, request: pwRequest } = require('@playwright/test');

const KIOSK = 'http://kiosk.wakdo.test';
const ADMIN = 'http://admin.wakdo.test';
const ADMIN_EMAIL = 'admin@wakdo.local';
const ADMIN_PASSWORD = 'WakdoAdmin2026!';

function setCookies(res) {
  return res.headersArray().filter((h) => h.name.toLowerCase() === 'set-cookie').map((h) => h.value);
}

function sessionCookie(res) {
  return setCookies(res).find((c) => c.startsWith('WAKDO_SID=')) || null;
}

function cookieValue(raw) {
  return raw ? raw.split(';')[0].split('=').slice(1).join('=') : null;
}

async function csrfFrom(ctx, path) {
  const html = await (await ctx.get(`${ADMIN}${path}`)).text();
  const m = html.match(/name="_csrf" value="([^"]+)"/);
  return m ? m[1] : '';
}

async function formLogin(ctx, email, password, extraHeaders = {}) {
  const csrf = await csrfFrom(ctx, '/login');
  return ctx.post(`${ADMIN}/login`, {
    form: { _csrf: csrf, email, password },
    maxRedirects: 0,
    headers: extraHeaders,
  });
}

test.describe('Session du back-office', () => {
  test('cookie de session : HttpOnly + SameSite=Strict, sans Secure en HTTP clair', async () => {
    const ctx = await pwRequest.newContext();
    const res = await ctx.get(`${ADMIN}/login`);
    const raw = sessionCookie(res);
    expect(raw, 'Set-Cookie WAKDO_SID').toBeTruthy();
    expect(raw).toMatch(/;\s*HttpOnly/i);
    expect(raw).toMatch(/;\s*SameSite=Strict/i);
    // Pile locale en HTTP : un cookie Secure serait refuse par le navigateur (ADR-0010).
    expect(raw).not.toMatch(/;\s*Secure/i);
    await ctx.dispose();
  });

  test('cookie de session : Secure pose des que la requete arrive en HTTPS (X-Forwarded-Proto de Traefik)', async () => {
    const ctx = await pwRequest.newContext();
    const res = await ctx.get(`${ADMIN}/login`, { headers: { 'X-Forwarded-Proto': 'https' } });
    const raw = sessionCookie(res);
    expect(raw).toMatch(/;\s*Secure/i);
    expect(raw).toMatch(/;\s*HttpOnly/i);
    expect(raw).toMatch(/;\s*SameSite=Strict/i);
    await ctx.dispose();
  });

  test('anti-fixation : un identifiant impose par le client est remplace, et la connexion en emet un nouveau', async () => {
    const forged = 'attaquantFixeCetIdentifiant0123456789abcdefABCDEF';
    const ctx = await pwRequest.newContext({ extraHTTPHeaders: { Cookie: `WAKDO_SID=${forged}` } });
    const first = await ctx.get(`${ADMIN}/login`);
    const issued = cookieValue(sessionCookie(first));
    // use_strict_mode : l'identifiant inconnu du serveur n'est pas adopte.
    expect(issued).toBeTruthy();
    expect(issued).not.toBe(forged);
    await ctx.dispose();

    // Connexion : l'identifiant d'avant authentification est regenere (RG-3).
    const ctx2 = await pwRequest.newContext();
    const pre = cookieValue(sessionCookie(await ctx2.get(`${ADMIN}/login`)));
    const login = await formLogin(ctx2, ADMIN_EMAIL, ADMIN_PASSWORD);
    expect(login.status()).toBe(302);
    const post = cookieValue(sessionCookie(login));
    expect(post).toBeTruthy();
    expect(post).not.toBe(pre);
    await ctx2.dispose();
  });

  test('anti-fixation : l identifiant d avant connexion ne donne pas acces apres la connexion', async () => {
    const ctx = await pwRequest.newContext();
    const pre = cookieValue(sessionCookie(await ctx.get(`${ADMIN}/login`)));
    await formLogin(ctx, ADMIN_EMAIL, ADMIN_PASSWORD);
    // Un attaquant qui aurait plante "pre" chez la victime ne recupere pas la session.
    const attacker = await pwRequest.newContext({ extraHTTPHeaders: { Cookie: `WAKDO_SID=${pre}` } });
    const res = await attacker.get(`${ADMIN}/admin/dashboard`, { maxRedirects: 0 });
    expect(res.status()).toBe(302);
    expect(res.headers()['location']).toBe('/login');
    await attacker.dispose();
    await ctx.dispose();
  });

  test('deconnexion : l ancien cookie ne donne plus acces (session detruite cote serveur)', async () => {
    const ctx = await pwRequest.newContext();
    const login = await formLogin(ctx, ADMIN_EMAIL, ADMIN_PASSWORD);
    const sid = cookieValue(sessionCookie(login));
    expect((await ctx.get(`${ADMIN}/admin/dashboard`, { maxRedirects: 0 })).status()).toBe(200);

    const csrf = await csrfFrom(ctx, '/admin/dashboard');
    const out = await ctx.post(`${ADMIN}/logout`, { form: { _csrf: csrf }, maxRedirects: 0 });
    expect(out.status()).toBe(302);
    // Le serveur demande au navigateur d'effacer le cookie...
    const cleared = setCookies(out).find((c) => c.startsWith('WAKDO_SID=') && /expires=/i.test(c));
    expect(cleared).toBeTruthy();

    // ... et, surtout, un client qui aurait garde l'ancienne valeur n'entre plus.
    const replay = await pwRequest.newContext({ extraHTTPHeaders: { Cookie: `WAKDO_SID=${sid}` } });
    const page = await replay.get(`${ADMIN}/admin/dashboard`, { maxRedirects: 0 });
    expect(page.status()).toBe(302);
    expect(page.headers()['location']).toBe('/login');
    const api = await replay.get(`${ADMIN}/admin/api/auth/me`);
    expect(api.status()).toBe(401);
    await replay.dispose();
    await ctx.dispose();
  });

  test('deconnexion par l API JSON : meme effet sur l ancien cookie', async () => {
    const ctx = await pwRequest.newContext();
    const login = await ctx.post(`${ADMIN}/admin/api/auth/login`, { data: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD } });
    expect(login.status()).toBe(200);
    const { data } = await login.json();
    const sid = cookieValue(sessionCookie(login));
    const out = await ctx.post(`${ADMIN}/admin/api/auth/logout`, { headers: { 'X-CSRF-Token': data.csrf_token } });
    expect(out.status()).toBe(204);
    const replay = await pwRequest.newContext({ extraHTTPHeaders: { Cookie: `WAKDO_SID=${sid}` } });
    expect((await replay.get(`${ADMIN}/admin/api/auth/me`)).status()).toBe(401);
    await replay.dispose();
    await ctx.dispose();
  });

  test('la reponse de connexion JSON ne renvoie ni hash ni PIN', async () => {
    const ctx = await pwRequest.newContext();
    const login = await ctx.post(`${ADMIN}/admin/api/auth/login`, { data: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD } });
    const text = await login.text();
    expect(text).not.toMatch(/password|pin_hash|\$argon2/i);
    await ctx.dispose();
  });

  test('borne : l API publique n ouvre pas de session (aucun cookie pose sur l hote kiosk)', async () => {
    // CONSTAT (mineur) : src/public/admin/index.php demarre la session
    // ((new SessionManager($config))->start(), avant le dispatch) pour TOUTE requete, y
    // compris l'API publique anonyme relayee par le vhost borne et la sonde /api/health.
    // Chaque appel de la borne sans cookie cree un fichier de session cote serveur et
    // recoit un Set-Cookie WAKDO_SID inutile. Pas d'elevation de droit (la session est vide),
    // mais une surface et un stockage serveur qui grossissent avec le trafic anonyme.
    const ctx = await pwRequest.newContext();
    const responses = [];
    for (const path of ['/api/categories', '/api/products', '/api/health']) {
      const res = await ctx.get(`${KIOSK}${path}`);
      expect(res.status()).toBe(200);
      responses.push([path, res]);
    }
    test.fail(true, 'session demarree pour /api/* (src/public/admin/index.php, appel a SessionManager::start avant le dispatch)');
    for (const [path, res] of responses) {
      expect(sessionCookie(res), `${path} ne pose pas WAKDO_SID`).toBeNull();
    }
    await ctx.dispose();
  });

  test('borne : les pages statiques ne posent aucun cookie', async () => {
    const ctx = await pwRequest.newContext();
    for (const path of ['/', '/categories.html', '/products.html', '/payment.html', '/confirmation.html']) {
      const res = await ctx.get(`${KIOSK}${path}`);
      expect(res.status()).toBe(200);
      expect(setCookies(res), path).toEqual([]);
    }
    await ctx.dispose();
  });
});
