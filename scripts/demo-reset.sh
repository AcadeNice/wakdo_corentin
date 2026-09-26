#!/usr/bin/env bash
#
# Wakdo - remise a zero des donnees de demo, sur un INSTANTANE DE REFERENCE
# (pas sur le seed).
#
# La production de Wakdo est une demo publique : le compte admin du seed est
# connu de quiconque assiste a la soutenance, donc n'importe qui peut abimer les
# donnees (supprimer un produit, changer le mot de passe admin, creer de fausses
# commandes...). ATTENTION : ce script ne restaure PAS le jeu de seed d'origine.
# Il restaure le dernier instantane VALIDE par l'utilisateur (scripts/demo-snapshot.sh),
# qui peut differer du seed (prix ajustes, produits ajoutes, mot de passe demo
# choisi...). C'est l'etat que l'utilisateur a fige qui fait foi, pas le seed.
#
# Protocole, dans l'ordre REEL d'execution (identique en mode normal et en
# --dry-run, memes libelles [n/6]) :
#   [1/6] verification de compatibilite : instantane bien forme (y compris
#         l'integrite de son dump db.sql.gz ET de son archive uploads, verifiees
#         ICI, avant toute destruction), CIBLE (projet/conteneurs compose
#         resolus) et SCHEMA (schema_migrations de la base courante vs
#         migrations.txt de l'instantane) - REFUS si l'un ou l'autre diverge ;
#   [2/6] confirmation tapee "RESET" (sautee avec --yes) ;
#   [3/6] sauvegarde de securite de l'etat COURANT (avant tout ecrasement), au
#         meme format qu'un instantane, sous demo-backups/ - abandon total si
#         cette sauvegarde echoue ou parait suspecte ;
#   [4/6] restauration des DONNEES (dump complet de l'instantane : DROP/CREATE
#         des tables puis reinsertion - la base elle-meme n'est ni supprimee ni
#         recreee) ;
#   [5/6] images uploads : restaurees si l'instantane en contient (sinon le
#         dossier est vide, pour rester fidele a l'instantane) ;
#   [6/6] invalidation des sessions PHP actives, puis verification des
#         comptages par table.
#
# Le mode --dry-run s'arrete apres [1/6] (rien n'est modifie) et affiche le
# reste du plan sans l'executer.
#
# Aucun volume supprime ni recree, aucun conteneur recree, aucun reseau touche
# (pas de down -v, pas de recreation) : uniquement des `docker compose exec`
# dans les services deja demarres.
#
# Usage :
#   scripts/demo-reset.sh -f <fichier-compose> [--snapshot NOM_OU_CHEMIN] [--dry-run] [--yes]
#
# Options :
#   -f, --compose-file FICHIER   fichier docker-compose ciblant la pile (obligatoire)
#       --snapshot NOM|CHEMIN    instantane a restaurer : nom sous demo-snapshots/,
#                                 chemin direct (p.ex. une sauvegarde de securite
#                                 precedente, pour un "retour arriere" manuel), ou
#                                 defaut = demo-snapshots/reference
#       --dry-run                affiche ce qui serait fait, ne modifie rien
#       --yes                    saute la confirmation tapee "RESET" (non interactif)
#
# Variables d'environnement :
#   COMPOSE_FILE      equivalent a -f (surcharge par -f si les deux sont donnes)
#   COMPOSE_PROJECT   nom de projet compose (-p) ; utile UNIQUEMENT pour cibler
#                     une pile de VERIFICATION jetable a project-name distinct de
#                     la prod (les deux fichiers compose du depot declarent tous
#                     les deux `name: wakdo`)
#   COMPOSE_ENV_FILE  fichier .env alternatif passe a `docker compose --env-file`
#
# Il n'existe pas de script demo-restore.sh distinct : restaurer une sauvegarde
# (de securite ou un instantane plus ancien) se fait avec ce meme script via
# --snapshot <chemin>.
#
# Un seul demo-snapshot.sh/demo-reset.sh a la fois sur ce depot (verrou partage,
# voir acquire_demo_lock dans la lib) : un second lancement pendant qu'un reset
# est en cours est refuse immediatement (il ne se met pas en attente).
#
# Exit codes :
#   0 - reset effectue et verifie (ou dry-run sans anomalie)
#   1 - usage / compose manquant / pile injoignable / confirmation refusee /
#       verrou deja pris
#   2 - instantane cible introuvable ou invalide (y compris dump db.sql.gz ou
#       archive uploads corrompu - detecte avant toute destruction)
#   3 - schema incompatible (migration appliquee depuis l'instantane, ou inverse)
#   4 - la sauvegarde de securite a echoue - RIEN n'a ete touche
#   5 - la restauration a echoue en cours de route (donnees, uploads, invalidation
#       de session, ou sortie inattendue - signal, erreur non geree) - etat
#       intermediaire possible, voir le message
#   6 - verification post-restauration en echec (comptages divergents)
#   7 - la cible (projet/conteneur) resolue ne correspond pas a celle enregistree
#       dans l'instantane, ou n'a pas pu etre resolue alors que l'instantane
#       porte une identite de cible

