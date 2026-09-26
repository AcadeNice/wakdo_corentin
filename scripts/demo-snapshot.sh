#!/usr/bin/env bash
#
# Wakdo - instantane de reference pour la remise a zero de demo (scripts/demo-reset.sh).
#
# La production de Wakdo sert de demo publique (compte admin du seed accessible
# a n'importe qui) : n'importe quelle donnee peut etre abimee entre deux visites.
# Ce script fige un instantane VALIDE de l'etat courant - pas le seed d'origine,
# l'etat REEL de la base au moment ou l'utilisateur le juge bon (catalogue,
# comptes, mots de passe, PIN, historique...) - pour que demo-reset.sh puisse y
# revenir a volonte.
#
# IMPORTANT : figer l'instantane APRES le deploiement (toutes les migrations
# appliquees) et AVANT toute demonstration devant le jury. Refaire un instantane
# APRES le passage du jury, si des donnees ont ete modifiees pendant la demo,
# figerait ces donnees abimees comme nouvelle reference - a eviter.
#
# Capture, dans demo-snapshots/<horodatage-unique>/ (a la racine du depot, hors
# du code servi par wakdo-web/wakdo-app, ignore par git via /demo-snapshots/
# dans .gitignore - pas sous var/, qui est parfois cree root:root par le tout
# premier `docker compose up` via le bind-mount var/backups de wakdo-cron) :
#   db.sql.gz         dump complet (schema + donnees), --single-transaction
#   migrations.txt    fichiers de db/migrations/ appliques au moment du dump
#                     (permet a demo-reset.sh de detecter un schema plus recent)
#   counts.txt        nombre de lignes par table (verification post-reset)
#   uploads.tar.gz    images de src/public/uploads (volume wakdo_uploads),
#                     presentes seulement si ce dossier n'est pas vide (ces
#                     images ne sont ni versionnees ni dans les seeds)
#   meta.txt          horodatage, label, compose/projet/conteneurs cibles, compteurs
#
# Met a jour demo-snapshots/reference (lien symbolique) vers ce nouvel
# instantane : c'est LUI que demo-reset.sh restaure par defaut.
#
# Operation non destructive : lecture seule cote base (dump) et cote uploads
# (tar en lecture). Rien n'est ecrit dans la pile ciblee.
#
# Les fichiers ecrits (dump, PIN et hash de mots de passe compris) le sont avec
# des permissions restreintes (umask 077) : demo-snapshots/ n'est lisible que
# par le proprietaire.
#
# Usage :
#   scripts/demo-snapshot.sh -f <fichier-compose> [--label TEXTE] [--no-reference]
#   COMPOSE_FILE=docker-compose.prod.yml scripts/demo-snapshot.sh --label "avant oral"
#
# Options :
#   -f, --compose-file FICHIER   fichier docker-compose ciblant la pile (obligatoire,
#                                 pas de defaut implicite - variable COMPOSE_FILE acceptee)
#       --label TEXTE             annotation libre stockee dans meta.txt
#       --no-reference            ne pas deplacer le pointeur "reference" (instantane
#                                 ad hoc, gardé mais pas promu restauration par defaut)
#
# Variables d'environnement :
#   COMPOSE_FILE      equivalent a -f (surcharge par -f si les deux sont donnes)
#   COMPOSE_PROJECT   nom de projet compose (-p) ; utile UNIQUEMENT pour cibler
#                     une pile de VERIFICATION jetable a project-name distinct de
#                     la prod (les deux fichiers compose du depot declarent tous
#                     les deux `name: wakdo`)
#   COMPOSE_ENV_FILE  fichier .env alternatif passe a `docker compose --env-file`
#
# Un seul demo-snapshot.sh/demo-reset.sh a la fois sur ce depot (verrou partage,
# voir acquire_demo_lock dans la lib) : un second lancement pendant qu'une
# capture est en cours est refuse immediatement.
#
# Exit codes :
#   0 - instantane cree
#   1 - usage / compose manquant ou introuvable / pile injoignable / verrou pris
#   2 - le dump ou l'archive uploads a echoue (rien n'est ecrase : le dossier
#       partiel est supprime)

set -euo pipefail
umask 077

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
# shellcheck source=lib/demo-snapshot-lib.sh
. "$ROOT/scripts/lib/demo-snapshot-lib.sh"

