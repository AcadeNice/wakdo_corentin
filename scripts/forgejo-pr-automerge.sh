#!/usr/bin/env bash
#
# Wakdo - ouvre une PR Forgejo et planifie son auto-merge quand la CI passe.
#
# Strategie solo dev : la PR reste obligatoire (trace de gouvernance, Cr 4.f)
# mais le merge se declenche tout seul des que les checks requis sont verts.
# Prerequis : status checks requis sur la branche de base
# (voir scripts/forgejo-branch-protection.sh avec REQUIRE_CI=1).
#
# Usage :
#   scripts/forgejo-pr-automerge.sh [HEAD] [BASE] ["Titre"]
# Defauts : HEAD = branche courante, BASE = dev, titre = dernier sujet de commit.
#
set -euo pipefail

REPO_API="https://git.acadenice.com/api/v1/repos/AcadeNice/corentin_wakdo"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT/.env"

TOKEN="$(grep -E '^FORGEJO_TOKEN=' "$ENV_FILE" | cut -d= -f2-)"
[ -n "${TOKEN:-}" ] || { echo "ERREUR : FORGEJO_TOKEN absent de $ENV_FILE" >&2; exit 1; }

HEAD="${1:-$(git -C "$ROOT" rev-parse --abbrev-ref HEAD)}"
BASE="${2:-dev}"
TITLE="${3:-$(git -C "$ROOT" log -1 --pretty=%s "$HEAD")}"

if [ "$BASE" = "main" ] && [ "$HEAD" != "dev" ]; then
  echo "Garde-fou : seules les PR depuis 'dev' visent 'main'. Abandon." >&2
  exit 1
fi

echo "PR : $HEAD -> $BASE"
echo "Titre : $TITLE"

# 1. Creer la PR (ou recuperer l'index si elle existe deja).
create_resp=$(curl -s -X POST -H "Authorization: token $TOKEN" -H "Content-Type: application/json" \
  -d "$(printf '{"head":"%s","base":"%s","title":"%s"}' "$HEAD" "$BASE" "$TITLE")" \
  "$REPO_API/pulls")
index=$(printf '%s' "$create_resp" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('number',''))" 2>/dev/null || true)

if [ -z "$index" ]; then
  # PR deja existante : la retrouver par branche head.
  index=$(curl -s -H "Authorization: token $TOKEN" "$REPO_API/pulls?state=open&limit=50" \
    | python3 -c "import sys,json;hs='$HEAD';d=json.load(sys.stdin);print(next((p['number'] for p in d if p['head']['ref']==hs),''))" 2>/dev/null || true)
fi
[ -n "$index" ] || { echo "Impossible de creer/trouver la PR. Reponse : $create_resp" >&2; exit 1; }
echo "PR #$index"

# 2. Planifier l'auto-merge (squash) quand les checks requis sont verts.
merge_resp=$(curl -s -o /dev/null -w "%{http_code}" -X POST \
  -H "Authorization: token $TOKEN" -H "Content-Type: application/json" \
  -d '{"Do":"squash","merge_when_checks_succeed":true,"delete_branch_after_merge":false}' \
  "$REPO_API/pulls/$index/merge")

case "$merge_resp" in
  200|201|202) echo "Auto-merge planifie sur PR #$index (squash a la CI verte)." ;;
  405) echo "PR #$index : merge differe - checks pas encore verts, auto-merge en attente." ;;
  *)   echo "Reponse merge HTTP $merge_resp sur PR #$index (verifier l'etat des checks / protections)." ;;
esac
