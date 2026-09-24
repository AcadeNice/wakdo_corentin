# Deploiement continu (CD) — Wakdo

**Version** : v0.3 (2026-09-24) — mise en coherence avec le code livre (2a09597) : l'hote unique est nomme stark partout (le texte parlait aussi de "Vision") ; l'hote est joint a une adresse explicite, et non par la route par defaut du conteneur de job ; `DEPLOY_HOST` est une variable de la forge, pas un secret ; le workflow compte deux jobs ; le deploiement n'attend pas le resultat de la CI sur `main`.

Ce document decrit le deploiement automatique vers la production et la mise en place
a faire une seule fois cote serveur. Il complete `scripts/deploy.sh` et
`.forgejo/workflows/deploy.yml`.

## Topologie

| Hote | Role |
|---|---|
| **Thanos** (`git.acadenice.com`) | Forge : depot Git + Forgejo Actions |
| **Stark** | Hote UNIQUE : il porte a la fois le runner d'integration et la production Wakdo |

Un seul serveur, pas deux. La production (`wakdo-web`, `wakdo-app`, `wakdo-db`,
`wakdo-cron`) et le runner d'integration tournent sur la meme machine.

Le privilege Docker est reparti en deux niveaux, et c'est la clef du dispositif.
Le CONTENEUR DU RUNNER a le socket Docker : il en a besoin pour lancer les
conteneurs de job. Les CONTENEURS DE JOB, eux, ne l'ont pas. Mesure du 2026-09-22,
par quatre jobs de sonde poussees sur la forge : telecharger le client Docker
passe, mais `test -S /var/run/docker.sock` echoue dans le job, le demon ne repond
pas, et lancer un conteneur frere echoue. Un job d'integration est donc non
privilegie.

C'est voulu, et c'est ce qui justifie le detour : donner le socket aux jobs
reviendrait a accorder les pleins pouvoirs sur la machine de production a tout
code passant en integration. Le job DEMANDE donc a l'hote de se deployer, par un
canal qui ne peut lancer qu'une seule commande (commande forcee cote hote). La
cle de deploiement ne permet rien d'autre : ni shell, ni copie de fichier, ni
redirection de port.

## Flux

```
merge dev -> main           (release, deja passee par la CI sur la PR)
        │
        ▼
Forgejo Actions: workflow Deploy (.forgejo/workflows/deploy.yml)
        │  ssh <user>@<hote>   (sans commande : la commande forcee decide)
        │  job 1 cle-de-deploiement : la cle du secret est-elle celle autorisee ?
        │  job 2 deploiement : ssh vers l'adresse EXPLICITE de l'hote (variable
        │  DEPLOY_HOST, sinon valeur par defaut de deploy.yml), cle d'hote epinglee
        ▼
Hote: scripts/deploy.sh     (garde arbre propre -> git ff-only -> VERSION +
                             deploy.log -> compose build/up)
        │
        ▼
GET /api/health renvoie le nouveau SHA  ← preuve du deploiement
```

## Ce qui est automatise (dans le depot)

