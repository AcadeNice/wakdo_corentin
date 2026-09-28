// Capture des VRAIES reponses de chaque route, pour le trajet de la page Sante.
//
// Joue sur une pile jetable (tests/e2e/run-health-capture.sh), jamais contre la production :
// chaque route de la carte (lue sur /admin/health, donc dans le routeur) est appelee pour
// de vrai, en succes et sur chacun des refus que le trajet propose. Le fichier produit,
// src/app/Health/captured-responses.json (hors de la racine web), est ce que la page affiche quand
// elle ne peut pas faire l'appel elle-meme (une ecriture, ou un refus qui demande un autre
// compte) : une reponse observee, datee, jamais un modele.
//
// Un refus qu'on n'arrive pas a provoquer n'est pas invente : il est ecrit « non reproduit »
// avec le statut obtenu, et la page le dit.
//
// Deux phases, pilotees par HEALTH_CAPTURE_PHASE :
//   - main    : tout, base en marche ;
//   - db-down : la base est arretee par le lanceur ; on capture la vraie reponse d'une
//               exception (JSON et HTML), celle du bouton « Simuler une exception ».
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const OUT = process.env.HEALTH_CAPTURE_OUT;
const PHASE = process.env.HEALTH_CAPTURE_PHASE || 'main';
const STATE_DIR = process.env.HEALTH_CAPTURE_STATE || '/tmp/health-capture';
const ADMIN = 'http://admin.wakdo.test';
const KIOSK = 'http://kiosk.wakdo.test';
const COLLECTION = path.join(__dirname, '../../docs/api/wakdo-admin.postman_collection.json');
// Meme mise en forme que la page (health.js) : une reponse capturee et une reponse en
// direct se lisent de la meme facon.
const health = require('../../src/public/admin/assets/js/health.js');

// Comptes publics de demonstration (db/seeds/0001 et 0009, docs/demo/comptes-demo.md).
// L'administrateur n'a pas de code personnel au depart : la capture lui en pose un, sur
// la pile jetable seulement, par la vraie page /admin/profile/pin.
const ACCOUNTS = {
  admin: { email: 'admin@wakdo.local', password: 'WakdoAdmin2026!', pin: '5151' },
  manager: { email: 'manager@wakdo.local', password: 'WakdoManager2026!', pin: '1010' },
  kitchen: { email: 'cuisine@wakdo.local', password: 'WakdoCuisine2026!', pin: '2020' },
  counter: { email: 'comptoir@wakdo.local', password: 'WakdoComptoir2026!', pin: '3030' },
  drive: { email: 'drive@wakdo.local', password: 'WakdoDrive2026!', pin: '4040' },
};
// Compte qui encaisse les essais de code faux : la limitation des essais ne touche pas
// l'administrateur qui doit encore agir ensuite.
const PIN_DECOY = 'comptoir2@wakdo.local';

const BODY_MAX = 1600;

test.skip(!OUT, 'capture lancee seulement par tests/e2e/run-health-capture.sh');
test.setTimeout(30 * 60 * 1000);

// ---------------------------------------------------------------------------------
// Mise en forme d'une reponse : ce que la page affichera, tel quel.
// ---------------------------------------------------------------------------------
function prune(v, depth = 0) {
  if (Array.isArray(v)) {
    const head = v.slice(0, 2).map((x) => prune(x, depth + 1));
    if (v.length > 2) head.push(`… et ${v.length - 2} autre(s) élément(s)`);
    return head;
  }
  if (v && typeof v === 'object') {
    const o = {};
    for (const [k, x] of Object.entries(v)) {
      o[k] = /csrf|token/i.test(k) && typeof x === 'string' && x.length > 16 ? `<jeton de ${x.length} caractères>` : prune(x, depth + 1);
    }
    return o;
  }
  return v;
}

function htmlSummary(html) {
  return health.summarizeBody('text/html', html);
}

async function shape(res, request) {
  const status = res.status();
  const h = res.headers();
  const ctype = (h['content-type'] || '').split(';')[0];
  const out = { status, type: ctype || null };
  if (h.location) out.location = h.location;
  const keep = ['content-type', 'location', 'cache-control', 'access-control-allow-origin', 'allow', 'retry-after'];
  out.headers = Object.fromEntries(Object.entries(h).filter(([k]) => keep.includes(k)));
  let text = '';
  try { text = await res.text(); } catch { text = ''; }
  if (ctype === 'application/json' || ctype === 'text/html') {
    text = health.summarizeBody(ctype, text);
  }
  if (!text && out.location) text = `Location: ${out.location}\n\n(corps vide : le navigateur suit la redirection)`;
  out.body = text.length > BODY_MAX ? text.slice(0, BODY_MAX) + '\n… (tronqué)' : text;
  if (request) out.request = request;
  return out;
}

function describeRequest(method, url, headers, body) {
  const u = new URL(url);
  const shown = {};
  for (const [k, v] of Object.entries(headers || {})) {
    if (/^(content-type|x-csrf-token|origin|idempotency-key|access-control-request-method)$/i.test(k)) {
      shown[k] = /csrf/i.test(k) ? '<jeton de la session>' : v;
    }
  }
  let b = null;
  if (body && typeof body === 'object' && !Buffer.isBuffer(body)) {
    const masked = JSON.parse(JSON.stringify(body, (k, v) => (/(password|pin|csrf)/i.test(k) && !/^pin_email$/.test(k) && typeof v === 'string' && v ? '<masqué>' : v)));
    b = JSON.stringify(prune(masked), null, 2);
  } else if (typeof body === 'string') {
    b = body.slice(0, 400);
  }
  return { method, path: u.pathname + u.search, headers: shown, body: b };
}

// ---------------------------------------------------------------------------------
// Registre des captures.
// ---------------------------------------------------------------------------------
const store = { routes: {} };
const EXPECT_REFUSAL = new Set(['session', 'perm', 'csrf', 'body', 'valid', 'pin', 'reauth', 'creds', 'router', 'db', 'cors']);

