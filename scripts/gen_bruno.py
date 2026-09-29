#!/usr/bin/env python3
"""Genere la collection Bruno native (docs/api/bruno/, fichiers .bru) a partir
de la MEME source de donnees que scripts/gen_postman.py.

Script LIVRE (comme gen_postman.py) : c'est la SOURCE DE VERITE des fichiers
.bru -- on modifie CE script (ou gen_postman.py pour le contenu des endpoints,
reutilise ici par import) pour changer un contrat, jamais les .bru directement
a la main.

Pourquoi importer gen_postman plutot que redefinir les 91 requetes une
deuxieme fois (nombre reel au 29/09 -- comptees requete par requete dans
`gen_postman.items`, pas l'estimation "~55" d'une version anterieure a moins de
dossiers, contre-audit constat 9) : un seul point d'entretien pour methode/chemin/corps/description
(la partie identique entre les deux outils) ; seuls les scripts de test (API
JS differente entre Postman et Bruno, cf. plus bas) sont RETRADUITS ici, pas
copies. Cout assume (comme documente dans ADR-0017 pour d'autres duplications
similaires) : ce script depend de la structure interne du dict Postman produit
par gen_postman.request() (cles 'name'/'request'/'item'/'event') -- un
changement de forme de ce cote-la doit rester compatible ou mettre a jour la
traduction ci-dessous.

Traduction des scripts de test : Postman (`pm.test`, `pm.response.json()`,
`pm.environment.set(...)`) et Bruno (`expect(res.getStatus())...`,
`res.getBody()`, `bru.setEnvVar(...)`, cf. docs.usebruno.com/testing/script/
javascript-reference) n'ont PAS la meme API -- copier le JS Postman tel quel ne
fonctionnerait pas sous Bruno. gen_postman.py n'ecrit que quatre motifs de test
(capture de csrf_token, capture d'un id, capture d'un numero de commande,
assertion de code HTTP) : ce script les RECONNAIT par expression reguliere
(motifs stables, ecrits par gen_postman.py lui-meme) et les reecrit dans l'API
Bruno equivalente, plutot que d'essayer d'interpreter du JS arbitraire.

Format de sortie : classique `.bru` (pas OpenCollection YAML -- la doc Bruno
note que ce dernier est "the recommended format for new collections" au moment
d'ecrire ceci, mais le classique reste pleinement supporte et c'est le format
demande ici, plus proche d'une revue par fichier en pull request).

Usage :
    python3 scripts/gen_bruno.py             # regenere docs/api/bruno/
    python3 scripts/gen_bruno.py --help       # affiche cette aide, n'ecrit rien
"""
import json
import os
import re
import shutil
import sys

if "--help" in sys.argv or "-h" in sys.argv:
    print(__doc__)
    sys.exit(0)

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import gen_postman  # noqa: E402  -- reutilise sa liste `items` (source unique des endpoints)

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT_DIR = os.path.join(ROOT, "docs", "api", "bruno")

CAPTURE_RE = re.compile(r"pm\.environment\.set\('([a-zA-Z0-9_]+)',\s*\w+\.data\.(id|csrf_token|order_number)\)")
# Motif EXACT ecrit par expect_status()/created_test()/LOGIN_TEST/rbac_login_test
# dans gen_postman.py : f"pm.test('{label}', function () {{ pm.response.to.have.
# status({status}); }});" -- capture (label, status) pour reecrire une assertion
# NOMMEE cote Bruno (test('label', function () {...}), pas un expect() nu.
# Contre-audit du 29/09 (constat 5) : un expect() nu, hors de tout test(), ne
# compte PAS comme un test individuel dans le rapport `bru run` (rien n'y
# apparait comme reussite/echec nomme -- seule une erreur non rattrapee fait
# echouer l'execution globale du fichier), alors que test('label', fn) est
# compte et nomme, exactement comme pm.test(...) cote Postman.
TEST_RE = re.compile(
    r"pm\.test\('([^']*)',\s*function\s*\(\)\s*\{\s*pm\.response\.to\.have\.status\((\d+)\)\s*;\s*\}\s*\);"
)


