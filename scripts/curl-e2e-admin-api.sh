#!/usr/bin/env bash
# Verification curl reelle de l'API d'administration JSON (/admin/api/*), sur une
# pile JETABLE dediee (meme principe que tests/e2e/run.sh / _byan-output/outils/e2e.sh),
# projet Docker Compose unique pour ne toucher ni wakdo-* (prod) ni une autre pile de
# test. Detruit systematiquement la pile a la sortie (trap), meme en echec.
#
# Identifiants du compte de demo du SEED (pas un secret de production, mais on ne les
# ecrit pas en clair dans ce script versionne) : a fournir via l'environnement,
#   WAKDO_E2E_EMAIL=admin@wakdo.local WAKDO_E2E_PASSWORD=... ./scripts/curl-e2e-admin-api.sh <copie> <projet>
# Valeurs exactes : cf. db/seeds/0001_rbac_and_reference.sql, section "bootstrap administrator".
set -uo pipefail
# Pas de -e : les extractions grep (csrf, id cree) et check() doivent pouvoir constater
# un echec ET continuer les verifications suivantes plutot que d'interrompre le script
# (une premiere assertion ratee ne doit pas masquer les suivantes).

ROOT="$1"
PROJECT="${2:-wakdoadmcurl}"
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

echo "[curl-e2e] up ($PROJECT)"
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
echo "[curl-e2e] web @ $WEB_IP"

run_curl() {
  docker run --rm --network "$NET" \
    --add-host "kiosk.wakdo.test:$WEB_IP" --add-host "admin.wakdo.test:$WEB_IP" \
    -v "${JAR_DIR}:/jar" \
    curlimages/curl:8.10.1 "$@"
}

JAR_DIR="$(mktemp -d)"
# 777 : le conteneur curlimages/curl tourne en utilisateur NON-ROOT (uid different de
# l'hote), donc le mode par defaut de mktemp -d (700, proprietaire hote) lui refuserait
# l'ecriture du cookie jar / des dumps d'en-tetes montes en volume.
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

: "${WAKDO_E2E_EMAIL:?WAKDO_E2E_EMAIL manquant (email du compte de demo du seed, cf. db/seeds/0001_rbac_and_reference.sql, section bootstrap administrator)}"
: "${WAKDO_E2E_PASSWORD:?WAKDO_E2E_PASSWORD manquant (mot de passe DEV du meme compte)}"
EMAIL="$WAKDO_E2E_EMAIL"
PASSWORD="$WAKDO_E2E_PASSWORD"

echo "[curl-e2e] 1) GET /login (extraire le jeton _csrf initial)"
LOGIN_HTML="$(run_curl -s -c "$JAR" "http://admin.wakdo.test/login")"
LOGIN_CSRF="$(printf '%s' "$LOGIN_HTML" | grep -oE 'name="_csrf" value="[^"]+"' | head -1 | sed -E 's/.*value="([^"]+)".*/\1/')"
echo "    _csrf(login)=${LOGIN_CSRF:0:8}..."

echo "[curl-e2e] 2) POST /login (authentification reelle)"
LOGIN_STATUS="$(run_curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" \
  -d "_csrf=${LOGIN_CSRF}" --data-urlencode "email=${EMAIL}" --data-urlencode "password=${PASSWORD}" \
  "http://admin.wakdo.test/login")"
check "POST /login redirige (302)" "$LOGIN_STATUS" "302"

echo "[curl-e2e] 3) GET /admin/me (identite + csrf_token JSON)"
ME_JSON="$(run_curl -s -b "$JAR" "http://admin.wakdo.test/admin/me")"
echo "    $ME_JSON"
CSRF="$(printf '%s' "$ME_JSON" | grep -oE '"csrf_token":"[^"]+"' | sed -E 's/.*:"([^"]+)"/\1/')"
[ -n "$CSRF" ] && echo "OK   csrf_token present" || { echo "FAIL csrf_token absent"; FAIL=1; }

