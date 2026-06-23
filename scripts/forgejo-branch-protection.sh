#!/usr/bin/env bash
#
# Wakdo - applique (idempotent) les regles de protection de branche sur Forgejo.
#
# Pourquoi un script versionne : la regle de gouvernance devient reproductible
# et auditable (Cr 7.b), pas un clic dans une UI. Roter le token = editer .env.
#
# Regle posee :
#   - main et dev : push direct interdit (PR obligatoire), force-push bloque
#   - required_approvals = 0 (travail solo : on ne peut pas approuver sa propre PR)
#   - status check : OPTIONNEL via REQUIRE_CI=1, contextes dans CI_CONTEXTS
#     (a activer en lot D, une fois les jobs .forgejo/workflows/ nommes ;
#      activer avant que le workflow n'existe bloquerait tout merge)
#
# Usage :
#   scripts/forgejo-branch-protection.sh                 # baseline (PR requise)
#   REQUIRE_CI=1 CI_CONTEXTS='ci' scripts/forgejo-branch-protection.sh   # + CI verte requise
#
set -euo pipefail

REPO_API="https://git.acadenice.com/api/v1/repos/AcadeNice/corentin_wakdo"
ENV_FILE="$(cd "$(dirname "$0")/.." && pwd)/.env"

TOKEN="$(grep -E '^FORGEJO_TOKEN=' "$ENV_FILE" | cut -d= -f2-)"
if [ -z "${TOKEN:-}" ]; then
  echo "ERREUR : FORGEJO_TOKEN absent de $ENV_FILE" >&2
  exit 1
fi

REQUIRE_CI="${REQUIRE_CI:-0}"
CI_CONTEXTS="${CI_CONTEXTS:-ci}"

# Construit le tableau JSON des contextes de status check si REQUIRE_CI=1.
status_check_json="false"
contexts_json="[]"
if [ "$REQUIRE_CI" = "1" ]; then
  status_check_json="true"
  contexts_json="$(printf '%s' "$CI_CONTEXTS" | awk -F, '{printf "["; for(i=1;i<=NF;i++){printf "%s\"%s\"", (i>1?",":""), $i}; printf "]"}')"
fi

for branch in main dev; do
  payload=$(cat <<JSON
{
  "branch_name": "$branch",
  "enable_push": false,
  "enable_force_push": false,
  "required_approvals": 0,
  "enable_status_check": $status_check_json,
  "status_check_contexts": $contexts_json
}
JSON
)
  # PATCH si la protection existe, sinon POST pour la creer.
  if curl -sf -o /dev/null -H "Authorization: token $TOKEN" "$REPO_API/branch_protections/$branch"; then
    method=PATCH; url="$REPO_API/branch_protections/$branch"
  else
    method=POST;  url="$REPO_API/branch_protections"
  fi
  echo "[$branch] $method (status_check=$status_check_json contexts=$contexts_json)"
  curl -s -X "$method" -H "Authorization: token $TOKEN" -H "Content-Type: application/json" \
    -d "$payload" "$url" >/dev/null
done

echo "OK - protections appliquees sur main et dev."
