#!/usr/bin/env bash
#
# Wakdo - tests de scripts/lib/demo-snapshot-lib.sh.
#
# Couvre les deux controles joues a l'etape [1/6] de scripts/demo-reset.sh
# AVANT toute destruction (mode --dry-run compris) :
#
#   - validate_snapshot_dir : un instantane corrompu doit etre refuse LA, pas au
#     milieu de l'etape [4/6] quand les DROP/CREATE ont deja commence et que la
#     base est a moitie ecrasee ;
#   - rollback_escape_granted / schema_decision : la sortie de secours du retour
#     arriere. Une restauration interrompue AVANT d'avoir remis schema_migrations
#     en place laisse la base a un schema hybride ; le retour arriere vers la
#     sauvegarde de securite que l'outil vient de prendre doit rester possible,
#     sans pour autant desarmer le garde-fou de compatibilite pour un instantane
#     quelconque.
#
# Aucun conteneur demarre, aucune base touchee : les fonctions exercees ici ne
# lisent que des fichiers. C'est ce qui permet de les faire tourner en CI sans
# service MariaDB (job shell-tests de .forgejo/workflows/ci.yml).
#
# Lancement : tests/shell/demo-snapshot-lib.test.sh
#
# Exit codes : 0 = toutes les assertions passent ; 1 = au moins une echoue.

set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

# La lib refuse d'etre executee seule et attend ROOT + COMPOSE_FILE de son
# appelant. COMPOSE_FILE reste vide ici : aucune des fonctions exercees ne
# lance de docker compose.
COMPOSE_FILE=""
# shellcheck source=../../scripts/lib/demo-snapshot-lib.sh
. "$ROOT/scripts/lib/demo-snapshot-lib.sh"
# La lib pose `set -e` : sans ca, la premiere assertion en echec couperait le
# test au lieu de laisser tourner les suivantes (on veut le bilan complet).
set +e

PASS=0
FAIL=0
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Le marqueur de retour arriere repose sur une empreinte sha256, calculee cote
# hote par sha256_hex. Sans outil de hachage, la moitie des assertions echouerait
# sans dire pourquoi : autant le constater ici, en clair.
if ! printf 'x' | sha256_hex > /dev/null 2>&1; then
    echo "ERREUR : aucun outil de hachage sha256 disponible (sha256sum, shasum ou openssl)." >&2
    exit 1
fi

check() {
    local label="$1" got="$2" want="$3"
    if [ "$got" = "$want" ]; then
        printf 'OK   %s\n' "$label"
        PASS=$((PASS + 1))
    else
        printf 'FAIL %s (code %s, attendu %s)\n' "$label" "$got" "$want" >&2
        FAIL=$((FAIL + 1))
    fi
}

# 0 = instantane accepte, 1 = refuse. Le message d'erreur de la lib n'est
# reaffiche que sur un echec d'assertion (sinon il polluerait une sortie verte).
expect_validate() {
    local label="$1" dir="$2" want="$3" got
    validate_snapshot_dir "$dir" > "$WORK/last.log" 2>&1
    got=$?
    check "$label" "$got" "$want"
    if [ "$got" != "$want" ] && [ -s "$WORK/last.log" ]; then
        sed 's/^/     /' "$WORK/last.log" >&2
    fi
}

# Fabrique un instantane bien forme, au format exact de capture_snapshot :
# db.sql.gz (dump gzip se terminant par "-- Dump completed"), migrations.txt,
# counts.txt, meta.txt, et uploads.tar.gz si $2 = yes. $3 = kind de meta.txt
# ("reference" par defaut, "pre-reset" pour une sauvegarde de securite).
#
# Le contenu du dump varie avec le nom du dossier : deux instantanes distincts
# ont donc deux empreintes sha256 distinctes, ce qui permet de tester qu'un
# marqueur recopie d'une sauvegarde vers un autre instantane est rejete.
make_snapshot() {
    local dir="$1" uploads="${2:-no}" kind="${3:-reference}"
    mkdir -p "$dir"
    {
        printf -- '-- MariaDB dump (fixture de test, %s)\n' "$(basename "$dir")"
        printf 'CREATE TABLE `t` (`id` int);\n'
        printf 'INSERT INTO `t` VALUES (1);\n'
        printf -- '-- Dump completed on 2026-09-26 12:00:00\n'
    } | gzip -9 -c > "$dir/db.sql.gz"
    printf '0001_init.sql\n' > "$dir/migrations.txt"
    printf 't\t1\n' > "$dir/counts.txt"
    {
        printf 'created_at=2026-09-26T12:00:00+00:00\n'
        printf 'kind=%s\n' "$kind"
        printf 'table_count=1\n'
        printf 'uploads_included=%s\n' "$uploads"
        printf 'uploads_files=%s\n' "$([ "$uploads" = yes ] && echo 1 || echo 0)"
    } > "$dir/meta.txt"
    if [ "$uploads" = yes ]; then
        mkdir -p "$dir/.src/products"
        printf 'image' > "$dir/.src/products/burger.png"
        tar czf "$dir/uploads.tar.gz" -C "$dir/.src" .
        rm -rf "$dir/.src"
    fi
}