set -euo pipefail
umask 077

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
# shellcheck source=lib/demo-snapshot-lib.sh
. "$ROOT/scripts/lib/demo-snapshot-lib.sh"

# Les instantanes/sauvegardes contiennent des hash de mots de passe et des
# PIN. umask 077 protege tout ce que CE lancement cree ; ce chmod couvre en
# plus les dossiers de premier niveau s'ils existent deja.
[ -d "$ROOT/demo-snapshots" ] && chmod 700 "$ROOT/demo-snapshots"
[ -d "$ROOT/demo-backups" ] && chmod 700 "$ROOT/demo-backups"

START_TS="$(date +%s)"
print_duration() {
    echo "Duree ecoulee : $(( $(date +%s) - START_TS ))s" >&2
}

# DESTRUCTION_STARTED bascule a 1 juste avant l'etape [4/6] (premiere ecriture
# destructive). WARNED bascule a 1 des que warn_inconsistent_state a deja
# affiche son message. Le trap EXIT s'appuie sur les deux : une sortie
# inattendue (signal, erreur non geree par `set -e`) APRES le debut de la
# destruction, qui n'a pas deja produit son propre message ECHEC, en produit un
# ici - sans ca, un tel cas restait silencieux (aucun ECHEC, aucun rappel de
# retour arriere), alors qu'un signal ou une erreur non geree peuvent survenir
# a tout moment, y compris entre deux etapes explicitement testees.
DESTRUCTION_STARTED=0
WARNED=0

# Commande de retour arriere exacte : reprend COMPOSE_PROJECT/COMPOSE_ENV_FILE
# quand ils sont definis, sinon la commande affichee ne fonctionnerait pas telle
# quelle sur une pile ciblee via ces variables (verification jetable).
rollback_cmd() {
    local cmd=""
    [ -n "${COMPOSE_PROJECT:-}" ] && cmd="${cmd}COMPOSE_PROJECT=$COMPOSE_PROJECT "
    [ -n "${COMPOSE_ENV_FILE:-}" ] && cmd="${cmd}COMPOSE_ENV_FILE=$COMPOSE_ENV_FILE "
    printf '%sscripts/demo-reset.sh -f %s --snapshot %s --yes' "$cmd" "$COMPOSE_FILE" "${BACKUP_DIR:-<sauvegarde-indisponible>}"
}

# Reutilisee par toutes les sorties d'echec qui suivent le debut de la
# destruction, et par le trap EXIT pour le cas non couvert explicitement.
warn_inconsistent_state() {
    local detail="${1:-}"
    WARNED=1
    echo >&2
    echo "ATTENTION : la restauration a ete interrompue APRES le debut de la destruction." >&2
    [ -n "$detail" ] && echo "            $detail." >&2
    echo "            La pile ciblee peut etre dans un etat INTERMEDIAIRE INCOHERENT." >&2
    echo "            Retour arriere vers l'etat d'avant tentative :" >&2
    echo "            $(rollback_cmd)" >&2
}

final_trap() {
    local rc=$?
    if [ "$rc" -ne 0 ] && [ "$DESTRUCTION_STARTED" -eq 1 ] && [ "$WARNED" -ne 1 ]; then
        echo "ECHEC : sortie inattendue apres le debut de la destruction (erreur non geree, code $rc)." >&2
        warn_inconsistent_state "cause exacte non identifiee - sortie hors d'un chemin d'erreur gere explicitement"
    fi
    print_duration
}

