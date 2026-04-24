# Infrastructure Docker - stack complete + referentiel RNCP

**Date** : 2026-04-24
**Branche** : `feat/infra-docker`
**PR** : a ouvrir vers `dev` apres merge
**Duree estimee** : ~6h (etalee sur 2 sessions de travail)

---

## Ce qui a ete fait

Travaux regroupes en 3 lots sur la branche `feat/infra-docker`, depuis le commit de cadrage `c044d9b`.

### Lot 1 - Scaffold infra et documentation (commits `c5c6bac` a `32924a5`)

- `README.md` projet (10 849 octets) avec section Methodologie BYAN + 64 Mantras, quickstart "serveur-derriere-Traefik", prerequis, avertissement sur le `.env` pre-existant.
- `.env.example` template neutre (`kiosk.example.com`, `admin.example.com`, `traefik_proxy` - RFC 2606 pour les domaines, aucune info d'infra prod leakee).
- `.dockerignore` pour exclure `node_modules`, `.git`, `docs/notes/`, etc. du contexte de build.
- `Makefile` avec 24 cibles, aide auto-generee (`make help`), cible `check-env` qui detecte les variables critiques manquantes et oriente vers un merge plutot qu'un ecrasement du `.env` existant.
- Structure `docs/journal/` (commit) et `docs/notes/` (gitignore, perso).
- Section 17 du `PROJECT_CONTEXT.md` : "Transparence methodologie et usage d'assistants IA", qui declare l'usage conjoint BYAN + Claude Code, precise le scope (ce que l'IA fait / ne fait pas), et documente la politique "zero trailer `Co-Authored-By`" sur les commits.

### Lot RNCP - Referentiel officiel integre et cross-check (commit `324f5cd`)

- PDF du referentiel officiel RNCP 37805 (Webecom V09-11-22, 20 pages) ajoute dans `docs/_ref/rncp-37805-referentiel.pdf`.
- Index texte compact `docs/_ref/rncp-37805-index.md` : les 24 competences et les ~92 criteres des Blocs 1, 2 et 5 (option DevOps) sont transcrits depuis la source primaire, grep-ables et mis a jour avec leur libelle officiel.
- Cross-check des mappings existants : deux citations de criteres etaient incorrectes dans les documents anterieurs (Cr 4.f.1 et Cr 4.f.4 qualifies d'artefacts Git, alors que la lecture de la source primaire les designe comme des soft skills evaluees a l'oral). Correction appliquee dans ce meme commit, sur `docs/journal/2026-04-23--cadrage-projet.md` et sur la section 8 du `PROJECT_CONTEXT.md`.
- Confirmation que la **RGPD (C3.d, Cr 3.d.1 a 3.d.4) est obligatoire pour valider le Bloc 2**, pas un bonus.

### Lot 2 - Stack Docker complete (commit a venir, voir "Liens vers artefacts")

Arborescence `docker/` creee avec 4 services :

```
docker/
  apache/
    Dockerfile         FROM httpd:2.4-alpine + modules
    httpd.conf         main config durcie (ServerTokens Prod, timeouts)
    mpm.conf           tuning MPM event
    vhost.conf         3 vhosts (healthz + kiosk + admin avec FCGI reverse)
  php-fpm/
    Dockerfile         FROM php:8.3-fpm-alpine3.20 + extensions
    php.ini            display_errors/opcache/session/upload
    www.conf           pool dynamic 3-10 workers, TCP :9000
  cron/
    Dockerfile         FROM alpine:3.20 + dcron + mariadb-client
    crontab            backup 03h00 (14j retention) + templates purge/stats
    scripts/backup-db.sh  mysqldump + gzip + rotation + validations
```

`docker-compose.yml` (9 667 octets) orchestre les 4 services sur 2 reseaux (`wakdo_internal` bridge interne + `reverse_proxy` externe partage avec le Traefik hote), 2 named volumes (`wakdo_db_data`, `wakdo_uploads`) et 1 bind-mount (`./var/backups/`).

