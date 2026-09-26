#!/usr/bin/env bash
#
# Validation W3C REPRODUCTIBLE des deux niveaux de la borne, en une commande :
#
#   1. Niveau 1 - les 5 pages SERVIES (src/public/borne/*.html), telles que le serveur
#      les envoie. Le moteur Nu les lit sur disque, aucune pile n'est necessaire.
#   2. Niveau 2 - le DOM RENDU (JavaScript execute) des ecrans qui construisent leur
#      contenu cote client. Monte une pile JETABLE isolee, capture le document avec
#      Playwright (tests/e2e/w3c-capture.spec.js), puis soumet les captures au meme
#      moteur Nu.
#
#   tests/e2e/run-w3c.sh [dossier_de_sortie]
#
# Pre-requis : Docker. Aucune dependance Node/Playwright sur l'hote (tout en conteneur).
#
# POURQUOI ce script : jusqu'ici le niveau 2 etait capture a la main, sans trace
# versionnee. Les fichiers de w3c/dom-rendu/ ne pouvaient donc pas etre refaits a
# l'identique, et ils ont derive du code (voir la reserve datee de
# docs/soutenance/preuves/01-validation-w3c.md). Meme structure que run-a11y.sh :
# conteneur sous l'UID de l'appelant (artefacts non root), node_modules hors du depot,
# nom de projet dedie pour ne croiser ni la production ni une autre pile de test.
#
# AUCUNE commande n'est creee : la capture lit le document rendu. L'etat client (mode de
# consommation) est seme en localStorage cote navigateur. La production n'est jamais
# jointe : tout se passe contre la pile jetable montee ici.
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

SORTIE_REL="${1:-docs/soutenance/preuves/w3c}"
PROJECT=wakdow3c
PW_VERSION=1.49.1   # doit matcher devDependencies["@playwright/test"] de package.json
VNU_IMAGE=ghcr.io/validator/validator:latest
NET="${PROJECT}_wakdo_internal"

NM_DIR="${TMPDIR:-/tmp}/wakdo-w3c-node_modules"
mkdir -p "$NM_DIR" "$ROOT/$SORTIE_REL/dom-rendu"

ENVFILE="$(mktemp)"
cp .env.example "$ENVFILE"
# Hostnames de TEST en .test (pas .localhost) : Chromium resout *.localhost en dur vers
# 127.0.0.1 (RFC 6761) et ignore --add-host. .test n'est pas special -> joignable.
perl -pi -e 's/^APP_HOST_KIOSK=.*/APP_HOST_KIOSK=kiosk.wakdo.test/; s/^APP_HOST_ADMIN=.*/APP_HOST_ADMIN=admin.wakdo.test/;' "$ENVFILE"
COMPOSE="docker compose -p $PROJECT --env-file $ENVFILE -f docker-compose.yml -f tests/e2e/docker-compose.w3c.yml"

cleanup() { echo "[w3c] teardown"; $COMPOSE down -v >/dev/null 2>&1 || true; rm -f "$ENVFILE"; }
trap cleanup EXIT

# Lance le moteur Nu sur les fichiers d'un dossier et depose le JSON dans un artefact.
#
#   valider <dossier a monter> <artefact de sortie> <fichier> [fichier...]
#
# Deux precautions :
#  - vnu ecrit son rapport sur la sortie d'ERREUR, melangee ici a la ligne
#    "Picked up JAVA_TOOL_OPTIONS:" que la machine Java emet au demarrage. Non filtree,
#    elle atterrit en tete de l'artefact et le rend illisible par un analyseur JSON. On
#    ne garde donc que la ligne du rapport (celle qui commence par une accolade).
#  - vnu sort en 1 des qu'il a un message a rapporter. Un message N'EST PAS une panne du
#    script : c'est le resultat qu'on veut consigner. Le verdict se lit dans le JSON
#    depose, pas dans le code de sortie.
valider() {
  local dossier="$1" artefact="$2"; shift 2
  docker run --rm -v "$dossier":/data:ro --entrypoint java \
    "$VNU_IMAGE" -jar /vnu.jar --format json "$@" 2>&1 \
    | grep -a '^{' > "$ROOT/$artefact" || true
  cat "$ROOT/$artefact"
}

echo "[w3c] niveau 1 : les 5 pages servies"
valider "$ROOT/src/public/borne" "$SORTIE_REL/borne-statique.json" \
  /data/index.html /data/categories.html /data/products.html \
  /data/payment.html /data/confirmation.html

echo "[w3c] build + up pile jetable ($PROJECT)"
$COMPOSE up -d --build

echo "[w3c] attente migrate (completion)"
for _ in $(seq 1 40); do
  st="$(docker inspect -f '{{.State.Status}}' ${PROJECT}-migrate 2>/dev/null || echo NA)"
  code="$(docker inspect -f '{{.State.ExitCode}}' ${PROJECT}-migrate 2>/dev/null || echo NA)"
  [ "$st" = "exited" ] && [ "$code" = "0" ] && { echo "[w3c] migrate OK"; break; }
  [ "$st" = "exited" ] && [ "$code" != "0" ] && { echo "[w3c] migrate ECHEC ($code)"; docker logs ${PROJECT}-migrate; exit 1; }
  sleep 2
done

echo "[w3c] attente web healthy"
for _ in $(seq 1 40); do
  [ "$(docker inspect -f '{{.State.Health.Status}}' ${PROJECT}-web 2>/dev/null || echo NA)" = "healthy" ] && break
  sleep 2
done

WEB_IP="$(docker inspect -f "{{(index .NetworkSettings.Networks \"$NET\").IPAddress}}" ${PROJECT}-web)"
echo "[w3c] web @ $WEB_IP ($NET)"

echo "[w3c] niveau 2 : capture du DOM rendu (Playwright v$PW_VERSION)"
docker run --rm \
  --network "$NET" \
  --user "$(id -u):$(id -g)" \
  --add-host "kiosk.wakdo.test:$WEB_IP" \
  -v "$ROOT":/work \
  -v "$NM_DIR":/work/node_modules \
  -w /work \
  -e HOME=/tmp \
  -e npm_config_cache=/tmp/.npm \
  -e BASE_URL="http://kiosk.wakdo.test" \
  -e W3C_OUT="/work/$SORTIE_REL/dom-rendu" \
  -e CI=1 \
  "mcr.microsoft.com/playwright:v${PW_VERSION}-jammy" \
  bash -c "npm ci --no-audit --no-fund --silent && npx playwright test tests/e2e/w3c-capture.spec.js --reporter=line --output=/tmp/pw-artifacts"

echo "[w3c] niveau 2 : validation des captures"
# Deux artefacts distincts, comme dans le dossier de preuves : les trois ecrans d'un
# cote, la modale allergenes de l'autre (elle a sa propre entree dans la preuve 01).
valider "$ROOT/$SORTIE_REL/dom-rendu" "$SORTIE_REL/borne-rendu.json" \
  /data/accueil.html /data/categories.html /data/produits.html

valider "$ROOT/$SORTIE_REL/dom-rendu" "$SORTIE_REL/borne-modale-allergenes.json" \
  /data/produits-modale-allergenes.html

echo "[w3c] artefacts deposes dans $SORTIE_REL :"
ls -1 "$ROOT/$SORTIE_REL" "$ROOT/$SORTIE_REL/dom-rendu"
