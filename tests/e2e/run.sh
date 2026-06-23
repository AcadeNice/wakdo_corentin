#!/usr/bin/env bash
#
# E2E borne : monte une stack JETABLE isolee, lance Playwright (conteneur officiel,
# headless) contre elle, puis demonte tout. Ne touche a aucune stack existante.
#
#   tests/e2e/run.sh
#
# Pre-requis : Docker. Aucune dependance Node/Playwright sur l'hote (tout en conteneur).
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

PROJECT=wakdoe2e
PW_VERSION=1.49.1   # doit matcher devDependencies["@playwright/test"] de package.json
NET="${PROJECT}_wakdo_internal"

ENVFILE="$(mktemp)"
cp .env.example "$ENVFILE"   # template local-first : marche tel quel (valeurs dev)
# Hostnames de TEST en .test (pas .localhost) : Chromium/curl resolvent *.localhost en
# dur vers 127.0.0.1 (RFC 6761) et ignorent --add-host. .test n'est pas special -> joignable.
perl -pi -e 's/^APP_HOST_KIOSK=.*/APP_HOST_KIOSK=kiosk.wakdo.test/; s/^APP_HOST_ADMIN=.*/APP_HOST_ADMIN=admin.wakdo.test/;' "$ENVFILE"
COMPOSE="docker compose -p $PROJECT --env-file $ENVFILE -f docker-compose.yml -f tests/e2e/docker-compose.e2e.yml"

cleanup() { echo "[e2e] teardown"; $COMPOSE down -v >/dev/null 2>&1 || true; rm -f "$ENVFILE"; }
trap cleanup EXIT

echo "[e2e] build + up stack jetable ($PROJECT)"
$COMPOSE up -d --build

echo "[e2e] attente migrate (completion)"
for _ in $(seq 1 40); do
  st="$(docker inspect -f '{{.State.Status}}' wakdoe2e-migrate 2>/dev/null || echo NA)"
  code="$(docker inspect -f '{{.State.ExitCode}}' wakdoe2e-migrate 2>/dev/null || echo NA)"
  [ "$st" = "exited" ] && [ "$code" = "0" ] && { echo "[e2e] migrate OK"; break; }
  [ "$st" = "exited" ] && [ "$code" != "0" ] && { echo "[e2e] migrate ECHEC ($code)"; docker logs wakdoe2e-migrate; exit 1; }
  sleep 2
done

echo "[e2e] attente web healthy"
for _ in $(seq 1 40); do
  [ "$(docker inspect -f '{{.State.Health.Status}}' wakdoe2e-web 2>/dev/null || echo NA)" = "healthy" ] && break
  sleep 2
done

WEB_IP="$(docker inspect -f "{{(index .NetworkSettings.Networks \"$NET\").IPAddress}}" wakdoe2e-web)"
echo "[e2e] web @ $WEB_IP ($NET)"

echo "[e2e] Playwright (conteneur officiel v$PW_VERSION)"
docker run --rm \
  --network "$NET" \
  --add-host "kiosk.wakdo.test:$WEB_IP" \
  --add-host "admin.wakdo.test:$WEB_IP" \
  -v "$ROOT":/work -w /work \
  -e BASE_URL="http://kiosk.wakdo.test" \
  -e CI=1 \
  "mcr.microsoft.com/playwright:v${PW_VERSION}-jammy" \
  bash -c "npm install --no-audit --no-fund --silent && npx playwright test"
