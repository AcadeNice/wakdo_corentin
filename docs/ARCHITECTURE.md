# Architecture — Wakdo

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
  consommant des donnees (JSON statique en P5, API DB-backed au swap P4).
- **Back-office + API** — application PHP rendue serveur (MVC maison) + endpoints
  `/api/*`, derriere authentification et RBAC.

Trois canaux de commande (`source`) : `kiosk`, `counter`, `drive`. Le cycle de vie
d'une commande et la machine a etats sont decrits dans `docs/merise/` (domaine
commande = phase **P4**, schema en base mais workflow applicatif a venir).

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
| CI/CD | Forgejo Actions | secret-scan, lint, tests, auto-merge |
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

        wakdo-cron (dcron) : backup BDD + purges retention (RGPD)
```

- **Reseau** : `wakdo_internal` (bridge) isole les services ; aucun port hote en mode
  prod (acces par le proxy). En mode local, seul `wakdo-web` publie un port.
- **Volumes** : `wakdo_db_data` (persistance MariaDB), `wakdo_uploads` (images produits) ;
  bind-mount `./var/backups` pour les dumps.
- **`wakdo-cron`** utilise `init: true` (tini comme PID 1 : dcron a besoin d'un init
  parent pour `setpgid` sur ses jobs).
- Choix d'un **subnet RFC 1918 explicite** sur `wakdo_internal` cote prod : l'hote
  mutualise a un allocateur Docker sature ; le subnet evite l'echec d'allocation auto.

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
  Core/          Autoloader, Config, Database (PDO), Request, Response, Router
  Auth/          AuthService, SessionManager, SessionGuard, Authorizer, PinVerifier,
                 PinThrottle, ThrottlePolicy, PasswordHasher, Csrf, PasswordResetService,
                 UserRepository, RoleRepository, UserDirectory, Mailer/LogMailer
  Catalogue/     Category / Product / Menu / Ingredient / Stats Repository
  Controllers/   Admin (base), Authenticated (base), Auth, PasswordReset, Profile, Me,
                 Dashboard, Stats, Category, Product, Menu, Ingredient, User, Role,
                 Health, Home
  Views/         admin/*  (pages back-office rendues serveur), auth/*  (login/reset)
src/public/
  admin/         front controller + assets (CSS/JS) du back-office
  borne/         front kiosk statique (index, categories, products, product, cart,
                 payment, confirmation) + assets JS modules + data JSON
```

