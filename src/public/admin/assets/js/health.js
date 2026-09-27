/*
 * health.js — page "Santé de l'API" (/admin/health), back-office.
 *
 * Quatre blocs, quatre responsabilités dans ce fichier :
 *  1. État en direct : la vue rend déjà les valeurs côté serveur (page utile sans
 *     JavaScript) ; ce module relit GET /admin/api/health toutes les 15 s et
 *     remplace les mêmes noeuds. Un échec de relecture fige l'affichage et le dit
 *     (jamais un faux "tout va bien") ; la relecture s'arrête pendant que l'onglet
 *     est caché (aucun intérêt à sonder une page qu'on ne regarde pas).
 *  2. Appels réels : un vrai fetch par sonde de la page (construit depuis SA
 *     description, transmise par le serveur en attribut data-probe), comparé au
 *     statut/code attendus.
 *  3. Le trajet animé : PORTAGE FIDÈLE de l'artifact validé (scratchpad/apimap/
 *     template.html) — construction des étapes (stations), réponses de succès et
 *     d'échec, notes explicatives : mêmes textes, même logique, seule la source
 *     des routes change (attribut data-routes plutôt qu'un <script> JSON embarqué)
 *     et les classes CSS sont préfixées health- pour vivre dans admin.css. Aucune
 *     étape n'est réécrite de mémoire : c'est la matière déjà vérifiée ligne à
 *     ligne contre le code qui est reprise ici.
 *  4. La carte des routes : mêmes filtres que l'artifact (surface, méthode,
 *     recherche, "sous code personnel"), alimentée par le même data-routes.
 *
 * CSP script-src 'self' : aucun script en ligne, aucun gestionnaire en attribut.
 * Module CommonJS (admin = racine CommonJS, comme product-recipe.js/menu-form.js) :
 * init(doc) exporté pour les tests (node --test + jsdom), auto-appelé au
 * DOMContentLoaded en production. Les fonctions pures (stations, buildProbeRequest,
 * probeMatches, filterRoutes, applyReport...) sont exportées séparément pour être
 * testées sans DOM ni fetch.
 *
 * Le trajet et la carte des routes ne s'affichent qu'au JavaScript (comme le
 * constructeur de recette ou de slots de menu) : un <noscript> le signale. Le
 * bloc "État en direct", lui, est intégralement rendu par le serveur.
 */
