/*
 * Tests de health.js — page "Santé de l'API" (back-office), node:test + jsdom.
 *
 * health.js est du CommonJS (admin = racine CommonJS, comme product-recipe.js/
 * stock-thresholds.js) : import par défaut, fonctions pures exportées à côté de
 * init(doc) pour être testées sans DOM ni fetch réel.
 *
 * fetch est mocké via `global.fetch` (même technique que tests/js/data.test.js,
 * qui teste le front borne) : health.js appelle le `fetch` global directement,
 * jamais un paramètre injecté, pour rester cohérent avec cette convention.
 *
 * Fixture ROUTES : six routes couvrant les trois surfaces (borne/api/bo), avec
 * et sans code personnel, un pin='price' (conditionnel), un pin='always'
 * (jamais conditionnel), et une route re='password' sans pin. Formes EXACTES
 * de App\Health\RouteMap::rows() (mêmes clés que scratchpad/routes-final.json).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

import health from '../../src/public/admin/assets/js/health.js';

const ROUTES = [
    // 0 — borne, lecture publique, sans permission ni code personnel.
    { m: 'GET', p: '/api/products', c: 'Product', a: 'index', s: 'borne', anon: true, perm: null, w: false, csrf: null, pin: null, re: null, f: 'json', g: 'Catalogue' },
    // 1 — borne, écriture publique (création de commande).
    { m: 'POST', p: '/api/orders', c: 'Order', a: 'create', s: 'borne', anon: true, perm: null, w: true, csrf: null, pin: null, re: null, f: 'json', g: 'Commandes' },
    // 2 — API d'administration, lecture authentifiée avec permission, sans jeton ni pin.
    { m: 'GET', p: '/admin/api/products', c: 'ProductApi', a: 'apiIndex', s: 'api', anon: false, perm: 'product.read', w: false, csrf: null, pin: null, re: null, f: 'json', g: 'Produits et recettes' },
    // 3 — API d'administration, écriture avec jeton anti-rejeu et pin='price' (conditionnel).
    { m: 'PUT', p: '/admin/api/products/{id}', c: 'ProductApi', a: 'apiUpdate', s: 'api', anon: false, perm: 'product.update', w: true, csrf: 'header', pin: 'price', re: null, f: 'json', g: 'Produits et recettes' },
    // 4 — Back-office, écriture avec pin='always' (jamais conditionnel).
    { m: 'POST', p: '/admin/users', c: 'User', a: 'store', s: 'bo', anon: false, perm: 'user.create', w: true, csrf: 'form', pin: 'always', re: null, f: 'redirect', g: 'Comptes' },
    // 5 — Back-office, sans pin, mais re='password' (redéfinition du code personnel).
    { m: 'POST', p: '/admin/profile/pin', c: 'Profile', a: 'updatePin', s: 'bo', anon: false, perm: null, w: true, csrf: 'form', pin: null, re: 'password', f: 'redirect', g: 'Connexion et compte' },
];

function ids(steps) {
    return steps.map(function (s) { return s.id; });
}

function stepById(steps, id) {
    return steps.filter(function (s) { return s.id === id; })[0];
}

/* ============================================================
 * Bloc 3 — construction des étapes (stations), un test par surface
 * ============================================================ */

test('stations() : route borne publique, sans permission ni code personnel', () => {
    const steps = health.stations(ROUTES[0], ROUTES);
    // Le contrôleur frontal ('front') est traversé par TOUTE requête, borne comprise :
    // il n'y a qu'un seul point d'entrée PHP. Seuls Apache et le contrôle CORS
    // diffèrent selon la surface.
    assert.deepEqual(ids(steps), ['client', 'traefik', 'apache', 'front', 'cors', 'router', 'db', 'resp']);
    // Sans code personnel : aucune étape 'pin' ni 'reauth'.
    assert.equal(stepById(steps, 'pin'), undefined);
    assert.equal(stepById(steps, 'reauth'), undefined);
    // Note métier réelle sur /api/products (catalogue commandable uniquement).
    assert.match(stepById(steps, 'db').does, /Seul le commandable est servi/);
});

