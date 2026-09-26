#!/usr/bin/env bash
#
# Wakdo - fonctions partagees par scripts/demo-snapshot.sh et scripts/demo-reset.sh.
#
# Justification d'une lib partagee (plutot que deux scripts autonomes, comme le
# reste de scripts/) : la capture d'un instantane (dump BDD + comptages +
# uploads) sert a la fois pour l'instantane de reference et pour la sauvegarde
# de securite prise avant chaque reset. Un correctif de securite applique a un
# seul des deux chemins serait pire que le cout d'un fichier source de plus.
#
# A sourcer ainsi (jamais execute seul), apres avoir defini ROOT et COMPOSE_FILE :
#   ROOT="$(cd "$(dirname "$0")/.." && pwd)"
#   . "$ROOT/scripts/lib/demo-snapshot-lib.sh"
#
# Les identifiants (MARIADB_ROOT_PASSWORD, MARIADB_DATABASE) sont lus DANS le
# conteneur wakdo-db, depuis son propre environnement pose par docker-compose,
# et passes au client mariadb via MYSQL_PWD (jamais via `-p"$MOT_DE_PASSE"`, qui
# apparaitrait dans les arguments du process visible par `docker top`/`ps`).

set -euo pipefail

# Tables ecrites par la borne kiosk SANS authentification (customer_order,
# order_item, stock_movement, et par ricochet login_throttle/pin_throttle/
# audit_log alimentes par le trafic normal). Leur comptage a la capture peut
# differer de ce que contient reellement db.sql.gz si du trafic ecrit entre le
# debut du dump et la requete de comptage separee qui suit (voir capture_snapshot,
# qui recompte avant/apres le dump et recommence en cas d'ecart). Cote
# demo-reset.sh, un ecart post-restauration sur ces tables ne signale donc pas
# une restauration ratee : elles restent exclues de la comparaison stricte.
DEMO_VOLATILE_TABLES="login_throttle audit_log pin_throttle"

# Retire les lignes "table<TAB>count" (stdin) dont la table est dans
# DEMO_VOLATILE_TABLES. Sert a comparer deux comptages en ignorant les tables
# a fort trafic - sans ca, une simple connexion (login_throttle) pendant une
# capture ferait boucler ou echouer la capture pour une table qui n'est de
# toute facon jamais comparee strictement au moment du reset.
strip_volatile_tables() {
    awk -F'\t' -v volatile="$DEMO_VOLATILE_TABLES" '
        BEGIN { n = split(volatile, v, " "); for (i = 1; i <= n; i++) skip[v[i]] = 1 }
        !($1 in skip)
    '
}

# --- Invocation docker compose ------------------------------------------------
#
# COMPOSE_FILE doit etre pose par l'appelant (refus explicite sinon, voir
# require_compose_file). COMPOSE_PROJECT (-p) et COMPOSE_ENV_FILE (--env-file)
# permettent de cibler une pile de verification jetable a project-name distinct
# de la prod (les deux fichiers compose du depot declarent `name: wakdo`).
#
# `9>&-` : ferme le descripteur du verrou de reset (voir acquire_demo_lock) pour
# tout processus lance ici. Sans ca, chaque `docker compose exec` heriterait du
# descripteur ouvert par le script appelant ; un sous-processus qui survivrait
# au script (peu probable mais pas exclu) garderait alors le verrou pris apres
# la fin du script. Redirection inoffensive si le descripteur n'est pas ouvert.
dc() {
    docker compose -f "$COMPOSE_FILE" \
        ${COMPOSE_PROJECT:+-p "$COMPOSE_PROJECT"} \
        ${COMPOSE_ENV_FILE:+--env-file "$COMPOSE_ENV_FILE"} \
        "$@" 9>&-
}

# Commande a afficher dans les messages (ne l'execute pas) : reprend les memes
# options que dc() pour que le diagnostic suggere a l'utilisateur soit copiable
# tel quel.
dc_display() {
    printf 'docker compose -f %s' "$COMPOSE_FILE"
    [ -n "${COMPOSE_PROJECT:-}" ] && printf ' -p %s' "$COMPOSE_PROJECT"
    [ -n "${COMPOSE_ENV_FILE:-}" ] && printf ' --env-file %s' "$COMPOSE_ENV_FILE"
}

