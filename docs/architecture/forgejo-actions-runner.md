# Forgejo Actions - runner (act_runner)

Prerequis d'infrastructure pour la CI/CD Wakdo. Les workflows vivent dans
`.forgejo/workflows/` (lot D) ; ils ne s'executent que si un `act_runner` est
enregistre et en ligne sur le serveur.

## Pourquoi un runner separe de la stack app

La stack `docker-compose.yml` de Wakdo = runtime applicatif (web, app, db, cron).
Le runner CI est du **tooling** : il se rattache au depot Forgejo, pas a l'app.
On le fait tourner comme service dedie sur l'hote stark (meme lecon que
"gh dans Docker = mauvaise idee", cf. journal session 6). Cela evite que la CI
puisse impacter le runtime, et garde un cycle de vie independant.

## 1. Obtenir le token de registration (action manuelle, niveau admin)

Le token vient de l'instance Forgejo, pas du repo. Dans l'UI Forgejo :

- niveau **repo** : `Settings > Actions > Runners > Create new runner`
- ou niveau **org/instance** : `Site Administration > Actions > Runners`

Recuperer le `REGISTRATION_TOKEN` affiche. Il est a usage unique pour
l'enregistrement (pas a versionner).

## 2. Enregistrer le runner (sur stark)

Setup reel en place (image `simplyforma/forgejo-runner` deja presente sur
l'hote, data dir sous `$HOME` car `/srv` non inscriptible par `corentin`).
Le conteneur tourne sous l'uid de l'hote (`--user`) pour pouvoir ecrire
`.runner` dans le volume monte.

```bash
DATA=/home/corentin/forgejo-runner-wakdo
mkdir -p "$DATA"

docker run --rm \
  --user "$(id -u):$(id -g)" \
  -v "$DATA":/data --workdir /data \
  --entrypoint forgejo-runner \
  simplyforma/forgejo-runner:12.10.2 \
  register --no-interactive \
    --instance https://git.acadenice.com \
    --token "<REGISTRATION_TOKEN>" \
    --name stark-wakdo \
    --labels 'docker:docker://node:20-bookworm,php-ci:docker://php:8.3-cli'
```

L'enregistrement ecrit `$DATA/.runner` (contient le secret du runner - ne pas
versionner, ne pas sortir de l'hote). Runner enregistre le 2026-06-15
(uuid `e4a3dbef-...`, labels `docker` + `php-ci`).

## 3. Lancer le runner en service

```bash
DATA=/home/corentin/forgejo-runner-wakdo
DOCKER_GID=$(stat -c '%g' /var/run/docker.sock)

docker run -d --restart=always \
  --name forgejo-runner-wakdo \
  --user "$(id -u):$(id -g)" \
  --group-add "$DOCKER_GID" \
  -e HOME=/data \
  -v "$DATA":/data --workdir /data \
  -v /var/run/docker.sock:/var/run/docker.sock \
  --entrypoint forgejo-runner \
  simplyforma/forgejo-runner:12.10.2 \
  daemon
```

Notes :
- `--group-add $DOCKER_GID` : acces au socket Docker pour executer les jobs
  dans des conteneurs (sans tourner en root).
- `-e HOME=/data` : evite l'erreur `mkdir /.cache: permission denied` (le cache
  server interne ecrit sous `$HOME`).
- Verifier `docker logs forgejo-runner-wakdo` : `declared successfully` +
  `[poller] launched`, et `Settings > Actions > Runners` doit montrer `stark-wakdo` **Idle**.
- Prerequis cote depot : **Actions activees** (`Settings > Actions` du depot).

## 4. Labels et usage en workflow

Les jobs ciblent un label via `runs-on`. Pour la CI PHP de Wakdo :

```yaml
jobs:
  ci:
    runs-on: docker          # image par defaut node:20-bookworm
    # les etapes installent/php via le conteneur ou une action setup-php
```

## Securite du runner

- Le `.runner` (secret) reste sur l'hote, hors du repo.
- Le socket Docker monte donne un acces privilegie : le runner ne doit executer
  que des workflows du depot Wakdo (runner dedie au repo, pas partage).
- Roter le secret = re-enregistrer avec un nouveau token et supprimer l'ancien
  runner dans l'UI.

## Lien avec les autres lots

- **Lot C** : ce document + prerequis infra.
- **Lot D** : `.forgejo/workflows/ci.yml` (PHPUnit + PHPStan + secret-scan gitleaks)
  et auto-merge des PR sur CI verte (strategie solo dev validee).
