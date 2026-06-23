# Strategie de test — Wakdo

> Comment le projet est teste, comment lancer chaque niveau, ce qui tourne en CI,
> comment mesurer la couverture, et pourquoi les tests E2E ne tournent pas en CI.
> Priorite : Unit > Integration > E2E. Cote PHP, aucune dependance Composer
> (PHPUnit en `.phar` autonome).

---

## 1. Niveaux de test

| Niveau | Outil | Perimetre | Ou |
|---|---|---|---|
| Unitaire PHP | PHPUnit (`.phar`) | logique (Auth, RBAC, PIN, throttle, calcul commande, controleurs via doubles) | CI + local |
| Integration PHP | PHPUnit + vraie MariaDB | requetes SQL preparees, contraintes, RBAC `is_active`, audit, FK | CI + local |
| Analyse statique | PHPStan niveau 6 | typage, erreurs potentielles sur `src/` + `tests/` | CI + local |
| Unitaire JS | `node:test` + jsdom | modules du front borne (panier, composeur, checkout, allergenes, a11y, validation) | CI + local |
| E2E | Playwright | parcours borne + admin de bout en bout | **local / manuel** (voir section 5) |

---

## 2. Lancer les tests PHP (sans Composer)

Le binaire `php` n'est pas requis sur l'hote : on passe par l'image applicative.

```bash
# Unitaire + integration (la vraie base est utilisee si WAKDO_DB_TESTS=1) :
docker run --rm -v "$PWD":/app -w /app wakdo-wakdo-app php phpunit.phar -c phpunit.xml

# Integration DB explicite (vraie MariaDB du reseau interne) :
docker run --rm --network wakdo_wakdo_internal --env-file .env -e WAKDO_DB_TESTS=1 \
    -v "$PWD":/app -w /app wakdo-wakdo-app php phpunit.phar -c phpunit.xml

# Analyse statique :
docker run --rm -v "$PWD":/app -w /app wakdo-wakdo-app \
    php -d memory_limit=-1 phpstan.phar analyse --no-progress
```

Les `*.phar` (phpunit 11.5.2, phpstan 1.12.27) sont gitignores ; les retelecharger si absents.
Les tests d'integration s'auto-skippent hors `WAKDO_DB_TESTS=1` ; la CI force `--fail-on-skipped`
pour qu'aucun test de securite (throttle, RBAC, audit, FK) ne soit silencieusement saute.

---

## 3. Lancer les tests JS

```bash
npm run test:js     # node --test tests/js/ (jsdom en devDependency)
```

---

## 4. Couverture de code

Le pilote de couverture (pcov ou Xdebug) n'est **pas** embarque dans l'image de
production (surcout au runtime, sans interet en prod). La couverture se mesure en
dev/CI, avec un PHP equipe d'un pilote de couverture :

```bash
# avec pcov ou xdebug actif dans le PHP utilise :
php -d pcov.enabled=1 phpunit.phar -c phpunit.xml --coverage-text --coverage-html var/coverage
```

`phpunit.xml` declare deja la source a mesurer (`<source><include><directory>src`).
A ce stade, la couverture est produite **a la demande** : aucun seuil n'est impose
en CI (pas de gate de pourcentage). L'ajout d'un pilote de couverture a l'etape CI
`static-tests` et d'un seuil minimal est une evolution identifiee (decision exploitant :
elle suppose un PHP de CI equipe de pcov).

---

## 5. E2E (Playwright) — execution manuelle, hors CI

Les parcours E2E (borne : accueil -> commande -> chevalet -> confirmation ; admin :
login -> dashboard -> logout) se lancent **a la main**, contre une stack jetable :

```bash
tests/e2e/run.sh
```

Le script monte une stack isolee (`docker-compose.yml` + `tests/e2e/docker-compose.e2e.yml`),
attend migrate + healthcheck, puis lance Playwright dans le conteneur officiel.

**Pourquoi pas en CI ?** Decision assumee : le runner Forgejo de production execute les
jobs sans acces au socket Docker (pas de docker-in-docker), et les jobs sont repartis sur
plusieurs runners. Monter une stack Docker complete + Playwright dans ce contexte n'est pas
fiable. Les E2E restent donc un filet **manuel** (lance avant une livraison sensible), tandis
que la CI couvre l'unitaire, l'integration DB, l'analyse statique, le lint et le scan de secrets.
A l'oral, c'est la position a defendre : E2E reels et reproductibles, mais declenches a la main.

---

## 6. Ce que la CI execute (Forgejo Actions, sur PR)

`.forgejo/workflows/ci.yml`, sur `pull_request` vers `dev`/`main` :

| Job | Verifie |
|---|---|
| `secret-scan` | gitleaks (aucun secret dans le diff/historique) |
| `php-lint` | `php -l` sur tous les fichiers `.php` |
| `static-tests` | PHPStan niveau 6 + PHPUnit (unit + integration sur service MariaDB, `--fail-on-skipped`) |
| `js-tests` | `node --test tests/js/` (jsdom) |

L'auto-merge ne se declenche que lorsque ces checks requis sont verts (branch protection).