# Refuse de partir sans fichier compose EXPLICITE : une remise a zero ne doit
# pas deviner sa cible (contrairement a scripts/deploy.sh, qui a un defaut).
require_compose_file() {
    if [ -z "${COMPOSE_FILE:-}" ]; then
        echo "ERREUR : fichier compose non specifie (-f/--compose-file, ou variable COMPOSE_FILE)." >&2
        echo "         Ce script refuse de deviner la pile ciblee." >&2
        exit 1
    fi
    if [ ! -f "$COMPOSE_FILE" ]; then
        echo "ERREUR : fichier compose introuvable : $COMPOSE_FILE" >&2
        exit 1
    fi
}

# wakdo-db doit repondre pour la suite (dump, comptages, restauration). Les
# fonctions d'introspection ci-dessous passent toutes `-T < /dev/null` : sans ce
# stdin explicite, `docker compose exec -T` herite du stdin REEL du script
# appelant (la confirmation "RESET" attendue plus loin dans demo-reset.sh) et
# peut le consommer avant que le script y arrive. Seuls les exec qui attendent
# vraiment des donnees (restauration du dump, des uploads, requete de comptage
# construite localement) recoivent un stdin different de /dev/null.
require_db_up() {
    if ! dc exec -T wakdo-db true < /dev/null 2>/dev/null; then
        echo "ERREUR : le service wakdo-db ne repond pas via $COMPOSE_FILE." >&2
        echo "         Diagnostic (lecture seule, ne modifie rien) : $(dc_display) ps" >&2
        exit 1
    fi
}

# Verrou exclusif partage par demo-snapshot.sh et demo-reset.sh : une capture
# (instantane ou sauvegarde de securite) lit l'etat de wakdo-db et wakdo-app a
# un instant donne, une deuxieme capture ou un reset concurrent lirait un etat
# en train de changer. Le PID du detenteur est ecrit dans le fichier de verrou,
# pour qu'un refus indique QUI le detient. Necessite ROOT (defini par l'appelant
# avant de sourcer cette lib).
acquire_demo_lock() {
    local lock_file="$ROOT/.demo-reset.lock" holder_pid
    # `>>` (pas `>`) : ne tronque PAS le fichier a l'ouverture. Un `9>` tronque
    # des l'ouverture, MEME quand le flock qui suit echoue ensuite - ce qui
    # effacerait le PID que le detenteur reel vient d'y ecrire, juste parce
    # qu'un second processus a tente (et rate) l'acquisition. Une fois le
    # verrou obtenu, le contenu est explicitement remplace par notre PID via
    # une ecriture separee (`>`, tronquante) : elle n'affecte pas le verrou lui
    # meme, qui est attache au descripteur 9, pas au chemin.
    exec 9>>"$lock_file"
    if ! flock -n 9; then
        holder_pid="$(cat "$lock_file" 2>/dev/null)"
        echo "ERREUR : une autre operation demo-snapshot.sh/demo-reset.sh est deja en cours sur ce depot" >&2
        echo "         (verrou $lock_file${holder_pid:+, PID $holder_pid})." >&2
        exit 1
    fi
    printf '%s\n' "$$" > "$lock_file"
}

# Binaire client / dump disponible dans l'image mariadb:11.4 (mariadb-dump /
# mariadb ; repli sur mysqldump / mysql si l'image utilisee est plus ancienne).
db_dump_bin() {
    dc exec -T wakdo-db sh -c 'command -v mariadb-dump || command -v mysqldump' < /dev/null
}

db_client_bin() {
    dc exec -T wakdo-db sh -c 'command -v mariadb || command -v mysql' < /dev/null
}

