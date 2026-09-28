#!/usr/bin/env bash
#
# Capture des VRAIES reponses de chaque route pour le trajet de la page Sante : monte une
# stack JETABLE isolee, joue tests/e2e/health-capture.spec.js contre elle, arrete la base
# pour capturer la vraie reponse d'une exception, puis demonte tout.
#
#   tests/e2e/run-health-capture.sh
#
# Ecrit src/app/Health/captured-responses.json (versionne, hors de la racine web). A relancer
# apres tout changement de route, de garde ou de message d'erreur : le test
# tests/Unit/Health/CapturedResponsesTest.php echoue en CI si une route de la carte n'a
# pas sa reponse capturee.
#
# La production n'est jamais jointe : toutes les ecritures (commandes, produits, comptes)
# se font sur la stack jetable montee ici, puis detruite avec son volume.
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

OUT_REL="src/app/Health/captured-responses.json"
PROJECT=wakdohc
PW_VERSION=1.49.1   # doit matcher devDependencies["@playwright/test"] de package.json
NET="${PROJECT}_wakdo_internal"
NM_DIR="${TMPDIR:-/tmp}/wakdo-health-node_modules"
STATE_REL=".health-capture"
mkdir -p "$NM_DIR" "$ROOT/$STATE_REL" "$(dirname "$ROOT/$OUT_REL")"

ENVFILE="$(mktemp)"
cp .env.example "$ENVFILE"
perl -pi -e 's/^APP_HOST_KIOSK=.*/APP_HOST_KIOSK=kiosk.wakdo.test/; s/^APP_HOST_ADMIN=.*/APP_HOST_ADMIN=admin.wakdo.test/;' "$ENVFILE"
# Comme en production : sans le mode debogage, une exception ne renvoie qu un message generique.
perl -pi -e "s/^APP_DEBUG=.*/APP_DEBUG=false/" "$ENVFILE"
COMPOSE="docker compose -p $PROJECT --env-file $ENVFILE -f docker-compose.yml -f tests/e2e/docker-compose.health.yml"

cleanup() { echo "[capture] teardown"; $COMPOSE down -v >/dev/null 2>&1 || true; rm -f "$ENVFILE"; rm -rf "${ROOT:?}/$STATE_REL"; }
trap cleanup EXIT

echo "[capture] build + up stack jetable ($PROJECT)"
$COMPOSE up -d --build

for _ in $(seq 1 40); do
  st="$(docker inspect -f '{{.State.Status}}' ${PROJECT}-migrate 2>/dev/null || echo NA)"
  code="$(docker inspect -f '{{.State.ExitCode}}' ${PROJECT}-migrate 2>/dev/null || echo NA)"
  [ "$st" = "exited" ] && [ "$code" = "0" ] && break
  [ "$st" = "exited" ] && [ "$code" != "0" ] && { docker logs ${PROJECT}-migrate; exit 1; }
  sleep 2
done
for _ in $(seq 1 40); do
  [ "$(docker inspect -f '{{.State.Health.Status}}' ${PROJECT}-web 2>/dev/null || echo NA)" = "healthy" ] && break
  sleep 2
done
WEB_IP="$(docker inspect -f "{{(index .NetworkSettings.Networks \"$NET\").IPAddress}}" ${PROJECT}-web)"
COMMIT="$(git rev-parse --short HEAD 2>/dev/null || echo inconnu)"

play() {
  docker run --rm --network "$NET" --user "$(id -u):$(id -g)" \
    --add-host "kiosk.wakdo.test:$WEB_IP" --add-host "admin.wakdo.test:$WEB_IP" \
    -v "$ROOT":/work -v "$NM_DIR":/work/node_modules -w /work \
    -e HOME=/tmp -e npm_config_cache=/tmp/.npm -e CI=1 \
    -e HEALTH_CAPTURE_OUT="/work/$OUT_REL" -e HEALTH_CAPTURE_STATE="/work/$STATE_REL" \
    -e HEALTH_CAPTURE_PHASE="$1" -e HEALTH_CAPTURE_COMMIT="$COMMIT" -e HEALTH_CAPTURE_RESET_URL="${RESET_URL:-}" \
    "mcr.microsoft.com/playwright:v${PW_VERSION}-jammy" \
    bash -c "npm ci --no-audit --no-fund --silent && npx playwright test tests/e2e/health-capture.spec.js --reporter=line --retries=0 --output=/tmp/pw-artifacts"
}

echo "[capture] phase 1 : toutes les routes, base en marche"
play main
# Le lien de reinitialisation demande en phase 1 : sans SMTP, l'application l'ecrit dans
# son journal (LogMailer) au lieu de l'envoyer.
RESET_URL="$(docker logs ${PROJECT}-app 2>&1 | grep -o 'http[^ ]*reset_password?token=[A-Za-z0-9]*' | tail -1 || true)"
if [ -n "$RESET_URL" ]; then LIEN=trouve; else LIEN=absent; fi
echo "[capture] phase 2 : nouveau mot de passe par le lien du journal (lien $LIEN)"
play reset
echo "[capture] phase 3 : base arretee, vraie reponse d'une exception"
docker stop ${PROJECT}-db >/dev/null
play db-down

echo "[capture] ecrit : $OUT_REL"
