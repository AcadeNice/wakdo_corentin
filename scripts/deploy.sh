#!/usr/bin/env bash
#
# Wakdo - deploiement scripte (declenchement humain ; socle d'un CD pull-based).
#
# Strategie CD : deploiement DECLENCHE A LA MAIN pour l'instant (solo dev, prod
# unique). Ce script fiabilise l'operation (Cr 7.b.2). Il :
#   1. recupere la derniere `main` depuis Forgejo (git fetch + fast-forward) ;
#   2. RECONSTRUIT les images depuis les Dockerfiles -- les images wakdo
#      (apache / php-fpm / cron) sont buildees localement, il n'y a pas de registre,
#      donc on `build`, on ne `pull` pas ;
#   3. recree la stack.
# L'automatisation "pull-based" (un job cron cote hote qui detecte un nouveau `main`
# et lance ce script) est l'etape suivante : elle reutilisera ce meme script.
#
# A lancer SUR L'HOTE de prod, depuis la racine du depot :
#   scripts/deploy.sh [BRANCHE]                        (defaut : main)
#   GIT_REMOTE=origin scripts/deploy.sh                (override du remote git)
#   COMPOSE_FILE=docker-compose.yml scripts/deploy.sh  (override du fichier compose)
#
# Prerequis : le fichier compose cible (defaut docker-compose.prod.yml, gitignore,
# propre a l'hote) declare les services wakdo en `build:` (memes contextes que le
# docker-compose.yml standalone) et un .env de prod renseigne. Le service one-shot
# wakdo-migrate applique migrations + seed (idempotents) avant que l'app ne serve.
#
# Exit codes : 0 = OK ; 1 = prerequis manquant / confirmation refusee.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

BRANCH="${1:-main}"
# Remote : le canonique du projet est `forgejo` (git.acadenice.com) ; on le prefere
# s'il existe, sinon `origin` (clone standard). Surchargeable via GIT_REMOTE.
if [ -n "${GIT_REMOTE:-}" ]; then
    REMOTE="$GIT_REMOTE"
elif git remote | grep -qx forgejo; then
    REMOTE="forgejo"
else
    REMOTE="origin"
fi
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"

if [ ! -f "$COMPOSE_FILE" ]; then
    echo "deploy: $COMPOSE_FILE introuvable (fichier compose de l'hote)." >&2
    exit 1
fi

if ! command -v docker >/dev/null 2>&1; then
    echo "deploy: docker introuvable sur l'hote." >&2
    exit 1
fi

echo "Deploiement Wakdo : branche '$BRANCH' depuis '$REMOTE' via $COMPOSE_FILE"
# Mode non-interactif pour le CD : DEPLOY_YES=1 saute la confirmation (la forced
# command SSH le pose). On NE lit PAS $SSH_ORIGINAL_COMMAND : la cle CI ne peut
# influencer ni la branche ni le compose, seulement declencher CE script.
if [ "${DEPLOY_YES:-}" = "1" ] || [ "${DEPLOY_YES:-}" = "oui" ]; then
    echo "deploy: confirmation automatique (DEPLOY_YES)."
else
    printf 'Confirmer le deploiement en production ? [oui/NON] '
    read -r answer
    if [ "$answer" != "oui" ]; then
        echo "deploy: annule."
        exit 1
    fi
fi

echo "[1/5] recuperation de '$BRANCH' depuis '$REMOTE' (fast-forward only)"
git fetch --prune "$REMOTE" "$BRANCH"
git checkout "$BRANCH"
git merge --ff-only "$REMOTE/$BRANCH"

echo "[2/5] marqueur de version (preuve CD cote app)"
SHA="$(git rev-parse --short HEAD)"
NOW="$(date --iso-8601=seconds)"
# Sous src/ pour etre visible dans le conteneur (mount ./src -> /var/www/html),
# lu a chaud par GET /api/health. Journal d'historique a la racine du depot.
printf '%s %s\n' "$SHA" "$NOW" > src/VERSION
printf '[%s] deploy %s (branche %s)\n' "$NOW" "$SHA" "$BRANCH" >> deploy.log

echo "[3/5] reconstruction des images depuis les Dockerfiles (--pull rafraichit les bases)"
docker compose -f "$COMPOSE_FILE" build --pull

echo "[4/5] demarrage de la stack (migrate + seed idempotents puis app)"
docker compose -f "$COMPOSE_FILE" up -d

echo "[5/5] etat des services"
docker compose -f "$COMPOSE_FILE" ps

echo "Deploiement termine ($SHA)."