function slotOf(key) {
  if (!store.routes[key]) store.routes[key] = { ok: null, fail: {} };
  return store.routes[key];
}

function isRefusal(slot, shaped) {
  if (shaped.status >= 400) return true;
  if (slot === 'session' && shaped.status === 302 && /\/login/.test(shaped.location || '')) return true;
  if (['valid', 'pin', 'reauth', 'creds'].includes(slot) && shaped.status === 200 && /erreur sous un champ|message :|bloc d'alerte/.test(shaped.body)) return true;
  return false;
}

function record(key, slot, shaped, how) {
  const entry = slotOf(key);
  shaped.how = how;
  if (slot === 'ok') {
    if (!entry.ok && shaped.status < 400) entry.ok = shaped;
    else if (!entry.ok && !entry.ok_attempt) entry.ok_attempt = shaped;
    return;
  }
  if (entry.fail[slot] && !entry.fail[slot].not_reproduced) return;
  if (EXPECT_REFUSAL.has(slot) && !isRefusal(slot, shaped)) {
    entry.fail[slot] = { not_reproduced: true, observed_status: shaped.status, how };
    return;
  }
  entry.fail[slot] = shaped;
}

async function send(ctx, method, url, opts = {}) {
  const headers = { ...(opts.headers || {}) };
  const init = { method, headers, maxRedirects: 0, failOnStatusCode: false };
  if (opts.json !== undefined) { init.data = JSON.stringify(opts.json); headers['Content-Type'] = headers['Content-Type'] || 'application/json'; }
  if (opts.raw !== undefined) init.data = opts.raw;
  if (opts.form) init.form = opts.form;
  if (opts.multipart) init.multipart = opts.multipart;
  const res = await ctx.fetch(url, init);
  return shape(res, describeRequest(method, url, headers, opts.json !== undefined ? opts.json : (opts.form || opts.raw)));
}

// ---------------------------------------------------------------------------------
// Sessions.
// ---------------------------------------------------------------------------------
async function apiLogin(playwright, account) {
  const ctx = await playwright.request.newContext({ baseURL: ADMIN });
  const res = await ctx.post('/admin/api/auth/login', { data: { email: account.email, password: account.password }, failOnStatusCode: false });
  const body = await res.json().catch(() => ({}));
  return { ctx, csrf: body && body.data ? body.data.csrf_token : null, account };
}

async function csrfOf(ctx) {
  const res = await ctx.get('/admin/api/auth/me', { failOnStatusCode: false });
  const body = await res.json().catch(() => ({}));
  return body && body.data ? body.data.csrf_token : null;
}

function concrete(pattern, ids) {
  return pattern.replace(/\{(id|number)\}/g, (m, k) => String(ids[k] ?? (k === 'number' ? 'ZZZZ' : '999999')));
}

// ---------------------------------------------------------------------------------
// Rejeu de la collection Postman (ecritures JSON) avec un petit equivalent de `pm`.
// ---------------------------------------------------------------------------------
function makePm(env, response) {
  const swallow = new Proxy(function () {}, { get: () => swallow, apply: () => swallow });
  return {
    environment: { set: (k, v) => { env[k] = v; }, get: (k) => env[k] },
    variables: { get: (k) => env[k] },
    response: { json: () => response.json, code: response.status, to: swallow },
    test: (name, fn) => { try { fn(); } catch { /* verdict de la collection, pas le notre */ } },
    expect: () => swallow,
  };
}

function subst(s, env) {
  return String(s).replace(/\{\{([a-zA-Z_]+)\}\}/g, (m, k) => (env[k] !== undefined ? String(env[k]) : m));
}

function flatten(items, out = []) {
  for (const it of items) { if (it.item) flatten(it.item, out); else out.push(it); }
  return out;
}

function patternFor(routes, method, pathname) {
  for (const r of routes) {
    if (r.m !== method) continue;
    const re = new RegExp('^' + r.p.replace(/\{[^}]+\}/g, '[^/]+') + '$');
    if (re.test(pathname)) return r;
  }
  return null;
}

function lackingRole(sessions, perm) {
  // Premier compte de demonstration dont le role ne detient pas la permission.
  for (const k of ['kitchen', 'drive', 'counter', 'manager']) {
    if (sessions[k] && !sessions[k].perms.includes(perm)) return sessions[k];
  }
  return null;
}

