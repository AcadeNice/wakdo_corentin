#!/usr/bin/env bash
#
# Wakdo - deploiement scripte (declenchement humain).
#
# Strategie CD du projet : le deploiement est volontairement DECLENCHE A LA MAIN
# (solo dev, un seul environnement de prod). Ce script fiabilise l'operation
# (Cr 7.b.2) ; il n'est PAS execute automatiquement par la CI. Un veritable
# deploiement continu (job Forgejo sur push main -> SSH -> ce script) reste a armer
# explicitement avec un secret de connexion, decision laissee a l'exploitant.
#
# A lancer SUR L'HOTE de prod, depuis la racine du depot :
#   scripts/deploy.sh [BRANCHE]   (defaut : main)
#
# Prerequis : docker-compose.prod.yml present (gitignore, propre a l'hote) et un
# .env de prod renseigne. Le service one-shot wakdo-migrate applique migrations +
# seed (idempotents) avant que l'app ne serve.
#
# Exit codes : 0 = OK ; 1 = prerequis manquant / confirmation refusee.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

BRANCH="${1:-main}"
REMOTE="${GIT_REMOTE:-origin}"
COMPOSE_FILE="docker-compose.prod.yml"

if [ ! -f "$COMPOSE_FILE" ]; then
    echo "deploy: $COMPOSE_FILE introuvable (fichier de prod, propre a l'hote)." >&2
    exit 1
fi

if ! command -v docker >/dev/null 2>&1; then
    echo "deploy: docker introuvable sur l'hote." >&2
    exit 1
fi

echo "Deploiement Wakdo : branche '$BRANCH' via $COMPOSE_FILE"
printf 'Confirmer le deploiement en production ? [oui/NON] '
read -r answer
if [ "$answer" != "oui" ]; then
    echo "deploy: annule."
    exit 1
fi

echo "[1/4] mise a jour du code (fast-forward only, remote: $REMOTE)"
git fetch --prune "$REMOTE" "$BRANCH"
git checkout "$BRANCH"
git merge --ff-only "$REMOTE/$BRANCH"

echo "[2/4] recuperation des images"
docker compose -f "$COMPOSE_FILE" pull

echo "[3/4] demarrage de la stack (migrate + seed idempotents puis app)"
docker compose -f "$COMPOSE_FILE" up -d

echo "[4/4] etat des services"
docker compose -f "$COMPOSE_FILE" ps

echo "Deploiement termine."