- `.forgejo/workflows/deploy.yml` : sur push `main` ou lancement a la demande
  (`workflow_dispatch`), deux jobs. `cle-de-deploiement` compare la partie publique de la
  cle du secret a celle autorisee sur l'hote et echoue vite sinon ; `deploiement`
  (`needs: cle-de-deploiement`) ouvre la session vers l'hote, puis VERIFIE que
  `/api/health` sert bien le commit deploye (24 essais a 5 s d'ecart) avant de passer au vert.
- Ordre avec la CI : `deploy.yml` ne depend pas de `ci.yml`. Une poussee sur `main` lance
  les deux workflows en parallele ; le controle en amont est la demande de fusion
  `dev -> main`, deja testee par la CI.
- `scripts/deploy.sh` : recupere `main` (fast-forward), ecrit le marqueur de version
  (`src/VERSION`) et une ligne dans `deploy.log`, reconstruit et recree la stack.
  Mode non-interactif via `DEPLOY_YES=1`.
- `GET /api/health` expose `version` (SHA) et `deployed_at` (date), lus depuis
  `src/VERSION`.

## Mise en place cote hote stark (une fois)

Prerequis : Docker + docker compose, le depot clone (ex. `/srv/wakdo`).

Le compose et le `.env` de prod ne sont pas versionnes (propres a l'hote) ; ils se
derivent des modeles fournis dans le depot :
```bash
cp docker-compose.prod.yml.example docker-compose.prod.yml
cp .env.prod.example .env       # puis renseigner domaines + mots de passe + reseau Traefik
docker compose -f docker-compose.prod.yml up -d --build
```
Le compose est entierement pilote par le `.env` : le meme fichier marche sur tout hote.

1. Creer un utilisateur dedie au deploiement, membre du groupe `docker` :
   ```bash
   sudo useradd -m -G docker deploy
   ```
2. Lui donner le depot (ou ajuster les droits du clone existant) :
   ```bash
   sudo chown -R deploy:deploy /srv/wakdo
   ```
3. Autoriser la cle CI avec une **forced command** : la cle ne peut lancer que le
   deploiement, aucune autre commande. Dans `~deploy/.ssh/authorized_keys` :
   ```
   command="cd /srv/wakdo && DEPLOY_YES=1 scripts/deploy.sh main",no-pty,no-port-forwarding,no-X11-forwarding,no-agent-forwarding ssh-ed25519 AAAA...CLE_PUBLIQUE... deploy@wakdo-ci
   ```
   `deploy.sh` ne lit pas `$SSH_ORIGINAL_COMMAND` : meme si un appel SSH tentait de
   passer une autre commande, elle serait ignoree.

## Generer la cle et la connaitre cote forge

Sur un poste de confiance :
```bash
ssh-keygen -t ed25519 -f wakdo-deploy -C "deploy@wakdo-ci" -N ""
# wakdo-deploy      -> cle PRIVEE (secret de la forge, ci-dessous)
# wakdo-deploy.pub  -> cle PUBLIQUE (authorized_keys de l'hote, etape 3)

ssh-keyscan -t ed25519 <hote>   # -> contenu du secret DEPLOY_KNOWN_HOSTS
```

## Secrets et variables a creer sur la forge

Depot -> Settings -> Actions -> Secrets / Variables :

| Type | Nom | Valeur |
|---|---|---|
| Secret | `DEPLOY_SSH_KEY` | contenu de la cle privee `wakdo-deploy` |
| Secret | `DEPLOY_KNOWN_HOSTS` | sortie de `ssh-keyscan` (cle d'hote de stark) |
| Variable | `DEPLOY_HOST` | adresse de l'hote ; facultative, `deploy.yml` porte une valeur par defaut |
| Variable | `DEPLOY_HEALTH_URL` | URL de la sonde ; facultative, par defaut celle du back-office de production |
| Variable | `DEPLOY_USER` | `deploy` |

## Verification

1. Faire une release (`dev -> main`).
2. Suivre le workflow **Deploy** dans l'interface de la forge (il se declenche au push
   sur `main`).
3. Interroger la sonde et lire la version deployee :
   ```bash
   curl -s https://<fqdn-admin-prod>/api/health
   # { ... "version": "<sha>", "deployed_at": "<date>" }
   ```
   Le `version` correspond au HEAD de `main` apres la release — preuve que l'hote a ete
   mise a jour sans intervention manuelle.

## Notes de securite

- Cle SSH dediee au seul deploiement, **forced command** + options `no-*` qui retirent
  shell, tunnels et forwarding.
- Cle d'hote **epinglee** (`DEPLOY_KNOWN_HOSTS`, `StrictHostKeyChecking=yes`) : pas de
  confiance a la premiere connexion.
- Secrets stockes cote forge, hors du depot. `.env` et `docker-compose.prod.yml`
  restent gitignores.
- Le runner n'a pas le socket Docker : un job ne peut pas agir sur Docker localement.