`.gitignore` mis a jour pour ignorer `/var/` (contenant `var/backups/`) avec commentaire pointant vers `docs/notes/docker-volumes-vs-bind-mounts.md` pour justifier la strategie.

`Makefile` enrichi de deux cibles : `make backup` (dump SQL manuel horodate via le conteneur cron) et `make backup-ls` (liste les dumps existants).

Validation `docker compose config --quiet` : syntaxe OK, seuls warnings sur les variables d'env non-injectees dans le contexte de test (comportement normal quand `.env` n'est pas complete).

---

## Pourquoi - decisions et alternatives

### Decision 1 : Dockerfile custom pour Apache plutot qu'image officielle + bind-mount de la conf

- **Decision retenue** : Dockerfile custom pour wakdo-web, avec `COPY httpd.conf /usr/local/apache2/conf/httpd.conf`.
- **Alternative consideree** : utiliser directement `image: httpd:2.4-alpine` dans le compose, avec un bind-mount `./docker/apache/httpd.conf:/usr/local/apache2/conf/httpd.conf:ro`.
- **Raison du choix** :
  1. Homogeneite avec les autres services. Sur 4 services, **3** doivent etre custom par necessite (php-fpm pour installer les extensions, cron pour embarquer le crontab, seul `db` restait eligible a une image officielle sans modification). Faire Apache en image officielle + bind-mount aurait cree une exception a justifier dans la documentation.
  2. Image autosuffisante : le resultat du `docker build` contient toute la conf, aucun risque que la conf soit manquante sur le serveur cible si un fichier bind-mounte disparait.
  3. Lisibilite a l'oral : *"j'ai 4 Dockerfiles qui embarquent la conf de leur service"* est plus clair pour le jury que *"3 Dockerfiles et un bind-mount"*.
  4. Cout marginal faible : 2 lignes de Dockerfile, rebuild en ~15 secondes sur une image Alpine + un COPY. Pendant le dev, la conf Apache est touchee rarement une fois le vhost stable.
- **Trace technique** : Cr 7.c.3 du referentiel (*"L'application complete est correctement conteneurisee avec les services et les dependances"*) est renforce par le fait que toute la conf vit dans les images versionnees.

### Decision 2 : Named volumes pour BDD et uploads, bind-mount pour les backups

- **Decision retenue** :
  - MariaDB → `wakdo_db_data` (named volume).
  - Uploads images produits → `wakdo_uploads` (named volume).
  - Backups SQL du cron → `./var/backups/` (bind-mount).
- **Alternative consideree** : tout en bind-mount vers `./data/`, pour avoir une vue hote unifiee.
- **Raison du choix** : MariaDB cree ses fichiers avec UID 999 dans le conteneur, en bind-mount cela donne des fichiers que l'hote voit comme appartenant a un utilisateur inexistant (`rm -rf` impossible sans sudo). Les named volumes laissent Docker gerer cette isolation. Les backups, eux, sont des `.sql.gz` qu'on veut pouvoir inspecter et `scp` hors conteneur : le bind-mount a un vrai benefice de visibilite ici, et le script backup s'execute en root dans le conteneur donc pas de probleme de permissions.
- **Source** : la doc Docker Engine (section "Storage") et la doc officielle MariaDB Docker Hub recommandent explicitement les named volumes pour les BDD conteneurisees.
- **Ressource projet** : `docs/notes/docker-volumes-vs-bind-mounts.md` (note perso) documente en detail la difference entre `docker compose down` et `docker compose down -v`, le piege de permissions UID 999, et la strategie retenue.

### Decision 3 : Un seul conteneur Apache pour les 2 FQDN (kiosk + admin)

- **Decision retenue** : wakdo-web sert les deux hosts via 2 vhosts Apache distincts, et Traefik pose 2 routers sur ce meme conteneur via les labels.
- **Alternative consideree** : deux conteneurs Apache distincts (un par FQDN), chacun avec son reseau et sa conf.
- **Raison du choix** :
  1. Le code source est unique (un seul repo, un seul bind-mount `./src:/var/www/html`), donc deux conteneurs partageraient exactement la meme image - duplication inutile.
  2. L'isolation fonctionnelle est deja assuree au niveau vhost Apache : la directive `<FilesMatch "\.php$"> Require all denied </FilesMatch>` dans le vhost kiosk interdit toute execution PHP cote borne, meme si un fichier `.php` se trouvait dans `public/borne/`.
  3. Ressources : un conteneur Apache consomme ~30 Mo RAM au repos, en doubler serait gaspiller pour un projet RNCP qui tourne sur un VPS modeste.