Conventions transverses : controleurs non-`final` (seam de test : sous-classe injectant
des doubles via `db()` / `sessionManager()`) ; repository sur `DatabaseInterface` ;
chaque mutation passe par CSRF + validation serveur + allowlist (voir section 7).

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
Vue rendue dans admin/layout (sorties echappees, RG-T15) | ou JSON pour /api/*
```

La borne (kiosk) est servie en statique par Apache ; ses pages consomment les donnees
via `fetch` (JSON statique en P5 ; bascule sur `/api/*` DB-backed au swap P4).

---

## 7. Securite (security-by-design)

Couche transverse, regles `RG-T*` definies dans `docs/merise/mlt.md`. Synthese :

- **Authentification** : mot de passe hache **argon2id** (cout configurable, defauts
  OWASP) ; sessions PHP avec regeneration d'ID au login, idle 4h + absolu 10h ; cookie
  nomme `WAKDO_SID`.
- **RBAC** : `Authorizer::can(role_id, permission_code)` teste une **permission** (pas
  un nom de role), rechargee depuis la base a chaque verification. 5 roles seedes, 23
  permissions figees, matrice `role_permission` editable (back-office, voir domaine 10).
- **PIN d'action sensible (RG-T13)** : les operations sensibles (annulation, prix/TVA,
  suppressions, inventaire, gestion utilisateur, RBAC, effacement PII) exigent une
  re-autorisation par PIN equipier (argon2id). L'`acting_user_id` resolu par le PIN est
  ecrit dans `audit_log` (RG-T14) dans la **meme transaction** que l'effet (RG-T08). Les
  operations de stock tracent via `stock_movement.user_id` (pas de double-journal).
- **Throttling** (backoff degressif, pas de verrou definitif) :
  - login par compte (`user.failed_login_attempts` / `lockout_until`) + par IP
    (`login_throttle`, RG-8/9) ;
  - PIN d'action sensible (`pin_throttle`, RG-T22) — compteur **separe** du login, par
    utilisateur agissant.
- **Entrees / sorties** : validation serveur bornee (RG-T18) ; allowlist d'affectation
  de masse (RG-T16, empeche d'injecter `role_id`/`price_cents`/`is_active`...) ; toutes
  les sorties HTML echappees (RG-T15) ; front borne CSP-safe (pas de script inline cote
  code projet).
- **Conventions HTTP** : conflit d'etat (unicite, FK RESTRICT) -> **409** ; validation
  qui echoue -> **422** ; CSRF/permission -> **403**.
- **RGPD** : anonymisation (mlt 10.5) qui conserve la ligne (tombstone) pour preserver
  les FK et la trace d'audit, en vidant la PII ; purges de retention par `wakdo-cron`
  (audit_log, throttle, sessions, commandes).
- **Isolation** : pas de port hote en mode prod (acces par le proxy) ; user applicatif
  MariaDB en moindre privilege (DDL reserve au runner migrate root ; cf.
  `db/init/10-scope-app-user.sh`).

Threat model STRIDE + classification des donnees : `docs/PROJECT_CONTEXT.md` section 19.

---

## 8. Modele de donnees

22 tables (DDL `db/migrations/`), regroupees par domaine :

- **Catalogue** : `category`, `product`, `menu`, `menu_slot`, `menu_slot_option`,
  `ingredient`, `product_ingredient`, `allergen`, `ingredient_allergen`, `stock_movement`.
- **RBAC / comptes** : `user`, `role`, `permission`, `role_permission`,
  `role_visible_source`.
- **Commande (P4, schema pret)** : `customer_order`, `order_item`,
  `order_item_selection`, `order_item_modifier`.
- **Transverses** : `audit_log` (journal immuable), `login_throttle`, `pin_throttle`.

Quelques derivations **calculees, non stockees** :

- **Stock en pourcentage** (mcd 5.3) : `stock_pct = round(stock_quantity / stock_capacity
  * 100)` ; 3 bandes (normal / alerte / critique) selon `low_stock_pct` /
  `critical_stock_pct`. `stock_quantity` est signe (survente assumee).
- **Disponibilite produit (RG-T21)** : un produit est commandable si `is_available = 1`
  ET chaque ingredient non retirable de sa composition est au-dessus de la bande
  critique. Pas de cascade ni de colonne stockee.
- **`service_day`** : journee de service (coupure a 10:00) pour les agregations stats,
  expression SQL non materialisee.

MCD / MLD / dictionnaire : `docs/merise/`.

---

## 9. Tests & qualite

- **PHPUnit** (`.phar`, sans Composer) : tests *unit* (controleurs via double
  `FakeDatabase`, logique pure) + *integration* contre une vraie MariaDB (auto-skip si
  `WAKDO_DB_TESTS != 1`). Lancement :
  `docker run --rm -v "$PWD":/app -w /app wakdo-wakdo-app php phpunit.phar -c phpunit.xml`.
- **Front borne** : `node --test` + jsdom (`tests/js/`).
- **PHPStan niveau 6** (`.phar`).
- **CI Forgejo Actions** (`.forgejo/workflows/ci.yml`) : `secret-scan` (gitleaks),
  `php-lint`, `static-tests` (PHPStan + PHPUnit avec service MariaDB ephemere migre +
  seede), `js-tests` (Node 20), `auto-merge` (squash sur label + CI verte).
- **Branch protection** : `dev` et `main` proteges (PR requise, force-push bloque,
  checks requis).

Pyramide visee : Unit > Integration > E2E. Les tests E2E navigateur (Playwright) sont
une initiative a venir.

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
