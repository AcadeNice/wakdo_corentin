#!/usr/bin/env bash
#
# Wakdo - migration runner.
#
# Applique les fichiers db/migrations/*.sql dans l'ordre lexicographique,
# de maniere idempotente : une table schema_migrations enregistre les fichiers
# deja appliques, donc relancer ne rejoue que les nouvelles migrations.
#
# Cible : le service docker-compose `wakdo-db` (MariaDB). Lance depuis l'hote
# (usage manuel / `--status`, identifiants lus dans .env). Au boot de la stack,
# c'est le service `wakdo-migrate` (db/migrate-container.sh, via le reseau) qui
# applique migrations + seed automatiquement.
#
# Usage :
#   bash db/migrate.sh            # applique les migrations en attente
#   bash db/migrate.sh --status   # liste l'etat sans rien appliquer
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT/.env"
CONTAINER="${WAKDO_DB_CONTAINER:-wakdo-db}"
MIGRATIONS_DIR="$ROOT/db/migrations"

[ -f "$ENV_FILE" ] || { echo "ERREUR : .env introuvable ($ENV_FILE)" >&2; exit 1; }
DB_NAME="$(grep -E '^DB_NAME=' "$ENV_FILE" | cut -d= -f2- | tr -d '[:space:]')"
DB_ROOT_PASSWORD="$(grep -E '^DB_ROOT_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)"
: "${DB_NAME:?DB_NAME absent de .env}"
: "${DB_ROOT_PASSWORD:?DB_ROOT_PASSWORD absent de .env}"

# Client mariadb dans le conteneur (root : les migrations sont des operations DDL).
db() { docker exec -i "$CONTAINER" mariadb -uroot -p"$DB_ROOT_PASSWORD" "$@"; }

# Le conteneur doit etre en marche.
docker exec "$CONTAINER" true 2>/dev/null || { echo "ERREUR : conteneur $CONTAINER non demarre (docker compose up -d)" >&2; exit 1; }

# Journal des migrations appliquees.
db "$DB_NAME" -e "CREATE TABLE IF NOT EXISTS schema_migrations (
  filename   VARCHAR(255) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"

shopt -s nullglob
files=("$MIGRATIONS_DIR"/*.sql)
[ ${#files[@]} -gt 0 ] || { echo "[migrate] aucune migration dans $MIGRATIONS_DIR"; exit 0; }

if [ "${1:-}" = "--status" ]; then
  echo "[migrate] etat des migrations (base $DB_NAME) :"
  for f in "${files[@]}"; do
    base="$(basename "$f")"
    n="$(db "$DB_NAME" -N -s -e "SELECT COUNT(*) FROM schema_migrations WHERE filename='$base';")"
    [ "$n" = "0" ] && echo "  PENDING  $base" || echo "  applied  $base"
  done
  exit 0
fi

applied=0
for f in "${files[@]}"; do
  base="$(basename "$f")"
  n="$(db "$DB_NAME" -N -s -e "SELECT COUNT(*) FROM schema_migrations WHERE filename='$base';")"
  if [ "$n" = "0" ]; then
    echo "[migrate] application de $base ..."
    db "$DB_NAME" < "$f"
    db "$DB_NAME" -e "INSERT INTO schema_migrations (filename) VALUES ('$base');"
    applied=$((applied + 1))
  else
    echo "[migrate] $base deja applique, ignore"
  fi
done
echo "[migrate] termine ($applied nouvelle(s) migration(s) appliquee(s))."