# Un signal externe (TERM/INT/HUP) recu pendant que bash attend un processus au
# premier plan (le cas de tous nos `docker compose exec`) ne fait pas remonter
# de facon fiable un $? non nul dans le trap EXIT ci-dessus (verifie : bash y
# lit 0 dans ce cas precis, meme si le processus se termine bien avec le code
# 128+signal). Un signal doit donc etre intercepte explicitement pour produire
# le meme avertissement que les sorties d'echec normales.
on_terminating_signal() {
    local sig="$1" code="$2"
    if [ "$DESTRUCTION_STARTED" -eq 1 ] && [ "$WARNED" -ne 1 ]; then
        echo >&2
        echo "ECHEC : signal $sig recu apres le debut de la destruction." >&2
        warn_inconsistent_state "interrompu par un signal ($sig) - etat non verifie au-dela de ce point"
    elif [ "$DESTRUCTION_STARTED" -eq 0 ] && [ -n "${BACKUP_DIR:-}" ] && [ -d "$BACKUP_DIR" ]; then
        # Signal recu pendant l'etape [3/6] (sauvegarde de securite), donc
        # AVANT toute destruction : rien n'a encore ete ecrase, mais le dossier
        # reserve par mktemp -d pour cette sauvegarde peut contenir une capture
        # incomplete. Il n'a pas encore servi de reference a quoi que ce soit
        # (aucun retour arriere annonce dessus), donc le supprimer est sans
        # risque - une capture complete devra etre reprise depuis le debut.
        echo >&2
        echo "Interrompu (signal $sig) avant toute destruction : sauvegarde de securite partielle supprimee ($BACKUP_DIR)." >&2
        rm -rf "$BACKUP_DIR"
    fi
    exit "$code"
}
trap 'on_terminating_signal TERM 143' TERM
trap 'on_terminating_signal INT 130' INT
trap 'on_terminating_signal HUP 129' HUP

COMPOSE_FILE="${COMPOSE_FILE:-}"
SNAPSHOT_ARG=""
DRY_RUN=0
YES=0

while [ $# -gt 0 ]; do
    case "$1" in
        -f|--compose-file)
            COMPOSE_FILE="$2"; shift 2 ;;
        --snapshot)
            SNAPSHOT_ARG="$2"; shift 2 ;;
        --dry-run)
            DRY_RUN=1; shift ;;
        --yes)
            YES=1; shift ;;
        -h|--help)
            sed -n '2,83p' "$0" | sed 's/^# \{0,1\}//'
            exit 0 ;;
        *)
            echo "ERREUR : option inconnue : $1" >&2
            exit 1 ;;
    esac
done

# La duree n'a de sens qu'une fois le travail reellement engage : elle
# n'apparait pas sur --help (deja sorti ci-dessus) ni avant ce point.
trap final_trap EXIT

require_compose_file
require_db_up
acquire_demo_lock

# Les deux fichiers compose du depot declarent `name: wakdo` - resoudre la
# cible REELLE (projet + conteneurs), pas seulement le nom de fichier.
TARGET_INFO="$(resolve_target_info)"
TARGET_PROJECT="$(sed -n '1p' <<<"$TARGET_INFO")"
TARGET_DB_CONTAINER="$(sed -n '2p' <<<"$TARGET_INFO")"
TARGET_APP_CONTAINER="$(sed -n '3p' <<<"$TARGET_INFO")"

SNAP_ROOT="$ROOT/demo-snapshots"

# Resolution de l'instantane cible : nom sous demo-snapshots/, chemin direct
# (absolu ou relatif, p.ex. une sauvegarde de securite pour un retour arriere),
# ou par defaut le pointeur "reference".
if [ -z "$SNAPSHOT_ARG" ]; then
    SNAPSHOT_DIR="$SNAP_ROOT/reference"
    SNAPSHOT_LABEL="reference"