test('stations() : route borne publique en écriture (création de commande)', () => {
    const steps = health.stations(ROUTES[1], ROUTES);
    assert.deepEqual(ids(steps), ['client', 'traefik', 'apache', 'front', 'cors', 'router', 'valid', 'db', 'resp']);
    assert.match(stepById(steps, 'valid').fail.code, /PRODUCT_UNAVAILABLE/);
    assert.match(stepById(steps, 'db').does, /clé d'idempotence est unique en base/);
});

test("stations() : route API d'administration authentifiée, refus possible à l'étape session", () => {
    const steps = health.stations(ROUTES[2], ROUTES);
    assert.deepEqual(ids(steps), ['client', 'traefik', 'apache', 'front', 'router', 'session', 'perm', 'db', 'resp']);
    const sessionStep = stepById(steps, 'session');
    assert.equal(sessionStep.fail.status, 401);
    assert.equal(sessionStep.fail.code, 'AUTH_REQUIRED');
    // pas de pin, pas de reauth sur cette route.
    assert.equal(stepById(steps, 'pin'), undefined);
});

test("stations() : route API avec pin='price' — le code personnel n'est exigé QUE si le prix ou la TVA changent", () => {
    const steps = health.stations(ROUTES[3], ROUTES);
    assert.ok(ids(steps).indexOf('csrf') >= 0, "le jeton anti-rejeu doit apparaître (r.csrf='header')");
    assert.equal(stepById(steps, 'csrf').fail.code, 'CSRF_INVALID');
    const pinStep = stepById(steps, 'pin');
    assert.ok(pinStep, "l'étape 'pin' doit exister (r.pin='price')");
    assert.match(pinStep.does, /^Exigé seulement si le prix ou le taux de TVA change\./);
    assert.equal(pinStep.fail.code, 'PIN_INVALID');
    // La validation précède le code personnel, qui précède la base de données.
    const order = ids(steps);
    assert.ok(order.indexOf('valid') < order.indexOf('pin') && order.indexOf('pin') < order.indexOf('db'));
});

test("stations() : route back-office avec pin='always' — jamais conditionnel", () => {
    const steps = health.stations(ROUTES[4], ROUTES);
    const pinStep = stepById(steps, 'pin');
    assert.ok(pinStep, "l'étape 'pin' doit exister (r.pin='always')");
    assert.ok(!pinStep.does.startsWith('Exigé seulement'), "un pin='always' ne porte pas la clause conditionnelle du prix/TVA");
    assert.match(pinStep.does, /Email et code à quatre chiffres/);
});

test("stations() : re='password' sans pin — étape 'reauth' présente, 'pin' absente", () => {
    const steps = health.stations(ROUTES[5], ROUTES);
    assert.equal(stepById(steps, 'pin'), undefined);
    const reauth = stepById(steps, 'reauth');
    assert.ok(reauth, "l'étape 'reauth' doit exister (r.re='password')");
    assert.match(reauth.does, /redemande le mot de passe/);
});

/* ============================================================
 * Bloc 3 — point de refus (failIndex), boom prioritaire sur l'étape db
 * ============================================================ */

test('failIndex() : une exception simulée (boom) désigne toujours l\'étape "db", même avec un autre refus choisi', () => {
    const steps = health.stations(ROUTES[3], ROUTES);
    const withFail = health.failIndex(steps, 'perm', false);
    assert.equal(steps[withFail].id, 'perm');
    const withBoom = health.failIndex(steps, 'perm', true);
    assert.equal(steps[withBoom].id, 'db');
});

test('failIndex() : aucun refus choisi -> -1 (trajet complet)', () => {
    const steps = health.stations(ROUTES[0], ROUTES);
    assert.equal(health.failIndex(steps, null, false), -1);
});

/* ============================================================
 * Bloc 3 — simulation d'un refus à une étape donnée, au niveau DOM
 * ============================================================ */

function setupTrajetDom() {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body>' +
        '<div class="health-page">' +
        '  <div id="health-presets"></div>' +
        '  <div id="health-routebar"></div>' +
        '  <div class="health-controls">' +
        '    <button id="health-play" type="button">Lancer</button>' +
        '    <button id="health-step-btn" type="button">Étape suivante</button>' +
        '    <button id="health-reset" type="button">Recommencer</button>' +
        '    <button id="health-boom" type="button" aria-pressed="false">Simuler une exception</button>' +
        '  </div>' +
        '  <ol id="health-rail"></ol>' +
        '  <span id="health-status">—</span>' +
        '  <span id="health-respwhere"></span>' +
        '  <pre id="health-respbody"></pre>' +
        '  <div id="health-notes"></div>' +
        '</div>' +
        '</body></html>',
    );
    return dom.window.document;
}

