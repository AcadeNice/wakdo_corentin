#!/usr/bin/env bash
#
# Wakdo - preuve d'execution REELLE de docker/cron/scripts/purge-throttle.sh
# contre une vraie MariaDB jetable (meme binaire `mariadb` que le conteneur cron).
#
# Constat d'audit corrige le 2026-09-29 : le script ne purgeait que
# login_throttle et pin_throttle -- password_reset_throttle (migration 0020,
# porte l'ADRESSE demandee, y compris pour une adresse sans compte, RG-2)
# n'etait JAMAIS purgee, alors que la migration documentait deja la requete en
# commentaire. Ce test couvre les TROIS tables avec le MEME predicat (verrou
# inactif ET derniere tentative plus ancienne que THROTTLE_PURGE_AFTER_HOURS) :
# une ligne recente ou verrouillee doit survivre, une ligne ancienne et
# deverrouillee doit disparaitre.
#
# Pourquoi un test SHELL plutot que PHPUnit : docker/php-fpm/php.ini desactive
# volontairement exec/passthru/shell_exec/system/proc_open/popen (defense en
# profondeur) -- le meme php.ini est utilise pour lancer PHPUnit (image
# wakdo-wakdo-app), donc un test PHPUnit ne peut PAS invoquer ce script shell
# reel depuis PHP dans ce projet. Le shell reste le seul executeur capable de
# prouver ce script sans contourner ce garde-fou.
#
# Necessite `docker` (auto-skip sinon, code 0 -- un environnement sans Docker
# ne peut de toute facon pas faire tourner le reste de la stack). Conteneur
# MariaDB ephemere, nettoye par le trap EXIT quel que soit le resultat.
#
# Lancement : tests/shell/purge-throttle.test.sh
# Exit codes : 0 = toutes les assertions passent (ou Docker absent) ; 1 = au moins une echoue.

set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SCRIPT="$ROOT/docker/cron/scripts/purge-throttle.sh"
CONTAINER="purge-throttle-test-$$"

if ! command -v docker > /dev/null 2>&1; then
    echo "Docker indisponible : test saute (aucune assertion, pas d'echec)." >&2
    exit 0
fi

cleanup() { docker rm -f "$CONTAINER" > /dev/null 2>&1 || true; }
trap cleanup EXIT

PASS=0
FAIL=0
check() {
    local label="$1" got="$2" want="$3"
    if [ "$got" = "$want" ]; then
        printf 'OK   %s\n' "$label"
        PASS=$((PASS + 1))
    else
        printf 'FAIL %s (obtenu: %s, attendu: %s)\n' "$label" "$got" "$want" >&2
        FAIL=$((FAIL + 1))
    fi
}

echo "Demarrage MariaDB jetable ($CONTAINER)..."
docker run --rm -d --network none --name "$CONTAINER" \
    -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wakdo mariadb:11.4 > /dev/null

ready=0
for _ in $(seq 1 40); do
    if docker exec "$CONTAINER" mariadb -uroot -proot -e "SELECT 1" wakdo > /dev/null 2>&1; then
        ready=1
        break
    fi
    sleep 1
done
if [ "$ready" != 1 ]; then
    echo "ERREUR : MariaDB jetable injoignable apres 40s." >&2
    exit 1
fi

docker cp "$ROOT/db/migrations" "$CONTAINER:/migrations"
docker cp "$SCRIPT" "$CONTAINER:/purge-throttle.sh"

