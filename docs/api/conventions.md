# API Wakdo - conventions de nommage, structure et listing

**Statut** : v0.2 - convention de casse arbitree (snake_case, voir section 4)
**Perimetre** : back-office admin (rendu serveur) + API REST sous `/api/*`
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

## 3. Deux familles d'endpoints

| Famille | Prefixe | Rendu | Authentification | Exemple |
|---|---|---|---|---|
| Pages back-office | aucun | HTML (vue serveur + `layout.php`) | session admin | `/login`, `/forgot_password` |
| API REST | `/api/` | JSON (enveloppe section 7) | selon la ressource (section 10) | `/api/health`, `/api/categories` (prevu) |

La borne (kiosk) consommera l'API REST `/api/*` (P4). En attendant, elle lit un repli JSON
statique sous `src/public/borne/data/` (voir section 8.3).

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
| GET | `/api/me` | session | JSON | identite + permissions du compte courant (RG-6/RG-T02/RG-T03) |

`/api/me` est le premier consommateur reel de `SessionGuard` (RG-6 idle/absolu + RG-T02
is_active) et d'`Authorizer` (RG-T03, permissions rechargees depuis la base). Reponse :
`{ "data": { "user_id", "role_id", "role_code", "permissions": [...] } }` ; `401 AUTH_REQUIRED`
si la session est absente, expiree ou le compte desactive. Les autorisations par operation
(et le PIN des actions sensibles, RG-T13) se cablent quand les operations existent (P3).

### 5.2 API kiosk - lecture catalogue + commande (prevu P4, public)

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
| GET | `/api/orders/{number}` | (lecture publique) | (suivi statut) | livre (champs non sensibles : numero, statut, total) |

### 5.3 API / pages back-office (prevu P3-P4, session + permission)

Provisoire : le choix entre endpoints JSON `/api/*` et pages rendues serveur pour les ecritures
admin est tranche phase par phase (P3 CRUD). Les colonnes Permission renvoient au catalogue fige
des 23 permissions (`db/seeds/0001_rbac_and_reference.sql`) ; l'imputabilite et le PIN suivent
`mlt.md` RG-T13/RG-T14.

Commandes (cote equipier) :

| Methode | Chemin | Permission | Op MCT | Note |
|---|---|---|---|---|
| GET | `/api/orders` | `order.read` | READ_ORDERS | filtre par `role_visible_source` (RG-T12) |
| GET | `/api/orders/{number}` | `order.read` | READ_ORDERS | vue back-office detaillee (differe) ; le suivi public minimal est livre en 5.2 |
| POST | `/api/orders` (comptoir/drive) | `order.create` | CREATE_COUNTER_ORDER (mlt 4.1) | source auto-taggee |
| POST | `/api/orders/{id}/deliver` | `order.deliver` | DELIVER_ORDER (mlt 6.1) | |
| POST | `/api/orders/{id}/cancel` | `order.cancel` | CANCEL_ORDER (mlt 7.1) | PIN + audit_log (RG-T13/14) |

Catalogue (produits, menus, categories) :

| Methode | Chemin | Permission | Op MCT |
|---|---|---|---|
| POST | `/api/products` | `product.create` | CREATE_PRODUCT (mlt 8.1) |
| PUT | `/api/products/{id}` | `product.update` | UPDATE_PRODUCT (mlt 8.2) - PIN sur prix/TVA |
| DELETE | `/api/products/{id}` | `product.delete` | DELETE_PRODUCT (mlt 8.3) - PIN |
| POST | `/api/menus` | `menu.create` | CREATE_MENU |
| PUT | `/api/menus/{id}` | `menu.update` | UPDATE_MENU |
| DELETE | `/api/menus/{id}` | `menu.delete` | DELETE_MENU - PIN |
| POST/PUT/DELETE | `/api/categories[/{id}]` | `category.manage` | MANAGE_CATEGORY |

Stock et ingredients :

| Methode | Chemin | Permission | Op MCT |
|---|---|---|---|
| GET | `/api/ingredients` | `ingredient.manage` | READ_INGREDIENTS |
| GET | `/api/stock` | `stock.read` | READ_STOCK |
| POST | `/api/stock/restock` | `stock.manage` | RESTOCK (mlt 9.1) |
| POST | `/api/stock/count` | `stock.count` | INVENTORY_COUNT (mlt 9.2) - PIN |

Utilisateurs et RBAC :