test("wireTrajet : un refus simulé à l'étape 'session' fige cette étape, saute la suite, et affiche le bon statut/corps", () => {
    const doc = setupTrajetDom();
    const trajet = health.wireTrajet(doc, ROUTES);

    // Route 2 (API authentifiée, avec permission) : refus simulé à l'étape session.
    trajet.loadRoute(2, { fail: 'session' });

    const steps = doc.querySelectorAll('#health-rail .health-step');
    const sessionLi = doc.querySelector('[data-step-id="session"]');
    const permLi = doc.querySelector('[data-step-id="perm"]');
    assert.ok(sessionLi.classList.contains('health-step--failed'), "l'étape 'session' doit porter la classe failed");
    assert.ok(permLi.classList.contains('health-step--skipped'), "l'étape suivante ('perm') doit être marquée sautée");
    assert.ok(steps[0].classList.contains('health-step--passed'), "les étapes avant le refus sont marquées passées");

    assert.match(doc.getElementById('health-status').textContent, /^401\b/);
    const body = doc.getElementById('health-respbody').textContent;
    assert.match(body, /AUTH_REQUIRED/);
    assert.match(body, /Authentification requise/);
});

test('wireTrajet : sans refus choisi, le trajet complet affiche la réponse de succès', () => {
    const doc = setupTrajetDom();
    const trajet = health.wireTrajet(doc, ROUTES);

    trajet.loadRoute(0, {});

    assert.match(doc.getElementById('health-status').textContent, /^200\b/);
    const allSteps = doc.querySelectorAll('#health-rail .health-step');
    allSteps.forEach(function (li) {
        assert.ok(li.classList.contains('health-step--passed'), 'chaque étape doit être marquée passée sur un trajet complet');
    });
});

/* ============================================================
 * Bloc 4 — filtres de la carte des routes
 * ============================================================ */

test('matchesFilters() : surface, méthode, recherche texte et "sous code personnel"', () => {
    const all = { surface: 'all', methods: ['GET', 'POST', 'PUT', 'DELETE'], query: '', onlyPin: false };
    assert.equal(health.matchesFilters(ROUTES[0], all), true);

    const onlyBorne = { surface: 'borne', methods: ['GET', 'POST', 'PUT', 'DELETE'], query: '', onlyPin: false };
    assert.equal(health.matchesFilters(ROUTES[0], onlyBorne), true);
    assert.equal(health.matchesFilters(ROUTES[2], onlyBorne), false);

    const onlyGet = { surface: 'all', methods: ['GET'], query: '', onlyPin: false };
    assert.equal(health.matchesFilters(ROUTES[0], onlyGet), true);
    assert.equal(health.matchesFilters(ROUTES[1], onlyGet), false);

    const withPin = { surface: 'all', methods: ['GET', 'POST', 'PUT', 'DELETE'], query: '', onlyPin: true };
    assert.equal(health.matchesFilters(ROUTES[3], withPin), true, "pin='price' compte comme sous code personnel");
    assert.equal(health.matchesFilters(ROUTES[2], withPin), false, 'sans pin, exclue par le filtre "sous code personnel"');

    const searchPerm = { surface: 'all', methods: ['GET', 'POST', 'PUT', 'DELETE'], query: 'product.update', onlyPin: false };
    assert.equal(health.matchesFilters(ROUTES[3], searchPerm), true);
    assert.equal(health.matchesFilters(ROUTES[0], searchPerm), false);
});

test('filterRoutes() : retourne les index (alignés sur la liste des routes), pas les objets', () => {
    const kept = health.filterRoutes(ROUTES, { surface: 'api', methods: ['GET', 'POST', 'PUT', 'DELETE'], query: '', onlyPin: false });
    assert.deepEqual(kept, [2, 3]);
});

