# Wakdo

Borne de commande pour restauration rapide. Projet de certification RNCP 37805 (Titre Developpeur Web, B2, option DevOps).

**Statut** : en developpement actif. Soutenance prevue septembre 2026.

**Sites exposes cibles** :
- `https://corentin-wakdo.acadenice.fr` — Borne client (Bloc 1 Front)
- `https://corentin-wakdo-admin.acadenice.fr` — Back-office + API REST (Bloc 2)

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
| Orchestration locale | Makefile | — |
| CI/CD | GitHub Actions | — |
| Versioning | Git + GitHub | Conventional Commits |

Detail et justifications : `docs/PROJECT_CONTEXT.md` section 6.

---

## Architecture

```
      corentin-wakdo.acadenice.fr         corentin-wakdo-admin.acadenice.fr
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

## Quickstart

Ce projet tourne **sur serveur derriere un reverse proxy Traefik** : pas de binding de ports hote, pas d'acces `localhost`. L'acces public se fait par FQDN HTTPS (TLS gere automatiquement par Traefik). Les environnements `dev`, `staging` et `prod` se distinguent par des FQDN et des fichiers `.env` separes.

### Prerequis sur l'hote

1. Docker Engine + docker compose v2 (voir ci-dessous)
2. Un reverse proxy Traefik deja en place, avec un reseau Docker externe dedie. Le **nom du reseau** est configurable via la variable `REVERSE_PROXY_NETWORK` du `.env` (defaut : `admin_proxy` — convention de l'auteur). A adapter a votre infrastructure.
3. Les FQDN cibles pointent en DNS vers l'hote

### Sur un hote deja equipe (Docker + Traefik)

```bash
git clone git@github.com:AcadeNice/wakdo_corentin.git
cd wakdo_corentin
cp .env.example .env
# Editer .env : DB_PASSWORD, DB_ROOT_PASSWORD, APP_URL_*, TRAEFIK_DOMAIN_*
make init
```

> **Attention au `.env` pre-existant.** Si un fichier `.env` existe deja a la racine (tooling externe, autre plateforme installee dans le meme repertoire), **ne pas faire** `cp .env.example .env` — cela ecraserait les variables existantes. Faire un **merge manuel** a la place : ajouter les variables manquantes du template dans le `.env` actuel. Les prefixes de variables de ce projet (`APP_`, `DB_`, `SESSION_`, `CORS_`, `UPLOAD_`, `CRON_`, `TRAEFIK_`, `REVERSE_PROXY_`) sont disjoints de ceux utilises par des outils tiers courants, donc la cohabitation est safe.

Critere RNCP Cr 7.c.4 couvert : une seule commande (`make init`) orchestre build, demarrage, attente BDD, migrations et seed.

Services accessibles apres `make init` :
- Borne : la valeur de `TRAEFIK_DOMAIN_KIOSK` dans `.env`
- Admin + API : la valeur de `TRAEFIK_DOMAIN_ADMIN` dans `.env`

Liste complete des cibles : `make help`.

### Installation Docker sur un hote neuf (Debian / Ubuntu)

Procedure officielle detaillee : `https://docs.docker.com/engine/install/` (selectionner la distribution). Resume pour Debian stable :

```bash
sudo apt update
sudo apt install -y ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/debian/gpg \
  -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] \
  https://download.docker.com/linux/debian \
  $(. /etc/os-release && echo $VERSION_CODENAME) stable" \
  | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io \
  docker-buildx-plugin docker-compose-plugin
sudo usermod -aG docker $USER
# Fermer et rouvrir la session pour activer le groupe docker
```

### Reseau externe du reverse proxy

Le `docker-compose.yml` attend un reseau Docker externe deja existant sur l'hote, dont le nom est donne par la variable `REVERSE_PROXY_NETWORK` (defaut : `admin_proxy`).

Si vous avez deja un Traefik en place, ce reseau a generalement ete cree par son propre stack. Adaptez la variable `REVERSE_PROXY_NETWORK` dans votre `.env` au nom utilise par votre proxy. Sinon, creez-le manuellement :

```bash
docker network create mon_reseau_proxy
# puis dans .env :
# REVERSE_PROXY_NETWORK=mon_reseau_proxy
```

Avant le premier `make init`, s'assurer que le reseau existe. Verification rapide :

```bash
docker network inspect "$(grep ^REVERSE_PROXY_NETWORK .env | cut -d= -f2)"
```

Si la commande retourne une erreur, soit adapter `REVERSE_PROXY_NETWORK` au nom du reseau utilise par votre proxy, soit creer le reseau manuellement. La cible `make init` echoue proprement avec un message d'aide si le reseau est introuvable.

*Section mise a jour au fil de l'implementation (migrations reelles, seed, CI/CD deploiement).*

---

## Structure du projet

```
.
|-- .claude/                     # Methodologie BYAN (visible jury : CLAUDE.md + rules/)
|-- .github/
|   `-- workflows/               # CI/CD GitHub Actions [a venir]
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
|-- Makefile
|-- docker-compose.yml
`-- README.md
```

---

## Developpement

### Conventions

- **Commits** : Conventional Commits en anglais (`feat`, `fix`, `docs`, `refactor`, `test`, `chore`, `ci`, `db`, `perf`, `style`). Format : `type(scope): description`. Voir `docs/PROJECT_CONTEXT.md` section 9.
- **Branches** : `feat/*`, `fix/*`, `refactor/*`, `docs/*`, `ci/*`, `db/*`, `chore/*`, `test/*` depuis `dev`. Merge vers `dev` par PR squashee. Periodiquement `dev` -> `main` par PR avec tag semver.
- `main` et `dev` sont proteges cote GitHub (PR requise, force push bloque, resolution des conversations requise).
- Pas d'emoji dans le code, les commits ou les specs techniques (Mantra IA-23).

*Sections detaillees (setup env de dev, lint, tests) : a completer au fil de l'implementation.*

---

## Tests

*Section a completer. Strategie globale : PHPUnit via `.phar` autonome (sans Composer), priorite Unit > Integration > E2E, voir `docs/PROJECT_CONTEXT.md` section 6 et mantras Merise Agile.*

---

## Deploiement

*Section a completer. Strategie cible : CI GitHub Actions sur PR vers `dev` (lint + PHPUnit), CD automatique sur merge vers `main` via SSH + `make rebuild`, voir `docs/PROJECT_CONTEXT.md` section 7 Bloc 5.*

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
