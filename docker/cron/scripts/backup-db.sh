#!/usr/bin/env bash
#
# Wakdo - backup quotidien de la BDD
#
# Execute par cron a 03h00 (voir /etc/crontabs/root).
# Dump complet de la BDD dans /backups (bind-mount vers ./var/backups),
# compresse gzip, horodate, rotation sur 14 derniers dumps.
#
# Variables d'env lues (injectees par docker-compose depuis .env) :
#   - DB_HOST
#   - DB_PORT
#   - DB_NAME
#   - DB_USER          (on utilise le user applicatif, pas root)
#   - DB_PASSWORD
#
# Le USER applicatif doit avoir SELECT + LOCK TABLES + SHOW VIEW sur wakdo.
# (GRANT donnes dans les migrations a venir en P2.)
#
# Exit codes :
#   0 - backup OK
#   1 - variables env manquantes
#   2 - mysqldump a echoue
#   3 - rotation a echoue

set -euo pipefail

BACKUP_DIR="/backups"
RETENTION_DAYS=14
TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
DUMP_FILE="${BACKUP_DIR}/wakdo_${TIMESTAMP}.sql.gz"

log() {
    echo "[backup-db $(date -Iseconds)] $*" >&2
}

# --- Verification variables ---
for var in DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD; do
    if [ -z "${!var:-}" ]; then
        log "ERROR: variable $var vide ou non definie"
        exit 1
    fi
done

# --- Verification que /backups est ecrivable ---
if [ ! -w "${BACKUP_DIR}" ]; then
    log "ERROR: ${BACKUP_DIR} n'est pas ecrivable (verifier bind-mount et permissions)"
    exit 1
fi

# --- Dump ---
log "demarrage dump BDD ${DB_NAME} depuis ${DB_HOST}:${DB_PORT}"
# --single-transaction : dump consistent sans LOCK TABLES (innodb only).
# --routines --triggers : inclut procedures stockees et triggers (si on en a).
# --no-tablespaces : evite le besoin de PROCESS privilege.
if ! mysqldump \
        --host="${DB_HOST}" \
        --port="${DB_PORT}" \
        --user="${DB_USER}" \
        --password="${DB_PASSWORD}" \
        --single-transaction \
        --routines \
        --triggers \
        --no-tablespaces \
        --default-character-set=utf8mb4 \
        "${DB_NAME}" \
        | gzip -9 > "${DUMP_FILE}"; then
    log "ERROR: mysqldump a echoue"
    # Supprime un dump partiel si mysqldump a commence a ecrire.
    rm -f "${DUMP_FILE}"
    exit 2
fi

# --- Verification du dump ---
# Un dump vide ou quasi-vide = probleme silencieux (ex: mauvais USER GRANT).
# On exige une taille min de 512 octets pour alerter tot.
DUMP_SIZE="$(stat -c '%s' "${DUMP_FILE}")"
if [ "${DUMP_SIZE}" -lt 512 ]; then
    log "ERROR: dump suspect (${DUMP_SIZE} octets), supprime pour ne pas polluer la rotation"
    rm -f "${DUMP_FILE}"
    exit 2
fi

log "dump OK : ${DUMP_FILE} (${DUMP_SIZE} octets)"

# --- Rotation : supprime les dumps plus vieux que RETENTION_DAYS ---
if ! find "${BACKUP_DIR}" -maxdepth 1 -type f -name 'wakdo_*.sql.gz' \
        -mtime "+${RETENTION_DAYS}" -delete; then
    log "WARNING: rotation incomplete"
    exit 3
fi

REMAINING="$(find "${BACKUP_DIR}" -maxdepth 1 -type f -name 'wakdo_*.sql.gz' | wc -l)"
log "rotation OK : ${REMAINING} dumps conserves"

exit 0
