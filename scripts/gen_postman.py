#!/usr/bin/env python3
"""Genere docs/api/wakdo-admin.postman_collection.json (Postman Schema v2.1).

Script LIVRE (fait partie du depot, pas un brouillon jete apres usage) : c'est la
SOURCE DE VERITE de la collection Postman -- on modifie ce fichier pour changer un
contrat d'endpoint, jamais le JSON genere directement a la main (fragile, difficile
a relire et a diffuser en revue). Reexecuter ce script regenere le fichier a
l'identique (idempotent) si aucune requete n'a change ; toute modification de
contrat (nouvel endpoint, nouveau champ) passe par une modification ICI puis une
regeneration.

IDEMPOTENCE D'EXECUTION (chantier demo-jury, distincte de l'idempotence de
GENERATION ci-dessus) : chaque requete "Creer ..." nomme sa ressource avec le
suffixe `{{run}}` (horodatage court, pose par le script de test de "Se connecter",
Date.now().toString(36) -- change a CHAQUE execution) : deux executions
consecutives de la collection entiere, sur la MEME base, ne se heurtent jamais a
un doublon (slug/code/email deja pris, 409 CONFLICT). Chaque ressource creee est
rangee dans une variable d'environnement (`created_<ressource>_id`) par le script
de test de sa requete de creation ; les GET/PUT/DELETE qui suivent DANS LE MEME
dossier la reutilisent -- aucun id n'est ecrit en dur pour une ressource CREE par
cette collection (un id de reference STABLE et FIGE au seed -- ex. le catalogue de
permissions, un ingredient de base -- reste, lui, un litteral documente : ce n'est
pas la meme categorie de donnee, cf. les commentaires plus bas). Chaque dossier CRUD
(Categories, Produits, Menus, Ingredients, Utilisateurs, Roles) supprime ou
desactive ce qu'il a cree -- verifie par ce meme script en execution DOUBLE (voir
le rapport E2E du commit). CECI NE VEUT PAS DIRE QUE LA BASE ENTIERE RESTE PROPRE :
le dossier Commandes cree des commandes REELLEMENT ENCAISSEES (decrement de stock,
comptage dans les statistiques), assume et documente en detail dans
docs/api/demo-api.md, section "Effets de bord" (avec la consigne d'y lancer
scripts/demo-reset.sh si present, sinon de ne pas lancer ce dossier contre une
base de production).

Usage :
    python3 scripts/gen_postman.py            # regenere la collection
    python3 scripts/gen_postman.py --help      # affiche cette aide, n'ecrit rien
"""
import json
import sys
import uuid

if "--help" in sys.argv or "-h" in sys.argv:
    print(__doc__)
    sys.exit(0)

def uid():
    # UUID DETERMINISTE (uuid5, pas uuid4 aleatoire) : la collection Postman
    # reste vraiment identique (idempotente) d'une regeneration a l'autre, y
    # compris ce champ -- indispensable pour qu'un `git diff` apres regeneration
    # ne montre RIEN quand aucun endpoint n'a change (relecture adverse, point 6).
    # Namespace + nom fixes -> meme UUID a chaque execution, sur n'importe quelle
    # machine.
    return str(uuid.uuid5(uuid.NAMESPACE_URL, "https://wakdo.local/docs/api/wakdo-admin.postman_collection.json"))

BASE = "{{baseUrl}}"

def csrf_header(varname="csrf"):
    return {"key": "X-CSRF-Token", "value": "{{" + varname + "}}", "type": "text"}

def json_content_type():
    return {"key": "Content-Type", "value": "application/json", "type": "text"}

def url(path):
    # Chemin ET chaine de requete SEPARES (schema Postman v2.1) : une URL comme
    # "/admin/api/products/import?dry_run=1" decoupee sur "/" SANS retirer sa
    # chaine de requete produirait un dernier segment "import?dry_run=1" -- Postman/
    # Newman reconstruisent l'URL envoyee depuis path+query (pas depuis 'raw' seul),
    # ce "?" finirait donc URL-encode a l'interieur d'un segment de chemin au lieu
    # d'introduire une vraie chaine de requete (bloquant probable, releve au
    # contre-audit du 29/09). 'raw' reste INCHANGE (chaine complete, y compris "?...")
    # : c'est le seul champ que lit tests/e2e/health-capture.spec.js (new URL(...).
    # pathname), donc ce correctif ne le concerne pas.
    path_part, sep, query_part = path.partition("?")
    result = {
        "raw": BASE + path,
        "host": [BASE],
        "path": [p for p in path_part.split("/") if p != ""],
    }
    if sep:
        query = []
        for pair in query_part.split("&"):
            if pair == "":
                continue
            key, _, value = pair.partition("=")
            query.append({"key": key, "value": value})
        result["query"] = query
    return result

def body(obj):
    return {
        "mode": "raw",
        "raw": json.dumps(obj, ensure_ascii=False, indent=2),
        "options": {"raw": {"language": "json"}},
    }

# --- Environnement (Postman + Bruno) : SOURCE UNIQUE ------------------------
# Contre-audit du 29/09 (constat 2) : gen_bruno.py codait un DEUXIEME jeu de
# cles/valeurs en dur (baseUrl notamment, reste sur "http://localhost:8080" alors
# que le fichier .bru COMMIS avait ete corrige a la main en
# "http://admin.localhost:8080", seule valeur qui correspond au vhost admin --
# .env.example APP_HOST_ADMIN) -- deux sources qui pouvaient diverger sans qu'aucun
# generateur ne le releve. gen_bruno.py IMPORTE desormais cette liste plutot que
# d'en maintenir une deuxieme copie ; docs/api/wakdo.postman_environment.json,
# jusqu'ici maintenu a la main (aucun script ne l'ecrivait), est lui aussi
# desormais genere d'ici (write_postman_environment(), plus bas).
#
# Chaque tuple : (cle, valeur par defaut committee, secret?). Une cle secrete
# n'est JAMAIS committee avec une valeur (fournie a l'usage par --env-var, section
# 6 de docs/api/demo-api.md) : Postman la type "secret" (masquee a l'affichage,
# valeur vide ici) ; Bruno la liste UNIQUEMENT dans vars:secret[] (format officiel,
# docs.usebruno.com/variables/environment-variables), jamais aussi dans vars{}
# (doublon corrige par ce meme chantier, constat 3 -- une valeur secrete Bruno se
# fournit par --env-var, pas par une entree vide dupliquee dans vars{}).
#
# categoryId/productId/menuId/ingredientId/userId/roleId/orderNumber (presentes
# dans une version anterieure des deux fichiers d'environnement) sont ABSENTES
# ICI : aucune requete de ce generateur ne les lit ni ne les ecrit (verifie par
# recherche sur ce fichier) -- variables mortes, retirees par ce meme chantier
# (constat 4). Les variables reellement utilisees (created_category_id,
# burgersCategoryId, run, ...) restent volontairement HORS de cette liste : elles
# sont posees dynamiquement par les scripts de test au fil de l'execution
# (Postman/Bruno creent une variable d'environnement des le premier
# set/setEnvVar, sans declaration prealable requise), jamais committees.
ENV_VARS = [
    ("baseUrl", "http://admin.localhost:8080", False),
    ("email", "", False),
    ("password", "", True),
    ("csrf", "", True),
    ("pin_email", "{{email}}", False),
    ("pin", "", True),
    ("email_manager", "", False),
    ("password_manager", "", True),
    ("csrf_manager", "", True),
    ("email_cuisine", "", False),
    ("password_cuisine", "", True),
    ("csrf_cuisine", "", True),
    ("email_comptoir", "", False),
    ("password_comptoir", "", True),
    ("csrf_comptoir", "", True),
    ("email_drive", "", False),
    ("password_drive", "", True),
    ("csrf_drive", "", True),
]

# Registre des scripts Bruno ECRITS A LA MAIN (motif trop specifique pour la
# traduction generique par expression reguliere de gen_bruno.py -- recherche
# dans une reponse, comparaisons, branchements) : cle = nom de la requete
# (unique dans cette collection), valeur = {"script": [...], "tests": [...]}
# en syntaxe Bruno NATIVE (bru.setEnvVar/bru.getEnvVar/res.getBody(), pas
# pm.*). gen_bruno.py les consulte AVANT d'essayer sa traduction par motif.
BRUNO_OVERRIDES = {}

def request(name, method, path, headers=None, json_body=None, description="", tests=None, csrf=True, csrf_var="csrf", bruno_script=None, bruno_tests=None):
    # Aucun en-tete Cookie manuel (relecture chantier connexion JSON) : Postman
    # garde le cookie de session TOUT SEUL, dans son pot a cookies par domaine,
    # une fois pose par la reponse de "Se connecter" (cf. docs/api/demo-api.md).
    hdrs = []
    if json_body is not None or method in ("POST", "PUT", "DELETE"):
        hdrs.append(json_content_type())
    if csrf and method in ("POST", "PUT", "DELETE"):
        hdrs.append(csrf_header(csrf_var))
    if headers:
        hdrs.extend(headers)

    item = {
        "name": name,
        "request": {
            "method": method,
            "header": hdrs,
            "url": url(path),
            "description": description,
        },
    }
    if json_body is not None:
        item["request"]["body"] = body(json_body)

    if tests:
        item["event"] = [
            {
                "listen": "test",
                "script": {"type": "text/javascript", "exec": tests},
            }
        ]

    if bruno_script is not None or bruno_tests is not None:
        if name in BRUNO_OVERRIDES:
            raise ValueError(f"BRUNO_OVERRIDES: nom de requete en double ({name!r}) -- la cle doit rester unique dans toute la collection.")
        BRUNO_OVERRIDES[name] = {"script": bruno_script or [], "tests": bruno_tests or []}

    return item

