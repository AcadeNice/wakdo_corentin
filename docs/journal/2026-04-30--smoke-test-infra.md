# Smoke test infra Docker — passage prod-ready

**Date** : 2026-04-30
**Branche** : `feat/infra-docker`
**PR** : a creer (suite de la session)
**Duree estimee** : 2h30

---

## Ce qui a ete fait

Validation bout-en-bout de la stack Docker livree en Session 3 (`ac8b6a6`), sur le serveur de deploiement reel.

1. **Fusion `.env`** : le fichier existant ne contenait que les vars `BYAN_API_*` (outil tiers, lit ce fichier). Fusion avec le template `.env.example` Wakdo dans un seul `.env` (gitignore), sans deplacer les vars BYAN. Mots de passe DB generes via `openssl rand -base64 32`.
2. **Switch FQDN** : `corentin-wakdo.acadenice.fr` -> `corentin-wakdo.stark.a3n.fr` (idem admin). Modifie dans `README.md`, `docs/PROJECT_CONTEXT.md`, `.env`. Commit `4edabf2`.
3. **Smoke test `make init`** : echec puis 3 corrections successives, finalement OK avec 4 conteneurs healthy. Commit `d9890cf`.
4. **Validation HTTPS externe** via curl : cert Let's Encrypt provisionne automatiquement par Traefik sur les 2 FQDN, isolation `/healthz` confirmee (non expose publiquement).

---

## Pourquoi — decisions et alternatives

### Decision 1 : Switch FQDN sur `*.stark.a3n.fr` plutot qu'ajout de records sur `acadenice.fr`

- **Decision retenue** : utiliser le wildcard DNS existant sur `*.stark.a3n.fr` (deja configure pour ce serveur).
- **Alternatives** : ajouter 2 records A `corentin-wakdo` et `corentin-wakdo-admin` dans la zone `acadenice.fr`.
- **Raison** : la zone `acadenice.fr` n'a pas de wildcard, son apex pointe vers un autre serveur (`195.15.210.22`), et Traefik d'ici utilise un challenge HTTP-01 (pas DNS-01), donc le FQDN cible doit resoudre vers cet hote *avant* de pouvoir provisionner un cert. Le wildcard `*.stark.a3n.fr` (verifie a coup de `dig` sur des sous-domaines aleatoires : `foo-test-9999.stark.a3n.fr` resout vers `62.210.93.152`) supprime cette etape. Cout : la documentation projet ne reflete plus la branding `acadenice.fr` cible, mais c'est defendable a l'oral comme "sous-domaine d'infrastructure dev sur l'hote stark, branding final restera flexible cote DNS public quand le projet sortira".

### Decision 2 : Subnet explicite `192.168.148.0/24` sur `wakdo_internal`

- **Decision retenue** : declarer un subnet IPAM fixe dans `docker-compose.yml` au lieu de laisser Docker auto-allouer.
- **Alternatives** :
  - `docker network prune` pour liberer 3 reseaux orphelins (~2 /20 + 1 /24)
  - Etendre `default-address-pools` dans `/etc/docker/daemon.json` + restart Docker daemon
  - Subnet en `10.x` plutot que `192.168.x`
- **Raison** : sur cet hote mutualise, l'auto-allocateur Docker echoue (`all predefined address pools have been fully subnetted`) parce que les 15 `/16` du pool par defaut (`172.17.0.0/16` a `172.31.0.0/16`) et 13 sur 16 `/20` du `192.168.0.0/16` sont deja pris par d'autres stacks. Un prune liberait l'instant t mais le probleme reviendrait. Restart du daemon = blast radius eleve (les autres apps de l'hote coupees pendant ~30s). Subnet explicite = deterministe, defendable, isole Wakdo des fluctuations d'allocation auto. Choix de `192.168.148.0/24` : milieu du gap libre `192.168.144-159`, hors collision avec les `/24` acquagest voisins (150, 154, 155, 157), `/24` = 254 IP = right-sized pour 4 services (RFC 1918 `192.168.0.0/16`, classes A/B/C deprecated par CIDR/RFC 1519 depuis 1993).

### Decision 3 : `init: true` sur `wakdo-cron` (au lieu d'installer tini dans le Dockerfile)

