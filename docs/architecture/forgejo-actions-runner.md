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

### Etat mesure le 2026-09-22

Deux constats, verifies sur la machine, a connaitre avant de toucher au runner.

**Le privilege Docker est a deux niveaux.** Le conteneur du runner a le socket
(il en a besoin pour lancer les conteneurs de job). Les conteneurs de job ne
l'ont pas : quatre jobs de sonde poussees sur la forge le montrent — telecharger
le client Docker passe, mais `test -S /var/run/docker.sock` echoue dans le job,
le demon ne repond pas, et lancer un conteneur frere echoue. Un job d'integration
est donc non privilegie. C'est cette separation qui justifie le canal restreint du
deploiement (voir `deployment.md`) : lui donner le socket reviendrait a accorder
les pleins pouvoirs sur la machine de production a tout code passant en integration.

**Les bornes de ressources ne sont pas en vigueur.** Le compose qui definit ce
runner porte `mem_limit: 1g`, `memswap_limit: 1g` et `cpus: 2.0`, poses le
2026-09-16. Le conteneur en marche, lui, a demarre le 2026-09-10 a 14h36 et n'a
jamais ete recree depuis : `docker inspect` renvoie processeur 0 et memoire 0.
Les limites existent sur le papier, pas dans le noyau. Recreer le conteneur les
applique. Tant que ce n'est pas fait, un job peut consommer toute la machine —
qui heberge aussi la production.

**La concurrence n'est pas reglee explicitement.** Aucun `config.yml` n'est
monte : le runner tourne sur ses valeurs par defaut. Mesure du 2026-09-22 sur la
poussee 423 : quatre jobs independants se sont enchaines sans le moindre
chevauchement (09:51:08-14, 09:51:16-19, 09:51:19-22, 09:51:23-31), donc un seul
job a la fois. Le comportement voulu est obtenu, mais par defaut et non par choix
ecrit ; le fixer explicitement vaut mieux que de l'heriter.

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