(function () {
    'use strict';

    /* ============================================================
     * Utilitaires partagés
     * ============================================================ */

    function esc(s) {
        return String(s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    /** Met en valeur les segments {param} d'un chemin de route. */
    function pathHtml(p) {
        return esc(p).replace(/\{(\w+)\}/g, '<i>{$1}</i>');
    }

    /**
     * Classe de pastille pour une surface, parmi les variantes .pill-* DEJA
     * mesurees AA (docs/soutenance/preuves/06-audit-accessibilite-mesure.md) :
     * jamais un texte colore pose directement sur la carte (une paire fond+texte
     * non auditee), toujours l'une des paires .pill-*-bg / .pill-*-text
     * existantes du reste du back-office.
     */
    function surfPillClass(s) {
        if (s === 'borne') return 'pill-warning';
        if (s === 'api') return 'pill-info';
        return 'pill-success';
    }

    /** Horodatage lisible (heure locale), pour "dernière lecture"/"dernier essai". */
    function fmtTime(date) {
        try {
            return date.toLocaleTimeString();
        } catch (e) {
            return date.toISOString();
        }
    }

    /** performance.now() si disponible (mesure monotone), sinon Date.now(). */
    function nowMs() {
        if (typeof performance !== 'undefined' && typeof performance.now === 'function') {
            return performance.now();
        }
        return Date.now();
    }

    /** JSON.parse tolérant : une donnée absente ou illisible retombe sur un repli. */
    function parseJsonAttr(raw, fallback) {
        if (!raw) {
            return fallback;
        }
        try {
            var v = JSON.parse(raw);
            return v === null || v === undefined ? fallback : v;
        } catch (e) {
            return fallback;
        }
    }

    function byId(doc, id) {
        return doc.getElementById(id);
    }

    /* ============================================================
     * Utilitaires partagés de rendu de réponse (addendum : réponse JSON
     * complète, console d'appels, connexion) — communs aux blocs 2, 2bis et 5.
     *
     * Un corps de réponse HTTP est une donnée INCONNUE (l'API répond ce que son
     * code produit ; un test la fait volontairement contenir du HTML) : chaque
     * fonction ci-dessous écrit dans le DOM par `textContent` ou par des noeuds
     * créés un par un (`createElement` + `textContent`), JAMAIS par `innerHTML`
     * sur une chaîne dérivée de la réponse. C'est la garantie demandée : une
     * réponse ne peut jamais devenir un élément actif de la page.
     * ============================================================ */

    /**
     * En-têtes jugés probants pour la pédagogie de la page, jamais la totalité
     * (un en-tête interne au serveur n'a rien à faire ici). Seuls ceux
     * effectivement présents et non vides sont retenus par pickHeaders().
     */
    var SAFE_HEADER_NAMES = [
        'Content-Type', 'Cache-Control', 'X-Content-Type-Options',
        'Content-Security-Policy', 'X-Frame-Options', 'Retry-After',
    ];

    /**
     * @param {{get: function(string): ?string}} headers un objet Headers (ou un
     *   double de test qui en imite la seule méthode utilisée ici).
     * @returns {Object<string,string>} uniquement les en-têtes de la liste
     *   blanche réellement présents (non null, non vide).
     */
    function pickHeaders(headers) {
        var out = {};
        if (!headers || typeof headers.get !== 'function') {
            return out;
        }
        SAFE_HEADER_NAMES.forEach(function (name) {
            var v = headers.get(name);
            if (v !== null && v !== undefined && v !== '') {
                out[name] = v;
            }
        });
        return out;
    }

    var BODY_TEXT_MAX = 4000;

    /**
     * Décrit un corps de réponse brut (texte lu une seule fois, jamais
     * res.json() ET res.text() sur le même Response — impossible sur un vrai
     * Response, "body stream already read"). Un corps JSON est indenté
     * (JSON.stringify(..., null, 2)) ; un corps non JSON est rendu tel quel,
     * tronqué avec une mention explicite au-delà de BODY_TEXT_MAX caractères
     * (jamais silencieusement coupé sans le dire).
     *
     * @param {string} text
     * @returns {{isJson: boolean, json: ?object, pretty: string, truncated: boolean}}
     */
    function describeBody(text) {
        var raw = text || '';
        try {
            var json = JSON.parse(raw);
            return { isJson: true, json: json, pretty: JSON.stringify(json, null, 2), truncated: false };
        } catch (e) {
            var truncated = raw.length > BODY_TEXT_MAX;
            var pretty = truncated ? (raw.slice(0, BODY_TEXT_MAX) + '\n… (tronqué, réponse trop longue)') : raw;
            return { isJson: false, json: null, pretty: pretty, truncated: truncated };
        }
    }

    function statusPillClass(status) {
        if (status === null || status === undefined) {
            return 'pill-danger';
        }
        var c = String(status).charAt(0);
        if (c === '2') return 'pill-success';
        if (c === '3') return 'pill-info';
        if (c === '4') return 'pill-warning';
        return 'pill-danger';
    }

    /** Écrit le statut (pastille) et un texte annexe (temps écoulé). */
    function writeStatusMeta(statusEl, metaEl, status, elapsedMs) {
        if (statusEl) {
            statusEl.textContent = (status === null || status === undefined) ? 'échec réseau' : String(status);
            statusEl.className = 'pill ' + statusPillClass(status);
        }
        if (metaEl) {
            metaEl.textContent = (elapsedMs === null || elapsedMs === undefined) ? '' : (Math.round(elapsedMs) + ' ms');
        }
    }

    /**
     * Remplit `container` (un noeud vide, jamais le corps de réponse lui-même)
     * avec une liste de définitions des en-têtes retenus. Reconstruit
     * systématiquement à partir de zéro : aucune trace d'un appel précédent ne
     * peut rester affichée par erreur.
     */
    function writeHeaders(doc, container, headers) {
        if (!container) {
            return;
        }
        container.textContent = '';
        var names = Object.keys(headers || {});
        if (names.length === 0) {
            var none = doc.createElement('p');
            none.className = 'health-headers-empty';
            none.textContent = 'Aucun en-tête retenu.';
            container.appendChild(none);
            return;
        }
        var dl = doc.createElement('dl');
        dl.className = 'health-headers';
        names.forEach(function (name) {
            var dt = doc.createElement('dt');
            dt.textContent = name;
            var dd = doc.createElement('dd');
            dd.textContent = headers[name];
            dl.appendChild(dt);
            dl.appendChild(dd);
        });
        container.appendChild(dl);
    }

    /** Écrit le corps décrit par describeBody() dans un <pre>, par textContent uniquement. */
    function writeBody(bodyEl, bodyText) {
        if (bodyEl) {
            bodyEl.textContent = bodyText || '';
        }
    }

    /* ============================================================
     * Bloc 1 — État en direct (rapport, section 3 du contrat)
     * ============================================================ */

    /**
     * Ecrit un rapport (App\Health\HealthReport::build()) dans les noeuds de la
     * page. Utilisée à la fois par la relecture périodique ET, indirectement, par
     * le rendu serveur initial (mêmes ids, mêmes règles de formatage côté PHP) :
     * cette fonction est la SEULE source de vérité pour "comment afficher un
     * rapport", ce qui garantit que le premier affichage et les relectures ne
     * peuvent pas diverger.
     *
     * @param {Document} doc
     * @param {object} report
     */
    function applyReport(doc, report) {
        if (!report || typeof report !== 'object') {
            return;
        }

        var db = report.db || {};
        var dbPill = byId(doc, 'health-db-pill');
        if (dbPill) {
            var dbOk = db.ok === true;
            dbPill.textContent = dbOk ? 'Joignable' : 'Injoignable';
            dbPill.className = 'pill ' + (dbOk ? 'pill-success' : 'pill-danger');
        }
        var dbDetail = byId(doc, 'health-db-detail');
        if (dbDetail) {
            dbDetail.textContent = (db.ok === true)
                ? (fmtNum(db.latency_ms) + ' ms · ' + (db.server_version || '—'))
                : 'Aucune donnée disponible : la base ne répond pas.';
        }

        var debugPill = byId(doc, 'health-debug-pill');
        if (debugPill) {
            var errOff = report.display_errors_off === true;
            debugPill.textContent = errOff ? 'Coupé' : 'Actif';
            debugPill.className = 'pill ' + (errOff ? 'pill-success' : 'pill-danger');
        }
        var debugDetail = byId(doc, 'health-debug-detail');
        if (debugDetail) {
            debugDetail.textContent = 'Environnement ' + (report.app_env || '—')
                + ' · débogage ' + (report.debug === true ? 'activé' : 'désactivé')
                + ' · PHP ' + (report.php_version || '—');
        }

        var version = byId(doc, 'health-version');
        if (version) {
            version.textContent = report.version || '—';
        }
        var deployedAt = byId(doc, 'health-deployed-at');
        if (deployedAt) {
            deployedAt.textContent = report.deployed_at ? ('déployée le ' + report.deployed_at) : 'date de déploiement inconnue';
        }

        var migrations = report.migrations || {};
        var migrationsEl = byId(doc, 'health-migrations');
        if (migrationsEl) {
            migrationsEl.textContent = fmtCount(migrations.applied, migrations.files);
        }
        var migrationsLast = byId(doc, 'health-migrations-last');
        if (migrationsLast) {
            migrationsLast.textContent = migrations.last ? ('dernière : ' + migrations.last) : 'aucune migration appliquée';
        }

        var seeds = report.seeds || {};
        var seedsEl = byId(doc, 'health-seeds');
        if (seedsEl) {
            seedsEl.textContent = fmtCount(seeds.applied, seeds.files);
        }
        var seedsLast = byId(doc, 'health-seeds-last');
        if (seedsLast) {
            seedsLast.textContent = seeds.last ? ('dernier : ' + seeds.last) : 'aucun seed appliqué';
        }

        var activity = report.activity_24h || {};
        var activityEl = byId(doc, 'health-activity');
        if (activityEl) {
            activityEl.textContent = (activity.orders_created === null || activity.orders_created === undefined)
                ? 'indisponible'
                : (fmtNum(activity.orders_created) + ' créée' + (activity.orders_created === 1 ? '' : 's'));
        }
        var activityDetail = byId(doc, 'health-activity-detail');
        if (activityDetail) {
            activityDetail.textContent = (activity.orders_created === null || activity.orders_created === undefined)
                ? 'Base injoignable : aucun décompte disponible.'
                : (fmtNum(activity.orders_paid) + ' payée' + (activity.orders_paid === 1 ? '' : 's')
                    + ' · ' + fmtNum(activity.audit_lines) + ' ligne' + (activity.audit_lines === 1 ? '' : 's') + " d'audit"
                    + ' · ' + fmtNum(activity.pin_failures) + ' échec' + (activity.pin_failures === 1 ? '' : 's') + ' de code personnel');
        }
    }

    function fmtNum(n) {
        return (n === null || n === undefined) ? '—' : String(n);
    }

    function fmtCount(applied, files) {
        if (files === null || files === undefined) {
            return fmtNum(applied) + ' (nombre de fichiers inconnu)';
        }
        return fmtNum(applied) + ' / ' + fmtNum(files);
    }

    /** Marque la relecture comme réussie : horodatage à jour, message d'échec effacé. */
    function markRefreshOk(doc, when) {
        var status = byId(doc, 'health-refresh-status');
        var time = byId(doc, 'health-refresh-time');
        if (time) {
            time.textContent = fmtTime(when);
        }
        if (status) {
            status.classList.remove('health-refresh-status--error');
        }
        var err = byId(doc, 'health-refresh-error');
        if (err) {
            err.textContent = '';
            err.hidden = true;
        }
    }

    /**
     * Marque une relecture en échec : les valeurs affichées restent celles de la
     * dernière lecture réussie (aucune écriture par-dessus), et un message le dit
     * explicitement. Jamais un faux "tout va bien".
     */
    function markRefreshError(doc, when, message) {
        var status = byId(doc, 'health-refresh-status');
        if (status) {
            status.classList.add('health-refresh-status--error');
        }
        var err = byId(doc, 'health-refresh-error');
        if (err) {
            err.hidden = false;
            err.textContent = 'Échec de la relecture à ' + fmtTime(when) + ' — ' + message + ' Les valeurs affichées datent de la lecture précédente.';
        }
    }

    /**
     * Une relecture : GET /admin/api/health, même origine, cookies de session
     * inclus (`credentials: 'same-origin'`). Toute anomalie (réseau, statut non
     * 2xx, enveloppe illisible, `data` absent) est un échec de relecture, jamais
     * une valeur à afficher comme si elle était fraîche.
     */
    async function pollHealth(doc) {
        var when = new Date();
        try {
            var res = await fetch('/admin/api/health', { credentials: 'same-origin' });
            var json = await res.json().catch(function () { return null; });
            if (!res.ok || !json || !json.data) {
                markRefreshError(doc, when, 'réponse HTTP ' + res.status + '.');
                return false;
            }
            applyReport(doc, json.data);
            markRefreshOk(doc, when);
            return true;
        } catch (e) {
            markRefreshError(doc, when, 'la requête a échoué (réseau).');
            return false;
        }
    }

    var STATE_POLL_MS = 15000;

    /**
     * Relecture périodique, suspendue tant que l'onglet est caché (Page
     * Visibility API) : aucun intérêt à sonder une page que personne ne regarde,
     * et ça évite d'accumuler des relectures pendant que l'onglet dort en arrière-plan.
     * Une relecture immédiate est relancée au retour au premier plan.
     */
    function wireStatePolling(doc) {
        var timer = null;

        function stop() {
            if (timer) {
                clearInterval(timer);
                timer = null;
            }
        }

        function start() {
            stop();
            timer = setInterval(function () { pollHealth(doc); }, STATE_POLL_MS);
        }

        if (typeof doc.addEventListener === 'function') {
            doc.addEventListener('visibilitychange', function () {
                if (doc.hidden) {
                    stop();
                } else {
                    pollHealth(doc);
                    start();
                }
            });
        }

        start();
        return { stop: stop, start: start };
    }

    /* ============================================================
     * Bloc 2 — Appels réels (sondes, section 4 du contrat)
     * ============================================================ */

    /**
     * Construit les paramètres fetch d'une sonde, à partir de SA description
     * (jamais une requête écrite en dur ici). L'en-tête X-CSRF-Token n'est posé
     * QUE si la sonde le demande (`sendCsrf`) : c'est justement ce que la sonde
     * "jeton" vérifie en NE le posant PAS.
     *
     * @param {object} probe
     * @param {string} csrfToken
     * @returns {{url: string, init: object}}
     */
    function buildProbeRequest(probe, csrfToken) {
        var headers = {};
        if (probe.contentType) {
            headers['Content-Type'] = probe.contentType;
        }
        if (probe.sendCsrf) {
            headers['X-CSRF-Token'] = csrfToken || '';
        }
        var init = {
            method: probe.method,
            credentials: probe.credentials,
            headers: headers,
        };
        if (probe.body !== null && probe.body !== undefined && probe.body !== '') {
            init.body = probe.body;
        }
        return { url: probe.url, init: init };
    }

    /**
     * Une sonde est "conforme" si le statut obtenu est celui attendu ET, quand un
     * code d'erreur est attendu, que le code lu dans l'enveloppe JSON correspond.
     * Une sonde de succès (pas d'expectCode) ne regarde que le statut.
     */
    function probeMatches(probe, status, errorCode) {
        if (status !== probe.expect) {
            return false;
        }
        if (probe.expectCode) {
            return errorCode === probe.expectCode;
        }
        return true;
    }

    function probeResultEl(doc, probeId) {
        var li = doc.querySelector('[data-probe-id="' + probeId + '"]');
        return li ? li.querySelector('[data-probe-result]') : null;
    }

    /** Rend le résultat d'une sonde dans son emplacement (région aria-live). */
    function renderProbeOutcome(doc, probe, outcome) {
        var el = probeResultEl(doc, probe.id);
        if (!el) {
            return;
        }
        if (outcome.networkError) {
            el.textContent = 'Échec réseau : la requête n\'a pas abouti.';
            el.className = 'health-probe-result health-probe-result--unexpected';
            return;
        }
        var verdict = outcome.conforme ? 'conforme' : 'inattendu';
        var text = outcome.status + ' obtenu (attendu ' + probe.expect + (probe.expectCode ? ' ' + probe.expectCode : '') + ')';
        if (outcome.code) {
            text += ' · code ' + outcome.code;
        }
        text += ' · ' + Math.round(outcome.elapsedMs) + ' ms · ' + verdict;
        el.textContent = text;
        el.className = 'health-probe-result ' + (outcome.conforme ? 'health-probe-result--ok' : 'health-probe-result--unexpected');
    }

    /**
     * Écrit la réponse complète d'une sonde (en-têtes retenus + corps décrit)
     * dans son panneau de détail (`#health-probe-detail-<id>`, replié par défaut
     * — cf. wireProbes()). Reconstruit à chaque appel : jamais de mélange entre
     * deux lancements successifs.
     */
    function renderProbeDetail(doc, probeId, outcome) {
        var container = byId(doc, 'health-probe-detail-' + probeId);
        if (!container) {
            return;
        }
        container.textContent = '';
        var headersBox = doc.createElement('div');
        headersBox.className = 'health-headers-box';
        container.appendChild(headersBox);
        writeHeaders(doc, headersBox, outcome.headers);
        var pre = doc.createElement('pre');
        pre.className = 'health-body health-probe-body';
        container.appendChild(pre);
        writeBody(pre, outcome.networkError ? '' : outcome.bodyText);
    }

    /**
     * Lance une sonde : vrai fetch, mesure du temps (performance.now()), lecture
     * du corps UNE SEULE FOIS en texte (jamais res.json() ET res.text() sur le
     * même Response), dont le code d'erreur est extrait pour la comparaison au
     * statut/code attendus. Un résultat inattendu est rendu de façon visible
     * (jamais masqué) via `renderProbeOutcome` ; la réponse complète (en-têtes +
     * corps) est écrite dans le panneau de détail, replié par défaut.
     *
     * @returns {Promise<object>} le résultat (utile à l'agrégat "Tout lancer")
     */
    async function runProbe(doc, probe, csrfToken) {
        var el = probeResultEl(doc, probe.id);
        if (el) {
            el.textContent = 'Appel en cours…';
            el.className = 'health-probe-result';
        }
        var req = buildProbeRequest(probe, csrfToken);
        var start = nowMs();
        var outcome;
        try {
            var res = await fetch(req.url, req.init);
            var elapsed = nowMs() - start;
            var text = await res.text().catch(function () { return ''; });
            var desc = describeBody(text);
            var code = (desc.isJson && desc.json && desc.json.error && desc.json.error.code) ? desc.json.error.code : null;
            outcome = {
                status: res.status,
                code: code,
                elapsedMs: elapsed,
                conforme: probeMatches(probe, res.status, code),
                headers: pickHeaders(res.headers),
                bodyText: desc.pretty,
            };
        } catch (e) {
            outcome = { status: null, code: null, elapsedMs: nowMs() - start, conforme: false, networkError: true, headers: {}, bodyText: '' };
        }
        renderProbeOutcome(doc, probe, outcome);
        renderProbeDetail(doc, probe.id, outcome);
        return outcome;
    }

    function renderProbesSummary(doc, results) {
        var el = byId(doc, 'health-probes-summary');
        if (!el) {
            return;
        }
        var ok = results.filter(function (r) { return r.conforme; }).length;
        el.textContent = ok + ' sur ' + results.length + ' sonde' + (results.length > 1 ? 's' : '') + ' conforme' + (ok > 1 ? 's' : '')
            + (ok < results.length ? ' — vérifie les résultats marqués "inattendu".' : '.');
    }

    async function runAllProbes(doc, probes, csrfToken) {
        var results = [];
        for (var i = 0; i < probes.length; i++) {
            results.push(await runProbe(doc, probes[i], csrfToken));
        }
        renderProbesSummary(doc, results);
        return results;
    }

    function wireProbes(doc, probes, csrfToken) {
        var list = byId(doc, 'health-probes');
        if (!list) {
            return;
        }
        list.addEventListener('click', function (e) {
            // Bascule "Détails" : replié par défaut (index.php), déplié au clic.
            // Le contenu est déjà à jour (renderProbeDetail écrit à chaque
            // lancement, que le panneau soit visible ou non) : ce bouton ne fait
            // qu'afficher/masquer, jamais relancer un appel.
            var toggle = e.target.closest('[data-probe-detail-toggle]');
            if (toggle) {
                var pid = toggle.getAttribute('data-probe-detail-toggle');
                var panel = byId(doc, 'health-probe-detail-' + pid);
                var expanded = toggle.getAttribute('aria-expanded') === 'true';
                toggle.setAttribute('aria-expanded', String(!expanded));
                if (panel) {
                    panel.hidden = expanded;
                }
                return;
            }
            var btn = e.target.closest('[data-probe-run]');
            if (!btn) {
                return;
            }
            var li = btn.closest('[data-probe-id]');
            var id = li ? li.getAttribute('data-probe-id') : null;
            var probe = probes.filter(function (p) { return p.id === id; })[0];
            if (probe) {
                runProbe(doc, probe, csrfToken);
            }
        });
        var runAllBtn = byId(doc, 'health-run-all');
        if (runAllBtn) {
            runAllBtn.addEventListener('click', function () {
                runAllProbes(doc, probes, csrfToken);
            });
        }
    }

    /* ============================================================
     * Bloc 2bis — Console d'appels (lecture seule, GET uniquement)
     *
     * Contrairement aux sept sondes (bloc 2, requêtes figées) ou au trajet
     * (bloc 3, simulation), la console lance un VRAI GET vers n'importe quelle
     * route GET de la carte (bloc 4), avec la session courante. Le "GET
     * uniquement" n'est pas qu'une contrainte d'interface : buildConsolePath()
     * et buildConsoleRequest() REFUSENT (exception) toute route dont la méthode
     * n'est pas GET, et le sélecteur (wireConsole) ne propose jamais que les
     * routes GET au départ — double verrou, testé aux deux niveaux.
     * ============================================================ */

    /** Extrait, dans l'ordre, les noms des segments {param} d'un chemin de route. */
    function routeParamNames(path) {
        var out = [];
        var re = /\{(\w+)\}/g;
        var m;
        while ((m = re.exec(path))) {
            out.push(m[1]);
        }
        return out;
    }

    /**
     * Remplace chaque {param} du chemin par sa valeur ENCODÉE
     * (encodeURIComponent) : jamais un chemin libre construit par
     * concaténation, qui permettrait d'appeler autre chose que la route
     * choisie. Refuse (exception) toute route qui n'est pas GET.
     *
     * @param {object} route une entrée de RouteMap::rows() (data-routes)
     * @param {Object<string,string>} values valeurs saisies par paramètre
     * @returns {string} le chemin final, relatif à l'origine courante
     */
    function buildConsolePath(route, values) {
        if (!route || route.m !== 'GET') {
            throw new Error("La console n'appelle que des routes GET.");
        }
        return route.p.replace(/\{(\w+)\}/g, function (_, name) {
            return encodeURIComponent((values && values[name]) || '');
        });
    }

    /**
     * Construit la requête fetch de la console : méthode GET figée en dur
     * (jamais lue depuis un paramètre), session courante (`credentials:
     * 'same-origin'`), aucune clé `body`.
     */
    function buildConsoleRequest(route, values) {
        var path = buildConsolePath(route, values);
        return { url: path, init: { method: 'GET', credentials: 'same-origin' } };
    }

    /** Les seules options proposées par le sélecteur : les routes GET, avec leur index réel dans `routes`. */
    function consoleRouteOptions(routes) {
        var out = [];
        routes.forEach(function (r, i) {
            if (r.m === 'GET') {
                out.push({ ri: i, label: 'GET ' + r.p + ' — ' + r.g });
            }
        });
        return out;
    }

    /**
     * Câble la console : sélecteur (routes GET uniquement), un champ par
     * paramètre du chemin choisi, validation (aucun appel si un paramètre est
     * vide), exécution réelle, rendu XSS-safe de la réponse (statut, en-têtes
     * retenus, corps décrit). `openRoute(ri)` est appelée par la carte des
     * routes (bouton "Ouvrir dans la console", bloc 4) pour pré-remplir la
     * sélection depuis l'extérieur.
     *
     * @returns {?{openRoute: function(number): void, send: function(): Promise<void>}}
     */
    function wireConsole(doc, routes) {
        var sel = byId(doc, 'health-console-route');
        var paramsEl = byId(doc, 'health-console-params');
        var sendBtn = byId(doc, 'health-console-send');
        var statusEl = byId(doc, 'health-console-status');
        var resultEl = byId(doc, 'health-console-result');
        var statusPill = byId(doc, 'health-console-resp-status');
        var respTime = byId(doc, 'health-console-resp-time');
        var headersEl = byId(doc, 'health-console-headers');
        var bodyEl = byId(doc, 'health-console-body');
        if (!sel || !paramsEl || !sendBtn) {
            return null;
        }

        var options = consoleRouteOptions(routes);
        sel.innerHTML = options.map(function (o) {
            return '<option value="' + o.ri + '">' + esc(o.label) + '</option>';
        }).join('');

        function currentRoute() {
            var ri = sel.value === '' ? NaN : +sel.value;
            return { ri: ri, route: routes[ri] };
        }

        function rebuildFields() {
            paramsEl.textContent = '';
            var cur = currentRoute();
            if (!cur.route) {
                return;
            }
            routeParamNames(cur.route.p).forEach(function (name) {
                var group = doc.createElement('div');
                group.className = 'form-group health-console-field';
                var inputId = 'health-console-param-' + name;
                var label = doc.createElement('label');
                label.className = 'form-label';
                label.setAttribute('for', inputId);
                label.textContent = name;
                var input = doc.createElement('input');
                input.className = 'form-input';
                input.type = 'text';
                input.id = inputId;
                input.setAttribute('data-console-param', name);
                group.appendChild(label);
                group.appendChild(input);
                paramsEl.appendChild(group);
            });
        }

        function readValues() {
            var values = {};
            paramsEl.querySelectorAll('[data-console-param]').forEach(function (input) {
                values[input.getAttribute('data-console-param')] = input.value.trim();
            });
            return values;
        }

        function paramsMissing(route, values) {
            return routeParamNames(route.p).some(function (name) { return !values[name]; });
        }

        async function send() {
            var cur = currentRoute();
            if (!cur.route) {
                return;
            }
            var values = readValues();
            if (paramsMissing(cur.route, values)) {
                statusEl.textContent = 'Renseigne tous les paramètres avant d’envoyer.';
                return;
            }
            var req = buildConsoleRequest(cur.route, values);
            statusEl.textContent = 'Appel en cours…';
            if (resultEl) {
                resultEl.hidden = true;
            }
            var start = nowMs();
            try {
                var res = await fetch(req.url, req.init);
                var elapsed = nowMs() - start;
                var text = await res.text().catch(function () { return ''; });
                var desc = describeBody(text);
                writeStatusMeta(statusPill, respTime, res.status, elapsed);
                writeHeaders(doc, headersEl, pickHeaders(res.headers));
                writeBody(bodyEl, desc.pretty);
                if (resultEl) {
                    resultEl.hidden = false;
                }
                statusEl.textContent = 'Réponse reçue en ' + Math.round(elapsed) + ' ms.';
            } catch (e) {
                statusEl.textContent = 'Échec réseau : la requête n’a pas abouti.';
            }
        }

        sel.addEventListener('change', rebuildFields);
        sendBtn.addEventListener('click', function (e) {
            e.preventDefault();
            send();
        });
        rebuildFields();

        return {
            openRoute: function (ri) {
                sel.value = String(ri);
                rebuildFields();
                if (routes[ri]) {
                    statusEl.textContent = 'Route chargée : ' + routes[ri].m + ' ' + routes[ri].p + '.';
                }
                var firstInput = paramsEl.querySelector('input');
                (firstInput || sendBtn).focus();
            },
            send: send,
        };
    }

    /* ============================================================
     * Bloc 3 — Le trajet animé (portage fidèle de l'artifact)
     * ============================================================ */

    var REASON = { 200: 'OK', 201: 'Created', 302: 'Found', 400: 'Bad Request', 401: 'Unauthorized', 403: 'Forbidden', 404: 'Not Found', 409: 'Conflict', 415: 'Unsupported Media Type', 422: 'Unprocessable Content', 429: 'Too Many Requests', 500: 'Internal Server Error' };

    function isJson(r) { return r.f === 'json'; }
    function createsResource(r) { return r.m === 'POST' && (/\/(orders|categories|products|menus|ingredients|users|roles)$/.test(r.p)); }

    function find(routes, m, p) {
        for (var i = 0; i < routes.length; i++) {
            if (routes[i].m === m && routes[i].p === p) {
                return i;
            }
        }
        return -1;
    }

    /**
     * Construit les étapes traversées par un appel, dans l'ordre réel des gardes
     * (App\Core\routes.php -> App\Core\Router -> App\Auth\SessionGuard ->
     * App\Auth\Authorizer -> App\Auth\Csrf -> requireJsonBody -> validation ->
     * App\Auth\PinGate -> dépôts/MariaDB -> réponse). PORTAGE FIDÈLE de
     * scratchpad/apimap/template.html : mêmes textes, même logique. `routes` sert
     * uniquement à compter le nombre total de routes déclarées (message du routeur).
     *
     * @param {object} r une entrée de $routes (RouteMap::rows())
     * @param {Array} routes la liste complète (pour ROUTES.length)
     * @returns {Array<object>} liste ordonnée d'étapes {id, name, where, does, fail?}
     */
    function stations(r, routes) {
        var S = [];
        var json = isJson(r);
        var borne = r.s === 'borne';
        var api = r.s === 'api';

        S.push({
            id: 'client',
            name: borne ? 'Navigateur de la borne' : api ? 'Client HTTP' : "Navigateur de l'équipier",
            where: borne ? 'écran tactile en salle' : api ? 'Postman, Bruno ou script' : 'poste du comptoir ou du bureau',
            does: borne ? 'Appel relatif sur la même origine que la borne : aucune requête vers un autre domaine.'
                : api ? "Requête JSON vers le site d'administration, avec le cookie de session et, pour une écriture, l'en-tête X-CSRF-Token."
                    : 'Lien ou formulaire du back-office, avec le cookie de session.',
        });
        S.push({
            id: 'traefik', name: 'Traefik', where: 'mandataire de l’hôte',
            does: 'Termine le HTTPS puis relaie la requête au conteneur web, avec les en-têtes X-Forwarded-*.',
        });
        if (borne) {
            S.push({
                id: 'apache', name: 'Apache, site de la borne', where: 'wakdo-web · vhost.conf',
                does: 'Seul ^/api est relayé à PHP-FPM, forcé sur le contrôleur frontal. Politique de sécurité de contenu stricte, HSTS, nosniff.',
            });
        } else {
            S.push({
                id: 'apache', name: "Apache, site d'administration", where: 'wakdo-web · vhost.conf',
                does: "Toute adresse qui n'est pas un fichier est réécrite vers index.php. Politique de sécurité de contenu, HSTS, et Cache-Control: no-store, private sur le PHP.",
            });
        }
        S.push({
            id: 'front', name: 'Contrôleur frontal', where: 'wakdo-app · src/public/admin/index.php',
            does: "PHP-FPM exécute le point d'entrée unique : en-têtes nosniff et noindex, affichage des erreurs réglé depuis APP_DEBUG, lecture de la requête, démarrage de la session.",
        });
        if (borne) {
            S.push({
                id: 'cors', name: 'Contrôle CORS', where: 'App\\Core\\Cors',
                does: "Sur ce trajet la borne appelle sa propre origine, donc CORS n'intervient pas. Le contrôle reste en place en défense : préflight OPTIONS traité avant le routeur, origine exacte ou refus.",
                fail: { status: '—', code: 'origine refusée', kind: 'cors' },
            });
        }
        S.push({
            id: 'router', name: 'Routeur', where: 'App\\Core\\Router',
            does: 'Compare ' + r.m + ' ' + r.p + ' aux ' + routes.length + ' routes déclarées. Les segments entre accolades deviennent des paramètres.',
            fail: { status: 404, code: 'NOT_FOUND', msg: 'Resource not found', alt: '405 METHOD_NOT_ALLOWED si le chemin existe avec une autre méthode' },
        });
        if (r.a === 'apiLogin' || (r.p === '/login' && r.m === 'POST')) {
            if (r.a === 'apiLogin') {
                S.push({
                    id: 'body', name: 'Corps JSON', where: 'requireJsonBody',
                    does: "Content-Type exactement application/json. Sur cette route, c'est la protection : ce type force un préflight CORS, fermé sur ce préfixe.",
                    fail: { status: 415, code: 'UNSUPPORTED_MEDIA_TYPE', msg: 'Content-Type doit être application/json pour un corps non vide', alt: '400 INVALID_JSON si le corps est illisible' },
                });
            }
            if (r.p === '/login') {
                S.push({
                    id: 'csrf', name: 'Jeton anti-rejeu', where: 'champ caché _csrf',
                    does: 'Le formulaire de connexion porte lui aussi le jeton de la session.',
                    fail: { status: 403, code: '« Session expirée, merci de réessayer. »', kind: 'page' },
                });
            }
            S.push({
                id: 'creds', name: 'Identifiants', where: 'AuthService · argon2id',
                does: 'Mot de passe vérifié à coût constant, même pour un compte inexistant. Tentatives limitées par adresse et par compte.',
                fail: json
                    ? { status: 401, code: 'INVALID_CREDENTIALS', msg: 'Email ou mot de passe incorrect', alt: '429 TOO_MANY_ATTEMPTS après trop d’essais' }
                    : { status: 200, code: 'formulaire réaffiché : « Email ou mot de passe incorrect »', kind: 'login', tone: 'warn' },
            });
        }
        if (!r.anon) {
            S.push({
                id: 'session', name: 'Session', where: 'SessionGuard',
                does: 'Session présente, ni expirée par inactivité ni par durée absolue, compte actif.',
                fail: json ? { status: 401, code: 'AUTH_REQUIRED', msg: 'Authentification requise' } : { status: 302, code: '→ /login', kind: 'redirect' },
            });
        }
        if (r.perm) {
            S.push({
                id: 'perm', name: 'Permission', where: 'Authorizer',
                does: 'Le rôle détient-il ' + r.perm + " ? Le code vérifie une permission, pas un nom de rôle : un rôle créé sur mesure ouvre les mêmes fonctions.",
                fail: json ? { status: 403, code: 'FORBIDDEN', msg: 'Permission manquante' } : { status: 403, code: 'page « Accès refusé »', kind: 'page' },
            });
        }
        if (r.csrf && r.p !== '/login') {
            S.push({
                id: 'csrf', name: 'Jeton anti-rejeu', where: r.csrf === 'header' ? 'en-tête X-CSRF-Token' : 'champ caché _csrf',
                does: "Le jeton lié à la session doit accompagner toute écriture : une page tierce ne peut pas faire agir l'équipier à son insu.",
                fail: r.csrf === 'header'
                    ? { status: 403, code: 'CSRF_INVALID', msg: 'Jeton CSRF absent ou invalide (en-tête X-CSRF-Token)' }
                    : { status: 403, code: 'Requête invalide.', kind: 'text' },
            });
        }
        if (api && r.w && r.a !== 'apiLogin') {
            S.push({
                id: 'body', name: 'Corps JSON', where: 'requireJsonBody',
                does: 'Content-Type exactement application/json, syntaxe valide, racine objet. Un corps mal typé est refusé avant toute validation de champ.',
                fail: { status: 415, code: 'UNSUPPORTED_MEDIA_TYPE', msg: 'Content-Type doit être application/json pour un corps non vide', alt: '400 INVALID_JSON si le corps est illisible' },
            });
        }
        if (r.w && r.a !== 'apiLogin' && r.p !== '/login' && r.p !== '/logout' && r.a !== 'apiLogout' && !/\/pay$/.test(r.p)) {
            var vdoes = (r.p === '/api/orders')
                ? 'Chaque ligne est vérifiée contre le catalogue réel : produit disponible, choix de menu valide, ingrédient retirable. Le prix est recalculé en base ; celui qu’envoie la borne ne sert pas.'
                : "Champs obligatoires, types, bornes et cohérence, vérifiés côté serveur même si le navigateur l'a déjà fait.";
            S.push({
                id: 'valid', name: 'Validation', where: 'règles du contrôleur', does: vdoes,
                fail: api ? { status: 422, code: 'VALIDATION_ERROR', msg: 'Entrée invalide', fields: true }
                    : borne ? { status: 422, code: 'PRODUCT_UNAVAILABLE', msg: 'Produit indisponible.' }
                        : { status: 422, code: 'formulaire réaffiché', kind: 'form' },
            });
        }
        if (r.pin) {
            S.push({
                id: 'pin', name: 'Code personnel', where: 'PinGate · PinThrottle',
                does: (r.pin === 'price' ? 'Exigé seulement si le prix ou le taux de TVA change. ' : '') + "Email et code à quatre chiffres de l'équipier qui agit, vérifiés indépendamment de la session. Temporisation croissante après des échecs répétés.",
                fail: json ? { status: 422, code: 'PIN_INVALID', msg: 'Email ou PIN invalide', pinNote: true } : { status: 422, code: 'code refusé, formulaire réaffiché', kind: 'form', pinNote: true },
            });
        }
        if (r.re === 'password') {
            S.push({
                id: 'reauth', name: 'Mot de passe actuel', where: 'PasswordHasher · argon2id',
                does: 'Définir son code personnel redemande le mot de passe : un poste laissé ouvert ne suffit pas à changer un secret.',
                fail: { status: 422, code: 'formulaire réaffiché', kind: 'form' },
            });
        }
        var mdoes;
        if (r.p === '/api/orders' && r.m === 'POST') {
            mdoes = "Commande créée en attente de paiement. La clé d'idempotence est unique en base : un double appui ou une nouvelle tentative réseau ne crée pas une seconde commande.";
        } else if (/\/pay$/.test(r.p)) {
            mdoes = 'Encaissement dans une transaction : la commande passe payée et chaque ingrédient de la recette est décrémenté par un mouvement de stock.';
        } else if (/\/cancel$/.test(r.p) && r.m === 'POST') {
            mdoes = "Annulation dans une transaction, remise en stock si la commande avait été payée, et ligne d'audit écrite dans la même transaction que l'action.";
        } else if (r.w) {
            mdoes = 'Écriture dans une transaction : tout ou rien. Requêtes préparées uniquement.' + (r.pin ? " Ligne d'audit écrite dans la même transaction que l'action." : '');
        } else {
            mdoes = 'Lecture par requêtes préparées. La connexion à MariaDB n’est ouverte qu’au premier besoin.' + (r.p === '/api/products' ? " Seul le commandable est servi : produits disponibles d'une catégorie active." : '');
        }
        var mfail = r.w
            ? (borne ? { status: 409, code: 'INVALID_TRANSITION', msg: 'Transition de statut invalide.' } : api ? { status: 409, code: 'CONFLICT', msg: 'Conflit' } : { status: 409, code: 'conflit signalé', kind: 'form' })
            : (/\{(id|number)\}/.test(r.p) ? (json ? { status: 404, code: 'NOT_FOUND', msg: 'Resource not found' } : { status: 404, code: 'page introuvable', kind: 'page' }) : null);
        S.push({ id: 'db', name: 'Métier et base de données', where: 'dépôts · MariaDB, conteneur wakdo-db', does: mdoes, fail: mfail });
        S.push({
            id: 'resp', name: 'Réponse', where: json ? 'enveloppe {data, error}' : (r.m === 'GET' ? 'vue rendue côté serveur' : 'redirection'),
            does: json ? 'Enveloppe JSON uniforme, décorée des en-têtes CORS pour la borne, puis envoyée.'
                : (r.m === 'GET' ? 'Page HTML composée côté serveur, sans script en ligne.' : "Redirection vers la liste avec un message de confirmation, pour qu'un rechargement ne renvoie pas le formulaire."),
        });
        return S;
    }

    function redirectOf(r) {
        var special = {
            '/login': "route d'accueil du rôle (par exemple /admin/stats ou /kitchen/display)",
            '/logout': '/login', '/reset_password': '/login',
            '/forgot_password': '/forgot_password (message neutre, que le compte existe ou non)',
            '/admin/profile/pin': '/admin/profile/pin',
        };
        if (special[r.p]) {
            return special[r.p];
        }
        return r.p.replace(/\/\{[^}]+\}.*$/, '').replace(/\/(new|import.*)$/, '') || '/';
    }

    function successOf(r) {
        if (!isJson(r)) {
            return r.m === 'GET'
                ? { status: 200, body: '<!doctype html>\n… page du back-office, rendue côté serveur …' }
                : { status: 302, body: 'Location: ' + redirectOf(r) + '\n\n(corps vide : le navigateur suit la redirection)' };
        }
        var st = (r.p === '/api/orders' && r.m === 'POST') || (r.s === 'api' && createsResource(r)) ? 201 : 200;
        var body = r.m === 'GET' && !/\{(id|number)\}/.test(r.p) && r.p !== '/api/health' && r.p !== '/admin/me' && r.a !== 'apiMe' && r.p !== '/admin/api/stats'
            ? '{\n  "data": [ … ]\n}' : '{\n  "data": { … }\n}';
        if (r.a === 'apiCancel') {
            body = '{\n  "data": {\n    "order_number": "{number}",\n    "status": "cancelled"\n  }\n}';
        }
        return { status: st, body: body };
    }

    function errorBody(f) {
        if (f.kind === 'redirect') return 'Location: /login\n\n(corps vide : retour à la connexion)';
        if (f.kind === 'text') return 'Content-Type: text/plain; charset=utf-8\n\nRequête invalide.';
        if (f.kind === 'page') return '<!doctype html>\n… ' + f.code + ' …';
        if (f.kind === 'login') return '<!doctype html>\n… page de connexion réaffichée …\nEmail ou mot de passe incorrect\n\n(même message pour un compte inconnu ou un mauvais mot de passe)';
        if (f.kind === 'form') return '<!doctype html>\n… formulaire réaffiché, saisie conservée, erreur signalée au champ concerné …';
        if (f.kind === 'cors') return 'aucun en-tête Access-Control-Allow-Origin\n\n(le navigateur bloque la lecture de la réponse)';
        var e = '{\n  "data": null,\n  "error": {\n    "code": "' + f.code + '",\n    "message": "' + f.msg + '"';
        if (f.fields) e += ',\n    "fields": { "<champ>": "…" }';
        return e + '\n  }\n}';
    }

    /**
     * Point de refus effectif : la simulation d'exception ("boom") l'emporte,
     * puis un code de refus choisi sous une étape, sinon aucun refus (trajet complet).
     *
     * @param {Array} steps résultat de stations()
     * @param {?string} failId id de l'étape dont le refus est simulé (ou null)
     * @param {boolean} boom simulation d'exception (toujours à l'étape 'db')
     * @returns {number} index dans steps, ou -1 si aucun refus
     */
    function failIndex(steps, failId, boom) {
        if (boom) {
            for (var i = 0; i < steps.length; i++) {
                if (steps[i].id === 'db') return i;
            }
        }
        if (!failId) return -1;
        for (var j = 0; j < steps.length; j++) {
            if (steps[j].id === failId) return j;
        }
        return -1;
    }

    function wireTrajet(doc, routes, onLoad) {
        var win = doc.defaultView || (typeof window !== 'undefined' ? window : null);
        var REDUCED = !!(win && win.matchMedia && win.matchMedia('(prefers-reduced-motion: reduce)').matches);

        var PRESETS = [
            { label: 'Borne · lire le catalogue', m: 'GET', p: '/api/products' },
            { label: 'Borne · passer commande', m: 'POST', p: '/api/orders' },
            { label: 'API · annuler une commande', m: 'POST', p: '/admin/api/orders/{number}/cancel' },
            { label: 'API · appel sans session', m: 'GET', p: '/admin/api/products', fail: 'session' },
            { label: 'Back-office · changer un prix', m: 'POST', p: '/admin/products/{id}' },
        ];
        var SURF = { borne: 'Borne publique', api: "API d'administration", bo: 'Back-office' };

        var state = { ri: find(routes, 'POST', '/admin/api/orders/{number}/cancel'), fail: null, boom: false, step: -1, timer: null, preset: 2 };
        if (state.ri < 0) {
            state.ri = 0;
        }
        var S = [];

        var elPresets = byId(doc, 'health-presets');
        var elRoutebar = byId(doc, 'health-routebar');
        var elRail = byId(doc, 'health-rail');
        var elStatus = byId(doc, 'health-status');
        var elRespWhere = byId(doc, 'health-respwhere');
        var elBody = byId(doc, 'health-respbody');
        var elNotes = byId(doc, 'health-notes');
        var elBoom = byId(doc, 'health-boom');
        if (!elRail || !elRoutebar || !elStatus) {
            return;
        }

        function badgesHtml(r) {
            var b = [];
            if (r.anon) b.push('<span class="pill pill-neutral">sans compte</span>');
            if (r.perm) b.push('<span class="pill pill-info">' + esc(r.perm) + '</span>');
            if (r.csrf) b.push('<span class="pill pill-warning">jeton</span>');
            if (r.pin) b.push('<span class="pill pill-danger">' + (r.pin === 'price' ? 'code perso si prix ou TVA' : 'code personnel') + '</span>');
            if (r.re === 'password') b.push('<span class="pill pill-neutral">mot de passe actuel</span>');
            return b.join('');
        }

        function renderPresets() {
            if (!elPresets) return;
            elPresets.innerHTML = PRESETS.map(function (pr, i) {
                var idx = find(routes, pr.m, pr.p);
                var r = idx >= 0 ? routes[idx] : null;
                return '<button type="button" class="health-chip" data-p="' + i + '" aria-pressed="' + (state.preset === i) + '">'
                    + '<span class="health-chip__dot health-chip__dot--' + (r ? r.s : '') + '"></span>' + esc(pr.label) + '</button>';
            }).join('');
        }

        function renderRoute() {
            var r = routes[state.ri];
            elRoutebar.innerHTML = '<span class="health-meth health-meth--' + r.m + '">' + r.m + '</span>'
                + '<span class="health-route__path">' + pathHtml(r.p) + '</span>'
                + '<span class="pill ' + surfPillClass(r.s) + '">' + SURF[r.s] + '</span>' + badgesHtml(r)
                + '<span class="health-routebar__handler">' + esc(r.c) + 'Controller::' + esc(r.a) + '()</span>';
            S = stations(r, routes);
            var html = S.map(function (s, i) {
                var f = s.fail, fb = '';
                if (f) {
                    fb = '<div class="health-step__fail"><button type="button" class="health-failbtn" data-fail="' + s.id + '" aria-pressed="' + (state.fail === s.id) + '">si refus : <span class="mono">' + (f.status !== '—' ? f.status + ' ' : '') + esc(f.code) + '</span></button>'
                        + (f.alt ? '<span class="health-step__where">' + esc(f.alt) + '</span>' : '') + '</div>';
                }
                return '<li class="health-step" data-i="' + i + '" data-step-id="' + esc(s.id) + '"><span class="health-step__node" aria-hidden="true"></span>'
                    + '<div class="health-step__body"><div class="health-step__title"><span class="health-step__name">' + esc(s.name) + '</span>'
                    + '<span class="health-step__where">' + esc(s.where) + '</span></div><p class="health-step__does">' + esc(s.does) + '</p>' + fb + '</div></li>';
            }).join('');
            elRail.innerHTML = html + '<li aria-hidden="true" class="health-packet" id="health-packet" style="list-style:none"></li>';
            if (elBoom) {
                elBoom.setAttribute('aria-pressed', String(state.boom));
            }
        }

        function currentFailIndex() {
            return failIndex(S, state.fail, state.boom);
        }

        function packetTo(i, cls) {
            var p = byId(doc, 'health-packet');
            var items = elRail.querySelectorAll('.health-step');
            var li = items[i];
            if (!p || !li) return;
            var y = li.offsetTop + 8 + 6 + 11 - 8.5;
            p.style.transform = 'translateY(' + y + 'px)';
            p.className = 'health-packet' + (cls ? ' health-packet--' + cls : '');
        }

        function paint(upTo, finished) {
            var fi = currentFailIndex();
            var items = elRail.querySelectorAll('.health-step');
            items.forEach(function (li, i) {
                li.classList.remove('health-step--passed', 'health-step--active', 'health-step--failed', 'health-step--skipped');
                if (fi >= 0 && i === fi && upTo >= fi) li.classList.add('health-step--failed');
                else if (fi >= 0 && i > fi && upTo >= fi) li.classList.add('health-step--skipped');
                else if (i < upTo || (finished && i <= upTo)) li.classList.add('health-step--passed');
                else if (i === upTo) li.classList.add('health-step--active');
            });
        }

        function setResp(st, where, body, tone) {
            var c = (st === '—' || tone === 'warn') ? 'pill-warning' : (String(st).charAt(0) === '2' ? 'pill-success' : String(st).charAt(0) === '3' ? 'pill-info' : String(st).charAt(0) === '4' ? 'pill-warning' : 'pill-danger');
            elStatus.className = 'pill ' + c;
            elStatus.textContent = st === '—' ? 'bloquée' : st + ' ' + (REASON[st] || '');
            if (elRespWhere) elRespWhere.textContent = where;
            if (elBody) elBody.textContent = body;
        }

        function showResponse(kind) {
            var r = routes[state.ri], fi = currentFailIndex(), notes = [];
            if (kind === 'wait') {
                elStatus.className = 'pill';
                elStatus.textContent = 'en route…';
                if (elRespWhere) elRespWhere.textContent = 'la requête traverse les couches';
                if (elBody) elBody.textContent = '';
                if (elNotes) elNotes.innerHTML = '';
                return;
            }
            if (fi >= 0 && state.boom) {
                setResp(500, 'refus au niveau « ' + S[fi].name + ' »', '{\n  "data": null,\n  "error": {\n    "code": "INTERNAL_ERROR",\n    "message": "Internal server error"\n  }\n}');
                notes.push(['Côté serveur', 'La pile complète part dans le journal du conteneur, quel que soit APP_DEBUG. Côté client, un message générique : rien de la mécanique interne ne fuit.']);
                notes.push(['Toujours lisible par la borne', 'La réponse 500 est elle aussi décorée des en-têtes CORS, pour que le navigateur puisse lire l’erreur.']);
            } else if (fi >= 0) {
                var f = S[fi].fail;
                setResp(f.status, 'arrêt à l’étape « ' + S[fi].name + ' »', errorBody(f), f.tone);
                notes.push(['Ce qui s’est passé', 'La requête s’arrête ici : aucune étape suivante n’est exécutée, rien n’est écrit en base.']);
                if (f.pinNote) notes.push(['Même réponse si le compte est verrouillé', 'Un code verrouillé et un code faux renvoient la même chose et coûtent le même temps de calcul : de l’extérieur, rien ne distingue les deux cas. L’échec est écrit au journal d’audit.']);
                if (S[fi].id === 'perm' && r.s === 'api' && /\{number\}/.test(r.p) && r.w) notes.push(['Pas de 404 révélateur', 'Une commande inexistante et une commande hors des canaux du rôle renvoient toutes deux 403 : on ne peut pas sonder l’existence d’un numéro.']);
                if (S[fi].id === 'session' && r.s === 'api') notes.push(['Pourquoi 401 et pas une redirection', 'L’API répond en JSON, jamais par une redirection vers la page de connexion : un client HTTP doit pouvoir lire le refus.']);
            } else {
                var ok = successOf(r);
                setResp(ok.status, 'trajet complet, ' + S.length + ' étapes franchies', ok.body);
                if (r.p === '/api/orders' && r.m === 'POST') notes.push(['Le prix ne vient pas du client', 'Le montant est recalculé depuis la base à la création ; chaque ligne fige le libellé, le prix et la TVA appliqués.']);
                if (r.pin) notes.push(['Traçabilité', 'L’action et sa ligne d’audit sont écrites dans la même transaction : l’une ne peut pas exister sans l’autre.']);
                if (r.s === 'borne') notes.push(['Sans compte, mais pas sans garde', 'La borne est publique : la protection tient dans la validation serveur, l’idempotence des commandes et la lecture seule du catalogue.']);
                if (r.s === 'api') notes.push(['Mêmes règles que le back-office', 'L’API réutilise la session, le jeton, le code personnel et les permissions du back-office HTML ; seul le format de réponse change.']);
            }
            if (elNotes) {
                elNotes.innerHTML = notes.map(function (n) { return '<p><span class="health-notes__k">' + esc(n[0]) + '</span>' + esc(n[1]) + '</p>'; }).join('');
            }
        }

        function stopTimer() {
            if (state.timer) { clearTimeout(state.timer); state.timer = null; }
        }

        function atRest() {
            stopTimer();
            var fi = currentFailIndex(), last = fi >= 0 ? fi : S.length - 1;
            state.step = last;
            paint(last, true);
            packetTo(fi >= 0 ? fi : 0, fi >= 0 ? 'fail' : 'done');
            showResponse('final');
        }

        function advance() {
            var fi = currentFailIndex(), last = fi >= 0 ? fi : S.length - 1;
            if (state.step >= last) { finish(); return false; }
            state.step++;
            paint(state.step, false);
            packetTo(state.step, state.step === fi ? 'fail' : '');
            if (state.step === last) { state.timer = setTimeout(finish, REDUCED ? 0 : 600); return false; }
            return true;
        }

        function finish() {
            stopTimer();
            var fi = currentFailIndex(), last = fi >= 0 ? fi : S.length - 1;
            state.step = last;
            paint(last, true);
            packetTo(0, fi >= 0 ? 'fail' : 'done');
            showResponse('final');
        }

        function play() {
            stopTimer();
            if (REDUCED) { atRest(); return; }
            state.step = -1;
            paint(-1, false);
            packetTo(0, '');
            showResponse('wait');
            (function tick() { if (advance()) state.timer = setTimeout(tick, 650); })();
        }

        function load(ri, opts) {
            opts = opts || {};
            stopTimer();
            state.ri = ri;
            state.fail = opts.fail || null;
            state.boom = false;
            state.preset = (opts.preset !== undefined) ? opts.preset : null;
            renderPresets();
            renderRoute();
            markCurrentRoute(doc, state.ri);
            if (onLoad) { onLoad(state.ri); }
            if (opts.animate) { play(); } else { atRest(); }
        }

        elPresets && elPresets.addEventListener('click', function (e) {
            var b = e.target.closest('[data-p]'); if (!b) return;
            var pr = PRESETS[+b.getAttribute('data-p')];
            load(find(routes, pr.m, pr.p), { fail: pr.fail, preset: +b.getAttribute('data-p'), animate: true });
        });
        elRail.addEventListener('click', function (e) {
            var b = e.target.closest('[data-fail]'); if (!b) return;
            var id = b.getAttribute('data-fail');
            state.boom = false;
            state.fail = (state.fail === id) ? null : id;
            renderRoute();
            play();
        });
        var elPlay = byId(doc, 'health-play');
        var elStep = byId(doc, 'health-step-btn');
        var elReset = byId(doc, 'health-reset');
        elPlay && elPlay.addEventListener('click', play);
        elStep && elStep.addEventListener('click', function () {
            stopTimer();
            var fi = currentFailIndex(), last = fi >= 0 ? fi : S.length - 1;
            if (state.step >= last) { state.step = -1; paint(-1, false); packetTo(0, ''); showResponse('wait'); }
            advance();
        });
        elReset && elReset.addEventListener('click', function () { stopTimer(); state.fail = null; state.boom = false; renderRoute(); atRest(); });
        elBoom && elBoom.addEventListener('click', function () { state.boom = !state.boom; state.fail = null; renderRoute(); play(); });

        load(state.ri, { preset: 2 });

        return {
            loadRoute: function (ri, opts) { load(ri, opts); },
            currentIndex: function () { return state.ri; },
        };
    }

    /* ============================================================
     * Bloc 4 — La carte des routes
     * ============================================================ */

    var METHODS = ['GET', 'POST', 'PUT', 'DELETE'];
    var GROUPS = ['Catalogue', 'Commandes', 'Produits et recettes', 'Menus', 'Catégories', 'Ingrédients et stock', 'Comptes', 'Rôles et permissions', 'Pilotage', 'Connexion et compte', 'Système'];

    /**
     * Prédicat de filtre pur : surface (radio, 'all' = tout), méthodes cochées,
     * recherche texte (chemin, permission, contrôleur, action, surface, groupe),
     * "sous code personnel" (r.pin non nul).
     *
     * @param {object} r une entrée de routes
     * @param {{surface: string, methods: string[], query: string, onlyPin: boolean}} filters
     */
    function matchesFilters(r, filters) {
        var surfLabel = { borne: 'Borne publique', api: "API d'administration", bo: 'Back-office' };
        if (filters.surface && filters.surface !== 'all' && r.s !== filters.surface) return false;
        if (filters.methods && filters.methods.indexOf(r.m) < 0) return false;
        if (filters.onlyPin && !r.pin) return false;
        var q = (filters.query || '').trim().toLowerCase();
        if (q) {
            var hay = (r.m + ' ' + r.p + ' ' + (r.perm || '') + ' ' + r.c + 'Controller ' + r.a + ' ' + (surfLabel[r.s] || '') + ' ' + r.g).toLowerCase();
            if (hay.indexOf(q) < 0) return false;
        }
        return true;
    }

    /** Filtre une liste de routes ; retourne les INDEX (pas les objets) pour rester alignée sur `routes`. */
    function filterRoutes(routes, filters) {
        var kept = [];
        routes.forEach(function (r, i) { if (matchesFilters(r, filters)) kept.push(i); });
        return kept;
    }

    function readFilters(doc) {
        var checked = doc.querySelector('input[name="health-surf"]:checked');
        var methods = METHODS.filter(function (m) { var el = byId(doc, 'health-m-' + m); return el ? el.checked : true; });
        var q = byId(doc, 'health-q');
        var onlyPin = byId(doc, 'health-onlypin');
        return {
            surface: checked ? checked.value : 'all',
            methods: methods,
            query: q ? q.value : '',
            onlyPin: onlyPin ? onlyPin.checked : false,
        };
    }

    var markCurrentRoute = function (doc, ri) {
        var rows = doc.querySelectorAll('#health-routes-groups [data-ri]');
        rows.forEach(function (b) { b.setAttribute('aria-current', String(+b.getAttribute('data-ri') === ri)); });
    };

    function wireRoutesMap(doc, routes, onPick, onOpenConsole) {
        var groupsEl = byId(doc, 'health-routes-groups');
        if (!groupsEl) {
            return;
        }
        var SURF = { borne: 'Borne publique', api: "API d'administration", bo: 'Back-office' };
        var currentRi = -1;

        function badgesHtml(r) {
            var b = [];
            if (r.anon) b.push('<span class="pill pill-neutral">sans compte</span>');
            if (r.perm) b.push('<span class="pill pill-info">' + esc(r.perm) + '</span>');
            if (r.csrf) b.push('<span class="pill pill-warning">jeton</span>');
            if (r.pin) b.push('<span class="pill pill-danger">' + (r.pin === 'price' ? 'code perso si prix ou TVA' : 'code personnel') + '</span>');
            if (r.re === 'password') b.push('<span class="pill pill-neutral">mot de passe actuel</span>');
            return b.join('');
        }

        function render() {
            var filters = readFilters(doc);
            var kept = filterRoutes(routes, filters);
            var byGroup = {};
            kept.forEach(function (i) {
                var g = routes[i].g;
                (byGroup[g] = byGroup[g] || []).push(i);
            });
            var html = '';
            GROUPS.forEach(function (g) {
                var list = byGroup[g];
                if (!list) return;
                html += '<div class="health-group"><h3 class="health-group__title">' + esc(g) + ' <span class="mono">' + list.length + '</span></h3>';
                list.forEach(function (i) {
                    var r = routes[i];
                    // Un <button> ne peut pas en contenir un autre : le bouton "Ouvrir
                    // dans la console" (GET uniquement) est un FRÈRE de .health-route
                    // dans une enveloppe, jamais imbriqué dedans.
                    html += '<div class="health-route-row">';
                    html += '<button type="button" class="health-route" data-ri="' + i + '" aria-current="' + (i === currentRi) + '">'
                        + '<span class="health-meth health-meth--' + r.m + '">' + r.m + '</span>'
                        + '<span class="health-route__path">' + pathHtml(r.p) + '</span>'
                        + '<span class="health-badges">' + '<span class="pill ' + surfPillClass(r.s) + '">' + SURF[r.s] + '</span>' + badgesHtml(r) + '</span>'
                        + '<span class="health-route__handler">' + esc(r.c) + 'Controller::' + esc(r.a) + '()</span></button>';
                    if (r.m === 'GET') {
                        // aria-label distinct par ligne (pas juste "Ouvrir dans la
                        // console" répété) : au clavier/lecteur d'écran, la carte peut
                        // afficher des dizaines de lignes GET, toutes avec le même texte
                        // visible.
                        html += '<button type="button" class="btn btn-ghost btn-sm health-route-console-btn" data-console-ri="' + i + '" aria-label="Ouvrir ' + esc(r.m + ' ' + r.p) + ' dans la console">Ouvrir dans la console</button>';
                    }
                    html += '</div>';
                });
                html += '</div>';
            });
            groupsEl.innerHTML = html || '<p class="health-empty">Aucune route ne correspond à ces filtres.</p>';
            var count = byId(doc, 'health-count');
            if (count) {
                count.textContent = kept.length + ' route' + (kept.length > 1 ? 's' : '') + ' sur ' + routes.length;
            }
        }

        function setCurrent(ri) {
            currentRi = ri;
            markCurrentRoute(doc, ri);
        }

        groupsEl.addEventListener('click', function (e) {
            var openBtn = e.target.closest('[data-console-ri]');
            if (openBtn) {
                // Volontairement SANS setCurrent/onPick : ouvrir la console ne
                // sélectionne pas la route pour le trajet (bloc 3), qui reste un
                // choix séparé.
                if (onOpenConsole) onOpenConsole(+openBtn.getAttribute('data-console-ri'));
                return;
            }
            var b = e.target.closest('[data-ri]'); if (!b) return;
            var ri = +b.getAttribute('data-ri');
            setCurrent(ri);
            if (onPick) onPick(ri);
        });
        ['health-surf-all', 'health-surf-borne', 'health-surf-api', 'health-surf-bo', 'health-onlypin'].forEach(function (id) {
            var el = byId(doc, id);
            if (el) el.addEventListener('change', render);
        });
        METHODS.forEach(function (m) {
            var el = byId(doc, 'health-m-' + m);
            if (el) el.addEventListener('change', render);
        });
        var q = byId(doc, 'health-q');
        if (q) q.addEventListener('input', render);

        render();
        return { render: render, setCurrent: setCurrent };
    }

    /* ============================================================
     * Bloc 5 — Connexion JSON (démonstration), sans perte de session
     *
     * `credentials: 'omit'` est le coeur du contrat : d'après la spécification
     * Fetch (section "http network or cache fetch"), l'étape qui écrit un
     * cookie depuis un en-tête Set-Cookie ne s'exécute QUE si le mode
     * credentials de la requête n'est PAS "omit". Avec 'omit', le navigateur
     * envoie la requête sans le cookie de session courant ET ignore tout
     * Set-Cookie de la réponse : le cookie WAKDO_SID de qui regarde cette page
     * n'est donc jamais remplacé, quelle que soit la réussite de l'appel.
     * ============================================================ */

    /**
     * @returns {{url: string, init: object}} POST JSON, credentials 'omit'
     *   (cf. note ci-dessus) : jamais 'include' ni 'same-origin' ici.
     */
    function buildLoginRequest(email, password) {
        return {
            url: '/admin/api/auth/login',
            init: {
                method: 'POST',
                credentials: 'omit',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: email, password: password }),
            },
        };
    }

    /**
     * Séquence curl reproductible (contrat), avec l'hôte RÉEL de la page
     * courante et des marqueurs à la place des identifiants — jamais ceux
     * effectivement saisis dans le formulaire, pour ne pas afficher un mot de
     * passe réel dans un bloc de code.
     */
    function curlSnippet(origin) {
        return "curl -c cookies.txt -H 'Content-Type: application/json' -d '{\"email\":\"<email>\",\"password\":\"<mot de passe>\"}' "
            + origin + '/admin/api/auth/login\n'
            + 'curl -b cookies.txt ' + origin + '/admin/api/auth/me';
    }

    /**
     * Copie `token` dans le presse-papiers si l'API est disponible, sinon le
     * dit clairement (jamais un échec silencieux). Retourne la promesse pour
     * que l'appelant (et les tests) puisse l'attendre.
     */
    function copyToken(win, token, statusEl) {
        if (win && win.navigator && win.navigator.clipboard && typeof win.navigator.clipboard.writeText === 'function') {
            return win.navigator.clipboard.writeText(token).then(function () {
                if (statusEl) { statusEl.textContent = 'Jeton copié.'; }
            }).catch(function () {
                if (statusEl) { statusEl.textContent = 'Échec de la copie : copie-le manuellement.'; }
            });
        }
        if (statusEl) {
            statusEl.textContent = 'Copie indisponible dans ce navigateur : copie-le manuellement.';
        }
        return Promise.resolve();
    }

    /**
     * Câble le formulaire de connexion : soumission -> buildLoginRequest()
     * (credentials 'omit'), rendu XSS-safe du statut/corps, jeton csrf_token
     * affiché avec un bouton de copie s'il est présent. Le mot de passe n'est
     * JAMAIS journalisé, stocké, ni réaffiché : le champ est vidé après
     * l'appel, réussite ou échec.
     *
     * @returns {?{submit: function(?Event): Promise<void>}}
     */
    function wireLogin(doc) {
        var form = byId(doc, 'health-login-form');
        var emailInput = byId(doc, 'health-login-email');
        var passwordInput = byId(doc, 'health-login-password');
        var statusEl = byId(doc, 'health-login-status');
        var resultEl = byId(doc, 'health-login-result');
        var statusPill = byId(doc, 'health-login-resp-status');
        var respTime = byId(doc, 'health-login-resp-time');
        var bodyEl = byId(doc, 'health-login-body');
        var tokenRow = byId(doc, 'health-login-token-row');
        var copyBtn = byId(doc, 'health-login-copy-token');
        var copyStatus = byId(doc, 'health-login-copy-status');
        var curlEl = byId(doc, 'health-login-curl');
        if (!form || !emailInput || !passwordInput) {
            return null;
        }

        var win = doc.defaultView || (typeof window !== 'undefined' ? window : null);
        if (curlEl) {
            var origin = (win && win.location && win.location.origin) ? win.location.origin : '';
            curlEl.textContent = curlSnippet(origin);
        }

        var lastToken = null;

        async function submit(e) {
            if (e) {
                e.preventDefault();
            }
            var email = emailInput.value.trim();
            var password = passwordInput.value;
            lastToken = null;
            if (tokenRow) {
                tokenRow.hidden = true;
            }
            if (statusEl) {
                statusEl.textContent = 'Connexion en cours…';
            }
            if (resultEl) {
                resultEl.hidden = true;
            }
            var req = buildLoginRequest(email, password);
            var start = nowMs();
            try {
                var res = await fetch(req.url, req.init);
                var elapsed = nowMs() - start;
                var text = await res.text().catch(function () { return ''; });
                var desc = describeBody(text);
                writeStatusMeta(statusPill, respTime, res.status, elapsed);
                writeBody(bodyEl, desc.pretty);
                if (resultEl) {
                    resultEl.hidden = false;
                }
                if (desc.isJson && desc.json && desc.json.data && desc.json.data.csrf_token) {
                    lastToken = desc.json.data.csrf_token;
                    if (tokenRow) {
                        tokenRow.hidden = false;
                    }
                }
                if (statusEl) {
                    statusEl.textContent = res.ok
                        ? ('Connexion démonstrative réussie (' + res.status + ').')
                        : ('Échec (' + res.status + ') — voir la réponse ci-dessous.');
                }
            } catch (err) {
                if (statusEl) {
                    statusEl.textContent = 'Échec réseau : la requête n’a pas abouti.';
                }
            } finally {
                // Jamais réaffiché, jamais conservé : le champ est vidé après
                // l'appel, réussite ou échec (STRICT-3 : pas de coupure silencieuse
                // de cette garantie, elle s'applique aussi bien à un succès qu'à un
                // rejet).
                passwordInput.value = '';
            }
        }

        form.addEventListener('submit', submit);
        if (copyBtn) {
            copyBtn.addEventListener('click', function () {
                if (lastToken) {
                    copyToken(win, lastToken, copyStatus);
                }
            });
        }

        return { submit: submit };
    }

    /* ============================================================
     * Point d'entrée
     * ============================================================ */

    function init(doc) {
        var page = doc.querySelector('.health-page');
        if (!page) {
            return;
        }
        var routes = parseJsonAttr(page.getAttribute('data-routes'), []);
        var probes = parseJsonAttr(page.getAttribute('data-probes'), []);
        var csrfToken = page.getAttribute('data-csrf-token') || '';

        wireStatePolling(doc);
        wireProbes(doc, probes, csrfToken);
        var consoleApi = wireConsole(doc, routes);
        wireLogin(doc);

        // Reference croisee trajet <-> carte des routes : un clic sur une route de
        // la carte charge le trajet (onPick), et tout chargement du trajet (preset,
        // clic sur une route, refus simule) marque la meme route "actuelle" sur la
        // carte (onLoad). `routesMap` est construit en premier avec une reference
        // encore vide vers `trajet` (assignee juste apres) : au moment ou onPick/
        // onLoad s'executent reellement (au clic, ou au premier chargement), les
        // deux existent deja. Le bouton "Ouvrir dans la console" (GET uniquement)
        // delegue directement a consoleApi.openRoute, sans passer par onPick : il
        // ne selectionne pas la route pour le trajet (choix independant).
        var trajet;
        var routesMap = wireRoutesMap(doc, routes, function (ri) {
            if (trajet) {
                trajet.loadRoute(ri, { animate: true });
            }
        }, function (ri) {
            if (consoleApi) {
                consoleApi.openRoute(ri);
            }
        });
        trajet = wireTrajet(doc, routes, function (ri) {
            if (routesMap) {
                routesMap.setCurrent(ri);
            }
        });
    }

    var api = {
        init: init,
        // Fonctions pures, exportées pour les tests (node --test + jsdom, sans fetch ni timers réels) :
        stations: stations,
        successOf: successOf,
        errorBody: errorBody,
        failIndex: failIndex,
        find: find,
        buildProbeRequest: buildProbeRequest,
        probeMatches: probeMatches,
        matchesFilters: matchesFilters,
        filterRoutes: filterRoutes,
        applyReport: applyReport,
        pollHealth: pollHealth,
        runProbe: runProbe,
        runAllProbes: runAllProbes,
        wireTrajet: wireTrajet,
        wireRoutesMap: wireRoutesMap,
        wireProbes: wireProbes,
        // Addendum : réponse complète des sondes, console d'appels (lecture), connexion JSON.
        pickHeaders: pickHeaders,
        describeBody: describeBody,
        routeParamNames: routeParamNames,
        buildConsolePath: buildConsolePath,
        buildConsoleRequest: buildConsoleRequest,
        wireConsole: wireConsole,
        buildLoginRequest: buildLoginRequest,
        curlSnippet: curlSnippet,
        copyToken: copyToken,
        wireLogin: wireLogin,
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    if (typeof document !== 'undefined' && document.addEventListener) {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    }
})();