- **Critere RNCP** : Cr 7.a.1 (*"analyse des contraintes infrastructure et securite"*) : l'isolation est justifiee par la conf applicative, pas par la multiplication des conteneurs.

### Decision 4 : PHP-FPM pool dynamic 3-10 workers, pas de socket Unix

- **Decision retenue** : `listen = 0.0.0.0:9000` (socket TCP), `pm = dynamic`, 3 workers au demarrage jusqu'a 10 au pic.
- **Alternative consideree** : socket Unix (`/var/run/php-fpm.sock`) partage entre conteneurs via volume, reputee offrir un petit gain de performance.
- **Raison du choix du TCP** : partager un socket Unix entre deux conteneurs exige un volume partage qui couple wakdo-web et wakdo-app plus fortement. Le gain de performance d'un Unix socket vs TCP sur localhost est mesure a quelques pourcents dans plusieurs benchmarks publics [CLAIM L4 consensus communaute, a re-checker avant soutenance si la question tombe]. Pour un projet RNCP a trafic modere, la simplicite d'orchestration l'emporte.
- **Raison du choix du pm dynamic 3-10** : un worker PHP-FPM consomme ~30-60 Mo avec les extensions activees. Avec 10 max, le pire cas est ~600 Mo reserve a PHP, ce qui laisse de la marge sur un VPS 2 vCPU / 4 Go RAM pour MariaDB et les autres services.

### Decision 5 : Crontab au format Vixie (dcron) avec retention de 14 jours

- **Decision retenue** : `mariadb:11.4` LTS + conteneur cron Alpine avec `dcron`, backup nocturne 03h00, gzip, rotation 14 dumps.
- **Alternative consideree** : utiliser le cron systeme de l'hote pour declencher `docker exec wakdo-app php /scripts/backup.php`.
- **Raison du choix** : Cr 7.b.3 du referentiel demande explicitement *"planification de taches repetitives (planificateur de tache, cron tab)"* - avoir un conteneur cron distinct materialise clairement cette competence. Le cron hote aurait melange des responsabilites. La retention de 14 jours suit un consensus communaute [CLAIM L4] : suffisant pour detecter un probleme de deploiement + marge de reprise, sans saturer le disque.

---

## Comment - points techniques cles

### Reverse FastCGI Apache -> PHP-FPM

Le coeur du setup est la directive dans `docker/apache/vhost.conf` pour le vhost admin :

```apache
<FilesMatch "\.php$">
    SetHandler "proxy:fcgi://wakdo-app:9000"
</FilesMatch>
```

Apache agit comme un serveur HTTP statique pour les assets (HTML, CSS, JS, images) et relaye toute requete `*.php` vers le pool PHP-FPM via TCP, en utilisant le nom DNS interne `wakdo-app` resolu par le reseau docker `wakdo_internal`. Aucun port 9000 n'est expose a l'hote - seul wakdo-web peut joindre wakdo-app, exactement ce qu'on veut.

Le module `mod_proxy_fcgi` est compile dans l'image `httpd:2.4-alpine` officielle mais pas charge par defaut. On le charge explicitement dans `httpd.conf` :

```apache
LoadModule proxy_module modules/mod_proxy.so
LoadModule proxy_fcgi_module modules/mod_proxy_fcgi.so
```

### Isolation kiosk / admin au niveau vhost

Le vhost kiosk refuse explicitement l'execution PHP :

```apache
<FilesMatch "\.php$">
    Require all denied
</FilesMatch>
```

C'est une defense en profondeur : meme si un fichier `.php` se retrouvait par erreur dans `public/borne/`, il serait servi comme un fichier statique 403, et non execute. Cela materialise la separation Bloc 1 (front vanilla) / Bloc 2 (back PHP) au niveau infra, au-dela de la separation organisationnelle du code source.

