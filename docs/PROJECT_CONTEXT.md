# Wakdo — Project Context

**Source de verite du projet.** Ce document est injecte comme contexte dans tous les agents BYAN utilises pour ce projet (expert-merise-agile, architect, dev, ux-designer, tea, quinn, etc.). Il doit etre mis a jour des qu'une decision structurante change.

---

## 1. Identite projet

| Champ | Valeur |
|---|---|
| Nom du projet | **Wakdo** — borne de commande fictive (pastiche McDonald's) |
| Auteur | Corentin |
| Cadre | Epreuve RNCP 37805 — Titre Developpeur Web, B2, option **DevOps** |
| Centre | Acadenice |
| Contexte pro | Alternance en tant qu'admin sys + etudiant B2 DevOps |
| Deadline soutenance | **Septembre 2026** |
| Budget heures | 10-15 h/semaine — cible ~240 h effectives |
| Mode de travail | Solo |
| Date de creation du doc | 2026-04-23 |

---

## 2. Contexte metier

Wakdo est une **borne de commande tactile** pour un restaurant de restauration rapide. L'utilisateur final (client) compose sa commande sur ecran tactile, valide, recupere un numero, puis retire son produit au comptoir.

### Acteurs

| Acteur | Role | Interface |
|---|---|---|
| **Client** | Passe sa commande sur la borne | Borne tactile (Bloc 1) |
| **Accueil** | Saisit commandes au **comptoir** (client au guichet) ou au **drive** (client en voiture via intercom + casque equipier), remet les commandes livrees aux clients | Back-office (Bloc 2) |
| **Preparation** | Voit les commandes a preparer triees par heure croissante, les declare "preparees" | Back-office (Bloc 2) |
| **Administration** | CRUD sur donnees (produits, menus, prix, images) + gestion utilisateurs + stats | Back-office (Bloc 2) |

### Processus metier cle

```
Client                Borne (Bloc 1)           API (Bloc 2)          BDD
  │                       │                       │                    │
  │─compose panier──────▶│                       │                    │
  │                       │─GET menus,produits───▶│───SELECT──────────▶│
  │                       │◀──────────JSON────────│◀───────────────────│
  │─valide────────────────│                       │                    │
  │─saisit numero─────────│                       │                    │
  │                       │─POST /api/orders─────▶│───INSERT──────────▶│
  │                       │◀──────────201─────────│                    │
  │─recupere au comptoir  │                       │                    │
                          Preparation voit commande pending
                          → declare "preparee"
                          Accueil voit commande prete
                          → declare "livree"
```

### Regles metier (MCT - a modeliser en Merise)

- Un **menu** = burger + accompagnement (frites OU salade) + boisson + sauce
- Les **accompagnements** et **boissons** ont **2 tailles** (normale / grande)
- **Grande taille** = +0,50 € sur le prix de base
- Une **commande** a un **numero** saisi par le client (remplace le paiement dans le cadre de l'exam)
- Statuts commande : `pending` -> `preparing` -> `ready` -> `delivered` (ou `cancelled`)
- **Source commande** (trace sur chaque commande) : `kiosk` (borne autonome) | `counter` (comptoir) | `drive` (drive-thru)
- La preparation voit les commandes triees par **heure de livraison croissante** (tous canaux confondus)
- **Horaires service** : 10h00 → 01h00 du matin (service continu 15h, pas de fermeture intermediaire)
- **Pas de notion de "session de service" a modeliser** : les equipiers se relaient, chacun se connecte a sa prise de poste et se deconnecte a la fin. Pas de "shift" a tracer dans la BDD (hors scope RNCP)
- **Fenetre de maintenance systeme** : 01h30 → 09h30 (crons lourds, backups, agregations) — evite toute interference avec le service actif
- **Notion de "jour de service"** (important pour les stats et l'agregation) :
  - Un jour de service J = toutes les commandes creees entre **J 10h00** et **J+1 01h00**
  - Exemple : la soiree du 22/04 = commandes `22/04 10:00 → 23/04 01:00`, regroupees sous `service_day = 2026-04-22`
  - Une commande creee a 23h55 le 22/04 et livree a 00h30 le 23/04 appartient au meme jour de service (22/04)
  - **Implementation** : colonne `service_day` calculee sur la table `orders`, ou vue SQL :
    ```sql
    service_day = CASE
      WHEN HOUR(created_at) < 10 THEN DATE(created_at - INTERVAL 1 DAY)
      ELSE DATE(created_at)
    END
    ```
  - Les requetes de stats (CA jour, produits top, etc.) utilisent `service_day` et non `DATE(created_at)` brut

---

## 3. Contexte academique — RNCP 37805

**Referentiel** : [certifpro.francecompetences.fr](https://certifpro.francecompetences.fr/api/fiches/refActivity/24345/472313)

### Blocs couverts

| Bloc | Nom | Statut | Activites |
|---|---|---|---|
| **Bloc 1** | Developpement Front-End | Tronc commun obligatoire | A1 (integration), A2 (fonctionnalites JS) |
| **Bloc 2** | Developpement Back-End | Tronc commun obligatoire | A3 (data/BDD), A4 (back-end) |
| **Bloc 5** | DevOps (option 3) | Option choisie | A7 (automatisation + conteneurisation + CI/CD) |

### Validation

- **50 % minimum par bloc** pour le valider
- **50 % moyenne globale** pour valider le titre
- **Stage** = 20 % de la note finale (couvert par l'alternance)
- **Controle continu** 30 % + **Examens jurys** 50 %

---

## 4. Strategie de projet

### Strategie B — unifiee

**Un seul codebase, deux FQDN d'exposition publique.** Le front Bloc 1 et le back Bloc 2 coexistent dans la meme arborescence. Une bascule mode JSON-seuls (Bloc 1 isole) vs mode API-connecte doit rester possible via configuration.

**Pourquoi pas strategie A (deux rendus isoles)** : le Bloc 5 DevOps impose une conteneurisation **unique** qui lance la stack complete avec `make init` en une commande (Cr 7.c.4). Deux codebases isolees seraient incoherentes avec cette exigence.

### Compatibilite evaluation par bloc

- **Jury Bloc 1** : voit le front seul ; le front peut tomber en fallback sur JSON statiques fournis (`src/public/borne/data/*.json`) si l'API est indisponible.
- **Jury Bloc 2** : voit le back-office + teste l'API via curl/Postman de maniere autonome, sans dependre du front.
- **Jury Bloc 5** : lance `make init` ou `docker compose up`, verifie la CI/CD, les crons, l'archi, les scripts.

---

## 5. Architecture

### 2 FQDN exposes

| FQDN | Role | Bloc | Auth |
|---|---|---|---|
| `corentin-wakdo.stark.a3n.fr` | Borne client (kiosk tactile) | Bloc 1 | Public |
| `corentin-wakdo-admin.stark.a3n.fr` | Back-office + API REST (sous `/api/*`) | Bloc 2 | Sessions (back-office) + tokens (API ecriture) |

**CORS** : la borne (`corentin-wakdo.stark.a3n.fr`) consomme l'API (`corentin-wakdo-admin.stark.a3n.fr/api/*`). Headers CORS explicites avec origine precise (pas de wildcard `*`), argumentable comme durcissement securite face au jury.

### Services Docker

```
┌─────────────────────────────────────────────────────────────────┐
│                       Traefik (existant)                         │
│                    (admin_proxy network)                         │
└──────────┬────────────────────────────┬─────────────────────────┘
           │                            │
   wakdo.acadenice.fr      wakdo-admin.acadenice.fr
           │                            │
           ▼                            ▼
    ┌──────────────────────────────────────────┐
    │       wakdo-web (Apache Alpine)           │
    │   Front controller + reverse vers FPM     │
    └──────────┬───────────────────────────────┘
               │ FastCGI :9000
               ▼
    ┌──────────────────────────────────────────┐
    │      wakdo-app (PHP 8.3-fpm Alpine)       │
    │   POO MVC — borne.php, admin.php, api.php │
    └──────────┬───────────────────────────────┘
               │ PDO MySQL
               ▼
    ┌──────────────────────────────────────────┐
    │          wakdo-db (MariaDB 11)            │
    │            volume persistant              │
    └──────────────────────────────────────────┘

    ┌──────────────────────────────────────────┐
    │       wakdo-cron (Alpine + PHP CLI)       │
    │  crond — backup BDD, purge sessions, ...  │
    └──────────────────────────────────────────┘
```

Reseaux :
- `admin_proxy` (external) — expose `wakdo-web` a Traefik
- `wakdo_internal` (bridge, interne) — isole `wakdo-app`, `wakdo-db`, `wakdo-cron`

---

## 6. Stack technique lockee

| Couche | Choix | Version | Raison |
|---|---|---|---|
| Front langages | HTML5 + CSS3 + JavaScript | Standards 2024+ | Exigence Bloc 1 (vanilla) |
| Front framework | **Aucun** | — | Sujet Bloc 1 impose |
| Front dep JS | **Aucune** | — | Exigence explicite |
| Back langage | PHP | **8.3** LTS | Moderne, support jusqu'a 2027 |
| Back framework | **Aucun** | — | Sujet Bloc 2 "from scratch" |
| Autoloader | **Manuel** (spl_autoload_register, PSR-4) | — | Defend "from scratch", Cr 4.c.3 compatible |
| Tests | **PHPUnit** via `.phar` autonome | 11.x | Cr 4.g.2 sans Composer |
| Composer | **Non utilise** | — | Colle au "from scratch" |
| BDD | MariaDB | **11** LTS | Compatible MySQL, LTS 2028 |
| Driver BDD | PDO (prepared statements uniquement) | natif PHP | Cr 4.e.1 anti-injection |
| Serveur web | Apache httpd | Alpine latest | Reverse proxy vers PHP-FPM |
| Serveur app | PHP-FPM | 8.3-fpm-alpine | Contenairisation propre |
| Reverse proxy | Traefik (deja en place) | existant | `admin_proxy` network |
| TLS | Let's Encrypt via Traefik | auto | `acme.json` existant |
| Conteneurisation | Docker + docker compose | v2 | Cr 7.c |
| Orchestration locale | Makefile | — | Cr 7.b (script) + Cr 7.c.4 (une commande) |
| CI/CD | GitHub Actions | — | Cr 7.d |
| Versioning | Git + GitHub | — | Cr 4.f (collaboration) |
| Hooks Git | pre-commit + commit-msg | versionnes dans `.githooks/` | Conventional Commits |

---

## 7. Scope fonctionnel

### Bloc 1 — Borne client (Front)

**IN scope :**
- Affichage dynamique menus + produits (charges par Ajax depuis API ou JSON fallback)
- Composition panier : produits unitaires OU menus (burger + accompagnement + boisson + sauce)
- Options taille (normale / grande, +0,50 € sur grande) pour accompagnements et boissons
- Options de personnalisation simples (ex : sans oignon, avec fromage)
- Recapitulatif panier (ajout, modification quantite, suppression)
- Validation commande + saisie numero (remplace paiement)
- Envoi POST JSON de la commande vers l'API
- Ecran de confirmation avec numero
- Responsive cible 1920x1080 portrait (borne) **+ adaptatif** autres resolutions
- **Accessibilite RGAA** : police OpenDys pour dyslexiques, navigation clavier, contrastes, alts, pas d'info via couleur seule
- **SEO / semantique** : balises HTML5 (`article`, `aside`, `nav`), schema.org, meta tags uniques, canonical, favicon

**OUT scope :**
- Paiement reel (remplace par numero de commande)
- Authentification client (pas de compte fidelite)
- Multi-langue (FR uniquement)
- Offline mode

### Bloc 2 — Back-office + API (Back)

**IN scope — Back-office :**
- Authentification sessions securisees (hash bcrypt/argon2, protection CSRF, fixation session) — duree de session adaptee a un poste complet d'equipier (idle timeout 4h, absolute timeout 10h)
- 3 roles RBAC : `admin`, `preparation`, `accueil`
- **Admin** : CRUD categories, produits (nom, description, prix, image, dispo), menus (composition + options), utilisateurs
- **Preparation** : liste commandes a preparer triees par heure livraison croissante, bouton "declarer preparee"
- **Accueil** : saisir commande manuellement (comptoir ou drive-thru via casque/intercom), bouton "declarer livree" ; champ `source` enregistre sur chaque commande (`counter` ou `drive`)
- Upload images produits (validation type MIME + taille + stockage dans volume `wakdo_uploads`)
- Historique commandes par statut
- Stats de base (commandes du jour, CA jour, produits top)

**IN scope — API REST :**
- `GET /api/categories` — liste categories produits
- `GET /api/products` — liste produits (filtrable par categorie)
- `GET /api/menus` — liste menus avec compositions et options
- `POST /api/orders` — creer une commande (body JSON, retour `{id, number, status}`)
- `GET /api/orders/{number}` — recuperer statut commande
- Headers CORS explicites pour l'origine borne
- Reponses JSON standardisees : `{data: ..., error: null}` ou `{data: null, error: {code, message}}`

**IN scope — Transverse :**
- Architecture **MVC** stricte (Models / Views / Controllers)
- **Heritage** entre classes (ex : `BaseController` -> `AdminController` -> `ProductController`)
- **Namespaces** + autoloader PSR-4 manuel
- **Anti-injection** : PDO prepared statements exclusivement
- **RGPD** : hash mdp, droit consultation/modif/suppr user, info stockage/utilisation donnees

**OUT scope :**
- Paiement reel, systeme comptable
- Multi-restaurants / multi-bornes
- Fidelite client
- Rapports avances / exports CSV

### Bloc 5 — DevOps

**IN scope :**
- Dockerfile custom PHP-FPM avec extensions
- `docker-compose.yml` orchestrant les 4 services (web, app, db, cron)
- `Makefile` avec cible `make init` qui lance tout en une commande (Cr 7.c.4)
- Scripts Bash d'automatisation (backup, deploy, migrate)
- **Cron tab** avec au moins 3 jobs planifies dans la fenetre de maintenance (01h30-09h30) :
  - `0 3 * * *` — backup BDD quotidien a 03h00 (entre fin service 01h et ouverture 10h)
  - `*/15 * * * *` — purge sessions expirees toutes les 15 min (leger, peut tourner en service)
  - `30 4 * * *` — agregation stats commandes a 04h30 sur le **jour de service** ecoule (10h J-1 → 01h J)
- **CI GitHub Actions** : lint PHP + PHPUnit sur PR -> dev
- **CD GitHub Actions** : deploy auto sur merge main (SSH + pull + `make rebuild`)
- `.env.example` documente, secrets hors du repo
- Healthcheck Traefik + readiness probes
- Logs centralises (stdout des conteneurs)
- Documentation deploiement + architecture (schemas dans `docs/`)

**OUT scope :**
- Kubernetes / Swarm
- Monitoring complet type Prometheus/Grafana (trop chronophage)
- Multi-environnements (pre-prod, staging)
- Blue-green deployment

---

## 8. Criteres RNCP — mapping feature

### Bloc 1

| Critere | Libelle court | Feature Wakdo couvrant |
|---|---|---|
| Cr 1.a.1 | Integration conforme maquette | Integration HTML/CSS fidele au prototype |
| Cr 1.a.2 | Normes W3C + accessibilite | Validator W3C vert + RGAA |
| Cr 1.a.3 | Tests validator passent | Test en soutenance via W3C validator |
| Cr 1.a.4 | Code commente, indente | Conventions de code appliquees partout |
| Cr 1.a.5 | Balises semantiques | `<article>`, `<aside>`, `<nav>`, `<header>`, `<main>`, `<section>` |
| Cr 1.b.1-3 | Responsive + multi-navigateurs | Media queries + @supports + tests Chrome/FF/Safari |
| Cr 1.c.1-4 | Accessibilite RGAA/OpenDys | alt, aria-label, navigation clavier, police OpenDys, pas d'info via couleur seule |
| Cr 1.d.1-4 | Classes CSS reutilisables | Convention BEM ou similaire, regroupe par theme, sans repetition |
| Cr 1.e.1-11 | SEO + meta + semantique | hierarchie titres, schema.org, canonical, alt images, favicon, temps chargement |
| Cr 2.a.1-5 | JS ES6+ + DOM + animations | Modules ES6, classes, async/await, pas de jQuery |
| Cr 2.b.1-3 | Validation formulaires | Validation client temps reel (regex) + validation serveur |
| Cr 2.c.1-4 | Ajax async | `fetch()` avec gestion erreurs, pas d'exposition donnees sensibles |
| Cr 2.d.1-3 | Librairies externes | **Non applicable** (zero dep JS) — argumenter "developpement sans lib externe" |

### Bloc 2

| Critere | Libelle court | Feature Wakdo couvrant |
|---|---|---|
| Cr 3.a.1-4 | Analyse + modele donnees | Dictionnaire + MCD + cardinalites |
| Cr 3.a.3 | Exploiter donnees externes d'API | API interne consommee par le front (auto-consommation) |
| Cr 3.b.1-3 | Construction BDD | MCD → MLD → DDL MariaDB, FK + typage coherent |
| Cr 3.c.1-3 | Requetes SQL optimisees | PDO prepared, index sur FK, LIMIT/tri explicites |
| Cr 3.d.1-4 | RGPD | hash mdp, droit acces/modif/suppr, info utilisation donnees |
| Cr 4.a.1-4 | Conceptualisation | Schema fonctionnel des vues + interactions |
| Cr 4.b.1-6 | Syntaxe + indentation + erreurs | PSR-12 style, try/catch cibles, logs |
| Cr 4.c.1-3 | POO + heritage + namespaces | `BaseModel` -> `Product`, `BaseController` -> `AdminController`, PSR-4 |
| Cr 4.d.1-3 | MVC | `src/Models/`, `src/Views/`, `src/Controllers/`, separation stricte |
| Cr 4.e.1-3 | Securite | PDO prepared (anti-SQLi), sessions regeneration, role-based middleware |
| Cr 4.f.2 | Maitrise outil collaboratif (artefact) | Commits Conventional, branches `feat/*`, PR descriptions, squash merge, hooks Git |
| Cr 4.f.1, 4.f.3, 4.f.4 | Soft skills (evalues a l'oral) | Partage de savoir-faire (4.f.1), auto-evaluation avant PR (4.f.3), compte-rendu de la participation individuelle (4.f.4) — demontres pendant la soutenance |
| Cr 4.g.1-4 | Preparation livraison | PHPUnit tests verts, pas d'erreur en prod, testee deployee |

### Bloc 5

| Critere | Libelle court | Feature Wakdo couvrant |
|---|---|---|
| Cr 7.a.1-3 | Analyse infra + securite | Audit code + proposition automatisation documentee |
| Cr 7.b.1 | Langage de script | Bash (deploy, backup) + Makefile |
| Cr 7.b.2 | Automatisation fiabilisee | Makefile avec exit codes, retries, logs |
| Cr 7.b.3 | **Cron tab** | `wakdo-cron` service avec crontab : backup BDD, purge sessions, stats |
| Cr 7.c.1 | VM operationnelle | Serveur existant Acadenice |
| Cr 7.c.2 | OS conteneur installe | Docker Engine |
| Cr 7.c.3 | App conteneurisee complete | 4 services (web, app, db, cron) |
| Cr 7.c.4 | **Une ligne de commande** | `make init` lance toute la stack + migrate + seed |
| Cr 7.d.1 | Architecture serveur | Traefik reverse + reseaux segmentes documentes |
| Cr 7.d.2 | Tests avant deploy | CI PHPUnit + lint sur PR |
| Cr 7.d.3 | Integration/deploiement continus | GitHub Actions deploy automatique sur merge main |

---

## 9. Conventions

### Git

**Branches :**
```
main            ← production (tag vX.Y.Z sur chaque release)
  dev           ← integration
    feat/*      ← nouvelles features
    fix/*       ← corrections
    refactor/*  ← refactos
    docs/*      ← doc seulement
    ci/*        ← GitHub Actions
    db/*        ← migrations / schema BDD
    chore/*     ← tooling, config
    test/*      ← ajout de tests
```

Les branches `main` et `dev` sont **protegees** cote GitHub. Pas de commit direct autorise. Hook pre-commit local les bloque egalement.

**Flow :**
1. `git checkout -b feat/menu-composition` (depuis `dev`)
2. Commits atomiques Conventional Commits
3. Push + PR vers `dev`
4. Merge squash
5. Periodiquement `dev` -> `main` via PR avec tag semver

**Commits** — Conventional Commits, en anglais :

```
<type>(<scope>): <description imperative min 5 chars>

<body optionnel si changement complexe>
```

- **Types** : `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `ci`, `db`, `perf`, `style`
- **Scopes Wakdo** : `front`, `back`, `api`, `admin`, `auth`, `db`, `docker`, `ci`, `docs`
- **Interdits** : emoji (Mantra IA-23), description en francais, WIP commits
- **Exemples :**
  - `feat(front): add menu composition screen with size options`
  - `fix(api): correct order total calculation with large size`
  - `db: add migration 003 orders with fk to users`
  - `docker: add cron service with daily backup job`
  - `ci: add phpunit workflow on pull_request`

### Code PHP

- **PSR-12** style (indentation 4 espaces, namespaces 1 classe par fichier, `{` sur nouvelle ligne pour classes/methodes)
- **Namespaces** : `App\Controllers`, `App\Models`, `App\Core`, `App\Services`
- **1 classe = 1 fichier**, nom fichier == nom classe (PascalCase)
- **Proprietes typees** (PHP 8.3) : `private int $id`, `private string $name`
- **Retours typees** : `public function find(int $id): ?Product`
- **Commentaires** : pour le **pourquoi** (Mantra IA-24), pas le quoi
- **Docblocks** : sur methodes publiques uniquement, concis

### Code JavaScript

- **Vanilla ES6+** : `const`/`let`, arrow functions, destructuring, modules `import`/`export`
- **Pas de `var`**
- **Modules** : fichiers `.js` avec `export` explicite
- **Async** : `async/await` prefere au chaining `.then()` complexe
- **Event delegation** : sur conteneurs parents plutot que listener par element
- **Aucune lib externe** (pas jQuery, pas Lodash)

### Code CSS

- **BEM** naming : `.block__element--modifier`
- **Variables CSS** dans `:root` (palette couleurs, spacings, fonts)
- **Mobile-first** : `min-width` media queries
- **1 fichier par composant** dans `src/public/assets/css/components/`, assemble via `@import` ou concat build

### BDD

- **Tables** : `snake_case`, pluriel (`products`, `orders`, `order_items`)
- **Colonnes** : `snake_case`
- **PK** : `id` BIGINT UNSIGNED AUTO_INCREMENT
- **FK** : `<table_singulier>_id` (ex : `product_id`, `user_id`)
- **Timestamps** : `created_at`, `updated_at` (DATETIME default CURRENT_TIMESTAMP)
- **Soft delete** : `deleted_at` DATETIME NULL quand applicable
- **Index** systematique sur FK + colonnes de recherche frequente
- **Collation** : `utf8mb4_unicode_ci`

---

## 10. Decisions techniques lockees — recap

| # | Decision | Justification |
|---|---|---|
| 1 | Strategie B unifiee | Cr 7.c.4 impose une stack unique lancable en 1 commande |
| 2 | PHP 8.3 LTS | Moderne, supporte jusqu'en 2027 |
| 3 | Pas de framework PHP | Sujet Bloc 2 "from scratch" explicite |
| 4 | Pas de Composer | Colle au "from scratch", autoloader manuel autorise par Cr 4.c.3 |
| 5 | PHPUnit via .phar | Tests unitaires requis Cr 4.g.2, sans dep Composer |
| 6 | MariaDB 11 LTS | LTS 2028, compatible MySQL |
| 7 | Apache Alpine + PHP-FPM Alpine | Demande utilisateur (admin sys), images legeres |
| 8 | 2 FQDN | Separation claire borne publique / admin+API interne, defensible jury |
| 9 | API sous `/api` sur le FQDN admin | Simplicite d'exploitation, CORS explicite gere |
| 10 | Service cron dedie | Cr 7.b.3 explicite + realiste prod |
| 11 | Makefile avec `make init` | Cr 7.c.4 + demonstration DevOps |
| 12 | Conventional Commits + hooks | Cr 4.f.x + discipline de versioning |
| 13 | Branches feat/* -> dev -> main | Pipeline propre pour jury, GitHub PR trace |
| 14 | CI/CD GitHub Actions | Cr 7.d explicite dans referentiel |
| 15 | RGPD implemente minimal | Cr 3.d.1-4 evaluees meme projet ecole |

---

## 11. Planning — budget heures

| Phase | Scope | Budget (h) | Deadline intermediaire |
|---|---|---|---|
| **P0 - Setup** | PC, arborescence, Docker, hooks, CI squelette, init Git/GitHub | 20 | Semaine 1 |
| **P1 - Conception Merise** | Dictionnaire, MCD, MCT, MLD, schemas fonctionnels, DDL | 30 | Semaine 3 |
| **P2 - Back squelette** | POO base (Core, Router, Autoloader, DB), auth + roles | 30 | Semaine 6 |
| **P3 - Back CRUD admin** | Produits, menus, utilisateurs, views | 40 | Semaine 10 |
| **P4 - API REST** | Endpoints + CORS + tests | 20 | Semaine 12 |
| **P5 - Front borne** | Integration maquette, Ajax, accessibilite, responsive | 60 | Semaine 16 |
| **P6 - Tests + finition** | PHPUnit, tests E2E borne, corrections | 25 | Semaine 18 |
| **P7 - DevOps finalisation** | CI/CD deploy auto, crons, docs argumentation | 20 | Semaine 19 |
| **P8 - Prep soutenance** | README pour jury, schemas finaux, repetitions, modifs en direct | 15 | Semaine 20 |
| **TOTAL** | | **260** | **Semaine 20 = fin aout 2026** |

Buffer : ~20 h pour imprevus. Cible effective : ~240 h sur 20 semaines = **12 h/semaine**.

---

## 12. Livrables

### Par bloc

**Bloc 1 :**
- App front deployee et fonctionnelle sur `https://corentin-wakdo.stark.a3n.fr`
- Code source Git accessible au jury
- Validator W3C screenshot (HTML + CSS verts)
- Checklist RGAA auto-evaluee

**Bloc 2 :**
- **Dictionnaire de donnees** (`docs/merise/dictionary.md`)
- **MCD** schema (`docs/merise/mcd.md` + image)
- **MCT** schemas (`docs/merise/mct.md` + diagrammes)
- **MLD** (`docs/merise/mld.md`)
- **Schema fonctionnel** de l'app (`docs/architecture/functional-schema.md`)
- **BDD** deployee (dump SQL dans `db/migrations/` + script init)
- App back-office deployee sur `https://corentin-wakdo-admin.stark.a3n.fr`
- Documentation API (OpenAPI minimal ou README API)

**Bloc 5 :**
- `docker-compose.yml` commente
- Dockerfiles customs commentes
- `Makefile` avec `make help`
- `.github/workflows/` avec CI + CD
- Crontab documente
- Script de backup/restore teste
- Architecture serveur decrite (`docs/architecture/deployment.md`)

### Pour la soutenance (tous blocs)

- **README.md** synthetique (quick start + liens docs)
- **Presentation** (slides ou live) argumentant les choix
- **Demo** live : borne + back-office + API (Postman/curl) + `make init`
- **Capacite modification en direct** (Cr 4.a.1) : code structure pour permettre modifs sans casser

---

## 13. Risques et mitigations

| Risque | Probabilite | Impact | Mitigation |
|---|---|---|---|
| Sous-estimation temps front (accessibilite RGAA stricte) | Haute | Moyen | 60 h budgetees + tests W3C/axe-core pendant le dev, pas a la fin |
| Complexite MCT (statuts commande) mal modelisee | Moyenne | Fort | Valider MCT avec un pair ou prof avant d'implementer Bloc 2 |
| Dockerfile PHP extensions manquantes decouvert tard | Moyenne | Faible | Tester `make up` + un vrai appel BDD des P0 |
| Conflit reseau Docker `wakdo_internal` existant | Faible | Faible | Verifie au setup, fallback nom `wakdo_backend` |
| CORS mal configure bloque la borne | Moyenne | Moyen | Test immediat apres setup 2 FQDN |
| Performance borne sur ecran tactile reel | Faible | Fort | Optimiser images + lazy loading + tests sur device tactile si possible |
| Scope creep (ajout fonctionnalites non RNCP) | Haute | Fort | Sticker le scope, OUT scope documente ici, refuser les ajouts |
| Gestion secrets (`.env`) leaked sur Git | Faible | Tres fort | `.env` liste dans `.gitignore` des P0, pre-commit hook check secrets futur |

---

## 14. Glossaire metier (fast-food)

| Terme | Definition |
|---|---|
| **Menu** | Combinaison burger + accompagnement + boisson + sauce |
| **Combo** | Synonyme menu (terme marketing US) |
| **Borne** | Ecran tactile autonome dans le restaurant, le client compose lui-meme (canal `kiosk`) |
| **Comptoir** | Guichet physique, equipier saisit la commande pour le client (canal `counter`) |
| **Drive** | Piste drive-thru : client en voiture, borne intercom + haut-parleur, equipier avec casque + tablette saisit la commande (canal `drive`) |
| **Jour de service** | Periode d'activite 10h J -> 01h J+1 (15h continu), unite d'agregation pour toutes les stats commerciales |
| **Fenetre de maintenance** | Periode 01h30 -> 09h30 reservee aux taches systeme (backups, stats, purges) |
| **Accompagnement** | Frite, salade, potatoes — option avec taille |
| **Supplement** | Ajout optionnel sur un produit (fromage, bacon) — peut impacter prix |
| **Option** | Retrait ou modif produit (sans oignon, sans sauce) — neutre prix |
| **Panier** | Liste des produits/menus selectionnes par le client avant validation |
| **Commande** | Panier valide, dote d'un numero, en attente de preparation |
| **Preparation** | Etat "en cuisine" |
| **Livraison** | Remise au client au comptoir |
| **Ticket** | Numero de commande (remplace paiement dans l'exam) |

## 15. Glossaire technique

| Terme | Definition |
|---|---|
| **Borne** | Interface tactile publique pour client final (Bloc 1) |
| **Back-office** | Interface interne pour employes (Bloc 2) |
| **API interne** | API consommee par la borne, non publique a tiers |
| **PSR-4** | Standard PHP d'autoloading classes par namespace |
| **PDO** | Abstraction BDD PHP avec prepared statements (anti-SQLi) |
| **BEM** | Convention naming CSS Block__Element--Modifier |
| **RGAA** | Referentiel General d'Amelioration de l'Accessibilite (France) |
| **OpenDys** | Police de caractere pour personnes dyslexiques |

---

## 16. Points d'attention jury (prep argumentation)

Le jury Bloc 2 demande **capacite a modifier le code en direct** (Cr 4.a.1). Il faut donc :

- **Connaitre son code** : pouvoir expliquer chaque fichier et la raison de sa presence
- **Argumenter les choix** : pas Laravel ? pourquoi pas. POO + heritage ? demontrer l'arbre. MVC ? montrer qu'une route traverse un controller puis un model puis une vue
- **Justifier l'archi Docker** : pourquoi 4 services, pourquoi Alpine, pourquoi Traefik
- **Comprendre la BDD** : defendre les cardinalites, les contraintes, les index
- **Expliquer les choix securite** : PDO prepared, bcrypt, CSRF, sessions, CORS
- **Avoir un plan B** : savoir repondre "si le jury vous demande d'ajouter X, par ou commencer"

Preparer des **questions frequentes** :
- "Pourquoi pas Laravel/Symfony ?" → Sujet impose from scratch + demo maitrise des fondamentaux
- "Pourquoi pas Composer ?" → Criteres Cr 4.c.3 autorise manuel, plus defendable "from scratch"
- "Comment gerez-vous l'injection SQL ?" → PDO prepared statements exclusivement, montrer exemple dans Models
- "Votre API ne gere pas X role" → Fallback : middleware RBAC par role, montrer code
- "Comment deploieriez-vous en prod vrai ?" → Ajouter monitoring, blue-green, IaC, secrets manager

---

## 17. Transparence methodologie et usage d'assistants IA

Ce projet a ete developpe avec l'appui de **BYAN (Builder of YAN)**, un systeme d'agents IA custom applicant la methodologie **Merise Agile enrichie de 64 Mantras** (voir `.claude/CLAUDE.md` et `.claude/rules/`).

Cette section documente l'usage d'outils d'IA generative dans la conduite du projet, la delimitation precise de ce que l'IA fait et ne fait pas, et les dispositifs de tracabilite mis en place pour le jury.

### 17.1 Base d'autorisation

Le centre de formation Acadenice autorise explicitement l'usage d'assistants IA pour la realisation du projet de certification. La presente section formalise cet usage pour assurer la tracabilite vis-a-vis du jury RNCP.

Principe directeur : **toute decision structurante du projet est prise par l'auteur**. L'IA peut rediger, challenger, relire, proposer — elle ne choisit pas a la place de l'auteur.

### 17.2 Outils utilises

| Outil | Role | Depuis |
|---|---|---|
| **BYAN (Builder of YAN)** | Meta-framework methodologique : Merise Agile + TDD + 64 Mantras + fact-check scientifique + ELO trust. Oriente la demarche projet. | 2026-04-23 |
| **Claude Code** (Opus 4.7, Sonnet 4.6) | Interface d'interaction : redaction, co-programmation, lecture code, execution de commandes shell sous supervision. | 2026-04-23 |

La configuration des outils est versionnee et visible dans `.claude/CLAUDE.md` et `.claude/rules/*.md`.

L'auteur peut recourir ponctuellement a d'autres outils IA (completion IDE, assistants tiers). Leur usage respecte le meme cadre de scope defini en 17.3 et 17.4.

### 17.3 Scope — ce que l'IA fait

- **Redaction de texte** : documents de cadrage, retrospectives de session (`docs/journal/`), commentaires techniques, messages de commit. Supervise et valide par l'auteur avant versioning.
- **Co-programmation** : proposition de code PHP, JavaScript, CSS, SQL, Dockerfile, YAML. Lu, teste et valide par l'auteur avant commit.
- **Relecture critique** : challenge des choix techniques via le protocole fact-check (`.claude/rules/fact-check.md`) et le mantra "Challenge Before Confirm".
- **Execution de commandes shell** sous autorisation explicite de l'auteur via le mecanisme de permissions Claude Code.
- **Generation de tests unitaires** PHPUnit, revus par l'auteur.
- **Assistance au debug** : lecture de logs, proposition d'hypotheses, test des correctifs.
- **Redaction de la couche `docs/notes/`** (non versionnee) : fiches techniques destinees a la revision de l'oral.

### 17.4 Scope — ce que l'IA ne fait pas

- **Decisions d'architecture** : tranchees par l'auteur. L'IA propose des alternatives, l'auteur choisit.
- **Choix du scope fonctionnel** : defini par l'auteur a partir du brief RNCP. L'IA n'ajoute ni ne retire de fonctionnalite sans instruction explicite.
- **Modelisation Merise** (MCD, MCT, MLD) : formalisation produite par l'IA a partir du dictionnaire de donnees et des user stories ; arbitrage, validation et corrections par l'auteur. Chaque cardinalite, chaque relation et chaque transition de statut est validee par l'auteur avant integration. Le livrable final reflete ses decisions.
- **Validation des livrables** : reservee au jury. L'IA n'emet pas de jugement final sur la conformite RNCP.
- **Deploiements** : declenchement humain uniquement, y compris sur `make init` local. Aucune action sur environnement serveur sans instruction explicite.
- **Commit en son nom** : aucun trailer `Co-Authored-By: Claude...` n'est appose sur les commits. Voir section 17.7.
- **Decisions de securite critiques** : tous les choix de type hash mdp, CORS, RBAC, politique sessions sont valides par l'auteur meme si l'IA en propose la mise en oeuvre.

### 17.5 Dispositifs de tracabilite versionnes (visibles jury)

Fichiers committes dans le repo qui rendent la methodologie observable :

- `.claude/CLAUDE.md` : constitution du projet pour les agents Claude Code.
- `.claude/rules/` : les protocoles appliques au projet :
  - `fact-check.md` — exigence de sources pour tout claim technique absolu.
  - `merise-agile.md` — methodologie Merise + TDD + mantras.
  - `elo-trust.md` — calibration de l'intensite du challenge selon l'expertise auto-declaree.
  - `hermes-dispatcher.md` — routage entre specialistes.
  - `byan-api.md` et `byan-agents.md` — reference ecosysteme.
- `docs/PROJECT_CONTEXT.md` (le present document) : source de verite projet, injectee comme contexte aux agents.
- `docs/journal/` : retrospectives de session et de feature, format standardise, destinees a la preparation de l'oral et a la tracabilite jury.

### 17.6 Ce qui n'est pas versionne (et pourquoi)

| Categorie | Chemin | Raison |
|---|---|---|
| Moteur BYAN (code des agents) | `_byan/`, `_byan-output/` | Non pertinent pour le rendu RNCP. La methodologie qui s'en sert est visible dans `.claude/rules/`. |
| Configuration personnelle Claude | `.claude/` sauf `CLAUDE.md` et `rules/` | Etat local, logs conversations, config machine. Non pertinent et potentiellement sensible. |
| Notes techniques personnelles | `docs/notes/` | Supports de revision rediges par l'IA pour l'auteur. Ne font pas partie du livrable. Exclus pour eviter toute ambiguite sur ce qui est "de la main du candidat". |
| Notes de session | `docs/SESSION_*.md` | Documents de continuite entre sessions de travail. Usage personnel. |

### 17.7 Politique de commit

- **Conventional Commits** appliques (voir section 9).
- **Aucun `Co-Authored-By`** ajoute automatiquement, y compris par les outils IA. Raison : plusieurs outils IA peuvent etre utilises au fil du projet ; tagger un modele specifique serait reducteur, et des commits rediges sans assistance porteraient faussement le tag par defaut. La transparence de la methodologie vit dans la presente section et dans les `.claude/rules/`, pas dans les metadonnees de commit.
- **Messages de commit rediges ou co-rediges avec assistance IA** : aucune obligation de le signaler individuellement, la section 17 vaut declaration globale.

### 17.8 Declaration d'honnetete intellectuelle

L'auteur declare que :

1. Chaque decision d'architecture, de stack, de scope et de design documentee dans ce document a ete prise par lui, apres examen des alternatives proposees ou rappelees par l'IA.
2. Chaque ligne de code committee a ete lue, comprise et validee par lui.
3. Chaque contrainte du referentiel RNCP a ete lue directement dans la source officielle, pas seulement resumee par l'IA.
4. Les sections des documents redigees avec assistance IA sont des sections techniques ou de synthese (pas des sections de choix personnel) ; leur contenu factuel est verifie avant commit.
5. Si le jury demande a voir l'auteur raisonner sans assistance sur un sujet du projet (live-coding, explication orale, modification en direct), l'auteur est en mesure de le faire.

### 17.9 Protocoles de controle interne

Deux mecanismes sont en place pour garantir la rigueur :

- **Fact-check scientifique** (`.claude/rules/fact-check.md`) : l'IA doit sourcer tout claim technique absolu (`toujours`, `jamais`, `plus rapide`) par une source L1-L2 (spec officielle, benchmark, CVE). Les claims non sources sont marques `[HYPOTHESIS]` ou `[REASONING]`.
- **ELO trust** (`.claude/rules/elo-trust.md`) : systeme 0-1000 par domaine technique qui adapte l'intensite du challenge. Un claim dans un domaine ou le score est bas declenche explication pedagogique ; dans un domaine haut, l'IA va droit au but. L'auteur peut declarer son niveau initial par domaine.

Ces deux protocoles rendent les interactions IA tracables et auditables a posteriori via les logs Claude Code.

---

## 18. Regles invariantes

Ces regles tiennent lieu de garde-fous pendant toute la duree du projet. Les enfreindre demande une mise a jour explicite de ce document.

1. **Zero emoji** dans code, commits, docs techniques (Mantra IA-23)
2. **Zero Composer, zero vendor/** dans le repo
3. **Zero dep JS front** (pas jQuery, pas Bootstrap)
4. **Zero secret en clair dans Git** (`.env` liste dans `.gitignore`)
5. **Zero commit direct sur `main` ou `dev`** (hook bloque)
6. **Zero requete SQL sans prepared statement** (anti-SQLi)
7. **Zero hash mdp en clair** (bcrypt ou argon2)
8. **Zero CORS `*`** (origine explicite uniquement)
9. **Zero deployment manuel** en condition normale (CI/CD)
10. **Zero feature hors scope** sans mise a jour de ce document

---

*Document vivant — version 1.1 — 2026-04-24 (ajout section 17 transparence IA). A mettre a jour a chaque decision structurante.*