# Les instantanes contiennent des hash de mots de passe et des PIN : umask 077
# (ci-dessus) protege tout ce que CE lancement cree ; ce chmod couvre en plus
# les dossiers de premier niveau s'ils existent deja (crees avant cette regle).
[ -d "$ROOT/demo-snapshots" ] && chmod 700 "$ROOT/demo-snapshots"
[ -d "$ROOT/demo-backups" ] && chmod 700 "$ROOT/demo-backups"

START_TS="$(date +%s)"
print_duration() {
    echo "Duree ecoulee : $(( $(date +%s) - START_TS ))s" >&2
}

COMPOSE_FILE="${COMPOSE_FILE:-}"
LABEL=""
SET_REFERENCE=1

while [ $# -gt 0 ]; do
    case "$1" in
        -f|--compose-file)
            COMPOSE_FILE="$2"; shift 2 ;;
        --label)
            LABEL="$2"; shift 2 ;;
        --no-reference)
            SET_REFERENCE=0; shift ;;
        -h|--help)
            sed -n '2,68p' "$0" | sed 's/^# \{0,1\}//'
            exit 0 ;;
        *)
            echo "ERREUR : option inconnue : $1" >&2
            exit 1 ;;
    esac
done

# La duree n'a de sens qu'une fois le travail reellement engage : elle
# n'apparait pas sur --help (deja sorti ci-dessus) ni avant ce point.
trap print_duration EXIT

require_compose_file
require_db_up
acquire_demo_lock

# Les deux fichiers compose du depot declarent `name: wakdo` : afficher la
# cible REELLE (projet + conteneurs), pas seulement le nom de fichier.
TARGET_INFO="$(resolve_target_info)"
TARGET_PROJECT="$(sed -n '1p' <<<"$TARGET_INFO")"
TARGET_DB_CONTAINER="$(sed -n '2p' <<<"$TARGET_INFO")"
TARGET_APP_CONTAINER="$(sed -n '3p' <<<"$TARGET_INFO")"

SNAP_ROOT="$ROOT/demo-snapshots"
mkdir -p "$SNAP_ROOT"
chmod 700 "$SNAP_ROOT"
STAMP="$(date +%Y%m%d_%H%M%S)"
# mktemp -d reserve un nom UNIQUE de facon atomique (suffixe aleatoire) : deux
# lancements a la meme seconde ne peuvent pas se disputer le meme dossier, et
# capture_snapshot n'a plus qu'a remplir un dossier vide qu'elle n'a pas eu a
# creer elle-meme.
SNAP_DIR="$(mktemp -d "$SNAP_ROOT/${STAMP}.XXXXXX")"

echo "Instantane de demo Wakdo"
echo "  compose  : $COMPOSE_FILE"
echo "  projet   : ${TARGET_PROJECT:-inconnu} (wakdo-db -> ${TARGET_DB_CONTAINER:-?}, wakdo-app -> ${TARGET_APP_CONTAINER:-?})"
echo "  cible    : $SNAP_DIR"
[ -n "$LABEL" ] && echo "  label    : $LABEL"
echo

if ! capture_snapshot "$SNAP_DIR" "$LABEL" "reference"; then
    echo >&2
    echo "ECHEC : instantane non cree, dossier partiel supprime." >&2
    rm -rf "$SNAP_DIR"
    exit 2
fi

echo
echo "Comptages par table :"
if command -v column >/dev/null 2>&1; then
    column -t -s $'\t' "$SNAP_DIR/counts.txt" | sed 's/^/  /'
else
    sed 's/^/  /' "$SNAP_DIR/counts.txt"
fi

SNAP_NAME="$(basename "$SNAP_DIR")"
if [ "$SET_REFERENCE" -eq 1 ]; then
    ln -sfn "$SNAP_NAME" "$SNAP_ROOT/reference"
    echo
    echo "Pointeur demo-snapshots/reference -> $SNAP_NAME"
else
    echo
    echo "Instantane cree SANS toucher au pointeur reference (--no-reference)."
    echo "Pour le promouvoir plus tard : ln -sfn $SNAP_NAME $SNAP_ROOT/reference"
fi

echo
echo "OK : $SNAP_DIR"
echo "Restauration : scripts/demo-reset.sh -f $COMPOSE_FILE [--snapshot $SNAP_NAME]"