elif [ -d "$SNAP_ROOT/$SNAPSHOT_ARG" ]; then
    SNAPSHOT_DIR="$SNAP_ROOT/$SNAPSHOT_ARG"
    SNAPSHOT_LABEL="$SNAPSHOT_ARG"
elif [ -d "$SNAPSHOT_ARG" ]; then
    SNAPSHOT_DIR="$(cd "$SNAPSHOT_ARG" && pwd)"
    SNAPSHOT_LABEL="$SNAPSHOT_ARG"
else
    echo "ERREUR : instantane introuvable : $SNAPSHOT_ARG (ni sous $SNAP_ROOT, ni comme chemin)." >&2
    exit 2
fi

if [ ! -e "$SNAPSHOT_DIR" ]; then
    echo "ERREUR : aucun instantane de reference (demo-snapshots/reference absent)." >&2
    echo "         Lancer d'abord : scripts/demo-snapshot.sh -f $COMPOSE_FILE" >&2
    exit 2
fi
# Verifie aussi l'integrite du dump db.sql.gz, et celle de l'archive uploads
# quand l'instantane est cense en contenir une : AVANT toute destruction,
# dry-run compris (la fonction est appelee ici, avant l'etape [1/6] et avant la
# branche --dry-run).
if ! validate_snapshot_dir "$SNAPSHOT_DIR"; then
    exit 2
fi

SNAP_CREATED_AT="$(meta_get "$SNAPSHOT_DIR" created_at)"
SNAP_LABEL_META="$(meta_get "$SNAPSHOT_DIR" label)"
SNAP_UPLOADS_INCLUDED="$(meta_get "$SNAPSHOT_DIR" uploads_included)"
SNAP_PROJECT="$(meta_get "$SNAPSHOT_DIR" compose_project)"
SNAP_DB_CONTAINER="$(meta_get "$SNAPSHOT_DIR" db_container)"

echo "Remise a zero des donnees de demo Wakdo"
echo "  compose        : $COMPOSE_FILE"
echo "  projet cible   : ${TARGET_PROJECT:-inconnu} (wakdo-db -> ${TARGET_DB_CONTAINER:-?}, wakdo-app -> ${TARGET_APP_CONTAINER:-?})"
echo "  instantane      : $SNAPSHOT_LABEL ($SNAPSHOT_DIR)"
echo "  pris le         : ${SNAP_CREATED_AT:-inconnu}"
[ -n "${SNAP_LABEL_META:-}" ] && echo "  annotation      : $SNAP_LABEL_META"
echo "  images uploads  : $([ "$SNAP_UPLOADS_INCLUDED" = yes ] && echo "restaurees depuis l'instantane" || echo "aucune dans l'instantane -> dossier uploads vide apres reset")"
echo

# --- [1/6] Verification de compatibilite (instantane + cible + schema) -------
# Lecture seule, executee dans les deux modes (dry-run compris).
echo "[1/6] verification de compatibilite (instantane + cible + schema) ..."

# Refus si la cible ne peut pas etre comparee alors que l'instantane porte une
# identite de cible : mieux vaut refuser que de supposer que "pas d'info =
# cible identique". Un instantane sans cette information (format anterieur a
# cette regle) reste tolere : rien a comparer, avertissement seulement.
if [ -n "$SNAP_PROJECT" ] && [ -z "$TARGET_PROJECT" ]; then
    echo "REFUS : impossible de resoudre la cible actuelle (projet/conteneurs), alors que" >&2
    echo "        l'instantane porte une identite de cible (projet=$SNAP_PROJECT)." >&2
    echo "        Diagnostic (lecture seule) : $(dc_display) ps" >&2
    exit 7
fi
if [ -n "$SNAP_PROJECT" ] && [ -n "$TARGET_PROJECT" ]; then
    if [ "$SNAP_PROJECT" != "$TARGET_PROJECT" ] || { [ -n "$SNAP_DB_CONTAINER" ] && [ -n "$TARGET_DB_CONTAINER" ] && [ "$SNAP_DB_CONTAINER" != "$TARGET_DB_CONTAINER" ]; }; then
        echo "REFUS : l'instantane a ete pris sur une cible differente de celle visee maintenant :" >&2
        echo "        instantane : projet=$SNAP_PROJECT conteneur wakdo-db=${SNAP_DB_CONTAINER:-?}" >&2
        echo "        cible actuelle : projet=$TARGET_PROJECT conteneur wakdo-db=${TARGET_DB_CONTAINER:-?}" >&2
        echo "        Verifier -f/--compose-file et COMPOSE_PROJECT avant de continuer." >&2
        exit 7
    fi