def make_dual(template):
    """Un GABARIT unique -> (lignes Postman, lignes Bruno), pour ne jamais
    ecrire deux fois la meme logique de recherche/comparaison avec le risque
    de divergence que ca implique. Jetons remplaces :
      __BODY__  -> accesseur du corps de reponse deja parse
      __SET__   -> pose d'une variable d'environnement
      __GET__   -> lecture d'une variable d'environnement (renvoie une CHAINE
                   dans les deux outils, meme quand la valeur posee etait un
                   nombre -- cf. docs.usebruno.com/testing/script/javascript-
                   reference et le comportement Postman equivalent)."""
    pm_js = (
        template.replace("__BODY__", "pm.response.json()")
        .replace("__SET__", "pm.environment.set")
        .replace("__GET__", "pm.environment.get")
    )
    bru_js = (
        template.replace("__BODY__", "res.getBody()")
        .replace("__SET__", "bru.setEnvVar")
        .replace("__GET__", "bru.getEnvVar")
    )
    return pm_js.split("\n"), bru_js.split("\n")

CSRF_CAPTURE_TEST = [
    "const body = pm.response.json();",
    "if (body && body.data && body.data.csrf_token) {",
    "    pm.environment.set('csrf', body.data.csrf_token);",
    "}",
]

# `run` : horodatage court (base36), pose UNE FOIS par execution complete de la
# collection (des la premiere requete, "Se connecter") -- change a CHAQUE
# execution, donc chaque requete "Creer ..." qui l'inclut dans son nom/slug/
# code produit un identifiant NEUF a chaque passage. C'est ce qui rend deux
# executions consecutives, sur la MEME base, non conflictuelles (plus de 409
# sur une deuxieme execution -- constate et corrige pendant ce chantier).
LOGIN_TEST = [
    "pm.test('200 OK', function () { pm.response.to.have.status(200); });",
    "pm.environment.set('run', Date.now().toString(36));",
] + CSRF_CAPTURE_TEST
LOGIN_BRUNO_SCRIPT = [
    "const body = res.getBody();",
    "bru.setEnvVar('run', Date.now().toString(36));",
    "if (body && body.data && body.data.csrf_token) { bru.setEnvVar('csrf', body.data.csrf_token); }",
]
LOGIN_BRUNO_TESTS = ["test('200 OK', function () { expect(res.getStatus()).to.equal(200); });"]

def id_capture(varname):
    return [
        "const body = pm.response.json();",
        "if (body && body.data && body.data.id) {",
        f"    pm.environment.set('{varname}', body.data.id);",
        "}",
    ]

def expect_status(status, label):
    return [f"pm.test('{label}', function () {{ pm.response.to.have.status({status}); }});"]

def status_test_bru(status, label):
    """Equivalent Bruno d'expect_status() : a utiliser explicitement partout ou une
    requete porte deja un bruno_script/bruno_tests SUR MESURE (motif de recherche,
    cf. make_dual()) -- ces requetes sont enregistrees dans BRUNO_OVERRIDES et ne
    beneficient donc PAS de la traduction generique par expression reguliere de
    gen_bruno.py (qui, elle, reconnait automatiquement le motif pm.test(...) ecrit
    par expect_status()/created_test() et le retraduit en test(...) Bruno)."""
    return [f"test('{label}', function () {{ expect(res.getStatus()).to.equal({status}); }});"]

def created_test(status, varname):
    """201 Created + capture de l'id cree -- motif le plus courant des dossiers
    CRUD, factorise une fois pour ne pas repeter la meme paire partout."""
    return [f"pm.test('{status} Created', function () {{ pm.response.to.have.status({status}); }});"] + id_capture(varname)

# --- Gabarits de recherche dynamique (point 4 : aucun id de ressource CREE ou
# variable selon le seed n'est ecrit en dur ; recupere par un GET precedent) ---

CATEGORY_LOOKUP_TEMPLATE = """const body = __BODY__;
if (body && body.data) {
    var findSlug = function (slug) { return body.data.find(function (c) { return c.slug === slug; }); };
    var burgers = findSlug('burgers');
    var boissons = findSlug('boissons');
    var menus = findSlug('menus');
    if (burgers) { __SET__('burgersCategoryId', burgers.id); }
    if (boissons) { __SET__('boissonsCategoryId', boissons.id); }
    if (menus) { __SET__('menusCategoryId', menus.id); }
}"""
CATEGORY_LOOKUP_PM, CATEGORY_LOOKUP_BRU = make_dual(CATEGORY_LOOKUP_TEMPLATE)

PRODUCT_LOOKUP_TEMPLATE = """const body = __BODY__;
if (body && body.data) {
    var burgersCat = Number(__GET__('burgersCategoryId'));
    var boissonsCat = Number(__GET__('boissonsCategoryId'));
    var burger = body.data.find(function (p) { return p.category_id === burgersCat && !p.base_product_id; });
    var drink = body.data.find(function (p) { return p.category_id === boissonsCat && !p.base_product_id; });
    if (burger) { __SET__('burgerBaseProductId', burger.id); }
    if (drink) { __SET__('drinkBaseProductId', drink.id); }
}"""
PRODUCT_LOOKUP_PM, PRODUCT_LOOKUP_BRU = make_dual(PRODUCT_LOOKUP_TEMPLATE)

# is_orderable (pas is_available) : contre-audit du 29/09, constat 6. GET
# /admin/api/products (ProductApiController::present()) n'expose QUE is_available
# (flag manuel, retrait a la main) -- un produit is_available=1 peut malgre tout
# etre EN RUPTURE CALCULEE (RG-T21 : stock insuffisant sur un ingredient non
# retirable de sa recette), auquel cas OrderRepository::resolveLine refuse la
# ligne de commande a la creation. is_orderable (is_available ET pas de rupture
# calculee) n'existe que sur la lecture catalogue PUBLIQUE, ANONYME,
# /api/products (CatalogueController::presentProduct(), meme routeur -- table des
# routes du vhost admin, docs/api/conventions.md section 5.2) : c'est ELLE qu'on
# interroge ici, pas /admin/api/products, precisement pour choisir un produit
# reellement commandable.
ORDER_PRODUCT_LOOKUP_TEMPLATE = """const body = __BODY__;
if (body && body.data) {
    var orderable = body.data.find(function (p) { return p.is_orderable; });
    if (orderable) { __SET__('orderProductId', orderable.id); }
}"""
ORDER_PRODUCT_LOOKUP_PM, ORDER_PRODUCT_LOOKUP_BRU = make_dual(ORDER_PRODUCT_LOOKUP_TEMPLATE)

# Role NON-ADMIN de preference (contre-audit du 29/09, constat 6) : GET
# /admin/api/roles renvoie les roles ORDER BY id (RoleRepository::allRoles()), et
# 'admin' est le premier role insere par le seed (db/seeds/0001_rbac_and_reference.sql)
# -- body.data[0] etait donc TOUJOURS le role admin, si bien que chaque execution
# de la collection creait un compte "equipier" temporaire cumulant les 23
# permissions admin. UserController::validate() n'exige qu'un role EXISTANT et
# ACTIF ("n'importe quel role actif convient", cf. commentaire du dossier
# Utilisateurs plus bas) : aucune permission particuliere n'est requise pour le
# parcours demontre ici, donc un role non-admin (en pratique 'manager', premier
# role non-admin du seed) remplit le meme role sans le meme risque. Repli sur le
# premier role si, par hypothese degenerative, aucun role non-admin actif
# n'existe (jamais le cas sur le seed livre).
ROLE_LIST_LOOKUP_TEMPLATE = """const body = __BODY__;
if (body && body.data && body.data.length > 0) {
    var nonAdmin = body.data.find(function (r) { return r.code !== 'admin' && r.is_active; });
    var chosen = nonAdmin || body.data[0];
    __SET__('firstRoleId', chosen.id);
}"""
ROLE_LIST_LOOKUP_PM, ROLE_LIST_LOOKUP_BRU = make_dual(ROLE_LIST_LOOKUP_TEMPLATE)

ROLE_PERMISSION_LOOKUP_TEMPLATE = """const body = __BODY__;
if (body && body.data && Array.isArray(body.data.permission_ids) && body.data.permission_ids.length > 0) {
    __SET__('examplePermissionId', body.data.permission_ids[0]);
}"""
ROLE_PERMISSION_LOOKUP_PM, ROLE_PERMISSION_LOOKUP_BRU = make_dual(ROLE_PERMISSION_LOOKUP_TEMPLATE)

def order_number_capture_dual(varname):
    template = f"""const body = __BODY__;
if (body && body.data && body.data.order_number) {{
    __SET__('{varname}', body.data.order_number);
}}"""
    return make_dual(template)