echo "[curl-e2e] 4) GET /admin/api/ingredients SANS cookie -> 401"
NOAUTH_STATUS="$(run_curl -s -o /dev/null -w '%{http_code}' "http://admin.wakdo.test/admin/api/ingredients")"
check "GET sans session" "$NOAUTH_STATUS" "401"

echo "[curl-e2e] 5) POST /admin/api/ingredients SANS X-CSRF-Token -> 403"
NOCSRF_STATUS="$(run_curl -s -o /dev/null -w '%{http_code}' -b "$JAR" \
  -H 'Content-Type: application/json' \
  -d '{"name":"Test sans csrf","unit":"unite","pack_size":10,"stock_capacity":100,"low_stock_pct":20,"critical_stock_pct":5}' \
  "http://admin.wakdo.test/admin/api/ingredients")"
check "POST sans X-CSRF-Token" "$NOCSRF_STATUS" "403"

echo "[curl-e2e] 6) POST /admin/api/ingredients (creation reelle) -> 201"
CREATE_OUT="$(run_curl -s -w '\n%{http_code}' -b "$JAR" \
  -H "X-CSRF-Token: ${CSRF}" -H 'Content-Type: application/json' \
  -d '{"name":"Ingredient curl e2e","unit":"unite","pack_size":10,"stock_capacity":100,"low_stock_pct":20,"critical_stock_pct":5}' \
  "http://admin.wakdo.test/admin/api/ingredients")"
CREATE_STATUS="$(printf '%s' "$CREATE_OUT" | tail -1)"
CREATE_BODY="$(printf '%s' "$CREATE_OUT" | sed '$d')"
echo "    $CREATE_BODY"
check "POST creation" "$CREATE_STATUS" "201"
NEW_ID="$(printf '%s' "$CREATE_BODY" | grep -oE '"id":[0-9]+' | head -1 | grep -oE '[0-9]+')"
echo "    id cree = $NEW_ID"

echo "[curl-e2e] 7) GET /admin/api/ingredients/{id} -> 200"
SHOW_STATUS="$(run_curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "http://admin.wakdo.test/admin/api/ingredients/${NEW_ID}")"
check "GET un ingredient" "$SHOW_STATUS" "200"

echo "[curl-e2e] 8) PUT /admin/api/ingredients/{id} -> 200"
PUT_STATUS="$(run_curl -s -o /dev/null -w '%{http_code}' -b "$JAR" \
  -X PUT -H "X-CSRF-Token: ${CSRF}" -H 'Content-Type: application/json' \
  -d '{"name":"Ingredient curl e2e v2","unit":"unite","pack_size":10,"stock_capacity":100,"low_stock_pct":20,"critical_stock_pct":5}' \
  "http://admin.wakdo.test/admin/api/ingredients/${NEW_ID}")"
check "PUT modification" "$PUT_STATUS" "200"

echo "[curl-e2e] 9) POST /admin/api/ingredients/{id}/restock -> 200"
# Cree un DEUXIEME ingredient dedie au restock : une fois reapprovisionne, l'ingredient
# porte une ligne stock_movement (FK RESTRICT), donc il ne serait plus supprimable a
# l'etape 12 (409 CONFLICT, comportement reel et correct, mais pas ce que l'etape 12
# verifie). RESTOCK_ID reste volontairement NON supprime par ce script (pile jetable,
# detruite a la sortie de toute facon).
RESTOCK_CREATE="$(run_curl -s -w '\n%{http_code}' -b "$JAR" \
  -H "X-CSRF-Token: ${CSRF}" -H 'Content-Type: application/json' \
  -d '{"name":"Ingredient a reapprovisionner","unit":"unite","pack_size":10,"stock_capacity":100,"low_stock_pct":20,"critical_stock_pct":5}' \
  "http://admin.wakdo.test/admin/api/ingredients")"
