#!/usr/bin/env bash
#
# Wakdo - runner migrations + seed IN-CONTAINER (service compose one-shot wakdo-migrate).
#
# Applique, dans l'ordre lexicographique et de maniere IDEMPOTENTE :
#   1. db/migrations/*.sql  (suivi : table schema_migrations)
#   2. db/seeds/*.sql        (suivi : table seeds_applied)
# Relancer ne rejoue que les fichiers en attente (tracking par nom de fichier).
#
# Contrairement a db/migrate.sh (hote, via `docker exec`), ce runner tourne DANS
# un conteneur et se connecte a la base PAR LE RESEAU compose (DB_HOST). Il est
# lance par le service `wakdo-migrate` apres que `wakdo-db` soit healthy ; les
# services applicatifs (app/web) attendent sa COMPLETION (service_completed_successfully).
#
# But : `docker compose up` amene une stack COMPLETE et utilisable (schema + donnees
# de reference, dont l'admin bootstrap) en une seule commande, sans dependance a
# l'hote (Cr 7.c.4) -> remplace `make init`.
#
# Variables injectees par docker-compose : DB_HOST, DB_PORT, DB_NAME, DB_ROOT_PASSWORD.
# Root requis : migrations = DDL, seeds = INSERT de reference.
#
set -euo pipefail

: "${DB_HOST:?DB_HOST manquant}"
: "${DB_NAME:?DB_NAME manquant}"
: "${DB_ROOT_PASSWORD:?DB_ROOT_PASSWORD manquant}"
PORT="${DB_PORT:-3306}"

db() { mariadb -h "$DB_HOST" -P "$PORT" -uroot -p"$DB_ROOT_PASSWORD" "$@"; }

# Applique les *.sql d'un dossier non encore enregistres dans sa table de suivi.
apply_tracked() {
    local dir="$1" table="$2"
    local f base n applied=0

    db "$DB_NAME" -e "CREATE TABLE IF NOT EXISTS ${table} (
        filename   VARCHAR(255) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"

    shopt -s nullglob
    local files=("$dir"/*.sql)
    if [ ${#files[@]} -eq 0 ]; then
        echo "[${table}] aucun fichier dans ${dir}"
        return 0
    fi

    for f in "${files[@]}"; do
        base="$(basename "$f")"
        n="$(db "$DB_NAME" -N -s -e "SELECT COUNT(*) FROM ${table} WHERE filename='${base}';")"
        if [ "$n" = "0" ]; then
            echo "[${table}] application de ${base} ..."
            db "$DB_NAME" < "$f"
            db "$DB_NAME" -e "INSERT INTO ${table} (filename) VALUES ('${base}');"
            applied=$((applied + 1))
        else
            echo "[${table}] ${base} deja applique, ignore"
        fi
    done
    echo "[${table}] termine (${applied} nouveau(x))."
}

echo "[migrate] cible ${DB_HOST}:${PORT}/${DB_NAME}"
apply_tracked /db/migrations schema_migrations
apply_tracked /db/seeds seeds_applied
echo "[migrate] stack a jour (schema + donnees de reference)."
