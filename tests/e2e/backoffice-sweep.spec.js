// Balayage du back-office : pour chaque role, chaque page atteignable depuis sa
// navigation reelle et chaque largeur d'ecran, on verifie que rien ne se chevauche,
// que rien ne deborde, que tout fonctionne, et que les actions principales ont leur
// effet a l'ecran avec leur message de confirmation.
//
// Lancement (pile JETABLE, jamais la production) :
//   _byan-output/outils/e2e.sh <depot> <nom-unique> tests/e2e/backoffice-sweep.spec.js
//
// Resultats : rapport.md, tableau-complet.csv, resultats.json et une capture par
// echec dans SWEEP_DIR (par defaut _byan-output/dossier-soutenance/annexes/ux-backoffice/sweep
// sous la racine du depot, dossier non versionne). Captures de reference (temoin avant
// et apres la refonte visuelle) dans SWEEP_DIR/reference/<role>/.
//
// Les echecs actuels sont ATTENDUS (bugs connus, voir rapport.md de l'audit UX) : le
// test les montre, il ne les masque pas. Il est donc rouge tant qu'ils existent.
//
// Options (variables d'environnement) :
//   SWEEP_ROLES=admin,cuisine   ne balaie que ces roles (defaut : tous)
//   SWEEP_MAX_PAGES=10          plafond de pages par role (defaut : 80)
//   SWEEP_COMPARE=1             compare aux captures de reference au lieu de les
//                               reecrire (desactive par defaut : les donnees de la pile
//                               changent d'une execution a l'autre, la comparaison est
//                               un outil ponctuel de la refonte, pas un filet de CI)
//   SWEEP_REFERENCE_DIR=<dir>   dossier de reference a comparer (defaut SWEEP_DIR/reference)
//   SWEEP_MAX_DIFF=0.02         part de pixels differents toleree en comparaison
//
// Comptes : le seed de demonstration n'a que l'administrateur. Le test cree lui-meme,
// par l'interface et sur la pile jetable, un compte par role et le PIN de chacun. Mot
// de passe et PIN de ces comptes crees sont tires au hasard a chaque execution (voir
// randomPassword/randomPin plus bas) : aucune valeur de compte cree ne doit etre ecrite
// en dur (le scan de secrets, gitleaks, lit tout l'historique git et signale toute
// chaine qui ressemble a un mot de passe litteral, meme dans un fichier de test).
// Le compte admin, lui, preexiste dans le seed (db/seeds/0001_rbac_and_reference.sql) :
// son mot de passe reel est fixe par ce seed, donc lu depuis une variable d'environnement
// (ADMIN_EMAIL/ADMIN_PASSWORD, meme nom que a11y.spec.js et rbac-channel.spec.js) avec un
// repli qui correspond a la valeur documentee dans ce seed (commentaire "DEV password",
// db/seeds/0001_rbac_and_reference.sql) : la pile jetable par defaut (e2e.sh, sans
// variable particuliere) reste utilisable tel quel.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const audit = require('./backoffice-sweep/audit-page');
const { writeReport } = require('./backoffice-sweep/report');

const HOST = 'http://admin.wakdo.test';
const REPO = path.resolve(__dirname, '..', '..');
const OUT = process.env.SWEEP_DIR
  || path.join(REPO, '_byan-output', 'dossier-soutenance', 'annexes', 'ux-backoffice', 'sweep');
const RESULTS = path.join(OUT, 'resultats.jsonl');
const CAPTURES = path.join(OUT, 'captures');
const REFERENCE = path.join(OUT, 'reference');
const COMPARE = process.env.SWEEP_COMPARE === '1';
const REFERENCE_SOURCE = process.env.SWEEP_REFERENCE_DIR || REFERENCE;
const CURRENT = path.join(OUT, 'comparaison', 'actuel');
const DIFFS = path.join(OUT, 'comparaison', 'ecarts');
const MAX_DIFF = Number(process.env.SWEEP_MAX_DIFF || '0.02');
const MAX_PAGES = Number(process.env.SWEEP_MAX_PAGES || '80');
const ONLY_ROLES = (process.env.SWEEP_ROLES || '').split(',').map((s) => s.trim()).filter(Boolean);
// Suffixe propre a l'execution : les elements crees ne se heurtent pas a ceux d'une
// execution precedente si la pile est reutilisee.
const RUN = Date.now().toString(36);

// Largeurs demandees : ordinateur, petite tablette paysage, tablette portrait, telephone.
// ref : capture de reference ; tab : parcours au clavier (Tab), fait aux trois largeurs
// de reference pour borner la duree (1024 px suit la meme mise en page que 1366 px).
const VIEWPORTS = [
  { name: '1366x768', width: 1366, height: 768, ref: true, tab: true },
  { name: '1024x768', width: 1024, height: 768, ref: false, tab: false },
  { name: '768x1024', width: 768, height: 1024, ref: true, tab: true },
  { name: '390x844', width: 390, height: 844, ref: true, tab: true },
];

// Compte admin (preexistant, seed 0001) : email/mot de passe reels, pas des identifiants
// de compte cree par ce test — lus depuis l'environnement, repli sur la valeur du seed.
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'admin@wakdo.local';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'WakdoAdmin2026!';

// Mot de passe (>= 8 caracteres, seule regle du formulaire "Nouvel utilisateur",
// src/app/Controllers/UserController.php) et PIN (4 chiffres, ctype_digit, comme les
// autres PIN du projet) tires au hasard, pour les comptes que CE TEST cree lui-meme —
// jamais de valeur litterale en dur ici (voir commentaire plus haut).
function randomPassword() {
  return crypto.randomBytes(9).toString('hex');
}
function randomPin() {
  return String(crypto.randomInt(1000, 10000));
}