def safe_name(name):
    """Nom de fichier/dossier portable (Windows interdit : < > : " / \\ | ? *).
    Le nom AFFICHE (meta.name, dans le fichier) garde lui la forme exacte de
    la collection Postman -- seul le nom sur disque est assaini."""
    return re.sub(r'[<>:"/\\|?*]', '-', name).strip()


def is_folder(node):
    return "item" in node


def translate_tests(name, event):
    """event = item['event'] Postman (liste d'1 objet {listen:'test', script:
    {exec:[...]}}) ou None. Renvoie (script_post_response_lines, tests_lines),
    chacune une liste de lignes JS Bruno (vide si rien a traduire).

    Consulte D'ABORD gen_postman.BRUNO_OVERRIDES (motif trop specifique pour
    une traduction generique -- recherche dans une reponse, comparaisons,
    branchements : cf. docstring du module) ; ne retombe sur la traduction par
    expression reguliere que pour les quatre motifs simples que gen_postman.py
    ecrit lui-meme (capture d'id/csrf_token/order_number, assertion de statut)."""
    override = gen_postman.BRUNO_OVERRIDES.get(name)
    if override is not None:
        return override["script"], override["tests"]

    if not event:
        return [], []

    js = "\n".join(event[0]["script"]["exec"])

    captures = CAPTURE_RE.findall(js)
    named_tests = list(dict.fromkeys(TEST_RE.findall(js)))

    script_lines = []
    if captures:
        script_lines.append("const body = res.getBody();")
        for varname, field in captures:
            script_lines.append(
                f"if (body && body.data && body.data.{field}) {{ bru.setEnvVar('{varname}', body.data.{field}); }}"
            )

    test_lines = [
        f"test('{label}', function () {{ expect(res.getStatus()).to.equal({status}); }});"
        for label, status in named_tests
    ]

    return script_lines, test_lines


def bru_headers_block(headers):
    if not headers:
        return ""
    lines = "\n".join(f"  {h['key']}: {h['value']}" for h in headers)
    return f"headers {{\n{lines}\n}}\n\n"


def bru_body_block(request):
    body = request.get("body")
    if body is None:
        return ""
    raw = body["raw"]
    indented = "\n".join(("  " + line if line else line) for line in raw.split("\n"))
    # Forme documentee (docs.usebruno.com/bru-lang/language, tag-reference) :
    # le contenu JSON est place DIRECTEMENT a l'interieur du bloc body{...},
    # sans qualificatif ':json' (non retrouve dans la documentation officielle
    # malgre plusieurs recherches -- cf. docs/api/demo-api.md pour le detail et
    # le repli si un import Bruno plus recent attendait 'body:json').
    return f"body {{\n{indented}\n}}\n\n"


def bru_request_file(name, seq, request, event):
    method = request["method"].lower()
    raw_url = request["url"]["raw"]
    description = request.get("description", "")

    script_lines, test_lines = translate_tests(name, event)

    out = "meta {\n"
    out += f"  name: {name}\n"
    out += "  type: http\n"
    out += f"  seq: {seq}\n"
    out += "}\n\n"

    out += f"{method} {{\n  url: {raw_url}\n}}\n\n"

    out += bru_headers_block(request.get("header"))
    out += bru_body_block(request)

    if description:
        # Bloc texte multi-lignes : ''' ... ''' (meme convention que l'exemple
        # @description(''' ... ''') documente pour les variables d'environnement).
        out += f"docs {{\n{description}\n}}\n\n"

    if script_lines:
        out += "script:post-response {\n"
        out += "\n".join(f"  {line}" for line in script_lines)
        out += "\n}\n\n"

    if test_lines:
        out += "tests {\n"
        out += "\n".join(f"  {line}" for line in test_lines)
        out += "\n}\n\n"

    return out.rstrip() + "\n"


