# Demo de l'API d'administration en 5 minutes (Postman ou Bruno)

L'API d'administration JSON (`/admin/api/*`, [docs/api/conventions.md](conventions.md)
section 5.3) se demontre desormais de bout en bout SANS navigateur : la connexion se fait
par `POST /admin/api/auth/login` (section 5.3bis), qui pose le cookie de session et renvoie
le jeton CSRF dans le corps de sa reponse — plus besoin d'ouvrir les outils de developpement
pour copier un cookie a la main.

Deux outils sont couverts, memes fichiers sources, deux rendus : **Postman**
(`docs/api/wakdo-admin.postman_collection.json` + `docs/api/wakdo.postman_environment.json`,
generes par `scripts/gen_postman.py`) et **Bruno** (`docs/api/bruno/`, genere par
`scripts/gen_bruno.py`, natif — un fichier `.bru` par requete, versionnable comme du code).

## 0. Choisir son outil

| | Postman | Bruno |
|---|---|---|
| Fichier a importer | `docs/api/wakdo-admin.postman_collection.json` + `docs/api/wakdo.postman_environment.json` | Ouvrir le DOSSIER `docs/api/bruno/` comme collection |
| Execution en ligne de commande | `newman` (npm) | `bru run` (`@usebruno/cli`, npm) |
| Ou vit la collection | Un seul fichier JSON (genere) | Un fichier `.bru` par requete (genere) |

Les deux suivent le meme scenario ci-dessous (memes noms de dossiers/requetes, memes
variables d'environnement) : la traduction entre les deux syntaxes de script est generee
depuis une source commune (`scripts/gen_postman.py`/`scripts/gen_bruno.py`), pas recopiee a
la main — un ecart entre les deux resterait possible sur un cas non couvert par cette
generation, mais aucun n'est connu au moment d'ecrire ceci (verifie par l'execution reelle
des deux, section 6).

## 1. Importer

**Postman** : **Import** -> selectionner les deux fichiers JSON -> choisir l'environnement
"Wakdo admin API" en haut a droite.

**Bruno** : **Open Collection** -> selectionner le dossier `docs/api/bruno/` -> selectionner
l'environnement "wakdo" (menu des environnements, en haut a droite).

Aucun secret n'est present dans ces fichiers : `email`, `password`, `csrf` et `pin` sont vides
par defaut (idem pour les variables `email_manager`/`password_manager`/... du dossier RBAC,
section 5 ci-dessous), non versionnes en clair — a completer localement. Ajustez `baseUrl` si
votre instance n'ecoute pas sur `http://localhost:8080`.

## 2. Renseigner l'environnement puis se connecter

1. Dans l'environnement, renseignez `email` et `password` avec le compte de demo du seed
   (cf. `db/seeds/0001_rbac_and_reference.sql`, section "bootstrap administrator" — pas
   recopie ici pour ne pas dupliquer un identifiant de demonstration dans un fichier distinct).
2. Executez **0. Connexion > Se connecter**. Reponse `200` :
   `{ data: { user: {id, email, display_name, role}, permissions: [...], csrf_token } }`.
   Le script de test de la requete range `csrf_token` dans la variable d'environnement
   `csrf`, reutilisee par toutes les requetes suivantes via l'en-tete `X-CSRF-Token`, et pose
   `run` (horodatage court, section 4).
3. Le cookie de session (`WAKDO_SID`) n'est pas a copier a la main : l'outil le garde tout
   seul dans son propre pot a cookies, par domaine, et le renvoie automatiquement sur les
   requetes suivantes vers `{{baseUrl}}`.

### Le pot a cookies, verifie (pas suppose)

**Postman n'implemente pas l'attribut `SameSite`** — vérifié dans la doc officielle
(`learning.postman.com/docs/sending-requests/cookies/`, page "Cookies") : *"Postman doesn't
support the `SameSite` attribute, or the `__Secure-` et `__Host-` prefixes."* [CLAIM L2,
doc produit officielle]. Consequence : un cookie `SameSite=Strict` (notre cas) n'empeche pas
Postman de le reutiliser — cette restriction n'a de sens qu'entre origines dans un
NAVIGATEUR, pas dans un client API. La meme page precise aussi que `HttpOnly` "doesn't have
an effect on Postman's behavior" (Postman peut stocker/rejouer un cookie `HttpOnly`, la
restriction ne vise que `document.cookie` en JavaScript navigateur) et que `Secure`
"restricts sending cookies to `https://` connections only" — sur `http://localhost:8080`
(par defaut), notre cookie n'est justement pas marque `Secure` (`SessionManager::cookieSecure()`
ne le pose que derriere une vraie connexion HTTPS), donc pas de probleme ici ; si vous pointez
`baseUrl` sur un deploiement HTTPS, le cookie sera `Secure` ET la connexion sera elle-meme en
HTTPS — coherent.