else
    echo "      (identite de cible non comparee : instantane sans cette information)" >&2
fi

LIVE_MIGRATIONS="$(db_migrations_list | sort)"
SNAP_MIGRATIONS="$(sort "$SNAPSHOT_DIR/migrations.txt")"

EXTRA_LIVE="$(comm -23 <(echo "$LIVE_MIGRATIONS") <(echo "$SNAP_MIGRATIONS") 2>/dev/null | sed '/^$/d' || true)"
EXTRA_SNAP="$(comm -13 <(echo "$LIVE_MIGRATIONS") <(echo "$SNAP_MIGRATIONS") 2>/dev/null | sed '/^$/d' || true)"

if [ -n "$EXTRA_LIVE" ]; then
    echo "REFUS : une ou plusieurs migrations ont ete appliquees DEPUIS la prise de l'instantane :" >&2
    echo "$EXTRA_LIVE" | sed 's/^/  + /' >&2
    echo "        Reprendre un instantane a jour avant de reset : scripts/demo-snapshot.sh -f $COMPOSE_FILE" >&2
    echo "        Ne figer ce nouvel instantane que sur des donnees SAINES - pas juste apres le passage" >&2
    echo "        du jury si des donnees ont ete modifiees pendant la demo (cela figerait ces donnees" >&2
    echo "        abimees comme nouvelle reference)." >&2
    exit 3
fi
if [ -n "$EXTRA_SNAP" ]; then
    echo "REFUS : l'instantane contient des migrations ABSENTES de la base courante :" >&2
    echo "$EXTRA_SNAP" | sed 's/^/  - /' >&2
    echo "        Le code deploie semble plus ancien que l'instantane choisi. Verifier le deploiement," >&2
    echo "        ou choisir un instantane plus ancien (--snapshot)." >&2
    exit 3
fi
echo "      instantane, cible et schema compatibles ($(echo "$LIVE_MIGRATIONS" | sed '/^$/d' | wc -l | tr -d '[:space:]') migration(s))."

if [ "$DRY_RUN" -eq 1 ]; then
    echo
    echo "[dry-run] etapes qui seraient executees (rien n'a ete modifie) :"
    echo "  [2/6] confirmation tapee \"RESET\" ($([ "$YES" -eq 1 ] && echo "sautee via --yes" || echo "requise"))"
    echo "  [3/6] sauvegarde de securite de l'etat courant -> demo-backups/<horodatage-unique>_pre-reset/"
    echo "  [4/6] restauration des donnees depuis $SNAPSHOT_DIR/db.sql.gz"
    if [ "$SNAP_UPLOADS_INCLUDED" = yes ]; then
        echo "  [5/6] restauration des images depuis $SNAPSHOT_DIR/uploads.tar.gz"
    else
        echo "  [5/6] vidage du dossier uploads (l'instantane n'en contient pas)"
    fi
    echo "  [6/6] invalidation des sessions PHP actives + verification des comptages"
    exit 0
fi

# --- [2/6] Confirmation -------------------------------------------------------
echo
echo "[2/6] confirmation ..."
if [ "$YES" -ne 1 ]; then
    printf 'Ceci va ECRASER les donnees courantes de la pile ciblee par l'"'"'instantane du %s.\n' "${SNAP_CREATED_AT:-?}"
    printf 'Une sauvegarde de securite de l'"'"'etat courant sera prise avant. Taper RESET pour confirmer : '
    read -r ANSWER
    if [ "$ANSWER" != "RESET" ]; then
        echo "Annule : confirmation non recue." >&2
        exit 1
    fi
else
    echo "      confirmation sautee (--yes)."
fi