function setupRoutesMapDom() {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body>' +
        '<div class="health-page">' +
        '  <input type="radio" name="health-surf" id="health-surf-all" value="all" checked>' +
        '  <input type="radio" name="health-surf" id="health-surf-borne" value="borne">' +
        '  <input type="radio" name="health-surf" id="health-surf-api" value="api">' +
        '  <input type="radio" name="health-surf" id="health-surf-bo" value="bo">' +
        '  <input type="checkbox" id="health-m-GET" value="GET" checked>' +
        '  <input type="checkbox" id="health-m-POST" value="POST" checked>' +
        '  <input type="checkbox" id="health-m-PUT" value="PUT" checked>' +
        '  <input type="checkbox" id="health-m-DELETE" value="DELETE" checked>' +
        '  <input type="search" id="health-q">' +
        '  <input type="checkbox" id="health-onlypin">' +
        '  <span id="health-count"></span>' +
        '  <div id="health-routes-groups"></div>' +
        '</div>' +
        '</body></html>',
    );
    return dom.window.document;
}

test('wireRoutesMap : changer le filtre de surface réduit la liste affichée', () => {
    const doc = setupRoutesMapDom();
    health.wireRoutesMap(doc, ROUTES, function () {});

    assert.equal(doc.querySelectorAll('#health-routes-groups [data-ri]').length, ROUTES.length);

    const radio = doc.getElementById('health-surf-borne');
    radio.checked = true;
    radio.dispatchEvent(new doc.defaultView.Event('change', { bubbles: true }));

    const rows = doc.querySelectorAll('#health-routes-groups [data-ri]');
    assert.equal(rows.length, 2);
});

test('wireRoutesMap : cliquer une route notifie onPick avec son index et marque aria-current', () => {
    const doc = setupRoutesMapDom();
    let picked = null;
    health.wireRoutesMap(doc, ROUTES, function (ri) { picked = ri; });

    const firstRoute = doc.querySelector('#health-routes-groups [data-ri]');
    firstRoute.click();

    assert.equal(picked, +firstRoute.getAttribute('data-ri'));
    assert.equal(firstRoute.getAttribute('aria-current'), 'true');
});

/* ============================================================
 * Bloc 2 — construction de la requête d'une sonde
 * ============================================================ */

test("buildProbeRequest() : l'en-tête X-CSRF-Token n'apparaît que si sendCsrf est vrai", () => {
    const withoutCsrf = { method: 'PUT', url: '/admin/api/roles/0', credentials: 'include', sendCsrf: false, contentType: 'application/json', body: '{}' };
    const req1 = health.buildProbeRequest(withoutCsrf, 'tok-123');
    assert.equal(req1.init.headers['X-CSRF-Token'], undefined);
    assert.equal(req1.init.credentials, 'include');
    assert.equal(req1.init.body, '{}');
    assert.equal(req1.init.headers['Content-Type'], 'application/json');

    const withCsrf = { method: 'PUT', url: '/admin/api/roles/0', credentials: 'include', sendCsrf: true, contentType: 'text/plain', body: 'x' };
    const req2 = health.buildProbeRequest(withCsrf, 'tok-123');
    assert.equal(req2.init.headers['X-CSRF-Token'], 'tok-123');
    assert.equal(req2.init.headers['Content-Type'], 'text/plain');
});

test('buildProbeRequest() : une sonde sans corps (GET) ne pose pas de clé body', () => {
    const probe = { method: 'GET', url: '/api/health', credentials: 'include', sendCsrf: false, contentType: null, body: null };
    const req = health.buildProbeRequest(probe, '');
    assert.equal('body' in req.init, false);
    assert.equal(req.init.credentials, 'include');
});

test('buildProbeRequest() : credentials "omit" est respecté (sonde "session")', () => {
    const probe = { method: 'GET', url: '/admin/api/products', credentials: 'omit', sendCsrf: false, contentType: null, body: null };
    const req = health.buildProbeRequest(probe, '');
    assert.equal(req.init.credentials, 'omit');
});