# --- Identite de la cible ------------------------------------------------------
#
# Les deux fichiers compose du depot declarent `name: wakdo` : le nom de projet
# seul ne distingue donc pas deux piles differentes (dev/prod, ou une pile de
# verification jetable). resolve_target_info() lit le PROJET et les CONTENEURS
# reels via `docker inspect` (labels com.docker.compose.project), independamment
# du fichier compose utilise. Sortie : 3 lignes (projet, conteneur wakdo-db,
# conteneur wakdo-app), vides si indisponible. Ne fait pas echouer l'appelant :
# le blocage eventuel est decide par l'appelant (demo-reset.sh), pas ici.
resolve_target_info() {
    local db_cid app_cid project="" db_name="" app_name=""
    db_cid="$(dc ps -q wakdo-db 2>/dev/null | head -1)"
    app_cid="$(dc ps -q wakdo-app 2>/dev/null | head -1)"
    if [ -n "$db_cid" ]; then
        project="$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project" }}' "$db_cid" 2>/dev/null || true)"
        db_name="$(docker inspect -f '{{ .Name }}' "$db_cid" 2>/dev/null | sed 's#^/##')"
    fi
    if [ -n "$app_cid" ]; then
        app_name="$(docker inspect -f '{{ .Name }}' "$app_cid" 2>/dev/null | sed 's#^/##')"
    fi
    printf '%s\n%s\n%s\n' "$project" "$db_name" "$app_name"
}

# --- Introspection (lecture seule, non destructive) ---------------------------

# Liste des tables BASE TABLE, une par ligne, triee.
db_table_list() {
    dc exec -T wakdo-db sh -c '
        BIN="$(command -v mariadb || command -v mysql)"
        MYSQL_PWD="$MARIADB_ROOT_PASSWORD" "$BIN" -N -B -uroot "$MARIADB_DATABASE" -e \
            "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = '"'"'BASE TABLE'"'"' ORDER BY table_name;"
    ' < /dev/null
}

# Liste des migrations appliquees (table schema_migrations), une par ligne, triee.
# Table absente (base jamais migree) -> liste vide, pas une erreur.
db_migrations_list() {
    dc exec -T wakdo-db sh -c '
        BIN="$(command -v mariadb || command -v mysql)"
        MYSQL_PWD="$MARIADB_ROOT_PASSWORD" "$BIN" -N -B -uroot "$MARIADB_DATABASE" -e \
            "SELECT filename FROM schema_migrations ORDER BY filename;" 2>/dev/null || true
    ' < /dev/null
}

# Comptage exact (SELECT COUNT(*), pas les stats approximatives d'InnoDB) de
# chaque table listee en entree (une par ligne sur stdin). Sortie :
# "table<TAB>count", une ligne par table, dans l'ordre d'entree.
#
# Le code de retour de la requete est explicitement conserve (rc) puis restitue
# apres le menage du fichier temporaire : sans ca, le dernier `rm` (qui reussit
# presque toujours) masquerait un echec de la requete elle-meme.
db_counts_for_tables() {
    local tmp rc
    tmp="$(mktemp)"
    {
        local first=1 t
        while IFS= read -r t; do
            [ -z "$t" ] && continue
            if [ "$first" = 1 ]; then first=0; else echo "UNION ALL"; fi
            printf "SELECT '%s' AS table_name, COUNT(*) AS row_count FROM \`%s\`\n" "$t" "$t"
        done
        echo ";"
    } > "$tmp"
    dc exec -T wakdo-db sh -c '
        BIN="$(command -v mariadb || command -v mysql)"
        MYSQL_PWD="$MARIADB_ROOT_PASSWORD" "$BIN" -N -B -uroot "$MARIADB_DATABASE"
    ' < "$tmp"
    rc=$?
    rm -f "$tmp"
    return "$rc"
}

# Nombre de fichiers reguliers dans le volume uploads.
#
# Doit echouer (statut non nul, rien sur stdout) si wakdo-app ne repond pas -
# jamais retourner silencieusement "0". Un wakdo-app injoignable a la capture
# ne doit pas se traduire par un instantane marque "aucune image" : un reset
# ulterieur effacerait alors des images bien reelles pour la seule raison
# qu'on n'a pas pu les compter cette fois-la. L'appelant (capture_snapshot)
# traite tout echec ici comme un echec de capture, jamais comme "0 image".
uploads_file_count() {
    local out
    if ! out="$(dc exec -T wakdo-app sh -c 'find /var/www/html/public/uploads -type f | wc -l' < /dev/null 2>/dev/null)"; then
        return 1
    fi
    out="$(printf '%s' "$out" | tr -d '[:space:]')"
    case "$out" in
        ''|*[!0-9]*) return 1 ;;
    esac
    printf '%s\n' "$out"
}

