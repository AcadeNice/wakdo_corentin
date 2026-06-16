#!/usr/bin/env bash
#
# Wakdo - purge des compteurs de throttle sans verrou actif (mlt.md 13.5).
#
# Borne la croissance de login_throttle (per-IP, RG-8) et pin_throttle
# (per-acteur, RG-T22) : supprime les lignes dont le verrou n'est plus actif
# ET dont la derniere tentative est plus ancienne que THROTTLE_PURGE_AFTER_HOURS.
# Les lignes servant encore un verrou actif sont conservees.
#
# Variables d'env (injectees par docker-compose depuis .env) :
#   DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD
#   THROTTLE_PURGE_AFTER_HOURS (defaut 24)
#
# Exit codes : 0 OK | 1 env manquant/invalide | 2 requete SQL echouee
set -euo pipefail

log() { echo "[purge-throttle $(date -Iseconds)] $*" >&2; }

for var in DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD; do
    if [ -z "${!var:-}" ]; then log "ERROR: variable $var vide ou non definie"; exit 1; fi
done

HOURS="${THROTTLE_PURGE_AFTER_HOURS:-24}"
case "$HOURS" in
    ''|*[!0-9]*) log "ERROR: THROTTLE_PURGE_AFTER_HOURS non entier ('$HOURS')"; exit 1 ;;
esac

db() {
    mariadb --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --password="$DB_PASSWORD" \
        --default-character-set=utf8mb4 -N -B "$DB_NAME" -e "$1"
}

# login_throttle et pin_throttle partagent le meme predicat (mlt.md 13.5).
for table in login_throttle pin_throttle; do
    if ! n="$(db "DELETE FROM ${table} WHERE (lockout_until IS NULL OR lockout_until < NOW()) AND last_attempt_at < NOW() - INTERVAL ${HOURS} HOUR; SELECT ROW_COUNT();")"; then
        log "ERROR: purge ${table} a echoue"
        exit 2
    fi
    log "${table}: ${n} ligne(s) purgee(s) (sans verrou actif, > ${HOURS}h)"
done
