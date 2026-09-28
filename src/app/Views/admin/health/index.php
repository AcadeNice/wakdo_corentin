<?php

declare(strict_types=1);

/**
 * Page "Santé de l'API" (/admin/health, permission role.manage), injectée dans
 * admin/layout.php. Quatre blocs (contrat scratchpad/health-contrat.md) :
 *
 *  1. État en direct : les valeurs de $report sont écrites ICI, côté serveur —
 *     la page reste utile sans JavaScript. health.js relit GET /admin/api/health
 *     toutes les 15 s et remplace les mêmes noeuds (mêmes ids).
 *  2. Appels réels : un bouton par sonde de $probes, un vrai fetch construit par
 *     health.js depuis la description de CHAQUE sonde (transmise en JSON via
 *     data-probe, jamais une requête écrite en dur côté client).
 *  3. Le trajet animé : porté fidèlement de l'artifact validé
 *     (scratchpad/apimap/template.html), alimenté par $routes.
 *  4. La carte des routes : mêmes filtres que l'artifact, alimentée par $routes.
 *
 * Les blocs 2 à 4 sont entièrement construits par health.js (comme le
 * constructeur de recette ou de slots de menu) : un <noscript> le signale.
 * Le bloc 1 seul est un fragment HTML statique, indépendant du script.
 *
 * CSP script-src 'self' : aucun script en ligne. Les données transmises au script
 * (routes, sondes, jeton CSRF) passent par des attributs data-*, échappées avec
 * le même utilitaire $attr que admin/products/form.php.
 *
 * @var array<string, mixed>            $report  App\Health\HealthReport::build()
 * @var list<array<string, mixed>>      $routes  App\Health\RouteMap::rows()
 * @var list<array<string, mixed>>      $probes  App\Health\Probes::all()
 * @var string                           $csrfToken
 * @var callable(string): string         $asset
 */

