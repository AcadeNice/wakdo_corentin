# Base de donnees - migrations & seeds

Transcription executable du MLD (`docs/merise/mld.md`, 21 tables) vers MariaDB 11.4.

## Arborescence

```
db/
  migrations/   migrations SQL versionnees, appliquees dans l'ordre lexicographique
    0001_init_schema.sql   schema initial : 21 tables, FK, CHECK, index (InnoDB, utf8mb4)
  seeds/        donnees de demonstration (a venir : roles/permissions, allergenes, catalogue)
  migrate.sh    runner de migrations (idempotent)
```

## Appliquer les migrations

```bash
bash db/migrate.sh            # applique les migrations en attente
bash db/migrate.sh --status   # liste l'etat sans rien appliquer
```

Le runner cible le conteneur `wakdo-db` et lit les identifiants dans `.env`
(`DB_NAME`, `DB_ROOT_PASSWORD`). Il maintient une table `schema_migrations`
(une ligne par fichier applique) : relancer ne rejoue que les nouvelles
migrations. La cible `bash db/migrate.sh` est destinee a appeler ce script.

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