# --- [3/6] Sauvegarde de securite de l'etat COURANT --------------------------
mkdir -p "$ROOT/demo-backups"
chmod 700 "$ROOT/demo-backups"
BACKUP_STAMP="$(date +%Y%m%d_%H%M%S)"
# mktemp -d reserve un nom UNIQUE de facon atomique : deux resets qui
# tomberaient sur le meme horodatage a la seconde ne peuvent plus se disputer
# le meme dossier - et donc plus jamais se faire supprimer l'un l'autre en cas
# d'echec de capture (rm -rf ne peut cibler qu'un dossier que CE lancement vient
# de reserver lui-meme, jamais une sauvegarde preexistante).
BACKUP_DIR="$(mktemp -d "$ROOT/demo-backups/${BACKUP_STAMP}_pre-reset.XXXXXX")"
echo
echo "[3/6] sauvegarde de securite de l'etat courant -> $BACKUP_DIR"
if ! capture_snapshot "$BACKUP_DIR" "pre-reset avant restauration de $SNAPSHOT_LABEL" "pre-reset"; then
    echo >&2
    echo "ECHEC : la sauvegarde de securite a echoue. AUCUNE donnee n'a ete touchee." >&2
    rm -rf "$BACKUP_DIR"
    exit 4
fi
echo "      sauvegarde OK ($(stat -c '%s' "$BACKUP_DIR/db.sql.gz" 2>/dev/null || stat -f '%z' "$BACKUP_DIR/db.sql.gz") octets)."
echo "      retour arriere possible : $(rollback_cmd)"

# --- [4/6] Restauration des donnees -------------------------------------------
# A PARTIR D'ICI, une erreur peut laisser la pile dans un etat intermediaire
# incoherent (destruction commencee) : chaque sortie qui suit rappelle la
# commande de retour arriere (y compris une sortie inattendue non geree
# explicitement, voir final_trap).
DESTRUCTION_STARTED=1
echo
echo "[4/6] restauration des donnees depuis l'instantane ..."
if ! gzip -dc "$SNAPSHOT_DIR/db.sql.gz" | dc exec -T wakdo-db sh -c '
        BIN="$(command -v mariadb || command -v mysql)"
        MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec "$BIN" --default-character-set=utf8mb4 -uroot "$MARIADB_DATABASE"
    ' 2>"$ROOT/demo-backups/.restore-stderr.log"; then
    echo "ECHEC : la restauration des donnees a echoue en cours de route." >&2
    cat "$ROOT/demo-backups/.restore-stderr.log" >&2
    warn_inconsistent_state "les tables peuvent etre partiellement restaurees (certaines au nouvel etat, d'autres a l'ancien, voire vides)"
    exit 5
fi
rm -f "$ROOT/demo-backups/.restore-stderr.log"
echo "      donnees restaurees."

# Comptages PRIS IMMEDIATEMENT apres la restauration (pas plus tard) : la borne
# kiosk ecrit dans customer_order/order_item/stock_movement sans authentification
# (voir DEMO_VOLATILE_TABLES) - plus on attend avant de compter, plus une
# commande reelle risque de survenir entre-temps et de fausser la verification.
# Le resultat n'est COMPARE qu'a l'etape [6/6], mais il est PRIS ici.
if ! POST_TABLES="$(db_table_list)"; then
    echo "ECHEC : impossible de lister les tables apres restauration (verification impossible)." >&2
    warn_inconsistent_state "les donnees ont ete restaurees mais n'ont pas pu etre verifiees"
    exit 5
fi
if ! POST_COUNTS="$(printf '%s\n' "$POST_TABLES" | db_counts_for_tables)"; then
    echo "ECHEC : le comptage post-restauration a echoue (verification impossible)." >&2
    warn_inconsistent_state "les donnees ont ete restaurees mais n'ont pas pu etre verifiees"
    exit 5
fi

# --- [5/6] Restauration / vidage des uploads ----------------------------------
# L'archive uploads a deja ete verifiee (taille, tar tzf) a l'etape [1/6], via
# validate_snapshot_dir - avant meme la restauration des donnees. Rien a
# revalider ici, seulement executer.
echo
echo "[5/6] images uploads ..."
if [ "$SNAP_UPLOADS_INCLUDED" = yes ]; then
    if ! dc exec -T wakdo-app sh -c '
            set -e
            find /var/www/html/public/uploads -mindepth 1 -type f -delete
            tar xzf - -C /var/www/html/public/uploads
        ' < "$SNAPSHOT_DIR/uploads.tar.gz"; then
        echo "ECHEC : la restauration des images uploads a echoue en cours de route." >&2
        warn_inconsistent_state "les donnees sont deja restaurees (etape [4/6]) ; les images uploads peuvent etre partiellement supprimees et/ou restaurees"
        exit 5
    fi
    echo "      images restaurees depuis l'instantane."