# --- Capture d'un instantane ---------------------------------------------------
#
# capture_snapshot <dossier_cible> <label> <kind>
#   kind = "reference" (demo-snapshot.sh) | "pre-reset" (sauvegarde de securite
#   automatique de demo-reset.sh). Purement informatif (meta.txt).
#
# Ecrit dans <dossier_cible> : db.sql.gz, migrations.txt, counts.txt, meta.txt,
# et uploads.tar.gz si le volume uploads contient au moins un fichier. N'ecrit
# rien en dehors de ce dossier (aucune ecriture en base, lecture seule cote
# BDD/uploads).
#
# <dossier_cible> doit exister et etre VIDE, ou ne pas exister du tout (dans ce
# second cas il est cree ici). Un dossier non vide est refuse : cela permet a
# l'appelant de reserver un nom unique via `mktemp -d` (evite toute collision
# entre deux captures qui tomberaient sur le meme horodatage a la seconde) tout
# en laissant cette fonction remplir le contenu.
#
# Cette fonction est appelee par ses deux appelants sous `if ! capture_snapshot`
# - un contexte ou bash suspend `set -e` pour tout ce qui s'execute A
# L'INTERIEUR de l'appel (regle bash : une commande simple faisant partie de la
# condition d'un if/while/until, ou niee par `!`, ne declenche pas errexit).
# CHAQUE etape ci-dessous verifie donc explicitement son propre statut de
# sortie et `return 2` au premier echec.
capture_snapshot() {
    local target="$1" label="$2" kind="$3"
    local dump_bin uploads_n table_count migrations_count created_at
    local target_project target_db_container target_app_container

    if [ -e "$target" ]; then
        if [ -n "$(ls -A "$target" 2>/dev/null)" ]; then
            echo "ERREUR : $target existe deja et n'est pas vide - abandon (pas d'ecrasement d'un contenu existant)." >&2
            return 2
        fi
    elif ! mkdir "$target" 2>/dev/null; then
        echo "ERREUR : impossible de creer $target - abandon." >&2
        return 2
    fi

    # meta.txt est un fichier key=value ligne a ligne : un label multi-lignes
    # casserait le parsing (meta_get). On l'aplatit en une seule ligne.
    label="${label//$'\n'/ }"

    target_project=""
    target_db_container=""
    target_app_container=""
    {
        IFS= read -r target_project
        IFS= read -r target_db_container
        IFS= read -r target_app_container
    } < <(resolve_target_info) || true

    if ! dump_bin="$(db_dump_bin)" || [ -z "$dump_bin" ]; then
        echo "ERREUR : aucun binaire mariadb-dump/mysqldump trouve dans wakdo-db - abandon." >&2
        return 2
    fi

    if ! db_table_list > "$target/.tables.txt"; then
        echo "ERREUR : impossible de lister les tables de la base - abandon." >&2
        return 2
    fi
    table_count="$(wc -l < "$target/.tables.txt" | tr -d '[:space:]')"
    if [ -z "$table_count" ] || [ "$table_count" -eq 0 ]; then
        echo "ERREUR : la base ne contient aucune table (table_count=0) - abandon." >&2
        rm -f "$target/.tables.txt"
        return 2
    fi

    created_at="$(date -Iseconds)"

    # La borne kiosk ecrit dans customer_order/order_item/stock_movement sans
    # authentification (voir DEMO_VOLATILE_TABLES) : une commande peut arriver
    # pendant la capture. Le dump (--single-transaction) fixe sa propre vue au
    # debut ; le comptage est une requete separee, apres coup. Pour que
    # counts.txt decrive fidelement ce que contient reellement le dump, on
    # compte AVANT et APRES le dump et on recommence si les deux different.
    local attempt=1 max_attempts=3 pre_counts post_counts create_table_n dump_size last_line
    while :; do
        if ! pre_counts="$(db_counts_for_tables < "$target/.tables.txt")"; then
            echo "ERREUR : le comptage (avant dump) a echoue - abandon." >&2
            rm -f "$target/.tables.txt"
            return 2
        fi

        echo "  - dump de la base (${dump_bin}, --single-transaction), tentative ${attempt}/${max_attempts} ..." >&2
        if ! dc exec -T wakdo-db sh -c '
                MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec '"$dump_bin"' \
                    --single-transaction --routines --triggers --no-tablespaces \
                    --default-character-set=utf8mb4 \
                    -uroot "$MARIADB_DATABASE"
            ' < /dev/null 2>"$target/.dump-stderr.log" > "$target/.dump.sql"; then
            echo "ERREUR : le dump de la base a echoue :" >&2
            cat "$target/.dump-stderr.log" >&2
            rm -f "$target/.dump.sql" "$target/.tables.txt"
            return 2
        fi
        rm -f "$target/.dump-stderr.log"

        dump_size="$(wc -c < "$target/.dump.sql" | tr -d '[:space:]')"
        if [ -z "$dump_size" ] || [ "$dump_size" -lt 1024 ]; then
            echo "ERREUR : dump suspect (${dump_size:-0} octets) - abandon." >&2
            rm -f "$target/.dump.sql" "$target/.tables.txt"
            return 2
        fi
        last_line="$(tail -1 "$target/.dump.sql")"
        case "$last_line" in
            "-- Dump completed"*) : ;;
            *)
                echo "ERREUR : le dump ne se termine pas par '-- Dump completed' (troncature ?) - abandon." >&2
                rm -f "$target/.dump.sql" "$target/.tables.txt"
                return 2
                ;;
        esac
        create_table_n="$(grep -c '^CREATE TABLE' "$target/.dump.sql" || true)"
        if [ "$create_table_n" -ne "$table_count" ]; then
            echo "ERREUR : $create_table_n CREATE TABLE dans le dump, $table_count tables vivantes - ecart, abandon." >&2
            rm -f "$target/.dump.sql" "$target/.tables.txt"
            return 2
        fi

        if ! post_counts="$(db_counts_for_tables < "$target/.tables.txt")"; then
            echo "ERREUR : le comptage (apres dump) a echoue - abandon." >&2
            rm -f "$target/.dump.sql" "$target/.tables.txt"
            return 2
        fi

        # Comparaison HORS tables volatiles : une connexion (login_throttle),
        # une purge cron ou une ligne d'audit pendant la capture ne doit pas a
        # elle seule faire boucler ou echouer la capture, puisque ces tables ne
        # sont de toute facon jamais comparees strictement au moment du reset.
        if [ "$(printf '%s\n' "$pre_counts" | strip_volatile_tables)" = "$(printf '%s\n' "$post_counts" | strip_volatile_tables)" ]; then
            break
        fi
        if [ "$attempt" -ge "$max_attempts" ]; then
            echo "ERREUR : comptages divergents avant/apres le dump apres ${max_attempts} tentatives (ecritures concurrentes, ex. commandes borne) - abandon." >&2
            rm -f "$target/.dump.sql" "$target/.tables.txt"
            return 2
        fi
        echo "  - comptages differents avant/apres le dump (ecriture concurrente pendant la capture) - nouvelle tentative ..." >&2
        attempt=$((attempt + 1))
    done
    rm -f "$target/.tables.txt"

    if ! gzip -9 -c "$target/.dump.sql" > "$target/db.sql.gz"; then
        echo "ERREUR : compression du dump echouee - abandon." >&2
        rm -f "$target/.dump.sql" "$target/db.sql.gz"
        return 2
    fi
    rm -f "$target/.dump.sql"
    if ! gzip -t "$target/db.sql.gz" 2>/dev/null; then
        echo "ERREUR : db.sql.gz corrompu (gzip -t a echoue) - abandon." >&2
        rm -f "$target/db.sql.gz"
        return 2
    fi

    if ! db_migrations_list > "$target/migrations.txt"; then
        echo "ERREUR : impossible de lister les migrations appliquees - abandon." >&2
        return 2
    fi
    migrations_count="$(wc -l < "$target/migrations.txt" | tr -d '[:space:]')"

    printf '%s\n' "$post_counts" > "$target/counts.txt"
    if [ ! -s "$target/counts.txt" ]; then
        echo "ERREUR : counts.txt est vide - abandon." >&2
        return 2
    fi

    if ! uploads_n="$(uploads_file_count)"; then
        echo "ERREUR : wakdo-app ne repond pas (comptage des uploads impossible) - abandon." >&2
        echo "         Diagnostic (lecture seule) : $(dc_display) ps" >&2
        return 2
    fi

    if [ "$uploads_n" -gt 0 ]; then
        echo "  - archive des images uploads (${uploads_n} fichier(s)) ..." >&2
        if ! dc exec -T wakdo-app sh -c 'tar czf - -C /var/www/html/public/uploads .' < /dev/null > "$target/uploads.tar.gz"; then
            echo "ERREUR : l'archivage des images uploads (tar) a echoue - abandon." >&2
            rm -f "$target/uploads.tar.gz"
            return 2
        fi
        if [ ! -s "$target/uploads.tar.gz" ] || ! tar tzf "$target/uploads.tar.gz" >/dev/null 2>&1; then
            echo "ERREUR : uploads.tar.gz vide ou illisible apres archivage - abandon." >&2
            rm -f "$target/uploads.tar.gz"
            return 2
        fi
    else
        echo "  - aucune image dans uploads, pas d'archive." >&2
        rm -f "$target/uploads.tar.gz" 2>/dev/null || true
    fi

    {
        printf 'created_at=%s\n'        "$created_at"
        printf 'label=%s\n'             "${label:-}"
        printf 'kind=%s\n'              "$kind"
        printf 'compose_file=%s\n'      "$COMPOSE_FILE"
        printf 'compose_project=%s\n'   "$target_project"
        printf 'db_container=%s\n'      "$target_db_container"
        printf 'app_container=%s\n'     "$target_app_container"
        printf 'table_count=%s\n'       "$table_count"
        printf 'migrations_count=%s\n'  "$migrations_count"
        printf 'uploads_files=%s\n'     "$uploads_n"
        printf 'uploads_included=%s\n'  "$([ "$uploads_n" -gt 0 ] && echo yes || echo no)"
        printf 'tool_version=3\n'
    } > "$target/meta.txt"

    if [ ! -s "$target/meta.txt" ]; then
        echo "ERREUR : meta.txt n'a pas pu etre ecrit - abandon, instantane incomplet." >&2
        return 2
    fi

    return 0
}