def status_and_active_dual(status, expected_active, label):
    """Assertion composite (code HTTP + champ is_active du corps) : la seule
    forme de test, dans cette collection, qui inspecte un CHAMP de la reponse
    plutot que le seul code -- d'ou l'ecriture manuelle des deux variantes
    (BRUNO_OVERRIDES) plutot que la traduction generique par motif."""
    js_bool = "true" if expected_active else "false"
    pm_lines = [
        "const body = pm.response.json();",
        f"pm.test('{label}', function () {{",
        f"    pm.response.to.have.status({status});",
        f"    pm.expect(body.data.is_active).to.eql({js_bool});",
        "});",
    ]
    bru_lines = [
        "const body = res.getBody();",
        f"test('{label}', function () {{",
        f"    expect(res.getStatus()).to.equal({status});",
        f"    expect(body.data.is_active).to.equal({js_bool});",
        "});",
    ]
    return pm_lines, bru_lines

def validation_error_field_dual(field, label):
    """Assertion composite : 422 + error.code === VALIDATION_ERROR + le champ
    error.fields.<field> present -- ne se contente PAS du seul code HTTP (un
    422 peut venir d'un tout autre champ manquant/invalide que celui vise ici,
    ce qui rendrait la preuve RBAC fragile a un changement de validation sans
    rapport)."""
    pm_lines = [
        "const body = pm.response.json();",
        f"pm.test('{label}', function () {{",
        "    pm.response.to.have.status(422);",
        "    pm.expect(body.error.code).to.eql('VALIDATION_ERROR');",
        f"    pm.expect(body.error.fields).to.have.property('{field}');",
        "});",
    ]
    bru_lines = [
        "const body = res.getBody();",
        f"test('{label}', function () {{",
        "    expect(res.getStatus()).to.equal(422);",
        "    expect(body.error.code).to.equal('VALIDATION_ERROR');",
        f"    expect(body.error.fields).to.have.property('{field}');",
        "});",
    ]
    return pm_lines, bru_lines

items = []

# --- 1. Connexion (docs/api/conventions.md section 5.3bis) ---
# Remplace l'ancien dossier "Authentification" (qui supposait une session deja
# ouverte a la main dans un navigateur, cf. ADR-0017 addendum 2026-09-26).
# Aucun en-tete Cookie manuel, aucune variable 'sid' : Postman garde le cookie
# de session WAKDO_SID tout seul dans son pot a cookies des la reponse de
# "Se connecter" (cf. docs/api/demo-api.md pour la verification de ce
# comportement).
items.append({
    # "1." (pas "0.") : ce dossier est le PREMIER des onze dossiers de premier
    # niveau de la collection (rang 1, coherent avec le prefixe numerique sur
    # disque de gen_bruno.py, `enumerate(..., start=1)`) -- l'ancien "0." datait
    # d'une version a moins de dossiers et ne correspondait plus a son rang reel
    # (contre-audit du 29/09, constat 9 ; meme correctif pour "9. Fin de demo"
    # plus bas, devenu "11. Fin de demo", rang reel parmi les onze dossiers).
    "name": "1. Connexion",
    "item": [
        request(
            "Se connecter", "POST", "/admin/api/auth/login",
            json_body={"email": "{{email}}", "password": "{{password}}"},
            description=(
                "Ouvre la session JSON : pose le cookie WAKDO_SID (Postman le garde tout seul) "
                "et renvoie { user, permissions, csrf_token }. Le script de test range csrf_token "
                "dans la variable d'environnement 'csrf' ET pose 'run' (horodatage court, change a "
                "chaque execution -- rend toute la collection rejouable sans collision de nom/slug/"
                "code, cf. docstring du generateur). Aucun en-tete X-CSRF-Token envoye ICI : aucune "
                "session n'existe encore avant cette requete (protection = Content-Type + CORS + "
                "SameSite, docs/api/conventions.md section 5.3bis, PAS le jeton synchroniseur)."
            ),
            tests=LOGIN_TEST,
            csrf=False,
            bruno_script=LOGIN_BRUNO_SCRIPT,
            bruno_tests=LOGIN_BRUNO_TESTS,
        ),
        request(
            "Qui suis-je", "GET", "/admin/api/auth/me",
            description=(
                "Alias JSON de GET /admin/me (meme identite, memes permissions, meme csrf_token "
                "courant) : reste sous /admin/api/... pour que toute la demo utilise un seul "
                "prefixe. Utile pour rafraichir 'csrf' sans se reconnecter."
            ),
            tests=expect_status(200, "200 OK") + CSRF_CAPTURE_TEST,
        ),
    ],
})
# NOTE DE SEQUENCEMENT (verifie par Newman, cf. rapport E2E du commit) :
# "Se deconnecter" N'EST PAS ici, dans 1. Connexion. Un run COMPLET de la
# collection (Newman/bru run, de haut en bas) executerait sinon la deconnexion
# AVANT tous les dossiers CRUD suivants (Categories, Produits...), qui
# echoueraient tous en 401 -- casse constatee et corrigee pendant ce chantier.
# La deconnexion vit donc dans son propre dossier final (voir plus bas, apres
# RBAC), pour que l'ordre d'execution top-to-bottom reste un parcours de demo
# valide de bout en bout, REJOUABLE (deux executions consecutives = deux
# demos vertes, cf. docstring du generateur).

# --- Categories ---
# Cycle complet : creer -> lire -> modifier -> relire -> deplacer -> bascule ->
# rebascule -> desactiver (DELETE) -> relire (200, is_active=false -- pas de
# suppression dure, FK RESTRICT). Nom/slug uniques par execution ({{run}}) ; id
# capture (created_category_id), aucun id ecrit en dur.
#
# DEUX bascules (pas une seule) avant le DELETE final : correctif du
# contre-audit du 29/09 (constat 5). Une categorie est active a la creation ;
# UNE SEULE bascule la laisse deja masquee (is_active=false) AVANT le DELETE, si
# bien que le DELETE (qui ne fait QUE poser is_active=0, idempotent,
# CategoryRepository::setActive() sans changement reel sur une ligne deja a 0)
# ne prouvait plus rien de SPECIFIQUE a lui-meme -- la relecture finale aurait
# pu constater le meme etat sans que DELETE n'ait rien fait. La deuxieme
# bascule ramene la categorie a actif juste avant, pour que le DELETE fasse
# reellement la transition actif -> masque, seule preuve reelle de son propre
# effet (par opposition au simple constat d'un etat deja atteint par un autre
# appel). Les deux bascules portent chacune leur propre assertion is_active.
items.append({
    "name": "Categories",
    "item": [
        request(
            "Lister les categories", "GET", "/admin/api/categories",
            description="Capture aussi, pour les dossiers suivants (Produits/Menus), les ids des categories 'burgers'/'boissons'/'menus' par leur SLUG (stable, pas par id -- cf. docs/api/demo-api.md).",
            tests=expect_status(200, "200 OK") + CATEGORY_LOOKUP_PM,
            bruno_script=CATEGORY_LOOKUP_BRU,
            bruno_tests=status_test_bru(200, "200 OK"),
        ),
        request(
            "Creer une categorie", "POST", "/admin/api/categories",
            json_body={"name": "Demo {{run}}", "slug": "demo-{{run}}", "display_order": 50},
            description="category.manage, pas de PIN. 201 + Location. Nom/slug uniques par execution ({{run}}).",
            tests=created_test(201, "created_category_id"),
        ),
        request(
            "Lire une categorie", "GET", "/admin/api/categories/{{created_category_id}}",
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Modifier une categorie", "PUT", "/admin/api/categories/{{created_category_id}}",
            json_body={"name": "Demo {{run}} v2", "slug": "demo-{{run}}", "display_order": 51},
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Relire apres modification", "GET", "/admin/api/categories/{{created_category_id}}",
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Deplacer d'un rang (move)", "POST", "/admin/api/categories/{{created_category_id}}/move",
            json_body={"direction": "up"},
            description='direction : "up" ou "down".',
            tests=expect_status(200, "200 OK"),
        ),
    ],
})
_cat_toggle1_pm, _cat_toggle1_bru = status_and_active_dual(200, False, "200, is_active=false (bascule 1/2)")
items[-1]["item"].append(request(
    "Basculer visible/masque (toggle)", "POST", "/admin/api/categories/{{created_category_id}}/toggle",
    description="Bascule dans les DEUX sens (contrairement a DELETE qui ne masque que). Premiere bascule : actif (creation) -> masque.",
    tests=_cat_toggle1_pm,
    bruno_tests=_cat_toggle1_bru,
))
_cat_toggle2_pm, _cat_toggle2_bru = status_and_active_dual(200, True, "200, is_active=true (bascule 2/2)")
items[-1]["item"].append(request(
    "Rebasculer visible (toggle) -- pour que la suppression prouve son propre effet", "POST", "/admin/api/categories/{{created_category_id}}/toggle",
    description=(
        "Deuxieme bascule : masque -> actif. Sans elle, le DELETE ci-dessous ne ferait que "
        "confirmer un etat DEJA masque par la bascule precedente (setActive() idempotent, "
        "sans changement reel), et la relecture finale ne prouverait rien de specifique au "
        "DELETE lui-meme (releve du contre-audit du 29/09, constat 5). Avec cette deuxieme "
        "bascule, la categorie est de nouveau active juste avant le DELETE."
    ),
    tests=_cat_toggle2_pm,
    bruno_tests=_cat_toggle2_bru,
))
items[-1]["item"].append(request(
    "Desactiver une categorie (DELETE = is_active=0)", "DELETE", "/admin/api/categories/{{created_category_id}}",
    description="Pas de suppression dure (FK RESTRICT) : bascule is_active=0, meme geste que le bouton Masquer du back-office. Etat terminal de ce dossier pour CETTE ressource (categorie desactivee, pas supprimee). Vient de la bascule 2/2 (actif) : cette requete fait donc reellement la transition, ce que la relecture ci-dessous verifie.",
    tests=expect_status(200, "200 OK"),
))
# La derniere requete (relecture post-suppression) utilise l'assertion
# composite status+is_active : ecrite a la main pour les deux outils
# (status_and_active_dual(), pas la traduction generique par motif), ajoutee
# apres coup pour rester lisible (pas d'argument-tuple deballe dans un appel
# a plusieurs lignes deja charge).
_cat_pm, _cat_bru = status_and_active_dual(200, False, "200, is_active=false")
items[-1]["item"].append(request(
    "Relire apres suppression (is_active=false)", "GET", "/admin/api/categories/{{created_category_id}}",
    description="Preuve de nettoyage : la categorie existe toujours (pas de suppression dure) mais est desactivee -- transition reellement due au DELETE ci-dessus (arrivait active, grace a la bascule 2/2), pas a la bascule 1/2 (deja neutralisee par la bascule 2/2).",
    tests=_cat_pm,
    bruno_tests=_cat_bru,
))

