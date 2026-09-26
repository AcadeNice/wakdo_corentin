#!/usr/bin/env python3
"""Genere docs/api/wakdo-admin.postman_collection.json (Postman Schema v2.1).

Script LIVRE (fait partie du depot, pas un brouillon jete apres usage) : c'est la
SOURCE DE VERITE de la collection Postman -- on modifie ce fichier pour changer un
contrat d'endpoint, jamais le JSON genere directement a la main (fragile, difficile
a relire et a diffuser en revue). Reexecuter ce script regenere le fichier a
l'identique (idempotent) si aucune requete n'a change ; toute modification de
contrat (nouvel endpoint, nouveau champ) passe par une modification ICI puis une
regeneration.

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

def cookie_header():
    return {"key": "Cookie", "value": "WAKDO_SID={{sid}}", "type": "text"}

def csrf_header():
    return {"key": "X-CSRF-Token", "value": "{{csrf}}", "type": "text"}

def json_content_type():
    return {"key": "Content-Type", "value": "application/json", "type": "text"}

def url(path):
    return {
        "raw": BASE + path,
        "host": [BASE],
        "path": [p for p in path.split("/") if p != ""],
    }

def body(obj):
    return {
        "mode": "raw",
        "raw": json.dumps(obj, ensure_ascii=False, indent=2),
        "options": {"raw": {"language": "json"}},
    }

def request(name, method, path, headers=None, json_body=None, description="", tests=None):
    hdrs = [cookie_header()]
    if json_body is not None or method in ("POST", "PUT", "DELETE"):
        hdrs.append(json_content_type())
    if method in ("POST", "PUT", "DELETE"):
        hdrs.append(csrf_header())
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

    return item

ME_TEST = [
    "const body = pm.response.json();",
    "pm.test('200 OK', function () { pm.response.to.have.status(200); });",
    "if (body && body.data && body.data.csrf_token) {",
    "    pm.environment.set('csrf', body.data.csrf_token);",
    "}",
]

def id_capture(varname):
    return [
        "const body = pm.response.json();",
        "if (body && body.data && body.data.id) {",
        f"    pm.environment.set('{varname}', body.data.id);",
        "}",
    ]

items = []

# --- Authentification ---
items.append({
    "name": "Authentification",
    "item": [
        request(
            "GET identite courante + jeton CSRF",
            "GET",
            "/admin/me",
            description=(
                "Point d'entree obligatoire : lit l'identite/permissions de la session "
                "(cookie WAKDO_SID, copie depuis le navigateur, cf. docs/api/postman.md) "
                "et stocke csrf_token dans la variable d'environnement 'csrf', reutilisee "
                "par toutes les requetes POST/PUT/DELETE ci-dessous."
            ),
            tests=ME_TEST,
        ),
    ],
})

# --- Categories ---
items.append({
    "name": "Categories",
    "item": [
        request("Lister les categories", "GET", "/admin/api/categories"),
        request("Lire une categorie", "GET", "/admin/api/categories/{{categoryId}}"),
        request(
            "Creer une categorie", "POST", "/admin/api/categories",
            json_body={"name": "Desserts Postman", "slug": "desserts-postman", "display_order": 50},
            description="category.manage, pas de PIN. 201 + Location.",
            tests=["pm.test('201 Created', function () { pm.response.to.have.status(201); });"] + id_capture("categoryId"),
        ),
        request(
            "Modifier une categorie", "PUT", "/admin/api/categories/{{categoryId}}",
            json_body={"name": "Desserts Postman v2", "slug": "desserts-postman", "display_order": 51},
        ),
        request(
            "Desactiver une categorie (DELETE = is_active=0)", "DELETE", "/admin/api/categories/{{categoryId}}",
            description="Pas de suppression dure (FK RESTRICT) : bascule is_active=0, meme geste que le bouton Masquer du back-office.",
        ),
        request(
            "Basculer visible/masque (toggle)", "POST", "/admin/api/categories/{{categoryId}}/toggle",
            description="Bascule dans les DEUX sens (contrairement a DELETE qui ne masque que).",
        ),
        request(
            "Deplacer d'un rang (move)", "POST", "/admin/api/categories/{{categoryId}}/move",
            json_body={"direction": "up"},
            description='direction : "up" ou "down".',
        ),
    ],
})

# --- Products ---
items.append({
    "name": "Produits",
    "item": [
        request("Lister les produits", "GET", "/admin/api/products"),
        request("Lire un produit", "GET", "/admin/api/products/{{productId}}"),
        request(
            "Creer un produit", "POST", "/admin/api/products",
            json_body={
                "category_id": 1,
                "name": "Produit Postman",
                "price_cents": 490,
                "vat_rate": 100,
                "is_available": True,
                "display_order": 99,
            },
            description=(
                "product.create, pas de PIN. price_cents en CENTIMES, ENTIER STRICT (mlt 8.1) : "
                "un decimal (590.9) ou une chaine en euros (\"12,50\") sont refuses (422), "
                "jamais tronques ni relus comme des euros. Adaptez category_id a un id reel "
                "de votre seed."
            ),
            tests=["pm.test('201 Created', function () { pm.response.to.have.status(201); });"] + id_capture("productId"),
        ),
        request(
            "Modifier un produit (sans changement de prix/TVA)", "PUT", "/admin/api/products/{{productId}}",
            json_body={
                "category_id": 1,
                "name": "Produit Postman renomme",
                "price_cents": 490,
                "vat_rate": 100,
                "is_available": True,
                "display_order": 99,
            },
            description=(
                "Aucun PIN requis tant que price_cents et vat_rate ne changent pas (mlt 8.2 RG-4). "
                "price_cents reste un entier de centimes strict (voir la requete de creation)."
            ),
        ),
        request(
            "Modifier le prix d'un produit (PIN requis)", "PUT", "/admin/api/products/{{productId}}",
            json_body={
                "category_id": 1,
                "name": "Produit Postman renomme",
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
                "Definissez d'abord un PIN sur le compte via /admin/profile/pin (navigateur, cf. postman.md)."
            ),
        ),
        request(
            "Supprimer un produit (PIN requis)", "DELETE", "/admin/api/products/{{productId}}",
            json_body={"pin_email": "{{pin_email}}", "pin": "{{pin}}"},
            description="409 CONFLICT si le produit est encore reference (commande, menu, recette).",
        ),
        request(
            "Deplacer d'un rang dans sa categorie (move)", "POST", "/admin/api/products/{{productId}}/move",
            json_body={"direction": "up"},
        ),
        request(
            "Lire la recette (composition)", "GET", "/admin/api/products/{{productId}}/recipe",
        ),
        request(
            "Remplacer la recette", "PUT", "/admin/api/products/{{productId}}/recipe",
            json_body={
                "composition": [
                    {"ingredient_id": 1, "quantity_normal": 1, "quantity_maxi": 2, "is_removable": True, "is_addable": False, "extra_price_cents": 0},
                ],
            },
            description="ingredient.manage, pas de PIN. Composition vide autorisee (purge la recette).",
        ),
    ],
})

# --- Menus ---
items.append({
    "name": "Menus",
    "item": [
        request("Lister les menus", "GET", "/admin/api/menus"),
        request("Lire un menu (avec slots)", "GET", "/admin/api/menus/{{menuId}}"),
        request(
            "Creer un menu", "POST", "/admin/api/menus",
            json_body={
                "category_id": 1,
                "burger_product_id": 1,
                "name": "Menu Postman",
                "price_normal_cents": 890,
                "price_maxi_cents": 990,
                "is_available": True,
                "display_order": 50,
                "slots": [
                    {"name": "Boisson", "slot_type": "drink", "is_required": True, "options": [2]},
                ],
            },
            description=(
                "menu.create, pas de PIN. burger_product_id doit etre un produit DE BASE "
                "(pas une variante de taille). price_normal_cents/price_maxi_cents sont des entiers "
                "de centimes stricts (un decimal ou une chaine en euros est refuse, 422). "
                "Adaptez les ids a votre seed."
            ),
            tests=["pm.test('201 Created', function () { pm.response.to.have.status(201); });"] + id_capture("menuId"),
        ),
        request(
            "Modifier un menu", "PUT", "/admin/api/menus/{{menuId}}",
            json_body={
                "category_id": 1,
                "burger_product_id": 1,
                "name": "Menu Postman v2",
                "price_normal_cents": 890,
                "price_maxi_cents": 990,
                "is_available": True,
                "display_order": 50,
                "slots": [
                    {"name": "Boisson", "slot_type": "drink", "is_required": True, "options": [2]},
                ],
            },
            description="Memes regles que la creation : prix en entiers de centimes stricts.",
        ),
        request(
            "Supprimer un menu (PIN requis)", "DELETE", "/admin/api/menus/{{menuId}}",
            json_body={"pin_email": "{{pin_email}}", "pin": "{{pin}}"},
            description="409 CONFLICT si le menu est encore reference par des commandes.",
        ),
        request(
            "Basculer la disponibilite (toggle)", "POST", "/admin/api/menus/{{menuId}}/toggle",
        ),
    ],
})

# --- Ingredients ---
items.append({
    "name": "Ingredients",
    "item": [
        request("Lister les ingredients", "GET", "/admin/api/ingredients"),
        request("Lire un ingredient", "GET", "/admin/api/ingredients/{{ingredientId}}"),
        request(
            "Creer un ingredient", "POST", "/admin/api/ingredients",
            json_body={
                "name": "Ingredient Postman",
                "unit": "unite",
                "pack_size": 50,
                "pack_label": "carton de 50",
                "stock_capacity": 500,
                "low_stock_pct": 20,
                "critical_stock_pct": 5,
            },
            description="ingredient.manage, pas de PIN. stock_quantity pose a 0 cote serveur (RG-CREATE-ING).",
            tests=["pm.test('201 Created', function () { pm.response.to.have.status(201); });"] + id_capture("ingredientId"),
        ),
        request(
            "Modifier un ingredient", "PUT", "/admin/api/ingredients/{{ingredientId}}",
            json_body={
                "name": "Ingredient Postman v2",
                "unit": "unite",
                "pack_size": 50,
                "pack_label": "carton de 50",
                "stock_capacity": 500,
                "low_stock_pct": 20,
                "critical_stock_pct": 5,
            },
        ),
        request(
            "Reapprovisionner (restock)", "POST", "/admin/api/ingredients/{{ingredientId}}/restock",
            json_body={"packs": 5, "note": "reappro Postman"},
            description="stock.manage, pas de PIN (mlt 9.1). L'ingredient doit etre actif.",
        ),
        request(
            "Supprimer un ingredient", "DELETE", "/admin/api/ingredients/{{ingredientId}}",
            description="409 CONFLICT si reference par une recette ou des mouvements de stock.",
        ),
        request(
            "Basculer actif/inactif (toggle)", "POST", "/admin/api/ingredients/{{ingredientId}}/toggle",
        ),
        request(
            "Reglage rapide des seuils", "PUT", "/admin/api/ingredients/{{ingredientId}}/thresholds",
            json_body={"stock_capacity": 600, "low_stock_pct": 20, "critical_stock_pct": 5},
            description="stock.manage, pas de PIN (calibrage, pas un comptage).",
        ),
        request(
            "Inventaire (comptage absolu, PIN requis)", "POST", "/admin/api/ingredients/{{ingredientId}}/inventory",
            json_body={"actual_quantity": 42, "note": "comptage Postman", "pin_email": "{{pin_email}}", "pin": "{{pin}}"},
            description="stock.count + PIN (mlt 9.2). Pas d'audit_log au succes (stock_movement suffit).",
        ),
        request(
            "Ajustement libre (delta signe, PIN requis)", "POST", "/admin/api/ingredients/{{ingredientId}}/adjust",
            json_body={"delta": -3, "note": "casse Postman", "pin_email": "{{pin_email}}", "pin": "{{pin}}"},
            description="stock.count + PIN. delta entier non nul (+ pour ajouter, - pour retirer).",
        ),
        request(
            "Revue des allergenes", "PUT", "/admin/api/ingredients/{{ingredientId}}/allergens",
            json_body={"allergen_ids": [1], "source": "Fiche fournisseur"},
            description="ingredient.manage, pas de PIN. source obligatoire (une revue non sourcee est refusee, 422).",
        ),
    ],
})

# --- Users ---
items.append({
    "name": "Utilisateurs",
    "item": [
        request("Lister les utilisateurs", "GET", "/admin/api/users"),
        request("Lire un utilisateur", "GET", "/admin/api/users/{{userId}}"),
        request(
            "Creer un utilisateur (PIN requis)", "POST", "/admin/api/users",
            json_body={
                "email": "equipier.postman@wakdo.local",
                "first_name": "Postman",
                "last_name": "Equipier",
                "role_id": 2,
                "password": "MotDePasse123!",
                "pin_email": "{{pin_email}}",
                "pin": "{{pin}}",
            },
            description="user.create, PIN obligatoire (mlt 10.1). Adaptez role_id a un role reel de votre seed.",
            tests=["pm.test('201 Created', function () { pm.response.to.have.status(201); });"] + id_capture("userId"),
        ),
        request(
            "Modifier un utilisateur (PIN requis)", "PUT", "/admin/api/users/{{userId}}",
            json_body={
                "email": "equipier.postman@wakdo.local",
                "first_name": "Postman",
                "last_name": "Equipier v2",
                "role_id": 2,
                "is_active": True,
                "pin_email": "{{pin_email}}",
                "pin": "{{pin}}",
            },
            description=(
                "user.update, PIN obligatoire (mlt 10.2). PUT PARTIEL sur is_active : "
                "omis, il conserve la valeur actuelle (ne desactive jamais par omission) ; "
                "envoye ici a true pour l'illustrer explicitement."
            ),
        ),
        request(
            "Desactiver un utilisateur (DELETE, PIN requis)", "DELETE", "/admin/api/users/{{userId}}",
            json_body={"pin_email": "{{pin_email}}", "pin": "{{pin}}"},
            description=(
                "user.deactivate. == desactivation (PAS de suppression physique ni "
                "d'effacement RGPD, mlt 10.3). Refuse sur son propre compte (403) ou "
                "sur le dernier administrateur actif (422)."
            ),
        ),
        request(
            "Reinitialiser le PIN (PIN requis)", "POST", "/admin/api/users/{{userId}}/reset-pin",
            json_body={"pin_email": "{{pin_email}}", "pin": "{{pin}}"},
            description="Efface le PIN de la cible ; elle le redefinit ensuite en self-service (/admin/profile/pin).",
        ),
        request(
            "Anonymiser (RGPD, PIN requis)", "POST", "/admin/api/users/{{userId}}/erase",
            json_body={"pin_email": "{{pin_email}}", "pin": "{{pin}}"},
            description="Tombstone (pas de suppression physique). 403 sur son propre compte, 409 si deja anonymise.",
        ),
    ],
})

# --- Roles ---
items.append({
    "name": "Roles (RBAC)",
    "item": [
        request("Lister les roles", "GET", "/admin/api/roles"),
        request("Lire un role (avec permissions)", "GET", "/admin/api/roles/{{roleId}}"),
        request(
            "Creer un role (PIN requis)", "POST", "/admin/api/roles",
            json_body={
                "code": "shift_lead_postman",
                "label": "Chef de faction (Postman)",
                "permission_ids": [1],
                "visible_sources": ["counter"],
                "pin_email": "{{pin_email}}",
                "pin": "{{pin}}",
            },
            description=(
                "role.manage, PIN obligatoire (escalade de privileges, mlt 10.4). "
                "permission_ids : liste d'ids du catalogue fige (voir GET /admin/api/roles/{id})."
            ),
            tests=["pm.test('201 Created', function () { pm.response.to.have.status(201); });"] + id_capture("roleId"),
        ),
        request(
            "Modifier un role (PIN requis)", "PUT", "/admin/api/roles/{{roleId}}",
            json_body={
                "label": "Chef de faction (Postman) v2",
                "permission_ids": [1],
                "visible_sources": ["counter", "drive"],
                "pin_email": "{{pin_email}}",
                "pin": "{{pin}}",
            },
            description=(
                "Garde-fou anti-lockout (422) : le role 'admin' doit garder role.manage et "
                "rester actif. PUT PARTIEL sur is_active, meme regle que les utilisateurs "
                "(omis = valeur actuelle conservee)."
            ),
        ),
    ],
})

# --- Commandes ---
items.append({
    "name": "Commandes",
    "item": [
        request(
            "Lister les commandes (filtre par canaux visibles)", "GET", "/admin/api/orders",
            description="order.read. Filtre par role_visible_source (RG-T12), meme regle que la file cuisine.",
        ),
        request("Lire une commande", "GET", "/admin/api/orders/{{orderNumber}}"),
        request(
            "Saisir une commande comptoir/drive", "POST", "/admin/api/orders",
            json_body={
                "service_mode": "dine_in",
                "source": "counter",
                "items": [
                    {"type": "product", "product_id": 1, "quantity": 1},
                ],
            },
            description=(
                "order.create, pas de PIN, encaissee immediatement (comme le HTML). "
                "Un seul endpoint JSON pour les deux canaux (le HTML a deux pages, "
                "/counter/orders et /drive/orders) : un role a canal FIXE (role.order_source) "
                "impose sa source et IGNORE ce champ 'source' s'il est fourni ; un role SANS "
                "canal fixe (admin/manager) doit le renseigner ('counter' ou 'drive'), sinon 422."
            ),
            tests=["const b = pm.response.json(); if (b && b.data && b.data.order_number) { pm.environment.set('orderNumber', b.data.order_number); }"],
        ),
        request(
            "Marquer prete (cuisine)", "POST", "/admin/api/orders/{{orderNumber}}/ready",
            description="order.read, pas de PIN. Meme garde de visibilite de source (PRE-3) que le HTML.",
        ),
        request(
            "Remettre au client (deliver)", "POST", "/admin/api/orders/{{orderNumber}}/deliver",
            description="order.deliver, pas de PIN.",
        ),
        request(
            "Annuler (PIN requis)", "POST", "/admin/api/orders/{{orderNumber}}/cancel",
            json_body={"pin_email": "{{pin_email}}", "pin": "{{pin}}"},
            description="order.cancel + PIN (RG-T13). 422 CANNOT_CANCEL_IN_STATE si deja livree/annulee.",
        ),
    ],
})

# --- Statistiques ---
items.append({
    "name": "Statistiques",
    "item": [
        request(
            "Tableau de bord (compteurs + stock + ventes)", "GET", "/admin/api/stats",
            description="stats.read, lecture seule.",
        ),
    ],
})

collection = {
    "info": {
        "_postman_id": uid(),
        "name": "Wakdo - API d'administration JSON",
        "description": (
            "CRUD JSON de l'API d'administration Wakdo (/admin/api/*, docs/api/conventions.md "
            "section 5.3). Prerequis : lire docs/api/postman.md (cookie de session + jeton CSRF). "
            "Toutes les requetes portent le cookie WAKDO_SID et, pour les ecritures, l'en-tete "
            "X-CSRF-Token. Les actions marquees PIN exigent en plus pin_email + pin dans le corps JSON."
        ),
        "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
    },
    "item": items,
}

with open("docs/api/wakdo-admin.postman_collection.json", "w", encoding="utf-8") as fh:
    json.dump(collection, fh, ensure_ascii=False, indent=2)
    fh.write("\n")

print("wrote docs/api/wakdo-admin.postman_collection.json")