// ---------------------------------------------------------------------------------
test('capture des reponses reelles', async ({ playwright, browser }) => {
  fs.mkdirSync(STATE_DIR, { recursive: true });
  if (PHASE === 'db-down') { await captureDbDown(playwright); return; }
  if (PHASE === 'reset') { await captureReset(browser); return; }

  // --- Carte des routes, lue dans la page (donc dans le routeur) -------------------
  const context = await browser.newContext();
  const page = await context.newPage();
  await page.goto(`${ADMIN}/login`);
  await page.fill('#email', ACCOUNTS.admin.email);
  await page.fill('#password', ACCOUNTS.admin.password);
  await page.locator('form[action="/login"] button[type="submit"]').click();
  await page.waitForURL(/\/admin\//);
  await page.goto(`${ADMIN}/admin/health`);
  const routes = JSON.parse(await page.locator('.health-page').getAttribute('data-routes'));
  const byKey = new Map(routes.map((r) => [`${r.m} ${r.p}`, r]));

  // --- Code personnel de l'administrateur, par la vraie page ----------------------
  await page.goto(`${ADMIN}/admin/profile/pin`);
  const pinCsrf = await page.locator('form[action="/admin/profile/pin"] input[name="_csrf"]').inputValue();
  const pinForm = { _csrf: pinCsrf, current_password: ACCOUNTS.admin.password, pin: ACCOUNTS.admin.pin, pin_confirm: ACCOUNTS.admin.pin };
  record('POST /admin/profile/pin', 'reauth', await send(page.request, 'POST', `${ADMIN}/admin/profile/pin`, { form: { ...pinForm, current_password: 'mauvais-mot-de-passe' } }), 'mot de passe actuel faux');
  record('POST /admin/profile/pin', 'csrf', await send(page.request, 'POST', `${ADMIN}/admin/profile/pin`, { form: { ...pinForm, _csrf: '' } }), 'formulaire sans jeton');
  record('POST /admin/profile/pin', 'valid', await send(page.request, 'POST', `${ADMIN}/admin/profile/pin`, { form: { ...pinForm, pin_confirm: '9' } }), 'confirmation differente');
  record('POST /admin/profile/pin', 'ok', await send(page.request, 'POST', `${ADMIN}/admin/profile/pin`, { form: pinForm }), 'code personnel pose sur la pile de test');

  // --- Sessions par role et leurs permissions -------------------------------------
  const sessions = {};
  for (const [k, acc] of Object.entries(ACCOUNTS)) sessions[k] = await apiLogin(playwright, acc);
  const anon = await playwright.request.newContext({ baseURL: ADMIN });
  const kioskCtx = await playwright.request.newContext({ baseURL: KIOSK });
  const rolesRes = await sessions.admin.ctx.get('/admin/api/roles');
  const roleList = ((await rolesRes.json()).data) || [];
  const permsByRole = {};
  for (const role of roleList) {
    const d = await (await sessions.admin.ctx.get(`/admin/api/roles/${role.id}`)).json();
    const perms = (d.data && (d.data.permissions || d.data.permission_codes)) || [];
    permsByRole[role.code] = perms.map((p) => (typeof p === 'string' ? p : p.code));
  }
  for (const k of Object.keys(ACCOUNTS)) sessions[k].perms = permsByRole[k] || [];

  // --- Identifiants reels pour les lectures ----------------------------------------
  const firstOf = async (url, field = 'id') => {
    const d = await (await sessions.admin.ctx.get(url)).json().catch(() => ({}));
    const list = Array.isArray(d.data) ? d.data : (d.data && (d.data.items || d.data.orders)) || [];
    return list.length ? list[0][field] : null;
  };
  const kioskProducts = (await (await kioskCtx.get('/api/products')).json()).data || [];
  const orderProduct = kioskProducts[0];
  const kioskOrder = await (await kioskCtx.post('/api/orders', {
    data: { idempotency_key: `cap-${Date.now()}`, service_mode: 'takeaway', items: [{ type: 'product', product_id: orderProduct.id, quantity: 1 }] },
    failOnStatusCode: false,
  })).json();
  const ids = {
    '/api/products': orderProduct.id,
    '/api/menus': (((await (await kioskCtx.get('/api/menus')).json()).data || [])[0] || {}).id,
    '/api/orders': kioskOrder.data && kioskOrder.data.order_number,
    '/admin/api/categories': await firstOf('/admin/api/categories'),
    '/admin/api/products': await firstOf('/admin/api/products'),
    '/admin/api/menus': await firstOf('/admin/api/menus'),
    '/admin/api/ingredients': await firstOf('/admin/api/ingredients'),
    '/admin/api/users': await firstOf('/admin/api/users'),
    '/admin/api/roles': await firstOf('/admin/api/roles'),
    '/admin/api/orders': await firstOf('/admin/api/orders', 'order_number'),
  };
  const idFor = (pattern) => {
    const m = pattern.match(/^(\/(?:admin\/api|api|admin|counter|drive|kitchen)\/[a-z-]+)/);
    if (!m) return {};
    const base = m[1].replace(/^\/admin\/(?!api)/, '/admin/api/').replace(/^\/(counter|drive|kitchen)\//, '/admin/api/');
    const v = ids[m[1]] ?? ids[base];
    return /\{number\}/.test(pattern) ? { number: v } : { id: v };
  };

  // --- Lectures : succes, sans session, sans permission, route et ressource absentes
  for (const r of routes.filter((x) => x.m === 'GET')) {
    const key = `GET ${r.p}`;
    const base = r.s === 'borne' ? KIOSK : ADMIN;
    const url = base + concrete(r.p, idFor(r.p));
    const admin = r.s === 'borne' ? kioskCtx : (r.anon ? anon : (r.f === 'html' ? page.request : sessions.admin.ctx));
    record(key, 'ok', await send(admin, 'GET', url), 'lecture avec la session administrateur');
    if (!r.anon) record(key, 'session', await send(anon, 'GET', url), 'sans cookie de session');
    if (r.perm) {
      const lack = lackingRole(sessions, r.perm);
      if (lack) record(key, 'perm', await send(lack.ctx, 'GET', url), `compte ${lack.account.email}, sans ${r.perm}`);
    }
    record(key, 'router', await send(admin, 'GET', url.replace(/\/?$/, '/inexistant')), 'chemin inconnu du routeur');
    if (/\{(id|number)\}/.test(r.p)) record(key, 'db', await send(admin, 'GET', base + concrete(r.p, {})), 'identifiant inexistant');
  }

  // --- Borne : commande, paiement, refus -----------------------------------------
  let seq = 0;
  const order = (extra = {}) => ({ idempotency_key: `cap-${Date.now()}-${seq++}`, service_mode: 'takeaway', items: [{ type: 'product', product_id: orderProduct.id, quantity: 1 }], ...extra });
  record('POST /api/orders', 'valid', await send(kioskCtx, 'POST', `${KIOSK}/api/orders`, { json: order({ items: [{ type: 'product', product_id: 999999, quantity: 1 }] }) }), 'produit inexistant dans la commande');
  record('POST /api/orders', 'cors', await send(kioskCtx, 'OPTIONS', `${KIOSK}/api/orders`, { headers: { Origin: 'https://site-tiers.example', 'Access-Control-Request-Method': 'POST' } }), 'preflight depuis une origine etrangere');
  record('POST /api/orders', 'router', await send(kioskCtx, 'POST', `${KIOSK}/api/orders/inexistant/route`, { json: order() }), 'chemin inconnu du routeur');
  const created = await send(kioskCtx, 'POST', `${KIOSK}/api/orders`, { json: order() });
  record('POST /api/orders', 'ok', created, 'commande de la borne sur la pile de test');
  const number = JSON.parse(created.body.startsWith('{') ? created.body : '{}').data?.order_number;
  record('POST /api/orders/{number}/pay', 'db', await send(kioskCtx, 'POST', `${KIOSK}/api/orders/ZZZZ/pay`), 'numero de commande inexistant');
  record('POST /api/orders/{number}/pay', 'cors', await send(kioskCtx, 'OPTIONS', `${KIOSK}/api/orders/${number}/pay`, { headers: { Origin: 'https://site-tiers.example', 'Access-Control-Request-Method': 'POST' } }), 'preflight depuis une origine etrangere');
  record('POST /api/orders/{number}/pay', 'ok', await send(kioskCtx, 'POST', `${KIOSK}/api/orders/${number}/pay`), 'paiement simule de la commande');
  record('POST /api/orders/{number}/pay', 'db', await send(kioskCtx, 'POST', `${KIOSK}/api/orders/${number}/pay`), 'second paiement de la meme commande');
  for (const k of ['GET /api/categories', 'GET /api/products', 'GET /api/products/{id}', 'GET /api/menus', 'GET /api/menus/{id}', 'GET /api/allergens', 'GET /api/orders/{number}', 'GET /api/health']) {
    record(k, 'cors', await send(kioskCtx, 'OPTIONS', `${KIOSK}${concrete(k.split(' ')[1], idFor(k.split(' ')[1]))}`, { headers: { Origin: 'https://site-tiers.example', 'Access-Control-Request-Method': 'GET' } }), 'preflight depuis une origine etrangere');
  }

  // --- Ecritures JSON : rejeu de la collection Postman ----------------------------
  const collection = JSON.parse(fs.readFileSync(COLLECTION, 'utf8'));
  const env = { baseUrl: ADMIN, email: ACCOUNTS.admin.email, password: ACCOUNTS.admin.password, pin_email: ACCOUNTS.admin.email, pin: ACCOUNTS.admin.pin };
  for (const k of ['manager', 'cuisine', 'comptoir', 'drive']) {
    const acc = ACCOUNTS[{ manager: 'manager', cuisine: 'kitchen', comptoir: 'counter', drive: 'drive' }[k]];
    env[`email_${k}`] = acc.email; env[`password_${k}`] = acc.password;
  }
  // Deux passes sur des ressources distinctes : la premiere ne capture que les succes ; la
  // seconde envoie les refus avant chaque requete. Un « refus » qui reussit malgre tout (une
  // route sans controle de corps, par exemple) consomme alors une ressource de la seconde
  // passe, pas celle dont le succes est capture.
  const replayCollection = async (withRefusals) => {
    const replay = await playwright.request.newContext({ baseURL: ADMIN });
    const probe = await apiLogin(playwright, ACCOUNTS.admin);
    let replayRole = 'admin';
    for (const it of flatten(collection.item)) {
      const req = it.request;
      const method = req.method;
      const url = subst(req.url.raw || req.url, env);
      const headers = {};
      for (const h of req.header || []) headers[h.key] = subst(h.value, env);
      const raw = req.body && req.body.raw ? subst(req.body.raw, env) : undefined;
      let json;
      try { json = raw ? JSON.parse(raw) : undefined; } catch { json = undefined; }
      const pathname = new URL(url).pathname;
      const r = patternFor(routes, method, pathname);
      const key = r ? `${method} ${r.p}` : null;

      if (withRefusals && r && r.w && replayRole === 'admin' && r.a !== 'apiLogin' && r.a !== 'apiLogout') {
        const withToken = { 'Content-Type': 'application/json', 'X-CSRF-Token': probe.csrf };
        record(key, 'session', await send(anon, method, url, { json: json || {} }), 'sans cookie de session');
        if (r.perm) {
          const lack = lackingRole(sessions, r.perm);
          if (lack) record(key, 'perm', await send(lack.ctx, method, url, { json: json || {}, headers: { 'X-CSRF-Token': lack.csrf } }), `compte ${lack.account.email}, sans ${r.perm}`);
        }
        record(key, 'csrf', await send(probe.ctx, method, url, { json: json || {} }), 'sans en-tete X-CSRF-Token');
        record(key, 'body', await send(probe.ctx, method, url, { raw: 'champ=valeur', headers: { ...withToken, 'Content-Type': 'text/plain' } }), 'corps en text/plain');
        record(key, 'valid', await send(probe.ctx, method, url, { json: {}, headers: withToken }), 'corps JSON vide');
        if (r.pin && json) record(key, 'pin', await send(probe.ctx, method, url, { json: { ...json, pin_email: PIN_DECOY, pin: '0000' }, headers: withToken }), 'code personnel faux');
        record(key, 'router', await send(probe.ctx, method, url.replace(/\/?$/, '/inexistant'), { json: json || {}, headers: withToken }), 'chemin inconnu du routeur');
        if (/\{(id|number)\}/.test(r.p)) record(key, 'db', await send(probe.ctx, method, ADMIN + concrete(r.p, {}), { json: json || {}, headers: withToken }), 'identifiant inexistant');
      }
      if (withRefusals && r && r.a === 'apiLogin') {
        record(key, 'creds', await send(anon, 'POST', url, { json: { email: ACCOUNTS.admin.email, password: 'mauvais-mot-de-passe' } }), 'mot de passe faux');
        record(key, 'body', await send(anon, 'POST', url, { raw: 'email=x', headers: { 'Content-Type': 'text/plain' } }), 'corps en text/plain');
      }

      const res = await replay.fetch(url, { method, headers, data: raw, maxRedirects: 0, failOnStatusCode: false });
      const shaped = await shape(res, describeRequest(method, url, headers, json !== undefined ? json : raw));
      let parsed = null;
      try { parsed = JSON.parse(await res.text()); } catch { parsed = null; }
      if (key && !withRefusals) {
        if (replayRole === 'admin' || shaped.status < 400) record(key, 'ok', { ...shaped }, `collection Postman, « ${it.name} »`);
      }
      if (key && withRefusals && replayRole !== 'admin' && shaped.status === 403) record(key, 'perm', { ...shaped }, `collection Postman, « ${it.name} »`);
      if (r && r.a === 'apiLogin') replayRole = json && json.email === ACCOUNTS.admin.email ? 'admin' : 'other';
      const pm = makePm(env, { json: parsed, status: shaped.status });
      for (const ev of it.event || []) {
        if (ev.listen !== 'test') continue;
        try { new Function('pm', ev.script.exec.join('\n'))(pm); } catch { /* script de la collection */ }
      }
    }
  };
  await replayCollection(false);
  await replayCollection(true);

  // --- Import par fichier (absent de la collection) : corps JSON {csv} -------------
  const tpl = await sessions.admin.ctx.get('/admin/api/products/import/template');
  const csv = ((await tpl.json()).data || {}).csv || '';
  const importUrl = `${ADMIN}/admin/api/products/import`;
  const tok = { 'X-CSRF-Token': sessions.admin.csrf };
  const K = 'POST /admin/api/products/import';
  record(K, 'session', await send(anon, 'POST', importUrl, { json: { csv } }), 'sans cookie de session');
  const lackImport = lackingRole(sessions, 'product.create');
  if (lackImport) record(K, 'perm', await send(lackImport.ctx, 'POST', importUrl, { json: { csv }, headers: { 'X-CSRF-Token': lackImport.csrf } }), `compte ${lackImport.account.email}, sans product.create`);
  record(K, 'csrf', await send(sessions.admin.ctx, 'POST', importUrl, { json: { csv } }), 'sans en-tete X-CSRF-Token');
  record(K, 'body', await send(sessions.admin.ctx, 'POST', importUrl, { raw: csv, headers: { ...tok, 'Content-Type': 'text/csv' } }), 'fichier CSV envoye brut, sans enveloppe JSON');
  record(K, 'valid', await send(sessions.admin.ctx, 'POST', importUrl, { json: { csv: '' }, headers: tok }), 'champ csv vide');
  record(K, 'router', await send(sessions.admin.ctx, 'POST', importUrl + '/inexistant', { json: { csv }, headers: tok }), 'chemin inconnu du routeur');
  record(K, 'ok', await send(sessions.admin.ctx, 'POST', importUrl + '?dry_run=1', { json: { csv }, headers: tok }), 'modele officiel, apercu sans ecriture (dry_run=1)');

  // --- Formulaires du back-office (ecritures HTML) --------------------------------
  const made = async (url, body) => {
    const d = await (await sessions.admin.ctx.post(url, { data: body, headers: { 'X-CSRF-Token': sessions.admin.csrf }, failOnStatusCode: false })).json();
    return d.data || {};
  };
  const run = Date.now().toString(36);
  // Ressources propres a la capture, creees avec les corps de la collection (donc valides) :
  // les formulaires HTML les modifient, les deplacent puis les suppriment sans toucher aux
  // donnees de demonstration.
  const items = flatten(collection.item);
  let made_n = 0;
  const createFrom = async (res) => {
    const it = items.find((x) => x.request.method === 'POST' && new URL(subst(x.request.url.raw, env)).pathname === `/admin/api/${res}`);
    if (!it) return {};
    made_n += 1;
    const body = JSON.parse(subst(it.request.body.raw, { ...env, run: `${run}${res.slice(0, 3)}${made_n}` }));
    return made(`/admin/api/${res}`, body);
  };
  const cat = await createFrom('categories');
  const prod = await createFrom('products');
  const ingr = await createFrom('ingredients');
  const menu = await createFrom('menus');
  const userMade = await createFrom('users');
  const roleMade = await createFrom('roles');
  const htmlIds = {
    '/admin/categories': cat.id, '/admin/products': prod.id, '/admin/ingredients': ingr.id, '/admin/menus': menu.id,
    '/admin/users': userMade.id, '/admin/roles': roleMade.id,
  };
  // Une suppression vise une ressource creee pour elle seule : celle des autres
  // formulaires a un historique (mouvements de stock, recette) qui peut, a juste titre,
  // interdire de la supprimer.
  const doomed = {
    '/admin/products': (await createFrom('products')).id,
    '/admin/ingredients': (await createFrom('ingredients')).id,
    '/admin/menus': (await createFrom('menus')).id,
    '/admin/users': (await createFrom('users')).id,
  };
  const eraseUser = (await createFrom('users')).id;
  const newOrder = async (source) => {
    const d = await made('/admin/api/orders', { service_mode: 'takeaway', source, items: [{ type: 'product', product_id: orderProduct.id, quantity: 1 }] });
    return d.order_number;
  };
  const ordReady = await newOrder('counter');
  const ordDeliver = await newOrder('counter');
  await sessions.admin.ctx.post(`/admin/api/orders/${ordDeliver}/ready`, { headers: { 'X-CSRF-Token': sessions.admin.csrf }, failOnStatusCode: false });
  const ordCancel = await newOrder('counter');

  const htmlWrites = routes.filter((r) => r.m === 'POST' && r.s === 'bo' && r.f !== 'json');
  const rank = (p) => (/\/(delete|erase|deactivate)$/.test(p) ? 2 : /\/toggle$/.test(p) ? 1 : 0);
  htmlWrites.sort((a, b) => rank(a.p) - rank(b.p));
  // Les options d'un menu, telles que le constructeur de la page les serialise, lues sur
  // le menu de la capture : la creation en a besoin et passe avant la modification.
  const menuEdit = await findForm(page, `/admin/menus/${menu.id}`, {});
  let menuSlots = menuEdit && menuEdit.fields.slots_json && menuEdit.fields.slots_json !== '[]' ? menuEdit.fields.slots_json : null;
  // Fichier d'import : le modele officiel, dont les produits sont renommes pour que
  // l'apercu propose de vraies creations a confirmer.
  const importCsv = csv.split(/\r?\n/).map((line, i) => (i === 0 || !line ? line : line.replace(/^([^;]*);([^;]*);/, (m, c, n) => `${c};${n} capture ${run};`))).join('\r\n');
  let previewHtml = null;
  const orderFor = { '/admin/orders/{number}/ready': ordReady, '/admin/orders/{number}/deliver': ordDeliver, '/admin/orders/{number}/cancel': ordCancel };

  for (const r of htmlWrites) {
    const key = `POST ${r.p}`;
    if (['/login', '/logout', '/forgot_password', '/reset_password', '/admin/profile/pin'].includes(r.p)) continue;
    const m = r.p.match(/^(\/admin\/[a-z]+)/);
    const destructive = /\/(delete|deactivate)$/.test(r.p);
    const idv = orderFor[r.p] || (/\/erase$/.test(r.p) ? eraseUser : null) || (m && (destructive ? doomed[m[1]] : htmlIds[m[1]]));
    let actual = r.p.replace(/\{(id|number)\}/, String(idv));
    let form;
    if (r.p === '/admin/products/import/confirm' && previewHtml) {
      const token = (previewHtml.match(/name="import_token"[^>]*value="([^"]*)"/) || previewHtml.match(/value="([^"]*)"[^>]*name="import_token"/) || [])[1] || '';
      const csrf = (previewHtml.match(/name="_csrf"[^>]*value="([^"]*)"/) || [])[1] || '';
      form = { fields: { _csrf: csrf, import_token: token }, required: [], multipart: false };
    } else {
      form = await findForm(page, actual, r);
    }
    if (!form) { slotOf(key).ok = { not_reproduced: true, how: 'aucun formulaire trouve pour cette adresse sur les pages candidates' }; continue; }
    if (form.actual && form.actual !== actual && !/\/thresholds$/.test(r.p)) actual = form.actual;
    const fields = fillForm(form.fields, form.required, run, form.types || {});
    if ('slots_json' in fields) {
      if (fields.slots_json && fields.slots_json !== '[]') menuSlots = menuSlots || fields.slots_json;
      else if (menuSlots) fields.slots_json = menuSlots;
    }
    if (r.pin) { fields.pin_email = ACCOUNTS.admin.email; fields.pin = ACCOUNTS.admin.pin; }
    const withFile = (f) => ({ ...f, csv_file: { name: 'modele.csv', mimeType: 'text/csv', buffer: Buffer.from(importCsv) } });
    const opts = (f) => (form.multipart ? { multipart: withFile(f) } : { form: f });
    const sendVariants = async (fields) => {
      record(key, 'session', await send(anon, 'POST', ADMIN + actual, opts(fields)), 'sans cookie de session');
      if (r.perm) {
        const lack = lackingRole(sessions, r.perm);
        if (lack) record(key, 'perm', await send(lack.ctx, 'POST', ADMIN + actual, opts({ ...fields, _csrf: lack.csrf || '' })), `compte ${lack.account.email}, sans ${r.perm}`);
      }
      record(key, 'csrf', await send(page.request, 'POST', ADMIN + actual, opts({ ...fields, _csrf: '' })), 'formulaire sans jeton');
      if (form.required.length) {
        const emptied = { ...fields };
        for (const n of form.required) emptied[n] = '';
        record(key, 'valid', await send(page.request, 'POST', ADMIN + actual, opts(emptied)), 'champs obligatoires vides');
      }
      if (r.pin) record(key, 'pin', await send(page.request, 'POST', ADMIN + actual, opts({ ...fields, pin_email: PIN_DECOY, pin: '0000' })), 'code personnel faux');
      record(key, 'router', await send(page.request, 'POST', ADMIN + actual + '/inexistant', opts(fields)), 'chemin inconnu du routeur');
      if (/\{(id|number)\}/.test(r.p)) record(key, 'db', await send(page.request, 'POST', ADMIN + concrete(r.p, {}), opts(fields)), 'identifiant inexistant');
    };
    // La confirmation d'import ne demande un code que si un prix change : un essai « code
    // faux » peut donc la reussir et consommer l'apercu. Son succes passe d'abord, les
    // refus suivent sur un apercu neuf.
    const variantsAfter = r.p === '/admin/products/import/confirm';
    if (!variantsAfter) await sendVariants(fields);
    const okRes = await page.request.fetch(ADMIN + actual, { method: 'POST', maxRedirects: 0, failOnStatusCode: false, ...(form.multipart ? { multipart: withFile(fields) } : { form: fields }) });
    const okHtml = await okRes.text().catch(() => '');
    if (r.p === '/admin/products/import/preview') previewHtml = okHtml;
    const ok = await shape({ status: () => okRes.status(), headers: () => okRes.headers(), text: async () => okHtml }, describeRequest('POST', ADMIN + actual, {}, fields));
    const redirected = ok.status === 302 || ok.status === 303;
    const previewShown = r.p === '/admin/products/import/preview' && ok.status === 200 && /import_token/.test(okHtml);
    if (!redirected && !previewShown) { record(key, 'ok', { ...ok, status: Math.max(ok.status, 400) }, 'formulaire du back-office rempli sur la pile de test'); slotOf(key).ok_attempt = ok; continue; }
    if (ok.location) {
      const next = await page.request.get(ADMIN + ok.location, { failOnStatusCode: false });
      const flash = htmlSummary(await next.text()).split('\n').filter((l) => /^message :/.test(l));
      if (flash.length) ok.body += '\n\n(page suivante, apres la redirection)\n' + flash.join('\n');
    }
    record(key, 'ok', ok, 'formulaire du back-office rempli sur la pile de test');
    if (variantsAfter) {
      const again = await page.request.fetch(`${ADMIN}/admin/products/import/preview`, { method: 'POST', maxRedirects: 0, failOnStatusCode: false, multipart: withFile({ _csrf: fields._csrf }) });
      const html = await again.text();
      const token = (html.match(/name="import_token"[^>]*value="([^"]*)"/) || [])[1] || '';
      await sendVariants({ ...fields, import_token: token });
    }
  }

  // --- Connexion, mot de passe oublie, deconnexion (HTML) -------------------------
  const fresh = await browser.newContext();
  const lp = await fresh.newPage();
  await lp.goto(`${ADMIN}/login`);
  const loginCsrf = await lp.locator('form[action="/login"] input[name="_csrf"]').inputValue().catch(() => '');
  const loginForm = { _csrf: loginCsrf, email: ACCOUNTS.manager.email, password: ACCOUNTS.manager.password };
  record('POST /login', 'creds', await send(lp.request, 'POST', `${ADMIN}/login`, { form: { ...loginForm, password: 'mauvais-mot-de-passe' } }), 'mot de passe faux');
  record('POST /login', 'csrf', await send(lp.request, 'POST', `${ADMIN}/login`, { form: { ...loginForm, _csrf: '' } }), 'formulaire sans jeton');
  record('POST /login', 'router', await send(lp.request, 'POST', `${ADMIN}/login/inexistant`, { form: loginForm }), 'chemin inconnu du routeur');
  await lp.goto(`${ADMIN}/login`);
  const loginCsrf2 = await lp.locator('form[action="/login"] input[name="_csrf"]').inputValue().catch(() => '');
  record('POST /login', 'ok', await send(lp.request, 'POST', `${ADMIN}/login`, { form: { ...loginForm, _csrf: loginCsrf2 } }), 'connexion du compte responsable');
  const fp = await fresh.newPage();
  await fp.goto(`${ADMIN}/forgot_password`);
  const fpCsrf = await fp.locator('form input[name="_csrf"]').first().inputValue().catch(() => '');
  record('POST /forgot_password', 'csrf', await send(fp.request, 'POST', `${ADMIN}/forgot_password`, { form: { _csrf: '', email: ACCOUNTS.counter.email } }), 'formulaire sans jeton');
  record('POST /forgot_password', 'ok', await send(fp.request, 'POST', `${ADMIN}/forgot_password`, { form: { _csrf: fpCsrf, email: ACCOUNTS.counter.email } }), 'demande pour un compte existant');
  await fp.goto(`${ADMIN}/reset_password?token=jeton-invalide`);
  const rpCsrf = await fp.locator('form input[name="_csrf"]').first().inputValue().catch(() => '');
  record('POST /reset_password', 'valid', await send(fp.request, 'POST', `${ADMIN}/reset_password`, { form: { _csrf: rpCsrf, token: 'jeton-invalide', password: 'NouveauMotDePasse2026!', password_confirm: 'NouveauMotDePasse2026!' } }), 'lien de reinitialisation invalide');
  slotOf('POST /reset_password').ok = slotOf('POST /reset_password').ok || { not_reproduced: true, how: 'le succes demande le lien recu par e-mail, que la pile de test n\'envoie pas' };
  record('POST /logout', 'csrf', await send(lp.request, 'POST', `${ADMIN}/logout`, { form: { _csrf: '' } }), 'formulaire sans jeton');
  await lp.goto(`${ADMIN}/admin/dashboard`).catch(() => null);
  const outCsrf = await lp.locator('form[action="/logout"] input[name="_csrf"]').first().inputValue().catch(() => '');
  record('POST /logout', 'ok', await send(lp.request, 'POST', `${ADMIN}/logout`, { form: { _csrf: outCsrf } }), 'deconnexion');

  // --- Session d'administration conservee pour la phase base arretee -------------
  await sessions.admin.ctx.storageState({ path: path.join(STATE_DIR, 'admin-api.json') });
  await context.storageState({ path: path.join(STATE_DIR, 'admin-page.json') });

  write(routes);
});

async function findForm(page, actual, r) {
  const parts = actual.split('/');
  const candidates = new Set([`${actual}/new`, `${actual}/edit`, actual]);
  for (let i = parts.length - 1; i >= 3; i--) {
    const up = parts.slice(0, i).join('/');
    candidates.add(up); candidates.add(`${up}/edit`); candidates.add(`${up}/new`);
  }
  if (/^\/(counter|drive)\/orders$/.test(actual)) candidates.add(`${actual}/new`);
  if (/^\/admin\/products\/\d+\/move$/.test(actual)) candidates.add('/admin/products/by-category');
  if (/\/admin\/orders\//.test(actual)) { candidates.add('/admin/orders'); candidates.add('/kitchen/display'); candidates.add(`${actual}`); }
  for (const c of candidates) {
    const res = await page.goto(ADMIN + c).catch(() => null);
    if (!res || res.status() !== 200) continue;
    const found = await page.evaluate(([target, pattern]) => {
      const forms = Array.from(document.querySelectorAll('form'));
      const pathOf = (x) => { try { return new URL(x.action, location.href).pathname; } catch { return ''; } };
      const isPost = (x) => (x.method || '').toLowerCase() === 'post';
      let f = forms.find((x) => pathOf(x) === target && isPost(x));
      // Repli : un formulaire de la meme route sur un autre element (la page ne propose
      // pas l'action sur l'element de la capture, par exemple le rang d'un produit).
      if (!f && pattern) {
        const re = new RegExp('^' + pattern.replace(/\{[^}]+\}/g, '[^/]+') + '$');
        f = forms.find((x) => re.test(pathOf(x)) && isPost(x) && !Array.from(x.elements).some((e) => e.type === 'submit' && e.disabled) && !x.querySelector('button[disabled]'));
      }
      // Reglage des seuils : une seule fenetre pour la liste, dont le JavaScript pose
      // l'adresse au clic (data-threshold-form) ; ses champs sont ceux de la requete.
      if (!f && /\/thresholds$/.test(target)) f = document.querySelector('form[data-threshold-form]');
      if (!f) return null;
      // Les formulaires dont le JavaScript serialise l'etat a l'envoi (slots_json des
      // menus, items_json de la caisse) : un evenement submit annulable lui laisse faire
      // ce travail sans rien envoyer.
      const ev = new Event('submit', { cancelable: true, bubbles: true });
      f.addEventListener('submit', (x) => x.preventDefault(), { once: true });
      try { f.dispatchEvent(ev); } catch (e) { /* le formulaire reste lisible */ }
      document.querySelectorAll('dialog[open]').forEach((d) => { try { d.close(); } catch (e) { /* rien */ } });
      const fields = {};
      const required = [];
      for (const el of Array.from(f.elements)) {
        if (!el.name || el.disabled || el.type === 'submit' || el.type === 'button' || el.type === 'file') continue;
        if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) continue;
        if (el.tagName === 'SELECT' && !el.value) {
          const opt = Array.from(el.options).find((o) => o.value);
          fields[el.name] = opt ? opt.value : '';
        } else {
          fields[el.name] = el.value;
        }
        if (el.required) required.push(el.name);
      }
      return { actual: pathOf(f) || target, fields, required, multipart: f.enctype === 'multipart/form-data', types: Object.fromEntries(Array.from(f.elements).filter((e) => e.name && e.type !== 'hidden').map((e) => [e.name, e.type])) };
    }, [actual, r && r.p]);
    if (found) return found;
  }
  return null;
}

const FIELD_VALUES = {
  stock_capacity: '100', pack_size: '10', pack_label: 'carton de 10', low_stock_pct: '30', critical_stock_pct: '10',
  delta: '1', packs: '1', counted_quantity: '5', quantity: '1', display_order: '70', unit: 'pièce',
  price_cents: '500', price_normal_cents: '900', price_maxi_cents: '1100', vat_rate: '10', note: 'capture',
};

function fillForm(fields, required, run, types) {
  const out = { ...fields };
  for (const [n, type] of Object.entries(types)) {
    if (out[n] || /^(pin|pin_email|_csrf|import_token|slots_json|items_json)$/.test(n)) continue;
    if (!['text', 'number', 'email', 'password', 'textarea', 'select-one', 'tel', 'search', 'url'].includes(type) && !required.includes(n)) continue;
    if (FIELD_VALUES[n] !== undefined) out[n] = FIELD_VALUES[n];
    else if (/email/.test(n)) out[n] = `capture-${run}-${n.replace(/_/g, '')}@wakdo.local`;
    else if (/password/.test(n)) out[n] = 'CaptureTest2026!';
    else if (/pin/.test(n)) out[n] = '7373';
    else if (type === 'number' || /(price|quantity|order|stock|cents|qty|count|threshold|pct)/.test(n)) out[n] = '1';
    else if (/^code$/.test(n)) out[n] = `capture_${run}`;
    else if (/slug/.test(n)) out[n] = `capture-${run}`;
    else out[n] = `Capture ${run}`;
  }
  if ('items_json' in out && (!out.items_json || out.items_json === '[]')) out.items_json = JSON.stringify([{ type: 'product', product_id: 14, quantity: 1 }]);
  if ('service_mode' in out && !out.service_mode) out.service_mode = 'takeaway';
  return out;
}

// Nouveau mot de passe : le lien de reinitialisation, que la pile de test ecrit dans son
// journal au lieu de l'envoyer (LogMailer), est passe par le lanceur.
async function captureReset(browser) {
  const link = process.env.HEALTH_CAPTURE_RESET_URL;
  if (!link) return;
  const prev = JSON.parse(fs.readFileSync(OUT, 'utf8'));
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  const url = new URL(link);
  await page.goto(`${ADMIN}${url.pathname}${url.search}`);
  const fields = await page.evaluate(() => {
    const f = document.querySelector('form[action="/reset_password"]') || document.querySelector('form');
    return f ? Object.fromEntries(Array.from(f.elements).filter((e) => e.name).map((e) => [e.name, e.value])) : null;
  });
  const entry = prev.routes['POST /reset_password'] || { ok: null, fail: {} };
  if (fields) {
    for (const k of Object.keys(fields)) if (/password/.test(k)) fields[k] = 'CaptureReset2026x';
    const res = await send(page.request, 'POST', `${ADMIN}/reset_password`, { form: fields });
    res.how = 'lien de reinitialisation recu (journal de la pile de test), nouveau mot de passe';
    if (res.status === 302 || res.status === 303) entry.ok = res;
  }
  prev.routes['POST /reset_password'] = entry;
  prev.missing_ok = (prev.missing_ok || []).filter((k) => !(k === 'POST /reset_password' && entry.ok && !entry.ok.not_reproduced));
  fs.writeFileSync(OUT, JSON.stringify(prev, null, 1) + '\n');
}

async function captureDbDown(playwright) {
  const boom = {};
  const apiCtx = await playwright.request.newContext({ baseURL: ADMIN, storageState: path.join(STATE_DIR, 'admin-api.json') });
  const pageCtx = await playwright.request.newContext({ baseURL: ADMIN, storageState: path.join(STATE_DIR, 'admin-page.json') });
  const kiosk = await playwright.request.newContext({ baseURL: KIOSK });
  boom.borne = await send(kiosk, 'GET', `${KIOSK}/api/products`);
  boom.borne.how = 'base de donnees arretee sur la pile de test, GET /api/products';
  boom.api = await send(apiCtx, 'GET', `${ADMIN}/admin/api/products`);
  boom.api.how = 'base de donnees arretee sur la pile de test, GET /admin/api/products';
  boom.html = await send(pageCtx, 'GET', `${ADMIN}/admin/products`);
  boom.html.how = 'base de donnees arretee sur la pile de test, GET /admin/products';
  const prev = JSON.parse(fs.readFileSync(OUT, 'utf8'));
  prev.boom = boom;
  fs.writeFileSync(OUT, JSON.stringify(prev, null, 1) + '\n');
}

function write(routes) {
  const missing = routes.filter((r) => !store.routes[`${r.m} ${r.p}`] || !store.routes[`${r.m} ${r.p}`].ok).map((r) => `${r.m} ${r.p}`);
  const doc = {
    captured_at: new Date().toISOString(),
    commit: process.env.HEALTH_CAPTURE_COMMIT || null,
    where: 'pile Docker jetable, donnees de demonstration du depot (tests/e2e/run-health-capture.sh)',
    routes: store.routes,
    missing_ok: missing,
  };
  fs.mkdirSync(path.dirname(OUT), { recursive: true });
  fs.writeFileSync(OUT, JSON.stringify(doc, null, 1) + '\n');
}