# Verifie qu'un dossier a la forme d'un instantane produit par capture_snapshot,
# ET que son archive uploads (si l'instantane est cense en contenir une) est
# exploitable. Appele AVANT toute etape destructive (y compris en --dry-run) :
# une archive vide/illisible doit etre detectee avant que quoi que ce soit
# n'ait ete ecrase, pas seulement au moment ou on tente de l'utiliser.
validate_snapshot_dir() {
    local dir="$1" uploads_included
    for f in db.sql.gz migrations.txt counts.txt meta.txt; do
        if [ ! -f "$dir/$f" ]; then
            echo "ERREUR : instantane invalide, $f absent de $dir" >&2
            return 1
        fi
    done
    uploads_included="$(meta_get "$dir" uploads_included)"
    if [ "$uploads_included" = yes ]; then
        if [ ! -s "$dir/uploads.tar.gz" ]; then
            echo "ERREUR : instantane invalide, uploads.tar.gz absent ou vide alors que l'instantane devrait contenir des images ($dir)" >&2
            return 1
        fi
        if ! tar tzf "$dir/uploads.tar.gz" >/dev/null 2>&1; then
            echo "ERREUR : instantane invalide, uploads.tar.gz illisible - tar tzf a echoue ($dir)" >&2
            return 1
        fi
    fi
    return 0
}

# Lit une cle de meta.txt (renvoie vide si absente - compatible avec un
# instantane cree avant l'ajout d'un champ).
#
# `|| true` : sous `pipefail` (actif dans les deux scripts appelants), un grep
# SANS MATCH (cle absente) sort en 1, ce qui ferait echouer toute la pipeline et,
# hors contexte if/while, terminerait le script appelant via `set -e` -
# silencieusement, avant meme d'afficher un message.
meta_get() {
    local dir="$1" key="$2"
    grep -E "^${key}=" "$dir/meta.txt" 2>/dev/null | head -1 | cut -d= -f2- || true
}
