# Remise a zero des donnees de demo (soutenance)

La production de Wakdo sert de site de demonstration public : le jury de
soutenance se connecte avec le compte admin du seed, qui est donc un compte
connu de n'importe qui. Entre deux visites, n'importe qui peut abimer les
donnees : supprimer un produit, changer un prix, changer le mot de passe admin,
creer de fausses commandes, desactiver un compte...

Deux scripts couvrent ce besoin, dans `scripts/` :

| Script | Role |
|---|---|
| `scripts/demo-snapshot.sh` | Fige un **instantane de reference** de l'etat courant (donnees + images uploads) |
| `scripts/demo-reset.sh` | Ecrase les donnees courantes par le dernier instantane de reference, avec sauvegarde de securite prealable et verification |

Point important : la remise a zero ne restaure PAS le jeu de seed d'origine.
Elle restaure le dernier instantane que l'utilisateur a explicitement fige avec
`demo-snapshot.sh` — qui peut differer du seed (catalogue ajuste, mot de passe
demo choisi, produits ajoutes...). C'est cet etat-la qui fait foi.

Il n'existe pas de script `demo-restore.sh` distinct : restaurer une sauvegarde
(de securite ou un instantane plus ancien) se fait avec `demo-reset.sh --snapshot
<chemin>`, qui accepte n'importe quel dossier produit par le meme mecanisme de
capture — y compris les sauvegardes de securite que `demo-reset.sh` prend
lui-meme avant chaque ecrasement.

## Quand figer l'instantane (important)

Figer l'instantane **apres le deploiement complet** (toutes les migrations,
0012 a 0014 comprises, appliquees) et **avant toute demonstration** devant le
jury. Ne PAS refaire un instantane apres le passage du jury si des donnees ont
ete modifiees pendant la demo : cela figerait ces donnees abimees comme
nouvelle reference, et `demo-reset.sh` les restaurerait ensuite fidelement — il
restaure l'instantane tel qu'il est, sans juger s'il est "propre". Un nouvel
instantane ne se prend que sur un etat verifie sain.

## La commande a lancer sur stark

Sur l'hote de prod, le fichier compose est `docker-compose.prod.yml` (gitignore,
propre a l'hote — voir `docker-compose.prod.yml.example` pour les noms de
service ; ne pas lire ni executer le fichier reel depuis un autre contexte).
Depuis la racine du depot sur stark :

**La veille ou le matin de l'oral, une fois**, pour figer l'etat que le jury
doit voir :

```bash
cd /home/acadenice/corentin_wakdo
scripts/demo-snapshot.sh -f docker-compose.prod.yml --label "avant oral 2026-10-05"
```

Le script affiche la cible reelle (projet compose + conteneurs) et les
comptages par table de l'instantane fige : verifier que ces informations
correspondent bien a ce qui est attendu avant de continuer.

**Entre deux demonstrations de l'API** (le jury enchaine un PUT puis un DELETE
sur des produits/categories, ce qui laisse la base modifiee) :

```bash
cd /home/acadenice/corentin_wakdo
scripts/demo-reset.sh -f docker-compose.prod.yml --yes
```

`--yes` saute la confirmation tapee (pratique entre deux passages devant le
jury). Lance sans `--yes`, le script demande de taper `RESET` pour confirmer.
Pour rejouer un scenario en verifiant d'abord ce qui va se passer, sans rien
modifier :

```bash
scripts/demo-reset.sh -f docker-compose.prod.yml --dry-run
```

## Prerequis

- La pile est demarree. Verification en lecture seule, sans rien demarrer ni
  recreer :
  ```bash
  docker compose -f docker-compose.prod.yml ps
  ```
  `wakdo-db` et `wakdo-app` doivent y apparaitre `running`/`healthy`. Les deux
  scripts s'appuient uniquement sur `docker compose ... exec` dans ces services
  deja en marche, pas sur `up`/`down`/une recreation : aucun volume n'est
  supprime ni recree, aucun conteneur recree, le reseau `admin_proxy` n'est pas
  touche.
