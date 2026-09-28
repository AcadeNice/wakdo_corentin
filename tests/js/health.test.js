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
        '  <li data-probe-id="' + probe.id + '">' +
        '    <button type="button" data-probe-detail-toggle="' + probe.id + '" aria-expanded="false" aria-controls="health-probe-detail-' + probe.id + '">Détails</button>' +
        '    <p data-probe-result>Pas encore lancé.</p>' +
        '    <div id="health-probe-detail-' + probe.id + '" hidden></div>' +
        '  </li>' +
        '</ul>' +
        '</body></html>',
    );
    return dom.window.document;
}

// runProbe() lit desormais le corps en texte (res.text()), une seule fois, pour a la
// fois en extraire le code d'erreur ET l'afficher integralement dans le panneau de
// detail (bloc 2, addendum) : un Response reel ne permet pas de lire .json() PUIS
// .text() sur le meme corps ("body stream already read"). Les mocks fetch portent
// donc `text` (plus `json`, desormais inutilise par runProbe).
test('runProbe() : un résultat conforme est rendu comme tel', async () => {
    const probe = { id: 'sante', method: 'GET', url: '/api/health', credentials: 'include', sendCsrf: false, contentType: null, body: null, expect: 200, expectCode: null };
    const doc = setupProbeDom(probe);
    global.fetch = async () => ({ ok: true, status: 200, headers: { get: () => null }, text: async () => JSON.stringify({ data: { status: 'ok' } }) });

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
    global.fetch = async () => ({ ok: true, status: 200, headers: { get: () => null }, text: async () => JSON.stringify({ data: {} }) });

    const outcome = await health.runProbe(doc, probe, 'tok');
    assert.equal(outcome.conforme, false);
    const el = doc.querySelector('[data-probe-result]');
    assert.match(el.textContent, /inattendu/);
    assert.ok(el.className.indexOf('health-probe-result--unexpected') >= 0);
});

/* ============================================================
 * Bloc 2, addendum — panneau de détail d'une sonde (en-têtes + corps complet)
 * ============================================================ */

test('runProbe() : le panneau de détail reçoit les en-têtes retenus et le corps JSON indenté', async () => {
    const probe = { id: 'sante', method: 'GET', url: '/api/health', credentials: 'include', sendCsrf: false, contentType: null, body: null, expect: 200, expectCode: null };
    const doc = setupProbeDom(probe);
    const headerMap = { 'Content-Type': 'application/json', 'Cache-Control': 'no-store, private' };
    global.fetch = async () => ({
        ok: true, status: 200,
        headers: { get: (name) => (Object.prototype.hasOwnProperty.call(headerMap, name) ? headerMap[name] : null) },
        text: async () => JSON.stringify({ data: { status: 'ok' } }),
    });

    await health.runProbe(doc, probe, 'tok');

    const panel = doc.getElementById('health-probe-detail-sante');
    assert.match(panel.textContent, /Content-Type/);
    assert.match(panel.textContent, /application\/json/);
    const pre = panel.querySelector('pre');
    assert.match(pre.textContent, /"status": "ok"/);
});

test("bascule 'Détails' d'une sonde : aria-expanded et l'attribut hidden du panneau s'inversent", () => {
    const probe = { id: 'sante', method: 'GET', url: '/api/health', credentials: 'include', sendCsrf: false, contentType: null, body: null, expect: 200, expectCode: null };
    const doc = setupProbeDom(probe);
    health.wireProbes(doc, [probe], 'tok');

    const toggle = doc.querySelector('[data-probe-detail-toggle]');
    const panel = doc.getElementById('health-probe-detail-sante');
    assert.equal(panel.hidden, true);
    assert.equal(toggle.getAttribute('aria-expanded'), 'false');

    toggle.click();
    assert.equal(panel.hidden, false);
    assert.equal(toggle.getAttribute('aria-expanded'), 'true');

    toggle.click();
    assert.equal(panel.hidden, true);
    assert.equal(toggle.getAttribute('aria-expanded'), 'false');
});