RESTOCK_ID="$(printf '%s' "$RESTOCK_CREATE" | sed '$d' | grep -oE '"id":[0-9]+' | head -1 | grep -oE '[0-9]+')"
RESTOCK_STATUS="$(run_curl -s -o /dev/null -w '%{http_code}' -b "$JAR" \
  -H "X-CSRF-Token: ${CSRF}" -H 'Content-Type: application/json' \
  -d '{"packs":3,"note":"curl e2e"}' \
  "http://admin.wakdo.test/admin/api/ingredients/${RESTOCK_ID}/restock")"
check "POST restock" "$RESTOCK_STATUS" "200"

echo "[curl-e2e] 10) PATCH /admin/api/ingredients/{id} (methode non enregistree) -> 405"
PATCH_STATUS="$(run_curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -X PATCH \
  "http://admin.wakdo.test/admin/api/ingredients/${NEW_ID}")"
check "PATCH methode non enregistree" "$PATCH_STATUS" "405"

echo "[curl-e2e] 11) GET /admin/api/ingredients/999999 (introuvable) -> 404"
NF_STATUS="$(run_curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "http://admin.wakdo.test/admin/api/ingredients/999999")"
check "GET id introuvable" "$NF_STATUS" "404"

echo "[curl-e2e] 12) DELETE /admin/api/ingredients/{id} -> 200"
DEL_OUT="$(run_curl -s -w '\n%{http_code}' -b "$JAR" -X DELETE -H "X-CSRF-Token: ${CSRF}" \
  "http://admin.wakdo.test/admin/api/ingredients/${NEW_ID}")"
DEL_STATUS="$(printf '%s' "$DEL_OUT" | tail -1)"
DEL_BODY="$(printf '%s' "$DEL_OUT" | sed '$d')"
echo "    $DEL_BODY"
check "DELETE suppression" "$DEL_STATUS" "200"

echo "[curl-e2e] 13) revoquer ingredient.manage au role admin (SQL direct, pile jetable) -> 403 attendu"
DB_PASSWORD="$(grep -E '^DB_PASSWORD=' "$ENVFILE" | cut -d= -f2)"
docker exec "${PROJECT}-db" mariadb -uwakdo -p"${DB_PASSWORD}" wakdo -e \
  "DELETE rp FROM role_permission rp JOIN role r ON r.id = rp.role_id JOIN permission p ON p.id = rp.permission_id WHERE r.code='admin' AND p.code='ingredient.manage';"
FORBIDDEN_STATUS="$(run_curl -s -o /dev/null -w '%{http_code}' -b "$JAR" \
  -H "X-CSRF-Token: ${CSRF}" -H 'Content-Type: application/json' \
  -d '{"name":"Sans permission","unit":"unite","pack_size":10,"stock_capacity":100,"low_stock_pct":20,"critical_stock_pct":5}' \
  "http://admin.wakdo.test/admin/api/ingredients")"
check "POST sans permission" "$FORBIDDEN_STATUS" "403"

echo "[curl-e2e] 14) le vhost kiosk NE relaie PAS /admin/api/* (surface d'attaque)"
# Le vhost kiosk est un SPA-like fallback statique (vhost.conf) : une route inconnue
# y renvoie index.html (200, text/html) plutot qu'un 404, donc le code HTTP seul ne
# prouve rien. La preuve reelle est le CONTENU : le front controller admin repond en
# JSON (Content-Type application/json) avec notre enveloppe {data,error}. Si la
# reponse kiosk est HTML (le SPA borne) et ne contient pas notre code AUTH_REQUIRED,
# le front controller admin n'a jamais ete atteint.
KIOSK_OUT="$(run_curl -s -D /jar/h3.txt "http://kiosk.wakdo.test/admin/api/ingredients")"
KIOSK_CT="$(grep -i '^content-type:' "$JAR_DIR/h3.txt" 2>/dev/null | tr -d '\r')"
echo "    content-type kiosk: $KIOSK_CT"
if printf '%s' "$KIOSK_OUT" | grep -q 'AUTH_REQUIRED\|"data"'; then
  echo "FAIL /admin/api atteignable depuis le vhost kiosk (reponse JSON de notre API recue sur l'origine borne)"
  FAIL=1
