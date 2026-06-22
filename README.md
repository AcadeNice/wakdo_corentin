# Wakdo

Borne de commande pour restauration rapide. Projet de certification RNCP 37805 (Titre Developpeur Web, B2, option DevOps).

**Statut** : en developpement actif. Soutenance prevue septembre 2026.

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

Statuts commande (machine a 4 etats) : `pending_payment` -> `paid` -> `delivered`, plus `cancelled` (atteignable depuis `pending_payment` ou `paid`). La saisie du numero tient lieu de paiement : la creation passe atomiquement de `pending_payment` a `paid`. La cuisine voit la file des commandes `paid` en lecture seule ; la remise est un geste unique `paid` -> `delivered`.

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

                  wakdo-cron       (backup BDD + purge audit-log + purge throttle)
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

*Deploiement detaille : section Deploiement plus bas et `scripts/deploy.sh`.*

---

## Structure du projet

```
.
|-- .claude/                     # Methodologie BYAN (visible jury : CLAUDE.md + rules/)
|-- .forgejo/workflows/          # CI Forgejo Actions (ci.yml : secret-scan, php-lint, static-tests, js-tests)
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
|-- scripts/                     # deploy, install-hooks, forgejo-* (branch-protection, pr-automerge)
|-- src/
|   |-- app/                     # namespace App\ : Core, Controllers, Auth, Catalogue, Order, Views
|   `-- public/                  # DocumentRoots Apache : borne/ (kiosk) + admin/ (back-office + API)
|-- tests/
|   |-- Unit/ Integration/       # PHPUnit (.phar autonome, sans Composer ; integration sur vraie MariaDB)
|   |-- js/                      # node:test + jsdom (front borne)
|   |-- e2e/                     # Playwright (parcours borne + admin, lance a la main)
|   `-- Support/                 # doubles de test (Fake* / Spy*)
|-- .env.example  .gitleaks.toml  phpstan.neon  phpunit.xml
|-- docker-compose.yml           # standalone local ; prod = docker-compose.prod.yml (gitignore, par hote)
`-- README.md
```

---

## Developpement

### Conventions

- **Commits** : Conventional Commits en anglais (`feat`, `fix`, `docs`, `refactor`, `test`, `chore`, `ci`, `db`, `perf`, `style`). Format : `type(scope): description`. Voir `docs/PROJECT_CONTEXT.md` section 9.
- **Branches** : `feat/*`, `fix/*`, `refactor/*`, `docs/*`, `ci/*`, `db/*`, `chore/*`, `test/*` depuis `dev`. Merge vers `dev` par PR squashee. Periodiquement `dev` -> `main` par PR avec tag semver.
- `main` et `dev` sont proteges cote Forgejo (PR requise, force push bloque, checks requis : secret-scan / php-lint / static-tests).
- Pas d'emoji dans le code, les commits ou les specs techniques (Mantra IA-23).

- **Hooks Git** : `scripts/install-hooks.sh` active `pre-commit` (refus de commit direct sur `main`/`dev`, `php -l` des fichiers indexes) et `commit-msg` (format Conventional Commits, refus emoji).
- **Verification locale** : voir la section Tests ci-dessous (PHPUnit + PHPStan via le conteneur applicatif, tests JS via node, E2E Playwright).

---

## Tests

Trois niveaux, sans dependance Composer cote PHP (priorite Unit > Integration > E2E).

- **PHP (PHPUnit `.phar`)** — unit + integration sur vraie MariaDB, via le conteneur applicatif :

  ```bash
  docker run --rm -v "$PWD":/app -w /app wakdo-wakdo-app php phpunit.phar -c phpunit.xml
  docker run --rm -v "$PWD":/app -w /app wakdo-wakdo-app php -d memory_limit=-1 phpstan.phar analyse
  ```

- **JS (node:test + jsdom)** — modules du front borne : `npm run test:js`
- **E2E (Playwright)** — parcours borne + admin, lances a la main contre une stack jetable : `tests/e2e/run.sh`

La CI Forgejo execute secret-scan, php-lint, static-tests (PHPStan niveau 6 + PHPUnit avec service MariaDB) et js-tests sur chaque PR.

---

## Deploiement

*CI Forgejo Actions sur PR vers `dev`/`main` (secret-scan gitleaks, php-lint, static-tests PHPStan + PHPUnit, js-tests), avec auto-merge sur CI verte. Deploiement a declenchement humain via `scripts/deploy.sh` (recupere `main` depuis Forgejo puis `docker compose build --pull && up -d` ; les images sont buildees localement depuis les Dockerfiles, le one-shot `wakdo-migrate` applique migrations + seed). L'automatisation visee est pull-based (un job cron cote hote detectant un nouveau `main`), a armer ensuite. Voir `docs/PROJECT_CONTEXT.md` section 7 Bloc 5.*

---

## Documentation

| Document | Role |
|---|---|
| `docs/PROJECT_CONTEXT.md` | Source de verite projet (17 sections : scope, stack, architecture, mapping critere RNCP, planning, risques, conventions) |
| `docs/journal/` | Retrospectives par session et par feature (preparation de l'oral RNCP) |
| `docs/merise/` | Modelisation Merise : dictionnaire, MCD, MCT, MLD, MLT (+ diagrammes) |
| `docs/ARCHITECTURE.md` / `docs/adr/` | Vue technique + decisions d'architecture (ADR) |
| `.claude/CLAUDE.md` | Constitution du projet pour les agents Claude Code |
| `.claude/rules/` | Protocoles appliques : fact-check, merise-agile, elo-trust, hermes-dispatcher, byan-api, byan-agents |

---

## Licence

Projet pedagogique dans le cadre de la certification RNCP 37805. Usage et reproduction reserves a l'evaluation et a la demonstration, sans cession de droits commerciaux.