/* ============================================================
 * Bloc 2 — comparaison statut obtenu / attendu
 * ============================================================ */

test('probeMatches() : conforme seulement si le statut ET (si attendu) le code correspondent', () => {
    const withCode = { expect: 403, expectCode: 'CSRF_INVALID' };
    assert.equal(health.probeMatches(withCode, 403, 'CSRF_INVALID'), true);
    assert.equal(health.probeMatches(withCode, 403, 'FORBIDDEN'), false, 'même statut mais mauvais code -> inattendu');
    assert.equal(health.probeMatches(withCode, 200, 'CSRF_INVALID'), false, 'mauvais statut -> inattendu');

    const successOnly = { expect: 200, expectCode: null };
    assert.equal(health.probeMatches(successOnly, 200, null), true);
    assert.equal(health.probeMatches(successOnly, 201, null), false);
});

/* ============================================================
 * Bloc 2 — exécution réelle d'une sonde (fetch mocké)
 * ============================================================ */

function setupProbeDom(probe) {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body>' +
        '<ul id="health-probes">' +
        '  <li data-probe-id="' + probe.id + '"><p data-probe-result>Pas encore lancé.</p></li>' +
        '</ul>' +
        '</body></html>',
    );
    return dom.window.document;
}

test('runProbe() : un résultat conforme est rendu comme tel', async () => {
    const probe = { id: 'sante', method: 'GET', url: '/api/health', credentials: 'include', sendCsrf: false, contentType: null, body: null, expect: 200, expectCode: null };
    const doc = setupProbeDom(probe);
    global.fetch = async () => ({ ok: true, status: 200, json: async () => ({ data: { status: 'ok' } }) });

    const outcome = await health.runProbe(doc, probe, 'tok');
    assert.equal(outcome.conforme, true);
    assert.equal(outcome.status, 200);
    const text = doc.querySelector('[data-probe-result]').textContent;
    assert.match(text, /conforme/);
    assert.ok(!/inattendu/.test(text));
});

test('runProbe() : un résultat inattendu est signalé de façon visible (jamais masqué)', async () => {
    const probe = { id: 'jeton', method: 'PUT', url: '/admin/api/roles/0', credentials: 'include', sendCsrf: false, contentType: 'application/json', body: '{}', expect: 403, expectCode: 'CSRF_INVALID' };
    const doc = setupProbeDom(probe);
    // Le serveur répond 200 au lieu du 403 attendu : anomalie réelle, doit être visible.
    global.fetch = async () => ({ ok: true, status: 200, json: async () => ({ data: {} }) });

    const outcome = await health.runProbe(doc, probe, 'tok');
    assert.equal(outcome.conforme, false);
    const el = doc.querySelector('[data-probe-result]');
    assert.match(el.textContent, /inattendu/);
    assert.ok(el.className.indexOf('health-probe-result--unexpected') >= 0);
});

/* ============================================================
 * Bloc 1 — état en direct : applyReport() et relecture (pollHealth)
 * ============================================================ */

function setupReportDom() {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body>' +
        '<span id="health-db-pill"></span><p id="health-db-detail"></p>' +
        '<span id="health-debug-pill"></span><p id="health-debug-detail"></p>' +
        '<span id="health-version"></span><p id="health-deployed-at"></p>' +
        '<span id="health-migrations"></span><p id="health-migrations-last"></p>' +
        '<span id="health-seeds"></span><p id="health-seeds-last"></p>' +
        '<span id="health-activity"></span><p id="health-activity-detail"></p>' +
        '<p id="health-refresh-status">x</p><time id="health-refresh-time"></time>' +
        '<p id="health-refresh-error" hidden></p>' +
        '</body></html>',
    );
    return dom.window.document;
}

const REPORT_OK = {
    generated_at: '2026-09-27T14:02:11+02:00', version: 'abc123', deployed_at: '2026-09-27T09:40:00+02:00',
    app_env: 'production', debug: false, display_errors_off: true, php_version: '8.3.22',
    db: { ok: true, latency_ms: 1.8, server_version: '11.4.x-MariaDB' },
    migrations: { applied: 17, files: 17, last: '0017_ingredient_family.sql' },
    seeds: { applied: 10, files: 10, last: '0010_ingredient_families.sql' },
    activity_24h: { orders_created: 12, orders_paid: 10, audit_lines: 4, pin_failures: 1 },
};