test('runProbe() : une réponse contenant du HTML injecté (<img onerror>) est affichée comme texte, jamais comme élément', async () => {
    const probe = { id: 'sante', method: 'GET', url: '/api/health', credentials: 'include', sendCsrf: false, contentType: null, body: null, expect: 200, expectCode: null };
    const doc = setupProbeDom(probe);
    const payload = '<img src=x onerror="window.__pwn=true">';
    global.fetch = async () => ({
        ok: true, status: 200, headers: { get: () => null },
        text: async () => JSON.stringify({ data: { name: payload } }),
    });

    await health.runProbe(doc, probe, 'tok');

    assert.equal(doc.querySelectorAll('img').length, 0, 'aucun <img> ne doit être créé à partir du corps de réponse');
    const pre = doc.getElementById('health-probe-detail-sante').querySelector('pre');
    assert.match(pre.textContent, /onerror/, 'le texte brut reste lisible, juste jamais interprété');
});

/* ============================================================
 * Utilitaires partagés de rendu de réponse (en-têtes retenus, corps décrit)
 * ============================================================ */

test('pickHeaders() : ne retient que les en-têtes de la liste blanche, présents et non vides', () => {
    const map = { 'Content-Type': 'application/json', 'Cache-Control': 'no-store, private', 'X-Powered-By': 'PHP/8.3', 'X-Content-Type-Options': '' };
    const headers = { get: (name) => (Object.prototype.hasOwnProperty.call(map, name) ? map[name] : null) };
    const picked = health.pickHeaders(headers);
    assert.deepEqual(picked, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store, private' });
});

test('describeBody() : un corps JSON valide est indenté (JSON.stringify null, 2) et isJson=true', () => {
    const d = health.describeBody('{"data":{"a":1}}');
    assert.equal(d.isJson, true);
    assert.equal(d.pretty, JSON.stringify({ data: { a: 1 } }, null, 2));
    assert.deepEqual(d.json, { data: { a: 1 } });
});

test('describeBody() : un corps non JSON est renvoyé tel quel', () => {
    const d = health.describeBody('<!doctype html><title>x</title>');
    assert.equal(d.isJson, false);
    assert.match(d.pretty, /<!doctype html>/);
});

test('describeBody() : un corps non JSON très long est tronqué avec une mention explicite', () => {
    const long = 'x'.repeat(5000);
    const d = health.describeBody(long);
    assert.equal(d.truncated, true);
    assert.ok(d.pretty.length < long.length);
    assert.match(d.pretty, /tronqué/);
});

/* ============================================================
 * Console d'appels (lecture seule, GET uniquement) — addendum
 * ============================================================ */

function flush() {
    return new Promise((r) => { setTimeout(r, 0); });
}

test('routeParamNames() : extrait les segments {param} dans l\'ordre', () => {
    assert.deepEqual(health.routeParamNames('/admin/api/orders/{number}/cancel'), ['number']);
    assert.deepEqual(health.routeParamNames('/admin/api/products/{id}'), ['id']);
    assert.deepEqual(health.routeParamNames('/api/products'), []);
});

test('buildConsolePath() : refuse toute route qui n\'est pas GET', () => {
    assert.throws(() => health.buildConsolePath({ m: 'POST', p: '/admin/api/orders/{number}/cancel' }, { number: '1' }));
});

test('buildConsolePath() : remplace chaque paramètre, encodé (encodeURIComponent), jamais un chemin libre', () => {
    const route = { m: 'GET', p: '/admin/api/orders/{number}' };
    const path = health.buildConsolePath(route, { number: 'WK-1/évasion?x' });
    assert.equal(path, '/admin/api/orders/' + encodeURIComponent('WK-1/évasion?x'));
});

test('buildConsoleRequest() : GET uniquement, même origine, aucune clé body', () => {
    const route = { m: 'GET', p: '/admin/api/products/{id}' };
    const req = health.buildConsoleRequest(route, { id: '42' });
    assert.equal(req.url, '/admin/api/products/42');
    assert.equal(req.init.method, 'GET');
    assert.equal(req.init.credentials, 'same-origin');
    assert.equal('body' in req.init, false);
});

test('buildConsoleRequest() : refuse toute route qui n\'est pas GET, même via ce chemin', () => {
    assert.throws(() => health.buildConsoleRequest({ m: 'DELETE', p: '/api/products' }, {}));
});

const CONSOLE_ROUTES = [
    // 0 — GET sans paramètre.
    { m: 'GET', p: '/api/products', c: 'Product', a: 'index', s: 'borne', anon: true, perm: null, w: false, csrf: null, pin: null, re: null, f: 'json', g: 'Catalogue' },
    // 1 — GET avec un paramètre {number}.
    { m: 'GET', p: '/admin/api/orders/{number}', c: 'OrderApi', a: 'apiShow', s: 'api', anon: false, perm: 'order.read', w: false, csrf: null, pin: null, re: null, f: 'json', g: 'Commandes' },
    // 2 — POST, ne doit jamais apparaître dans la console.
    { m: 'POST', p: '/admin/api/orders/{number}/cancel', c: 'OrderApi', a: 'apiCancel', s: 'api', anon: false, perm: 'order.manage', w: true, csrf: 'header', pin: null, re: null, f: 'json', g: 'Commandes' },
];

function setupConsoleDom() {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body>' +
        '<select id="health-console-route"></select>' +
        '<div id="health-console-params"></div>' +
        '<button type="button" id="health-console-send">Envoyer</button>' +
        '<p id="health-console-status" role="status"></p>' +
        '<div id="health-console-result" hidden>' +
        '  <span id="health-console-resp-status">—</span>' +
        '  <span id="health-console-resp-time"></span>' +
        '  <div id="health-console-headers"></div>' +
        '  <pre id="health-console-body"></pre>' +
        '</div>' +
        '</body></html>',
    );
    return dom.window.document;
}