# --- Products ---
# Cycle complet : creer -> lire -> modifier (sans prix) -> modifier le prix
# (PIN) -> relire -> deplacer -> recette (lire/remplacer) -> supprimer (PIN,
# suppression DURE) -> relire (404). category_id vient du dossier Categories
# (burgersCategoryId, par slug). ingredient_id=1 dans la recette : catalogue
# de reference FIGE au seed (Pain burger, jamais cree/supprime par cette
# collection) -- pas la meme categorie de donnee qu'un id de PRODUIT (dont
# l'ordre depend du contenu exact du seed, cf. le bug corrige dans ce meme
# chantier pour Menus).
items.append({
    "name": "Produits",
    "item": [
        request(
            "Lister les produits", "GET", "/admin/api/products",
            description="Capture aussi burgerBaseProductId/drinkBaseProductId (premier produit DE BASE de chaque categorie), pour ce dossier et pour Menus.",
            tests=expect_status(200, "200 OK") + PRODUCT_LOOKUP_PM,
            bruno_script=PRODUCT_LOOKUP_BRU,
            bruno_tests=status_test_bru(200, "200 OK"),
        ),
        request(
            "Creer un produit", "POST", "/admin/api/products",
            json_body={
                "category_id": "{{burgersCategoryId}}",
                "name": "Produit Demo {{run}}",
                "price_cents": 490,
                "vat_rate": 100,
                "is_available": True,
                "display_order": 99,
            },
            description=(
                "product.create, pas de PIN. price_cents en CENTIMES, ENTIER STRICT (mlt 8.1) : "
                "un decimal (590.9) ou une chaine en euros (\"12,50\") sont refuses (422), "
                "jamais tronques ni relus comme des euros. category_id recupere dynamiquement "
                "(categorie 'burgers', dossier Categories ci-dessus)."
            ),
            tests=created_test(201, "created_product_id"),
        ),
        request(
            "Lire un produit", "GET", "/admin/api/products/{{created_product_id}}",
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Modifier un produit (sans changement de prix/TVA)", "PUT", "/admin/api/products/{{created_product_id}}",
            json_body={
                "category_id": "{{burgersCategoryId}}",
                "name": "Produit Demo {{run}} renomme",
                "price_cents": 490,
                "vat_rate": 100,
                "is_available": True,
                "display_order": 99,
            },
            description=(
                "Aucun PIN requis tant que price_cents et vat_rate ne changent pas (mlt 8.2 RG-4). "
                "price_cents reste un entier de centimes strict (voir la requete de creation)."
            ),
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Modifier le prix d'un produit (PIN requis)", "PUT", "/admin/api/products/{{created_product_id}}",
            json_body={
                "category_id": "{{burgersCategoryId}}",
                "name": "Produit Demo {{run}} renomme",
                "price_cents": 590,
                "vat_rate": 100,
                "is_available": True,
                "display_order": 99,
                "pin_email": "{{pin_email}}",
                "pin": "{{pin}}",
            },
            description=(
                "Changer price_cents ou vat_rate est une action sensible (RG-T13) : "
                "pin_email + pin obligatoires (modele identifiant equipier + PIN). "
                "Definissez d'abord un PIN sur le compte via /admin/profile/pin (cf. docs/api/demo-api.md)."
            ),
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Relire apres modification du prix", "GET", "/admin/api/products/{{created_product_id}}",
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Deplacer d'un rang dans sa categorie (move)", "POST", "/admin/api/products/{{created_product_id}}/move",
            json_body={"direction": "up"},
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Lire la recette (composition)", "GET", "/admin/api/products/{{created_product_id}}/recipe",
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Remplacer la recette", "PUT", "/admin/api/products/{{created_product_id}}/recipe",
            json_body={
                "composition": [
                    {"ingredient_id": 1, "quantity_normal": 1, "quantity_maxi": 2, "is_removable": True, "is_addable": False, "extra_price_cents": 0},
                ],
            },
            description=(
                "ingredient.manage, pas de PIN. Composition vide autorisee (purge la recette). "
                "ingredient_id=1 (\"Pain burger\") : catalogue d'ingredients FIGE au seed (jamais "
                "cree/supprime par cette collection), pas un id de ressource creee ici."
            ),
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Supprimer un produit (PIN requis)", "DELETE", "/admin/api/products/{{created_product_id}}",
            json_body={"pin_email": "{{pin_email}}", "pin": "{{pin}}"},
            description=(
                "409 CONFLICT si le produit est encore reference (commande, menu). La recette part "
                "en CASCADE (ADR-0017), donc PAS un blocage ici. Suppression DURE (contrairement aux "
                "categories) : etat terminal de ce dossier pour CETTE ressource (suppression dure reelle)."
            ),
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Relire apres suppression (404 attendu)", "GET", "/admin/api/products/{{created_product_id}}",
            description="Preuve de nettoyage : suppression dure, la ressource n'existe plus.",
            tests=expect_status(404, "404 NOT_FOUND"),
        ),
        request(
            "Telecharger le modele d'import (CSV)", "GET", "/admin/api/products/import/template",
            description=(
                "product.create, lecture seule. Renvoie le modele officiel dans data.csv (en-tete + "
                "exemples), le meme que le bouton du back-office."
            ),
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Import : apercu sans ecriture (dry_run=1)", "POST", "/admin/api/products/import?dry_run=1",
            json_body={"csv": "categorie;produit;description;prix_ttc;tva;taille_cl;disponible;ingredient;unite;quantite;retirable;ajoutable\nBurgers;Burger demo {{run}};;6,90;10;;oui;Pain burger;pièce;1;non;non\n"},
            description=(
                "product.create. Corps JSON {csv}, pas un fichier. dry_run=1 : le serveur analyse et "
                "rend l'apercu (productsToCreate, errors...) SANS rien ecrire. Sans dry_run, il "
                "applique l'import (code personnel exige seulement si un prix change)."
            ),
            tests=expect_status(200, "200 OK"),
        ),
    ],
})

# --- Menus ---
# category_id = categorie 'menus' (par slug), burger_product_id/options de
# slot = produits DE BASE recuperes dynamiquement (dossier Produits) --
# corrige un bug reel de ce chantier : l'ancien corps codait en dur
# burger_product_id=1/options=[2], et le produit d'id 2 du seed est un
# BURGER (pas une boisson), donc le slot 'drink' etait systematiquement
# refuse (422). Cycle : creer -> lire -> modifier -> relire -> bascule ->
# supprimer (PIN, suppression DURE) -> relire (404).
items.append({
    "name": "Menus",
    "item": [
        request("Lister les menus", "GET", "/admin/api/menus", tests=expect_status(200, "200 OK")),
        request(
            "Creer un menu", "POST", "/admin/api/menus",
            json_body={
                "category_id": "{{menusCategoryId}}",
                "burger_product_id": "{{burgerBaseProductId}}",
                "name": "Menu Demo {{run}}",
                "price_normal_cents": 890,
                "price_maxi_cents": 990,
                "is_available": True,
                "display_order": 50,
                "slots": [
                    {"name": "Boisson", "slot_type": "drink", "is_required": True, "options": ["{{drinkBaseProductId}}"]},
                ],
            },
            description=(
                "menu.create, pas de PIN. burger_product_id doit etre un produit DE BASE (pas une "
                "variante de taille) ; l'option du slot 'drink' doit appartenir a la categorie "
                "'boissons' (F12) -- les deux sont recuperes dynamiquement (dossier Produits), "
                "jamais ecrits en dur. price_normal_cents/price_maxi_cents sont des entiers de "
                "centimes stricts (un decimal ou une chaine en euros est refuse, 422)."
            ),
            tests=created_test(201, "created_menu_id"),
        ),
        request(
            "Lire un menu (avec slots)", "GET", "/admin/api/menus/{{created_menu_id}}",
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Modifier un menu", "PUT", "/admin/api/menus/{{created_menu_id}}",
            json_body={
                "category_id": "{{menusCategoryId}}",
                "burger_product_id": "{{burgerBaseProductId}}",
                "name": "Menu Demo {{run}} v2",
                "price_normal_cents": 890,
                "price_maxi_cents": 990,
                "is_available": True,
                "display_order": 50,
                "slots": [
                    {"name": "Boisson", "slot_type": "drink", "is_required": True, "options": ["{{drinkBaseProductId}}"]},
                ],
            },
            description="Memes regles que la creation : prix en entiers de centimes stricts.",
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Relire apres modification", "GET", "/admin/api/menus/{{created_menu_id}}",
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Basculer la disponibilite (toggle)", "POST", "/admin/api/menus/{{created_menu_id}}/toggle",
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Supprimer un menu (PIN requis)", "DELETE", "/admin/api/menus/{{created_menu_id}}",
            json_body={"pin_email": "{{pin_email}}", "pin": "{{pin}}"},
            description="409 CONFLICT si le menu est encore reference par des commandes (pas le cas ici, menu jamais commande). Suppression DURE : etat terminal de ce dossier.",
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Relire apres suppression (404 attendu)", "GET", "/admin/api/menus/{{created_menu_id}}",
            description="Preuve de nettoyage.",
            tests=expect_status(404, "404 NOT_FOUND"),
        ),
    ],
})