for f in "$ROOT"/db/migrations/*.sql; do
    name="$(basename "$f")"
    if ! docker exec "$CONTAINER" sh -c "mariadb -uroot -proot wakdo < /migrations/$name"; then
        echo "ERREUR : migration $name a echoue." >&2
        exit 1
    fi
done

# Fixtures : une ligne ANCIENNE sans verrou actif (doit etre purgee) et une ligne
# RECENTE sans verrou actif (doit survivre) par table, plus une ligne ANCIENNE
# mais avec un verrou ACTIF sur password_reset_throttle (doit survivre malgre
# son age -- le verrou actif prime sur l'anciennete, meme predicat partout).
docker exec "$CONTAINER" sh -c '
set -e
OLD=$(date -d "-2 hours" "+%Y-%m-%d %H:%M:%S")
RECENT=$(date -d "-10 minutes" "+%Y-%m-%d %H:%M:%S")
FUTURE=$(date -d "+1 hour" "+%Y-%m-%d %H:%M:%S")

mariadb -uroot -proot wakdo -e "
INSERT INTO login_throttle (ip_address, failed_attempts, window_started_at, last_attempt_at)
    VALUES (\"purge-test-old-ip\", 3, \"$OLD\", \"$OLD\");
INSERT INTO login_throttle (ip_address, failed_attempts, window_started_at, last_attempt_at)
    VALUES (\"purge-test-recent-ip\", 3, \"$RECENT\", \"$RECENT\");

INSERT INTO role (code, label, is_active)
    SELECT \"purgetest\", \"Purge Test\", 1 WHERE NOT EXISTS (SELECT 1 FROM role WHERE code = \"purgetest\");
SET @rid = (SELECT id FROM role WHERE code = \"purgetest\");
INSERT INTO user (email, password_hash, first_name, last_name, role_id, is_active)
    VALUES (\"purge-test@wakdo.invalid\", \"x\", \"P\", \"T\", @rid, 1);
SET @uid = LAST_INSERT_ID();
INSERT INTO pin_throttle (actor_user_id, failed_attempts, window_started_at, last_attempt_at)
    VALUES (@uid, 3, \"$OLD\", \"$OLD\");

INSERT INTO password_reset_throttle (throttle_kind, identifier, failed_attempts, window_started_at, last_attempt_at)
    VALUES (\"email\", \"purge-test-old-email\", 3, \"$OLD\", \"$OLD\");
INSERT INTO password_reset_throttle (throttle_kind, identifier, failed_attempts, window_started_at, last_attempt_at)
    VALUES (\"email\", \"purge-test-recent-email\", 3, \"$RECENT\", \"$RECENT\");
INSERT INTO password_reset_throttle (throttle_kind, identifier, failed_attempts, window_started_at, last_attempt_at, lockout_until)
    VALUES (\"ip\", \"purge-test-locked-ip\", 5, \"$OLD\", \"$OLD\", \"$FUTURE\");
"
' || { echo "ERREUR : insertion des fixtures a echoue." >&2; exit 1; }

echo "Fixtures posees. Execution du script (THROTTLE_PURGE_AFTER_HOURS=1)..."
OUTPUT="$(docker exec \
    -e DB_HOST=127.0.0.1 -e DB_PORT=3306 -e DB_NAME=wakdo -e DB_USER=root -e DB_PASSWORD=root \
    -e THROTTLE_PURGE_AFTER_HOURS=1 \
    "$CONTAINER" bash /purge-throttle.sh 2>&1)"
SCRIPT_EXIT=$?
echo "$OUTPUT" | sed 's/^/    /'

check "script termine sans erreur" "$SCRIPT_EXIT" "0"
case "$OUTPUT" in
    *"login_throttle: 1 ligne"*) check "login_throttle : 1 ligne purgee (log)" 1 1 ;;
    *) check "login_throttle : 1 ligne purgee (log)" 0 1 ;;
esac
case "$OUTPUT" in
    *"pin_throttle: 1 ligne"*) check "pin_throttle : 1 ligne purgee (log)" 1 1 ;;
    *) check "pin_throttle : 1 ligne purgee (log)" 0 1 ;;
esac
case "$OUTPUT" in
    *"password_reset_throttle: 1 ligne"*) check "password_reset_throttle : 1 ligne purgee (log, coeur du constat)" 1 1 ;;
    *) check "password_reset_throttle : 1 ligne purgee (log, coeur du constat)" 0 1 ;;
esac

row_exists() {
    local table="$1" where="$2"
    docker exec "$CONTAINER" mariadb -uroot -proot -N -B wakdo \
        -e "SELECT COUNT(*) FROM ${table} WHERE ${where}"
}

check "login_throttle : ligne ancienne purgee" "$(row_exists login_throttle "ip_address = 'purge-test-old-ip'")" "0"
check "login_throttle : ligne recente conservee" "$(row_exists login_throttle "ip_address = 'purge-test-recent-ip'")" "1"
check "pin_throttle : ligne ancienne purgee" "$(row_exists pin_throttle "actor_user_id = (SELECT id FROM user WHERE email = 'purge-test@wakdo.invalid')")" "0"
check "password_reset_throttle (email) : adresse ancienne purgee" \
    "$(row_exists password_reset_throttle "throttle_kind = 'email' AND identifier = 'purge-test-old-email'")" "0"
check "password_reset_throttle (email) : adresse recente conservee" \
    "$(row_exists password_reset_throttle "throttle_kind = 'email' AND identifier = 'purge-test-recent-email'")" "1"
check "password_reset_throttle (ip) : verrou actif conserve malgre l'anciennete" \
    "$(row_exists password_reset_throttle "throttle_kind = 'ip' AND identifier = 'purge-test-locked-ip'")" "1"

echo
printf '%s assertion(s) OK, %s en echec\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
