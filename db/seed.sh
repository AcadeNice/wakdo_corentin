#!/usr/bin/env bash
#
# Wakdo - seed runner.
#
# Applique les fichiers db/seeds/*.sql dans l'ordre lexicographique, de maniere
# idempotente : une table seed_history enregistre les fichiers deja charges.
# Les seeds doivent etre joues APRES les migrations (les tables doivent exister).
#
# Cible : le service docker-compose `wakdo-db`. Identifiants lus dans .env.
#
# Usage :
#   bash db/seed.sh            # charge les seeds en attente
#   bash db/seed.sh --status   # liste l'etat sans rien charger
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT/.env"
CONTAINER="${WAKDO_DB_CONTAINER:-wakdo-db}"
SEEDS_DIR="$ROOT/db/seeds"

[ -f "$ENV_FILE" ] || { echo "ERREUR : .env introuvable ($ENV_FILE)" >&2; exit 1; }
DB_NAME="$(grep -E '^DB_NAME=' "$ENV_FILE" | cut -d= -f2- | tr -d '[:space:]')"
DB_ROOT_PASSWORD="$(grep -E '^DB_ROOT_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)"
: "${DB_NAME:?DB_NAME absent de .env}"
: "${DB_ROOT_PASSWORD:?DB_ROOT_PASSWORD absent de .env}"

db() { docker exec -i "$CONTAINER" mariadb -uroot -p"$DB_ROOT_PASSWORD" "$@"; }

docker exec "$CONTAINER" true 2>/dev/null || { echo "ERREUR : conteneur $CONTAINER non demarre (make up)" >&2; exit 1; }

if [ ! -d "$SEEDS_DIR" ]; then
  echo "[seed] aucun repertoire db/seeds/ - rien a charger"
  exit 0
fi

db "$DB_NAME" -e "CREATE TABLE IF NOT EXISTS seed_history (
  filename   VARCHAR(255) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"

shopt -s nullglob
files=("$SEEDS_DIR"/*.sql)
[ ${#files[@]} -gt 0 ] || { echo "[seed] aucun fichier seed dans $SEEDS_DIR"; exit 0; }

if [ "${1:-}" = "--status" ]; then
  echo "[seed] etat des seeds (base $DB_NAME) :"
  for f in "${files[@]}"; do
    base="$(basename "$f")"
    n="$(db "$DB_NAME" -N -s -e "SELECT COUNT(*) FROM seed_history WHERE filename='$base';")"
    [ "$n" = "0" ] && echo "  PENDING  $base" || echo "  loaded   $base"
  done
  exit 0
fi

loaded=0
for f in "${files[@]}"; do
  base="$(basename "$f")"
  n="$(db "$DB_NAME" -N -s -e "SELECT COUNT(*) FROM seed_history WHERE filename='$base';")"
  if [ "$n" = "0" ]; then
    echo "[seed] chargement de $base ..."
    db "$DB_NAME" < "$f"
    db "$DB_NAME" -e "INSERT INTO seed_history (filename) VALUES ('$base');"
    loaded=$((loaded + 1))
  else
    echo "[seed] $base deja charge, ignore"
  fi
done
echo "[seed] termine ($loaded nouveau(x) seed(s) charge(s))."