# --- Ingredients ---
# DEUX ingredients par execution, pour une raison de CONTRAT (pas une lubie) :
# restock/inventaire/ajustement ecrivent un stock_movement, ce qui bloque
# ensuite une suppression DURE (409 CONFLICT, FK RESTRICT -- documente dans
# conventions.md section 5.3). Le premier ("plain") demontre le cycle create
# -> read -> update -> delete -> reread(404) SANS mouvement, donc reellement
# supprimable. Le second ("stock") demontre restock/seuils/inventaire/
# ajustement/allergenes PUIS se termine par une DESACTIVATION (toggle), pas
# une suppression -- c'est la ressource qui a des mouvements, l'API l'interdit
# a raison. Les deux sont propres en fin de dossier (supprime OU desactive).
items.append({
    "name": "Ingredients",
    "item": [
        request("Lister les ingredients", "GET", "/admin/api/ingredients", tests=expect_status(200, "200 OK")),
        request(
            "Creer un ingredient (a supprimer)", "POST", "/admin/api/ingredients",
            json_body={
                "name": "Ingredient Demo Plain {{run}}",
                "unit": "unite",
                "family": "dosette",
                "pack_size": 50,
                "pack_label": "carton de 50",
                "stock_capacity": 500,
                "low_stock_pct": 20,
                "critical_stock_pct": 5,
            },
            description="ingredient.manage, pas de PIN. Nom unique par execution ({{run}} -- \"Cet ingrédient existe déjà\" sinon). stock_quantity pose a 0 cote serveur (RG-CREATE-ING). family (migration 0017) : slug de la liste canonique (App\\Catalogue\\IngredientFamily) ou omis/vide pour non classe.",
            tests=created_test(201, "created_ingredient_id"),
        ),
        request(
            "Lire un ingredient", "GET", "/admin/api/ingredients/{{created_ingredient_id}}",
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Modifier un ingredient", "PUT", "/admin/api/ingredients/{{created_ingredient_id}}",
            json_body={
                "name": "Ingredient Demo Plain {{run}} v2",
                "unit": "unite",
                "family": "sauce",
                "pack_size": 50,
                "pack_label": "carton de 50",
                "stock_capacity": 500,
                "low_stock_pct": 20,
                "critical_stock_pct": 5,
            },
            description="family change de \"dosette\" a \"sauce\" (visible sur la requete suivante) : remplacement COMPLET de la ressource (PUT), pas un PATCH -- omettre family ici la reinitialiserait a non classe, meme regle que pack_label.",
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Relire apres modification", "GET", "/admin/api/ingredients/{{created_ingredient_id}}",
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Supprimer un ingredient (pas de mouvement -> suppression reelle)", "DELETE", "/admin/api/ingredients/{{created_ingredient_id}}",
            description="AUCUN mouvement de stock sur celui-ci (pas de restock/inventaire/ajustement) : suppression DURE reussie. Etat terminal de ce dossier pour cet ingredient.",
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Relire apres suppression (404 attendu)", "GET", "/admin/api/ingredients/{{created_ingredient_id}}",
            description="Preuve de nettoyage.",
            tests=expect_status(404, "404 NOT_FOUND"),
        ),
        request(
            "Creer un ingredient (pour le stock)", "POST", "/admin/api/ingredients",
            json_body={
                "name": "Ingredient Demo Stock {{run}}",
                "unit": "unite",
                "pack_size": 50,
                "pack_label": "carton de 50",
                "stock_capacity": 500,
                "low_stock_pct": 20,
                "critical_stock_pct": 5,
            },
            description="Nom distinct du premier ingredient (suffixe Stock/{{run}}) : celui-ci va recevoir des mouvements de stock.",
            tests=created_test(201, "created_restock_ingredient_id"),
        ),
        request(
            "Reapprovisionner (restock)", "POST", "/admin/api/ingredients/{{created_restock_ingredient_id}}/restock",
            json_body={"packs": 5, "note": "reappro demo"},
            description="stock.manage, pas de PIN (mlt 9.1). L'ingredient doit etre actif. Pose un stock_movement : bloque desormais toute suppression DURE (409), voir plus bas.",
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Reglage rapide des seuils", "PUT", "/admin/api/ingredients/{{created_restock_ingredient_id}}/thresholds",
            json_body={"stock_capacity": 600, "low_stock_pct": 20, "critical_stock_pct": 5},
            description="stock.manage, pas de PIN (calibrage, pas un comptage).",
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Inventaire (comptage absolu, PIN requis)", "POST", "/admin/api/ingredients/{{created_restock_ingredient_id}}/inventory",
            json_body={"actual_quantity": 42, "note": "comptage demo", "pin_email": "{{pin_email}}", "pin": "{{pin}}"},
            description="stock.count + PIN (mlt 9.2). Pas d'audit_log au succes (stock_movement suffit).",
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Ajustement libre (delta signe, PIN requis)", "POST", "/admin/api/ingredients/{{created_restock_ingredient_id}}/adjust",
            json_body={"delta": -3, "note": "casse demo", "pin_email": "{{pin_email}}", "pin": "{{pin}}"},
            description="stock.count + PIN. delta entier non nul (+ pour ajouter, - pour retirer).",
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Historique des mouvements (lecture)", "GET", "/admin/api/ingredients/{{created_restock_ingredient_id}}/movements",
            description=(
                "stock.read, lecture seule : les mouvements du plus recent au plus ancien "
                "(reapprovisionnement, inventaire, ajustement ci-dessus). RG-4 : l'auteur (actor) "
                "n'est renvoye qu'aux detenteurs de stock.manage ; actor_visible le dit."
            ),
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Revue des allergenes", "PUT", "/admin/api/ingredients/{{created_restock_ingredient_id}}/allergens",
            json_body={"allergen_ids": [1], "source": "Fiche fournisseur"},
            description=(
                "ingredient.manage, pas de PIN. source obligatoire (une revue non sourcee est "
                "refusee, 422). allergen_ids=[1] : catalogue INCO FIGE (14 allergenes UE), pas un "
                "id de ressource creee par cette collection."
            ),
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Basculer actif/inactif (toggle) -- nettoyage final", "POST", "/admin/api/ingredients/{{created_restock_ingredient_id}}/toggle",
            description=(
                "Pas de DELETE possible ici : cet ingredient porte des stock_movement (restock/"
                "inventaire/ajustement ci-dessus), donc 409 CONFLICT sur une suppression dure "
                "(conventions.md section 5.3, comportement voulu -- une demarque non tracee serait "
                "pire). La bascule vers inactif est le nettoyage correct pour CETTE ressource."
            ),
            tests=expect_status(200, "200 OK"),
        ),
    ],
})