else
  echo "OK   /admin/api non relaye par le vhost kiosk (reponse = fallback SPA statique de la borne, pas notre API JSON)"
fi

echo "[curl-e2e] 15) definir un PIN sur le compte de demo (HTML self-service, prerequis aux etapes PIN)"
PIN_HTML="$(run_curl -s -b "$JAR" "http://admin.wakdo.test/admin/profile/pin")"
PIN_CSRF="$(printf '%s' "$PIN_HTML" | grep -oE 'name="_csrf" value="[^"]+"' | head -1 | sed -E 's/.*value="([^"]+)".*/\1/')"
PIN_SET_STATUS="$(run_curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" \
  -d "_csrf=${PIN_CSRF}" --data-urlencode "current_password=${PASSWORD}" -d 'pin=4729' -d 'pin_confirm=4729' \
  "http://admin.wakdo.test/admin/profile/pin")"
check "POST /admin/profile/pin definit le PIN (302)" "$PIN_SET_STATUS" "302"

echo "[curl-e2e] 16) GET /admin/api/products (recuperer un produit DISPONIBLE du seed)"
PRODUCTS_JSON="$(run_curl -s -b "$JAR" "http://admin.wakdo.test/admin/api/products")"
PRODUCT_ID="$(python3 -c "
import json, sys
data = json.load(sys.stdin)
for p in data.get('data', []):
    if p.get('is_available'):
        print(p['id'])
        break
" <<< "$PRODUCTS_JSON")"
echo "    produit choisi = $PRODUCT_ID"

echo "[curl-e2e] 17) POST /admin/api/orders (saisie comptoir, source deduite du role) -> 201"
ORDER_A_OUT="$(run_curl -s -w '\n%{http_code}' -b "$JAR" \
  -H "X-CSRF-Token: ${CSRF}" -H 'Content-Type: application/json' \
  -d "{\"service_mode\":\"dine_in\",\"source\":\"counter\",\"items\":[{\"type\":\"product\",\"product_id\":${PRODUCT_ID},\"quantity\":1}]}" \
  "http://admin.wakdo.test/admin/api/orders")"
ORDER_A_STATUS="$(printf '%s' "$ORDER_A_OUT" | tail -1)"
ORDER_A_BODY="$(printf '%s' "$ORDER_A_OUT" | sed '$d')"
echo "    $ORDER_A_BODY"
check "POST creation commande comptoir" "$ORDER_A_STATUS" "201"
ORDER_A="$(printf '%s' "$ORDER_A_BODY" | grep -oE '"order_number":"[^"]+"' | head -1 | sed -E 's/.*:"([^"]+)"/\1/')"
echo "    commande creee = $ORDER_A"

echo "[curl-e2e] 18) POST /admin/api/orders/{number}/ready -> 200"
READY_STATUS="$(run_curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -X POST \
  -H "X-CSRF-Token: ${CSRF}" "http://admin.wakdo.test/admin/api/orders/${ORDER_A}/ready")"
check "POST ready" "$READY_STATUS" "200"

echo "[curl-e2e] 19) POST /admin/api/orders/{number}/deliver -> 200"
DELIVER_STATUS="$(run_curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -X POST \
  -H "X-CSRF-Token: ${CSRF}" "http://admin.wakdo.test/admin/api/orders/${ORDER_A}/deliver")"
check "POST deliver" "$DELIVER_STATUS" "200"

