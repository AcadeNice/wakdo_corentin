# Architecture — Wakdo

**Version** : v0.6 (2026-09-29 apres-midi) — revue adversariale des correctifs du matin
(`680820f`) : `password_reset_throttle` purgee par le cron et adresse stockee en empreinte
SHA-256 (migration `0021`), changement de mot de passe par l'admin qui ferme les sessions du
compte ; modification de menu par appariement (type, nom) avec verrou (`e9f00d8`). Tout le
29/09 est dans le code de la branche `docs/contre-audit`, en production apres la release du
29/09. Version v0.5 (2026-09-29) — deux correctifs de securite : en-tetes HTTP durcis,
`TraceEnable Off`, sonde `/api/health` sans version PHP (commit `08d7a96`) ; garde de
session relisant `role_id`/`session_epoch` en base a chaque requete, `SessionRoutePolicy`
(aucune session sur `/api/*`), throttle de reinitialisation de mot de passe (commit
`ef7fd37`) ; 24 tables (section 8). Version v0.4 (2026-09-29) — recalage sur un contre-audit : le modele versionne
`docker-compose.prod.yml.example` declare bien un subnet explicite pour
`wakdo_internal` (la v0.3 affirmait a tort le contraire) ; `/api/*` est repositionne
comme public/sans CSRF face a `/admin/api/*` qui est le prefixe authentifie ; la borne
consomme l'API en meme origine via le proxy du vhost kiosk, pas via CORS ; la liste des
controleurs et des actions PIN est recalee sur `RouteSecurity.php`. Version v0.3
(2026-09-24, 2a09597) conservee pour memoire : le subnet etait alors decrit comme un
reglage propre a l'hote ; les tests Playwright, qui existent et se lancent a la main,
n'etaient plus presentes comme a venir.

Vue d'ensemble technique du projet (borne de commande fast-food, certification RNCP 37805).
Point d'entree pour comprendre la stack, le decoupage et les choix de conception.

- Scope metier, planning, mapping RNCP : `docs/PROJECT_CONTEXT.md`.
- Modelisation detaillee (entites, operations, regles) : `docs/merise/` (dictionary, mcd, mct, mlt).
- Decisions tracees : `docs/adr/` et `docs/journal/`.