- Un instantane de reference existe (`scripts/demo-snapshot.sh` a deja tourne au
  moins une fois). Sans instantane, `demo-reset.sh` refuse et l'indique.
- Le fichier compose est passe explicitement (`-f` ou variable `COMPOSE_FILE`),
  a chaque lancement : les deux scripts refusent de deviner la pile ciblee, il
  n'y a pas de defaut implicite comme dans `scripts/deploy.sh`.
- Variable `COMPOSE_PROJECT` (optionnelle) : les deux fichiers compose du depot
  declarent tous les deux `name: wakdo`. Sur l'usage normal contre stark, rien a
  faire — un seul projet `wakdo` existe. `COMPOSE_PROJECT` (et `COMPOSE_ENV_FILE`
  pour un `.env` alternatif) ne servent que pour cibler une pile de
  **verification jetable** a project-name distinct (voir plus bas, "identite de
  cible").

## Ce qui est fige / restaure

Un instantane (`demo-snapshots/<horodatage>.<suffixe-unique>/`, nom reserve
atomiquement par `mktemp -d`, hors du code servi, ignore par git, cree avec des
permissions restreintes — dossier et fichiers lisibles par le seul
proprietaire, voir "Permissions" plus bas) contient :

- `db.sql.gz` : dump complet de la base (`mariadb-dump --single-transaction`,
  schema + donnees des 20+ tables : catalogue, categories, menus, recettes,
  ingredients et stock, comptes et roles (dont le mot de passe admin et les PIN
  du seed ou ceux fixes par l'instantane), commandes, journal d'audit
  (`audit_log`), tentatives de connexion (`login_throttle`, `pin_throttle`)) ;
- `migrations.txt` : la liste des migrations appliquees au moment de la capture
  (sert a la verification de compatibilite de schema, voir plus bas) ;
- `counts.txt` : le nombre de lignes par table, pour verifier apres restauration
  que rien n'a diverge (sauf les tables volatiles, voir plus bas) ;
- `uploads.tar.gz` : les images de `src/public/uploads` (volume Docker
  `wakdo_uploads`), presentes seulement si ce dossier contient au moins un
  fichier au moment de la capture — ces images ne sont ni versionnees, ni dans
  les seeds ;
- `meta.txt` : horodatage, label, fichier compose, **projet compose et noms de
  conteneurs resolus a la capture** (sert a la verification d'identite de
  cible, voir plus bas), compteurs.

`demo-reset.sh` restaure ce contenu a l'identique : si l'instantane ne
contenait aucune image, le dossier uploads se retrouve vide apres reset
(fidelite a l'instantane, pas de melange avec un etat intermediaire). Les
sessions PHP actives (`wakdo-app`, stockage fichier par defaut, aucune table
`sessions` a ce jour) sont purgees a chaque reset : une session admin ouverte
avant le reset ne survit pas au reset.

Aucun conteneur, volume ou reseau Docker n'est cree, supprime ou recree (pas de
`up`/`down`/`down -v`) : seul le CONTENU est modifie (donnees en base, fichiers
du volume uploads), par les etapes decrites ci-dessus - c'est precisement leur
role, pas un effet de bord.

## Identite de cible (les deux fichiers compose declarent `name: wakdo`)

`docker-compose.yml` et `docker-compose.prod.yml.example` declarent tous les
deux `name: wakdo` en tete de fichier. Un instantane pris sur une pile et
restaure sur une autre pile portant le meme nom de projet serait une erreur
silencieuse dangereuse (donnees d'un environnement melangees avec un autre).
Pour l'ecarter :

- les deux scripts resolvent la cible REELLE (pas seulement le nom de fichier)
  via `docker inspect` — projet compose (label `com.docker.compose.project`) et
  noms de conteneurs `wakdo-db`/`wakdo-app` — et l'affichent en tete de sortie ;
- `demo-snapshot.sh` enregistre cette identite dans `meta.txt` (`compose_project`,
  `db_container`, `app_container`) ;
- `demo-reset.sh` compare l'identite enregistree dans l'instantane a celle
  resolue au moment du reset, et **refuse** (code 7) si elles divergent, OU si
  la cible actuelle ne peut pas etre resolue du tout alors que l'instantane
  porte une identite (mieux vaut refuser que supposer que "pas d'info = cible
  identique"). Un instantane cree avant cette verification (sans ces champs
  du tout) n'est pas bloque : rien n'est enregistre a comparer, un
  avertissement est affiche a la place du refus.

Sur l'usage normal contre stark, cette identite est stable (un seul projet
`wakdo`) : le refus ne se declenche que si `-f`/`COMPOSE_PROJECT` pointe par
erreur vers une autre pile que celle sur laquelle l'instantane a ete pris.

## Permissions (les dumps contiennent des secrets)

`db.sql.gz` contient les hash de mots de passe (argon2id) et les PIN des
comptes de demo. Les deux scripts posent `umask 077` avant de creer quoi que ce
soit : tout fichier/dossier cree est lisible par son seul proprietaire (mode
700/600). Ils appliquent aussi `chmod 700` sur `demo-snapshots/` et
`demo-backups/` s'ils existent deja, pour couvrir un lancement anterieur a
cette regle. Sur un hote a plusieurs comptes dans le meme groupe Unix (ex. le
groupe `devlopper` de stark), ceci empeche un autre membre du groupe de lire
ces dumps par un simple `cat`.

## Protocole execute par `demo-reset.sh`

L'ordre ci-dessous est l'ordre REEL d'execution : les memes libelles `[n/6]`
apparaissent a l'identique en mode normal et dans la sortie de `--dry-run`
(qui s'arrete apres l'etape 1, en affichant le reste du plan sans l'executer).

1. **`[1/6]` Verification de compatibilite : instantane + cible + schema.**
   Lecture seule integralement, executee dans les deux modes (dry-run compris),
   AVANT toute destruction :
   - l'instantane est bien forme (les 4 fichiers attendus sont presents) ET ses
     deux archives sont verifiees ICI — pas plus tard, pas seulement au moment
     ou on s'en sert : une archive vide ou illisible doit etre detectee avant
     que la base n'ait ete ecrasee, pas apres.
     - le **dump** `db.sql.gz` : taille non nulle, flux gzip intact (`gzip -t`),
       et derniere ligne `-- Dump completed` (un dump tronque puis recompresse
       passerait `gzip -t`). C'est l'artefact critique : sans ce controle, un
       `db.sql.gz` tronque — copie ou transfert interrompu, disque plein —
       traversait l'etape 1 (`--dry-run` annoncait meme « compatible »), puis
       cassait en plein milieu de l'etape 4, `DROP`/`CREATE` deja joues et base
       laissee dans un etat intermediaire incoherent ;
     - l'**archive uploads** `uploads.tar.gz`, si l'instantane est cense en
       contenir une : taille non nulle, lisible par `tar tzf` ;
   - l'identite de cible (voir plus haut) - refus (code 7) si divergente, ou si
     la cible actuelle n'a pas pu etre resolue alors que l'instantane porte une
     identite ;
   - les migrations appliquees dans la base courante (`schema_migrations`)
     comparees a celles de l'instantane (`migrations.txt`) : si une migration a
     ete appliquee depuis la prise de l'instantane (nouveau deploiement
     entre-temps), le script refuse plutot que de restaurer un schema perime
     sans le signaler — il faut relancer `demo-snapshot.sh` avant de reset (en
     respectant la regle "quand figer l'instantane" plus haut : uniquement sur
     un etat sain). Symetriquement, si l'instantane contient une migration
     absente de la base courante (code deploie plus ancien que l'instantane
     choisi), il refuse aussi.
2. **`[2/6]` Confirmation tapee `RESET`**, sautee avec `--yes`.
3. **`[3/6]` Sauvegarde de securite** de l'etat courant (avant tout
   ecrasement), au meme format qu'un instantane, sous un dossier au nom
   UNIQUE (`mktemp -d`) dans `demo-backups/` a la racine du depot (ignore par
   git) : deux resets qui tomberaient sur le meme horodatage a la seconde ne
   peuvent pas se disputer le meme dossier. La capture verifie elle-meme
   chaque etape (dump, comptages, uploads) : si l'une d'elles echoue ou parait
   suspecte (dump trop petit, integrite gzip invalide, derniere ligne du dump
   differente de `-- Dump completed`, nombre de `CREATE TABLE` different du
   nombre de tables vivantes, archive uploads vide ou illisible, wakdo-app
   injoignable pour compter les images...), le script abandonne entierement :
   rien n'est ecrase sans sauvegarde valide au prealable, et le dossier
   reserve par `mktemp -d` pour cette tentative precise (pas une sauvegarde
   preexistante, grace au nom unique) est supprime.
4. **`[4/6]` Restauration des donnees** : le dump complet de l'instantane est
   rejoue (DROP puis CREATE de chaque table, puis reinsertion des lignes — la
   base elle-meme n'est ni supprimee ni recreee). Les comptages par table sont
   pris IMMEDIATEMENT apres, avant les etapes suivantes (voir "Tables ecrites
   par la borne sans authentification" plus bas).
5. **`[5/6]` Images uploads** : les images sont restaurees depuis
   `uploads.tar.gz` (deja verifiee a l'etape 1) si l'instantane en contient ;
   si l'instantane n'en contient pas, le dossier est vide.
6. **`[6/6]` Invalidation des sessions PHP actives, puis verification** : les
   comptages pris a l'etape 4 sont compares a `counts.txt` de l'instantane (et
   au nombre de fichiers uploads) — a l'exception des tables a fort trafic
   `login_throttle`, `audit_log`, `pin_throttle`, exclues de la comparaison
   stricte (voir plus bas). Le script sort en erreur si un comptage diverge
   sur une table non exclue.

A partir de l'etape 4, une erreur peut laisser la pile dans un etat
INTERMEDIAIRE INCOHERENT (destruction commencee, restauration pas terminee) :
chaque message d'echec qui suit ce point rappelle explicitement la commande de
retour arriere exacte vers la sauvegarde de securite de l'etape 3 - y compris
une sortie totalement inattendue (signal, coupure de la base en plein milieu)
qui ne serait pas passee par un des messages d'erreur explicites : un filet de
dernier recours l'affiche quand meme avant que le script ne se termine.

## Tables ecrites par la borne sans authentification

La borne kiosk ecrit dans `customer_order`, `order_item` et `stock_movement`
sans authentification (n'importe qui devant la borne peut passer une
commande) : une commande peut donc arriver PENDANT une capture (instantane ou
sauvegarde de securite), avec deux consequences distinctes traitees chacune a
sa maniere :

- **A la capture** : le dump (`mariadb-dump --single-transaction`) fixe sa
  propre vue coherente au debut du dump, mais le comptage par table
  (`counts.txt`) est une requete SEPAREE, sur une connexion distincte, sans
  cette vue partagee. Pour que `counts.txt` decrive fidelement ce que contient
  reellement le dump, la capture compte les tables AVANT et APRES le dump : si
  les deux comptages different SUR UNE TABLE NON VOLATILE (une commande de
  borne est arrivee entre-temps sur `customer_order`/`order_item`/
  `stock_movement`), la capture recommence (dump + comptages) jusqu'a 3
  tentatives, puis abandonne si le trafic ne s'arrete pas. Un ecart limite aux
  tables volatiles elles-memes (par exemple une connexion admin qui alimente
  `login_throttle` pendant la capture de la sauvegarde de securite) ne
  declenche pas de nouvelle tentative : ces tables ne sont pas comparees
  strictement au moment du reset non plus (voir plus bas).
- **A la restauration** : les comptages post-restauration sont pris
  IMMEDIATEMENT apres l'ecriture des donnees (etape `[4/6]`, avant les etapes
  suivantes), pour minimiser la fenetre pendant laquelle une commande reelle
  pourrait s'intercaler avant la verification. Cette fenetre reste neanmoins
  possible en theorie (une commande peut encore arriver entre la fin de la
  restauration et l'instant precis du comptage) : `login_throttle`,
  `audit_log` et `pin_throttle` (alimentees en continu par l'application et le
  cron, `docker/cron/scripts/purge-throttle.sh`/`purge-audit-log.sh`, en plus du
  trafic de la borne) restent donc EXCLUES de la comparaison stricte — un ecart
  post-restauration sur ces trois tables specifiquement ne signale pas une
  restauration ratee. La liste est visible dans le script
  (`DEMO_VOLATILE_TABLES`, `scripts/lib/demo-snapshot-lib.sh`) et rappelee dans
  la sortie de l'etape `[6/6]`.

## Une seule operation a la fois

`demo-snapshot.sh` ET `demo-reset.sh` prennent le MEME verrou exclusif (`flock`
sur `.demo-reset.lock` a la racine du depot) avant de commencer : une capture
ou un reset lisent l'etat de wakdo-db/wakdo-app a un instant donne, une
deuxieme operation concurrente lirait un etat en train de changer. Le PID du
detenteur est ecrit dans le fichier de verrou ; un second lancement pendant
qu'une operation est deja en cours est refuse immediatement (il ne se met pas
en attente), avec ce PID dans le message.

## En cas d'echec

Le message de retour arriere (ci-dessous) reprend automatiquement
`COMPOSE_PROJECT`/`COMPOSE_ENV_FILE` s'ils etaient definis pour le reset qui a
echoue : la commande affichee fonctionne telle quelle, sans avoir a se
souvenir de ces variables.

- **Cible differente de celle de l'instantane, ou cible non resolue alors que
  l'instantane porte une identite** (etape 1, code 7) : verifier
  `-f`/`--compose-file` et `COMPOSE_PROJECT` — l'instantane vise n'a pas ete
  pris sur la pile actuellement ciblee (ou la pile actuelle n'a pas pu etre
  identifiee).
- **Instantane invalide : fichier manquant, dump `db.sql.gz` tronque/corrompu,
  ou archive uploads vide/illisible** (etape 1, code 2) : detecte avant toute
  destruction (y compris en `--dry-run`) ; reprendre un instantane valide ou
  relancer `demo-snapshot.sh`.
- **Schema incompatible** (etape 1, code 3) : relancer `scripts/demo-snapshot.sh
  -f docker-compose.prod.yml` pour figer un instantane a jour (sur un etat
  sain, voir "Quand figer l'instantane"), puis reset.
- **La sauvegarde de securite echoue** (etape 3, code 4) : le script s'arrete
  avant toute restauration, la base n'est pas touchee. Regarder le message
  d'erreur (mysqldump/mariadb-dump), diagnostiquer en lecture seule :
  ```bash
  docker compose -f docker-compose.prod.yml ps
  ```
- **La restauration echoue en cours de route, ou une sortie inattendue survient
  apres le debut de la destruction** (etapes 4/5/6, code 5) : le message
  rappelle que la pile peut etre dans un etat intermediaire incoherent, et
  affiche la commande exacte de retour arriere vers la sauvegarde de
  l'etape 3 (par exemple) :
  ```bash
  scripts/demo-reset.sh -f docker-compose.prod.yml --snapshot demo-backups/<horodatage-unique>_pre-reset.XXXXXX --yes
  ```
- **La verification post-restauration signale un ecart** (etape 6, code 6) :
  meme rappel et meme commande de retour arriere que ci-dessus, meme si les
  etapes precedentes se sont deroulees sans erreur apparente.
- **Le retour arriere lui-meme echoue parce que `schema_migrations` a disparu**
  (restauration interrompue en plein milieu du DROP/CREATE, table de suivi
  comprise) : le mecanisme de `demo-reset.sh` compare `schema_migrations` a
  l'instantane a l'etape 1, donc un schema disparu le bloque lui aussi. Dans ce
  cas extreme, restaurer directement via le mecanisme du cron, independant de
  ce mecanisme de suivi. Utiliser `exec` sur le service `wakdo-cron` DEJA
  demarre, pas `run --rm` : `run` demarre ses dependances declarees
  (`wakdo-db`) si elles ne tournent pas, et peut aussi la recreer si le `.env`
  a change depuis son dernier demarrage — `exec` ne fait qu'executer une
  commande dans un conteneur existant, sans toucher a ses dependances :
  ```bash
  docker compose -f docker-compose.prod.yml exec -T wakdo-cron \
      /scripts/restore-db.sh /backups/wakdo_<horodatage>.sql.gz --force
  ```
  (le montage `/backups` du service `wakdo-cron` est deja celui de
  `./var/backups` sur l'hote, voir `docker-compose.prod.yml.example` ; voir
  aussi "Lien avec la sauvegarde quotidienne" plus bas ; ce chemin restaure le
  dernier dump nocturne, pas necessairement l'instantane de demo le plus
  recent).
- **"Une autre operation demo-snapshot.sh/demo-reset.sh est deja en cours"**
  (code 1) : le message indique le PID du detenteur ; attendre sa fin (affichee
  dans sa propre sortie) avant de relancer.

## Lien avec la sauvegarde quotidienne (`wakdo-cron`)

Le service `wakdo-cron` fait, independamment de tout ce qui precede, un dump
complet de la base chaque nuit a 03h00 (`docker/cron/scripts/backup-db.sh`),
conserve 14 jours dans `./var/backups` sur l'hote. C'est un filet de reprise
apres sinistre (perte de disque, corruption), pas un outil de gestion de la
demo : il ne connait pas la notion d'« instantane de reference » et ne
declenche aucune restauration automatique. `docker/cron/scripts/restore-db.sh`
permet de restaurer un de ces dumps a la main si besoin, independamment de
`demo-reset.sh`.

Les deux mecanismes sont complementaires et n'ecrivent pas au meme endroit :
`demo-snapshot.sh`/`demo-reset.sh` ecrivent sous `demo-snapshots/` et
`demo-backups/` (racine du depot), le cron ecrit sous `var/backups/` — deux
dossiers et deux formats de nommage distincts.

## Limites connues

- L'invalidation de session supprime les fichiers `sess_*` du conteneur
  `wakdo-app` (`session.save_path` par defaut, `/tmp` du conteneur — voir
  `docker/php-fpm/php.ini`). Si un stockage de session different est introduit
  plus tard (base de donnees, Redis...), cette etape devra etre adaptee.
- Les trois tables `login_throttle`, `audit_log`, `pin_throttle` sont exclues
  de la verification stricte post-restauration (voir plus haut) : un ecart sur
  ces tables specifiquement n'est pas signale, meme s'il traduirait un vrai
  probleme ailleurs.
- La duree d'un reset depend de la taille reelle des donnees de prod (mesuree
  a environ 1-2 secondes sur un jeu de donnees de taille seed sur une pile
  jetable de verification ; a mesurer sur stark au premier usage reel, le
  script affiche systematiquement la duree ecoulee, y compris sur un echec).
- Le script cible les services `wakdo-db` et `wakdo-app` par leur nom de
  service (`docker compose exec`), pas par nom de conteneur : il fonctionne
  donc avec n'importe quel fichier compose declarant ces deux services, y
  compris une pile de verification jetable a project-name different (avec
  `COMPOSE_PROJECT`/`COMPOSE_ENV_FILE`) — la verification d'identite de cible
  (voir plus haut) empeche de melanger les deux par erreur.