**Bruno stocke et rejoue automatiquement les cookies recus par `Set-Cookie`**, cote CLI ET
cote application desktop — verifie dans la doc officielle
(`docs.usebruno.com/send-requests/res-data-cookies/cookies.md`) : *"Bruno automatically
saves and forwards cookies between requests"*, avec un drapeau `--disable-cookies` pour
`bru run` pour desactiver ce comportement [CLAIM L2, doc produit officielle]. Le detail de
son interaction avec `Secure`/`SameSite=Strict` specifiquement n'est pas ecrit noir sur blanc
dans cette page : **hypothese** (non contredite par l'execution reelle constatee pendant ce
chantier, section 6 ci-dessous) plutot qu'une garantie documentee.

**Repli documente, dans les deux cas** : si votre version de l'outil ne rejoue pas le cookie
automatiquement (comportement different d'une version future, proxy d'entreprise, etc.), la
variable d'environnement `csrf` reste utilisable independamment, mais la SESSION, elle,
necessiterait alors de reintroduire un en-tete `Cookie: WAKDO_SID=<valeur>` manuel — solution
degradee, retiree de ce guide car elle ne devrait pas etre necessaire dans l'usage normal.

## 3. Definir un PIN pour tester les actions sensibles

Les actions marquees **PIN** dans `conventions.md` (section 5.3) exigent `pin_email` + `pin`
dans le corps JSON (modele "identifiant equipier + PIN", RG-T13). La DEFINITION initiale du
PIN par son propre titulaire reste une page HTML uniquement (`/admin/profile/pin`, sans
equivalent JSON) :

1. Dans un navigateur, allez sur `{{baseUrl}}/admin/profile/pin`.
2. Renseignez votre mot de passe actuel et un nouveau PIN (4 a 12 chiffres).
3. Renseignez les variables d'environnement `pin_email` (deja pre-rempli avec `{{email}}`)
   et `pin` (le PIN que vous venez de definir).

## 4. Enchainer les requetes CRUD (rejouable, deux fois de suite ou plus)

Meme ordre que la collection : Categories, Produits, Menus, Ingredients, Roles (RBAC),
Utilisateurs (lister -> lire un -> creer -> modifier -> relire -> supprimer/desactiver ->
relire), Commandes (lister -> creer -> lire -> preparer -> remettre -> creer une seconde ->
annuler), Statistiques (lecture seule). Detail complet, methode par methode :
`conventions.md` section 5.3.

**Rejouable sans collision.** Chaque requete "Creer ..." nomme sa ressource avec le suffixe
`{{run}}` (horodatage court, pose par le script de test de "Se connecter" a chaque
execution) : `Demo {{run}}`, `demo-{{run}}`, `demo_role_{{run}}`,
`equipier-{{run}}@wakdo.local`. Deux executions consecutives, sur la meme base, ne se
heurtent donc pas a un `409 CONFLICT` de nom/slug/code deja pris.

**Aucun id ecrit en dur pour une ressource CREE par cette collection.** L'id (ou le numero de
commande) cree par chaque requete "Creer ..." est range par son script de test dans une
variable d'environnement `created_<ressource>_id` (`created_category_id`,
`created_product_id`, `created_menu_id`, `created_ingredient_id`,
`created_restock_ingredient_id`, `created_role_id`, `created_user_id`,
`created_order_number`, `created_order_number_cancel`), reutilisee par les GET/PUT/DELETE
suivants dans le meme dossier. Le corps de "Creer un menu" recupere aussi dynamiquement
(par un `GET` prealable, pas un id suppose) la categorie "menus", un produit DE BASE de
la categorie "burgers" et un produit DE BASE de la categorie "boissons" pour le slot
"Boisson" — un id de produit ecrit en dur s'est deja revele faux une fois pendant ce
chantier (le produit d'id 2 du seed est un burger, pas une boisson : le slot le refusait
systematiquement, 422). Un id de reference STABLE et FIGE au seed (le catalogue de 23
permissions, un ingredient de base pour une recette) reste, lui, un litteral documente : ce
n'est pas la meme categorie de donnee qu'un id de ressource creee par la collection.