test('wireConsole : le sélecteur ne propose que les routes GET', () => {
    const doc = setupConsoleDom();
    health.wireConsole(doc, CONSOLE_ROUTES);
    const options = doc.querySelectorAll('#health-console-route option');
    assert.equal(options.length, 2);
    assert.match(options[0].textContent, /^GET /);
    assert.match(options[1].textContent, /^GET /);
});

test('wireConsole : choisir une route avec paramètre fait apparaître un champ par paramètre', () => {
    const doc = setupConsoleDom();
    health.wireConsole(doc, CONSOLE_ROUTES);
    const select = doc.getElementById('health-console-route');
    select.value = '1'; // /admin/api/orders/{number}
    select.dispatchEvent(new doc.defaultView.Event('change', { bubbles: true }));

    const input = doc.querySelector('[data-console-param="number"]');
    assert.ok(input, 'un champ pour le paramètre "number" doit exister');
});

test('wireConsole : refuse d\'envoyer si un paramètre est vide (aucun appel réseau)', async () => {
    const doc = setupConsoleDom();
    let called = false;
    global.fetch = async () => { called = true; return { ok: true, status: 200, headers: { get: () => null }, text: async () => '{}' }; };
    const api = health.wireConsole(doc, CONSOLE_ROUTES);
    api.openRoute(1); // number laissé vide

    await api.send();

    assert.equal(called, false);
    assert.match(doc.getElementById('health-console-status').textContent, /paramètre/);
});

test('wireConsole : envoie un GET réel avec le paramètre rempli, et affiche statut/en-têtes/corps', async () => {
    const doc = setupConsoleDom();
    global.fetch = async (url, init) => {
        assert.equal(url, '/admin/api/orders/WK-42');
        assert.equal(init.method, 'GET');
        assert.equal(init.credentials, 'same-origin');
        assert.equal('body' in init, false);
        return {
            ok: true, status: 200,
            headers: { get: (n) => (n === 'Content-Type' ? 'application/json' : null) },
            text: async () => JSON.stringify({ data: { order_number: 'WK-42' } }),
        };
    };
    const api = health.wireConsole(doc, CONSOLE_ROUTES);
    api.openRoute(1);
    doc.querySelector('[data-console-param="number"]').value = 'WK-42';

    await api.send();

    assert.equal(doc.getElementById('health-console-result').hidden, false);
    assert.match(doc.getElementById('health-console-resp-status').textContent, /200/);
    assert.match(doc.getElementById('health-console-headers').textContent, /Content-Type/);
    assert.match(doc.getElementById('health-console-body').textContent, /WK-42/);
});