# --- Roles (RBAC) ---
# Deplace AVANT Utilisateurs (dans l'ordre d'execution) : la creation d'un
# utilisateur a besoin d'un role_id valide, recupere ici (firstRoleId).
# permission_ids utilise un id REEL du catalogue fige (recupere par GET, pas
# suppose) : le catalogue de permissions lui-meme est fige au seed (23 lignes,
# "jamais cree via l'UI", conventions.md) -- source stable, mais l'id EXACT
# depend malgre tout de l'ordre d'insertion du seed courant, donc recupere
# dynamiquement par prudence plutot que suppose "1". Pas de DELETE (aucun
# endpoint, limite documentee de longue date) : le nettoyage final est une
# DESACTIVATION (PUT is_active=false), pas une suppression.
items.append({
    "name": "Roles (RBAC)",
    "item": [
        request(
            "Lister les roles", "GET", "/admin/api/roles",
            description="Capture firstRoleId (premier role NON-ADMIN actif de la liste -- cf. commentaire de ROLE_LIST_LOOKUP_TEMPLATE) pour la requete suivante et pour la creation d'utilisateur (dossier Utilisateurs).",
            tests=expect_status(200, "200 OK") + ROLE_LIST_LOOKUP_PM,
            bruno_script=ROLE_LIST_LOOKUP_BRU,
            bruno_tests=status_test_bru(200, "200 OK"),
        ),
        request(
            "Lire un role (recupere un id de permission reel)", "GET", "/admin/api/roles/{{firstRoleId}}",
            description="permission_ids n'apparait QUE sur la lecture unitaire (pas la liste, conventions.md section 5.3) : capture examplePermissionId pour la creation ci-dessous.",
            tests=expect_status(200, "200 OK") + ROLE_PERMISSION_LOOKUP_PM,
            bruno_script=ROLE_PERMISSION_LOOKUP_BRU,
            bruno_tests=status_test_bru(200, "200 OK"),
        ),
        request(
            "Creer un role (PIN requis)", "POST", "/admin/api/roles",
            json_body={
                "code": "demo_role_{{run}}",
                "label": "Role Demo {{run}}",
                "permission_ids": ["{{examplePermissionId}}"],
                "visible_sources": ["counter"],
                "pin_email": "{{pin_email}}",
                "pin": "{{pin}}",
            },
            description=(
                "role.manage, PIN obligatoire (escalade de privileges, mlt 10.4). code : "
                "minuscules/chiffres/_ , commence par une lettre -- {{run}} (base36) est compatible. "
                "permission_ids : id REEL recupere par GET ci-dessus (jamais suppose)."
            ),
            tests=created_test(201, "created_role_id"),
        ),
        request(
            "Lire un role (avec permissions)", "GET", "/admin/api/roles/{{created_role_id}}",
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Modifier un role (PIN requis)", "PUT", "/admin/api/roles/{{created_role_id}}",
            json_body={
                "label": "Role Demo {{run}} v2",
                "permission_ids": ["{{examplePermissionId}}"],
                "visible_sources": ["counter", "drive"],
                "pin_email": "{{pin_email}}",
                "pin": "{{pin}}",
            },
            description=(
                "Garde-fou anti-lockout (422) : le role 'admin' doit garder role.manage et "
                "rester actif -- non pertinent ici (role fraichement cree, pas 'admin')."
            ),
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Relire apres modification", "GET", "/admin/api/roles/{{created_role_id}}",
            tests=expect_status(200, "200 OK"),
        ),
    ],
})
_role_deact_pm, _role_deact_bru = status_and_active_dual(200, False, "200, is_active=false")
_role_pm, _role_bru = status_and_active_dual(200, False, "200, is_active=false")
items[-1]["item"].append(request(
    "Desactiver (PUT is_active=false) -- nettoyage final", "PUT", "/admin/api/roles/{{created_role_id}}",
    json_body={
        "label": "Role Demo {{run}} v2",
        "permission_ids": ["{{examplePermissionId}}"],
        "visible_sources": ["counter", "drive"],
        "is_active": False,
        "pin_email": "{{pin_email}}",
        "pin": "{{pin}}",
    },
    description="Aucun endpoint DELETE pour les roles (limite documentee, conventions.md section 5.3 -- un role est rattache a des comptes). Le nettoyage correct est la desactivation, PUT PARTIEL comme pour les utilisateurs. RoleApiController::apiUpdate() renvoie le role a jour (dont is_active) : assertion directe sur CETTE reponse, pas seulement sur la relecture qui suit.",
    tests=_role_deact_pm,
    bruno_tests=_role_deact_bru,
))
items[-1]["item"].append(request(
    "Relire le role apres desactivation (is_active=false)", "GET", "/admin/api/roles/{{created_role_id}}",
    description="Preuve de nettoyage.",
    tests=_role_pm,
    bruno_tests=_role_bru,
))

# --- Users ---
# role_id = firstRoleId (dossier Roles ci-dessus, deja passe a ce stade de
# l'execution) : n'importe quel role actif convient ("Rôle requis et actif",
# UserController::validate()), donc reutiliser celui deja recupere evite un
# second aller-retour. Cycle : creer -> lire -> modifier -> relire ->
# reinitialiser le PIN -> desactiver (DELETE = soft) -> relire (is_active=
# false) -> anonymiser (RGPD, etat terminal reel de nettoyage : la ligne perd
# son email/nom identifiants, cf. mlt 10.5).
items.append({
    "name": "Utilisateurs",
    "item": [
        request("Lister les utilisateurs", "GET", "/admin/api/users", tests=expect_status(200, "200 OK")),
        request(
            "Creer un utilisateur (PIN requis)", "POST", "/admin/api/users",
            json_body={
                "email": "equipier-{{run}}@wakdo.local",
                "first_name": "Demo",
                "last_name": "Equipier {{run}}",
                "role_id": "{{firstRoleId}}",
                "password": "Demo-{{run}}!",
                "pin_email": "{{pin_email}}",
                "pin": "{{pin}}",
            },
            description=(
                "user.create, PIN obligatoire (mlt 10.1). Email unique par execution "
                "({{run}}). role_id recupere dynamiquement (dossier Roles). Mot de passe "
                "'Demo-{{run}}!' : la partie fixe seule ferait moins de 8 caracteres et, "
                "surtout, serait un LITTERAL constant commis en clair -- {{run}} (horodatage "
                "pose par 'Se connecter', change a chaque execution) rend ce mot de passe "
                "different a chaque passage de la collection, pas un secret reutilisable."
            ),
            tests=created_test(201, "created_user_id"),
        ),
        request(
            "Lire un utilisateur", "GET", "/admin/api/users/{{created_user_id}}",
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Modifier un utilisateur (PIN requis)", "PUT", "/admin/api/users/{{created_user_id}}",
            json_body={
                "email": "equipier-{{run}}@wakdo.local",
                "first_name": "Demo",
                "last_name": "Equipier {{run}} v2",
                "role_id": "{{firstRoleId}}",
                "is_active": True,
                "pin_email": "{{pin_email}}",
                "pin": "{{pin}}",
            },
            description=(
                "user.update, PIN obligatoire (mlt 10.2). PUT PARTIEL sur is_active : "
                "omis, il conserve la valeur actuelle (ne desactive jamais par omission) ; "
                "envoye ici a true pour l'illustrer explicitement."
            ),
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Relire apres modification", "GET", "/admin/api/users/{{created_user_id}}",
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Reinitialiser le PIN (PIN requis)", "POST", "/admin/api/users/{{created_user_id}}/reset-pin",
            json_body={"pin_email": "{{pin_email}}", "pin": "{{pin}}"},
            description="Efface le PIN de la cible ; elle le redefinit ensuite en self-service (/admin/profile/pin).",
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Desactiver un utilisateur (DELETE, PIN requis)", "DELETE", "/admin/api/users/{{created_user_id}}",
            json_body={"pin_email": "{{pin_email}}", "pin": "{{pin}}"},
            description=(
                "user.deactivate. == desactivation (PAS de suppression physique ni "
                "d'effacement RGPD, mlt 10.3). Refuse sur son propre compte (403) ou "
                "sur le dernier administrateur actif (422) -- non pertinent ici."
            ),
            tests=expect_status(200, "200 OK"),
        ),
    ],
})
_user_pm, _user_bru = status_and_active_dual(200, False, "200, is_active=false")
items[-1]["item"].append(request(
    "Relire l'utilisateur apres desactivation (is_active=false)", "GET", "/admin/api/users/{{created_user_id}}",
    description="Preuve de nettoyage intermediaire (avant l'anonymisation RGPD ci-dessous).",
    tests=_user_pm,
    bruno_tests=_user_bru,
))
items[-1]["item"].append(request(
    "Anonymiser (RGPD, PIN requis) -- nettoyage final", "POST", "/admin/api/users/{{created_user_id}}/erase",
    json_body={"pin_email": "{{pin_email}}", "pin": "{{pin}}"},
    description="Tombstone (pas de suppression physique, mlt 10.5, ADR-0007). 403 sur son propre compte, 409 si deja anonymise. Etat terminal de nettoyage pour cette ressource.",
    tests=expect_status(200, "200 OK"),
))