echo "[curl-e2e] 20) creer une seconde commande pour l'annulation"
ORDER_B_OUT="$(run_curl -s -w '\n%{http_code}' -b "$JAR" \
  -H "X-CSRF-Token: ${CSRF}" -H 'Content-Type: application/json' \
  -d "{\"service_mode\":\"dine_in\",\"source\":\"counter\",\"items\":[{\"type\":\"product\",\"product_id\":${PRODUCT_ID},\"quantity\":1}]}" \
  "http://admin.wakdo.test/admin/api/orders")"
ORDER_B_BODY="$(printf '%s' "$ORDER_B_OUT" | sed '$d')"
ORDER_B="$(printf '%s' "$ORDER_B_BODY" | grep -oE '"order_number":"[^"]+"' | head -1 | sed -E 's/.*:"([^"]+)"/\1/')"
echo "    commande creee = $ORDER_B"

echo "[curl-e2e] 21) POST .../cancel avec un PIN FAUX -> 422 PIN_INVALID, aucune transition"
CANCEL_WRONG_OUT="$(run_curl -s -w '\n%{http_code}' -b "$JAR" \
  -H "X-CSRF-Token: ${CSRF}" -H 'Content-Type: application/json' \
  -d "{\"pin_email\":\"${EMAIL}\",\"pin\":\"0000\"}" \
  "http://admin.wakdo.test/admin/api/orders/${ORDER_B}/cancel")"
CANCEL_WRONG_STATUS="$(printf '%s' "$CANCEL_WRONG_OUT" | tail -1)"
CANCEL_WRONG_BODY="$(printf '%s' "$CANCEL_WRONG_OUT" | sed '$d')"
echo "    $CANCEL_WRONG_BODY"
check "POST cancel PIN faux" "$CANCEL_WRONG_STATUS" "422"

echo "[curl-e2e] 22) POST .../cancel avec le PIN JUSTE -> 200, statut annule"
CANCEL_OK_OUT="$(run_curl -s -w '\n%{http_code}' -b "$JAR" \
  -H "X-CSRF-Token: ${CSRF}" -H 'Content-Type: application/json' \
  -d "{\"pin_email\":\"${EMAIL}\",\"pin\":\"4729\"}" \
  "http://admin.wakdo.test/admin/api/orders/${ORDER_B}/cancel")"
CANCEL_OK_STATUS="$(printf '%s' "$CANCEL_OK_OUT" | tail -1)"
CANCEL_OK_BODY="$(printf '%s' "$CANCEL_OK_OUT" | sed '$d')"
echo "    $CANCEL_OK_BODY"
check "POST cancel PIN correct" "$CANCEL_OK_STATUS" "200"

echo "[curl-e2e] 23) GET /admin/api/stats -> 200"
STATS_STATUS="$(run_curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "http://admin.wakdo.test/admin/api/stats")"
check "GET stats" "$STATS_STATUS" "200"

echo "[curl-e2e] 24) POST /admin/api/ingredients/{id}/restock avec packs=2.9 -> 422 (point 1, pas de troncature)"
FLOAT_STATUS="$(run_curl -s -o /dev/null -w '%{http_code}' -b "$JAR" \
  -H "X-CSRF-Token: ${CSRF}" -H 'Content-Type: application/json' \
  -d '{"packs":2.9,"note":"curl e2e float"}' \
  "http://admin.wakdo.test/admin/api/ingredients/${RESTOCK_ID}/restock")"
check "POST restock packs=2.9" "$FLOAT_STATUS" "422"

echo "[curl-e2e] 25) POST /admin/api/categories avec name=[\"Boissons\"] (non scalaire) -> 422, jamais cast en \"Array\""
ARRAY_OUT="$(run_curl -s -w '\n%{http_code}' -b "$JAR" \
  -H "X-CSRF-Token: ${CSRF}" -H 'Content-Type: application/json' \
  -d '{"name":["Boissons"],"slug":"boissons-curl-e2e"}' \
  "http://admin.wakdo.test/admin/api/categories")"
