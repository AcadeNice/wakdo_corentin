# Deploiement continu (CD) — Wakdo

Ce document decrit le deploiement automatique vers la production et la mise en place
a faire une seule fois cote serveur. Il complete `scripts/deploy.sh` et
`.forgejo/workflows/deploy.yml`.

## Topologie

| Hote | Role |
|---|---|
| **Thanos** (`git.acadenice.com`) | Forge : depot Git + Forgejo Actions |
| **Stark** | Environnement de dev ; heberge le runner Forgejo |
| **Vision** | Production : la stack Wakdo y tourne, cible du deploiement |

Le runner (sur Stark) n'a pas acces au socket Docker, par choix de securite : un job
CI ne peut pas piloter Docker sur son hote. Le deploiement vers Vision se fait donc
par SSH — ce qui correspond au schema normal d'un deploiement vers un hote distant.

## Flux

```
merge dev -> main           (release, deja passee par la CI sur la PR)
        │
        ▼
Forgejo Actions: workflow Deploy (.forgejo/workflows/deploy.yml)
        │  ssh deploy@vision   (sans commande : forced command cote Vision)
        ▼
Vision: scripts/deploy.sh   (git ff-only -> VERSION + deploy.log -> compose build/up)
        │
        ▼
GET /api/health renvoie le nouveau SHA  ← preuve du deploiement
```

## Ce qui est automatise (dans le depot)

- `.forgejo/workflows/deploy.yml` : sur push `main`, ouvre la session SSH vers Vision.
- `scripts/deploy.sh` : recupere `main` (fast-forward), ecrit le marqueur de version
  (`src/VERSION`) et une ligne dans `deploy.log`, reconstruit et recree la stack.
  Mode non-interactif via `DEPLOY_YES=1`.
- `GET /api/health` expose `version` (SHA) et `deployed_at` (date), lus depuis
  `src/VERSION`.

## Mise en place cote Vision (une fois)

Prerequis : Docker + docker compose, le depot clone (ex. `/srv/wakdo`), un `.env` de
prod renseigne et un `docker-compose.prod.yml` propre a l'hote.

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
# wakdo-deploy.pub  -> cle PUBLIQUE (authorized_keys de Vision, etape 3)

ssh-keyscan -t ed25519 <hote-vision>   # -> contenu du secret DEPLOY_KNOWN_HOSTS
```

## Secrets et variables a creer sur la forge

Depot -> Settings -> Actions -> Secrets / Variables :

| Type | Nom | Valeur |
|---|---|---|
| Secret | `DEPLOY_SSH_KEY` | contenu de la cle privee `wakdo-deploy` |
| Secret | `DEPLOY_KNOWN_HOSTS` | sortie de `ssh-keyscan` (cle d'hote de Vision) |
| Secret | `DEPLOY_HOST` | nom/IP de Vision |
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
   Le `version` correspond au HEAD de `main` apres la release — preuve que Vision a ete
   mise a jour sans intervention manuelle.

## Notes de securite

- Cle SSH dediee au seul deploiement, **forced command** + options `no-*` qui retirent
  shell, tunnels et forwarding.
- Cle d'hote **epinglee** (`DEPLOY_KNOWN_HOSTS`, `StrictHostKeyChecking=yes`) : pas de
  confiance a la premiere connexion.
- Secrets stockes cote forge, hors du depot. `.env` et `docker-compose.prod.yml`
  restent gitignores.
- Le runner n'a pas le socket Docker : un job ne peut pas agir sur Docker localement.