test('wireConsole : une réponse avec du HTML injecté n\'est jamais interprétée (aucun <img> créé)', async () => {
    const doc = setupConsoleDom();
    global.fetch = async () => ({
        ok: true, status: 200, headers: { get: () => null },
        text: async () => JSON.stringify({ data: { name: '<img src=x onerror="window.__pwn=true">' } }),
    });
    const api = health.wireConsole(doc, CONSOLE_ROUTES);
    api.openRoute(0); // route sans paramètre

    await api.send();

    assert.equal(doc.querySelectorAll('img').length, 0);
    assert.match(doc.getElementById('health-console-body').textContent, /onerror/);
});

test('wireConsole : le clic sur "Envoyer" déclenche bien un appel réseau', async () => {
    const doc = setupConsoleDom();
    let called = false;
    global.fetch = async () => { called = true; return { ok: true, status: 200, headers: { get: () => null }, text: async () => '{"data":{}}' }; };
    health.wireConsole(doc, CONSOLE_ROUTES);
    doc.getElementById('health-console-route').value = '0';
    doc.getElementById('health-console-route').dispatchEvent(new doc.defaultView.Event('change', { bubbles: true }));

    doc.getElementById('health-console-send').click();
    await flush();

    assert.equal(called, true);
});

test('wireConsole.openRoute() : pré-remplit la route sélectionnée et reconstruit ses champs', () => {
    const doc = setupConsoleDom();
    const api = health.wireConsole(doc, CONSOLE_ROUTES);
    api.openRoute(1);

    assert.equal(doc.getElementById('health-console-route').value, '1');
    const input = doc.querySelector('[data-console-param="number"]');
    assert.ok(input, 'le champ du paramètre doit avoir été reconstruit');
});

test('wireRoutesMap : bouton "Ouvrir dans la console" uniquement sur les routes GET, notifie onOpenConsole sans sélectionner la route pour le trajet', () => {
    const doc = setupRoutesMapDom();
    let picked = null;
    let opened = null;
    health.wireRoutesMap(doc, ROUTES, function (ri) { picked = ri; }, function (ri) { opened = ri; });

    const consoleButtons = doc.querySelectorAll('[data-console-ri]');
    // ROUTES (fixture partagée en tête de fichier) : GET aux index 0 et 2 uniquement.
    assert.equal(consoleButtons.length, 2);

    consoleButtons[0].click();
    assert.equal(opened, +consoleButtons[0].getAttribute('data-console-ri'));
    assert.equal(picked, null, 'le clic ne doit pas aussi sélectionner la route pour le trajet');
});

/* ============================================================
 * Connexion JSON (démonstration), sans perte de session — addendum
 * ============================================================ */

test('buildLoginRequest() : POST credentials \'omit\', corps JSON {email, password}', () => {
    const req = health.buildLoginRequest('a@b.fr', 'jeton-de-test');
    assert.equal(req.url, '/admin/api/auth/login');
    assert.equal(req.init.method, 'POST');
    assert.equal(req.init.credentials, 'omit');
    assert.equal(req.init.headers['Content-Type'], 'application/json');
    assert.deepEqual(JSON.parse(req.init.body), { email: 'a@b.fr', password: 'jeton-de-test' });
});

test('curlSnippet() : séquence reproductible avec fichier de cookies, marqueurs à la place des identifiants', () => {
    const snippet = health.curlSnippet('https://admin.wakdo.test');
    assert.match(snippet, /curl -c cookies\.txt/);
    assert.match(snippet, /<email>/);
    assert.match(snippet, /<mot de passe>/);
    assert.match(snippet, /https:\/\/admin\.wakdo\.test\/admin\/api\/auth\/login/);
    assert.match(snippet, /curl -b cookies\.txt https:\/\/admin\.wakdo\.test\/admin\/api\/auth\/me/);
});