$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$attr = static fn (mixed $data): string => htmlspecialchars((string) json_encode($data, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

/** @var array<string, mixed> $rep */
$rep = isset($report) && is_array($report) ? $report : [];
/** @var list<array<string, mixed>> $routeRows */
$routeRows = isset($routes) && is_array($routes) ? $routes : [];
// Reponses reelles capturees (App\Health\CapturedResponses) : lues par le trajet quand
// il ne peut pas faire l'appel lui-meme. Absentes -> objet vide, et la page le dit.
$capturedResponses = isset($responses) && is_array($responses) ? $responses : ['routes' => new \stdClass()];
/** @var list<array<string, mixed>> $probeRows */
$probeRows = isset($probes) && is_array($probes) ? $probes : [];
$csrf = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');

/** @var array<string, mixed> $db */
$db = is_array($rep['db'] ?? null) ? $rep['db'] : [];
/** @var array<string, mixed> $migrations */
$migrations = is_array($rep['migrations'] ?? null) ? $rep['migrations'] : [];
/** @var array<string, mixed> $seeds */
$seeds = is_array($rep['seeds'] ?? null) ? $rep['seeds'] : [];
/** @var array<string, mixed> $activity */
$activity = is_array($rep['activity_24h'] ?? null) ? $rep['activity_24h'] : [];

$dbOk = ($db['ok'] ?? null) === true;
$displayErrorsOff = ($rep['display_errors_off'] ?? null) === true;
$debugOn = ($rep['debug'] ?? null) === true;

$fmtNum = static fn (mixed $v): string => ($v === null) ? '—' : (string) $v;
$fmtCount = static function (mixed $applied, mixed $files) use ($fmtNum): string {
    if ($files === null) {
        return $fmtNum($applied) . ' (nombre de fichiers inconnu)';
    }

    return $fmtNum($applied) . ' / ' . $fmtNum($files);
};

// generated_at est au format ATOM (ISO 8601 avec fuseau), ecrit par
// HealthReport::build(). Repli sur la valeur brute si le format change un jour :
// mieux vaut un horodatage un peu moins joli qu'un champ vide.
$generatedAtHuman = null;
if (is_string($rep['generated_at'] ?? null) && $rep['generated_at'] !== '') {
    $d = \DateTimeImmutable::createFromFormat(DATE_ATOM, $rep['generated_at']);
    $generatedAtHuman = $d !== false ? $d->format('d/m/Y H:i:s') : $rep['generated_at'];
}

$activityKnown = array_key_exists('orders_created', $activity) && $activity['orders_created'] !== null;
?>
<div class="health-page"
     data-routes="<?= $attr($routeRows) ?>"
     data-responses="<?= $attr($capturedResponses) ?>"
     data-probes="<?= $attr($probeRows) ?>"
     data-csrf-token="<?= $csrf ?>">

    <div class="page-header">
        <div>
            <h1 class="page-title">Santé de l'API</h1>
            <p class="page-subtitle">Ce que le serveur voit de lui-même, les appels réels qu'on peut lui envoyer, et le trajet exact que suit chaque requête à travers les mêmes couches.</p>
        </div>
    </div>

    <nav class="toc" aria-label="Sommaire de la page">
        <p class="toc__label">Aller à la section :</p>
        <ul class="toc__list">
            <li><a href="#health-etat">État en direct</a></li>
            <li><a href="#health-sondes">Appels réels</a></li>
            <li><a href="#health-console">Console d'appels</a></li>
            <li><a href="#health-trajet">Le trajet d'un appel</a></li>
            <li><a href="#health-routes">La carte des routes</a></li>
            <li><a href="#health-login">Connexion (démonstration API)</a></li>
        </ul>
    </nav>

    <!-- ============================================================
         Bloc 1 — État en direct. Rendu ENTIÈREMENT côté serveur ; utile
         sans JavaScript. health.js relit /admin/api/health toutes les 15 s
         et remplace le contenu des mêmes noeuds (ids ci-dessous).
         ============================================================ -->
    <section class="health-section" id="health-etat" aria-labelledby="h-health-etat">
        <h2 id="h-health-etat">État en direct</h2>
        <p class="health-section-lede">Généré le <?= $esc($generatedAtHuman ?? '—') ?>, puis relu automatiquement toutes les 15 secondes tant que cet onglet est visible.</p>

        <div class="health-kpi-grid">
            <div class="kpi-card">
                <p class="kpi-label">Base de données</p>
                <p class="kpi-value">
                    <span class="pill <?= $dbOk ? 'pill-success' : 'pill-danger' ?>" id="health-db-pill"><?= $dbOk ? 'Joignable' : 'Injoignable' ?></span>
                </p>
                <p class="health-kpi-detail" id="health-db-detail"><?php
                    if ($dbOk) {
                        echo $esc($fmtNum($db['latency_ms'] ?? null) . ' ms · ' . (string) ($db['server_version'] ?? '—'));
                    } else {
                        echo 'Aucune donnée disponible : la base ne répond pas.';
                    }
                ?></p>
            </div>

            <div class="kpi-card">
                <p class="kpi-label">Affichage des erreurs</p>
                <p class="kpi-value">
                    <span class="pill <?= $displayErrorsOff ? 'pill-success' : 'pill-danger' ?>" id="health-debug-pill"><?= $displayErrorsOff ? 'Coupé' : 'Actif' ?></span>
                </p>
                <p class="health-kpi-detail" id="health-debug-detail">Environnement <?= $esc((string) ($rep['app_env'] ?? '—')) ?> · débogage <?= $debugOn ? 'activé' : 'désactivé' ?> · PHP <?= $esc((string) ($rep['php_version'] ?? '—')) ?></p>
            </div>

            <div class="kpi-card">
                <p class="kpi-label">Version servie</p>
                <p class="kpi-value mono" id="health-version"><?= $esc((string) ($rep['version'] ?? '—')) ?></p>
                <p class="health-kpi-detail" id="health-deployed-at"><?= isset($rep['deployed_at']) && $rep['deployed_at'] !== null ? 'déployée le ' . $esc((string) $rep['deployed_at']) : 'date de déploiement inconnue' ?></p>
            </div>

            <div class="kpi-card">
                <p class="kpi-label">Migrations</p>
                <p class="kpi-value" id="health-migrations"><?= $esc($fmtCount($migrations['applied'] ?? null, $migrations['files'] ?? null)) ?></p>
                <p class="health-kpi-detail" id="health-migrations-last"><?= isset($migrations['last']) && $migrations['last'] !== null ? 'dernière : ' . $esc((string) $migrations['last']) : 'aucune migration appliquée' ?></p>
            </div>

            <div class="kpi-card">
                <p class="kpi-label">Seeds</p>
                <p class="kpi-value" id="health-seeds"><?= $esc($fmtCount($seeds['applied'] ?? null, $seeds['files'] ?? null)) ?></p>
                <p class="health-kpi-detail" id="health-seeds-last"><?= isset($seeds['last']) && $seeds['last'] !== null ? 'dernier : ' . $esc((string) $seeds['last']) : 'aucun seed appliqué' ?></p>
            </div>

            <div class="kpi-card">
                <p class="kpi-label">Activité (24 h)</p>
                <p class="kpi-value" id="health-activity"><?php
                    if ($activityKnown) {
                        $n = (int) $activity['orders_created'];
                        echo $esc((string) $n . ' créée' . ($n === 1 ? '' : 's'));
                    } else {
                        echo 'indisponible';
                    }
                ?></p>
                <p class="health-kpi-detail" id="health-activity-detail"><?php
                    if ($activityKnown) {
                        $paid = (int) ($activity['orders_paid'] ?? 0);
                        $audit = (int) ($activity['audit_lines'] ?? 0);
                        $pinFail = (int) ($activity['pin_failures'] ?? 0);
                        echo $esc($paid . ' payée' . ($paid === 1 ? '' : 's') . ' · ' . $audit . " ligne" . ($audit === 1 ? '' : 's') . " d'audit" . ' · ' . $pinFail . ' échec' . ($pinFail === 1 ? '' : 's') . ' de code personnel');
                    } else {
                        echo "Base injoignable : aucun décompte disponible.";
                    }
                ?></p>
            </div>
        </div>

        <p class="health-refresh-status" id="health-refresh-status" role="status" aria-live="polite">
            Dernière lecture réussie : <time id="health-refresh-time"><?= $esc($generatedAtHuman ?? '—') ?></time>
        </p>
        <p class="health-refresh-status health-refresh-status--error" id="health-refresh-error" role="alert" aria-live="assertive" hidden></p>
    </section>

    <!-- ============================================================
         Bloc 2 — Appels réels. Un fetch par sonde ($probes), construit par
         health.js depuis la description transmise en data-probe (méthode,
         identifiants envoyés ou non, en-tête anti-rejeu envoyé ou non, corps).
         ============================================================ -->
    <section class="health-section" id="health-sondes" aria-labelledby="h-health-sondes">
        <h2 id="h-health-sondes">Appels réels</h2>
        <p class="health-section-lede">Sept appels réels, envoyés à l'API depuis ce navigateur. Aucun n'écrit quoi que ce soit : chacun est refusé avant toute écriture, par construction.</p>

        <noscript><p class="health-noscript">Active JavaScript pour lancer ces appels réels vers l'API.</p></noscript>

        <ul class="health-probes" id="health-probes">
            <?php foreach ($probeRows as $probe): ?>
                <?php
                $pid = (string) ($probe['id'] ?? '');
                $method = (string) ($probe['method'] ?? 'GET');
                ?>
                <li class="health-probe card" data-probe-id="<?= $esc($pid) ?>" data-probe="<?= $attr($probe) ?>">
                    <div class="health-probe-head">
                        <span class="health-meth health-meth--<?= $esc($method) ?>"><?= $esc($method) ?></span>
                        <code class="mono health-probe-url"><?= $esc((string) ($probe['url'] ?? '')) ?></code>
                        <button class="btn btn-ghost btn-sm" type="button" data-probe-detail-toggle="<?= $esc($pid) ?>" aria-expanded="false" aria-controls="health-probe-detail-<?= $esc($pid) ?>" aria-label="Détails de la réponse : <?= $esc((string) ($probe['label'] ?? '')) ?>">Détails</button>
                        <button class="btn btn-secondary btn-sm" type="button" data-probe-run>Lancer</button>
                    </div>
                    <p class="health-probe-label"><?= $esc((string) ($probe['label'] ?? '')) ?></p>
                    <p class="health-probe-why"><?= $esc((string) ($probe['why'] ?? '')) ?></p>
                    <p class="health-probe-result" data-probe-result role="status" aria-live="polite">Pas encore lancé.</p>
                    <!-- Réponse complète (en-têtes retenus + corps JSON indenté), reconstruite
                         par health.js à chaque lancement, repliée par défaut (bouton "Détails"
                         ci-dessus). Contenu écrit par textContent uniquement : un corps de
                         réponse est une donnée non fiable, jamais interprétée comme du HTML. -->
                    <div class="health-probe-detail" id="health-probe-detail-<?= $esc($pid) ?>" hidden></div>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="health-probes-actions">
            <button class="btn btn-primary" id="health-run-all" type="button">Tout lancer</button>
            <p class="health-probes-summary" id="health-probes-summary" role="status" aria-live="polite"></p>
        </div>
    </section>

    <!-- ============================================================
         Bloc 2bis — Console d'appels, lecture seule. N'importe quelle route
         GET de la carte des routes (bloc 4), avec la session courante
         (credentials: 'same-origin'). GET uniquement : garanti par le code
         (buildConsolePath/buildConsoleRequest refusent toute autre méthode),
         pas seulement par ce qui est proposé ici.
         ============================================================ -->
    <section class="health-section" id="health-console" aria-labelledby="h-health-console">
        <h2 id="h-health-console">Console d'appels (lecture)</h2>
        <p class="health-section-lede">Choisis n'importe quelle route <strong>GET</strong> de la carte plus bas, renseigne ses paramètres, et lance l'appel avec ta session actuelle. Aucune autre méthode n'est proposée : la console n'appelle jamais qu'une lecture.</p>

        <noscript><p class="health-noscript">Active JavaScript pour utiliser la console d'appels.</p></noscript>

        <div class="card health-console-card">
            <div class="health-console-form">
                <div class="form-group">
                    <label class="form-label" for="health-console-route">Route (GET uniquement)</label>
                    <select class="form-select" id="health-console-route"></select>
                </div>
                <div class="health-console-params" id="health-console-params"></div>
                <div class="health-console-actions">
                    <button class="btn btn-primary" type="button" id="health-console-send">Envoyer</button>
                    <p class="health-console-status" id="health-console-status" role="status" aria-live="polite"></p>
                </div>
            </div>

            <div class="health-console-result" id="health-console-result" hidden>
                <div class="health-resp-head">
                    <span class="pill" id="health-console-resp-status">—</span>
                    <span class="health-resp-where" id="health-console-resp-time"></span>
                </div>
                <!-- En-têtes et corps écrits par health.js via textContent uniquement
                     (writeHeaders/writeBody) : une réponse d'API n'est jamais interprétée
                     comme du HTML, même si son contenu en contient. -->
                <div class="health-headers-box" id="health-console-headers"></div>
                <pre class="health-body" id="health-console-body"></pre>
            </div>
        </div>
    </section>

    <!-- ============================================================
         Bloc 3 — Le trajet animé. Porte fidèlement l'artifact validé
         (scratchpad/apimap/template.html), alimenté par data-routes.
         ============================================================ -->
    <section class="health-section" id="health-trajet" aria-labelledby="h-health-trajet">
        <h2 id="h-health-trajet">Le trajet d'un appel</h2>
        <p class="health-section-lede">Cinq appels types pour démarrer. N'importe quelle route de la carte plus bas se charge ici d'un clic. Les réponses sont réelles : une lecture est envoyée au serveur quand tu lances l'appel ; une écriture, qui modifierait les données, n'est jamais envoyée depuis cette page, et le trajet montre la réponse obtenue pour de vrai sur une pile de test. Clique un code de refus sous une étape pour voir ce refus.</p>

        <noscript><p class="health-noscript">Active JavaScript pour suivre le trajet d'un appel étape par étape.</p></noscript>

        <div class="health-presets" id="health-presets" role="group" aria-label="Appels types"></div>

        <div class="health-scene">
            <div class="card health-trajet-card">
                <div class="health-routebar" id="health-routebar"></div>
                <div class="health-controls">
                    <button class="btn btn-primary" id="health-play" type="button">Lancer l'appel</button>
                    <button class="btn btn-secondary" id="health-step-btn" type="button">Étape suivante</button>
                    <button class="btn btn-ghost" id="health-reset" type="button">Recommencer</button>
                    <button class="btn btn-danger" id="health-boom" type="button" aria-pressed="false">Simuler une exception</button>
                    <span class="health-hint">Clique un code de refus sous une étape pour simuler ce refus.</span>
                </div>
                <ol class="health-rail" id="health-rail" aria-label="Couches traversées par l'appel"></ol>
            </div>

            <aside class="health-side" aria-label="Réponse renvoyée">
                <div class="card" aria-live="polite">
                    <div class="health-resp-head">
                        <span class="pill" id="health-status">—</span>
                        <span class="health-resp-where" id="health-respwhere"></span>
                    </div>
                    <p class="health-resp-source" id="health-respsource"></p>
                    <pre class="health-body" id="health-respbody"></pre>
                    <p class="health-req-label">Requête envoyée</p>
                    <pre class="health-body health-req" id="health-reqbody"></pre>
                    <div class="health-notes" id="health-notes"></div>
                </div>
            </aside>
        </div>
    </section>

    <!-- ============================================================
         Bloc 4 — La carte des routes. Filtres alimentés par data-routes ;
         un clic sur une route la charge dans le trajet (bloc 3).
         ============================================================ -->
    <section class="health-section" id="health-routes" aria-labelledby="h-health-routes">
        <h2 id="h-health-routes">La carte des routes</h2>
        <p class="health-section-lede">Toutes les routes déclarées dans le contrôleur frontal, avec ce que chacune exige. Clique une ligne pour la suivre dans le trajet ci-dessus.</p>

        <noscript><p class="health-noscript">Active JavaScript pour afficher et filtrer la carte des routes.</p></noscript>

        <div class="card">
            <div class="health-filters">
                <fieldset class="health-filter-group">
                    <legend>Surface</legend>
                    <label><input type="radio" name="health-surf" id="health-surf-all" value="all" checked> Toutes</label>
                    <label><input type="radio" name="health-surf" id="health-surf-borne" value="borne"> Borne publique</label>
                    <label><input type="radio" name="health-surf" id="health-surf-api" value="api"> API d'administration</label>
                    <label><input type="radio" name="health-surf" id="health-surf-bo" value="bo"> Back-office</label>
                </fieldset>

                <fieldset class="health-filter-group">
                    <legend>Méthodes</legend>
                    <?php foreach (['GET', 'POST', 'PUT', 'DELETE'] as $m): ?>
                        <label><input type="checkbox" id="health-m-<?= $m ?>" value="<?= $m ?>" checked> <span class="health-meth health-meth--<?= $m ?>"><?= $m ?></span></label>
                    <?php endforeach; ?>
                </fieldset>

                <div class="search-field">
                    <input type="search" id="health-q" placeholder="Chercher un chemin, une permission, un contrôleur" aria-label="Chercher une route">
                </div>

                <label class="health-checkline"><input type="checkbox" id="health-onlypin"> sous code personnel</label>

                <span class="health-count" id="health-count" aria-live="polite"></span>
            </div>

            <div class="health-legend">
                <span class="pill pill-neutral">sans compte</span>
                <span class="pill pill-info">permission</span>
                <span class="pill pill-warning">jeton anti-rejeu</span>
                <span class="pill pill-danger">code personnel</span>
            </div>

            <div class="health-groups" id="health-routes-groups"></div>
        </div>
    </section>

    <!-- ============================================================
         Bloc 5 — Connexion JSON (démonstration), sans perte de session.
         credentials: 'omit' (health.js, buildLoginRequest) : d'après la
         spécification Fetch, un Set-Cookie de la réponse n'est appliqué QUE si
         le mode credentials n'est pas 'omit' — le cookie de session de qui
         regarde cette page n'est donc jamais remplacé.
         ============================================================ -->
    <section class="health-section" id="health-login" aria-labelledby="h-health-login">
        <h2 id="h-health-login">Connexion (démonstration API)</h2>
        <p class="health-section-lede">Un appel réel vers <code>/admin/api/auth/login</code>, envoyé sans les identifiants de cette page (<code>credentials: 'omit'</code>) : ta session actuelle n'est pas remplacée. Le mot de passe n'est jamais conservé ni réaffiché.</p>

        <noscript><p class="health-noscript">Active JavaScript pour utiliser ce formulaire de démonstration.</p></noscript>

        <div class="card health-login-card">
            <form id="health-login-form" autocomplete="off">
                <div class="form-group">
                    <label class="form-label" for="health-login-email">Email</label>
                    <input class="form-input" type="email" id="health-login-email" name="email" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="health-login-password">Mot de passe</label>
                    <input class="form-input" type="password" id="health-login-password" name="password" autocomplete="off" required>
                </div>
                <button class="btn btn-primary" type="submit">Se connecter (démonstration)</button>
                <p class="health-console-status" id="health-login-status" role="status" aria-live="polite"></p>
            </form>

            <div class="health-console-result" id="health-login-result" hidden>
                <div class="health-resp-head">
                    <span class="pill" id="health-login-resp-status">—</span>
                    <span class="health-resp-where" id="health-login-resp-time"></span>
                </div>
                <!-- Corps écrit par textContent uniquement (writeBody) : jamais interprété
                     comme du HTML, y compris sur un message d'erreur. -->
                <pre class="health-body" id="health-login-body"></pre>
                <div class="health-login-token" id="health-login-token-row" hidden>
                    <button class="btn btn-secondary btn-sm" type="button" id="health-login-copy-token">Copier le jeton csrf_token</button>
                    <span class="health-login-copy-status" id="health-login-copy-status" role="status" aria-live="polite"></span>
                </div>
            </div>

            <div class="health-login-explain">
                <p><strong>Pourquoi la session ne peut pas être récupérée depuis cette page :</strong> le cookie de session est <code>HttpOnly</code>, volontairement illisible par tout script, y compris celui-ci. Chaque essai, réussi ou non, compte dans la limitation des connexions par adresse.</p>
                <p>Pour rejouer cette connexion en ligne de commande (Postman, Bruno, curl), avec un fichier de cookies :</p>
                <pre class="health-body health-curl" id="health-login-curl"></pre>
            </div>
        </div>
    </section>

    <footer class="health-footer">
        <ul>
            <li>Routes : <a href="https://git.acadenice.com/AcadeNice/corentin_wakdo/src/branch/dev/src/app/Core/routes.php">src/app/Core/routes.php</a></li>
            <li>Permission exacte de chaque route de l'API d'administration, figée par un test : <a href="https://git.acadenice.com/AcadeNice/corentin_wakdo/src/branch/dev/tests/Unit/Admin/Api/RouteMatrixTest.php">tests/Unit/Admin/Api/RouteMatrixTest.php</a></li>
            <li>Frontière des deux sites : <a href="https://git.acadenice.com/AcadeNice/corentin_wakdo/src/branch/dev/docker/apache/vhost.conf">docker/apache/vhost.conf</a> · gardes JSON : <a href="https://git.acadenice.com/AcadeNice/corentin_wakdo/src/branch/dev/src/app/Controllers/Admin/Api/JsonApiTrait.php">JsonApiTrait.php</a> · code personnel : <a href="https://git.acadenice.com/AcadeNice/corentin_wakdo/src/branch/dev/src/app/Auth/PinGate.php">PinGate.php</a></li>
        </ul>
        <p>Les réponses du trajet sont réelles : envoyées depuis cette page pour une lecture, ou capturées sur une pile de test jetable, jamais sur la production, pour une écriture (<a href="https://git.acadenice.com/AcadeNice/corentin_wakdo/src/branch/dev/tests/e2e/health-capture.spec.js">tests/e2e/health-capture.spec.js</a>). La date et le commit de la capture sont indiqués sous chaque réponse capturée.</p>
    </footer>
</div>
<script src="<?= $asset('/assets/js/health.js') ?>"></script>