// Genere une fois, PERSISTE sur disque (sous OUT, deja hors-repo/gitignore comme le
// reste des sorties du balayage) et relue ensuite : Playwright peut redemarrer le
// worker qui execute ce fichier (ex. apres une longue suite de verifications), ce qui
// RE-EXECUTE ce module et regenererait des valeurs differentes de celles deja ecrites
// en base par la premiere execution — la connexion echouerait alors pour un role dont
// le compte a ete cree plus tot dans la meme execution logique du balayage (constate :
// mot de passe change en memoire, mais aucun UserController::update en base — audit_log
// ne montre qu'un auth.login_failed). Le fichier fixe la valeur pour toute la duree de
// vie de la pile, y compris si le worker redemarre ou si la pile est reutilisee d'une
// execution a l'autre (meme principe que « compte deja present » plus bas).
const CREDENTIALS_FILE = path.join(OUT, '.credentials.json');
function createdCredentials() {
  return {
    adminPin: randomPin(),
    responsable: { password: randomPassword(), pin: randomPin() },
    cuisine: { password: randomPassword(), pin: randomPin() },
    comptoir: { password: randomPassword(), pin: randomPin() },
    drive: { password: randomPassword(), pin: randomPin() },
    remplacant: { password: randomPassword(), pin: randomPin() },
  };
}
function loadOrCreateCredentials() {
  if (fs.existsSync(CREDENTIALS_FILE)) {
    try {
      const parsed = JSON.parse(fs.readFileSync(CREDENTIALS_FILE, 'utf8'));
      if (parsed && parsed.responsable && parsed.cuisine && parsed.comptoir && parsed.drive && parsed.remplacant) return parsed;
    } catch (e) {
      // Fichier corrompu ou incomplet : on regenere plutot que de faire echouer le module.
    }
  }
  const fresh = createdCredentials();
  fs.mkdirSync(OUT, { recursive: true });
  fs.writeFileSync(CREDENTIALS_FILE, JSON.stringify(fresh), { mode: 0o600 });
  return fresh;
}
const CREDENTIALS = loadOrCreateCredentials();

const ADMIN_PIN = CREDENTIALS.adminPin;
const ACCOUNTS = {
  admin: { key: 'admin', label: 'Administrateur', email: ADMIN_EMAIL, password: ADMIN_PASSWORD, pin: ADMIN_PIN },
  responsable: { key: 'responsable', label: 'Responsable', email: 'balayage.responsable@wakdo.local', password: CREDENTIALS.responsable.password, pin: CREDENTIALS.responsable.pin, first: 'Rita', last: 'Balayage' },
  cuisine: { key: 'cuisine', label: 'Équipier cuisine', email: 'balayage.cuisine@wakdo.local', password: CREDENTIALS.cuisine.password, pin: CREDENTIALS.cuisine.pin, first: 'Karim', last: 'Balayage' },
  comptoir: { key: 'comptoir', label: 'Équipier comptoir', email: 'balayage.comptoir@wakdo.local', password: CREDENTIALS.comptoir.password, pin: CREDENTIALS.comptoir.pin, first: 'Chloé', last: 'Balayage' },
  drive: { key: 'drive', label: 'Équipier drive', email: 'balayage.drive@wakdo.local', password: CREDENTIALS.drive.password, pin: CREDENTIALS.drive.pin, first: 'Dylan', last: 'Balayage' },
};
// Compte de remplacement dont on reinitialise le PIN (parcours « comptes »).
const SPARE = { key: 'remplacant', label: 'Équipier comptoir', email: 'balayage.remplacant@wakdo.local', password: CREDENTIALS.remplacant.password, pin: CREDENTIALS.remplacant.pin, first: 'Inès', last: 'Remplaçante' };
const ROLE_ORDER = ['admin', 'responsable', 'cuisine', 'comptoir', 'drive'];

// Jetons techniques qui ne doivent jamais etre affiches seuls : codes d'enum de la base
// (db/migrations/0001_init_schema.sql, 0009, 0010), codes de role (seed 0001) et
// references de categorie (seed 0002).
const TECH_CODES = [
  'pending_payment', 'paid', 'preparing', 'ready', 'delivered', 'cancelled', 'dine_in', 'takeaway',
  'kiosk', 'counter', 'drive', 'drink', 'side', 'sauce', 'dessert', 'extra', 'product', 'menu', 'normal', 'maxi',
  'remove', 'add', 'sale', 'cancellation', 'restock', 'inventory_correction', 'adjustment',
  'admin', 'manager', 'kitchen', 'critical', 'low', 'fresh', 'late',
  'menus', 'boissons', 'burgers', 'frites', 'encas', 'wraps', 'salades', 'desserts', 'sauces',
];

// ---------------------------------------------------------------------------------
// Enregistrement des resultats : une ligne JSON par verification, ecrite au fil de
// l'eau (un echec de test relance le processus de test, la memoire ne suffit pas).
// ---------------------------------------------------------------------------------
let current = [];
test.beforeEach(() => { current = []; });

function record(r) {
  const row = {
    role: r.role, page: r.page, url: r.url || r.page, largeur: r.largeur, verification: r.verification,
    ok: !!r.ok, details: (r.details || []).slice(0, 25), capture: r.capture || null,
    categorie: r.categorie || null, extra: r.extra || null,
  };
  fs.mkdirSync(OUT, { recursive: true });
  fs.appendFileSync(RESULTS, JSON.stringify(row) + '\n');
  current.push(row);
  return row;
}

function failureSummary() {
  return current.filter((r) => !r.ok).map((r) => `${r.role} | ${r.page} | ${r.largeur} | ${r.verification} : ${(r.details[0] || '').slice(0, 160)}`);
}

function fileSlug(s) {
  return s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[{}]/g, '').replace(/[^a-zA-Z0-9]+/g, '-').replace(/^-|-$/g, '').toLowerCase();
}

async function failShot(page, parts, check) {
  fs.mkdirSync(CAPTURES, { recursive: true });
  const name = fileSlug(parts.join('__') + '__' + check) + '.png';
  await page.evaluate(audit.highlight, check).catch(() => 0);
  await page.screenshot({ path: path.join(CAPTURES, name), animations: 'disabled', caret: 'hide' }).catch(() => null);
  await page.evaluate(audit.clearHighlight).catch(() => null);
  return 'captures/' + name;
}

// ---------------------------------------------------------------------------------
// Aides de navigation
// ---------------------------------------------------------------------------------
async function login(page, account) {
  await page.goto(`${HOST}/login`);
  await page.fill('#email', account.email);
  await page.fill('#password', account.password);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'load' }),
    page.locator('form[action="/login"] button[type="submit"]').click(),
  ]);
  const at = new URL(page.url()).pathname;
  if (at.startsWith('/login')) {
    const msg = (await page.locator('.alert').first().textContent().catch(() => '')) || '';
    throw new Error(`connexion refusée pour ${account.email} : ${msg.trim()}`);
  }
  return at;
}

async function newRolePage(browser, account) {
  const context = await browser.newContext({ viewport: { width: 1366, height: 768 } });
  const page = await context.newPage();
  await login(page, account);
  return { context, page };
}

/** Soumet l'action sensible ouverte dans le modal PIN et attend la page suivante. */
async function confirmPin(page, account) {
  await expect(page.locator('.pin-modal-overlay.open'), 'le modal PIN s\'ouvre').toBeVisible({ timeout: 5000 });
  await page.fill('#pm-email', account.email);
  await page.fill('#pm-pin', account.pin);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'load' }),
    page.locator('[data-pm-form] button[type="submit"]').click(),
  ]);
}

async function submitAndWait(page, locator) {
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), locator.click()]);
}