**Chaque dossier CRUD supprime ou desactive ce qu'il a cree**, selon la semantique reelle de
chaque ressource (verifiee dans le code, pas supposee) : Categories (`DELETE` = desactivation,
`is_active=false`, pas de suppression dure) ; Produits et Menus (`DELETE` = suppression dure
reelle, verifiee par une relecture en 404 apres coup) ; Ingredients — DEUX par execution,
volontairement : un premier sans aucun mouvement de stock (suppression dure reelle possible),
un second qui passe par restock/inventaire/ajustement (qui posent des lignes
`stock_movement`, bloquant toute suppression dure — `409 CONFLICT`, comportement voulu, cf.
`conventions.md` section 5.3 — donc desactive plutot que supprime) ; Utilisateurs
(desactivation puis anonymisation RGPD, tombstone) ; Roles (aucun endpoint `DELETE`
n'existe — limite documentee de longue date — donc desactive via `PUT is_active:false`).

**Ce que ce nettoyage ne couvre pas : le dossier Commandes.** Voir la section "Effets de
bord" ci-dessous — les commandes qu'il cree sont reellement encaissees.

## 5. RBAC : preuve des droits (par poste)

Le dossier **RBAC : preuve des droits** contient un sous-dossier par poste (Manager,
Cuisine, Comptoir, Drive), chacun avec sa PROPRE connexion (`email_<poste>`/`password_<poste>`,
vides par defaut — a completer depuis `docs/demo/comptes-demo.md`, le seed de comptes de
demo par poste) puis 1-2 requetes qui prouvent la matrice de droits reelle du seed
(`db/seeds/0001_rbac_and_reference.sql`, section `role_permission`, pas une supposition) :

| Poste | Autorise (exemple) | Refuse (exemple, `403 FORBIDDEN`) |
|---|---|---|
| Manager | `GET /admin/api/stats` (`stats.read`) | `POST /admin/api/orders/{n}/cancel` (aucun `order.*`, decision D5) |
| Cuisine | `GET /admin/api/orders` (`order.read`) | `GET /admin/api/users` (pas `user.read`) |
| Comptoir | `POST /admin/api/orders` avec `items: []` (`422`, permission `order.create` accordee AVANT la validation) | `DELETE /admin/api/products/{id}` (pas `product.delete`) |
| Drive | Meme preuve non encaissee que Comptoir (`422`) | `DELETE /admin/api/products/{id}` (pas `product.delete`, meme ensemble de droits que Comptoir par construction du seed) |

Comptoir et Drive prouvent `order.create` sans creer de commande reelle : `{"items": []}`
declenche une erreur de VALIDATION (`422`, code `VALIDATION_ERROR`, avec `error.fields.items`
renseigne) APRES la verification de permission — l'atteindre (422 avec ce champ precis, pas
403) suffit a prouver que la permission est accordee, sans les effets de bord d'une commande
reellement encaissee (voir "Effets de bord" ci-dessous). Un role SANS `order.create` recoit,
lui, `403 FORBIDDEN` sur la MEME requete (verifie par
`OrderApiControllerTest::testStoreWithoutPermissionReturns403EvenWithEmptyItems`) : la
verification de permission passe strictement AVANT la validation du corps, donc les deux
codes restent distincts. Manager et Cuisine, eux, prouvent leur droit "Autorise" par une
LECTURE simple (`GET`), deja sans effet de bord.

