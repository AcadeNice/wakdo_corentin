#!/usr/bin/env bash
#
# Wakdo - restauration de la BDD depuis un dump produit par backup-db.sh.
#
# Operation MANUELLE (pas un job cron) : restaurer ecrase les donnees courantes.
# A lancer dans le conteneur disposant du client mysql et du reseau de la BDD, p.ex.
#   docker compose run --rm -v "$PWD/var/backups:/backups" wakdo-cron \
#       /scripts/restore-db.sh /backups/wakdo_YYYYMMDD_HHMMSS.sql.gz --force
#
# Variables d'env lues (memes que backup-db.sh) :
#   DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD
# Note : un dump complet contient des DROP/CREATE TABLE ; le compte utilise doit
# donc avoir les privileges DDL. Le user applicatif (DML seul) ne suffit pas :
# fournir un compte privilegie via DB_USER/DB_PASSWORD pour la restauration.
#
# Usage :
#   restore-db.sh <fichier.sql.gz|fichier.sql> [--force]
# Sans --force, le script demande une confirmation interactive.
#
# Exit codes :
#   0 - restauration OK
#   1 - variables env manquantes / mauvais usage / fichier absent
#   2 - restauration mysql a echoue
#   3 - confirmation refusee

set -euo pipefail

DUMP_FILE="${1:-}"
FORCE="${2:-}"

log() {
    echo "[restore-db $(date -Iseconds)] $*" >&2
}

if [ -z "$DUMP_FILE" ]; then
    log "usage: restore-db.sh <fichier.sql.gz|fichier.sql> [--force]"
    exit 1
fi

if [ ! -f "$DUMP_FILE" ]; then
    log "ERROR: fichier de dump introuvable : $DUMP_FILE"
    exit 1
fi

for var in DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD; do
    if [ -z "${!var:-}" ]; then
        log "ERROR: variable $var vide ou non definie"
        exit 1
    fi
done

# Garde-fou : la restauration ecrase la base cible. Confirmation requise sauf --force.
if [ "$FORCE" != "--force" ]; then
    printf 'Restaurer %s dans la base "%s" sur %s:%s ? Les donnees actuelles seront ecrasees. [oui/NON] ' \
        "$DUMP_FILE" "$DB_NAME" "$DB_HOST" "$DB_PORT" >&2
    read -r answer
    if [ "$answer" != "oui" ]; then
        log "restauration annulee."
        exit 3
    fi
fi

# Decompression a la volee si le dump est gzippe.
reader=(cat "$DUMP_FILE")
case "$DUMP_FILE" in
    *.gz) reader=(gzip -dc "$DUMP_FILE") ;;
esac

log "restauration de ${DB_NAME} depuis ${DUMP_FILE}"
if ! "${reader[@]}" | mysql \
        --host="${DB_HOST}" \
        --port="${DB_PORT}" \
        --user="${DB_USER}" \
        --password="${DB_PASSWORD}" \
        --default-character-set=utf8mb4 \
        "${DB_NAME}"; then
    log "ERROR: la restauration mysql a echoue"
    exit 2
fi

log "restauration OK : ${DB_NAME}"
exit 0