echo "== validate_snapshot_dir : instantane bien forme"
make_snapshot "$WORK/ok-sans-images" no
expect_validate "instantane complet sans images" "$WORK/ok-sans-images" 0
make_snapshot "$WORK/ok-avec-images" yes
expect_validate "instantane complet avec images" "$WORK/ok-avec-images" 0

echo "== validate_snapshot_dir : fichier attendu manquant"
for f in db.sql.gz migrations.txt counts.txt meta.txt; do
    make_snapshot "$WORK/sans-$f" no
    rm -f "$WORK/sans-$f/$f"
    expect_validate "$f absent -> refus" "$WORK/sans-$f" 1
done

echo "== validate_snapshot_dir : integrite du dump (regression)"
# Le defaut corrige : un db.sql.gz tronque (copie ou transfert interrompu,
# disque plein) passait l'etape [1/6], --dry-run annoncait "compatible", puis la
# restauration cassait EN PLEIN [4/6] avec la base a moitie ecrasee.
make_snapshot "$WORK/dump-tronque" no
FULL="$(wc -c < "$WORK/dump-tronque/db.sql.gz" | tr -d '[:space:]')"
head -c "$((FULL / 2))" "$WORK/ok-sans-images/db.sql.gz" > "$WORK/dump-tronque/db.sql.gz"
expect_validate "db.sql.gz tronque -> refus" "$WORK/dump-tronque" 1

make_snapshot "$WORK/dump-vide" no
: > "$WORK/dump-vide/db.sql.gz"
expect_validate "db.sql.gz vide -> refus" "$WORK/dump-vide" 1

make_snapshot "$WORK/dump-pas-gzip" no
printf 'ceci-nest-pas-un-gzip' > "$WORK/dump-pas-gzip/db.sql.gz"
expect_validate "db.sql.gz non gzip -> refus" "$WORK/dump-pas-gzip" 1

# Gzip parfaitement valide, mais le SQL a l'interieur s'arrete au milieu : le
# cas d'un dump tronque PUIS recompresse, que `gzip -t` seul ne verrait pas.
make_snapshot "$WORK/dump-sans-fin" no
{
    printf -- '-- MariaDB dump (fixture de test)\n'
    printf 'CREATE TABLE `t` (`id` int);\n'
    printf 'INSERT INTO `t` VALUES (1),(2\n'
} | gzip -9 -c > "$WORK/dump-sans-fin/db.sql.gz"
expect_validate "db.sql.gz sans '-- Dump completed' -> refus" "$WORK/dump-sans-fin" 1

echo "== validate_snapshot_dir : integrite de l'archive uploads"
make_snapshot "$WORK/uploads-absent" yes
rm -f "$WORK/uploads-absent/uploads.tar.gz"
expect_validate "uploads_included=yes + archive absente -> refus" "$WORK/uploads-absent" 1

make_snapshot "$WORK/uploads-vide" yes
: > "$WORK/uploads-vide/uploads.tar.gz"
expect_validate "uploads_included=yes + archive vide -> refus" "$WORK/uploads-vide" 1

make_snapshot "$WORK/uploads-corrompu" yes
printf 'ceci-nest-pas-un-tar-gz' > "$WORK/uploads-corrompu/uploads.tar.gz"
expect_validate "uploads_included=yes + archive illisible -> refus" "$WORK/uploads-corrompu" 1

# uploads_included=no : aucune archive n'est attendue, l'absence est normale.
make_snapshot "$WORK/uploads-non-attendu" no
expect_validate "uploads_included=no + archive absente -> accepte" "$WORK/uploads-non-attendu" 0

# --- Sortie de secours du retour arriere ------------------------------------

# Deux empreintes de base distinctes, au format rendu par db_identity (sha256).
LIVE_ID="$(printf 'wakdo|2026-09-26 16:03:36|pile-a|pile-a-db' | sha256_hex)"
AUTRE_ID="$(printf 'wakdo|2026-09-26 16:03:36|pile-b|pile-b-db' | sha256_hex)"

