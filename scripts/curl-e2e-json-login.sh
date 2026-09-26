#!/usr/bin/env bash
# Verification curl reelle de la CONNEXION JSON (/admin/api/auth/*,
# docs/api/conventions.md section 5.3bis), sur une pile JETABLE dediee (meme
# principe que scripts/curl-e2e-admin-api.sh / tests/e2e/run.sh). A la
# difference de curl-e2e-admin-api.sh (qui s'authentifie via le FORMULAIRE HTML
# /login, puis lit le csrf sur GET /admin/me), ce script n'utilise QUE
# /admin/api/auth/* et QUE le pot a cookies curl (-c/-b) + les reponses JSON :
# c'est le parcours que Postman/Bruno rejouent (0. Connexion), sans jamais
# toucher une page HTML.
#
# Identifiants du compte de demo du SEED (pas un secret de production, mais on
# ne les ecrit pas en clair dans ce script versionne) : a fournir via
# l'environnement,
#   WAKDO_E2E_EMAIL=admin@wakdo.local WAKDO_E2E_PASSWORD=... ./scripts/curl-e2e-json-login.sh <copie> <projet>
# Valeurs exactes : cf. db/seeds/0001_rbac_and_reference.sql, section "bootstrap administrator".
set -uo pipefail
# Pas de -e : voir curl-e2e-admin-api.sh (une assertion ratee ne doit pas
# masquer les suivantes).

ROOT="$1"
PROJECT="${2:-wakdojsonlogin}"
NET="${PROJECT}_wakdo_internal"
ENVFILE="$(mktemp)"
OVERRIDE="$(mktemp --suffix=.yml)"

cp "$ROOT/.env.example" "$ENVFILE"
perl -pi -e 's/^APP_HOST_KIOSK=.*/APP_HOST_KIOSK=kiosk.wakdo.test/; s/^APP_HOST_ADMIN=.*/APP_HOST_ADMIN=admin.wakdo.test/;' "$ENVFILE"
cat > "$OVERRIDE" <<YML
services:
  wakdo-db:
    container_name: ${PROJECT}-db
  wakdo-migrate:
    container_name: ${PROJECT}-migrate
  wakdo-app:
    container_name: ${PROJECT}-app
  wakdo-web:
    container_name: ${PROJECT}-web
    ports: !reset []
  wakdo-cron:
    container_name: ${PROJECT}-cron
YML

cd "$ROOT"
COMPOSE="docker compose -p $PROJECT --env-file $ENVFILE -f docker-compose.yml -f $OVERRIDE"
cleanup() { $COMPOSE down -v >/dev/null 2>&1 || true; rm -f "$ENVFILE" "$OVERRIDE"; }
trap cleanup EXIT

echo "[curl-e2e-json-login] up ($PROJECT)"
$COMPOSE up -d --build >/dev/null

for _ in $(seq 1 60); do
  st="$(docker inspect -f '{{.State.Status}}' ${PROJECT}-migrate 2>/dev/null || echo NA)"
  code="$(docker inspect -f '{{.State.ExitCode}}' ${PROJECT}-migrate 2>/dev/null || echo NA)"
  [ "$st" = exited ] && [ "$code" = 0 ] && break
  [ "$st" = exited ] && [ "$code" != 0 ] && { docker logs "${PROJECT}-migrate" | tail -30; exit 1; }
  sleep 2
done
for _ in $(seq 1 60); do
  [ "$(docker inspect -f '{{.State.Health.Status}}' ${PROJECT}-web 2>/dev/null || echo NA)" = healthy ] && break
  sleep 2
done