def write_folder_meta(path, name, seq):
    # folder.bru : nom AFFICHE + ordre d'execution du dossier lui-meme. Le nom
    # SUR DISQUE porte deja un prefixe numerique (voir write_folder) qui
    # garantit l'ordre par tri alphabetique SEUL, sans dependre de la lecture
    # de ce 'seq' par un outil donne (empiriquement verifie via `bru run`,
    # cf. docs/api/demo-api.md) -- ce fichier documente en plus l'intention.
    content = "meta {\n" + f"  name: {name}\n" + f"  seq: {seq}\n" + "}\n"
    with open(os.path.join(path, "folder.bru"), "w", encoding="utf-8") as fh:
        fh.write(content)


def write_folder(node_items, path):
    os.makedirs(path, exist_ok=True)
    for idx, node in enumerate(node_items, start=1):
        name = node["name"]
        if is_folder(node):
            # Prefixe numerique SUR DISQUE (2 chiffres) : garantit l'ordre
            # d'execution par le tri alphabetique par defaut, independamment
            # de la prise en compte ou non de meta.seq par l'outil qui lit la
            # collection (cf. docstring du module).
            folder_path = os.path.join(path, f"{idx:02d}-{safe_name(name)}")
            os.makedirs(folder_path, exist_ok=True)
            write_folder_meta(folder_path, name, idx)
            write_folder(node["item"], folder_path)
        else:
            content = bru_request_file(name, idx, node["request"], node.get("event"))
            filename = f"{idx:02d}-{safe_name(name)}.bru"
            with open(os.path.join(path, filename), "w", encoding="utf-8") as fh:
                fh.write(content)


def write_bruno_json():
    manifest = {
        "version": "1",
        "name": "Wakdo - API d'administration JSON",
        "type": "collection",
        "ignore": ["node_modules", ".git"],
    }
    with open(os.path.join(OUT_DIR, "bruno.json"), "w", encoding="utf-8") as fh:
        json.dump(manifest, fh, ensure_ascii=False, indent=2)
        fh.write("\n")


def write_environment():
    # SOURCE UNIQUE : gen_postman.ENV_VARS (voir son commentaire dans
    # scripts/gen_postman.py). Avant ce chantier, ce fichier maintenait sa PROPRE
    # liste plain/secrets, dupliquee de docs/api/wakdo.postman_environment.json --
    # c'est cette duplication qui a laisse baseUrl diverger ("http://localhost:8080"
    # ici, corrige a la main en "http://admin.localhost:8080" seulement cote fichier
    # commis) sans qu'aucune regeneration ne le releve (contre-audit du 29/09,
    # constat 2). categoryId/productId/menuId/ingredientId/userId/roleId/orderNumber
    # (variables mortes, constat 4) ont disparu avec cette meme liste.
    #
    # Format Bruno officiel des variables secretes (constat 3, docs.usebruno.com/
    # variables/environment-variables) : une cle secrete va UNIQUEMENT dans
    # vars:secret[] (sa valeur se fournit a l'usage par --env-var, jamais committee)
    # -- PAS aussi dans vars{} avec une valeur vide, doublon que la version
    # anterieure de ce fichier committait pour les 11 cles secretes.
    plain_lines = []
    secret_keys = []
    for key, default, secret in gen_postman.ENV_VARS:
        if secret:
            secret_keys.append(key)
        else:
            plain_lines.append(f"  {key}: {default}")

    lines = ["vars {"]
    lines.extend(plain_lines)
    lines.append("}")
    lines.append("")
    lines.append("vars:secret [")
    for key in secret_keys:
        lines.append(f"  {key},")
    lines.append("]")
    lines.append("")

    env_dir = os.path.join(OUT_DIR, "environments")
    os.makedirs(env_dir, exist_ok=True)
    with open(os.path.join(env_dir, "wakdo.bru"), "w", encoding="utf-8") as fh:
        fh.write("\n".join(lines))


if os.path.isdir(OUT_DIR):
    shutil.rmtree(OUT_DIR)
os.makedirs(OUT_DIR, exist_ok=True)

write_bruno_json()
write_environment()
write_folder(gen_postman.items, OUT_DIR)

print(f"wrote {OUT_DIR}/ (bruno.json, environments/wakdo.bru, {len(gen_postman.items)} dossiers de premier niveau)")