# Fabrique une sauvegarde de securite MARQUEE, comme le fait capture_snapshot
# en mode pre-reset : instantane bien forme + marqueur ecrit par la fonction
# REELLE (write_rollback_tag), pas par une imitation ecrite dans le test.
make_backup() {
    local dir="$1" identity="$2" uploads="${3:-no}"
    make_snapshot "$dir" "$uploads" pre-reset
    write_rollback_tag "$dir" "$identity"
}

# 0 = passe-droit accorde, 1 = refuse.
expect_escape() {
    local label="$1" dir="$2" identity="$3" want="$4" got
    rollback_escape_granted "$dir" "$identity" > "$WORK/last.log" 2>&1
    got=$?
    check "$label" "$got" "$want"
    if [ "$got" != "$want" ] && [ -s "$WORK/last.log" ]; then
        sed 's/^/     /' "$WORK/last.log" >&2
    fi
}

expect_decision() {
    local label="$1" extra_live="$2" extra_snap="$3" dir="$4" identity="$5" want="$6" got
    got="$(schema_decision "$extra_live" "$extra_snap" "$dir" "$identity" 2>/dev/null)"
    check "$label" "$got" "$want"
}

echo "== rollback_escape_granted : la sauvegarde de securite marquee"
make_backup "$WORK/sauvegarde" "$LIVE_ID"
expect_escape "sauvegarde marquee + meme base -> accorde" "$WORK/sauvegarde" "$LIVE_ID" 0

echo "== rollback_escape_granted : ce qui n'ouvre PAS la sortie de secours"
# Un instantane ordinaire (le cas de tous les jours) n'a pas de marqueur.
make_snapshot "$WORK/ordinaire" no
expect_escape "instantane ordinaire (kind=reference, sans marqueur) -> refus" "$WORK/ordinaire" "$LIVE_ID" 1

# Marqueur ABSENT alors que meta.txt annonce une sauvegarde de securite.
make_snapshot "$WORK/sans-marqueur" no pre-reset
expect_escape "kind=pre-reset sans marqueur -> refus" "$WORK/sans-marqueur" "$LIVE_ID" 1

# Marqueur venu d'une AUTRE base (autre pile, autre volume de donnees).
make_backup "$WORK/autre-base" "$AUTRE_ID"
expect_escape "marqueur d'une autre base -> refus" "$WORK/autre-base" "$LIVE_ID" 1

# Marqueur VALIDE recopie tel quel sur un AUTRE instantane : l'empreinte du dump
# enregistree dans le marqueur ne correspond plus au dump de ce dossier-la.
make_snapshot "$WORK/marqueur-recopie" no pre-reset
cp "$WORK/sauvegarde/rollback.tag" "$WORK/marqueur-recopie/rollback.tag"
expect_escape "marqueur recopie sur un autre instantane -> refus" "$WORK/marqueur-recopie" "$LIVE_ID" 1

# Marqueur retouche a la main : l'empreinte du dump ne colle plus.
make_backup "$WORK/marqueur-retouche" "$LIVE_ID"
sed -i 's/^dump_sha256=.*/dump_sha256=0000000000000000000000000000000000000000000000000000000000000000/' \
    "$WORK/marqueur-retouche/rollback.tag"
expect_escape "empreinte du dump falsifiee -> refus" "$WORK/marqueur-retouche" "$LIVE_ID" 1

# Marqueur ampute de son empreinte de base.
make_backup "$WORK/marqueur-sans-base" "$LIVE_ID"
sed -i 's/^origin_db=.*/origin_db=/' "$WORK/marqueur-sans-base/rollback.tag"
expect_escape "marqueur sans empreinte de base -> refus" "$WORK/marqueur-sans-base" "$LIVE_ID" 1

# Dump remplace APRES l'ecriture du marqueur (sauvegarde alteree apres coup).
make_backup "$WORK/dump-remplace" "$LIVE_ID"
printf -- '-- MariaDB dump (autre contenu)\n-- Dump completed on 2026-09-26 13:00:00\n' \
    | gzip -9 -c > "$WORK/dump-remplace/db.sql.gz"
expect_escape "dump remplace apres coup -> refus" "$WORK/dump-remplace" "$LIVE_ID" 1