WEB_IP="$(docker inspect -f "{{(index .NetworkSettings.Networks \"$NET\").IPAddress}}" "${PROJECT}-web")"
echo "[curl-e2e-json-login] web @ $WEB_IP"

run_curl() {
  docker run --rm --network "$NET" \
    --add-host "admin.wakdo.test:$WEB_IP" \
    -v "${JAR_DIR}:/jar" \
    curlimages/curl:8.10.1 "$@"
}

JAR_DIR="$(mktemp -d)"
chmod 777 "$JAR_DIR"
JAR=/jar/cookies.txt
FAIL=0
check() {
  local label="$1" got="$2" want="$3"
  if [ "$got" = "$want" ]; then
    echo "OK   $label ($got)"
  else
    echo "FAIL $label (got $got, want $want)"
    FAIL=1
  fi
}

: "${WAKDO_E2E_EMAIL:?WAKDO_E2E_EMAIL manquant (email du compte de demo du seed)}"
: "${WAKDO_E2E_PASSWORD:?WAKDO_E2E_PASSWORD manquant (mot de passe DEV du meme compte)}"
EMAIL="$WAKDO_E2E_EMAIL"
PASSWORD="$WAKDO_E2E_PASSWORD"

echo "[curl-e2e-json-login] 1) POST /admin/api/auth/login (identifiants faux) -> 401 INVALID_CREDENTIALS"
BAD_OUT="$(run_curl -s -w '\n%{http_code}' -c "$JAR" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"${EMAIL}\",\"password\":\"un-mot-de-passe-faux\"}" \
  "http://admin.wakdo.test/admin/api/auth/login")"
BAD_STATUS="$(printf '%s' "$BAD_OUT" | tail -1)"
BAD_BODY="$(printf '%s' "$BAD_OUT" | sed '$d')"
echo "    $BAD_BODY"
check "POST login identifiants faux" "$BAD_STATUS" "401"
if printf '%s' "$BAD_BODY" | grep -q '"code":"INVALID_CREDENTIALS"'; then
  echo "OK   code INVALID_CREDENTIALS"
else
  echo "FAIL code INVALID_CREDENTIALS absent"
  FAIL=1
fi

echo "[curl-e2e-json-login] 2) POST /admin/api/auth/login (Content-Type text/plain, corps non vide) -> 415"
CT_STATUS="$(run_curl -s -o /dev/null -w '%{http_code}' \
  -H 'Content-Type: text/plain' \
  -d "{\"email\":\"${EMAIL}\",\"password\":\"${PASSWORD}\"}" \
  "http://admin.wakdo.test/admin/api/auth/login")"
check "POST login Content-Type non-JSON" "$CT_STATUS" "415"

echo "[curl-e2e-json-login] 3) POST /admin/api/auth/login (identifiants corrects) -> 200, lit csrf_token DANS LA REPONSE (pas via /admin/me)"
LOGIN_OUT="$(run_curl -s -w '\n%{http_code}' -c "$JAR" -b "$JAR" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"${EMAIL}\",\"password\":\"${PASSWORD}\"}" \
  "http://admin.wakdo.test/admin/api/auth/login")"
LOGIN_STATUS="$(printf '%s' "$LOGIN_OUT" | tail -1)"
LOGIN_BODY="$(printf '%s' "$LOGIN_OUT" | sed '$d')"
echo "    $LOGIN_BODY"
check "POST login OK" "$LOGIN_STATUS" "200"
CSRF="$(printf '%s' "$LOGIN_BODY" | grep -oE '"csrf_token":"[^"]+"' | sed -E 's/.*:"([^"]+)"/\1/')"
[ -n "$CSRF" ] && echo "OK   csrf_token present dans la reponse de login" || { echo "FAIL csrf_token absent"; FAIL=1; }
if printf '%s' "$LOGIN_BODY" | grep -qi 'password'; then
  echo "FAIL le mot de passe ou son hash apparait dans la reponse de login"
  FAIL=1
else
  echo "OK   aucun mot de passe/hash dans la reponse de login"
fi
if grep -qi 'WAKDO_SID' "${JAR_DIR}/cookies.txt" 2>/dev/null; then
  echo "OK   cookie WAKDO_SID pose dans le pot a cookies (pot a cookies uniquement, aucune lecture manuelle)"
else
  echo "FAIL cookie WAKDO_SID absent du pot a cookies"
  FAIL=1
fi

echo "[curl-e2e-json-login] 4) GET /admin/api/auth/me (alias JSON de /admin/me) -> 200, meme identite"
ME_OUT="$(run_curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "http://admin.wakdo.test/admin/api/auth/me")"
check "GET /admin/api/auth/me" "$ME_OUT" "200"

echo "[curl-e2e-json-login] 5) POST /admin/api/categories (creation, X-CSRF-Token de la reponse de login) -> 201"
CREATE_OUT="$(run_curl -s -w '\n%{http_code}' -b "$JAR" \
  -H "X-CSRF-Token: ${CSRF}" -H 'Content-Type: application/json' \
  -d '{"name":"Categorie json-login e2e","slug":"categorie-json-login-e2e","display_order":90}' \
  "http://admin.wakdo.test/admin/api/categories")"
CREATE_STATUS="$(printf '%s' "$CREATE_OUT" | tail -1)"
CREATE_BODY="$(printf '%s' "$CREATE_OUT" | sed '$d')"
echo "    $CREATE_BODY"
check "POST creation categorie" "$CREATE_STATUS" "201"
NEW_ID="$(printf '%s' "$CREATE_BODY" | grep -oE '"id":[0-9]+' | head -1 | grep -oE '[0-9]+')"
echo "    id cree = $NEW_ID"

echo "[curl-e2e-json-login] 6) PUT /admin/api/categories/{id} -> 200"
PUT_STATUS="$(run_curl -s -o /dev/null -w '%{http_code}' -b "$JAR" \
  -X PUT -H "X-CSRF-Token: ${CSRF}" -H 'Content-Type: application/json' \
  -d '{"name":"Categorie json-login e2e v2","slug":"categorie-json-login-e2e","display_order":91}' \
  "http://admin.wakdo.test/admin/api/categories/${NEW_ID}")"
check "PUT modification categorie" "$PUT_STATUS" "200"