**Auteur : BYAN** (formalisation ; arbitrage et validation par l'auteur du projet).

---

## 1. Vue d'ensemble

Wakdo simule une borne de commande tactile de restauration rapide, avec back-office
d'administration, workflow cuisine et API REST interne. Deux surfaces applicatives :

- **Borne (kiosk)** — front statique (HTML/CSS/JS vanilla ES6) servi par Apache,
  consommant l'API REST DB-backed `/api/*` (catalogue + commande), PUBLIQUE et sans
  CSRF. Le repli JSON statique initial a ete retire au profit d'un branchement direct
  sur l'API.
- **Back-office + API** — application PHP rendue serveur (MVC maison) + une seconde
  API JSON sous `/admin/api/*` (distincte de `/api/*`, section 3 de
  `docs/api/conventions.md`), celle-ci derriere authentification, RBAC et CSRF.

Trois canaux de commande (`source`) : `kiosk`, `counter`, `drive`. Le cycle de vie
d'une commande et la machine a etats sont decrits dans `docs/merise/`. Le domaine
commande est livre de bout en bout : creation et encaissement via l'API
(`POST /api/orders`, `POST /api/orders/{number}/pay` avec decrement de stock),
file cuisine (KDS), annulation et livraison cote back-office, saisie comptoir et
drive (POS tactile).

---

## 2. Stack technique

| Couche | Techno | Note |
|---|---|---|
| Langage back | PHP 8.3 | from scratch, sans framework |
| Autoloader | PSR-4 manuel (`spl_autoload_register`) | namespace `App\` -> `src/app/` |
| Base de donnees | MariaDB 11.4 | PDO, requetes preparees uniquement |
| Serveur web | Apache httpd 2.4 (Alpine) | reverse FastCGI -> PHP-FPM |
| Serveur app | PHP-FPM 8.3 (Alpine) | execute le code back-office + API |
| Front borne | HTML5 + CSS3 + JS ES6 (modules) | vanilla, sans build |
| Conteneurisation | Docker + docker compose v2 | `docker compose up` = stack complete |
| Tests PHP | PHPUnit 11 (`.phar`, sans Composer) | unit + integration DB |
| Tests front | node:test + jsdom | harnais kiosk (`tests/js/`) |
| Analyse statique | PHPStan niveau 6 (`.phar`) | |
| CI/CD | Forgejo Actions | secret-scan, lint, tests ; merge natif sur CI verte |
| Versioning | Git + Forgejo (`git.acadenice.com`, miroir GitHub) | Conventional Commits |

Justifications (composer-less, from-scratch, etc.) : `docs/PROJECT_CONTEXT.md` section 6.

---

## 3. Topologie de deploiement

Cinq services Docker. Deux modes, par fichier compose :

- **`docker-compose.yml`** (versionne) — standalone : tourne en local sans configuration.
  `wakdo-web` publie un port hote (`${HTTP_PORT:-8080}`), reseau interne seul.
- **`docker-compose.prod.yml`** (gitignore, propre a chaque hote) — meme stack exposee
  via un reverse proxy Traefik (reseau externe + labels TLS), sans port hote.

```
                      [ docker compose up -d ]
                                |
        wakdo-db (MariaDB 11.4, healthcheck)
                                |  service_healthy
                                v
        wakdo-migrate (one-shot : migrations + seed idempotents, puis sort)
                                |  service_completed_successfully
                +---------------+----------------+
                v                                v
        wakdo-app (PHP-FPM 8.3)          wakdo-web (Apache)
                ^   FastCGI :9000  <-----------/   publie ${HTTP_PORT}:80 (mode local)
                |                                  ou labels Traefik (mode prod)
                |
        wakdo-db <-- PDO

        wakdo-cron (dcron + PHP CLI) : backup BDD, purges retention (RGPD),
                             expiration des commandes en attente de paiement
```

- **Reseau** : `wakdo_internal` (bridge) isole les services ; aucun port hote en mode
  prod (acces par le proxy). En mode local, seul `wakdo-web` publie un port.
- **Volumes** : `wakdo_db_data` (persistance MariaDB), `wakdo_uploads` (images produits) ;
  bind-mount `./var/backups` pour les dumps.
- **`wakdo-cron`** utilise `init: true` (tini comme PID 1 : dcron a besoin d'un init
  parent pour `setpgid` sur ses jobs). Il embarque **PHP en ligne de commande** et monte
  `./src` en **lecture seule** : certaines taches planifiees appellent le code metier
  plutot que de reecrire une regle en SQL. L'expiration des commandes en attente de
  paiement est une transition de la machine a etats de la commande — la mettre en SQL dans
  un script donnerait deux proprietaires a cette machine (voir
  `docs/adr/0014-expiration-commandes-pending.md`). Les purges de retention, elles,
  restent en bash : tables techniques, aucun etat metier, aucune trace a ecrire.
- **Subnet de `wakdo_internal` en production** : l'hote mutualise a un allocateur Docker
  sature ; le modele versionne `docker-compose.prod.yml.example` fixe donc un subnet
  RFC 1918 explicite (`192.168.148.0/24`) pour eviter l'echec d'allocation automatique,
  a ajuster si ce bloc entre en collision avec un autre reseau de l'hote.

Detail reseaux/volumes : `docs/PROJECT_CONTEXT.md` section 5.

---

## 4. Demarrage : une commande (Cr 7.c.4)

`docker compose up -d` amene une stack complete et utilisable :

1. `wakdo-db` demarre, devient *healthy* (script `healthcheck.sh` de l'image).
2. `wakdo-migrate` (service one-shot) applique, par le reseau et de maniere
   **idempotente** :
   - `db/migrations/*.sql` — suivi dans la table `schema_migrations` ;
   - `db/seeds/*.sql` — suivi dans la table `seeds_applied`.
   Relancer ne rejoue que les fichiers en attente. Le runner : `db/migrate-container.sh`.
3. `wakdo-app` et `wakdo-web` attendent la **completion** de `wakdo-migrate`
   (`depends_on: service_completed_successfully`) avant de servir.

Le schema (DDL) et les donnees de reference (roles, permissions, catalogue, admin
bootstrap) sont donc en place sans etape manuelle. `db/migrate.sh` (hote, via
`docker exec`) reste disponible pour l'usage manuel / `--status`.

> Migration de mecanisme : sur une base **deja seedee** avant l'introduction du suivi
> (`seeds_applied` absente), back-filler la table avant le premier `up` (sinon re-seed
> -> conflits d'unicite). Volume vierge : aucun souci. Cf.
> `docs/journal/2026-06-17--makefile-to-compose-migrate.md`.

---

## 5. Structure du code

Namespace `App\` -> `src/app/` (PSR-4 manuel). Front controller du vhost admin :
`src/public/admin/index.php` (Apache reecrit tout vers ce fichier ; le routeur voit
le `REQUEST_URI` intact).

```
src/app/
  Core/          Autoloader, Config, Database (PDO), Request, Response, Router (routes.php),
                 Cors, Asset, ErrorResponse/ErrorDisplay, Money, NumericInput
  Auth/          AuthService, SessionManager, SessionGuard, Authorizer, PinVerifier,
                 PinGate, PinThrottle, ThrottlePolicy, PasswordHasher, Csrf,
                 PasswordResetService, PasswordResetThrottle, SessionRoutePolicy,
                 AuthResult, GuardResult, UserRepository, RoleRepository, UserDirectory,
                 Mailer/LogMailer/SmtpMailer, SmtpClient/SmtpTransport/StreamSmtpTransport
  Catalogue/     Category / Product / Menu / Ingredient / Allergen / Stats /
                 CategoryIngredientFamily Repository, OpenFoodFactsGateway,
                 ProductImportService
  Order/         OrderRepository (creation, encaissement, transitions), OrderQueryRepository
                 (files KDS/comptoir/drive, stats), OrderValidationException
  Health/        RouteMap, RouteSecurity, Probes, HealthReport, CapturedResponses
                 (page Sante /admin/health)
  Controllers/   Admin (base), Authenticated (base), Auth, PasswordReset, Profile, Me,
                 Dashboard, Stats, Category, Product, Menu, Ingredient, User, Role,
                 Health, HealthPage, Home, Catalogue, Order, Order (Admin), CounterOrder,
                 Kitchen, Privacy
    Admin/Api/   10 controleurs JSON : Auth, Category, Health, Ingredient, Menu, Order,
                 Product, Role, Stats, User (+ JsonApiTrait partage)
  Views/         admin/*  (pages back-office rendues serveur), auth/*  (login/reset)
src/public/
  admin/         front controller (`index.php`) + assets (CSS/JS) du back-office
  borne/         front kiosk statique (index, categories, products, payment,
                 confirmation ; panier en panneau persistant) + assets JS modules
```

Conventions transverses : les controleurs sont non-`final` (seam de test : sous-classe
injectant des doubles via `db()` / `sessionManager()`), sauf `HomeController` qui est
`final` (aucune sous-classe de test necessaire). La plupart heritent de
`AdminController` (back-office) ou `AuthenticatedController` ; six controleurs
(`AuthController`, `CatalogueController`, `HealthController`, `HomeController`,
`OrderController`, `PasswordResetController`) etendent `Controller` directement, ce
sont les surfaces publiques ou pre-authentification. Repository sur
`DatabaseInterface` ; chaque mutation passe par CSRF + validation serveur + allowlist
(voir section 7).

---

## 6. Flux d'une requete back-office

```
Navigateur --(HTTPS via Traefik | HTTP local)--> wakdo-web (Apache)
   |  vhost par ServerName (APP_HOST_KIOSK -> public/borne, APP_HOST_ADMIN -> public/admin)
   |  PHP -> FastCGI :9000
   v
wakdo-app (PHP-FPM) : src/public/admin/index.php
   |  Router (methode + chemin) -> [Controller, action]
   v
Controller (extends AdminController)
   |  guard(permission)  -> SessionGuard (RG-6/RG-T02 : session valide ?)
   |                        + Authorizer::can(role, permission) (RG-T03, recharge DB)
   |  (mutation) Csrf::validate + validation serveur (RG-T18) + allowlist (RG-T16)
   |  (action sensible) PinVerifier + throttle, audit_log dans la meme transaction
   v
Repository -> PDO (prepared) -> MariaDB
   |
   v
Vue rendue dans admin/layout (sorties echappees, RG-T15) | ou JSON pour /admin/api/*
```

La borne (kiosk) est servie en statique par Apache ; ses pages consomment les donnees
via `fetch` sur l'API publique DB-backed (`/api/*`, sans session ni CSRF). Ce flux ne
passe PAS par le schema ci-dessus (pas de `guard()`/CSRF/PIN) : le vhost kiosk relaie
`/api/*` en meme origine directement au front controller admin (`ProxyPassMatch`,
`docker/apache/vhost.conf`), donc sans requete cross-origine ni dependance a CORS ; le
middleware `App\Core\Cors` reste en place comme defense en profondeur pour un
consommateur cross-origine eventuel, mais n'est pas sur le chemin de la borne (voir
`docs/api/conventions.md` section 10).

---

## 7. Securite (security-by-design)

Couche transverse, regles `RG-T*` definies dans `docs/merise/mlt.md`. Synthese :

- **Authentification** : mot de passe hache **argon2id** (cout configurable, defauts
  OWASP) ; sessions PHP avec regeneration d'ID au login, idle 4h + absolu 10h ; cookie
  nomme `WAKDO_SID`. Une session n'est ouverte que pour les routes qui en ont besoin
  (`SessionRoutePolicy`, corrige le 2026-09-29, commit `ef7fd37`) : l'API kiosk publique
  sous `/api/*` (y compris `/api/health`) n'ouvre aucune session ni cookie — avant cette
  date, une session etait demarree pour toute requete sans condition.
- **RBAC** : `Authorizer::can(role_id, permission_code)` teste une **permission** (pas
  un nom de role), rechargee depuis la base a chaque verification. Le `role_id` fourni en
  entree l'est aussi : `SessionGuard::check()` le relit en base (avec `is_active` et
  `session_epoch`) dans la MEME requete SQL, a chaque requete authentifiee (RG-T02,
  corrige le 2026-09-29, commit `ef7fd37`) — avant cette date, seule `is_active` etait
  ainsi revalidee, `role_id` restant celui pose en session a la connexion jusqu'a une
  reconnexion. `session_epoch` (migration `0020_session_invalidation.sql`) ferme en plus
  toute session ouverte avant une reinitialisation de mot de passe, ou avant un changement
  de mot de passe fait par un administrateur (`680820f`). 5 roles seedes, 23
  permissions figees, matrice `role_permission` editable (back-office, voir domaine 10).
- **PIN d'action sensible (RG-T13)** : certaines operations exigent une
  re-autorisation par PIN equipier (argon2id) -- source unique : `App\Health\RouteSecurity`.
  Sont PIN-gated : annulation de commande, creation/modification/desactivation d'un
  utilisateur, reinitialisation de PIN, effacement PII, gestion RBAC (creation/modification
  de role), suppression de produit, changement de prix d'un produit, suppression de menu,
  import CSV de produits quand il change un prix (`POST /admin/api/products/import`, champ
  `price`), ajustement de stock et comptage d'inventaire. Des actions voisines ne le sont
  volontairement PAS : suppression
  d'un ingredient, reappro de stock (`stock.manage`) ; le back-office HTML n'offre d'ailleurs
  aucune suppression de categorie (seul un `DELETE` existe cote JSON,
  `/admin/api/categories/{id}`, lui non plus sans PIN). L'`acting_user_id` resolu par le PIN
  est ecrit dans `audit_log` (RG-T14) dans la **meme transaction** que l'effet (RG-T08). Les
  operations de stock tracent via `stock_movement.user_id` (pas de double-journal).
- **Throttling** (backoff degressif, pas de verrou definitif) :
  - login par compte (`user.failed_login_attempts` / `lockout_until`) + par IP
    (`login_throttle`, RG-8/9) ;
  - PIN d'action sensible (`pin_throttle`, RG-T22) — compteur **separe** du login, par
    utilisateur agissant ;
  - demande de reinitialisation de mot de passe (`password_reset_throttle`), par adresse
    ET par IP source, ajoutee le 2026-09-29 (commit `ef7fd37`) — avant cette date, aucune
    limite n'existait sur `POST /forgot_password`. L'adresse y est stockee en empreinte
    SHA-256 (migration `0021`, `680820f`) et la table est purgee par
    `docker/cron/scripts/purge-throttle.sh` comme les deux autres.
- **En-tetes HTTP** (`docker/apache/httpd.conf`/`vhost.conf`, durcis dans le code le
  2026-09-29, commit `08d7a96`, en production apres la release du 29/09) : `Permissions-Policy` (toutes les fonctionnalites capteur/media/paiement
  coupees), `TraceEnable Off` (methode `TRACE` refusee sur les deux hotes), CSP du
  back-office completee de `base-uri 'self'` et `form-action 'self'` (ne retombent pas sur
  `default-src` en CSP niveau 3), et la sonde publique `/api/health` sans version PHP
  (`App\Controllers\HealthController`, 6 cles ; `/admin/health` authentifiee la garde).
- **Entrees / sorties** : validation serveur bornee (RG-T18) ; allowlist d'affectation
  de masse (RG-T16, empeche d'injecter `role_id`/`price_cents`/`is_active`...) ; toutes
  les sorties HTML echappees (RG-T15) ; front borne CSP-safe (pas de script inline cote
  code projet).
- **Conventions HTTP** : conflit d'etat (unicite, FK RESTRICT) -> **409** ; validation
  qui echoue -> **422** ; CSRF/permission -> **403**.
- **RGPD** : anonymisation (mlt 10.5) qui conserve la ligne (tombstone) pour preserver
  les FK et la trace d'audit, en vidant la PII ; purges de retention par `wakdo-cron`
  (`audit_log`, compteurs de throttle) et expiration des commandes restees en attente
  de paiement. La purge des sessions et l'agregation de stats restent des templates
  commentes dans `docker/cron/crontab`, non actifs.
- **Isolation** : pas de port hote en mode prod (acces par le proxy) ; user applicatif
  MariaDB en moindre privilege (DDL reserve au runner migrate root ; cf.
  `db/init/10-scope-app-user.sh`).

Threat model STRIDE + classification des donnees : `docs/PROJECT_CONTEXT.md` section 19.

---

## 8. Modele de donnees

24 tables (DDL `db/migrations/`), regroupees par domaine :

- **Catalogue** : `category`, `product`, `menu`, `menu_slot`, `menu_slot_option`,
  `ingredient`, `product_ingredient`, `allergen`, `ingredient_allergen`, `stock_movement`,
  `category_ingredient_family` (parametrage du constructeur de recette, migration
  `0017_ingredient_family.sql`).
- **RBAC / comptes** : `user` (dont `session_epoch`, migration
  `0020_session_invalidation.sql`, 2026-09-29), `role`, `permission`, `role_permission`,
  `role_visible_source`.
- **Commande (livre)** : `customer_order`, `order_item`,
  `order_item_selection`, `order_item_modifier`.
- **Transverses** : `audit_log` (journal immuable), `login_throttle`, `pin_throttle`,
  `password_reset_throttle` (par adresse et par IP, migration `0020` ; adresse en empreinte
  SHA-256 depuis la migration `0021`).

Quelques derivations **calculees, non stockees** :

- **Stock en pourcentage** (mcd 5.3) : `stock_pct = round(stock_quantity / stock_capacity
  * 100)` ; 3 bandes (normal / alerte / critique) selon `low_stock_pct` /
  `critical_stock_pct`. `stock_quantity` est signe (survente assumee).
- **Disponibilite produit (RG-T21)** : un produit est commandable si `is_available = 1`
  ET chaque ingredient non retirable de sa composition est au-dessus de la bande
  critique. Pas de cascade ni de colonne stockee.
- **`service_day`** : concept metier de journee de service (coupure a 10:00), documente
  en `docs/PROJECT_CONTEXT.md` section 2 -- **non implemente** : aucune colonne ni vue SQL
  `service_day` n'existe dans le code livre (`grep service_day src/app` ne trouve qu'un
  commentaire expliquant que ce compteur n'a pas ete retenu pour la numerotation des
  commandes). Les stats livrees (`OrderQueryRepository::salesKpis()`,
  `salesByDay()`) utilisent le jour CALENDAIRE (`CURDATE()` / `DATE(created_at)`), pas la
  fenetre 10h-01h. Aucune requete de type "top produits" n'existe non plus.

MCD / MLD / dictionnaire : `docs/merise/`.

---

## 9. Tests & qualite

- **PHPUnit** (`.phar`, sans Composer) : tests *unit* (controleurs via double
  `FakeDatabase`, logique pure) + *integration* contre une vraie MariaDB (auto-skip si
  `WAKDO_DB_TESTS != 1`). Lancement minimal (unitaire seul, l'integration s'auto-skip
  sans reseau ni variable) :
  `docker run --rm -v "$PWD":/app -w /app wakdo-wakdo-app php phpunit.phar -c phpunit.xml`.
  Commande complete (unitaire + integration sur la vraie base) :
  `docker run --rm --network wakdo_wakdo_internal --env-file .env -e WAKDO_DB_TESTS=1
  -v "$PWD":/app -w /app wakdo-wakdo-app php phpunit.phar -c phpunit.xml` (voir
  `docs/TESTING.md` section 2 ; attention aux commentaires en fin de ligne dans `.env`
  avec `--env-file`, voir cette meme section).
- **Front borne** : `node --test` + jsdom (`tests/js/`).
- **PHPStan niveau 6** (`.phar`).
- **CI Forgejo Actions** (`.forgejo/workflows/ci.yml`, cinq travaux) : `secret-scan`
  (gitleaks), `php-lint`, `static-tests` (PHPStan + PHPUnit avec service MariaDB
  ephemere migre + seede), `js-tests` (Node 20), `shell-tests` (fonctions pures du
  filet instantane/remise a zero de la demo, `tests/shell/`). Fusion par auto-merge
  NATIF Forgejo (squash, `merge_when_checks_succeed`) des que les checks requis sont
  verts — pas de job de merge. Le deploiement continu (`.forgejo/workflows/deploy.yml`,
  deux travaux `cle-de-deploiement` + `deploiement`) se declenche sur push `main`.
- **Branch protection** : `dev` et `main` proteges (PR requise, force-push bloque,
  checks requis).

Pyramide visee : Unit > Integration > E2E. Les tests E2E navigateur (Playwright, `tests/e2e/`)
existent et se lancent a la main contre une pile jetable (`tests/e2e/run.sh`, `tests/e2e/run-a11y.sh`
pour l'audit d'accessibilite) ; ils ne tournent pas en CI, le runner n'offrant pas le socket Docker aux jobs.

---

## 10. Methodologie & tracabilite

Projet developpe avec l'appui de **BYAN** (agents IA custom, Merise Agile + 64 mantras)
et d'outils d'IA generative, conformement a l'autorisation du centre de formation.

- Decisions d'architecture, scope et design : prises par l'auteur.
- Code, tests, doc : co-rediges et valides par l'auteur avant commit.
- **Pas de trailer `Co-Authored-By`** sur les commits : la transparence vit dans le
  README et `docs/PROJECT_CONTEXT.md` section 17, pas dans les metadonnees git.
- Tracabilite : `docs/journal/` (retros par session et par feature).

---

*Document vivant — mis a jour au fil de l'implementation. Source de verite scope/RNCP :
`docs/PROJECT_CONTEXT.md`.*