function setupLoginDom() {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body>' +
        '<form id="health-login-form">' +
        '  <input type="email" id="health-login-email">' +
        '  <input type="password" id="health-login-password">' +
        '  <button type="submit">Se connecter</button>' +
        '</form>' +
        '<p id="health-login-status"></p>' +
        '<div id="health-login-result" hidden>' +
        '  <span id="health-login-resp-status">—</span>' +
        '  <span id="health-login-resp-time"></span>' +
        '  <pre id="health-login-body"></pre>' +
        '</div>' +
        '<div id="health-login-token-row" hidden>' +
        '  <button type="button" id="health-login-copy-token">Copier</button>' +
        '  <span id="health-login-copy-status"></span>' +
        '</div>' +
        '<pre id="health-login-curl"></pre>' +
        '</body></html>',
        { url: 'https://admin.wakdo.test/admin/health' },
    );
    return dom.window.document;
}

test('wireLogin : construit la séquence curl avec l\'hôte réel de la page', () => {
    const doc = setupLoginDom();
    health.wireLogin(doc);
    assert.match(doc.getElementById('health-login-curl').textContent, /https:\/\/admin\.wakdo\.test\/admin\/api\/auth\/login/);
});

test('wireLogin : une connexion réussie affiche le statut, le corps, et vide le mot de passe', async () => {
    const doc = setupLoginDom();
    global.fetch = async (url, init) => {
        assert.equal(url, '/admin/api/auth/login');
        assert.equal(init.credentials, 'omit');
        return {
            ok: true, status: 200, headers: { get: () => null },
            text: async () => JSON.stringify({ data: { user: { email: 'a@b.fr' }, csrf_token: 'jeton-de-test' } }),
        };
    };
    const api = health.wireLogin(doc);
    doc.getElementById('health-login-email').value = 'a@b.fr';
    doc.getElementById('health-login-password').value = 'mot-de-passe-de-test';

    await api.submit();

    assert.equal(doc.getElementById('health-login-password').value, '', 'le mot de passe ne doit jamais rester dans le champ après l\'appel');
    assert.equal(doc.getElementById('health-login-result').hidden, false);
    assert.match(doc.getElementById('health-login-resp-status').textContent, /200/);
    assert.match(doc.getElementById('health-login-body').textContent, /jeton-de-test/);
    assert.equal(doc.getElementById('health-login-token-row').hidden, false, 'le bouton de copie du jeton doit apparaître');
    assert.ok(!/mot-de-passe-de-test/.test(doc.getElementById('health-login-body').textContent), 'le mot de passe ne doit jamais être réaffiché');
});

test('wireLogin : un échec (401) s\'affiche tel quel avec son code, et vide aussi le mot de passe', async () => {
    const doc = setupLoginDom();
    global.fetch = async () => ({
        ok: false, status: 401, headers: { get: () => null },
        text: async () => JSON.stringify({ data: null, error: { code: 'INVALID_CREDENTIALS', message: 'Email ou mot de passe incorrect' } }),
    });
    const api = health.wireLogin(doc);
    doc.getElementById('health-login-email').value = 'a@b.fr';
    doc.getElementById('health-login-password').value = 'mauvais-mot-de-passe-de-test';

    await api.submit();

    assert.equal(doc.getElementById('health-login-password').value, '');
    assert.match(doc.getElementById('health-login-resp-status').textContent, /401/);
    assert.match(doc.getElementById('health-login-body').textContent, /INVALID_CREDENTIALS/);
    assert.equal(doc.getElementById('health-login-token-row').hidden, true, 'pas de jeton à copier sur un échec');
});