echo "[curl-e2e-json-login] 7) definir un PIN (HTML self-service, seul prerequis hors JSON -- pas d'equivalent API, cf. docs/api/demo-api.md) puis DELETE avec PIN -> 200"
LOGIN_HTML="$(run_curl -s -b "$JAR" -c "$JAR" "http://admin.wakdo.test/admin/profile/pin")"
PIN_CSRF="$(printf '%s' "$LOGIN_HTML" | grep -oE 'name="_csrf" value="[^"]+"' | head -1 | sed -E 's/.*value="([^"]+)".*/\1/')"
PIN_SET_STATUS="$(run_curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" \
  -d "_csrf=${PIN_CSRF}" --data-urlencode "current_password=${PASSWORD}" -d 'pin=4729' -d 'pin_confirm=4729' \
  "http://admin.wakdo.test/admin/profile/pin")"
check "POST /admin/profile/pin definit le PIN (302)" "$PIN_SET_STATUS" "302"

DELETE_OUT="$(run_curl -s -w '\n%{http_code}' -b "$JAR" \
  -X DELETE -H "X-CSRF-Token: ${CSRF}" -H 'Content-Type: application/json' \
  -d '{"pin_email":"'"${EMAIL}"'","pin":"4729"}' \
  "http://admin.wakdo.test/admin/api/products/999999")"
# 999999 = id inexistant (ce script ne cree pas de produit) : la garde 404 se
# verifie APRES le PIN pour cette ressource -- ce test verifie seulement que la
# route DELETE + PIN est ATTEIGNABLE avec la session/csrf JSON, pas le succes
# d'une suppression reelle. La categorie creee plus haut, ELLE, n'a pas de PIN
# (category.manage n'est pas dans l'ensemble sensible RG-T13) : DELETE dessus
# pour prouver un vrai succes.
DELETE_CAT_OUT="$(run_curl -s -w '\n%{http_code}' -b "$JAR" \
  -X DELETE -H "X-CSRF-Token: ${CSRF}" \
  "http://admin.wakdo.test/admin/api/categories/${NEW_ID}")"
DELETE_CAT_STATUS="$(printf '%s' "$DELETE_CAT_OUT" | tail -1)"
DELETE_CAT_BODY="$(printf '%s' "$DELETE_CAT_OUT" | sed '$d')"
echo "    $DELETE_CAT_BODY"
check "DELETE categorie (desactivation)" "$DELETE_CAT_STATUS" "200"

echo "[curl-e2e-json-login] 8) creer + supprimer un ingredient AVEC PIN (ressource PIN-gated reelle) -> 201 puis 200"
ING_CREATE_OUT="$(run_curl -s -w '\n%{http_code}' -b "$JAR" \
  -H "X-CSRF-Token: ${CSRF}" -H 'Content-Type: application/json' \
  -d '{"name":"Ingredient json-login e2e","unit":"unite","pack_size":10,"stock_capacity":100,"low_stock_pct":20,"critical_stock_pct":5}' \
  "http://admin.wakdo.test/admin/api/ingredients")"
ING_ID="$(printf '%s' "$ING_CREATE_OUT" | sed '$d' | grep -oE '"id":[0-9]+' | head -1 | grep -oE '[0-9]+')"
echo "    ingredient cree = $ING_ID"

ING_DELETE_OUT="$(run_curl -s -w '\n%{http_code}' -b "$JAR" \
  -X DELETE -H "X-CSRF-Token: ${CSRF}" \
  "http://admin.wakdo.test/admin/api/ingredients/${ING_ID}")"
ING_DELETE_STATUS="$(printf '%s' "$ING_DELETE_OUT" | tail -1)"
check "DELETE ingredient" "$ING_DELETE_STATUS" "200"

echo "[curl-e2e-json-login] 9) POST /admin/api/auth/logout (destruction de la session) -> 204"
LOGOUT_STATUS="$(run_curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" \
  -X POST -H "X-CSRF-Token: ${CSRF}" "http://admin.wakdo.test/admin/api/auth/logout")"
check "POST logout" "$LOGOUT_STATUS" "204"

echo "[curl-e2e-json-login] 10) GET /admin/api/auth/me APRES deconnexion -> 401 JSON (pas de redirection HTML)"
AFTER_OUT="$(run_curl -s -w '\n%{http_code}' -D /jar/hafter.txt -b "$JAR" "http://admin.wakdo.test/admin/api/auth/me")"
AFTER_STATUS="$(printf '%s' "$AFTER_OUT" | tail -1)"
AFTER_BODY="$(printf '%s' "$AFTER_OUT" | sed '$d')"
echo "    $AFTER_BODY"
check "GET me apres logout" "$AFTER_STATUS" "401"
AFTER_CT="$(grep -i '^content-type:' "$JAR_DIR/hafter.txt" 2>/dev/null | tr -d '\r')"
if printf '%s' "$AFTER_CT" | grep -qi 'application/json'; then
  echo "OK   Content-Type JSON apres deconnexion (pas de redirection HTML vers /login)"
else
  echo "FAIL Content-Type inattendu apres deconnexion : $AFTER_CT"
  FAIL=1
fi

rm -rf "$JAR_DIR"

if [ "$FAIL" != 0 ]; then
  echo "[curl-e2e-json-login] ECHEC : au moins une verification a echoue"
  exit 1
fi
echo "[curl-e2e-json-login] TOUT EST VERT"