| Methode | Chemin | Permission | Op MCT |
|---|---|---|---|
| GET | `/api/users` | `user.read` | READ_USERS |
| POST | `/api/users` | `user.create` | CREATE_USER (mlt 10.1) - PIN |
| PUT | `/api/users/{id}` | `user.update` | UPDATE_USER (mlt 10.2) - PIN |
| POST | `/api/users/{id}/deactivate` | `user.deactivate` | DEACTIVATE_USER (mlt 10.3) - PIN |
| GET/PUT | `/api/roles[/{id}/permissions]` | `role.manage` | MANAGE_RBAC (mlt 10.4) - PIN |

Statistiques :

| Methode | Chemin | Permission | Op MCT |
|---|---|---|---|
| GET | `/api/stats` | `stats.read` | READ_STATS (mlt 11.x) |

> Les chemins exacts en 5.2/5.3 sont une projection a partir des operations MCT et des permissions
> seedees ; ils sont confirmes au moment d'ecrire chaque endpoint. Seule la section 5.1 est en service.

---

## 6. Methodes HTTP

| Methode | Usage |
|---|---|
| GET | lecture, sans effet de bord |
| POST | creation, ou action de formulaire back-office (login, logout, reset) |
| PUT | mise a jour d'une ressource (prevu, CRUD admin P3) |
| DELETE | suppression d'une ressource (prevu) |

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
| `VALIDATION_ERROR` | 422 | entree invalide (champ, longueur, enum) |
| `CONFLICT` | 409 | conflit d'etat (ex. transition de commande concurrente) ; suppression dure bloquee par une reference (FK RESTRICT) ; unicite slug/name deja prise (remontee par la base). La validation simple en amont (champ/format/bornes) reste `VALIDATION_ERROR` 422 |
| `AUTH_REQUIRED` | 401 | authentification requise (prevu, API admin) |
| `FORBIDDEN` | 403 | permission insuffisante, ou jeton CSRF invalide cote formulaire |
| `RATE_LIMITED` | 429 | throttling (prevu) |
| `INTERNAL_ERROR` | 500 | erreur interne, message generique (pas de divulgation) |

Codes specifiques nommes par le MLT, en surcharge du socle : `CANNOT_CANCEL_IN_STATE` (422) et
`INVALID_TRANSITION` (409) pour l'annulation (`mlt.md` 7.1, `security-sequence.md`). Meme format
d'enveloppe.

### 8.3 Divergence connue : repli JSON de la borne

Le repli statique de la borne (`src/public/borne/data/categories.json`, `produits.json`) provient
des sources de l'ecole et porte un nommage different et heterogene (`title`/`nom`, `prix`, `image`,
`type`). Ce contrat est fige par le brief ecole et consomme tel quel par le JS de la borne via
`data.js`.

La convention canonique reste celle de 8.1. Le rapprochement se fait en un point unique : la couche
`data.js` (bascule prevue en P4). Quand l'API exposera `/api/categories` et `/api/products`, elle
servira la forme canonique ; `data.js` mappera vers ce que la borne attend.

| Repli borne | Canonique API / dictionnaire |
|---|---|
| `title` (categorie) | `name` |
| `nom` (produit) | `name` |
| `prix` | `price_cents` |
| `image` | `image_path` |
| `type` | `item_type` (`product` / `menu`) |

---

## 9. Authentification et sessions

- **Cookie de session** : `WAKDO_SID` (`SESSION_NAME`), attributs `secure`, `HttpOnly`,
  `SameSite=Strict`. Bornes de validite appliquees cote application (idle 4h, absolue 10h),
  pas par la duree du cookie.
- **Formulaires back-office** : jeton CSRF synchroniseur en champ cache `_csrf`, verifie sur chaque
  POST (`/login`, `/logout`, `/forgot_password`, `/reset_password`). Jeton invalide -> `403`.
- **API REST** : endpoints kiosk de lecture catalogue et creation de commande publics (pas de
  session ; `mlt.md` CREATE_ORDER). Endpoints d'administration sous `/api` (P3/P4) : session admin +
  verification de permission via `role_permission` ; actions sensibles avec re-autorisation PIN
  (`mlt.md` RG-T13).

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
| Controleurs | `src/app/Controllers/` |
| Acces base (requetes preparees, transaction) | `src/app/Core/Database.php` |
| Noms de champs (source de verite) | `docs/merise/dictionary.md` |
| Operations metier et permissions | `docs/merise/mct.md`, `mlt.md`, `db/seeds/0001_rbac_and_reference.sql` |
