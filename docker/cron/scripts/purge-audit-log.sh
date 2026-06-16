#!/usr/bin/env bash
#
# Wakdo - purge de retention du journal d'audit (mlt.md 13.4).
#
# Supprime les lignes audit_log plus anciennes que AUDIT_LOG_RETENTION_DAYS
# (interet legitime / tracabilite fiscale, configurable). L'imputabilite recente
# est preservee. C'est l'unique exception documentee a l'append-only de audit_log
# (RG-T14) : une purge de retention planifiee, jamais une mutation applicative.
#
# Variables d'env (injectees par docker-compose depuis .env) :
#   DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD
#   AUDIT_LOG_RETENTION_DAYS (defaut 365)
#
# Exit codes : 0 OK | 1 env manquant/invalide | 2 requete SQL echouee
set -euo pipefail

log() { echo "[purge-audit-log $(date -Iseconds)] $*" >&2; }

for var in DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD; do
    if [ -z "${!var:-}" ]; then log "ERROR: variable $var vide ou non definie"; exit 1; fi
done

DAYS="${AUDIT_LOG_RETENTION_DAYS:-365}"
case "$DAYS" in
    ''|*[!0-9]*) log "ERROR: AUDIT_LOG_RETENTION_DAYS non entier ('$DAYS')"; exit 1 ;;
esac

if ! n="$(mariadb --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --password="$DB_PASSWORD" \
        --default-character-set=utf8mb4 -N -B "$DB_NAME" \
        -e "DELETE FROM audit_log WHERE created_at < NOW() - INTERVAL ${DAYS} DAY; SELECT ROW_COUNT();")"; then
    log "ERROR: purge audit_log a echoue"
    exit 2
fi
log "audit_log: ${n} ligne(s) purgee(s) (> ${DAYS} jours)"
