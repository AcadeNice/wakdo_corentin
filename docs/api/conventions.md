# API Wakdo - conventions de nommage, structure et listing

**Statut** : v0.3 - API d'administration JSON livree sous `/admin/api/*` (section 5.3,
[ADR-0017](../adr/0017-api-admin-json.md))
**Perimetre** : back-office admin (rendu serveur) + API REST publique sous `/api/*` + API
d'administration JSON sous `/admin/api/*`
**Auteur methodologie** : BYAN
**A lire avec** : `docs/PROJECT_CONTEXT.md`, `docs/merise/dictionary.md` (source de verite des
noms de champs), `docs/merise/mct.md` + `mlt.md` (operations metier), `db/seeds/0001_rbac_and_reference.sql`
(catalogue des 23 permissions). NB : `docs/api/byan-api.md` documente l'API de la plateforme BYAN,
distincte de l'API Wakdo decrite ici.

---

## 1. Objet

Fixer les conventions de nommage, la structure des points d'entree HTTP de Wakdo, et tenir le
listing des endpoints (en service et prevus). Objectif : que chaque endpoint ajoute suive le meme
moule. Les choix sont des conventions de projet (coherence, lisibilite), pas des regles universelles ;
une convention peut evoluer, auquel cas ce document est mis a jour en premier.

---

## 2. Par quoi passe une requete

Deux hotes distincts, un seul conteneur web (Apache), routes par le Traefik de l'hote :

```
Client (borne / navigateur back-office)
  -> Traefik (TLS, ajoute X-Forwarded-For, route par Host)
    -> wakdo-web (Apache, vhost selon le Host)
       - vhost kiosk  : DocumentRoot src/public/borne  (statique + futur appel /api)
       - vhost admin  : DocumentRoot src/public/admin
         - fichier existant (assets/ : css, js, images) : servi tel quel
         - sinon RewriteRule -> index.php (front controller)
    -> wakdo-app (PHP-FPM, via proxy FastCGI sur *.php)
       front controller -> Router -> Controller -> Response
    -> wakdo-db (MariaDB, requetes preparees PDO uniquement)
```

Consequence de nommage : le DocumentRoot du vhost admin est `src/public/admin`, donc le
`REQUEST_URI` arrive **sans prefixe** `/admin`. Le Router voit `/login`, `/api/health`, etc.
On n'ajoute pas de segment `/admin` dans les chemins de routes.

Code de reference : routes dans `src/public/admin/index.php`, controleurs dans
`src/app/Controllers/`, enveloppe de reponse dans `src/app/Core/Response.php`, resolution
(404 / 405) dans `src/app/Core/Router.php`.

---

## 3. Trois familles d'endpoints

| Famille | Prefixe | Rendu | Authentification | Exemple |
|---|---|---|---|---|
| Pages back-office | aucun | HTML (vue serveur + `layout.php`) | session admin | `/login`, `/forgot_password` |
| API REST publique | `/api/` | JSON (enveloppe section 7) | publique ou session (section 10) | `/api/health`, `/api/categories` (livre) |
| API d'administration JSON | `/admin/api/` | JSON (enveloppe section 7) | session admin + permission + PIN (section 5.3) | `/admin/api/products` (livre) |

La borne (kiosk) consomme l'API REST `/api/*` en lecture pour le catalogue (voir section 8.3).
L'API d'administration (`/admin/api/*`, section 5.3) est un troisieme prefixe, DISTINCT de
`/api/*` : le vhost kiosk relaie tout `/api/*` vers PHP-FPM pour la borne PUBLIQUE
(`docker/apache/vhost.conf`, `ProxyPassMatch "^/api(/.*)?$"`), donc une route authentifiee
placee sous `/api` serait atteignable depuis l'origine kiosk. `/admin/api/*` ne matche pas ce
prefixe et reste hors de portee du vhost kiosk (meme raisonnement que `/admin/me`, deja en
service). Voir [ADR-0017](../adr/0017-api-admin-json.md).

---

## 4. Nommage des chemins (URL)

Deux decisions, dont une sourcee et une de coherence :

- **Minuscules** sur tout le chemin. Sourced : RFC 3986 §6.2.2.1 - seuls le scheme et l'hote sont
  insensibles a la casse, le path est sensible a la casse ; le minuscule evite les bugs de casse.
- **Separateur de mots : `_` (snake_case)**. Aucun standard n'impose `-` ou `_` dans un segment
  (les deux sont des caracteres `unreserved`, RFC 3986 §2.3). On retient `_` pour n'avoir **qu'une
  seule convention de casse** sur tout le projet : colonnes DB, champs JSON (section 8) et chemins
  d'URL partagent le snake_case. Cela calque les noms de tables (`order_item` -> `/api/order_items`)
  et reduit la charge a memoriser (Rasoir d'Ockham, mantra #37).

Autres regles :

- **Noms de ressources au pluriel** pour les collections : `/api/categories`, `/api/products`,
  `/api/orders`.
- **Identifiant en segment** pour une ressource unitaire : `/api/orders/{number}`,
  `/api/products/{id}`. Parametre dynamique : `{nom}` (groupe nomme cote Router).
- **Sous-ressource** par imbrication : `/api/orders/{id}/items` (prevu).
- **Action non-CRUD** par sous-chemin verbe : `POST /api/orders/{id}/cancel`
  (cf. `docs/uml/security-sequence.md`).
- Pas de barre oblique finale signifiante : `Request::normalizePath` aligne `/api/health/` et
  `/api/health`.

---

## 5. Listing des endpoints

### 5.1 En service (P2)

| Methode | Chemin | Auth | Rendu | Role |
|---|---|---|---|---|
| GET | `/` | (session en P3) | HTML | accueil back-office (squelette) |
| GET | `/api/health` | public | JSON (plat) | sonde de sante (DB reelle) |
| GET | `/login` | public | HTML | formulaire de connexion |
| POST | `/login` | public + CSRF | 302 / HTML | authentification (mlt 12.1) |
| POST | `/logout` | session + CSRF | 302 | deconnexion (mlt 12.2) |
| GET | `/forgot_password` | public | HTML | demande de reinitialisation |
| POST | `/forgot_password` | public + CSRF | HTML (neutre) | envoi du lien (mlt 12.3) |
| GET | `/reset_password` | public (token en query) | HTML | formulaire nouveau mot de passe |
| POST | `/reset_password` | public + CSRF | 302 / HTML | confirmation (mlt 12.3) |
| GET | `/admin/me` | session | JSON | identite + permissions + jeton CSRF du compte courant (RG-6/RG-T02/RG-T03) |

`/admin/me` (sous `/admin`, pas `/api` : cf. section 3) est le premier consommateur reel de
`SessionGuard` (RG-6 idle/absolu + RG-T02 is_active) et d'`Authorizer` (RG-T03, permissions
rechargees depuis la base). Reponse :
`{ "data": { "user_id", "role_id", "role_code", "permissions": [...], "csrf_token" } }` ;
`401 AUTH_REQUIRED` si la session est absente, expiree ou le compte desactive. `csrf_token`
est le point d'entree du jeton CSRF pour l'API d'administration JSON (section 5.3) : un
client sans formulaire HTML (Postman, script) le lit ici puis le renvoie en en-tete
`X-CSRF-Token` sur chaque `POST`/`PUT`/`DELETE`. Les autorisations par operation et le PIN
des actions sensibles (RG-T13) sont cables depuis P3 sur les pages HTML, et depuis ce
chantier sur l'API JSON equivalente (section 5.3).