# --- Commandes ---
# Autonome (pas de dependance aux ids captures par les dossiers precedents,
# deja SUPPRIMES a ce stade pour Categories/Produits/Menus) : recupere son
# propre produit disponible par un GET dedie. DEUX commandes distinctes
# (corrige un bug reel de ce chantier : l'ancienne collection reutilisait le
# MEME numero pour "Remettre au client" puis "Annuler", ce qui echouait
# TOUJOURS en 422 CANNOT_CANCEL_IN_STATE -- une commande livree ne s'annule
# plus). Commande A : creer -> lire -> preparer -> remettre (etat terminal
# "delivered", normal, ne bloque rien). Commande B : creer -> annuler avec PIN
# (etat terminal "cancelled", restocke automatiquement, RG-T20).
items.append({
    "name": "Commandes",
    "item": [
        request(
            "Lister les commandes (filtre par canaux visibles)", "GET", "/admin/api/orders",
            description="order.read. Filtre par role_visible_source (RG-T12), meme regle que la file cuisine.",
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Choisir un produit disponible", "GET", "/api/products",
            description=(
                "Capture orderProductId (premier produit is_orderable) : /admin/api/products "
                "(utilise jusqu'ici) n'expose PAS is_orderable -- seulement is_available, un flag "
                "manuel qui n'exclut pas une rupture CALCULEE (RG-T21, stock insuffisant sur un "
                "ingredient non retirable), auquel cas OrderRepository::resolveLine refuse la "
                "ligne a la creation de commande. /api/products (lecture catalogue PUBLIQUE, "
                "anonyme, meme routeur -- docs/api/conventions.md section 5.2) calcule "
                "is_orderable et c'est ce champ qui garantit un produit reellement commandable. "
                "Ce dossier ne depend pas du dossier Produits (dont la ressource creee est deja "
                "supprimee a ce stade)."
            ),
            tests=expect_status(200, "200 OK") + ORDER_PRODUCT_LOOKUP_PM,
            bruno_script=ORDER_PRODUCT_LOOKUP_BRU,
            bruno_tests=status_test_bru(200, "200 OK"),
        ),
    ],
})
_ordA_pm, _ordA_bru = order_number_capture_dual("created_order_number")
items[-1]["item"].append(request(
    "Saisir la commande A (comptoir/drive)", "POST", "/admin/api/orders",
    json_body={
        "service_mode": "dine_in",
        "source": "counter",
        "items": [
            {"type": "product", "product_id": "{{orderProductId}}", "quantity": 1},
        ],
    },
    description=(
        "order.create, pas de PIN, encaissee immediatement (comme le HTML). "
        "Un seul endpoint JSON pour les deux canaux (le HTML a deux pages, "
        "/counter/orders et /drive/orders) : un role a canal FIXE (role.order_source) "
        "impose sa source et IGNORE ce champ 'source' s'il est fourni ; un role SANS "
        "canal fixe (admin/manager) doit le renseigner ('counter' ou 'drive'), sinon 422."
    ),
    tests=expect_status(201, "201 Created") + _ordA_pm,
    bruno_script=_ordA_bru,
    bruno_tests=status_test_bru(201, "201 Created"),
))
items[-1]["item"].append(request(
    "Lire la commande A", "GET", "/admin/api/orders/{{created_order_number}}",
    tests=expect_status(200, "200 OK"),
))
items[-1]["item"].append(request(
    "Marquer prete (cuisine)", "POST", "/admin/api/orders/{{created_order_number}}/ready",
    description="order.read, pas de PIN. Meme garde de visibilite de source (PRE-3) que le HTML.",
    tests=expect_status(200, "200 OK"),
))
items[-1]["item"].append(request(
    "Remettre au client (deliver) -- etat terminal de la commande A", "POST", "/admin/api/orders/{{created_order_number}}/deliver",
    description="order.deliver, pas de PIN. \"delivered\" est un etat terminal NORMAL (pas une pollution a nettoyer) : une commande livree fait partie de l'historique reel du restaurant.",
    tests=expect_status(200, "200 OK"),
))
_ordB_pm, _ordB_bru = order_number_capture_dual("created_order_number_cancel")
items[-1]["item"].append(request(
    "Saisir la commande B (pour l'annulation)", "POST", "/admin/api/orders",
    json_body={
        "service_mode": "dine_in",
        "source": "counter",
        "items": [
            {"type": "product", "product_id": "{{orderProductId}}", "quantity": 1},
        ],
    },
    description="Numero DISTINCT de la commande A (created_order_number_cancel) : annuler la MEME commande qu'on vient de livrer echouerait toujours (422 CANNOT_CANCEL_IN_STATE) -- bug corrige par ce chantier.",
    tests=expect_status(201, "201 Created") + _ordB_pm,
    bruno_script=_ordB_bru,
    bruno_tests=status_test_bru(201, "201 Created"),
))
items[-1]["item"].append(request(
    "Annuler la commande B (PIN requis) -- etat terminal de nettoyage", "POST", "/admin/api/orders/{{created_order_number_cancel}}/cancel",
    json_body={"pin_email": "{{pin_email}}", "pin": "{{pin}}"},
    description="order.cancel + PIN (RG-T13). Restocke automatiquement les ingredients consommes (RG-T20) : \"cancelled\" est aussi un etat terminal normal.",
    tests=expect_status(200, "200 OK"),
))

# --- Statistiques ---
items.append({
    "name": "Statistiques et sante",
    "item": [
        request(
            "Tableau de bord (compteurs + stock + ventes)", "GET", "/admin/api/stats",
            description="stats.read, lecture seule.",
            tests=expect_status(200, "200 OK"),
        ),
        request(
            "Sante de l'application (etat detaille)", "GET", "/admin/api/health",
            description=(
                "role.manage, lecture seule : version servie, base, migrations, activite des 24 "
                "dernieres heures. C'est ce que relit la page Sante du back-office toutes les 15 s. "
                "La sonde publique, sans compte, est GET /api/health."
            ),
            tests=expect_status(200, "200 OK"),
        ),
    ],
})

# --- RBAC : preuve des droits ---
# Un dossier par poste (compte de demo du seed a venir, docs/demo/comptes-demo.md,
# db/seeds/000x_demo_accounts.sql -- chantier separe) : connexion PROPRE (jeton
# 'csrf_<role>' distinct de 'csrf', pour ne pas ecraser la session admin utilisee
# par les dossiers ci-dessus), puis une requete AUTORISEE et une requete REFUSEE
# (403 FORBIDDEN), deduites du SEUL SEED (db/seeds/0001_rbac_and_reference.sql,
# section role_permission), pas d'une supposition :
#   - manager : stats.read + order.read + order.cancel oui (ADR-0020, qui remplace
#     la decision D5 sur ce point), user.create NON ;
#   - kitchen ("cuisine")  : order.read oui, user.read NON ;
#   - counter ("comptoir") : order.create oui, product.delete NON ;
#   - drive                : memes droits QUE counter (le seed leur donne le MEME
#     ensemble de permissions -- seule la source de commande differe), donc les
#     memes deux requetes prouvent la meme chose pour ce role.
# order.create (comptoir/drive) est prouve SANS creer de commande reelle :
# {"items": []} declenche une 422 VALIDATION_ERROR (avec error.fields.items
# renseigne -- verifie explicitement ci-dessous, pas seulement le code HTTP,
# qu'un autre 422 pourrait aussi produire) APRES guardApi('order.create')/CSRF.
# L'atteindre (422 VALIDATION_ERROR + error.fields.items, jamais 403) suffit a
# prouver la permission, sans les effets de bord d'une commande reellement
# encaissee (decrement de stock, comptage dans les statistiques). Un role SANS
# order.create recoit 403 FORBIDDEN sur la MEME requete (teste separement,
# OrderApiControllerTest::testStoreWithoutPermissionReturns403EvenWithEmptyItems) :
# la garde de permission passe avant la validation du corps, donc les deux
# codes ne se confondent pas. Voir docs/api/demo-api.md, section Effets de
# bord, pour ce que le dossier Commandes (lui, volontairement REEL) laisse
# derriere.
# Le refus ("DELETE /admin/api/products/1") reste un id LITTERAL, a dessein :
# guardApi() (verification de permission) s'execute AVANT toute lecture de la
# ressource -- la reponse est 403 FORBIDDEN que l'id existe ou non, ce n'est
# pas une donnee "creee puis supprimee" dont la disparition casserait le test
# (verifie dans le code, App\Controllers\Admin\Api\ProductApiController::apiDestroy()).
# Ce dossier reste par ailleurs REJOUABLE SEUL, sans dependre des autres.
def rbac_login_test(role_key):
    return [
        "pm.test('200 OK (connexion)', function () { pm.response.to.have.status(200); });",
        "const body = pm.response.json();",
        f"if (body && body.data && body.data.csrf_token) {{ pm.environment.set('csrf_{role_key}', body.data.csrf_token); }}",
    ]

def rbac_role_folder(role_key, role_label, seed_hint, demo_requests):
    return {
        "name": role_label,
        "item": [
            request(
                f"Se connecter en tant que {role_label.lower()}", "POST", "/admin/api/auth/login",
                json_body={"email": "{{email_%s}}" % role_key, "password": "{{password_%s}}" % role_key},
                description=(
                    f"Identifiants VIDES par defaut (email_{role_key}/password_{role_key}) : a "
                    "completer depuis docs/demo/comptes-demo.md (compte de demo par poste), jamais "
                    f"commis en clair dans cette collection. Droits attendus ({seed_hint}) : "
                    "db/seeds/0001_rbac_and_reference.sql, section role_permission."
                ),
                tests=rbac_login_test(role_key),
                csrf=False,
            ),
        ] + demo_requests,
    }

_comptoir_autorise_pm, _comptoir_autorise_bru = validation_error_field_dual(
    "items", "422 VALIDATION_ERROR sur items (permission order.create accordee, aucune commande creee)",
)
_drive_autorise_pm, _drive_autorise_bru = validation_error_field_dual(
    "items", "422 VALIDATION_ERROR sur items (permission order.create accordee, aucune commande creee)",
)

