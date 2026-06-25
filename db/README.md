# Base de donnees - migrations & seeds

Transcription executable du MLD (`docs/merise/mld.md`, 21 tables) vers MariaDB 11.4.

## Arborescence

```
db/
  migrations/          migrations SQL versionnees, appliquees dans l'ordre lexicographique
    0001_init_schema.sql   schema initial : 21 tables, FK, CHECK, index (InnoDB, utf8mb4)
  seeds/               donnees de reference (RBAC, allergenes, catalogue, variantes)
  migrate-container.sh runner de boot IN-CONTAINER (canonique, service wakdo-migrate)
  migrate.sh           runner de migrations cote HOTE (manuel, via docker exec)
  seed.sh              runner de seeds cote HOTE (manuel, via docker exec)
```

## Numerotation des migrations (trou 0004 assume)

Les migrations sautent `0004` : la sequence est `0001, 0002, 0003, 0005, 0006,
0007`. Ce n'est PAS un fichier manquant mais un desalignement historique assume :
le numero `0004` a ete consomme cote `seeds/` (`0004_menu_side_maxi.sql`) lors
d'un meme increment de travail, sans contrepartie cote `migrations/`. Le suivi se
fait par NOM DE FICHIER (`schema_migrations`), pas par numero contigu : le trou
est donc inoffensif (rien ne presuppose une sequence sans lacune). Convention
conservee : ne pas reattribuer `0004` cote migrations pour eviter toute confusion
avec le seed homonyme ; la prochaine migration prend le numero suivant disponible.

## Appliquer les migrations + seeds

Chemin canonique (boot de la stack) : le service one-shot `wakdo-migrate`
(`docker compose up`) execute `db/migrate-container.sh`, qui applique
`db/migrations/*.sql` (suivi : table `schema_migrations`) PUIS `db/seeds/*.sql`
(suivi : table `seeds_applied`), de maniere idempotente. Il se connecte a
`wakdo-db` par le reseau compose.

Chemin manuel (hote, via `docker exec`) :

```bash
bash db/migrate.sh            # applique les migrations en attente
bash db/migrate.sh --status   # liste l'etat des migrations sans rien appliquer
bash db/seed.sh               # charge les seeds en attente
bash db/seed.sh --status      # liste l'etat des seeds sans rien charger
```

Les runners hote ciblent le conteneur `wakdo-db` et lisent les identifiants dans
`.env` (`DB_NAME`, `DB_ROOT_PASSWORD`).

### Suivi partage entre les deux chemins

Les runners hote et conteneur partagent les MEMES tables de suivi (memes noms,
memes colonnes `filename` / `applied_at`) : `schema_migrations` pour les
migrations, `seeds_applied` pour les seeds. Consequence : rejouer un chemin apres
l'autre ne replaye RIEN. (Auparavant `db/seed.sh` suivait une table distincte
`seed_history`, ce qui pouvait lui faire re-jouer des seeds deja charges par
`wakdo-migrate` et echouer sur une contrainte UNIQUE — corrige.)

## Conventions

- Une migration = un fichier `NNNN_description.sql`. Un fichier deja applique en
  commun n'est plus edite : on ajoute une nouvelle migration pour corriger.
- Pas de `CREATE DATABASE` / `USE` dans les fichiers : la base cible est choisie
  par le runner.
- Le schema suit le MLD v0.2 a la lettre : montants en centimes (INT UNSIGNED),
  `vat_rate` en pour-mille, `service_day` NON materialise (calcule applicatif,
  decision D6), stock signe (survente), journaux append-only (`stock_movement`,
  `audit_log`).
- Verification : le DDL a ete applique sur une instance MariaDB 11.4 reelle
  (21 tables, 28 FK, 22 CHECK) sans erreur avant integration.