ARRAY_STATUS="$(printf '%s' "$ARRAY_OUT" | tail -1)"
ARRAY_BODY="$(printf '%s' "$ARRAY_OUT" | sed '$d')"
echo "    $ARRAY_BODY"
check "POST categorie name non scalaire" "$ARRAY_STATUS" "422"
if printf '%s' "$ARRAY_BODY" | grep -q '"name":"Array"'; then
  echo "FAIL le champ name a ete caste silencieusement en la chaine \"Array\""
  FAIL=1
fi

echo "[curl-e2e] 26) POST /admin/api/categories avec Content-Type: text/plain (corps non vide) -> 415"
# categories (category.manage), pas ingredients : le role admin de cette pile a deja
# perdu ingredient.manage a l'etape 13, ce qui masquerait le 415 derriere un 403
# (guardApi() s'execute avant le controle de Content-Type).
CT_STATUS="$(run_curl -s -o /dev/null -w '%{http_code}' -b "$JAR" \
  -H "X-CSRF-Token: ${CSRF}" -H 'Content-Type: text/plain' \
  -d '{"name":"Categorie mauvais content-type","slug":"categorie-mauvais-content-type"}' \
  "http://admin.wakdo.test/admin/api/categories")"
check "POST Content-Type non-JSON" "$CT_STATUS" "415"

echo "[curl-e2e] 27) GET /admin/api/orders/INCONNU-000 (numero inexistant) -> 403, pas 404 (anti-enumeration, point 8)"
UNKNOWN_ORDER_STATUS="$(run_curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "http://admin.wakdo.test/admin/api/orders/INCONNU-000")"
check "GET commande inconnue" "$UNKNOWN_ORDER_STATUS" "403"

echo "[curl-e2e] 28) creer un compte manager (role_id deduit de GET /admin/api/roles), connexion, commande drive explicite (point 9)"
ROLES_JSON="$(run_curl -s -b "$JAR" "http://admin.wakdo.test/admin/api/roles")"
MANAGER_ROLE_ID="$(python3 -c "
import json, sys
data = json.load(sys.stdin)
for r in data.get('data', []):
    if r.get('code') == 'manager':
        print(r['id'])
        break
" <<< "$ROLES_JSON")"
echo "    manager role_id = $MANAGER_ROLE_ID"

MANAGER_EMAIL="manager-curl-e2e@wakdo.test"
MANAGER_PASSWORD="ManagerCurlE2e!2026"
MANAGER_CREATE_OUT="$(run_curl -s -w '\n%{http_code}' -b "$JAR" \
  -H "X-CSRF-Token: ${CSRF}" -H 'Content-Type: application/json' \
  -d "{\"email\":\"${MANAGER_EMAIL}\",\"first_name\":\"Manager\",\"last_name\":\"CurlE2e\",\"role_id\":${MANAGER_ROLE_ID},\"password\":\"${MANAGER_PASSWORD}\",\"pin_email\":\"${EMAIL}\",\"pin\":\"4729\"}" \
  "http://admin.wakdo.test/admin/api/users")"
MANAGER_CREATE_STATUS="$(printf '%s' "$MANAGER_CREATE_OUT" | tail -1)"
MANAGER_CREATE_BODY="$(printf '%s' "$MANAGER_CREATE_OUT" | sed '$d')"
echo "    $MANAGER_CREATE_BODY"
check "POST creation manager" "$MANAGER_CREATE_STATUS" "201"
MANAGER_ID="$(printf '%s' "$MANAGER_CREATE_BODY" | grep -oE '"id":[0-9]+' | head -1 | grep -oE '[0-9]+')"

