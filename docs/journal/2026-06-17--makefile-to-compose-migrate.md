# 2026-06-17 — Du Makefile a `docker compose up` (service wakdo-migrate)

**Auteur : BYAN.** Remplacement de l'orchestration locale par Makefile par un service
compose one-shot. Objectif : que `docker compose up` amene a lui seul une stack
complete et utilisable, et retirer un Makefile devenu en partie trompeur.

## Pourquoi ce changement

### Le declencheur : le Makefile mentait en partie
Un audit du Makefile (24 cibles) a montre trois categories :
- **Cibles mortes / trompeuses** : `test`, `test-unit`, `test-integration`, `lint`
  affichaient *« Pas encore implemente … en P2 »* alors que les tests EXISTENT et
  tournent (PHPUnit via `.phar`, 263 tests unit + 301/916 en integration ; PHPStan
  L6 ; tests JS node:test+jsdom). `install-hooks` referencait un `.githooks/` et un
  `scripts/install-hooks.sh` absents. Une cible qui annonce un faux est pire qu'une
  cible absente.
- **Wrappers fins** : `up/down/logs/shell/...` = une ligne au-dessus de
  `docker compose`, valeur surtout de decouvrabilite (`make help`).
- **Une seule cible reellement porteuse** : `init` (build -> up -> wait-db ->
  migrate), citee comme la preuve du critere RNCP **Cr 7.c.4** (*« lancer la stack
  complete avec une seule ligne de commande »*).

### Le point cle : Cr 7.c.4 parle d'un RESULTAT, pas de `make`
Le critere exige *une commande -> stack complete*. Il ne mentionne pas `make` ;
`make init` n'etait qu'un choix d'implementation. Or `docker compose up` seul ne
suffisait pas : il demarre les conteneurs mais **n'applique pas les migrations**
(base vide -> stack non « complete »). C'etait l'unique raison d'etre de `make init`.

En deplacant migration + seed DANS la stack (un service one-shot qui tourne au
boot), c'est `docker compose up` LUI-MEME qui amene la stack complete. Avantages :
- **Commande universelle** : `docker compose up`, sans dependance a l'outil `make`
  sur l'hote (un correcteur n'a pas a installer/connaitre `make`).
- **Comportement = documentation** : l'ancien `make init` ne faisait meme PAS le
  seed (il s'arretait a `migrate`), alors que le README annoncait « migrate + seed ».
  Le nouveau chemin seed pour de vrai, donc la stack est *loginnable* (admin present)
  en une commande.
- **Plus idiomatique** : faire porter l'init par la couche d'orchestration (compose)
  plutot que par un outil hote externe.

## Ce qui a ete fait

- **`db/migrate-container.sh`** : runner in-container. Applique `db/migrations/*.sql`
  (suivi `schema_migrations`) PUIS `db/seeds/*.sql` (suivi `seeds_applied`), de
  maniere idempotente, en se connectant a la base par le reseau compose (DB_HOST).
  Distinct de `db/migrate.sh` (hote, via `docker exec`), conserve pour l'usage manuel
  (`--status`) et la CI.
- **Service `wakdo-migrate`** (image mariadb, `restart: "no"`) : `depends_on`
  `wakdo-db: service_healthy`, lance le runner puis sort. `wakdo-app` et `wakdo-web`
  gagnent `depends_on wakdo-migrate: service_completed_successfully` -> ils ne servent
  qu'une fois le schema + le seed en place.
- **Makefile supprime.** Les commandes equivalentes en clair :
  `docker compose up -d` (= ex-`make init`/`up`), `docker compose down` (`make down`),
  `docker compose down -v` (`make clean`), `docker compose build --no-cache && up -d`
  (`make rebuild`), `docker compose logs -f` (`make logs`),
  `docker compose exec wakdo-db mariadb -uroot -p"$DB_ROOT_PASSWORD"` (`make shell-db`).
  Tests : `docker run --rm -v "$PWD":/app -w /app wakdo-wakdo-app php phpunit.phar -c phpunit.xml`
  (cf. README / SESSION_RESUME).

## Verification

Base MariaDB ephemere et vierge (pour ne pas toucher la dev) :
- Run #1 : 2 migrations + 2 seeds appliques.
- Run #2 : 0 nouveau (idempotent, tout ignore).
- Donnees : 5 roles, 23 permissions, admin `admin@wakdo.local` present ;
  `schema_migrations`=2, `seeds_applied`=2.

`docker compose -p wakdo config` valide. La CI n'utilise pas `make` (0 appel) :
elle garde sa propre boucle migrate -> non impactee.

## Mapping Cr 7.c.4 (apres ce changement)
*« Le fichier de configuration permet de lancer la stack applicative complete avec
une seule ligne de commande »* -> **`docker compose up`** : `docker-compose.yml`
decrit la stack, le service `wakdo-migrate` applique schema + donnees, app/web
attendent sa completion. Une commande, aucune dependance hote.

## Note de deploiement (environnements deja seedes)
Sur une base existante deja migree ET seedee AVANT l'introduction du suivi
(`seeds_applied` absente), le premier `docker compose up` avec le nouveau service
tenterait de rejouer les seeds (INSERT non idempotents) -> conflits d'unicite. Pour
ces environnements : back-fill une fois la table de suivi
(`CREATE TABLE seeds_applied(...)` + INSERT des noms de fichiers seed deja appliques,
idem `schema_migrations` si besoin) AVANT le premier up. Les deploiements sur volume
VIERGE ne sont pas concernes (le service applique tout proprement, comme verifie).

## Compromis assumes
- migrations + seeds evalues a CHAQUE `up` : cout negligeable (le suivi rend les
  re-runs sans effet).
- `wakdo-migrate` se connecte en root (DDL + INSERT de reference), comme `migrate.sh`.