### Persistance et strategie de backup

La strategie retenue est la suivante :

- **Named volume `wakdo_db_data`** attache a `/var/lib/mysql`. Survit aux `docker compose down`. Pour une remise a zero, il faut `make clean` (interactif) ou `docker compose down -v` (destructif direct).
- **Conteneur `wakdo-cron`** monte le volume BDD en lecture seule (`wakdo_db_data:/var/lib/mysql:ro`) et peut dumper via `mariadb-client` connecte au reseau interne. Les dumps vont dans `./var/backups/` (bind-mount) pour etre inspectables depuis l'hote.
- Le script `backup-db.sh` valide que le dump fait plus de 512 octets (sanite), compresse en gzip -9, et supprime les dumps plus vieux que 14 jours via `find -mtime +14 -delete`.

### Depend_on avec condition service_healthy

Pour que `make init` soit deterministe, le compose utilise :

```yaml
depends_on:
  wakdo-db:
    condition: service_healthy
```

Cette condition s'appuie sur le healthcheck officiel `healthcheck.sh --connect --innodb_initialized` fourni par l'image MariaDB 11.4. Apache et PHP-FPM ne demarrent qu'une fois la BDD vraiment prete (pas juste "conteneur demarre"). Le Makefile conserve egalement sa cible `wait-db` en ceinture-bretelles avec un timeout de 60s.

---

## Criteres RNCP couverts

Chaque mapping ci-dessous reference le libelle exact transcrit depuis `docs/_ref/rncp-37805-index.md`, lui-meme base sur le PDF officiel.

### Bloc 5 (option DevOps) - tous les criteres touches

