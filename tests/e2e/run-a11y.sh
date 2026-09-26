#!/usr/bin/env bash
#
# Audit d'accessibilite MESURE : monte une stack JETABLE isolee, lance le moteur
# axe-core (via Playwright, conteneur officiel headless) contre elle, depose les
# artefacts dans le dossier de preuves, puis demonte tout.
#
#   tests/e2e/run-a11y.sh [dossier_de_sortie]
#
# Pre-requis : Docker. Aucune dependance Node/Playwright sur l'hote (tout en conteneur).
#
# POURQUOI un script separe de run.sh, qui monte deja une stack :
#   1. Il filtre sur le seul fichier a11y.spec.js et pose A11Y_OUT, donc lui seul
#      ecrit dans docs/. Un run.sh ordinaire joue la barriere d'accessibilite sans
#      reecrire le dossier de preuves.
#   2. Il tourne sous l'UID de l'appelant (--user) : les artefacts deposes dans
#      docs/ appartiennent a l'utilisateur, pas a root. run.sh n'ecrit rien de
#      versionnable et n'a pas ce besoin.
#   3. Il isole node_modules hors du depot : le npm du conteneur ne touche pas le
#      node_modules de l'hote (qui contient de l'outillage hors package.json).
#
# AUCUNE commande n'est creee pendant l'audit : axe lit le document rendu, l'etat
# panier est seme en localStorage cote client (voir a11y.spec.js). La production
# n'est jamais jointe : tout se passe contre la stack jetable montee ici.
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

SORTIE_REL="${1:-docs/soutenance/preuves/rapports}"
PROJECT=wakdoa11y
PW_VERSION=1.49.1   # doit matcher devDependencies["@playwright/test"] de package.json
NET="${PROJECT}_wakdo_internal"

# node_modules du conteneur, hors du depot. Sans ca, le `npm ci` du conteneur
# remplacerait le node_modules de l'hote (qui heberge aussi de l'outillage absent de
# package.json, donc considere comme surnumeraire et supprime).
NM_DIR="${TMPDIR:-/tmp}/wakdo-a11y-node_modules"
mkdir -p "$NM_DIR" "$ROOT/$SORTIE_REL"
# Purge des artefacts du passage precedent : un ecran renomme ou retire laisserait
# sinon son ancien fichier dans le dossier de preuves, et le resume (reconstruit depuis
# le disque) compterait un ecran qui n'est plus audite.
rm -f "$ROOT/$SORTIE_REL"/axe-*.json "$ROOT/$SORTIE_REL"/resume.json "$ROOT/$SORTIE_REL"/contrastes-mesures.csv

ENVFILE="$(mktemp)"
cp .env.example "$ENVFILE"   # template local-first : marche tel quel (valeurs dev)
# Hostnames de TEST en .test (pas .localhost) : Chromium/curl resolvent *.localhost en
# dur vers 127.0.0.1 (RFC 6761) et ignorent --add-host. .test n'est pas special -> joignable.
perl -pi -e 's/^APP_HOST_KIOSK=.*/APP_HOST_KIOSK=kiosk.wakdo.test/; s/^APP_HOST_ADMIN=.*/APP_HOST_ADMIN=admin.wakdo.test/;' "$ENVFILE"
COMPOSE="docker compose -p $PROJECT --env-file $ENVFILE -f docker-compose.yml -f tests/e2e/docker-compose.a11y.yml"

cleanup() { echo "[a11y] teardown"; $COMPOSE down -v >/dev/null 2>&1 || true; rm -f "$ENVFILE"; }
trap cleanup EXIT

echo "[a11y] build + up stack jetable ($PROJECT)"
$COMPOSE up -d --build

echo "[a11y] attente migrate (completion)"
for _ in $(seq 1 40); do
  st="$(docker inspect -f '{{.State.Status}}' ${PROJECT}-migrate 2>/dev/null || echo NA)"
  code="$(docker inspect -f '{{.State.ExitCode}}' ${PROJECT}-migrate 2>/dev/null || echo NA)"
  [ "$st" = "exited" ] && [ "$code" = "0" ] && { echo "[a11y] migrate OK"; break; }
  [ "$st" = "exited" ] && [ "$code" != "0" ] && { echo "[a11y] migrate ECHEC ($code)"; docker logs ${PROJECT}-migrate; exit 1; }
  sleep 2
done

echo "[a11y] attente web healthy"
for _ in $(seq 1 40); do
  [ "$(docker inspect -f '{{.State.Health.Status}}' ${PROJECT}-web 2>/dev/null || echo NA)" = "healthy" ] && break
  sleep 2
done

WEB_IP="$(docker inspect -f "{{(index .NetworkSettings.Networks \"$NET\").IPAddress}}" ${PROJECT}-web)"
echo "[a11y] web @ $WEB_IP ($NET)"

echo "[a11y] axe-core via Playwright (conteneur officiel v$PW_VERSION)"
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
  -e A11Y_OUT="/work/$SORTIE_REL" \
  -e CI=1 \
  "mcr.microsoft.com/playwright:v${PW_VERSION}-jammy" \
  bash -c "npm ci --no-audit --no-fund --silent && npx playwright test tests/e2e/a11y.spec.js --reporter=line --output=/tmp/pw-artifacts"

# --reporter=line / --output : le conteneur tourne sous l'UID de l'appelant (voir plus
# haut) alors que playwright-report/ et test-results/ du depot ont pu etre laisses a
# root par un run.sh precedent (celui-la tourne en root). On ecrit donc le rapport et
# les traces hors du depot ; les seuls fichiers qui doivent en sortir sont les
# artefacts de preuve, deposes par le test lui-meme dans A11Y_OUT.

echo "[a11y] artefacts deposes dans $SORTIE_REL :"
ls -1 "$ROOT/$SORTIE_REL"
