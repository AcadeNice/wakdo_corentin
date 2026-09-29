# Wakdo

Borne de commande pour restauration rapide. Projet de certification RNCP 37805 (Titre Developpeur Web, B2, option DevOps).

**Statut** : en developpement actif. Soutenance prevue lundi 5 octobre 2026.

**Sites exposes cibles** :
- `https://corentin-wakdo.stark.a3n.fr` — Borne client (Bloc 1 Front)
- `https://corentin-wakdo-admin.stark.a3n.fr` — Back-office + API REST (Bloc 2)

---

## Apercu

Wakdo simule une borne de commande tactile de type fast-food (pastiche McDonald's), avec back-office administrateur, workflow de preparation en cuisine et API REST interne consommee par la borne.

Trois canaux de prise de commande :

- `kiosk` — borne tactile autonome (le client compose seul)
- `counter` — comptoir (un equipier saisit pour le client au guichet)
- `drive` — drive-thru (equipier saisit via intercom + casque)

Statuts commande (machine a 6 etats) : `pending_payment` -> `paid` -> `preparing` -> `ready` -> `delivered`, plus `cancelled` (terminal, atteignable depuis tout etat non termine). Le paiement carte/especes n'est pas reel : il est simule par un bouton dans l'interface (`payment.html`). Sur la borne, deux appels HTTP distincts l'encaissent : `POST /api/orders` cree la commande (`pending_payment`), puis `POST /api/orders/{number}/pay` l'encaisse et la fait passer directement a `preparing` (`paid_at` et `preparing_at` poses dans la meme transaction ; `paid` reste un etat valide de l'enum, conserve pour compatibilite, mais aucun chemin de code actuel ne l'ecrit). Au comptoir/drive, l'equipier n'a rien a rejouer : `createStaffOrder()` enchaine les deux memes etapes en interne (`persist()` puis `pay()`, deux transactions distinctes). En sur-place, un numero de chevalet est demande avant paiement (transmis en `service_tag`) pour indiquer ou livrer en salle — il ne remplace pas le paiement. La cuisine (KDS) fait avancer `preparing` -> `ready` ; la remise accepte `paid`, `preparing` ou `ready` -> `delivered`.

Scope metier complet, regles, horaires de service et fenetre de maintenance : voir `docs/PROJECT_CONTEXT.md`.

---

## Methodologie et outils

Ce projet a ete developpe avec l'appui de **BYAN (Builder of YAN)**, un systeme d'agents IA custom applicant la methodologie **Merise Agile enrichie de 64 Mantras** (voir `.claude/CLAUDE.md` et `.claude/rules/`).

Realisation avec l'assistance d'outils d'IA generative (Claude Code, BYAN), conformement a l'autorisation du centre de formation Acadenice.

- Les decisions d'architecture, de scope et de design sont prises par l'auteur.
- Le code, les tests et la documentation sont co-rediges et valides par l'auteur avant commit.
- La modelisation Merise est formalisee par l'IA a partir du dictionnaire de donnees et des user stories ; l'arbitrage et la validation sont de l'auteur.
- Tracabilite du projet : `docs/journal/` (retrospectives de session et de feature) et `docs/PROJECT_CONTEXT.md` section 17 (scope complet de l'usage IA).
- Pas de trailer `Co-Authored-By` appose sur les commits — voir section 17.7 du `PROJECT_CONTEXT.md` pour la justification.

---

## Stack technique

| Couche | Techno | Version |
|---|---|---|
| Langage back | PHP | 8.3 |
| Framework back | Aucun (from scratch) | — |
| Autoloader | PSR-4 manuel via `spl_autoload_register` | — |
| Base de donnees | MariaDB | 11.4 |
| Pilote BDD | PDO (prepared statements uniquement) | natif PHP |
| Serveur web | Apache httpd | 2.4 Alpine |
| Serveur app | PHP-FPM | 8.3 Alpine |
| Reverse proxy | Traefik | existant sur l'hote (reseau `admin_proxy`) |
| Tests | PHPUnit | 11.x (`.phar` autonome, sans Composer) |
| Front | HTML5 + CSS3 + JS ES6+ vanilla | — |
| Conteneurisation | Docker + docker compose v2 | — |
| Orchestration locale | docker compose v2 (service one-shot `wakdo-migrate`) | — |
| CI/CD | Forgejo Actions | — |
| Versioning | Git + Forgejo (`git.acadenice.com`, miroir GitHub) | Conventional Commits |

Detail et justifications : `docs/PROJECT_CONTEXT.md` section 6.

---

## Architecture

```
      corentin-wakdo.stark.a3n.fr         corentin-wakdo-admin.stark.a3n.fr
                 |                                      |
                 +--------------+-----------------------+
                                |
                                v
                   Traefik  (reseau admin_proxy)
                                |
                                v
                        wakdo-web  (Apache httpd)
                                | FastCGI :9000
                                v
                        wakdo-app  (PHP-FPM 8.3)
                                | PDO
                                v
                        wakdo-db   (MariaDB 11.4)

                  wakdo-cron       (backup BDD + purge audit-log + purge throttle + expiration commandes en attente)
```

Reseaux, volumes, services et decoupage reseau interne / reseau proxy : voir `docs/PROJECT_CONTEXT.md` section 5.

---

## Quickstart (local)

```bash
git clone https://git.acadenice.com/AcadeNice/corentin_wakdo.git
cd corentin_wakdo
cp .env.example .env
docker compose up -d
```

Une seule commande lance la stack complete (Cr 7.c.4) : le service one-shot
`wakdo-migrate` applique les migrations puis le seed (idempotents, tables de suivi
`schema_migrations` / `seeds_applied`) avant que l'app ne serve. Ensuite :

- Borne : http://kiosk.localhost:8080
- Admin + API : http://admin.localhost:8080

`*.localhost` resout vers `127.0.0.1` nativement ; changer le port via `HTTP_PORT`
dans `.env`. Le `.env.example` fonctionne tel quel en local (valeurs dev).

Docker non installe ? Voir https://docs.docker.com/engine/install/

### Deploiement prod (derriere un reverse proxy Traefik)

Le repo ne ship que `docker-compose.yml` (standalone). En production derriere un
reverse proxy, chaque hote maintient son **propre `docker-compose.prod.yml`**
(gitignore, hors repo, comme `.env`) : meme stack, mais exposee via Traefik (reseau
externe + labels TLS) au lieu d'un port hote.

```bash
docker compose -f docker-compose.prod.yml up -d
```

Avec un `.env` adapte : `APP_ENV=prod`, `APP_DEBUG=false`, mots de passe forts,
`APP_HOST_*` / `APP_URL_*` / `CORS_ALLOWED_ORIGIN` en vrais FQDN HTTPS, et
`REVERSE_PROXY_NETWORK` = reseau Docker du Traefik de l'hote (doit exister avant le up).
Etat constate sur l'instance de demonstration le 29/09 : `GET /api/health` y renvoie
`"app_env": "production"` (valeur lue dans le `.env` de l'hote ; `Config::appEnv()` retombe
aussi sur `production` si la cle manque). Les reponses de `src/app/Health/captured-responses.json`
viennent d'une pile de test jetable (`.env.example`, donc `dev`) : elles ne decrivent pas la
production sur ce point.

*Deploiement detaille : section Deploiement plus bas et `scripts/deploy.sh`.*

---

## Structure du projet

```
.
|-- .claude/                     # Methodologie BYAN (visible jury : CLAUDE.md + rules/)
|-- .forgejo/workflows/          # Forgejo Actions (ci.yml : secret-scan, php-lint, static-tests, js-tests, shell-tests ; deploy.yml : cle-de-deploiement, deploiement)
|-- .githooks/                   # pre-commit (refus main/dev + php -l) + commit-msg (Conventional Commits)
|-- docker/                      # Dockerfiles customs par service
|   |-- apache/                  # httpd + vhosts kiosk / admin
|   |-- php-fpm/                 # PHP 8.3-fpm + php.ini durci
|   `-- cron/                    # dcron + scripts (backup, restore, purges)
|-- db/
|   |-- init/                    # init BDD (scope du user applicatif, moindre privilege)
|   |-- migrations/              # DDL MariaDB versionnes (0001_init_schema, 0002_pin_throttle, ...)
|   |-- seeds/                   # donnees de reference + demo (idempotents)
|   `-- *.sh                     # runners migrate / seed
|-- docs/
|   |-- PROJECT_CONTEXT.md       # source de verite projet (scope, stack, mapping RNCP)
|   |-- ARCHITECTURE.md          # vue technique (deploiement, stack, securite)
|   |-- merise/                  # dictionnaire, MCD, MCT, MLD, MLT (+ diagrammes)
|   |-- uml/                     # use-cases, sequences, machine a etats
|   `-- adr/ api/ domaines/ design/ journal/ _ref/
|-- scripts/                     # deploy, install-hooks, forgejo-*, demo-snapshot/demo-reset (voir docs/ops/demo-reset.md)
|-- src/
|   |-- app/                     # namespace App\ : Core, Controllers, Auth, Catalogue, Order, Health, Views
|   `-- public/                  # DocumentRoots Apache : borne/ (kiosk) + admin/ (back-office + API)
|-- tests/
|   |-- Unit/ Integration/       # PHPUnit (.phar autonome, sans Composer ; integration sur vraie MariaDB)
|   |-- js/                      # node:test + jsdom (front borne ET back-office)
|   |-- e2e/                     # Playwright (parcours borne + admin, lance a la main ; e2e/backoffice-sweep/ = balayage exhaustif)
|   |-- shell/                   # tests bash purs (scripts/lib/demo-snapshot-lib.sh, purge des tables de limitation), lances en CI (shell-tests)
|   `-- Support/                 # doubles de test (Fake* / Spy*)
|-- .env.example  .gitleaks.toml  phpstan.neon  phpunit.xml
|-- docker-compose.yml           # standalone local ; prod = docker-compose.prod.yml (gitignore, par hote)
`-- README.md
```

---

## Developpement

### Conventions

- **Commits** : Conventional Commits en francais (`feat`, `fix`, `docs`, `refactor`, `test`, `chore`, `ci`, `db`, `perf`, `style`). Format : `type(scope): description`. Correction du 2026-09-24 : premiers commits en anglais, en francais depuis mi-juin 2026 ; le francais fait regle. Voir `docs/PROJECT_CONTEXT.md` section 9.
- **Branches** : `feat/*`, `fix/*`, `refactor/*`, `docs/*`, `ci/*`, `db/*`, `chore/*`, `test/*` depuis `dev`. Merge vers `dev` par PR squashee. Periodiquement `dev` -> `main` par PR avec tag semver. Etat au 29/09 : seules `v0.1.0` et `v0.2.0` sont posees ; les releases suivantes sont identifiees par le titre de leur PR.
- `main` et `dev` sont proteges cote Forgejo (PR requise, force push bloque, 4 travaux de la CI requis sur les 5 du workflow).
- Pas d'emoji dans le code, les commits ou les specs techniques (Mantra IA-23).

- **Hooks Git** : `scripts/install-hooks.sh` active `pre-commit` (refus de commit direct sur `main`/`dev`, `php -l` des fichiers indexes) et `commit-msg` (format Conventional Commits, refus emoji).
- **Verification locale** : voir la section Tests ci-dessous (PHPUnit + PHPStan via le conteneur applicatif, tests JS via node, E2E Playwright).

---

## Tests

Trois niveaux, sans dependance Composer cote PHP (priorite Unit > Integration > E2E).

- **PHP (PHPUnit `.phar`)** — unit + integration sur vraie MariaDB, via le conteneur applicatif :

  Les deux `.phar` ne sont pas dans le depot. Les prendre aux memes versions que la CI
  (`.forgejo/workflows/ci.yml`, variables `PHPUNIT_VERSION` 11.5.2 et `PHPSTAN_VERSION` 1.12.27) :

  ```bash
  curl -sSL https://phar.phpunit.de/phpunit-11.5.2.phar -o phpunit.phar
  curl -sSL https://github.com/phpstan/phpstan/releases/download/1.12.27/phpstan.phar -o phpstan.phar
  ```


  ```bash
  # Unitaire seul (les tests d'integration s'auto-skippent sans reseau/WAKDO_DB_TESTS=1) :
  docker run --rm -v "$PWD":/app -w /app wakdo-wakdo-app php phpunit.phar -c phpunit.xml

  # Unitaire + integration sur la vraie base (commande complete, voir docs/ARCHITECTURE.md section 9) :
  docker run --rm --network wakdo_wakdo_internal --env-file .env -e WAKDO_DB_TESTS=1 \
      -v "$PWD":/app -w /app wakdo-wakdo-app php phpunit.phar -c phpunit.xml

  docker run --rm -v "$PWD":/app -w /app wakdo-wakdo-app php -d memory_limit=-1 phpstan.phar analyse
  ```

- **JS (node:test + jsdom)** — modules du front borne et du back-office : `npm run test:js`
- **E2E (Playwright)** — parcours borne + admin, lances a la main contre une stack jetable : `tests/e2e/run.sh`

La CI Forgejo (`ci.yml`, cinq travaux) execute secret-scan, php-lint, static-tests (PHPStan niveau 6 + PHPUnit avec service MariaDB), js-tests et shell-tests sur chaque PR.

---

## Deploiement

*CI Forgejo Actions (`ci.yml`, cinq travaux : secret-scan gitleaks, php-lint, static-tests PHPStan + PHPUnit, js-tests, shell-tests) sur PR vers `dev`/`main`, avec auto-merge sur CI verte. Deploiement via `scripts/deploy.sh` (recupere `main` depuis Forgejo puis `docker compose build --pull && up -d` ; les images sont buildees localement depuis les Dockerfiles, le one-shot `wakdo-migrate` applique migrations + seed). Le deploiement est CONTINU : tout commit arrivant sur `main` declenche le workflow `deploy.yml` (deux travaux : `cle-de-deploiement` verifie le secret SSH, `deploiement` demande a l'hote de se deployer par un canal restreint, commande forcee, une seule commande possible), puis verifie que `/api/health` sert bien le commit attendu avant de passer au vert. `scripts/deploy.sh` reste lancable a la main et refuse de partir si l'arbre de travail n'est pas propre. Voir `docs/PROJECT_CONTEXT.md` section 7 Bloc 5.*

---

## Documentation

| Document | Role |
|---|---|
| `docs/PROJECT_CONTEXT.md` | Source de verite projet (19 sections : scope, stack, architecture, mapping critere RNCP, planning, risques, conventions, threat model) |
| `docs/journal/` | Retrospectives par session et par feature (preparation de l'oral RNCP) |
| `docs/merise/` | Modelisation Merise : dictionnaire, MCD, MCT, MLD, MLT (+ diagrammes) |
| `docs/ARCHITECTURE.md` / `docs/adr/` | Vue technique + decisions d'architecture (ADR) |
| `docs/ops/demo-reset.md` | Remise a zero des donnees de demo avant/pendant la soutenance (`scripts/demo-snapshot.sh`, `scripts/demo-reset.sh`) |
| `.claude/CLAUDE.md` | Constitution du projet pour les agents Claude Code |
| `.claude/rules/` | Protocoles appliques (13 fichiers) : fact-check, merise-agile, elo-trust, hermes-dispatcher, byan-api, byan-agents, strict-mode, benchmark, team-doctrine, portable-core, native-workflows, plain-language, agent-entry-gate |

---

## Licence

Projet pedagogique dans le cadre de la certification RNCP 37805. Usage et reproduction reserves a l'evaluation et a la demonstration, sans cession de droits commerciaux.
