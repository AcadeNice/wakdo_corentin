#!/usr/bin/env bash
#
# Captures du dossier de preuves : monte une pile JETABLE isolee, joue
# tests/e2e/responsive.spec.js avec CAPTURES_DIR pose, range les images dans les deux
# dossiers de preuves concernes, puis demonte tout.
#
#   tests/e2e/run-captures.sh
#
# Pre-requis : Docker. Aucune dependance Node/Playwright sur l'hote (tout en conteneur).
#
# POURQUOI un script separe de run.sh, qui joue deja ce fichier de test :
#   1. run.sh ne pose pas CAPTURES_DIR : il joue les assertions sans rien ecrire dans
#      docs/. Le dossier de preuves ne peut donc pas etre reecrit par accident.
#   2. Il tourne sous l'UID de l'appelant (--user) : les images deposees dans docs/
#      appartiennent a l'utilisateur, pas a root.
#   3. Il isole node_modules hors du depot : le npm du conteneur ne touche pas le
#      node_modules de l'hote.
# Meme structure que run-a11y.sh et run-w3c.sh, pour la meme raison.
#
# Les captures sont prises sur la pile jetable (donnees de demonstration), pas sur la
# production : aucune donnee reelle n'entre dans le dossier de preuves.
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

PREUVES="${1:-docs/soutenance/preuves}"
RESPONSIVE="$PREUVES/captures-responsive"
SAISIE="$PREUVES/captures-controle-saisie"
PROJECT=wakdocaptures
PW_VERSION=1.49.1   # doit matcher devDependencies["@playwright/test"] de package.json
NET="${PROJECT}_wakdo_internal"

NM_DIR="${TMPDIR:-/tmp}/wakdo-captures-node_modules"
# Dossier de depot du conteneur : un seul dossier plat, trie ensuite (voir plus bas).
BRUT="$ROOT/.captures"
mkdir -p "$NM_DIR" "$BRUT" "$ROOT/$RESPONSIVE" "$ROOT/$SAISIE"
rm -f "$BRUT"/*.png

ENVFILE="$(mktemp)"
cp .env.example "$ENVFILE"
# Hostnames de TEST en .test (pas .localhost) : Chromium resout *.localhost en dur vers
# 127.0.0.1 (RFC 6761) et ignore --add-host. .test n'est pas special -> joignable.
perl -pi -e 's/^APP_HOST_KIOSK=.*/APP_HOST_KIOSK=kiosk.wakdo.test/; s/^APP_HOST_ADMIN=.*/APP_HOST_ADMIN=admin.wakdo.test/;' "$ENVFILE"
COMPOSE="docker compose -p $PROJECT --env-file $ENVFILE -f docker-compose.yml -f tests/e2e/docker-compose.captures.yml"

cleanup() { echo "[captures] teardown"; $COMPOSE down -v >/dev/null 2>&1 || true; rm -f "$ENVFILE"; }
trap cleanup EXIT

echo "[captures] build + up pile jetable ($PROJECT)"
$COMPOSE up -d --build

echo "[captures] attente migrate (completion)"
for _ in $(seq 1 40); do
  st="$(docker inspect -f '{{.State.Status}}' ${PROJECT}-migrate 2>/dev/null || echo NA)"
  code="$(docker inspect -f '{{.State.ExitCode}}' ${PROJECT}-migrate 2>/dev/null || echo NA)"
  [ "$st" = "exited" ] && [ "$code" = "0" ] && { echo "[captures] migrate OK"; break; }
  [ "$st" = "exited" ] && [ "$code" != "0" ] && { echo "[captures] migrate ECHEC ($code)"; docker logs ${PROJECT}-migrate; exit 1; }
  sleep 2
done

echo "[captures] attente web healthy"
for _ in $(seq 1 40); do
  [ "$(docker inspect -f '{{.State.Health.Status}}' ${PROJECT}-web 2>/dev/null || echo NA)" = "healthy" ] && break
  sleep 2
done

WEB_IP="$(docker inspect -f "{{(index .NetworkSettings.Networks \"$NET\").IPAddress}}" ${PROJECT}-web)"
echo "[captures] web @ $WEB_IP ($NET)"

echo "[captures] Playwright (conteneur officiel v$PW_VERSION)"
docker run --rm \
  --network "$NET" \
  --user "$(id -u):$(id -g)" \
  --add-host "kiosk.wakdo.test:$WEB_IP" \
  --add-host "admin.wakdo.test:$WEB_IP" \
  -v "$ROOT":/work \
  -v "$NM_DIR":/work/node_modules \
  -w /work \
  -e HOME=/tmp \
  -e npm_config_cache=/tmp/.npm \
  -e BASE_URL="http://kiosk.wakdo.test" \
  -e CAPTURES_DIR=/work/.captures \
  -e CI=1 \
  "mcr.microsoft.com/playwright:v${PW_VERSION}-jammy" \
  bash -c "npm ci --no-audit --no-fund --silent && npx playwright test tests/e2e/responsive.spec.js --reporter=line --output=/tmp/pw-artifacts"

# Rangement. Le fichier de test depose tout dans un dossier plat ; le dossier de preuves
# en distingue deux, parce qu'ils servent deux criteres differents : l'adaptation aux
# resolutions (Cr 1.b.1, preuve 02) et le controle de saisie (Cr 2.b.1, preuve 09). Le
# tri est explicite ici plutot que devine : trois captures nommement citees par la
# preuve 09 vont dans le second dossier, tout le reste va dans le premier.
echo "[captures] rangement"
rm -f "$ROOT/$RESPONSIVE"/*.png
for nom in admin-controle-saisie:saisie-en-direct admin-modal-pin:modal-pin-apres admin-modal-pin-refuse:modal-pin-refuse; do
  src="${nom%%:*}"; dst="${nom##*:}"
  [ -f "$BRUT/$src.png" ] && mv "$BRUT/$src.png" "$ROOT/$SAISIE/$dst.png"
done
# modal-pin-avant.png n'est PAS regenere : c'est la capture du defaut AVANT correctif
# (le modal qui ne s'ouvrait pas). Le code corrige ne peut plus la produire ; elle reste
# versionnee telle quelle comme trace du diagnostic.
mv "$BRUT"/*.png "$ROOT/$RESPONSIVE/"
rmdir "$BRUT" 2>/dev/null || true

echo "[captures] deposees :"
ls -1 "$ROOT/$RESPONSIVE" | wc -l | xargs echo "  $RESPONSIVE :"
ls -1 "$ROOT/$SAISIE" | wc -l | xargs echo "  $SAISIE :"