# Marqueur parfaitement valide, mais meta.txt annonce un instantane de reference :
# le marqueur SEUL ne suffit pas, les deux doivent concorder.
make_snapshot "$WORK/kind-reference" no reference
write_rollback_tag "$WORK/kind-reference" "$LIVE_ID"
expect_escape "marqueur valide mais kind=reference -> refus" "$WORK/kind-reference" "$LIVE_ID" 1

# Identite de la base courante indisponible (wakdo-db muet sur ce point) : pas
# de comparaison possible, donc pas de passe-droit.
expect_escape "identite de la base courante inconnue -> refus" "$WORK/sauvegarde" "" 1

echo "== schema_decision : le garde-fou mord toujours, sauf sur le retour arriere"
EXTRA="0015_ajout.sql"

expect_decision "schemas identiques -> ok" "" "" "$WORK/ordinaire" "$LIVE_ID" ok

# Migration appliquee DEPUIS l'instantane : vraie erreur, refus inchange - y
# compris sur une sauvegarde de securite marquee (la sortie de secours ne joue
# que dans l'autre sens).
expect_decision "migration appliquee depuis l'instantane -> refus" \
    "$EXTRA" "" "$WORK/ordinaire" "$LIVE_ID" extra-live
expect_decision "migration appliquee depuis la sauvegarde marquee -> refus quand meme" \
    "$EXTRA" "" "$WORK/sauvegarde" "$LIVE_ID" extra-live
expect_decision "divergence des deux cotes sur une sauvegarde marquee -> refus" \
    "$EXTRA" "$EXTRA" "$WORK/sauvegarde" "$LIVE_ID" extra-live

# La base a PERDU des migrations que l'instantane possede : c'est la signature
# d'une restauration interrompue avant la remise en place de schema_migrations.
expect_decision "base amputee + instantane ordinaire -> refus (inchange)" \
    "" "$EXTRA" "$WORK/ordinaire" "$LIVE_ID" extra-snap
expect_decision "base amputee + sauvegarde marquee de CETTE base -> retour arriere" \
    "" "$EXTRA" "$WORK/sauvegarde" "$LIVE_ID" rollback
expect_decision "base amputee + sauvegarde marquee d'une AUTRE base -> refus" \
    "" "$EXTRA" "$WORK/autre-base" "$LIVE_ID" extra-snap
expect_decision "base amputee + marqueur recopie -> refus" \
    "" "$EXTRA" "$WORK/marqueur-recopie" "$LIVE_ID" extra-snap

echo "== l'integrite du dump reste verifiee sur le retour arriere"
# La sortie de secours porte sur la COMPATIBILITE de schema, pas sur l'integrite
# du fichier : une sauvegarde marquee dont le dump est abime reste refusee par
# validate_snapshot_dir, qui tourne avant et independamment.
make_backup "$WORK/sauvegarde-dump-tronque" "$LIVE_ID"
FULL="$(wc -c < "$WORK/sauvegarde-dump-tronque/db.sql.gz" | tr -d '[:space:]')"
head -c "$((FULL / 2))" "$WORK/sauvegarde/db.sql.gz" > "$WORK/sauvegarde-dump-tronque/db.sql.gz"
expect_validate "sauvegarde marquee + dump tronque -> refus" "$WORK/sauvegarde-dump-tronque" 1
expect_escape "sauvegarde marquee + dump tronque -> pas de passe-droit non plus" \
    "$WORK/sauvegarde-dump-tronque" "$LIVE_ID" 1

make_backup "$WORK/sauvegarde-dump-vide" "$LIVE_ID"
: > "$WORK/sauvegarde-dump-vide/db.sql.gz"
expect_validate "sauvegarde marquee + dump vide -> refus" "$WORK/sauvegarde-dump-vide" 1

make_backup "$WORK/sauvegarde-dump-sans-fin" "$LIVE_ID"
{
    printf -- '-- MariaDB dump (fixture de test)\n'
    printf 'INSERT INTO `t` VALUES (1),(2\n'
} | gzip -9 -c > "$WORK/sauvegarde-dump-sans-fin/db.sql.gz"
expect_validate "sauvegarde marquee + dump sans '-- Dump completed' -> refus" \
    "$WORK/sauvegarde-dump-sans-fin" 1

make_backup "$WORK/sauvegarde-uploads-corrompu" "$LIVE_ID" yes
printf 'ceci-nest-pas-un-tar-gz' > "$WORK/sauvegarde-uploads-corrompu/uploads.tar.gz"
expect_validate "sauvegarde marquee + archive uploads illisible -> refus" \
    "$WORK/sauvegarde-uploads-corrompu" 1

echo
printf '%s assertion(s) OK, %s en echec\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