# Le seed RBAC (db/seeds/0001_rbac_and_reference.sql) refuse deliberement tout
# order.* au role manager (aucune commande, meme creation) : ce n'est donc pas
# le role a utiliser pour verifier une permission de commande. Pour isoler la
# regle testee ici (point 9 : un role SANS canal fixe doit pouvoir CHOISIR sa
# source), on accorde order.create/order.read a titre PUREMENT ponctuel sur
# cette pile jetable (meme technique que la revocation SQL directe de l'etape
# 13) : ca ne modifie ni le seed ni le code, seulement cette base ephemere.
docker exec "${PROJECT}-db" mariadb -uwakdo -p"${DB_PASSWORD}" wakdo -e \
  "INSERT INTO role_permission (role_id, permission_id) SELECT r.id, p.id FROM role r JOIN permission p ON p.code IN ('order.create','order.read') WHERE r.code='manager' AND NOT EXISTS (SELECT 1 FROM role_permission rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);"

MANAGER_JAR="/jar/manager-cookies.txt"
MANAGER_LOGIN_HTML="$(run_curl -s -c "$MANAGER_JAR" "http://admin.wakdo.test/login")"
MANAGER_LOGIN_CSRF="$(printf '%s' "$MANAGER_LOGIN_HTML" | grep -oE 'name="_csrf" value="[^"]+"' | head -1 | sed -E 's/.*value="([^"]+)".*/\1/')"
MANAGER_LOGIN_STATUS="$(run_curl -s -o /dev/null -w '%{http_code}' -b "$MANAGER_JAR" -c "$MANAGER_JAR" \
  -d "_csrf=${MANAGER_LOGIN_CSRF}" --data-urlencode "email=${MANAGER_EMAIL}" --data-urlencode "password=${MANAGER_PASSWORD}" \
  "http://admin.wakdo.test/login")"
check "POST /login manager (302)" "$MANAGER_LOGIN_STATUS" "302"

MANAGER_ME_JSON="$(run_curl -s -b "$MANAGER_JAR" "http://admin.wakdo.test/admin/me")"
MANAGER_CSRF="$(printf '%s' "$MANAGER_ME_JSON" | grep -oE '"csrf_token":"[^"]+"' | sed -E 's/.*:"([^"]+)"/\1/')"

MANAGER_ORDER_OUT="$(run_curl -s -w '\n%{http_code}' -b "$MANAGER_JAR" \
  -H "X-CSRF-Token: ${MANAGER_CSRF}" -H 'Content-Type: application/json' \
  -d "{\"service_mode\":\"drive\",\"source\":\"drive\",\"items\":[{\"type\":\"product\",\"product_id\":${PRODUCT_ID},\"quantity\":1}]}" \
  "http://admin.wakdo.test/admin/api/orders")"
MANAGER_ORDER_STATUS="$(printf '%s' "$MANAGER_ORDER_OUT" | tail -1)"
MANAGER_ORDER_BODY="$(printf '%s' "$MANAGER_ORDER_OUT" | sed '$d')"
echo "    $MANAGER_ORDER_BODY"
check "POST commande drive par un manager (source choisie explicitement)" "$MANAGER_ORDER_STATUS" "201"
if printf '%s' "$MANAGER_ORDER_BODY" | grep -q '"source":"drive"'; then
  echo "OK   la commande porte bien source=drive"
else
  echo "FAIL la commande ne porte pas source=drive"
  FAIL=1
fi

echo "[curl-e2e] 29) PUT /admin/api/users/{id} SANS is_active -> le compte reste actif (PUT partiel, point 10)"
PUT_NOACTIVE_STATUS="$(run_curl -s -o /dev/null -w '%{http_code}' -b "$JAR" \
  -X PUT -H "X-CSRF-Token: ${CSRF}" -H 'Content-Type: application/json' \
  -d "{\"email\":\"${MANAGER_EMAIL}\",\"first_name\":\"Manager\",\"last_name\":\"CurlE2eV2\",\"role_id\":${MANAGER_ROLE_ID},\"pin_email\":\"${EMAIL}\",\"pin\":\"4729\"}" \
  "http://admin.wakdo.test/admin/api/users/${MANAGER_ID}")"