async function expectFlash(page, expected) {
  const flash = page.locator('main .flash[role="status"]');
  await expect(flash, 'message de confirmation').toHaveText(expected, { timeout: 5000 });
}

/** Texte court de la page en cas d'echec (ex. « Requête invalide. » sur fond blanc). */
async function pageExcerpt(page) {
  const text = await page.evaluate(() => (document.body ? document.body.innerText : '')).catch(() => '');
  const main = await page.locator('main').first().innerText({ timeout: 500 }).catch(() => null);
  const src = (main || text || '').replace(/\s+/g, ' ').trim();
  return src.slice(0, 220);
}

/**
 * Parcours : chaque etape verifie l'effet a l'ecran et le message ; un echec est
 * enregistre avec sa capture, et le parcours continue a l'etape suivante.
 */
function journey(name, roleKey) {
  return async function step(page, stepName, fn) {
    const base = { role: roleKey, page: 'parcours : ' + name, url: null, largeur: '1366x768', verification: stepName };
    try {
      const note = await fn();
      return record({ ...base, url: new URL(page.url()).pathname, ok: true, details: note ? [String(note)] : [] });
    } catch (error) {
      const message = String(error && error.message ? error.message : error).replace(/\u001b\[[0-9;]*m/g, '').split('\n').filter((l) => l.trim()).slice(0, 4).join(' ').replace(/\s+/g, ' ');
      const excerpt = await pageExcerpt(page);
      const capture = await failShot(page, ['parcours', name, roleKey], stepName);
      return record({
        ...base, url: new URL(page.url()).pathname, ok: false, categorie: 'action sans effet', capture,
        details: [message.slice(0, 400), 'écran : ' + excerpt],
      });
    }
  };
}

function rowOf(page, text) {
  return page.locator('tr', { hasText: text });
}

/**
 * Mise en place de secours : quand le formulaire de creation echoue (bug connu), un
 * produit jetable est cree par l'API JSON d'administration (meme session, meme
 * jeton CSRF) pour que les etapes suivantes (prix, suppression) restent mesurables.
 */
async function apiCreateProduct(page, name) {
  const me = await (await page.request.get(`${HOST}/admin/me`)).json();
  const csrf = (me.data && me.data.csrf_token) || me.csrf_token;
  const cats = await (await page.request.get(`${HOST}/admin/api/categories`)).json();
  const list = Array.isArray(cats.data) ? cats.data : (cats.data && cats.data.items) || [];
  const resp = await page.request.post(`${HOST}/admin/api/products`, {
    headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' },
    data: { category_id: String(list[list.length - 1].id), name, price_cents: 250, vat_rate: '100', display_order: '95', is_available: true },
  });
  if (!resp.ok()) throw new Error(`création de secours par l'API refusée (${resp.status()}) : ${(await resp.text()).slice(0, 160)}`);
  return name;
}

async function tinyPng(page) {
  const dataUrl = await page.evaluate(() => {
    const c = document.createElement('canvas');
    c.width = 240; c.height = 240;
    const g = c.getContext('2d');
    g.fillStyle = '#ffc72c'; g.fillRect(0, 0, 240, 240);
    g.fillStyle = '#da291c'; g.fillRect(60, 60, 120, 120);
    return c.toDataURL('image/png');
  });
  return Buffer.from(dataUrl.split(',')[1], 'base64');
}

/** Ajoute au panier la premiere tuile simple commandable (sans modale). */
async function addFirstTile(page) {
  const tabs = page.locator('#pos-tabs .pos__tab');
  await expect(tabs.first(), 'onglets de catégories de la caisse').toBeVisible({ timeout: 5000 });
  const n = await tabs.count();
  for (let i = 0; i < n; i++) {
    await tabs.nth(i).click();
    const tile = page.locator('#pos-grid .pos-tile:not(.pos-tile--unavailable):not([aria-haspopup])').first();
    if (await tile.count()) {
      const name = (await tile.locator('.pos-tile__name').textContent()).trim();
      await tile.click();
      return name;
    }
  }
  throw new Error('aucune tuile simple commandable sur la caisse');
}

async function placeCounterOrder(page, channel) {
  await page.goto(`${HOST}/${channel}/orders`);
  await submitAndWait(page, page.locator(`a[href="/${channel}/orders/new"]`).first()).catch(async () => {
    await page.goto(`${HOST}/${channel}/orders/new`);
  });
  const product = await addFirstTile(page);
  await expect(page.locator('#order-cart li:not(.order-cart__empty)'), 'ligne ajoutée au panier').toHaveCount(1);
  await expect(page.locator('#order-submit')).not.toHaveText(/0,00/);
  await submitAndWait(page, page.locator('#order-submit'));
  const flash = page.locator('main .flash[role="status"]');
  await expect(flash, 'message de confirmation').toHaveText(/Commande [A-Z]\d+ enregistrée et encaissée\./, { timeout: 5000 });
  const number = ((await flash.textContent()).match(/Commande ([A-Z]\d+)/) || [])[1];
  return { number, product };
}

// ---------------------------------------------------------------------------------
// Balayage d'une page a toutes les largeurs
// ---------------------------------------------------------------------------------
function patternOf(pathname) {
  return pathname
    .split('/')
    .map((seg) => (/^\d+$/.test(seg) ? '{id}' : /^[A-Z]\d+$/.test(seg) ? '{numero}' : seg))
    .join('/');
}

function sameHostPath(href) {
  try {
    if (!href || href.startsWith('#')) return null;
    const u = new URL(href, HOST);
    if (u.host !== new URL(HOST).host) return null;
    if (u.pathname === '/logout' || u.pathname.startsWith('/admin/api')) return null;
    return u.pathname;
  } catch (e) {
    return null;
  }
}

async function referenceShot(page, roleKey, pattern, vp) {
  const file = fileSlug(pattern || 'racine') + '__' + vp.name + '.png';
  const options = { animations: 'disabled', caret: 'hide', mask: [page.locator('#kitchenTime')] };
  if (!COMPARE) {
    fs.mkdirSync(path.join(REFERENCE, roleKey), { recursive: true });
    await page.screenshot({ ...options, path: path.join(REFERENCE, roleKey, file) });
    return null;
  }
  // Comparaison : meme comparateur d'images que toHaveScreenshot (playwright-core).
  const { getComparator } = require('playwright-core/lib/utils');
  const buffer = await page.screenshot(options);
  fs.mkdirSync(path.join(CURRENT, roleKey), { recursive: true });
  fs.writeFileSync(path.join(CURRENT, roleKey, file), buffer);
  const refFile = path.join(REFERENCE_SOURCE, roleKey, file);
  if (!fs.existsSync(refFile)) return { ok: false, details: ['aucune capture de référence : ' + path.relative(OUT, refFile)] };
  const diff = getComparator('image/png')(buffer, fs.readFileSync(refFile), { maxDiffPixelRatio: MAX_DIFF, threshold: 0.2 });
  if (!diff) return { ok: true, details: [] };
  let capture = null;
  if (diff.diff) {
    fs.mkdirSync(path.join(DIFFS, roleKey), { recursive: true });
    fs.writeFileSync(path.join(DIFFS, roleKey, file), diff.diff);
    capture = 'comparaison/ecarts/' + roleKey + '/' + file;
  }
  return { ok: false, details: [String(diff.errorMessage || 'images différentes').split('\n')[0]], capture };
}

/** Declenche l'affichage des messages d'erreur d'un formulaire vide, sans l'envoyer. */
async function triggerFormErrors(page) {
  const handle = await page.evaluateHandle(() => {
    const forms = Array.from(document.querySelectorAll('main form')).filter((f) => f.checkVisibility()
      && f.querySelector('[required]') && !f.checkValidity());
    const form = forms[0];
    if (!form) return null;
    return form.querySelector('button[type="submit"], input[type="submit"]');
  });
  const button = handle.asElement();
  if (!button || !(await button.isVisible())) return false;
  const before = page.url();
  await button.click();
  await page.locator('.form-error:visible').first().waitFor({ timeout: 2000 }).catch(() => null);
  if (page.url() !== before) return false;
  return true;
}

async function sweepPage(page, roleKey, url, state) {
  const pattern = patternOf(url);
  const perPage = { console: [], network: [] };
  let links = [];
  let status = 0;
  for (const vp of VIEWPORTS) {
    const parts = [roleKey, pattern, vp.name];
    const base = { role: roleKey, page: pattern, url, largeur: vp.name };
    state.errors = [];
    state.bad = [];
    await page.setViewportSize({ width: vp.width, height: vp.height });
    const resp = await page.goto(HOST + url, { waitUntil: 'load' });
    status = resp ? resp.status() : 0;
    // Pointeur hors de la page : un survol laisse par un clic precedent fausserait les
    // styles mesures (survol et focus partagent souvent la meme regle CSS).
    await page.mouse.move(0, 0);
    if (vp === VIEWPORTS[0]) {
      await page.waitForLoadState('networkidle', { timeout: 4000 }).catch(() => null);
      if (status === 403 || status === 404) return { status, links: [] };
      const capture = status === 200 ? null : await failShot(page, parts, 'statut HTTP');
      record({ ...base, verification: 'statut HTTP', ok: status === 200, details: status === 200 ? [] : ['réponse ' + status], capture });
    }
    await page.evaluate(() => document.fonts && document.fonts.ready).catch(() => null);

    if (vp.ref) {
      const cmp = await referenceShot(page, roleKey, pattern, vp);
      if (cmp) record({ ...base, verification: 'comparaison référence', ok: cmp.ok, details: cmp.details, capture: cmp.capture || null });
    }

    const layout = await page.evaluate(audit.auditLayout, {});
    for (const [check, res] of Object.entries(layout)) {
      const capture = res.ok ? null : await failShot(page, parts, check);
      const extra = {};
      if (res.exceptions && res.exceptions.length) extra.exceptions = res.exceptions;
      if (res.histogram) { extra.histogram = res.histogram; extra.isolated = res.isolated; extra.scale = res.scale; }
      record({ ...base, verification: check, ok: res.ok, details: res.details, capture, extra: Object.keys(extra).length ? extra : null });
    }

    if (vp === VIEWPORTS[0]) {
      const tech = await page.evaluate(audit.auditTechnicalText, TECH_CODES);
      record({ ...base, verification: 'texte technique', ok: tech.ok, details: tech.details, capture: tech.ok ? null : await failShot(page, parts, 'texte technique') });
      links = await page.$$eval('a[href]', (as) => as.map((a) => a.getAttribute('href')));
    }

    if (vp.tab) {
      await page.evaluate(audit.installFocusRecorder);
      await page.evaluate(() => { if (document.activeElement && document.activeElement.blur) document.activeElement.blur(); window.scrollTo(0, 0); });
      const n = Math.min(await page.evaluate(audit.countTabbables) + 3, 160);
      for (let i = 0; i < n; i++) await page.keyboard.press('Tab');
      const focus = await page.evaluate(audit.analyseFocus);
      record({ ...base, verification: 'ordre de tabulation', ok: focus.order.ok, details: focus.order.details, capture: focus.order.ok ? null : await failShot(page, parts, 'ordre de tabulation'), extra: { steps: focus.steps } });
      record({ ...base, verification: 'focus visible', ok: focus.focus.ok, details: focus.focus.details, capture: focus.focus.ok ? null : await failShot(page, parts, 'focus visible'), extra: { steps: focus.steps } });
    }

    if (vp === VIEWPORTS[0]) {
      const triggered = await triggerFormErrors(page);
      const msgs = await page.evaluate(audit.auditMessages);
      if (msgs.mesures.length) {
        record({
          ...base, verification: 'messages (contraste)', ok: msgs.ok, details: msgs.details,
          capture: msgs.ok ? null : await failShot(page, parts, 'messages (contraste)'),
          extra: { mesures: msgs.mesures, erreursDeclenchees: triggered },
        });
      }
    }
    perPage.console.push(...state.errors.map((e) => vp.name + ' : ' + e));
    perPage.network.push(...state.bad.map((e) => vp.name + ' : ' + e));
  }
  const base = { role: roleKey, page: pattern, url, largeur: 'toutes' };
  record({ ...base, verification: 'console', ok: perPage.console.length === 0, details: Array.from(new Set(perPage.console)) });
  record({ ...base, verification: 'réseau', ok: perPage.network.length === 0, details: Array.from(new Set(perPage.network)) });
  return { status, links };
}

async function sweepRole(page, roleKey) {
  const account = ACCOUNTS[roleKey];
  const state = { errors: [], bad: [] };
  page.on('console', (msg) => { if (msg.type() === 'error') state.errors.push(msg.text().slice(0, 200)); });
  page.on('pageerror', (err) => state.errors.push('exception : ' + String(err.message).slice(0, 200)));
  page.on('response', (resp) => {
    const s = resp.status();
    if (s >= 400 && resp.request().resourceType() !== 'document') state.bad.push(s + ' ' + new URL(resp.url()).pathname);
  });

  const landing = await login(page, account);
  const queue = [landing];
  const visited = new Map();
  const outgoing = new Map();
  const queued = new Set([patternOf(landing)]);
  while (queue.length && visited.size < MAX_PAGES) {
    const url = queue.shift();
    const pattern = patternOf(url);
    if (visited.has(pattern)) continue;
    const res = await sweepPage(page, roleKey, url, state);
    visited.set(pattern, { url, status: res.status });
    const targets = new Set();
    for (const href of res.links) {
      const p = sameHostPath(href);
      if (!p) continue;
      const tp = patternOf(p);
      targets.add(tp);
      if (!queued.has(tp)) { queued.add(tp); queue.push(p); }
    }
    outgoing.set(pattern, targets);
  }
  // Lien refuse : une page du role propose un lien vers une page que ce role ne peut
  // pas ouvrir (403) ou qui n'existe pas (404).
  for (const [pattern, targets] of outgoing) {
    const info = visited.get(pattern);
    if (!info || info.status !== 200) continue;
    const refused = [];
    for (const tp of targets) {
      const t = visited.get(tp);
      if (t && (t.status === 403 || t.status === 404)) refused.push(tp + ' (' + (t.status === 403 ? 'accès refusé' : 'page introuvable') + ')');
    }
    record({ role: roleKey, page: pattern, url: info.url, largeur: 'toutes', verification: 'lien refusé', ok: refused.length === 0, details: refused });
  }
  return { pages: Array.from(visited.entries()).filter(([, v]) => v.status === 200).map(([k]) => k), refused: Array.from(visited.entries()).filter(([, v]) => v.status !== 200).map(([k, v]) => k + ' ' + v.status) };
}

// ---------------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------------
test.describe.configure({ retries: 0 });
// Delais bornes : une action qui n'aboutit pas echoue vite au lieu de bloquer le test.
test.use({ actionTimeout: 8000, navigationTimeout: 15000 });

test('préparation : PIN de l’administrateur, un compte par rôle et le PIN de chacun', async ({ page, browser }) => {
  test.setTimeout(180_000);
  fs.mkdirSync(OUT, { recursive: true });
  fs.rmSync(RESULTS, { force: true });
  fs.rmSync(CAPTURES, { recursive: true, force: true });
  if (COMPARE) fs.rmSync(path.join(OUT, 'comparaison'), { recursive: true, force: true });
  const step = journey('comptes et PIN', 'admin');
  await login(page, ACCOUNTS.admin);

  const setOwnPin = async (p, acc) => {
    await p.goto(`${HOST}/admin/profile/pin`);
    await p.fill('#current_password', acc.password);
    await p.fill('#pin', acc.pin);
    await p.fill('#pin_confirm', acc.pin);
    await submitAndWait(p, p.locator('form[action="/admin/profile/pin"] button[type="submit"]'));
    await expectFlash(p, 'PIN enregistré.');
    await expect(p.locator('main'), 'statut du PIN').toContainText('un PIN est défini');
  };

  await step(page, 'Mon PIN : l’administrateur définit son PIN', () => setOwnPin(page, ACCOUNTS.admin));

  for (const acc of [ACCOUNTS.responsable, ACCOUNTS.cuisine, ACCOUNTS.comptoir, ACCOUNTS.drive, SPARE]) {
    await step(page, `comptes : créer un équipier (${acc.label}, ${acc.key})`, async () => {
      await page.goto(`${HOST}/admin/users`);
      if (await rowOf(page, acc.email).count()) return 'compte déjà présent (pile réutilisée)';
      await submitAndWait(page, page.locator('a[href="/admin/users/new"]'));
      await page.fill('#email', acc.email);
      await page.fill('#first_name', acc.first);
      await page.fill('#last_name', acc.last);
      await page.selectOption('#role_id', { label: acc.label });
      await page.fill('#password', acc.password);
      await page.locator('main form button[type="submit"]').click();
      await confirmPin(page, ACCOUNTS.admin);
      await expectFlash(page, 'Utilisateur créé.');
      await expect(rowOf(page, acc.email), 'le compte apparaît dans la liste').toContainText(acc.label);
      return null;
    });
  }

  for (const acc of [ACCOUNTS.responsable, ACCOUNTS.cuisine, ACCOUNTS.comptoir, ACCOUNTS.drive, SPARE]) {
    const context = await browser.newContext({ viewport: { width: 1366, height: 768 } });
    const p = await context.newPage();
    const own = journey('comptes et PIN', acc.key === 'remplacant' ? 'comptoir' : acc.key);
    await own(p, `Mon PIN : ${acc.label} (${acc.key}) définit son PIN`, async () => {
      await login(p, acc);
      await setOwnPin(p, acc);
    });
    await context.close();
  }
  expect(failureSummary(), 'étapes de préparation en échec').toEqual([]);
});

test('parcours : catalogue (catégorie, produit, menu) par l’administrateur', async ({ browser }) => {
  test.setTimeout(180_000);
  const { context, page } = await newRolePage(browser, ACCOUNTS.admin);
  const step = journey('catalogue', 'admin');
  const catName = `Balayage ${RUN}`;
  let catTarget = catName;

  await step(page, 'catégorie : créer', async () => {
    await page.goto(`${HOST}/admin/categories`);
    await submitAndWait(page, page.locator('a[href="/admin/categories/new"]'));
    await page.fill('#name', catName);
    await page.fill('#slug', `balayage-${RUN}`);
    await page.fill('#display_order', '90');
    await submitAndWait(page, page.locator('main form button[type="submit"]'));
    await expectFlash(page, 'Catégorie créée.');
    await expect(rowOf(page, catName), 'la catégorie apparaît dans la liste').toBeVisible();
  });
  await page.goto(`${HOST}/admin/categories`);
  if (!(await rowOf(page, catName).count())) catTarget = 'Sauces';

  await step(page, 'catégorie : modifier', async () => {
    await page.goto(`${HOST}/admin/categories`);
    await submitAndWait(page, rowOf(page, catTarget).first().locator('a', { hasText: 'Modifier' }));
    const renamed = catTarget === catName ? catName + ' bis' : catTarget;
    await page.fill('#name', renamed);
    await page.fill('#display_order', '91');
    await submitAndWait(page, page.locator('main form button[type="submit"]'));
    await expectFlash(page, 'Catégorie mise à jour.');
    await expect(rowOf(page, renamed).first(), 'la catégorie modifiée apparaît').toBeVisible();
    catTarget = renamed;
  });

  await step(page, 'catégorie : masquer', async () => {
    await page.goto(`${HOST}/admin/categories`);
    await submitAndWait(page, rowOf(page, catTarget).first().locator('button', { hasText: 'Masquer' }));
    await expectFlash(page, 'Catégorie masquée.');
    await expect(rowOf(page, catTarget).first().locator('button', { hasText: 'Afficher' }), 'le bouton devient « Afficher »').toBeVisible();
    await submitAndWait(page, rowOf(page, catTarget).first().locator('button', { hasText: 'Afficher' }));
    await expectFlash(page, 'Catégorie affichée.');
  });

  const plainName = `Balayage produit ${RUN}`;
  const photoName = `Balayage photo ${RUN}`;
  const fillProduct = async (name) => {
    await page.goto(`${HOST}/admin/products`);
    await submitAndWait(page, page.locator('a[href="/admin/products/new"]').first());
    await page.selectOption('#category_id', { index: 1 });
    await page.fill('#name', name);
    await page.fill('#price_cents', '2,50');
    await page.fill('#display_order', '90');
  };
  await step(page, 'produit : créer sans image', async () => {
    await fillProduct(plainName);
    await submitAndWait(page, page.locator('main form button[type="submit"]'));
    await expectFlash(page, 'Produit créé.');
    await expect(rowOf(page, plainName), 'le produit apparaît dans la liste').toBeVisible();
  });
  await step(page, 'produit : créer avec image', async () => {
    const png = await tinyPng(page);
    await fillProduct(photoName);
    await page.setInputFiles('#image_file', { name: 'balayage.png', mimeType: 'image/png', buffer: png });
    await submitAndWait(page, page.locator('main form button[type="submit"]'));
    await expectFlash(page, 'Produit créé.');
    await expect(rowOf(page, photoName), 'le produit apparaît dans la liste').toBeVisible();
    await submitAndWait(page, rowOf(page, photoName).first().locator('a', { hasText: 'Modifier' }));
    await expect(page.locator('#image_path'), 'l’image est enregistrée').not.toHaveValue('');
  });

  await page.goto(`${HOST}/admin/products`);
  let fallbackNote = null;
  if (!(await rowOf(page, plainName).count())) {
    await apiCreateProduct(page, plainName).then(() => {
      fallbackNote = 'produit mis en place par l\'API JSON, la création par le formulaire ayant échoué';
    }).catch((e) => { fallbackNote = String(e.message); });
  }
  const priceTarget = plainName;
  await step(page, 'produit : modifier le prix (PIN)', async () => {
    await page.goto(`${HOST}/admin/products`);
    await submitAndWait(page, rowOf(page, priceTarget).first().locator('a', { hasText: 'Modifier' }));
    await page.fill('#price_cents', '2,90');
    await page.locator('main form button[type="submit"]').click();
    await confirmPin(page, ACCOUNTS.admin);
    await expectFlash(page, 'Produit mis à jour (changement de prix/TVA tracé).');
    await expect(rowOf(page, priceTarget).first(), 'le nouveau prix apparaît').toContainText('2,90');
    return fallbackNote;
  });

  await page.goto(`${HOST}/admin/products`);
  const deleteTarget = (await rowOf(page, photoName).count()) ? photoName : plainName;
  await step(page, 'produit : supprimer (PIN)', async () => {
    await page.goto(`${HOST}/admin/products`);
    const before = await rowOf(page, deleteTarget).count();
    await submitAndWait(page, rowOf(page, deleteTarget).first().locator('a', { hasText: 'Supprimer' }));
    await page.locator('main form button[type="submit"]').click();
    await confirmPin(page, ACCOUNTS.admin);
    await expectFlash(page, 'Produit supprimé.');
    await expect(rowOf(page, deleteTarget), 'le produit disparaît de la liste').toHaveCount(Math.max(0, before - 1));
    return deleteTarget === photoName ? null : fallbackNote;
  });

  const menuName = `Balayage menu ${RUN}`;
  await step(page, 'menu : créer', async () => {
    await page.goto(`${HOST}/admin/menus`);
    await submitAndWait(page, page.locator('a[href="/admin/menus/new"]'));
    await page.selectOption('#category_id', { label: 'Menus' }).catch(() => page.selectOption('#category_id', { index: 1 }));
    await page.selectOption('#burger_product_id', { index: 1 });
    await page.fill('#name', menuName);
    await page.fill('#price_normal_cents', '8,50');
    await page.fill('#price_maxi_cents', '9,50');
    await page.fill('#display_order', '90');
    const slot = page.locator('.slot-block').first();
    await slot.locator('.slot-name').fill('Boisson');
    await slot.locator('.slot-required').check();
    await slot.locator('.slot-option').first().check();
    await submitAndWait(page, page.locator('#menu-form button[type="submit"]'));
    await expectFlash(page, 'Menu créé.');
    await expect(rowOf(page, menuName), 'le menu apparaît dans la liste').toBeVisible();
  });
  await page.goto(`${HOST}/admin/menus`);
  const menuTarget = (await rowOf(page, menuName).count()) ? menuName : null;
  await step(page, 'menu : modifier', async () => {
    await page.goto(`${HOST}/admin/menus`);
    const row = menuTarget ? rowOf(page, menuTarget).first() : page.locator('tbody tr').first();
    await submitAndWait(page, row.locator('a', { hasText: 'Modifier' }));
    const name = await page.inputValue('#name');
    await page.fill('#price_normal_cents', '8,90');
    await submitAndWait(page, page.locator('#menu-form button[type="submit"]'));
    await expectFlash(page, 'Menu mis à jour.');
    await expect(rowOf(page, name).first(), 'le nouveau prix apparaît').toContainText('8,90');
    return menuTarget ? null : 'menu de démonstration utilisé (la création a échoué)';
  });
  await context.close();
  expect(failureSummary(), 'étapes du parcours catalogue en échec').toEqual([]);
});

test('parcours : comptoir, cuisine, remise, annulation et drive', async ({ browser }) => {
  test.setTimeout(180_000);
  const counter = await newRolePage(browser, ACCOUNTS.comptoir);
  const kitchen = await newRolePage(browser, ACCOUNTS.cuisine);
  const drive = await newRolePage(browser, ACCOUNTS.drive);
  const stepC = journey('comptoir et cuisine', 'comptoir');
  const stepK = journey('comptoir et cuisine', 'cuisine');
  const stepD = journey('comptoir et cuisine', 'drive');
  let order = null;

  await stepC(counter.page, 'comptoir : commande (ajout au panier)', async () => {
    await counter.page.goto(`${HOST}/counter/orders`);
    await submitAndWait(counter.page, counter.page.locator('a[href="/counter/orders/new"]').first());
    const product = await addFirstTile(counter.page);
    await expect(counter.page.locator('#order-cart li:not(.order-cart__empty)'), 'ligne ajoutée au panier').toHaveCount(1);
    await expect(counter.page.locator('#order-cart'), 'le panier nomme le produit').toContainText(product);
    await expect(counter.page.locator('#order-submit'), 'le total est mis à jour').not.toHaveText(/ 0,00/);
    return product;
  });
  await stepC(counter.page, 'comptoir : encaissement', async () => {
    await submitAndWait(counter.page, counter.page.locator('#order-submit'));
    const flash = counter.page.locator('main .flash[role="status"]');
    await expect(flash, 'message de confirmation').toHaveText(/Commande C\d+ enregistrée et encaissée\./, { timeout: 5000 });
    order = ((await flash.textContent()).match(/Commande (C\d+)/) || [])[1];
    await expect(rowOf(counter.page, order).first(), 'la commande apparaît dans « En cours »').toBeVisible();
    return order;
  });

  await stepK(kitchen.page, 'cuisine : marquer une commande prête', async () => {
    if (!order) throw new Error('aucune commande encaissée à préparer (étape précédente en échec)');
    await kitchen.page.goto(`${HOST}/kitchen/display`);
    const card = kitchen.page.locator('.kitchen-card', { has: kitchen.page.locator('.kitchen-order-num', { hasText: new RegExp(`^${order}$`) }) });
    await expect(card, 'la commande est affichée en cuisine').toBeVisible();
    await submitAndWait(kitchen.page, card.locator('button', { hasText: 'Prête' }));
    await expectFlash(kitchen.page, 'Commande marquée prête.');
    await expect(card.locator('.kitchen-status'), 'le statut devient « Prête »').toHaveText(/Prête/);
  });

  await stepC(counter.page, 'comptoir : remise au client', async () => {
    if (!order) throw new Error('aucune commande à remettre (étape précédente en échec)');
    await counter.page.goto(`${HOST}/kitchen/display`);
    const card = counter.page.locator('.kitchen-card', { has: counter.page.locator('.kitchen-order-num', { hasText: new RegExp(`^${order}$`) }) });
    await submitAndWait(counter.page, card.locator('button', { hasText: 'Remettre' }));
    await expectFlash(counter.page, 'Commande remise (livrée).');
    await expect(card, 'la commande quitte l’écran cuisine').toHaveCount(0);
  });

  await stepC(counter.page, 'annulation (PIN)', async () => {
    const second = await placeCounterOrder(counter.page, 'counter');
    await counter.page.goto(`${HOST}/admin/orders`);
    await submitAndWait(counter.page, rowOf(counter.page, second.number).first().locator('a', { hasText: 'Annuler' }));
    await counter.page.locator('main form button[type="submit"]').click();
    await confirmPin(counter.page, ACCOUNTS.comptoir);
    await expectFlash(counter.page, 'Commande annulée.');
    await expect(rowOf(counter.page, second.number).first(), 'le statut devient « Annulée »').toContainText('Annulée');
    return second.number;
  });

  await stepD(drive.page, 'drive : commande et encaissement', async () => {
    const d = await placeCounterOrder(drive.page, 'drive');
    if (!/^D/.test(d.number || '')) throw new Error(`commande drive numérotée ${d.number} (attendu D...)`);
    return d.number;
  });
  await Promise.all([counter.context.close(), kitchen.context.close(), drive.context.close()]);
  expect(failureSummary(), 'étapes du parcours comptoir en échec').toEqual([]);
});

test('parcours : stock (inventaire, réapprovisionnement, ajustement, seuils) par le responsable', async ({ browser }) => {
  test.setTimeout(180_000);
  const { context, page } = await newRolePage(browser, ACCOUNTS.responsable);
  const step = journey('stock', 'responsable');
  await page.goto(`${HOST}/admin/ingredients`);
  const lastRow = page.locator('.stock-list__row').last();
  const name = (await lastRow.locator('.stock-list__name').textContent()).trim();
  const row = () => page.locator('.stock-list__row', { has: page.locator('.stock-list__name', { hasText: new RegExp(`^${name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`) }) });
  const qty = async () => {
    const t = (await row().locator('.stock-bar__qty').first().textContent()).trim();
    const m = t.match(/(-?\d+)\s*\/\s*(\d+)/);
    return { q: Number(m[1]), cap: Number(m[2]) };
  };
  const original = await qty();

  await step(page, `ingrédient : inventaire (PIN) sur « ${name} »`, async () => {
    await page.goto(`${HOST}/admin/ingredients`);
    await submitAndWait(page, row().locator('a', { hasText: 'Inventaire' }));
    await page.fill('#actual_quantity', '1');
    await page.fill('#note', 'balayage');
    await page.locator('main form button[type="submit"]').click();
    await confirmPin(page, ACCOUNTS.responsable);
    await expectFlash(page, 'Inventaire enregistré.');
    expect((await qty()).q, 'la quantité affichée suit l’inventaire').toBe(1);
  });

  await step(page, `ingrédient : réapprovisionnement de « ${name} »`, async () => {
    await page.goto(`${HOST}/admin/ingredients`);
    const before = (await qty()).q;
    const card = page.locator('.stock-card', { has: page.locator('.stock-card__name', { hasText: name }) });
    await expect(card, 'l’ingrédient bas apparaît dans « À réapprovisionner »').toBeVisible();
    await submitAndWait(page, card.locator('a', { hasText: 'Réapprovisionner' }));
    await page.fill('#packs', '1');
    await submitAndWait(page, page.locator('main form button[type="submit"]'));
    await expectFlash(page, 'Réapprovisionnement enregistré.');
    expect((await qty()).q, 'la quantité augmente').toBeGreaterThan(before);
  });

  await step(page, `ingrédient : ajustement (PIN) de « ${name} »`, async () => {
    await page.goto(`${HOST}/admin/ingredients`);
    const before = await qty();
    await submitAndWait(page, row().locator('a', { hasText: 'Ajuster' }));
    await page.fill('#delta', '5');
    await page.locator('main form button[type="submit"]').click();
    await confirmPin(page, ACCOUNTS.responsable);
    await expectFlash(page, 'Ajustement de stock enregistré.');
    expect((await qty()).q, 'la quantité augmente de 5 (dans la limite de la capacité)').toBe(Math.min(before.q + 5, before.cap));
  });

  await step(page, `ingrédient : seuils de « ${name} »`, async () => {
    await page.goto(`${HOST}/admin/ingredients`);
    const btn = row().locator('[data-threshold-open]');
    const low = Number(await btn.getAttribute('data-low'));
    const critical = Number(await btn.getAttribute('data-critical'));
    const target = low + 5 <= 95 ? low + 5 : Math.max(critical + 1, low - 5);
    await btn.click();
    await expect(page.locator('[data-threshold-modal]'), 'la fenêtre des seuils s’ouvre').toBeVisible();
    await page.fill('#th-low', String(target));
    await submitAndWait(page, page.locator('[data-threshold-form] button[type="submit"]'));
    await expectFlash(page, 'Seuils mis à jour.');
    await expect(row().locator('[data-threshold-open]'), 'le nouveau seuil est pris en compte').toHaveAttribute('data-low', String(target));
  });

  // Mise en place pour le balayage (hors mesure) : l'ingredient est laisse EN ALERTE,
  // entre ses deux seuils. La section « À réapprovisionner » et la page de
  // reapprovisionnement sont ainsi atteignables par la navigation, sans rendre
  // indisponible le moindre produit (le seuil critique n'est pas franchi).
  await page.goto(`${HOST}/admin/ingredients`);
  const th = row().locator('[data-threshold-open]');
  const lowPct = Number(await th.getAttribute('data-low'));
  const critPct = Number(await th.getAttribute('data-critical'));
  const alertQty = Math.max(1, Math.floor((original.cap * (lowPct + critPct)) / 200));
  await submitAndWait(page, row().locator('a', { hasText: 'Inventaire' })).catch(() => null);
  if (await page.locator('#actual_quantity').count()) {
    await page.fill('#actual_quantity', String(alertQty));
    await page.locator('main form button[type="submit"]').click();
    await confirmPin(page, ACCOUNTS.responsable).catch(() => null);
  }
  await context.close();
  expect(failureSummary(), 'étapes du parcours stock en échec').toEqual([]);
});

test('parcours : comptes (réinitialiser un PIN) et rôles (créer, cocher des permissions)', async ({ browser }) => {
  test.setTimeout(120_000);
  const { context, page } = await newRolePage(browser, ACCOUNTS.admin);
  const step = journey('comptes et rôles', 'admin');

  await step(page, 'comptes : réinitialiser le PIN d’un équipier', async () => {
    await page.goto(`${HOST}/admin/users`);
    await submitAndWait(page, rowOf(page, SPARE.email).first().locator('a', { hasText: 'Réinitialiser le PIN' }));
    await page.locator('main form button[type="submit"]').click();
    await confirmPin(page, ACCOUNTS.admin);
    await expectFlash(page, 'PIN réinitialisé : l\'équipier doit le redéfinir.');
  });
  const spare = await browser.newContext({ viewport: { width: 1366, height: 768 } });
  const sp = await spare.newPage();
  await journey('comptes et rôles', 'comptoir')(sp, 'comptes : l’équipier réinitialisé n’a plus de PIN', async () => {
    await login(sp, SPARE);
    await sp.goto(`${HOST}/admin/profile/pin`);
    await expect(sp.locator('main'), 'statut du PIN après réinitialisation').toContainText('aucun PIN défini');
  });
  await spare.close();

  const roleLabel = `Balayage rôle ${RUN}`;
  const perm = (group, action) => page.locator('.perm-group', { has: page.locator('.perm-group-title', { hasText: new RegExp(`^${group}$`) }) })
    .locator('label.perm-opt', { hasText: new RegExp(`^\\s*${action}\\s*$`) }).locator('input[type="checkbox"]');
  await step(page, 'rôles : créer un rôle en cochant des permissions', async () => {
    await page.goto(`${HOST}/admin/roles`);
    await submitAndWait(page, page.locator('a[href="/admin/roles/new"]'));
    await page.fill('#label', roleLabel);
    await page.fill('#code', `balayage_${RUN}`);
    await perm('Produits', 'Voir').check();
    await perm('Stock', 'Voir').check();
    await page.locator('main form button[type="submit"]').click();
    await confirmPin(page, ACCOUNTS.admin);
    await expectFlash(page, 'Rôle créé.');
    await expect(rowOf(page, roleLabel), 'le rôle apparaît dans la liste').toBeVisible();
  });
  await step(page, 'rôles : les permissions cochées sont enregistrées', async () => {
    await page.goto(`${HOST}/admin/roles`);
    await submitAndWait(page, rowOf(page, roleLabel).first().locator('a', { hasText: 'Modifier' }));
    await expect(perm('Produits', 'Voir'), 'Produits : Voir').toBeChecked();
    await expect(perm('Stock', 'Voir'), 'Stock : Voir').toBeChecked();
    await expect(perm('Produits', 'Supprimer'), 'Produits : Supprimer reste décoché').not.toBeChecked();
  });
  await context.close();
  expect(failureSummary(), 'étapes du parcours comptes et rôles en échec').toEqual([]);
});

test('balayage : pages publiques (connexion, mot de passe oublié)', async ({ page }) => {
  test.setTimeout(300_000);
  const state = { errors: [], bad: [] };
  page.on('console', (msg) => { if (msg.type() === 'error') state.errors.push(msg.text().slice(0, 200)); });
  page.on('pageerror', (err) => state.errors.push('exception : ' + String(err.message).slice(0, 200)));
  page.on('response', (resp) => { if (resp.status() >= 400 && resp.request().resourceType() !== 'document') state.bad.push(resp.status() + ' ' + new URL(resp.url()).pathname); });
  await sweepPage(page, 'public', '/login', state);
  await sweepPage(page, 'public', '/forgot_password', state);
  // Message d'erreur de connexion (identifiants refuses) : affiche et lisible.
  await page.setViewportSize({ width: 1366, height: 768 });
  await page.goto(`${HOST}/login`);
  await page.fill('#email', 'inconnu@wakdo.local');
  await page.fill('#password', 'mauvais-mot-de-passe');
  await submitAndWait(page, page.locator('form[action="/login"] button[type="submit"]'));
  const msgs = await page.evaluate(audit.auditMessages);
  record({
    role: 'public', page: '/login (identifiants refusés)', url: '/login', largeur: '1366x768', verification: 'messages (contraste)',
    ok: msgs.ok && msgs.mesures.length > 0, details: msgs.mesures.length ? msgs.details : ['aucun message d’erreur affiché'],
    capture: msgs.ok && msgs.mesures.length ? null : await failShot(page, ['public', 'login-refus', '1366x768'], 'messages (contraste)'),
    extra: { mesures: msgs.mesures },
  });
  expect(failureSummary(), 'vérifications en échec').toEqual([]);
});

for (const roleKey of ROLE_ORDER) {
  test(`balayage : ${ACCOUNTS[roleKey].label} (${roleKey}), toutes pages, toutes largeurs`, async ({ page }) => {
    test.skip(ONLY_ROLES.length > 0 && !ONLY_ROLES.includes(roleKey), 'rôle exclu par SWEEP_ROLES');
    test.setTimeout(30 * 60_000);
    const res = await sweepRole(page, roleKey);
    fs.writeFileSync(path.join(OUT, `pages-${roleKey}.json`), JSON.stringify(res, null, 2));
    expect(failureSummary(), `vérifications en échec pour ${roleKey}`).toEqual([]);
  });
}

test('rapport : tableau rôle x page x largeur x vérification', async () => {
  const lines = fs.existsSync(RESULTS) ? fs.readFileSync(RESULTS, 'utf8').split('\n').filter(Boolean).map((l) => JSON.parse(l)) : [];
  const summary = writeReport(OUT, lines, { compare: COMPARE, viewports: VIEWPORTS.map((v) => v.name) });
  console.log(summary);
});
