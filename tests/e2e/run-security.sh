#!/usr/bin/env bash
#
# Suite de tests de securite (tests/e2e/security-*.spec.js) contre une pile JETABLE
# isolee, montee comme en production pour ce qui compte ici : APP_DEBUG=false.
#
#   tests/e2e/run-security.sh                 # les trois phases
#   tests/e2e/run-security.sh security-xss.spec.js   # phase 1 seule, fichiers choisis
#
# Trois phases, sur la MEME pile :
#   1. main    : tous les fichiers security-*.spec.js (reset et db-down se sautent seuls) ;
#   2. reset   : le lien de reinitialisation demande en phase 1 est relu dans le journal de
#                l'application (pas de SMTP : App\Auth\LogMailer l'y ecrit) puis rejoue ;
#   3. db-down : la base de la pile jetable est arretee, les reponses d'erreur sont relues.
#
# La production n'est jamais jointe : toutes les ecritures (comptes, produits, commandes)
# se font sur la pile montee ici, detruite avec ses volumes a la sortie. Aucune image
# nouvelle : httpd/php/mariadb du projet + mcr.microsoft.com/playwright (deja utilisee par
# les autres lanceurs de tests/e2e/).
#
# Variables : SEC_PROJECT (nom du projet compose, defaut wakdosec), SEC_APP_DEBUG (defaut
# false ; true reproduit .env.example).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

PROJECT="${SEC_PROJECT:-wakdosec}"
APP_DEBUG_VALUE="${SEC_APP_DEBUG:-false}"
PW_VERSION=1.49.1   # doit correspondre a devDependencies["@playwright/test"] de package.json
NET="${PROJECT}_wakdo_internal"
NM_DIR="${TMPDIR:-/tmp}/wakdo-security-node_modules"
mkdir -p "$NM_DIR"

ENVFILE="$(mktemp)"
OVERRIDE="$(mktemp --suffix=.yml)"
cp .env.example "$ENVFILE"
perl -pi -e 's/^APP_HOST_KIOSK=.*/APP_HOST_KIOSK=kiosk.wakdo.test/; s/^APP_HOST_ADMIN=.*/APP_HOST_ADMIN=admin.wakdo.test/;' "$ENVFILE"
perl -pi -e "s/^APP_DEBUG=.*/APP_DEBUG=${APP_DEBUG_VALUE}/" "$ENVFILE"
CORS_ORIGIN="$(sed -n 's/^CORS_ALLOWED_ORIGIN=\([^ #]*\).*/\1/p' "$ENVFILE")"
cat > "$OVERRIDE" <<YML
services:
  wakdo-db:
    container_name: ${PROJECT}-db
  wakdo-migrate:
    container_name: ${PROJECT}-migrate
  wakdo-app:
    container_name: ${PROJECT}-app
  wakdo-web:
    container_name: ${PROJECT}-web
    ports: !reset []
  wakdo-cron:
    container_name: ${PROJECT}-cron
YML
COMPOSE="docker compose -p $PROJECT --env-file $ENVFILE -f docker-compose.yml -f $OVERRIDE"

cleanup() {
  echo "[securite] demontage de la pile $PROJECT"
  $COMPOSE down -v >/dev/null 2>&1 || true
  rm -f "$ENVFILE" "$OVERRIDE"
  # Point de montage vide laisse par le volume node_modules (proprietaire root).
  if [ -d "$ROOT/node_modules" ] && [ -z "$(ls -A "$ROOT/node_modules" 2>/dev/null)" ]; then
    docker run --rm -v "$ROOT":/w alpine:3.20 rmdir /w/node_modules >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

echo "[securite] construction + demarrage de la pile jetable ($PROJECT, APP_DEBUG=$APP_DEBUG_VALUE)"
$COMPOSE up -d --build >/dev/null 2>&1

for _ in $(seq 1 60); do
  st="$(docker inspect -f '{{.State.Status}}' "${PROJECT}-migrate" 2>/dev/null || echo NA)"
  code="$(docker inspect -f '{{.State.ExitCode}}' "${PROJECT}-migrate" 2>/dev/null || echo NA)"
  [ "$st" = exited ] && [ "$code" = 0 ] && break
  [ "$st" = exited ] && [ "$code" != 0 ] && { docker logs "${PROJECT}-migrate" | tail -20; exit 1; }
  sleep 2
done
for _ in $(seq 1 60); do
  [ "$(docker inspect -f '{{.State.Health.Status}}' "${PROJECT}-web" 2>/dev/null || echo NA)" = healthy ] && break
  sleep 2
done
WEB_IP="$(docker inspect -f "{{(index .NetworkSettings.Networks \"$NET\").IPAddress}}" "${PROJECT}-web")"
echo "[securite] pile prete, serveur web @ $WEB_IP"

STATUS=0
play() {
  local phase="$1"; shift
  echo "[securite] phase $phase : $*"
  docker run --rm --network "$NET" --user "$(id -u):$(id -g)" \
    --add-host "kiosk.wakdo.test:$WEB_IP" --add-host "admin.wakdo.test:$WEB_IP" \
    -v "$ROOT":/work -v "$NM_DIR":/work/node_modules -w /work \
    -e HOME=/tmp -e npm_config_cache=/tmp/.npm -e CI=1 \
    -e SEC_PHASE="$phase" -e SEC_APP_DEBUG="$APP_DEBUG_VALUE" -e SEC_CORS_ORIGIN="$CORS_ORIGIN" \
    -e SEC_RESET_EMAIL="${SEC_RESET_EMAIL:-}" -e SEC_RESET_TOKEN="${SEC_RESET_TOKEN:-}" \
    "mcr.microsoft.com/playwright:v${PW_VERSION}-jammy" \
    bash -c "[ -d node_modules/@playwright/test ] || npm ci --no-audit --no-fund --silent; npx playwright test --reporter=list --retries=0 --output=/tmp/pw-artifacts $*" \
    || STATUS=1
}

if [ "$#" -gt 0 ]; then
  play main "$(printf 'tests/e2e/%s ' "$@")"
  exit "$STATUS"
fi

play main "tests/e2e/security-"

# Lien de reinitialisation demande en phase 1 pour le compte jetable sec-reset-* :
# "[wakdo][password-reset] <email> -> <url>?token=<jeton>" dans le journal de wakdo-app.
LINE="$(docker logs "${PROJECT}-app" 2>&1 | grep -o '\[wakdo\]\[password-reset\] sec-reset-[^ ]* -> [^ ]*token=[0-9a-f]*' | tail -1 || true)"
if [ -n "$LINE" ]; then
  export SEC_RESET_EMAIL="$(printf '%s' "$LINE" | sed 's/.*\] \(sec-reset-[^ ]*\) -> .*/\1/')"
  export SEC_RESET_TOKEN="$(printf '%s' "$LINE" | sed 's/.*token=\([0-9a-f]*\).*/\1/')"
  play reset tests/e2e/security-reset.spec.js
else
  echo "[securite] lien de reinitialisation absent du journal : phase reset non jouee"
  STATUS=1
fi

docker stop "${PROJECT}-db" >/dev/null
play db-down tests/e2e/security-dbdown.spec.js

exit "$STATUS"