- **Cr 7.a.1** : *"Le candidat a bien analyse les contraintes en termes d'infrastructure et de securite"* → documentation dans `README.md`, `PROJECT_CONTEXT.md` sections 5 et 7, et ce journal. Analyse explicite : reseau derriere Traefik existant, TLS gere en amont, pas de binding de ports hote, deux FQDN distincts.
- **Cr 7.a.2** : *"Propose un ensemble de solutions pertinentes pour automatiser tout ou partie du processus"* → `Makefile` (24 cibles, une ligne `make init`), `docker-compose.yml`, conteneur cron.
- **Cr 7.a.3** : *"Interactions avec les activites connexes, autant sur la partie developpement que sur la partie de l'infrastructure"* → la strategie `./src` bind-mount en dev et `COPY` en prod (via override) materialise cette interaction.
- **Cr 7.b.1** : *"Maitrise de la syntaxe d'un langage de script"* → `Makefile` (100+ lignes), `backup-db.sh` (bash strict avec `set -euo pipefail`).
- **Cr 7.b.2** : *"L'automatisation est fonctionnelle et fiabilisee"* → validations dans `backup-db.sh` (variables d'env verifiees, taille min du dump, rotation), exit codes distincts (1, 2, 3).
- **Cr 7.b.3** : *"Planification de taches repetitives (planificateur de tache, cron tab)"* → conteneur `wakdo-cron` avec `dcron` et crontab dedie.
- **Cr 7.c.1** : *"La machine virtuelle creee par le candidat est configuree et operationnelle"* → hebergement Acadenice en VPS (analogue fonctionnel d'une VM) + conteneurs Docker configures.
- **Cr 7.c.2** : *"Le systeme d'exploitation pour conteneur est installe dans la machine d'hebergement virtuelle"* → Docker Engine installe, `docker compose version` disponible.
- **Cr 7.c.3** : *"L'application complete est correctement conteneurisee avec les services et les dependances necessaires"* → 4 services distincts (web, app, db, cron), extensions PHP requises declarees dans le Dockerfile, mariadb-client dans le cron pour le backup.
- **Cr 7.c.4** : *"Le fichier de configuration est renseigne et permet de lancer la stack applicative complete avec une seule ligne commande"* → `make init` exactement, qui fait build + up + wait-db + migrate (futur). C'est litteralement la phrase.
- **Cr 7.d.1** : *"L'architecture serveur est mise en place et fonctionnelle"* → 2 reseaux Docker dont un externe Traefik, 3 volumes, labels Traefik pour 2 routers TLS.
- **Cr 7.d.2** : *"L'application est testee avant deploiement"* → a venir (P7, CI GitHub Actions), reference explicite dans `README.md` section CI/CD.
- **Cr 7.d.3** : *"L'integration et le deploiement continus sont testes et l'application est livree"* → a venir (P7).

### Bloc 2 - criteres deja adresses par l'infra

- **Cr 3.d.4** : *"Les donnees sensibles sont protegees"* → TLS en entree via Traefik, reseau interne isole (aucun port BDD expose a l'hote), mots de passe dans `.env` gitignore. La partie applicative RGPD (Cr 3.d.1 a 3.d.3) est a traiter en P2-P3.
- **Cr 4.e.1** : *"Le programme protege l'integrite des donnees en empechant toute injection d'elements pouvant les compromettre"* → infra prete (PDO dans l'image via `pdo_mysql`), implementation applicative en P2.
- **Cr 4.f.2** : *"L'utilisation de l'outil de travail collaboratif est maitrisee"* → branches `main` et `dev` protegees, flow `feat/*` impose, Conventional Commits, PR obligatoire.

---

## Questions anticipees du jury

- **Q** : *"Pourquoi un conteneur dedie pour le cron plutot qu'un cron systeme sur l'hote ?"*
  **R** : Trois raisons. D'abord, la reproductibilite : le conteneur cron est defini dans le repo et monte partout ou la stack tourne, alors qu'un cron hote exige de configurer chaque serveur manuellement. Ensuite, la trace RNCP : le Cr 7.b.3 demande explicitement un planificateur, avoir un conteneur distinct materialise clairement cette competence. Enfin, l'isolation : les scripts du cron ont leurs propres dependances (mariadb-client, gzip) qui n'ont pas a polluer l'hote.

- **Q** : *"Votre `docker-compose.yml` ne binde aucun port sur l'hote, meme pas pour la BDD - comment vous connectez-vous a MariaDB depuis votre poste pour inspecter ?"*
  **R** : Via `make shell-db` qui ouvre un `mariadb -u root -p` dans le conteneur `wakdo-db`. C'est volontaire : si on bindait le 3306 hote, on exposerait la BDD a tout le reseau local du serveur, alors qu'elle n'a strictement aucune raison d'etre joignable ailleurs que depuis wakdo-app et wakdo-cron. Pour un client graphique (DBeaver, TablePlus) on peut faire un tunnel SSH qui forward le port en local.

- **Q** : *"Qu'est-ce qui se passe si votre Traefik hote tombe ?"*
  **R** : Les services internes continuent de tourner (Apache continue de servir sur son port 80 interne), mais le site devient injoignable publiquement. On peut constater ce cas via `make ps` + `make logs-web`, et en attendant la restauration du Traefik on peut ouvrir temporairement un tunnel SSH pour acceder au vhost directement. C'est un cas de defaillance documente, pas une architecture resiliente au failover - pour un projet pedagogique, le Traefik unique est accepte.

- **Q** : *"Pourquoi MariaDB et pas MySQL ou PostgreSQL ?"*
  **R** : MariaDB parce que 11.4 est LTS jusqu'en 2028, totalement compatible avec le protocole MySQL (donc PDO avec `mysql:` fonctionne), licence libre sans ambiguite Oracle. PostgreSQL aurait ete defendable aussi - j'ai choisi MariaDB pour rester dans la famille la plus courante en formation dev Web et faciliter la collaboration future avec d'autres developpeurs qui connaitraient MySQL.

- **Q** : *"Comment votre stack protege-t-elle les donnees utilisateurs (RGPD Cr 3.d.4) au niveau infrastructure ?"*
  **R** : Trois couches. Primo, TLS en entree par Traefik (Let's Encrypt automatique, pas de HTTP en clair). Secundo, le reseau Docker interne n'est joignable que depuis les autres conteneurs du projet - la BDD ne parle a personne d'autre que wakdo-app et wakdo-cron. Tertio, les mots de passe BDD vivent dans `.env` gitignore (ni dans le repo, ni dans les images), et les backups sont en bind-mount sur un dossier lui aussi gitignore. Pour la partie applicative (hash mots de passe `argon2id`, cookies `HttpOnly`, Secure, SameSite=Strict), c'est deja prepare dans `php.ini` et dans `.env.example`.

- **Q** : *"Votre note `docs/notes/docker-volumes-vs-bind-mounts.md` est tres detaillee. Qui l'a redigee ?"*
  **R** : Claude Code, sur ma demande, au moment ou nous tranchions la strategie de persistance. Je l'ai lue, annotee mentalement, et je peux en defendre chaque decision. Cette repartition des roles est formalisee dans la section 17 du `PROJECT_CONTEXT.md` - l'IA redige les notes de revision, je valide et je defends le contenu.

---

## Points d'amelioration conscients

- **Absence de `src/`** : le bind-mount `./src:/var/www/html` pointe sur un dossier inexistant au moment ou la stack est livree sur la branche `feat/infra-docker`. Au premier `make init`, Apache servira un `DocumentRoot` vide et renverra des 404. C'est normal : la phase P2 cree les stubs `src/public/borne/index.html` et `src/public/admin/index.php`. Je n'ai pas voulu creer des stubs "Hello Wakdo" juste pour passer un smoke-test, parce qu'ils ressembleraient a du code Bloc 1/2 sans en etre, ce qui brouillerait la lecture du commit courant.
- **Pas de `docker-compose.prod.yml` (override)** : l'absence de fichier override prod est assumee pour l'instant. Le `.env` avec `APP_ENV=prod` et `APP_DEBUG=false` suffit en premiere approche, mais un override est prevu en P7 qui remplacera le bind-mount `./src` par un `COPY` pour figer le code dans l'image de prod et qui durcira `display_errors=0` dans `php.ini`.
- **Pas de `apache2-utils` pour htpasswd** : si un jour on veut proteger `/fpm-status` en Basic Auth, il faudra ajouter le package dans le Dockerfile Apache. Non prioritaire tant qu'on n'expose pas ce endpoint publiquement.
- **Backup SQL local uniquement** : les dumps sont sur le meme serveur que la BDD. Un disque qui plante = on perd tout. Une synchronisation hors-site (rsync, rclone) est a prevoir mais hors scope du RNCP courant.
- **Crons templates desactives** : les lignes de purge sessions et d'agregations stats sont commentees dans le `crontab` parce que les tables concernees n'existent pas encore. Elles seront decommentees en meme temps que les migrations P2-P3 qui les creeront.

---

## Liens vers artefacts

- Commits de la branche `feat/infra-docker` (depuis `c044d9b`) :
  - `c5c6bac` - docs: setup journal structure and session 1 retro
  - `f619f81` - docs: add AI usage transparency section to PROJECT_CONTEXT
  - `5dcc5b8` - docs: add README with methodology and server-behind-traefik quickstart
  - `32924a5` - chore(docker): add env template, dockerignore and Makefile scaffold
  - `324f5cd` - docs: add RNCP 37805 referentiel and fix Cr 4.f mappings
  - (a venir) - feat(docker): complete stack with compose and 4 services

- Fichiers principaux :
  - `docker-compose.yml`
  - `docker/apache/{Dockerfile,httpd.conf,vhost.conf,mpm.conf}`
  - `docker/php-fpm/{Dockerfile,php.ini,www.conf}`
  - `docker/cron/{Dockerfile,crontab,scripts/backup-db.sh}`
  - `Makefile`
  - `.env.example`

- Documentation associee :
  - `README.md` - Quickstart et methodologie
  - `docs/PROJECT_CONTEXT.md` - sections 5, 7, 8, 17
  - `docs/_ref/rncp-37805-index.md` - criteres cibles
  - `docs/notes/docker-volumes-vs-bind-mounts.md` - note revision perso (gitignore)
  - `docs/notes/makefile.md` - note revision perso (gitignore)