check "PUT utilisateur sans is_active" "$PUT_NOACTIVE_STATUS" "200"

MANAGER_SHOW_JSON="$(run_curl -s -b "$JAR" "http://admin.wakdo.test/admin/api/users/${MANAGER_ID}")"
echo "    $MANAGER_SHOW_JSON"
if printf '%s' "$MANAGER_SHOW_JSON" | grep -q '"is_active":true'; then
  echo "OK   is_active preserve a true malgre son absence du corps PUT"
else
  echo "FAIL is_active n'a pas ete preserve (desactivation par omission)"
  FAIL=1
fi

echo "[curl-e2e] 30) POST restock avec note:[\"x\"] (non scalaire) -> 422, jamais enregistre comme \"Array\" (relecture 2, point 1)"
NOTE_ARRAY_OUT="$(run_curl -s -w '\n%{http_code}' -b "$JAR" \
  -H "X-CSRF-Token: ${CSRF}" -H 'Content-Type: application/json' \
  -d '{"packs":2,"note":["x"]}' \
  "http://admin.wakdo.test/admin/api/ingredients/${RESTOCK_ID}/restock")"
NOTE_ARRAY_STATUS="$(printf '%s' "$NOTE_ARRAY_OUT" | tail -1)"
NOTE_ARRAY_BODY="$(printf '%s' "$NOTE_ARRAY_OUT" | sed '$d')"
echo "    $NOTE_ARRAY_BODY"
check "POST restock note non scalaire" "$NOTE_ARRAY_STATUS" "422"
if printf '%s' "$NOTE_ARRAY_BODY" | grep -q '"note":"Array"'; then
  echo "FAIL le champ note a ete caste silencieusement en la chaine \"Array\""
  FAIL=1
fi

echo "[curl-e2e] 31) PUT utilisateur avec is_active:\"false\" (chaine, pas booleen JSON) -> 422 (relecture 2, point 6)"
STRFALSE_STATUS="$(run_curl -s -o /dev/null -w '%{http_code}' -b "$JAR" \
  -X PUT -H "X-CSRF-Token: ${CSRF}" -H 'Content-Type: application/json' \
  -d "{\"email\":\"${MANAGER_EMAIL}\",\"first_name\":\"Manager\",\"last_name\":\"CurlE2eV2\",\"role_id\":${MANAGER_ROLE_ID},\"is_active\":\"false\",\"pin_email\":\"${EMAIL}\",\"pin\":\"4729\"}" \
  "http://admin.wakdo.test/admin/api/users/${MANAGER_ID}")"
check "PUT is_active chaine \"false\"" "$STRFALSE_STATUS" "422"

echo "[curl-e2e] 32) POST .../cancel d'un numero INCONNU -> 403, pas 404, AVANT le PIN (relecture 2, point 3)"
CANCEL_UNKNOWN_OUT="$(run_curl -s -w '\n%{http_code}' -b "$JAR" \
  -H "X-CSRF-Token: ${CSRF}" -H 'Content-Type: application/json' \
  -d "{\"pin_email\":\"${EMAIL}\",\"pin\":\"4729\"}" \
  "http://admin.wakdo.test/admin/api/orders/INCONNU-999/cancel")"
CANCEL_UNKNOWN_STATUS="$(printf '%s' "$CANCEL_UNKNOWN_OUT" | tail -1)"
CANCEL_UNKNOWN_BODY="$(printf '%s' "$CANCEL_UNKNOWN_OUT" | sed '$d')"
echo "    $CANCEL_UNKNOWN_BODY"
check "POST cancel numero inconnu" "$CANCEL_UNKNOWN_STATUS" "403"

rm -rf "$JAR_DIR"

if [ "$FAIL" != 0 ]; then
  echo "[curl-e2e] ECHEC : au moins une verification a echoue"
  exit 1
fi
echo "[curl-e2e] TOUT EST VERT"