### 5.2 API kiosk - lecture catalogue + commande (livre, public)

La borne est publique (aucune session) ; cf. `mlt.md` CREATE_ORDER, declencheur kiosk.

| Methode | Chemin | Permission | Op MCT | Statut |
|---|---|---|---|---|
| GET | `/api/categories` | (lecture publique) | READ_CATALOGUE | livre |
| GET | `/api/products` | (lecture publique) | READ_CATALOGUE | livre |
| GET | `/api/products/{id}` | (lecture publique) | READ_CATALOGUE | livre |
| GET | `/api/menus` | (lecture publique) | READ_CATALOGUE | livre |
| GET | `/api/menus/{id}` | (lecture publique) | READ_CATALOGUE | livre (slots de composition) |
| GET | `/api/allergens` | (lecture publique) | READ_CATALOGUE | livre (14 allergenes INCO) |
| POST | `/api/orders` | (kiosk public) | CREATE_ORDER (mlt 3.3) | livre (idempotency_key, RG-T19) |
| POST | `/api/orders/{number}/pay` | (kiosk public) | (encaissement) | livre (paid + decrement stock RG-T20) |
| GET | `/api/orders/{number}` | (lecture publique) | (suivi statut) | livre, RESTREINT AU CANAL KIOSK (relecture adverse, point 5b) : cet endpoint est public et anonyme, et les numeros sont sequentiels (prefixe canal + id auto-incremente) -- une commande comptoir/drive n'est PAS "kiosk anonyme" et son statut/total n'a pas a etre lisible sans authentification. Une commande d'un AUTRE canal rend la MEME reponse `404 ORDER_NOT_FOUND` qu'un numero inconnu (anti-enumeration). Champs renvoyes : `order_number`, `status` -- `total_ttc_cents` a ete RETIRE (aucun ecran borne ne le consomme sur cet endpoint ; `create()`/`pay()` continuent de le renvoyer, eux, car l'ecran de paiement en a besoin) |

### 5.3 API d'administration JSON (`/admin/api/*`, livre, session + permission + PIN)

Le choix entre endpoints JSON et pages rendues serveur pour les ecritures admin a ete
tranche : le back-office HTML (`/admin/*`, formulaires + redirections) reste le canal
primaire (ADR-0002), et cette API JSON sous **`/admin/api/*`** (et non `/api/*`, cf. section
3) le complete pour l'usage machine (tests Postman, integrations). Elle REUTILISE la meme
logique metier que le HTML — memes repositories, memes regles de validation, meme PIN — via
heritage de controleur (voir [ADR-0017](../adr/0017-api-admin-json.md)), pas une
reimplementation parallele. Les colonnes Permission renvoient au catalogue fige des 23
permissions (`db/seeds/0001_rbac_and_reference.sql`) ; l'imputabilite et le PIN suivent
`mlt.md` RG-T13/RG-T14.

**Authentification commune a toute route `/admin/api/*`** :

| Etape | Regle |
|---|---|
| Session | Cookie `WAKDO_SID` (section 9). Absente/expiree/compte desactive -> `401 AUTH_REQUIRED`. |
| Permission | Verifiee via `role_permission`, meme code que la page HTML equivalente (colonne Permission ci-dessous). Manquante -> `403 FORBIDDEN`. |
| Content-Type | Un corps NON VIDE doit s'annoncer `application/json` -> sinon `415 UNSUPPORTED_MEDIA_TYPE` (RFC 9110 §15.5.16). Un corps vide (GET, DELETE sans PIN) n'a pas cette contrainte. |
| Corps JSON invalide (syntaxe, ou racine qui n'est pas un objet) | `400 INVALID_JSON`. La racine DOIT etre un objet (`{...}`) ; une liste (`[...]`), un nombre, une chaine ou un booleen a la racine sont refuses (impossibles a lire comme des champs). |
| CSRF | En-tete `X-CSRF-Token` (le formulaire HTML utilise un champ cache `_csrf` ; l'API JSON n'en a pas, donc l'en-tete), exige sur `POST`/`PUT`/`DELETE`. Jeton lu via `GET /admin/me` (champ `csrf_token`). Absent/invalide -> `403 CSRF_INVALID`. Jeton SYNCHRONISEUR (ne tourne qu'a la regeneration de session, pas a chaque lecture de `/admin/me`) : un enchainement de requetes Postman reste valide sans le rafraichir. |
| PIN (actions marquees PIN) | Corps JSON `{ "pin_email": "...", "pin": "...." }` (modele "identifiant equipier + PIN", RG-T13, meme modele que le formulaire HTML). Invalide/verrouille -> `422 PIN_INVALID`. |
| Erreur de validation de champ (y compris un champ non scalaire -- tableau/objet -- pour un champ cense etre simple, et le garde-fou anti-lockout du dernier administrateur actif / du role `admin`) | `422 VALIDATION_ERROR`, avec le detail par champ dans `error.fields`. |
| Conflit (unicite, FK RESTRICT) | `409 CONFLICT`. |
| Ressource introuvable | `404 NOT_FOUND`. Exception anti-enumeration : sur les commandes (`/admin/api/orders/*`), un numero inconnu se comporte comme un canal non visible et renvoie `403 FORBIDDEN` plutot que `404` (cf. tableau Commandes ci-dessous). |
| Methode non enregistree sur un chemin connu | `405 METHOD_NOT_ALLOWED` (`Router::dispatch`). |

Categories (`category.manage`, pas de PIN — hors ensemble sensible RG-T13) :

| Methode | Chemin | PIN | Note |
|---|---|---|---|
| GET | `/admin/api/categories` | non | liste (`{data: [...], total}`) |
| GET | `/admin/api/categories/{id}` | non | |
| POST | `/admin/api/categories` | non | `201` + en-tete `Location` |
| PUT | `/admin/api/categories/{id}` | non | |
| DELETE | `/admin/api/categories/{id}` | non | PAS de suppression dure (FK RESTRICT) : bascule `is_active=0`, meme semantique que le bouton "Masquer" HTML. `200 { data: { id, status: "deactivated" } }` |
| POST | `/admin/api/categories/{id}/toggle` | non | bascule visible/masque dans les DEUX sens (contrairement a `DELETE`) |
| POST | `/admin/api/categories/{id}/move` | non | `{ "direction": "up"\|"down" }` |

Produits (`product.read` / `product.create` / `product.update` / `product.delete` /
`ingredient.manage` pour la recette) :

| Methode | Chemin | PIN | Note |
|---|---|---|---|
| GET | `/admin/api/products` | non | |
| GET | `/admin/api/products/{id}` | non | |
| POST | `/admin/api/products` | non | `price_cents` en CENTIMES (entier), pas en euros (mlt 8.1) |
| PUT | `/admin/api/products/{id}` | UNIQUEMENT si `price_cents` ou `vat_rate` change (mlt 8.2 RG-4) | sinon mise a jour simple sans PIN. PUT PARTIEL sur `is_available` (voir note ci-dessous) |
| DELETE | `/admin/api/products/{id}` | oui | `409 CONFLICT` si encore reference par une commande ou un menu (FK RESTRICT). La recette (`product_ingredient`) N'EST PAS un blocage : elle part en CASCADE avec le produit (comptee dans le resume d'audit). L'image deposee est supprimee (sauf image du catalogue livre avec le projet). |
| POST | `/admin/api/products/{id}/move` | non | `{ "direction": "up"\|"down" }`, dans sa categorie |
| GET | `/admin/api/products/{id}/recipe` | non | composition (`product_ingredient`) |
| PUT | `/admin/api/products/{id}/recipe` | non | remplace la composition ; `composition: []` autorise (purge la recette) |

Menus composes (`menu.read` / `menu.create` / `menu.update` / `menu.delete`) :

| Methode | Chemin | PIN | Note |
|---|---|---|---|
| GET | `/admin/api/menus` | non | |
| GET | `/admin/api/menus/{id}` | non | inclut `slots` (composition) |
| POST | `/admin/api/menus` | non | `slots` = tableau JSON natif (le formulaire HTML le soumet en `slots_json` serialise ; meme garde serveur F12/RG-T16 des deux cotes) |
| PUT | `/admin/api/menus/{id}` | non | PUT PARTIEL sur `is_available` (voir note ci-dessous) |
| DELETE | `/admin/api/menus/{id}` | oui | `409 CONFLICT` si reference par des commandes (proposer la desactivation) |
| POST | `/admin/api/menus/{id}/toggle` | non | bascule la disponibilite |

Stock et ingredients (`stock.read` / `ingredient.manage` / `stock.manage` / `stock.count`) :

| Methode | Chemin | PIN | Note |
|---|---|---|---|
| GET | `/admin/api/ingredients` | non | |
| GET | `/admin/api/ingredients/{id}` | non | |
| POST | `/admin/api/ingredients` | non | `stock_quantity=0` pose serveur (RG-CREATE-ING) |
| PUT | `/admin/api/ingredients/{id}` | non | |
| DELETE | `/admin/api/ingredients/{id}` | non | `409 CONFLICT` si reference (recette/mouvements) |
| POST | `/admin/api/ingredients/{id}/restock` | non | reapprovisionnement (mlt 9.1), `stock.manage` |
| POST | `/admin/api/ingredients/{id}/toggle` | non | bascule actif/inactif |
| PUT | `/admin/api/ingredients/{id}/thresholds` | non | capacite + seuils alerte/critique (`stock.manage`, calibrage) |
| POST | `/admin/api/ingredients/{id}/inventory` | oui | comptage absolu (mlt 9.2, `stock.count`) ; PAS d'audit_log au succes (RG-T14 : `stock_movement` suffit) |
| POST | `/admin/api/ingredients/{id}/adjust` | oui | delta signe non nul, meme garde que l'inventaire (R9, `stock.count`) |
| PUT | `/admin/api/ingredients/{id}/allergens` | non | `{ "allergen_ids": [int], "source": "..." }` (`ingredient.manage`) ; `source` obligatoire |

> **Plafonnement a la capacite (`restock`/`inventory`/`adjust`)** -- le stock d'un
> ingredient ne depasse JAMAIS sa capacite configuree (`stock_capacity`,
> `IngredientRepository::clampToCapacity()`). Ces trois endpoints repondent donc
> avec, en plus de la fiche ingredient a jour :
>
> ```json
> {
>   "data": {
>     "id": 3, "name": "Pain sesame", "stock_quantity": 300, "stock_capacity": 300,
>     "applied_delta": 0,
>     "requested_delta": 20,
>     "clamped": true
>   }
> }
> ```
>
> - `requested_delta` -- ce que la demande impliquait : `packs * pack_size` pour
>   `restock`, `delta` tel quel pour `adjust`, `actual_quantity - stock_quantity
>   (avant ecriture)` pour `inventory`.
> - `applied_delta` -- le changement REELLEMENT applique au stock, apres
>   plafonnement. Peut etre inferieur a `requested_delta` (voire `0`) si
>   l'ingredient est deja a sa capacite ou proche.
> - `clamped` -- `true` des que `applied_delta != requested_delta` : le client
>   API DOIT le distinguer d'un succes a plein effet plutot que de lire
>   silencieusement `200 OK` comme "tout s'est passe comme demande" (bug releve
>   2026-09-26 : le jeu de donnees de demonstration seede tous les ingredients a
>   100 % de leur capacite, rendant le tout premier reappro/ajustement toujours
>   plafonne). Le mouvement `stock_movement` ecrit, lui, TOUJOURS le delta
>   applique (jamais le delta demande) — ce champ ne fait qu'exposer la meme
>   valeur au client API, `RG-T08` inchangee.

Utilisateurs et RBAC (`user.read` / `user.create` / `user.update` / `user.deactivate` /
`role.manage`) :

| Methode | Chemin | PIN | Note |
|---|---|---|---|
| GET | `/admin/api/users` | non | |
| GET | `/admin/api/users/{id}` | non | |
| POST | `/admin/api/users` | oui | mlt 10.1 |
| PUT | `/admin/api/users/{id}` | oui | mlt 10.2. PUT PARTIEL sur `is_active` (voir note ci-dessous) |
| DELETE | `/admin/api/users/{id}` | oui | == desactivation (`user.deactivate`), PAS de suppression physique ni d'effacement RGPD (mlt 10.3) ; anti-lockout (dernier admin actif) -> `422 VALIDATION_ERROR` |
| POST | `/admin/api/users/{id}/reset-pin` | oui | efface le PIN de la cible (redefini ensuite en self-service HTML) |
| POST | `/admin/api/users/{id}/erase` | oui | anonymisation RGPD (mlt 10.5, tombstone, ADR-0007) ; `403` sur son propre compte, `409` si deja anonymise |
| GET | `/admin/api/roles` | non | |
| GET | `/admin/api/roles/{id}` | non | inclut `permission_ids`, `permissions`, `visible_sources` |
| POST | `/admin/api/roles` | oui | `permission_ids: [int]`, `visible_sources: ["kiosk"\|"counter"\|"drive"]` (mlt 10.4) |
| PUT | `/admin/api/roles/{id}` | oui | garde-fou anti-lockout (`422 VALIDATION_ERROR`) : le role `admin` garde `role.manage` et reste actif. PUT PARTIEL sur `is_active` (voir note ci-dessous) |

> **PUT partiel sur `is_active` / `is_available`** -- `is_active` sur
> `/admin/api/users/{id}` et `/admin/api/roles/{id}` ; `is_available` sur
> `/admin/api/products/{id}` et `/admin/api/menus/{id}` : ce champ OMIS du corps
> CONSERVE la valeur actuelle -- il ne desactive/rend JAMAIS indisponible par
> omission. Seule une valeur EXPLICITE (`false`) change l'etat, sous la meme
> permission et le meme PIN (quand il y en a un) que la mutation elle-meme. Tous
> les autres champs du PUT restent, eux, un ecrasement complet (pas de fusion
> partielle) : seuls ces deux champs (un par ressource) beneficient de cette
> semantique, pour ne jamais desactiver un compte/role ou rendre un produit/menu
> indisponible par un simple oubli de champ dans un client JSON.
>
> **Booleen JSON STRICT** -- `is_active`, `is_available`, et tout autre champ
> booleen de cette API, n'acceptent QUE le type JSON natif `true`/`false`. Une
> valeur presente mais d'un autre type -- la chaine `"true"`, la chaine `"false"`,
> l'entier `1` ou `0` -- est refusee : `422 VALIDATION_ERROR`, jamais interpretee
> comme un booleen "truthy"/"falsy" a la maniere de PHP.

> **Limite documentee** : aucune suppression de role — le back-office HTML n'en propose pas
> non plus (un role est rattache a des comptes).

Commandes (`order.read` / `order.create` / `order.deliver` / `order.cancel`) :

| Methode | Chemin | PIN | Note |
|---|---|---|---|
| GET | `/admin/api/orders` | non | liste recente, FILTREE par les sources visibles du role (`role_visible_source`, RG-T12), meme regle que la file cuisine |
| GET | `/admin/api/orders/{number}` | non | `403 FORBIDDEN` si le numero est inconnu OU si sa source n'est pas visible par le role : les deux cas rendent la meme reponse (anti-enumeration), pas de `404` qui revelerait qu'une commande d'un autre canal existe |
| POST | `/admin/api/orders` | non | saisie comptoir/drive (mlt 4.1), encaissee immediatement. Un seul endpoint JSON (contrairement aux deux pages HTML `/counter/orders`/`/drive/orders`) : un role a CANAL FIXE (`role.order_source` non NUL -- pas seulement `counter`/`drive`, TOUTE valeur non nulle, ex. `kiosk`) l'impose, le champ `source` du corps est alors IGNORE s'il est fourni ; un role SANS canal fixe (`admin` au seed 0001 -- `manager`, sans canal fixe lui aussi, n'a pas `order.create`) DOIT choisir `"source": "counter"` ou `"drive"` dans le corps, sinon `422 VALIDATION_ERROR` (saisie mal formee : aucun choix valide fourni). Un canal fixe qui n'est NI `counter` NI `drive` (ex. `kiosk`) n'a aucune saisie equipier associee : `403 FORBIDDEN`, message EXPLICITE nommant le canal (pas le rejet generique de `service_mode`/`items` sur lequel la requete aurait sinon echoue plus loin). Le canal retenu (impose ou choisi) DOIT en outre etre dans les sources VISIBLES du role (`role_visible_source`) : un role sans canal fixe mais a visibilite restreinte (ex. `['drive']`) voit son choix `"source": "counter"` refuse en `403 FORBIDDEN` (pas `422`) meme si la forme est valide -- c'est un droit absent, pas une saisie invalide |
| POST | `/admin/api/orders/{number}/ready` | non | etat cuisine paid/preparing -> ready ; meme garde de visibilite PRE-3 que le HTML (403 anti-enumeration comme le GET unitaire) |
| POST | `/admin/api/orders/{number}/deliver` | non | paid/preparing/ready -> delivered ; meme garde PRE-3 |
| POST | `/admin/api/orders/{number}/cancel` | oui | mlt 7.1 ; meme garde de visibilite PRE-3 que le GET unitaire/ready/deliver (403 anti-enumeration, verifiee AVANT le PIN : un numero inconnu et un canal non visible rendent la meme reponse, non distinguables via le PIN) ; puis `422 CANNOT_CANCEL_IN_STATE` (statut terminal) ou `409 INVALID_TRANSITION` (course perdue) ; audit ecrit par `OrderRepository::cancel()` lui-meme. **Limite levee (chantier RBAC canal)** : `OrderAdminController::cancel()` (HTML) applique desormais la MEME garde de visibilite PRE-3, verifiee AVANT le PIN (meme reponse 403 pour un numero inconnu et pour un canal non visible) ; `confirmCancel()` (page de confirmation GET) l'applique aussi, pour ne pas reveler numero/statut/total avant meme le PIN |

Statistiques (`stats.read`) :

| Methode | Chemin | PIN | Note |
|---|---|---|---|
| GET | `/admin/api/stats` | non | compteurs de catalogue + sante du stock (RG-T21) + KPIs de vente, lecture seule |

> **Perimetre des KPIs de vente (relecture adverse, point 6)** : `OrderQueryRepository::salesKpis()`/`salesByDay()` agregent le chiffre d'affaires et le nombre de commandes TOUS CANAUX confondus (kiosk + counter + drive) -- aucun filtre par `role_visible_source` ne s'y applique, contrairement a la liste des commandes (RG-T12). C'est une decision assumee, pas un oubli symetrique a `/admin/orders` : les statistiques sont un tableau de bord GLOBAL (accessible aux seuls roles titulaires de `stats.read` -- `admin`/`manager` au seed 0001, ni `counter`/`drive`/`kitchen`), pense pour une vue d'ensemble du chiffre d'affaires plutot que pour une file de travail par canal. Aucun role a la fois titulaire de `stats.read` ET a canal restreint n'existe au seed 0001 ; si un tel role etait cree via la gestion RBAC, il verrait un total agrege incluant des canaux qu'il ne voit pas ailleurs -- a garder en tete si ce croisement de permissions doit un jour etre autorise.

> **Limite documentee** : l'upload d'image (produit/categorie, multipart) reste HTML
> uniquement — l'API JSON accepte `image_path` deja heberge (chaine), pas de transfert
> binaire.

### 5.3bis Connexion JSON (`/admin/api/auth/*`, livre)

Avant ce chantier, toute la collection Postman (section 5.3) supposait une session deja
ouverte : le seul moyen d'en obtenir une etait de se connecter dans un NAVIGATEUR
(`POST /login`, formulaire HTML) puis de copier le cookie `WAKDO_SID` a la main depuis les
outils de developpement (l'attribut `HttpOnly` empeche toute lecture par
`document.cookie`, voir section 9) — fragile en demonstration (oral, jury). `/admin/api/auth/*`
ferme ce cas SANS changer le modele de securite (ADR-0017) : meme session serveur en cookie
`HttpOnly` + `SameSite=Strict`, meme jeton CSRF synchroniseur (`App\Auth\Csrf`). Aucun jeton
en `localStorage`, aucune cryptographie maison.

| Methode | Chemin | Session prealable | CSRF (`X-CSRF-Token`) | Note |
|---|---|---|---|---|
| POST | `/admin/api/auth/login` | non | non (protection differente, voir plus bas) | Reutilise `AuthService::authenticate()` a l'identique du formulaire HTML (`AuthController::login`) : meme limitation par compte ET par IP (RG-8), meme ralentissement degressif, meme regeneration de session (RG-3), meme controle de compte actif. Succes : `200 { data: { user: {id, email, display_name, role}, permissions: [...], csrf_token } }`, pose le cookie de session. Echecs : `401 INVALID_CREDENTIALS` (identifiants faux, compte inactif/inconnu, OU verrou de COMPTE — anti-enumeration, voir plus bas) ; `429 TOO_MANY_ATTEMPTS` (verrou IP UNIQUEMENT, en-tete `Retry-After` en secondes) ; `400 INVALID_JSON` ; `415 UNSUPPORTED_MEDIA_TYPE` ; `422 VALIDATION_ERROR` (champ manquant, vide, trop long, ou non scalaire) |
| POST | `/admin/api/auth/logout` | oui | oui | `204` sans corps, detruit la session (`AuthService::logout()`, inchange). Session absente -> `401 AUTH_REQUIRED` (a la difference du formulaire HTML, qui ne verifie que le CSRF) |
| GET | `/admin/api/auth/me` | oui | non | Alias STRICT de `GET /admin/me` (meme controleur sous-jacent, `AuthApiController::apiMe()` delegue a `MeController::show()`) — pour que toute la demonstration reste sous le seul prefixe `/admin/api/...`. `/admin/me` reste servi (compatibilite). |

**Pourquoi `401 INVALID_CREDENTIALS` et pas `429 TOO_MANY_ATTEMPTS` sur un verrou de COMPTE.**
`AuthService` distingue deux dimensions de throttling (RG-8) : par COMPTE (email precis) et
par IP source. Exposer un code HTTP different pour "compte verrouille" reviendrait a
REVELER, par le code HTTP seul, qu'un email precis existe et a echoue plusieurs fois — la
meme fuite que le message d'erreur unique evite deja cote formulaire HTML (anti-enumeration,
`mlt.md` RG-2/ERR-3). Le verrou IP, lui, ne depend d'aucun email tente : l'exposer via `429` +
`Retry-After` ne revele rien sur un compte precis — A CONDITION que le chemin "compte
verrouille" fasse par ailleurs EXACTEMENT le meme travail observable que le chemin "email
inconnu" (meme appel a `verifyDecoy()`, meme increment du compteur IP, compteur COMPTE laisse
inchange dans les deux cas). Un premier code de ce chantier ne le faisait pas (le compteur IP
arretait d'avancer sur un compte deja verrouille, RG-8) : un compte verrouille et un email
inconnu redevenaient distinguables — par le nombre de requetes avant le premier `429`
(vingt, le seuil `IP_THROTTLE_MAX_ATTEMPTS`, pour un email inconnu ; pour un compte
verrouille, le compteur IP restait gele, donc ce seuil ne pouvait pas etre atteint par
cette voie), et par le temps de reponse (quelques ms sans le leurre, contre 500+ ms avec).
Voir `AuthService::authenticate()`, bloc `if ($accountLocked)`, et `AuthServiceTest` pour
la preuve testee. Ce paragraphe documente donc une garantie qui depend de ce comportement
precis, pas d'une propriete acquise du seul choix
d'un code HTTP (`App\Auth\AuthResult::throttled()`, `AuthService::authenticate()`).

**Le leurre de timing est calibre sur un hash STOCKE, pas sur la configuration.**
`AuthService` calibre `verifyDecoy()` (le leurre anti-enumeration ci-dessus) sur un hash
argon2id REELLEMENT STOCKE (celui du compte cible s'il existe mais est verrouille, sinon le
premier compte NON ANONYMISE de la base -- `AuthService::referenceHashForDecoy()`, requete
triee par cle primaire et filtree sur `password_hash <> ''` : l'anonymisation RGPD garde la
ligne `user` en y ecrivant un `password_hash` vide (mlt 10.5), et une chaine vide n'est pas
un hash argon2id, donc servir un tel tombstone comme reference rouvrirait exactement l'ecart
decrit ci-dessous) plutot que sur `ARGON2_MEMORY_COST`/`TIME_COST`/`THREADS`
(la configuration courante). C'est le hash stocke qui dicte le cout REEL d'un
`password_verify()` (les parametres argon2id sont encodes DANS le hash lui-meme), pas la
configuration : calibrer sur la configuration seule reste correct tant que rien n'a change
depuis la creation des hashes existants, mais diverge des qu'un deploiement AUGMENTE (ou
diminue) ces parametres pour durcir son installation SANS rehacher l'existant — un exploitant
qui durcit son installation de cette facon rouvrirait alors precisement la fuite que ce
mecanisme sert a fermer (mesure relecture adverse, parametres personnalises 32768/3/1, cache
par ailleurs PARFAITEMENT SAIN, aucune panne : 256 ms pour un mot de passe faux sur un compte
existant, 99 ms pour un leurre calibre sur la configuration — 157 ms d'ecart). **Toute
modification de `ARGON2_MEMORY_COST`/`ARGON2_TIME_COST`/`ARGON2_THREADS` sur un deploiement
existant doit donc s'accompagner d'un rehachage des mots de passe stockes**, sans quoi les deux
couts (celui des hashes existants, celui de la nouvelle configuration) divergent durablement.
`AuthService::authenticate()` amorce cette convergence tout seul, a chaque connexion reussie :
si le hash stocke ne porte plus les parametres courants (`PasswordHasherInterface::
needsRehash()`, enveloppe de `password_needs_rehash()`), il est rehache avec le mot de passe
qui vient d'etre verifie en clair — seuls les comptes qui se REconnectent apres le changement
en beneficient ainsi ; un compte qui ne se reconnecte pas reste a l'ancien cout de son cote
(residu assume, pas un chemin de migration en masse).

**Compromis assume : le verrou IP se partage derriere un NAT de restaurant.** Le seuil
`IP_THROTTLE_MAX_ATTEMPTS` (`.env.example`, defaut 20 tentatives par fenetre de
`IP_THROTTLE_WINDOW_SECONDS`, defaut 900 s soit 15 min — `ThrottlePolicy::fromConfig()`,
dimension `'ip'`) est compte par ADRESSE IP SOURCE, pas par compte ni par poste de travail.
Plusieurs equipiers d'un meme restaurant, derriere le meme routeur (NAT), partagent en
pratique une seule adresse IP source cote serveur : les echecs de connexion d'un equipier
(mot de passe oublie, fautes de frappe repetees) avancent le MEME compteur que celui des
autres postes du meme restaurant, et peuvent, cumules, atteindre le seuil de verrou IP
(`429` + `Retry-After` degressif, RG-8) — ce qui ralentit alors aussi la connexion de
collegues dont les tentatives, elles, etaient correctes. Ce n'est pas un angle mort non vu :
sans ce compteur par IP, un seul poste pourrait essayer un volume non borne de mots de passe
contre un compte non verrouille (`mlt.md` RG-8) ; avec lui, un reseau local partage degrade
l'experience de plusieurs postes legitimes en cas d'echecs repetes sur un seul poste. C'est
pour ajuster ce compromis site par site (un restaurant avec plus de postes derriere le meme
NAT peut relever ce seuil) que `IP_THROTTLE_MAX_ATTEMPTS` est une variable d'environnement et
non une constante — sans necessiter de changement de code pour le recalibrer.

**Pourquoi pas de jeton CSRF classique sur `POST /admin/api/auth/login`.** Le jeton
synchroniseur (section 9) suppose une session PREALABLE pour le porter — hors de portee ici
puisque le but de cette requete est justement de CREER cette session.

**Le mecanisme reel : le Content-Type impose force un PREFLIGHT CORS, ferme sur ce prefixe
[CLAIM L1, WHATWG Fetch Standard §"CORS-preflight fetch" + HTML Living Standard §"form
submission algorithm" pour les valeurs d'enctype].** `JsonApiTrait::requireJsonBody()`
(verifie dans le code) exige `application/json` pour tout corps non vide (`415` sinon). Un
`<form>` HTML ne connait que trois valeurs d'`enctype` — `application/x-www-form-urlencoded`,
`multipart/form-data`, `text/plain` — aucune n'est `application/json` : un `<form>` cross-site
ne peut donc pas, par lui-meme, produire un corps que le serveur accepterait ici. Une page
attaquante devrait alors passer par `fetch()`/`XHR` avec `Content-Type: application/json`, ce
qui declenche un PREFLIGHT `OPTIONS` (Fetch Standard) AVANT que le navigateur n'envoie la
requete reelle. `App\Core\Cors::isAllowed()` (`src/app/Core/Cors.php`, verifie dans le code)
ne pose l'en-tete `Access-Control-Allow-Origin` que sur les chemins commencant par `/api/`
(le prefixe PUBLIC kiosk), pas sur `/admin/api/` : ce preflight echoue donc pour ce prefixe,
et la specification demande au navigateur de ne pas envoyer la requete reelle dans ce cas.
C'est le SEUL mecanisme, parmi ceux discutes ici, qui empeche reellement l'ENVOI de la
requete forgee depuis un navigateur.

**Ce que `SameSite=Strict` fait — et ne fait PAS — ici [CLAIM L3, IETF Internet-Draft
`draft-ietf-httpbis-rfc6265bis-22` (revision -22, la plus recente disponible au moment de
cette consultation le 2026-09-26 ; une revision -23 n'existe pas a cette date) — PAS une RFC
ratifiee, le brouillon dit lui-meme qu'il est "inappropriate to use Internet-Drafts as
reference material or to cite them other than as 'work in progress'" — section 5.6.7.1
"'Strict' and 'Lax' enforcement" et section 5.7 (modele de stockage, etape 18.2.3),
verifiees par lecture directe du texte].** `SessionManager::start()` (verifie dans le code) pose le cookie de
session avec `samesite: 'Strict'`. Cet attribut regit l'ENVOI d'un cookie EXISTANT sur une
requete ulterieure de site croise — PAS la reception ni la pose d'un cookie neuf : le
brouillon precise qu'une navigation de premier niveau peut CREER un cookie avec n'importe
quelle valeur de `SameSite`, "even if the new cookie wouldn't have been sent along with the
request had it already existed prior to the navigation" (section 5.7). Autrement dit,
`SameSite=Strict` ne bloque PAS l'acceptation du `Set-Cookie` renvoye par un
`POST /admin/api/auth/login` qui reussirait a s'executer, force ou non — une version
anterieure de ce document affirmait le contraire ("meme un formulaire cross-site qui
parviendrait a poster ne porterait pas la session a fixer"), corrige ici. Ce que
`SameSite=Strict` protege reellement, c'est la
SUITE : une fois la session ouverte, le cookie `WAKDO_SID` n'est plus ENVOYE par le
navigateur sur les requetes ulterieures initiees depuis un autre site — la raison pour
laquelle chaque ecriture authentifiee de `/admin/api/*` (et `POST /admin/api/auth/logout`)
garde, elle, le jeton CSRF synchroniseur classique.

**Ce que le blocage CORS ne dit pas.** CORS est un mecanisme applique par le NAVIGATEUR sur
du code de PAGE WEB (`fetch`/`XHR`) — pas un pare-feu serveur. Il ne rend pas `/admin/api/*`
injoignable pour un client HTTP quelconque (un `curl`, un script, un autre serveur) : un tel
client n'est pas soumis a la politique CORS et peut envoyer la requete et lire la reponse
sans restriction de ce cote (sous reserve du routage reseau reel, une question distincte de
CORS). Ce que ferme le preflight CORS ci-dessus, c'est UNIQUEMENT le scenario "une page
chargee dans le navigateur de la victime, sur un autre site, force ce navigateur a emettre la
requete" — le scenario CSRF classique — pas un acces direct au serveur par un client qui n'est
pas un navigateur.

`POST /admin/api/auth/logout`, lui, garde le jeton CSRF classique (une session existe deja a
ce moment).

---

## 6. Methodes HTTP

| Methode | Usage |
|---|---|
| GET | lecture, sans effet de bord |
| POST | creation, ou action de formulaire back-office (login, logout, reset) |
| PUT | mise a jour d'une ressource (livre sur `/admin/api/*`, section 5.3) |
| DELETE | suppression (ou desactivation, selon la ressource) d'une ressource (livre sur `/admin/api/*`, section 5.3) |

Le Router fait une correspondance exacte de la methode : methode connue sur chemin connu mais non
enregistree -> `405` ; chemin inconnu -> `404` (`Router::dispatch`). Une requete `HEAD` sur une
route `GET` renvoie aujourd'hui `405` (correspondance exacte) ; un assouplissement reste possible
si un besoin apparait.

---

## 7. Enveloppe de reponse JSON

L'API enveloppe ses reponses pour qu'un client distingue donnees et erreur de maniere uniforme.

Succes - ressource unitaire :

```json
{ "data": { "id": 3, "name": "Big Mac", "price_cents": 590 } }
```

Succes - collection (`total` optionnel pour la pagination future) :

```json
{ "data": [ { "id": 1 }, { "id": 2 } ], "total": 2 }
```

Erreur :

```json
{ "data": null, "error": { "code": "NOT_FOUND", "message": "Resource not found" } }
```

Exception documentee : `GET /api/health` renvoie un objet de diagnostic plat (`status`, `app_env`,
`php_version`, `db`, `categories`), hors enveloppe, car il sert le monitoring et non un client
applicatif.

Type de contenu : `application/json; charset=utf-8` (`Response::json`). Les pages back-office
renvoient `text/html; charset=utf-8`.

---

## 8. Normalisation des noms de champs

### 8.1 Regle generale : snake_case aligne sur le dictionnaire

Les champs JSON reprennent les noms du dictionnaire (`docs/merise/dictionary.md`), source de verite,
ce qui evite une couche de traduction entre base, code et contrat HTTP.

| Categorie | Convention | Exemple |
|---|---|---|
| Champ simple | snake_case, anglais | `display_order`, `image_path` |
| Montant monetaire | entier en centimes, suffixe `_cents` | `price_cents`, `total_ttc_cents` |
| Taux de TVA | entier pour mille | `vat_rate` (55 = 5,5 % ; 100 = 10 %) |
| Booleen | prefixe `is_` | `is_available`, `is_active` |
| Horodatage | suffixe `_at`, ISO 8601 en sortie API | `created_at`, `paid_at` |
| Cle etrangere | suffixe `_id` | `category_id`, `role_id` |
| Valeur d'enumeration | minuscules snake_case | `pending_payment`, `dine_in`, `kiosk` |
| Identifiant | `id` (entier) ou `order_number` (chaine metier) | `id`, `order_number` |

Les horodatages sont stockes en `DATETIME` ; leur exposition API se fait en ISO 8601 (a cadrer
au moment d'ecrire les endpoints de lecture P4).

### 8.2 Codes d'erreur

SCREAMING_SNAKE_CASE, stables (un client peut s'y fier) ; le `message` reste lisible (non garanti
stable).

| Code | HTTP | Sens |
|---|---|---|
| `NOT_FOUND` | 404 | ressource introuvable |
| `METHOD_NOT_ALLOWED` | 405 | methode non autorisee sur ce chemin |
| `VALIDATION_ERROR` | 422 | entree invalide (champ, longueur, enum) ; `error.fields` porte le detail par champ sur `/admin/api/*` |
| `CONFLICT` | 409 | conflit d'etat (ex. transition de commande concurrente) ; suppression dure bloquee par une reference (FK RESTRICT) ; unicite slug/name/code/email deja prise (remontee par la base). La validation simple en amont (champ/format/bornes) reste `VALIDATION_ERROR` 422 |
| `AUTH_REQUIRED` | 401 | authentification requise (session absente/expiree, `/admin/me` et `/admin/api/*`) |
| `INVALID_CREDENTIALS` | 401 | `POST /admin/api/auth/login` : email/mot de passe faux, compte inconnu/inactif, OU verrou de COMPTE (anti-enumeration, section 5.3bis) |
| `TOO_MANY_ATTEMPTS` | 429 | `POST /admin/api/auth/login` : verrou de throttling IP (RG-8, section 5.3bis) ; en-tete `Retry-After` en secondes |
| `FORBIDDEN` | 403 | permission insuffisante |
| `CSRF_INVALID` | 403 | jeton CSRF absent ou invalide (formulaire `_csrf` ou en-tete `X-CSRF-Token` sur `/admin/api/*`) |
| `PIN_INVALID` | 422 | PIN d'action sensible absent, invalide, ou acteur verrouille (RG-T13/RG-T22, `/admin/api/*`) |
| `INVALID_JSON` | 400 | corps de requete JSON malforme, OU dont la racine n'est pas un objet (une liste/un scalaire) (`/admin/api/*`) |
| `UNSUPPORTED_MEDIA_TYPE` | 415 | corps non vide envoye sans en-tete `Content-Type: application/json` (`/admin/api/*`) |
| `RATE_LIMITED` | 429 | throttling generique (prevu, hors connexion — voir `TOO_MANY_ATTEMPTS` pour la connexion, seul cas realise a ce jour) |
| `INTERNAL_ERROR` | 500 | erreur interne, message generique (pas de divulgation) |

Codes specifiques nommes par le MLT, en surcharge du socle : `CANNOT_CANCEL_IN_STATE` (422) et
`INVALID_TRANSITION` (409) pour l'annulation (`mlt.md` 7.1, `security-sequence.md`). Meme format
d'enveloppe.

**`ORDER_CANCELLED` (409, F18).** Rendu par `POST /api/orders` quand la cle
d'idempotence envoyee porte une commande **annulee ou expiree**. La colonne
`idempotency_key` etant UNIQUE, cette cle est definitivement consommee : elle ne peut
plus porter de commande. Sans ce code, un client dont la commande a ete annulee pendant
qu'il hesitait serait bloque — commande impayable, cle interdisant d'en creer une autre.
La borne repart alors d'une cle neuve, **une seule fois** (une reprise sur n'importe
quelle erreur masquerait un vrai probleme, par exemple un article indisponible).

### 8.2bis Ce que garantit la cle d'idempotence (revise par F18)

`POST /api/orders` avec une `idempotency_key` deja connue ne cree pas de seconde commande
(RG-T19). Ce qu'il fait du CONTENU depend de l'etat de la commande portee :

| Etat de la commande | Comportement | Reponse |
|---|---|---|
| `pending_payment` | les lignes sont **remplacees** et les totaux recalcules serveur | 201, meme `order_number`, total a jour |
| encaissee (`paid`/`preparing`/`ready`/`delivered`) | renvoyee telle quelle, aucune ecriture | 201, etat reel |
| `cancelled` | refus : la cle est consommee | 409 `ORDER_CANCELLED` |

La garantie n'est donc pas « meme cle, meme reponse quel que soit le corps » mais
**« meme cle, meme commande, dont le contenu reflete la derniere soumission »**. Le
changement est assume : il permet au client de modifier son panier avant de payer sans
qu'une seconde commande soit creee et abandonnee. Detail et mesures de concurrence :
[ADR-0016](../adr/0016-modification-commande-avant-paiement.md), `mlt.md` 3.3bis.

### 8.3 Nommage borne vs canonique : le rapprochement dans data.js

Le front de la borne attend un nommage historique heterogene issu des sources de l'ecole
(`title`/`nom`, `prix`, `image`, `type`). L'API sert la forme canonique de 8.1
(`/api/categories`, `/api/products`, `/api/menus`, `/api/allergens`). Le rapprochement se fait
en un point unique : la couche `data.js`, qui deballe l'enveloppe `{ data }` et mappe la forme
canonique vers ce que la borne attend. Les anciens fichiers JSON statiques sous
`src/public/borne/data/` ont ete retires.

| Forme borne | Canonique API / dictionnaire |
|---|---|
| `title` (categorie) | `name` |
| `nom` (produit) | `name` |
| `prix` | `price_cents` |
| `image` | `image_path` |
| `type` | `item_type` (`product` / `menu`) |
| `allergenes` | `allergens` (liste `{id, code, name}` calculee depuis la recette) |
| `allergenesComplets` | `allergens_complete` |

**Allergenes (F11b).** `/api/products`, `/api/products/{id}`, `/api/menus` et
`/api/menus/{id}` portent deux champs : `allergens`, la liste CALCULEE depuis la recette
(`product_ingredient` -> `ingredient_allergen` -> `allergen`, dedupliquee cote SQL), et
`allergens_complete`, faux des qu'un ingredient de la recette n'a pas ete revu. Les deux
sont indissociables : une liste vide avec `allergens_complete: true` affirme l'absence,
la meme liste vide avec `false` veut dire "non verifie". Cote borne, `data.js` applique un
defaut PRUDENT — une reponse sans le drapeau vaut `false`, pas `true`. Sur un menu la
liste est celle du burger impose (meme granularite que `is_orderable`).
`/api/allergens` conserve son role : les 14 categories INCO avec leur **description**
reglementaire, utilisee pour expliquer chaque allergene du produit.
Voir [ADR-0015](../adr/0015-allergenes-calcules-par-produit.md).

---

## 9. Authentification et sessions

- **Cookie de session** : `WAKDO_SID` (`SESSION_NAME`), attributs `secure`, `HttpOnly`,
  `SameSite=Strict`. Bornes de validite appliquees cote application (idle 4h, absolue 10h),
  pas par la duree du cookie.
- **Formulaires back-office** : jeton CSRF synchroniseur en champ cache `_csrf`, verifie sur chaque
  POST (`/login`, `/logout`, `/forgot_password`, `/reset_password`, et chaque ecriture
  `/admin/*`). Jeton invalide -> `403`.
- **API REST publique (`/api/*`)** : endpoints kiosk de lecture catalogue et creation de
  commande, publics (pas de session ; `mlt.md` CREATE_ORDER).
- **API d'administration JSON (`/admin/api/*`, section 5.3)** : session admin + verification
  de permission via `role_permission` + jeton CSRF en en-tete `X-CSRF-Token` (le meme jeton
  synchroniseur que le HTML, transporte differemment faute de formulaire) ; actions sensibles
  avec re-autorisation PIN (`mlt.md` RG-T13) dans le corps JSON.
- **Connexion JSON (`/admin/api/auth/*`, section 5.3bis)** : `POST .../login` OUVRE la
  session JSON (pose le cookie, sans jeton CSRF prealable — protection Content-Type/CORS/
  SameSite, detaillee en 5.3bis) ; `.../logout` et `.../me` suivent ensuite la meme regle que
  le reste de `/admin/api/*` ci-dessus.

Le schema `ApiKey` / `Bearer` de l'API plateforme BYAN (`docs/api/byan-api.md`) ne s'applique pas
ici.

---

## 10. CORS

La borne consomme `/api/*` en **meme origine** : le vhost kiosk (`docker/apache/vhost.conf`)
relaie `/api/*` au front controller admin via PHP-FPM (`ProxyPassMatch` + `ProxyFCGISetEnvIf`
qui force `SCRIPT_FILENAME` sur `public/admin/index.php`). `data.js` garde donc des URLs
relatives et le navigateur n'emet pas de requete cross-origin pour ce parcours.

Le middleware `App\Core\Cors` reste en place comme defense en profondeur : il lit
`CORS_ALLOWED_ORIGIN` (valeur exacte, sans joker, = `APP_URL_KIOSK`) et autorise un eventuel
consommateur cross-origin de l'API. Il n'est pas sur le chemin de la borne.

---

## 11. Versionnement

Demarrage sans segment de version (`/api/...`), ce qui correspond a une v1 implicite. En cas de
changement de contrat non retrocompatible, l'option retenue est un prefixe explicite `/api/v2/...`
introduit a ce moment-la, en gardant `/api/...` pour la v1 tant que des clients en dependent.

---

## 12. Ou est defini quoi (recap code)

| Element | Fichier |
|---|---|
| Declaration des routes | `src/public/admin/index.php` |
| Resolution / 404 / 405 | `src/app/Core/Router.php` |
| Enveloppe `data` / `error` / contenu JSON | `src/app/Core/Response.php` |
| Lecture de la requete (chemin, query, corps, IP) | `src/app/Core/Request.php` |
| Controleurs (HTML) | `src/app/Controllers/` |
| Controleurs (API d'administration JSON, section 5.3) | `src/app/Controllers/Admin/Api/` (etendent leur homologue HTML) |
| Connexion JSON (section 5.3bis) | `src/app/Controllers/Admin/Api/AuthApiController.php` (etend `MeController`) |
| Regles de securite de l'authentification (throttling, anti-enumeration) | `src/app/Auth/AuthService.php`, `src/app/Auth/AuthResult.php` |
| Garde JSON commune (401/403/CSRF/PIN/enveloppe) | `src/app/Controllers/Admin/Api/JsonApiTrait.php` |
| Porte du PIN d'action sensible pour l'API JSON | `src/app/Auth/PinGate.php` |
| Acces base (requetes preparees, transaction) | `src/app/Core/Database.php` |
| Noms de champs (source de verite) | `docs/merise/dictionary.md` |
| Operations metier et permissions | `docs/merise/mct.md`, `mlt.md`, `db/seeds/0001_rbac_and_reference.sql` |
| Collection Postman + environnement | `docs/api/wakdo-admin.postman_collection.json`, `docs/api/wakdo.postman_environment.json` (generees par `scripts/gen_postman.py`) |
| Collection Bruno + environnement | `docs/api/bruno/` (generee par `scripts/gen_bruno.py`) |
| Guide de demo (5 minutes, Postman ET Bruno) | `docs/api/demo-api.md` |
| Decision d'architecture | [ADR-0017](../adr/0017-api-admin-json.md) |
