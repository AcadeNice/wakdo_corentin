# Guide developpeur — Wakdo

Comment lancer, tester et contribuer. Pour l'architecture (stack, services, modele,
securite), voir `docs/ARCHITECTURE.md`. Pour le scope et le mapping RNCP,
`docs/PROJECT_CONTEXT.md`.

---

## 1. Prerequis

- Docker Engine + docker compose v2 (https://docs.docker.com/engine/install/).
- Node 20+ (uniquement pour les tests front borne ; pas requis pour faire tourner l'app).

Aucune installation de PHP / Composer / PHPUnit sur l'hote : tout passe par les
conteneurs et des `.phar` autonomes.

---

## 2. Lancer en local

```bash
cp .env.example .env
docker compose up -d
```

- Borne : http://kiosk.localhost:8080
- Admin + API : http://admin.localhost:8080

`*.localhost` resout vers `127.0.0.1`. Changer le port via `HTTP_PORT` dans `.env`.
Le `.env.example` fonctionne tel quel en local (valeurs dev). Au boot, le service
`wakdo-migrate` applique migrations + seed (admin bootstrap inclus) avant que l'app
ne serve.

Commandes utiles :

```bash
docker compose ps                 # etat des services
docker compose logs -f wakdo-app  # logs PHP-FPM
docker compose down               # arret (volumes preserves)
docker compose down -v            # arret + suppression des donnees
```

Deploiement derriere un reverse proxy : voir le `README.md` (section prod) +
`docker-compose.prod.yml` (gitignore, propre a l'hote).

---

## 3. Base de donnees : migrations & seed

- `db/migrations/*.sql` (DDL) et `db/seeds/*.sql` (donnees de reference) sont appliques
  de maniere **idempotente** par `wakdo-migrate` (suivi `schema_migrations` /
  `seeds_applied`). Relancer `docker compose up` ne rejoue que les fichiers en attente.
- **Ajouter une migration** : creer `db/migrations/000N_description.sql` (ordre
  lexicographique). Appliquee au prochain `docker compose up`, ou a la main :

```bash
bash db/migrate.sh            # applique les migrations en attente (hote)
bash db/migrate.sh --status   # liste l'etat sans rien appliquer
```

- Idem pour un seed : `db/seeds/000N_description.sql`.

---

## 4. Tests & analyse statique

Les tests PHP tournent dans l'image applicative (PHPUnit `.phar`). La stack doit etre
demarree pour les tests d'integration (ils ciblent le service `wakdo-db`).

```bash
# Tests unitaires (sans base)
docker run --rm -v "$PWD":/app -w /app wakdo-wakdo-app \
  php phpunit.phar -c phpunit.xml --testsuite unit

# Tests d'integration (vraie MariaDB ; auto-skip si WAKDO_DB_TESTS != 1)
docker run --rm --network wakdo_wakdo_internal --env-file .env -e WAKDO_DB_TESTS=1 \
  -v "$PWD":/app -w /app wakdo-wakdo-app php phpunit.phar -c phpunit.xml

# Analyse statique PHPStan niveau 6
docker run --rm -v "$PWD":/app -w /app wakdo-wakdo-app \
  php -d memory_limit=512M phpstan.phar analyse -c phpstan.neon --no-progress
```

Tests front borne (Node + jsdom) :

```bash
npm install          # une fois (devDependency jsdom)
npm run test:js      # node --test tests/js/
```

> Le nom de reseau `wakdo_wakdo_internal` et l'image `wakdo-wakdo-app` derivent du
> nom de projet compose (`name: wakdo`). Les `.phar` (phpunit, phpstan) sont
> gitignores ; les retelecharger si absents (voir `docs/journal/`).

---

## 5. Conventions de code

- **PSR-4 manuel** : namespace `App\` -> `src/app/`. Pas de framework.
- **Controleurs** : non-`final` (seam de test ; les tests sous-classent et injectent
  des doubles via `db()` / `sessionManager()`). Heritent de `AdminController`
  (back-office) ou `AuthenticatedController`.
- **Acces donnees** : un repository par entite, dependant de `DatabaseInterface`
  (PDO en prod, `FakeDatabase` en test). Requetes preparees uniquement.
- **Mutations** : CSRF (`Csrf::validate`) + validation serveur bornee (RG-T18) +
  allowlist de colonnes (RG-T16). Sorties HTML echappees (RG-T15).
- **Actions sensibles** : PIN equipier (`PinVerifier`) + `audit_log` dans la meme
  transaction ; throttle PIN (`PinThrottle`). Voir `docs/ARCHITECTURE.md` section 7.
- **Statuts HTTP** : conflit -> 409 ; validation -> 422 ; CSRF/permission -> 403.
- **Pas d'emoji** dans le code, les commits, les specs (Mantra IA-23).

Detail par entite : `docs/merise/` et `docs/domaines/` (a venir).

---

## 6. Git & CI

- **Conventional Commits** (anglais) : `type(scope): description` — types `feat`, `fix`,
  `docs`, `refactor`, `test`, `chore`, `ci`, `db`, `perf`, `style`.
- **Branches** depuis `dev` : `feat/*`, `fix/*`, `docs/*`, `chore/*`, `ci/*`, `db/*`,
  `refactor/*`, `test/*`. Merge vers `dev` par **PR squashee**. Periodiquement
  `dev -> main` avec tag semver.
- **Auto-merge** : l'ouverture de la PR programme la fusion squash automatique des que
  les checks requis passent (auto-merge NATIF Forgejo `merge_when_checks_succeed`, sans
  label ni job CI). Script : `scripts/forgejo-pr-automerge.sh`.
- **Pas de trailer `Co-Authored-By`** : la transparence sur l'usage de l'IA vit dans le
  `README.md` et `docs/PROJECT_CONTEXT.md` section 17.

---

## 7. Ou trouver quoi

| Besoin | Emplacement |
|---|---|
| Architecture, stack, securite, modele | `docs/ARCHITECTURE.md` |
| Scope metier, planning, mapping RNCP | `docs/PROJECT_CONTEXT.md` |
| Modelisation Merise (dictionnaire, MCD/MCT/MLT, regles RG-T*) | `docs/merise/` |
| Decisions d'architecture (le pourquoi) | `docs/adr/` |
| Retros par session / feature | `docs/journal/` |
| Methodologie agents | `.claude/CLAUDE.md` + `.claude/rules/` |

---

*Document vivant — mis a jour au fil de l'implementation.*