const REPORT_DB_DOWN = {
    generated_at: '2026-09-27T14:05:00+02:00', version: 'abc123', deployed_at: '2026-09-27T09:40:00+02:00',
    app_env: 'production', debug: false, display_errors_off: true, php_version: '8.3.22',
    db: { ok: false, latency_ms: null, server_version: null },
    migrations: { applied: null, files: null, last: null },
    seeds: { applied: null, files: null, last: null },
    activity_24h: { orders_created: null, orders_paid: null, audit_lines: null, pin_failures: null },
};

test('applyReport() : écrit les valeurs (base joignable, affichage des erreurs coupé, version)', () => {
    const doc = setupReportDom();
    health.applyReport(doc, REPORT_OK);

    assert.equal(doc.getElementById('health-db-pill').textContent, 'Joignable');
    assert.ok(doc.getElementById('health-db-pill').className.indexOf('pill-success') >= 0);
    assert.equal(doc.getElementById('health-debug-pill').textContent, 'Coupé');
    assert.equal(doc.getElementById('health-version').textContent, 'abc123');
    assert.equal(doc.getElementById('health-migrations').textContent, '17 / 17');
    assert.equal(doc.getElementById('health-activity').textContent, '12 créées');
});

test('applyReport() : base injoignable -> pastille rouge ET texte différent (pas seulement la couleur)', () => {
    const doc = setupReportDom();
    health.applyReport(doc, REPORT_DB_DOWN);

    const pill = doc.getElementById('health-db-pill');
    assert.equal(pill.textContent, 'Injoignable');
    assert.ok(pill.className.indexOf('pill-danger') >= 0);
    assert.equal(doc.getElementById('health-activity').textContent, 'indisponible');
    assert.match(doc.getElementById('health-migrations').textContent, /nombre de fichiers inconnu/);
});

test('pollHealth() : une lecture réussie met à jour l\'horodatage et efface un échec précédent', async () => {
    const doc = setupReportDom();
    global.fetch = async () => ({ ok: true, status: 200, json: async () => ({ data: REPORT_OK }) });

    const ok = await health.pollHealth(doc);
    assert.equal(ok, true);
    assert.equal(doc.getElementById('health-version').textContent, 'abc123');
    assert.notEqual(doc.getElementById('health-refresh-time').textContent, '');
    assert.equal(doc.getElementById('health-refresh-error').hidden, true);
});

test('pollHealth() : un échec de relecture fige les valeurs affichées et le dit clairement (jamais un faux "tout va bien")', async () => {
    const doc = setupReportDom();

    // Première relecture réussie : la version affichée devient "abc123".
    global.fetch = async () => ({ ok: true, status: 200, json: async () => ({ data: REPORT_OK }) });
    await health.pollHealth(doc);
    assert.equal(doc.getElementById('health-version').textContent, 'abc123');

    // Deuxième relecture : la requête échoue (réseau). La valeur affichée NE DOIT PAS bouger.
    global.fetch = async () => { throw new Error('network down'); };
    const ok = await health.pollHealth(doc);

    assert.equal(ok, false);
    assert.equal(doc.getElementById('health-version').textContent, 'abc123', 'la valeur précédente reste affichée, jamais écrasée par du vide');
    const err = doc.getElementById('health-refresh-error');
    assert.equal(err.hidden, false);
    assert.match(err.textContent, /Échec de la relecture/);
    const status = doc.getElementById('health-refresh-status');
    assert.ok(status.className.indexOf('health-refresh-status--error') >= 0);
});

test('pollHealth() : une réponse HTTP non-2xx est aussi un échec (pas seulement une exception réseau)', async () => {
    const doc = setupReportDom();
    global.fetch = async () => ({ ok: false, status: 500, json: async () => null });

    const ok = await health.pollHealth(doc);
    assert.equal(ok, false);
    assert.equal(doc.getElementById('health-refresh-error').hidden, false);
});
