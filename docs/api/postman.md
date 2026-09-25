# Tester l'API d'administration avec Postman

L'API d'administration JSON (`/admin/api/*`, [docs/api/conventions.md](conventions.md)
section 5.3) reutilise la session du back-office HTML : il n'existe pas d'endpoint JSON
de login (`POST /login` reste une page HTML, cf. ADR-0002). On se connecte donc une fois
dans un navigateur, on recupere le cookie de session, puis on l'utilise dans Postman.

## 1. Importer la collection et l'environnement

- Collection : `docs/api/wakdo-admin.postman_collection.json`
- Environnement : `docs/api/wakdo.postman_environment.json`

Dans Postman : **Import** -> selectionner les deux fichiers -> choisir l'environnement
"Wakdo admin API" en haut a droite. Aucun secret n'est present dans ces fichiers : `sid`,
`csrf` et `pin` sont vides par defaut, a completer localement (etapes 2 et 3).

Ajustez `baseUrl` si votre instance n'ecoute pas sur `http://localhost:8080` (variable
d'environnement).

## 2. Se connecter dans le navigateur et copier le cookie de session

1. Ouvrez `{{baseUrl}}/login` dans votre navigateur et connectez-vous avec le compte de
   demo du seed (email + mot de passe DEV en clair dans le commentaire de
   `db/seeds/0001_rbac_and_reference.sql`, section "bootstrap administrator" — pas
   recopie ici pour ne pas dupliquer un identifiant de demonstration dans un fichier
   distinct).
2. Ouvrez les outils de developpement du navigateur -> onglet **Application** (Chrome) ou
   **Stockage** (Firefox) -> **Cookies** -> selectionnez le domaine de l'application.
3. Le cookie `WAKDO_SID` est marque `HttpOnly` : il n'est pas lisible par
   `document.cookie` en JavaScript (protection XSS voulue, section 9 de
   `conventions.md`), donc il doit etre copie depuis cet onglet, pas depuis la console.
4. Copiez sa **valeur** (pas son nom) dans la variable d'environnement Postman `sid`.

## 3. Recuperer le jeton CSRF (`GET /admin/me`)

Executez la requete **Authentification > GET identite courante + jeton CSRF**. Son script
de test lit `data.csrf_token` dans la reponse et le range automatiquement dans la variable
d'environnement `csrf` : les requetes suivantes (POST/PUT/DELETE) l'envoient telles quelles
via l'en-tete `X-CSRF-Token`, sans autre manipulation.

Ce jeton est SYNCHRONISEUR (`App\Auth\Csrf`) : il ne change qu'a la regeneration de session
(reconnexion), pas a une simple lecture de `/admin/me`. Vous pouvez donc enchainer toutes
les requetes de la collection sans le rafraichir, tant que la session (`sid`) reste valide
(idle 4h / absolue 10h, section 9).

Si une requete renvoie `403 CSRF_INVALID` : la session a ete regeneree entre-temps (nouvelle
connexion, cookie change) — relancez l'etape 2 puis l'etape 3.

## 4. Definir un PIN pour tester les actions sensibles

Les actions marquees **PIN** dans `conventions.md` (section 5.3) exigent
`pin_email` + `pin` dans le corps JSON (modele "identifiant equipier + PIN", RG-T13). Le
compte de demo du seed n'a pas de PIN defini par defaut (`pin_hash` = `NULL`). La
DEFINITION du PIN par son propre titulaire (self-service) est une action distincte des
actions PIN-gated de l'API JSON : reinitialiser le PIN d'un AUTRE compte, elle, EST
exposee (`POST /admin/api/users/{id}/reset-pin`), mais la definition initiale par
soi-meme reste une page HTML uniquement (`/admin/profile/pin`), sans equivalent JSON.
Pour la definir :

1. Dans le navigateur, allez sur `{{baseUrl}}/admin/profile/pin`.
2. Renseignez votre mot de passe actuel et un nouveau PIN (4 a 12 chiffres).
3. Dans Postman, renseignez les variables d'environnement `pin_email` (l'email du compte,
   deja pre-rempli avec le compte de demo) et `pin` (le PIN que vous venez de definir).

Les requetes de la collection qui exigent un PIN utilisent deja `{{pin_email}}` / `{{pin}}`.

## 5. Enchainer les requetes CRUD

Les dossiers Categories, Produits, Menus, Ingredients et Utilisateurs suivent le meme
ordre : lister -> lire un -> creer -> modifier -> supprimer (ou desactiver, selon la
ressource — cf. section 5.3 de `conventions.md`). Deux dossiers font exception, sans
suppression :
- **Roles** : lister -> lire un -> creer -> modifier. Aucune requete "Supprimer" —
  `RoleController` (HTML) n'expose aucune suppression de role (rattache a des comptes),
  l'API JSON ne l'invente pas non plus.
- **Commandes** : pas un CRUD classique. Lister (filtre par canal) -> lire une -> saisir
  (comptoir/drive) -> marquer prete -> remettre -> annuler (PIN). Pas de "modifier" ni de
  "supprimer" : une commande passe par des transitions d'etat, pas une mise a jour libre.
- **Statistiques** : lecture seule (une seule requete, le tableau de bord).

Le script de test des requetes "Creer ..." range automatiquement l'id (ou le numero de
commande) cree dans la variable d'environnement correspondante (`categoryId`,
`productId`, ..., `orderNumber`), reutilisee par les requetes suivantes du meme dossier.

Certains corps de requete (ex. `category_id` d'un produit, `burger_product_id` d'un menu)
referencent des ids fixes (`1`, `2`) qui supposent le seed de demonstration
(`db/seeds/*.sql`) ; adaptez-les a vos donnees si vous testez sur une autre base.

## 6. Lire les erreurs

Toute reponse suit l'enveloppe `{ "data": ... }` ou `{ "data": null, "error": { "code",
"message" } }` (section 7 de `conventions.md`). Les codes utiles en test manuel :

| Code | HTTP | Cause probable en Postman |
|---|---|---|
| `AUTH_REQUIRED` | 401 | `sid` absent, perime, ou copie sans valeur |
| `FORBIDDEN` | 403 | le compte utilise n'a pas la permission requise |
| `CSRF_INVALID` | 403 | `csrf` perime ou vide (relancer l'etape 3) |
| `PIN_INVALID` | 422 | `pin_email`/`pin` absents, faux, ou compte verrouille (RG-T22, reessayer plus tard) |
| `VALIDATION_ERROR` | 422 | champ manquant/invalide, detail dans `error.fields` |
| `CONFLICT` | 409 | doublon (slug/email/code) ou suppression bloquee par une reference |
| `NOT_FOUND` | 404 | id absent en base (variable d'environnement pas encore renseignee ?) |

## Ce que couvre la collection

Neuf dossiers, dans l'ordre ou les enchainer : Authentification (etape 3), Categories,
Produits (dont recette et rangement), Menus, Ingredients (dont seuils, inventaire,
ajustement, allergenes), Utilisateurs (dont reinitialisation de PIN et anonymisation RGPD),
Roles (RBAC), Commandes (liste filtree par canal, saisie comptoir/drive, cuisine, remise,
annulation) et Statistiques. Le contrat complet, methode par methode, est dans
`docs/api/conventions.md` section 5.3.

## Limites connues (section 5.3 de conventions.md)

Ne sont pas couverts par cette API JSON : l'upload d'image (multipart, HTML uniquement —
l'API accepte `image_path` deja heberge) et la suppression de role (le back-office HTML
n'en propose pas non plus, un role etant rattache a des comptes).
