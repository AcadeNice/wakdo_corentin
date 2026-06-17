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

Quatre statuts commande : `pending` -> `preparing` -> `ready` -> `delivered` (ou `cancelled`).

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

                  wakdo-cron       (backup BDD + purge sessions + stats)
```

Reseaux, volumes, services et decoupage reseau interne / reseau proxy : voir `docs/PROJECT_CONTEXT.md` section 5.

---

## Quickstart (local)

```bash
git clone git@github.com:AcadeNice/wakdo_corentin.git
cd wakdo_corentin
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

*Section mise a jour au fil de l'implementation (migrations reelles, seed, CI/CD deploiement).*

---

## Structure du projet

```
.
|-- .claude/                     # Methodologie BYAN (visible jury : CLAUDE.md + rules/)
|-- .forgejo/
|   `-- workflows/               # CI Forgejo Actions (ci.yml : secret-scan, php-lint, static-tests, js-tests, auto-merge)
|-- .githooks/                   # pre-commit + commit-msg [a venir]
|-- docker/                      # Dockerfiles customs par service
|   |-- apache/
|   |-- php-fpm/
|   `-- cron/
|-- db/
|   |-- migrations/              # DDL MariaDB versionnes [a venir]
|   `-- seeds/                   # Donnees de demo [a venir]
|-- docs/
|   |-- PROJECT_CONTEXT.md       # Source de verite projet (scope, stack, RNCP mapping)
|   |-- journal/                 # Retros par session et par feature (oral RNCP)
|   `-- merise/                  # MCD, MCT, MLD [a venir]
|-- scripts/                     # backup-db, install-hooks, ... [a venir]
|-- src/                         # Code applicatif [a venir]
|   |-- Core/                    # Router, Autoloader, DB
|   |-- Controllers/
|   |-- Models/
|   |-- Views/
|   |-- Services/
|   |-- public/                  # DocumentRoot Apache
|   `-- bootstrap.php
|-- tests/
|   |-- Unit/                    # [a venir]
|   `-- Integration/             # [a venir]
|-- .env.example
|-- .dockerignore
|-- .gitignore
|-- docker-compose.yml
`-- README.md
```

---

## Developpement

### Conventions

- **Commits** : Conventional Commits en anglais (`feat`, `fix`, `docs`, `refactor`, `test`, `chore`, `ci`, `db`, `perf`, `style`). Format : `type(scope): description`. Voir `docs/PROJECT_CONTEXT.md` section 9.
- **Branches** : `feat/*`, `fix/*`, `refactor/*`, `docs/*`, `ci/*`, `db/*`, `chore/*`, `test/*` depuis `dev`. Merge vers `dev` par PR squashee. Periodiquement `dev` -> `main` par PR avec tag semver.
- `main` et `dev` sont proteges cote Forgejo (PR requise, force push bloque, checks requis : secret-scan / php-lint / static-tests).
- Pas d'emoji dans le code, les commits ou les specs techniques (Mantra IA-23).

*Sections detaillees (setup env de dev, lint, tests) : a completer au fil de l'implementation.*

---

## Tests

*Section a completer. Strategie globale : PHPUnit via `.phar` autonome (sans Composer), priorite Unit > Integration > E2E, voir `docs/PROJECT_CONTEXT.md` section 6 et mantras Merise Agile.*

---

## Deploiement

*Strategie : CI Forgejo Actions sur PR vers `dev`/`main` (secret-scan gitleaks, php-lint, static-tests PHPStan+PHPUnit, js-tests) avec auto-merge sur label + CI verte. CD : declenchement humain, redeploiement par `docker compose pull && docker compose up -d`. Voir `docs/PROJECT_CONTEXT.md` section 7 Bloc 5.*

---

## Documentation

| Document | Role |
|---|---|
| `docs/PROJECT_CONTEXT.md` | Source de verite projet (17 sections : scope, stack, architecture, mapping critere RNCP, planning, risques, conventions) |
| `docs/journal/` | Retrospectives par session et par feature (preparation de l'oral RNCP) |
| `docs/merise/` *(a venir)* | Modelisation Merise : dictionnaire, MCD, MCT, MLD |
| `.claude/CLAUDE.md` | Constitution du projet pour les agents Claude Code |
| `.claude/rules/` | Protocoles appliques : fact-check, merise-agile, elo-trust, hermes-dispatcher, byan-api, byan-agents |

---

## Licence

Projet pedagogique dans le cadre de la certification RNCP 37805. Usage et reproduction reserves a l'evaluation et a la demonstration, sans cession de droits commerciaux.