Chaque requete de ce dossier porte son propre script de test (`pm.test`/`tests{}` selon
l'outil) qui verifie le code HTTP attendu : Newman et `bru run` prouvent donc la matrice
automatiquement (voir section 6). Ce dossier utilise volontairement des ids/numeros fixes
(`1`, `K1`) plutot que les variables capturees par les dossiers precedents : il peut se rejouer
seul, sans dependre de l'ordre d'execution du reste de la collection.

## 6. Executer en ligne de commande (CI, preuve automatisee)

**Postman (Newman)** : Newman ne reecrit PAS le fichier d'environnement source par defaut
[CLAIM L2, doc officielle Newman, options en ligne de commande, consultee le 2026-09-26] :
les variables posees par un script ne sont ecrites sur disque que si `--export-environment
<path>` est fourni explicitement ("The path to the file where Newman will output the final
environment variables file before completing a run") ; sans ce drapeau, absent des commandes
ci-dessous, les changements restent en memoire pour la duree du run puis sont perdus. Le
lancer directement sur `docs/api/wakdo.postman_environment.json` est donc sans risque pour ce
fichier commis.

```bash
npx --yes newman run docs/api/wakdo-admin.postman_collection.json \
  -e docs/api/wakdo.postman_environment.json \
  --env-var email=admin@wakdo.local --env-var password='...'
```

**Bruno (`bru run`) — sur une COPIE de l'environnement, pas le fichier commis.** A la
difference de Newman, Bruno PERSISTE sur disque toute variable posee par un script via
`bru.setEnvVar()` — "persists the change to disk" (`docs.usebruno.com/testing/script/
javascript-reference`, verifie dans la doc officielle) — y compris dans le fichier `.bru`
COMMIS. Lancer `bru run` directement sur `docs/api/bruno/environments/wakdo.bru` a deja, une
fois, committe de vrais jetons CSRF et des ids de ressources creees dans ce fichier suivi par
git. La documentation officielle de `bru run` ne propose pas de drapeau dedie a desactiver cette
persistance (verifie : `docs.usebruno.com/bru-cli/commandOptions.md` liste toutes les
options, aucune ne porte sur ce point precis) ; en revanche `--env-file [string]` ("Path to
the environment file (.bru or .json) to use for the collection run") permet de pointer le
run sur une copie, en dehors du depot :

```bash
cp docs/api/bruno/environments/wakdo.bru /tmp/wakdo-demo.bru
cd docs/api/bruno
npx --yes @usebruno/cli run --env-file /tmp/wakdo-demo.bru \
  --env-var email=admin@wakdo.local --env-var password='...'
# la copie /tmp/wakdo-demo.bru accumule les valeurs d'execution ; le fichier
# COMMIS (environments/wakdo.bru) reste, lui, intact.
```

Un garde-fou (`node --test tests/js/api-collection-secrets.test.js`) applique une LISTE
BLANCHE sur les deux fichiers d'environnement commis (Bruno et Postman), sur les variables
de collection Postman, et sur les corps de requete `.bru` : toute cle y porte une valeur
vide, a deux exceptions pres (`baseUrl`, et `pin_email` qui doit valoir exactement
`{{email}}`) ; tout champ `password*`/`pin*` d'un corps de requete doit contenir une
reference `{{...}}` plutot qu'un litteral fixe. Il echoue si le fichier commis venait malgre
tout a porter une valeur hors de cette liste blanche.

Les deux outils se lancent via `npx`/`--yes`, sans installation globale — verifie pendant ce
chantier (`npx --yes @usebruno/cli --version` repond directement ; voir aussi le rapport de
verification E2E cite dans le commit). Le dossier `RBAC : preuve des droits` suppose que le
seed de comptes de demo par poste existe sur la base ciblee (`docs/demo/comptes-demo.md`,
chantier separe) — sur une pile sans ce seed, ses requetes de connexion echouent avec
`INVALID_CREDENTIALS` (401), ce qui n'affecte pas le reste de la collection (dossiers
independants).

## 7. Lire les erreurs

Toute reponse suit l'enveloppe `{ "data": ... }` ou `{ "data": null, "error": { "code",
"message" } }` (section 7 de `conventions.md`). Les codes utiles en test manuel :

| Code | HTTP | Cause probable |
|---|---|---|
| `AUTH_REQUIRED` | 401 | pas encore connecte, ou deconnecte (`Se connecter` a relancer) |
| `INVALID_CREDENTIALS` | 401 | `email`/`password` faux (ou compte inconnu/inactif/verrouille), section 5.3bis |
| `TOO_MANY_ATTEMPTS` | 429 | verrou IP (throttling de connexion), `Retry-After` en secondes |
| `FORBIDDEN` | 403 | le compte utilise n'a pas la permission requise (section 5, RBAC) |
| `CSRF_INVALID` | 403 | `csrf` perime ou vide (relancer `Se connecter`) |
| `PIN_INVALID` | 422 | `pin_email`/`pin` absents, faux, ou compte verrouille (RG-T22) |
| `VALIDATION_ERROR` | 422 | champ manquant/invalide, detail dans `error.fields` |
| `CONFLICT` | 409 | doublon (slug/email/code) ou suppression bloquee par une reference |
| `NOT_FOUND` | 404 | id absent en base (variable d'environnement pas encore renseignee ?) |

## Effets de bord (ce que la collection laisse derriere elle)

Une collection de demo n'est pas neutre. Ce qu'elle laisse, execution par execution (verifie
par l'inspection directe de la base apres plusieurs executions, cf. rapport E2E du commit) :

- **Une categorie** desactivee (`is_active=false`), pas supprimee (section 4).
- **Un ingredient** desactive (celui qui a recu restock/inventaire/ajustement — les mouvements
  de stock bloquent sa suppression dure, comportement voulu).
- **Un role** desactive (`is_active=false`) — aucun endpoint `DELETE` n'existe pour les roles.
- **Un compte utilisateur** anonymise (tombstone RGPD, mlt 10.5) : la ligne reste en base,
  videe de ses identifiants.
- **Des commandes reellement encaissees**, cote dossier **Commandes** UNIQUEMENT (le dossier
  RBAC, lui, prouve `order.create` sans commande reelle — section 5) : chaque execution cree
  une commande livree ("delivered", etat terminal normal) et une commande annulee
  ("cancelled", restockee automatiquement mais dont la ligne `order` persiste). Ces commandes
  decrementent le stock reel au moment de la creation et comptent dans les statistiques
  (`GET /admin/api/stats`) tant qu'elles ne sont pas retirees.

**Consigne pour une demo en production** : si `scripts/demo-reset.sh` est present dans votre
copie (voir `docs/ops/demo-reset.md` pour son usage exact), lancez-le juste apres la demo pour
retirer ces effets de bord d'une base de production. S'il est absent, ne lancez PAS le
dossier Commandes contre la production (les autres dossiers restent sans risque : ils
suppriment ou desactivent deja ce qu'ils creent, section 4) — reservez Commandes a une pile
jetable (section 6) tant que ce script n'est pas disponible. Sur une pile jetable justement,
aucun nettoyage n'est requis dans tous les cas : la pile entiere est detruite ensuite.

## Limite connue : `clientIp()` derriere un mauvais proxy

`App\Core\Request::clientIp()` retient le dernier maillon de l'en-tete `X-Forwarded-For`
(voir son docblock) : fiable uniquement derriere un proxy de confiance unique qui pose cet
en-tete lui-meme, sans laisser passer une valeur fournie directement par le client
(hypothese de deploiement : Traefik en frontal, `docker-compose.prod.yml`). Sur la pile
STANDALONE de demo/developpement (`docker-compose.yml`, sans Traefik devant), rien ne pose
ni ne filtre cet en-tete cote serveur : un client peut fournir directement un
`X-Forwarded-For` de son choix et donc contourner le throttling PAR IP (RG-8) en
changeant cette valeur a chaque tentative. Le verrou PAR COMPTE
(`failed_login_attempts`, meme RG-8) reste alors le seul garde-fou reel sur cette pile —
comportement assume, pas un correctif prevu ici : une demo/dev standalone n'est pas exposee
au meme modele de menace qu'un deploiement derriere Traefik.

## Ce que couvre la collection

Onze dossiers, dans l'ordre ou les enchainer : 0. Connexion (login/qui suis-je),
Categories, Produits (dont recette et rangement), Menus, Ingredients (dont seuils,
inventaire, ajustement, allergenes), Roles (RBAC), Utilisateurs (dont reinitialisation de
PIN et anonymisation RGPD), Commandes (liste filtree par canal, saisie comptoir/drive,
cuisine, remise, annulation), Statistiques, RBAC : preuve des droits (section 5), et 9. Fin
de demo (deconnexion). Le contrat complet, methode par methode, est dans
`docs/api/conventions.md` section 5.3 (+ 5.3bis pour la connexion).

## Limites connues (section 5.3 de conventions.md)

Ne sont pas couverts par cette API JSON : l'upload d'image (multipart, HTML uniquement —
l'API accepte `image_path` deja heberge) et la suppression de role (le back-office HTML
n'en propose pas non plus). La DEFINITION initiale d'un PIN (self-service, `/admin/profile/pin`)
reste elle aussi HTML uniquement (section 3 ci-dessus).