- **Decision retenue** : ajouter `init: true` au service `wakdo-cron` dans `docker-compose.yml`, qui declenche l'injection automatique de `tini` par Docker comme PID 1.
- **Alternative** : installer `tini` dans le Dockerfile cron et prefixer le `CMD` par `/sbin/tini --` (comme c'est fait pour `wakdo-app`).
- **Raison** : symptome rencontre = `dcron` boucle sur `setpgid: Operation not permitted` apres demarrage. Cause = un processus tournant en PID 1 dans un namespace PID Linux sans init parent ne peut souvent pas changer son groupe de processus pour ses enfants forkes (limite kernel sur `setpgid()` quand le pere est PID 1). `dcron` exige cette capacite pour isoler chaque job dans son propre process group. La solution canonique = un init reaper (tini, dumb-init, s6-overlay). `init: true` est l'option Docker Compose qui demande au runtime d'injecter automatiquement un init minimal — moins de code que de modifier le Dockerfile, semantique declarative. Trade-off : le wakdo-app utilise son propre `tini` installe explicitement (heritage Session 3) — incoherence stylistique a accepter ou unifier plus tard.

### Decision 4 : healthz servi en fichier statique depuis `/usr/local/apache2/htdocs/`

- **Decision retenue** : `Alias /healthz /usr/local/apache2/htdocs/healthz.txt` + un fichier `healthz.txt` (3 octets, `OK\n`) embarque dans l'image Apache.
- **Alternatives** :
  - `RewriteRule ^/healthz$ - [R=200,L]` (config initiale).
  - `Alias /healthz /var/www/html/public/healthz.txt` (premier essai du fix).
- **Raison** : la directive `R=200` declenche le mecanisme `ErrorDocument` interne d'Apache, qui rend un template HTML generique meme pour un statut 200 — d'ou le body parasite `"The server encountered an internal error or misconfiguration..."` au milieu d'une reponse `200 OK`. Servir un vrai fichier supprime ce comportement. Le fichier doit vivre HORS du chemin bind-monte (`./src` -> `/var/www/html`) qui ecrase le contenu de l'image au runtime, sinon le `COPY` est masque. `/usr/local/apache2/htdocs/` est un chemin Apache natif que le compose Wakdo ne bind-monte pas, donc adapte aux artefacts d'infrastructure.

---

## Comment — points techniques cles

### Saturation des pools d'adresses Docker

Docker daemon alloue les subnets bridge depuis une liste configurable `default-address-pools`. Les valeurs par defaut (cf. [Docker engine source](https://github.com/moby/moby/blob/master/libnetwork/ipamutils/utils.go)) :

```
172.17.0.0/16 -> 172.31.0.0/16   (15 pools de /16)
192.168.0.0/16 carve en /20      (16 pools de /20)
```

Sur cet hote : 15/15 `/16` pris, 13/16 `/20` pris dans `192.168`. Quand `docker network create` (sans `--subnet`) ne trouve aucun bloc contigu disponible, le daemon retourne :

```
Error: all predefined address pools have been fully subnetted
```

C'est un constat operationnel : sur un hote partage qui heberge >30 stacks, declarer ses subnets explicitement est plus une discipline qu'un nice-to-have. La RFC 1918 donne `10.0.0.0/8` (16M IP) presque vide ici, et `192.168.144.0/20` (4096 IP) en gap dans le second pool. Le choix `192.168.148.0/24` reste dans la convention `192.168.x` familiere tout en evitant la collision.

### `setpgid()` et init dans un container PID namespace

Le kernel Linux accorde `setpgid(pid, pgid)` au processus appelant pour changer le pgid de ses enfants, sous conditions (cf. `man 2 setpgid`) :
- l'enfant doit etre dans la meme session
- la cible `pgid` doit appartenir a une session existante
- l'appelant doit avoir des droits sur l'enfant

Quand un processus est PID 1 dans un namespace PID (cas du conteneur sans init explicite), il devient le process group leader d'office et ne peut pas se sortir de son propre PG facilement. `dcron` essaie de mettre chaque job dans un PG isole pour pouvoir l'attacher proprement et envoyer des signaux groupes — il echoue, le job exit 1, `restart: unless-stopped` relance, boucle. L'injection de `tini` (via `init: true` ou `--init`) place tini en PID 1 et `dcron` en PID 2+, donc `dcron` peut faire ses `setpgid()` sans souci.

### Bind-mount masque le COPY de l'image

Comportement Docker : un bind-mount sur un chemin destination ecrase entierement le contenu de l'image a ce chemin. Erreur classique : on `COPY config.txt /var/www/config.txt` dans le Dockerfile, puis on bind-mount `./src:/var/www`, et `config.txt` disparait au runtime. Le COPY a bien lieu *au build*, mais le bind-mount au *run* prend la priorite. C'est ce qui s'est passe pour `healthz.txt` au premier fix.

Solution propre : placer les artefacts d'infrastructure (healthchecks, scripts internes) dans un chemin que le compose ne bind-monte pas (`/usr/local/apache2/htdocs/`, `/opt/app/`, etc.), et reserver `/var/www/html/` au code applicatif bind-monte en dev.

### Validation cert Let's Encrypt sans config DNS prealable

Le wildcard DNS `*.stark.a3n.fr` resout deja `corentin-wakdo.stark.a3n.fr` et `corentin-wakdo-admin.stark.a3n.fr` vers `62.210.93.152`. Au premier hit HTTPS externe, Traefik :

1. Voit le label `traefik.http.routers.wakdo-kiosk.rule=Host(...)` et le `certResolver=letsencrypt`
2. Lance le challenge HTTP-01 : LE leur envoie un GET `/.well-known/acme-challenge/<token>` en HTTP
3. Le FQDN resout vers cet hote -> reponse correcte servie par Traefik (route specifique sur l'entrypoint `web`)
4. LE valide, emet le cert, Traefik le stocke dans `/acme.json` et le sert sur `:443`

Le tout est invisible : pas de config a faire cote Wakdo. La seule contrainte = le FQDN resout vers l'hote *avant* le premier hit. C'etait le bloqueur derriere le switch FQDN en Decision 1.

---

## Criteres RNCP couverts

- **Cr 7.a.1** *("Le candidat a bien analyse les contraintes en termes d'infrastructure et de securite")* : decisions Subnet et FQDN documentent une analyse explicite de l'environnement reel (hote mutualise, pools satures, wildcard disponible, Traefik partage). Pas de copie-colle d'un tutorial generique.
- **Cr 7.a.2** *("...justifie ses choix")* : 4 decisions argumentees avec alternatives evaluees ci-dessus.
- **Cr 7.b.3** *("Mise en place d'un planificateur de tache, type cron tab")* : `wakdo-cron` operationnel (verifie : crontab parse OK, `0 3 * * * backup-db.sh` actif, `init: true` resout les bouclages). Impl detail : `docker/cron/Dockerfile`, `docker/cron/crontab`.
- **Cr 7.c.4** *("...mise en place d'une procedure de deploiement automatisee")* : `make init` deploie l'integralite de la stack en une commande, idempotent, avec checks prealables (.env present, network existant, vars critiques renseignees). Echec cli net si pre-requis manquant.
- **Cr 1.e.4** *("Le candidat a respecte les bonnes pratiques de cloisonnement reseau")* : verifie via curl externe que `/healthz` renvoie 403 quand requis avec un Host applicatif, donc le vhost healthz est bien isole des vhosts publics. Pas de fuite d'observabilite vers l'exterieur dans ce setup.
- **Cr 5.b** *("Journalisation et tracabilite")* : les services redirigent stdout/stderr vers `docker logs` (Apache `ErrorLog /proc/self/fd/2`, php-fpm via tini, dcron `-d 8`, mariadb defaut). `docker compose -p wakdo logs` suffit pour audit.

---

## Questions anticipees du jury

- **Q** : *"Pourquoi avoir fusionne le `.env` BYAN avec celui du projet ? C'est pas du couplage ?"*
  **R** : C'est une dette assumee. L'outil BYAN lit `.env` du dossier de travail ; je n'ai pas creuse comment changer ce chemin sans risquer de casser sa liaison avec son API. La separation en deux fichiers (`docker compose --env-file .env.wakdo`) etait l'option propre, mais cout de modifier le Makefile + risque de regression sur l'outil tiers. J'ai choisi la fusion en sachant que `.env` est gitignore (donc le couplage ne pollue pas le repo public) et documente la raison dans le `.env` lui-meme.

- **Q** : *"Pourquoi `192.168.148.0/24` exactement et pas `10.99.0.0/24` ?"*
  **R** : Choix arbitre par convention de l'ecosysteme local. Les autres stacks de l'hote utilisent souvent `172.x` ou `192.168.x` ; rester dans la meme famille facilite le mental model des admins qui suivront. `10.x` aurait ete plus "datacenter-pro" mais introduit une plage absente des autres stacks de cet hote — visibilite cognitive moindre. C'est un choix de coherence locale, pas une regle universelle.

- **Q** : *"Le redirect HTTP -> HTTPS retourne 404. Est-ce normal ?"*
  **R** : Non. Mes labels Traefik definissent bien un router `wakdo-kiosk-http` sur l'entrypoint `web` avec middleware `redirectscheme`. Le 404 vient probablement d'une interaction avec le middleware global du Traefik d'hote (`redirect-except-acqua@file` declare dans `traefik.toml`) qui s'applique avant et ne match pas mes routers. Ce point est documente comme "a investiguer" dans le SESSION_RESUME ; en pratique l'usage final sera du HTTPS direct via les liens jury, donc non bloquant pour la demo.

- **Q** : *"Comment je sais que `make init` va marcher chez quelqu'un d'autre ?"*
  **R** : Honnetement : il ne marchera pas sans adaptation. Le `.env.example` pointe sur des FQDN neutres (`example.com`, `traefik_proxy`) qu'il faut adapter, et la cible `make init` echoue cleanly avec un message d'aide si le reseau Traefik n'existe pas ou si `.env` manque des vars. Le smoke test ici a valide la chaine sur l'hote stark precis ; un autre hote demanderait probablement un autre subnet (ou un retour a l'auto-alloc si le daemon n'est pas sature).

- **Q** : *"Pourquoi `init: true` plutot qu'installer tini comme dans wakdo-app ?"*
  **R** : Difference de timing. Pour `wakdo-app`, Session 3 a pose tini explicitement parce que c'etait la maniere "showcase" — montrer que je sais l'installer. Pour `wakdo-cron`, c'est apparu en correctif au smoke test ; `init: true` est plus court (1 ligne vs Dockerfile + ENTRYPOINT) et atteint le meme resultat car Docker injecte un init binaire compatible (en realite `docker-init`, qui est tini repackage). Coherence stylistique a faire dans une iteration ulterieure si jugee importante.

---

## Points d'amelioration conscients

- **Redirect HTTP -> HTTPS** : retourne 404 au lieu de 301. Sera traite dans une branche `feat/infra-polish` separee, pas avant validation que ce n'est pas un faux probleme (l'usage demo se fera via lien HTTPS direct).
- **Coherence init** : `wakdo-app` installe tini explicitement, `wakdo-cron` utilise `init: true`. Acceptable en l'etat (les deux marchent), a unifier en passant l'un ou l'autre style si on touche au compose.
- **`docker network prune` pas execute** : 3 reseaux orphelins detectes (`traefik-net`, `crm-chirurgien-network`, `acquagest-prod_acquaprocess_staging`) consomment des subnets sans usage. Pas mon role d'effacer des reseaux que je n'ai pas crees ; signaler a l'admin de l'hote.
- **Pas de DNS-01 + wildcard cert** : Traefik utilise HTTP-01 par FQDN. DNS-01 + wildcard ferait un seul cert pour `*.stark.a3n.fr` et reduirait les renouvellements LE. Mais c'est une optim de l'infra hote, hors scope projet examen.
- **`./src/` vide** : les FQDN repondent 403 sur `/`. Resolution prevue Phase P2 (creation des stubs `public/borne/index.html` et `public/admin/index.php`).

---

## Liens vers artefacts

- **Commits** :
  - `4edabf2` — `docs: switch project FQDN from acadenice.fr to stark.a3n.fr`
  - `d9890cf` — `chore(docker): smoke test fixes for stack startup and healthz`
- **Fichiers principaux modifies** :
  - `docker-compose.yml` (subnet IPAM + `init: true` cron)
  - `docker/apache/Dockerfile` (COPY healthz vers htdocs)
  - `docker/apache/vhost.conf` (Alias healthz)
  - `docker/apache/healthz.txt` (nouveau)
  - `README.md`, `docs/PROJECT_CONTEXT.md` (FQDN switch)
- **Documentation associee** :
  - `docs/journal/2026-04-24--infra-docker.md` (session precedente, decisions architecturales)
  - `docs/notes/docker-volumes-vs-bind-mounts.md` (note technique perso, complement)