items.append({
    "name": "RBAC : preuve des droits",
    "item": [
        rbac_role_folder(
            "manager", "Manager", "stats.read + order.read + order.cancel oui (ADR-0020), user.create non",
            [
                request(
                    "Autorise : tableau de bord (stats.read)", "GET", "/admin/api/stats",
                    description="Le manager a stats.read (seed) : 200 attendu.",
                    tests=expect_status(200, "200 stats.read accorde"),
                ),
                request(
                    "Autorise : lister les commandes (order.read)", "GET", "/admin/api/orders",
                    description=(
                        "ADR-0020 (docs/adr/0020-responsable-annule-commande.md, remplace la "
                        "decision D5 sur ce point) : le manager recoit desormais order.read, "
                        "indispensable pour trouver une commande a annuler. 200 attendu, sans "
                        "filtre de canal (aucune ligne role_visible_source pour ce role, comme admin)."
                    ),
                    tests=expect_status(200, "200 order.read accorde"),
                ),
                request(
                    "Refuse : creer un compte (pas user.create)", "POST", "/admin/api/users",
                    json_body={"email": "manager-{{run}}@wakdo.local"},
                    description=(
                        "ADR-0020 ajoute order.read/order.cancel au manager mais ne touche pas aux "
                        "droits utilisateurs : c'est ce refus qui porte desormais la demonstration "
                        "de separation des pouvoirs (le responsable ne peut ni creer de compte ni "
                        "s'attribuer des droits). 403 FORBIDDEN attendu, avant meme la validation du "
                        "corps (guardApi() s'execute en premier, meme raisonnement que le refus "
                        "product.delete ci-dessous)."
                    ),
                    tests=expect_status(403, "403 FORBIDDEN (user.create absent)"),
                    csrf_var="csrf_manager",
                ),
            ],
        ),
        rbac_role_folder(
            "cuisine", "Cuisine", "order.read oui, user.read non",
            [
                request(
                    "Autorise : lister les commandes (order.read)", "GET", "/admin/api/orders",
                    description="La cuisine a order.read (seed, KDS) : 200 attendu.",
                    tests=expect_status(200, "200 order.read accorde"),
                ),
                request(
                    "Refuse : lister les utilisateurs (pas user.read)", "GET", "/admin/api/users",
                    description="La cuisine n'a AUCUNE permission user.*. 403 FORBIDDEN attendu.",
                    tests=expect_status(403, "403 FORBIDDEN (user.read absent)"),
                ),
            ],
        ),
        rbac_role_folder(
            "comptoir", "Comptoir", "order.create oui, product.delete non",
            [
                request(
                    "Autorise : order.create accorde (422, PAS 403)", "POST", "/admin/api/orders",
                    json_body={"items": []},
                    description=(
                        "Prouve order.create SANS encaisser de commande reelle (une commande "
                        "reelle decremente le stock et compte dans les statistiques, cf. "
                        "docs/api/demo-api.md section Effets de bord) : 'items': [] est une "
                        "erreur de VALIDATION ('Ajoutez au moins un produit ou un menu'), "
                        "verifiee APRES guardApi('order.create')/CSRF. Le test verifie le code "
                        "ET error.fields.items (pas seulement 422, qu'un tout autre champ "
                        "pourrait aussi produire) -- l'atteindre prouve que la permission est "
                        "accordee, sans creer de ligne 'order'."
                    ),
                    tests=_comptoir_autorise_pm,
                    bruno_tests=_comptoir_autorise_bru,
                    csrf_var="csrf_comptoir",
                ),
                request(
                    "Refuse : supprimer un produit (pas product.delete)", "DELETE", "/admin/api/products/1",
                    description="Le comptoir n'a que product.read sur le catalogue. 403 FORBIDDEN attendu (verifie AVANT toute lecture de la ressource).",
                    tests=expect_status(403, "403 FORBIDDEN (product.delete absent)"),
                    csrf_var="csrf_comptoir",
                ),
            ],
        ),
        rbac_role_folder(
            "drive", "Drive", "memes droits que Comptoir (seed) : order.create oui, product.delete non",
            [
                request(
                    "Autorise (Drive) : order.create accorde (422, PAS 403)", "POST", "/admin/api/orders",
                    json_body={"items": []},
                    description=(
                        "Meme preuve NON encaissee que Comptoir : 'items': [] declenche une "
                        "erreur de VALIDATION (422, avec error.fields.items) apres "
                        "guardApi('order.create'), pas un 403 -- prouve la permission sans "
                        "creer de commande reelle."
                    ),
                    tests=_drive_autorise_pm,
                    bruno_tests=_drive_autorise_bru,
                    csrf_var="csrf_drive",
                ),
                request(
                    "Refuse : supprimer un produit (pas product.delete)", "DELETE", "/admin/api/products/1",
                    description="Meme ensemble de droits que Comptoir (seed) : 403 FORBIDDEN attendu.",
                    tests=expect_status(403, "403 FORBIDDEN (product.delete absent)"),
                    csrf_var="csrf_drive",
                ),
            ],
        ),
    ],
})

# --- Fin de demo : deconnexion (docs/api/conventions.md section 5.3bis) ---
# DERNIER dossier de la collection (rang 11 sur onze -- voir la note de
# sequencement dans 1. Connexion). Se reconnecte D'ABORD explicitement (independant de la session
# active a la sortie du dossier RBAC, qui appartient au DERNIER role connecte
# la-bas -- Drive) : ce dossier reste rejouable seul, sans supposer un ordre
# d'execution particulier des dossiers precedents.
items.append({
    "name": "11. Fin de demo",
    "item": [
        request(
            "Se reconnecter (pour la demo de deconnexion)", "POST", "/admin/api/auth/login",
            json_body={"email": "{{email}}", "password": "{{password}}"},
            description="Reprend une session admin fraiche (independante de RBAC ci-dessus) avant de la detruire.",
            tests=LOGIN_TEST,
            csrf=False,
            bruno_script=LOGIN_BRUNO_SCRIPT,
            bruno_tests=LOGIN_BRUNO_TESTS,
        ),
        request(
            "Se deconnecter", "POST", "/admin/api/auth/logout",
            description=(
                "Detruit la session serveur (204, sans corps). Toute requete /admin/api/* "
                "suivante, tant qu'on ne s'est pas reconnecte, renvoie 401 AUTH_REQUIRED."
            ),
            tests=expect_status(204, "204 No Content"),
        ),
        request(
            "Verifier la deconnexion (401 attendu)", "GET", "/admin/api/auth/me",
            description="Preuve directe : la meme requete qui renvoyait 200 juste avant renvoie desormais 401 AUTH_REQUIRED, en JSON (jamais une redirection HTML vers /login).",
            tests=expect_status(401, "401 AUTH_REQUIRED apres deconnexion"),
        ),
    ],
})

collection = {
    "info": {
        "_postman_id": uid(),
        "name": "Wakdo - API d'administration JSON",
        "description": (
            "CRUD JSON de l'API d'administration Wakdo (/admin/api/*, docs/api/conventions.md "
            "section 5.3). Demarrer par 1. Connexion > Se connecter : la session (cookie "
            "WAKDO_SID) est geree par le pot a cookies de Postman, aucune manipulation manuelle. "
            "Prerequis : lire docs/api/demo-api.md. Les ecritures portent l'en-tete X-CSRF-Token "
            "(range automatiquement dans 'csrf' par la connexion). Les actions marquees PIN "
            "exigent en plus pin_email + pin dans le corps JSON. Toute la collection est REJOUABLE "
            "(deux executions consecutives sur la meme base restent 100% vertes) : noms/slugs/codes "
            "uniques par execution ({{run}}), aucun id ecrit en dur pour une ressource creee, chaque "
            "dossier se termine par la suppression ou la desactivation de ce qu'il a cree."
        ),
        "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
    },
    "item": items,
}

with open("docs/api/wakdo-admin.postman_collection.json", "w", encoding="utf-8") as fh:
    json.dump(collection, fh, ensure_ascii=False, indent=2)
    fh.write("\n")

print("wrote docs/api/wakdo-admin.postman_collection.json")

def write_postman_environment():
    """Genere docs/api/wakdo.postman_environment.json depuis ENV_VARS (source
    UNIQUE partagee avec gen_bruno.py, cf. commentaire de ENV_VARS plus haut).
    Jusqu'ici maintenu a la main (aucun script ne l'ecrivait, constat 2 du
    contre-audit du 29/09) : c'etait la faille qui a laisse gen_bruno.py diverger
    (baseUrl incoherent) sans qu'aucune regeneration ne le releve. L'id
    ("_postman_id" du fichier ENVIRONNEMENT, distinct de celui de la collection)
    reste le litteral historique deja committe, pour ne pas faire perdre son
    identifiant a l'environnement importe par quiconque l'a deja dans Postman."""
    values = [
        {
            "key": key,
            "value": default,
            "type": "secret" if secret else "default",
            "enabled": True,
        }
        for key, default, secret in ENV_VARS
    ]
    environment = {
        "id": "b4b9b7a0-6e6b-4a4e-9a8e-3a9c3f6d0e01",
        "name": "Wakdo admin API",
        "values": values,
        "_postman_variable_scope": "environment",
    }
    with open("docs/api/wakdo.postman_environment.json", "w", encoding="utf-8") as fh:
        json.dump(environment, fh, ensure_ascii=False, indent=2)
        fh.write("\n")
    print("wrote docs/api/wakdo.postman_environment.json")

write_postman_environment()
