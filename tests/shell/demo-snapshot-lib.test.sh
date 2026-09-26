#!/usr/bin/env bash
#
# Wakdo - tests de scripts/lib/demo-snapshot-lib.sh.
#
# Couvre validate_snapshot_dir, le controle joue a l'etape [1/6] de
# scripts/demo-reset.sh AVANT toute destruction (mode --dry-run compris). Un
# instantane corrompu doit etre refuse LA, pas au milieu de l'etape [4/6] quand
# les DROP/CREATE ont deja commence et que la base est a moitie ecrasee.
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
# counts.txt, meta.txt, et uploads.tar.gz si $2 = yes.
make_snapshot() {
    local dir="$1" uploads="${2:-no}"
    mkdir -p "$dir"
    {
        printf -- '-- MariaDB dump (fixture de test)\n'
        printf 'CREATE TABLE `t` (`id` int);\n'
        printf 'INSERT INTO `t` VALUES (1);\n'
        printf -- '-- Dump completed on 2026-09-26 12:00:00\n'
    } | gzip -9 -c > "$dir/db.sql.gz"
    printf '0001_init.sql\n' > "$dir/migrations.txt"
    printf 't\t1\n' > "$dir/counts.txt"
    {
        printf 'created_at=2026-09-26T12:00:00+00:00\n'
        printf 'kind=reference\n'
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

echo
printf '%s assertion(s) OK, %s en echec\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