else
    if ! dc exec -T wakdo-app sh -c 'find /var/www/html/public/uploads -mindepth 1 -type f -delete' < /dev/null; then
        echo "ECHEC : le vidage du dossier uploads a echoue." >&2
        warn_inconsistent_state "les donnees sont deja restaurees (etape [4/6]) ; le dossier uploads peut etre partiellement vide"
        exit 5
    fi
    echo "      dossier uploads vide (l'instantane n'en contenait pas)."
fi

# --- [6/6] Invalidation des sessions puis verification ------------------------
echo
echo "[6/6] invalidation des sessions PHP actives, puis verification des comptages ..."
if ! dc exec -T wakdo-app sh -c 'rm -f /tmp/sess_*' < /dev/null; then
    echo "ECHEC : l'invalidation des sessions PHP a echoue." >&2
    warn_inconsistent_state "une session admin peut rester active malgre la restauration des donnees"
    exit 5
fi
echo "      sessions PHP purgees (stockage fichier par defaut du conteneur wakdo-app)."
echo "      limite connue : si session.save_path a ete personnalise ou qu'un autre"
echo "      backend de session est introduit plus tard, ce chemin devra etre adapte."

echo "      (tables exclues de la verification stricte, ecrites sans authentification par la borne : $DEMO_VOLATILE_TABLES)"

MISMATCH=0
if ! COUNTS_TMP="$(mktemp)"; then
    echo "ECHEC : impossible de creer un fichier temporaire pour la verification." >&2
    warn_inconsistent_state "les donnees ont ete restaurees mais n'ont pas pu etre verifiees"
    exit 5
fi
printf '%s\n' "$POST_COUNTS" > "$COUNTS_TMP"

if ! awk -F'\t' -v volatile="$DEMO_VOLATILE_TABLES" '
        BEGIN { n = split(volatile, v, " "); for (i = 1; i <= n; i++) skip[v[i]] = 1 }
        NR == FNR { post[$1] = $2; next }
        {
            if ($1 in skip) next
            if (!($1 in post)) {
                print "  ECART  " $1 " : absente apres restauration (attendu " $2 ")";
                bad = 1; next
            }
            if (post[$1] != $2) {
                print "  ECART  " $1 " : " post[$1] " ligne(s) apres restauration, " $2 " attendu(es)";
                bad = 1
            }
        }
        END { exit bad }
    ' "$COUNTS_TMP" "$SNAPSHOT_DIR/counts.txt" >&2; then
    MISMATCH=1
fi
rm -f "$COUNTS_TMP"

if [ "$SNAP_UPLOADS_INCLUDED" = yes ]; then
    EXPECTED_UPLOADS="$(meta_get "$SNAPSHOT_DIR" uploads_files)"
    ACTUAL_UPLOADS="$(uploads_file_count || echo 0)"
    if [ "$ACTUAL_UPLOADS" != "$EXPECTED_UPLOADS" ]; then
        echo "  ECART  uploads : $ACTUAL_UPLOADS fichier(s), $EXPECTED_UPLOADS attendu(s)" >&2
        MISMATCH=1
    fi
fi

echo
if [ "$MISMATCH" -eq 1 ]; then
    echo "ECHEC : des comptages divergent de l'instantane apres restauration (voir ci-dessus)." >&2
    warn_inconsistent_state "les donnees restaurees ne correspondent plus exactement a l'instantane sur au moins une table (hors tables volatiles exclues)"
    exit 6
fi

echo "OK : donnees revenues a l'instantane '$SNAPSHOT_LABEL' (pris le ${SNAP_CREATED_AT:-?})."
echo "     comptages verifies, sessions invalidees."
echo "     sauvegarde de l'etat pre-reset conservee : $BACKUP_DIR"