// Un essai vide partirait au serveur pour un 422 certain et compterait dans la
// limitation des connexions par adresse : il est arrêté avant l'envoi.
for (const [cas, email, password] of [
    ['email vide', '', 'mot-de-passe-de-test'],
    ['mot de passe vide', 'a@b.fr', ''],
    ['les deux vides', '   ', ''],
]) {
    test(`wireLogin : ${cas}, rien n'est envoyé et la page dit quoi compléter`, async () => {
        const doc = setupLoginDom();
        let calls = 0;
        global.fetch = async () => { calls += 1; return { ok: true, status: 200, headers: { get: () => null }, text: async () => '{}' }; };
        const api = health.wireLogin(doc);
        doc.getElementById('health-login-email').value = email;
        doc.getElementById('health-login-password').value = password;

        await api.submit();

        assert.equal(calls, 0, 'aucun appel réseau pour un formulaire incomplet');
        assert.equal(doc.getElementById('health-login-result').hidden, true, 'aucun panneau de réponse sans appel');
        assert.match(doc.getElementById('health-login-status').textContent, /email et le mot de passe/);
    });
}

test('wireLogin : un email mal formé (contrôle du navigateur) n\'est pas envoyé', async () => {
    const doc = setupLoginDom();
    doc.getElementById('health-login-email').setAttribute('required', '');
    let calls = 0;
    global.fetch = async () => { calls += 1; return { ok: true, status: 200, headers: { get: () => null }, text: async () => '{}' }; };
    const api = health.wireLogin(doc);
    doc.getElementById('health-login-email').value = 'pas-un-email';
    doc.getElementById('health-login-password').value = 'mot-de-passe-de-test';

    await api.submit();

    assert.equal(calls, 0);
    assert.equal(doc.getElementById('health-login-result').hidden, true);
});

test('wireLogin : le mot de passe n\'est jamais exposé ailleurs, même sur un échec de validation', async () => {
    const doc = setupLoginDom();
    global.fetch = async () => ({
        ok: false, status: 422, headers: { get: () => null },
        text: async () => JSON.stringify({ data: null, error: { code: 'VALIDATION_ERROR', message: 'Entrée invalide' } }),
    });
    const api = health.wireLogin(doc);
    doc.getElementById('health-login-email').value = 'a@b.fr';
    const secretMarker = 'ne-doit-jamais-apparaitre';
    doc.getElementById('health-login-password').value = secretMarker;

    await api.submit();

    assert.ok(!doc.getElementById('health-login-body').textContent.includes(secretMarker));
    assert.equal(doc.getElementById('health-login-password').value, '');
});

test('wireLogin : un corps de réponse contenant du HTML injecté n\'est jamais interprété (aucun <img> créé)', async () => {
    const doc = setupLoginDom();
    global.fetch = async () => ({
        ok: false, status: 401, headers: { get: () => null },
        text: async () => JSON.stringify({ data: null, error: { code: 'INVALID_CREDENTIALS', message: '<img src=x onerror="window.__pwn=true">' } }),
    });
    const api = health.wireLogin(doc);
    doc.getElementById('health-login-email').value = 'a@b.fr';
    doc.getElementById('health-login-password').value = 'x';

    await api.submit();

    assert.equal(doc.querySelectorAll('img').length, 0);
    assert.match(doc.getElementById('health-login-body').textContent, /onerror/);
});

test('copyToken() : écrit dans le presse-papiers quand l\'API est disponible, et le signale', async () => {
    const doc = setupLoginDom();
    let written = null;
    const fakeWin = { navigator: { clipboard: { writeText: (t) => { written = t; return Promise.resolve(); } } } };
    const statusEl = doc.getElementById('health-login-copy-status');

    await health.copyToken(fakeWin, 'jeton-de-test', statusEl);

    assert.equal(written, 'jeton-de-test');
    assert.match(statusEl.textContent, /copié/i);
});

test('copyToken() : sans presse-papiers disponible, le dit clairement (pas d\'échec silencieux)', async () => {
    const doc = setupLoginDom();
    const statusEl = doc.getElementById('health-login-copy-status');

    await health.copyToken({ navigator: {} }, 'jeton-de-test', statusEl);

    assert.match(statusEl.textContent, /indisponible/i);
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
