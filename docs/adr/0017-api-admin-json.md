# ADR-0017 — API d'administration JSON, en complement du MVC rendu serveur

- Statut : Accepte
- Date : 2026-09-25

## Contexte

ADR-0002 a fixe le back-office en MVC rendu serveur (formulaires POST + redirections,
vues PHP) : ce choix reste valide et n'est pas remis en cause ici. Mais
`docs/api/conventions.md` (section 5.3, redigee le 15/06) decrit depuis longtemps une API
REST d'administration (`PUT`/`DELETE` sur produits, menus, categories, users, roles) marquee
« prevu » : cette projection est restee non realisee jusqu'a ce chantier, alors que le
contrat est ecrit et lu comme s'il existait deja. Le jury de soutenance doit pouvoir tester
ce contrat en Postman (execution reelle, pas seulement une lecture du document). Sans
endpoints reels, l'ecart entre la documentation et le code est un mensonge de fait, pas
seulement une dette.

Deux options etaient sur la table : (a) migrer le back-office HTML vers une SPA consommant
une API JSON complete (annulerait ADR-0002, gros chantier, hors scope d'un ajout ponctuel) ;
(b) ajouter une couche API JSON etroite, en PARALLELE du MVC existant, qui reutilise sa
logique metier sans le remplacer. (b) est retenu : le present ADR ne fait donc que
COMPLETER ADR-0002, il ne le remplace pas.

## Decision

Ajouter une API JSON d'administration CRUD complete (categories, produits, menus,
ingredients, utilisateurs, roles) sous le prefixe **`/admin/api/...`** — et non `/api/...`.

Raison du prefixe : le vhost kiosk (`docker/apache/vhost.conf`) relaie sans filtrage tout
`/api/*` vers PHP-FPM pour que la borne consomme le catalogue en meme origine
(`ProxyPassMatch "^/api(/.*)?$"`). Cette API d'administration est authentifiee (session +
permissions + PIN sur les actions sensibles) : la placer sous `/api` l'aurait rendue
atteignable, en HTTP brut, depuis l'origine PUBLIQUE de la borne — une extension de surface
d'attaque que rien ne justifie. `/admin/api/...` ne matche pas ce prefixe et reste donc
hors de portee du vhost kiosk, meme raisonnement deja applique a `/admin/me` (ADR implicite
du code, jusqu'ici non documente).

Chaque controleur JSON (`App\Controllers\Admin\Api\*ApiController`) ETEND le controleur
HTML existant de sa ressource (ex. `CategoryApiController extends CategoryController`) pour
heriter de sa validation et ses repositories (methodes autrefois `private`, elargies a
`protected`). Ce qui est REELLEMENT partage (code identique, appele tel quel, pas une
regle equivalente reecrite en double) :
- `validate()`, `parseComposition()`, `decodeItems()`, `messageFor()`... (longueurs,
  formats, unicite) ;
- la validation des entiers de stock (packs/comptage/ajustement) : extraite dans
  `App\Core\NumericInput`, utilisee a la fois par `IngredientController` (HTML, modifie
  pour l'appeler) et `IngredientApiController` (JSON) -- une correction de regle a un seul
  endroit desormais, au prix d'un changement (mineur, teste) du code HTML existant.

Ce qui reste NEANMOINS reecrit en double, cote `IngredientApiController`, MALGRE le
partage de `NumericInput` ci-dessus (relecture adverse, 2e passe, point 5 -- honnetete
sur ce qui n'est pas factorise) :
- la longueur maximale de la note libre (255 caracteres, `restock`/`inventory`/`adjust`) :
  verifiee par un `if (mb_strlen($note) > 255)` DUPLIQUE dans chaque methode API, qui a
  son pendant DUPLIQUE separement dans chaque methode HTML correspondante -- meme regle,
  quatre copies (2 HTML + 2 JSON), pas une source unique ;
- le controle "ingredient actif" prealable au reapprovisionnement (`apiRestock` :
  `(int) ($ingredient['is_active'] ?? 0) !== 1`) : reecrit a l'identique de l'equivalent
  HTML (`IngredientController::restock()`), pas appele en partage ;
- la boucle de revue des allergenes (`apiAllergens`) : structurellement identique a
  `IngredientController::allergens()` (meme parcours du catalogue, meme construction de
  `$retained`), mais la CONDITION de retenue differe necessairement (cases a cocher
  `allergen_<id>` du formulaire HTML vs liste `allergen_ids` du corps JSON) -- une
  reimplementation du meme algorithme, pas du code partage.

Factoriser ce qui pouvait raisonnablement l'etre sans reecrire la logique metier reste
le principe directeur (voir `NumericInput` ci-dessus) ; les trois points ci-dessus sont
restes dupliques parce que les factoriser aurait exige soit de faire remonter une
dependance HTML dans le trait JSON (contraire au sens de l'heritage choisi), soit un
detour (interface commune, callback) juge disproportionne pour trois regles aussi
courtes. Nomme ici explicitement plutot que couvert par une formule "zero duplication"
qui ne serait plus exacte a l'echelle du fichier entier.

Ce qui n'est PAS du code partage, mais une REIMPLEMENTATION du meme comportement :
- la garde authentification/permission JSON (`guardApi()`, dans `JsonApiTrait`) reproduit
  le meme controle que `AdminController::guard()` (memes codes de permission) sans
  reutiliser son code (celui-ci redirige/rend une vue HTML, incompatible avec une reponse
  JSON) ;
- le PIN d'action sensible (RG-T13/RG-T22) est reimplemente dans un service neuf
  (`App\Auth\PinGate`), qui reproduit la sequence des `resolvePin()`/blocs inline prives
  des controleurs HTML (verrou avant verification, leurre de timing, trace `pin.failed` +
  audit dans une seule transaction) SANS partager leur code : les controleurs HTML gardent
  leur copie historique intacte (zero changement de comportement sur un code deja en
  production), `PinGate` evite seulement de la re-dupliquer une septieme fois pour les six
  ressources JSON ;
- la visibilite de canal des commandes (RG-T12, `OrderApiController::sourceVisible()`/
  `orderDetail()`) reproduit le principe de `OrderAdminController::orderSource()`/
  `sourceVisibleToRole()` (memes regles) dans du code neuf, pas partage.

**Mise a jour (post-soutenance, chantier RBAC canal)** : `roleFixedSource()` (canal
FIXE du role, `role.order_source`), a la redaction initiale de cet ADR, n'avait pas
d'equivalent HTML reutilise : le HTML deduisait sa source du CHEMIN (`/counter/orders`
vs `/drive/orders`) sans verifier que le role visitant cette page y avait droit --
faille verifiee en direct lors d'une revue de securite pre-soutenance (un compte a
canal fixe, ex. `drive`, pouvait visiter `/counter/orders`, route valide, meme
permission `order.create`, et y creer une commande taguee `counter`). Corrige :
`roleFixedSource()` est remontee dans `AdminController` (une seule lecture, un seul
contrat, `protected`) ; `CounterOrderController::channelGuard()` (HTML, appele par
`index()`/`create()`/`store()`) et `OrderApiController::apiStore()` (JSON)
appliquent desormais la meme regle depuis cette source unique -- ce qui n'etait pas
partage devient partage. Un role a canal fixe n'accede QU'A la page de son propre
canal quand celle-ci existe (`counter`/`drive`, 403 sur l'autre) ; un canal fixe SANS
page HTML dediee (ex. `kiosk`, propose par `RoleController::SOURCES` mais sans route
`/kiosk/orders`) reste ferme sur LES DEUX (relecture adverse, point 1 : bloquer par
defaut, pas seulement reconnaitre `counter`/`drive`). Un role SANS canal fixe reste,
lui, borne par ses sources VISIBLES (`role_visible_source`, point 2) plutot que par un
acces inconditionnel aux deux pages -- `admin` (seul role du seed 0001 a la fois sans
canal fixe et titulaire d'`order.create`) garde l'acces aux deux parce que sa
visibilite est globale (role_visible_source vide), pas parce que `order_source` NULL
suffirait a lui seul ; `manager`, lui aussi sans canal fixe, n'a de toute facon PAS
`order.create` (decision D5) et une garde de permission en amont l'arrete avant cette
resolution de canal.

Un trait (`JsonApiTrait`) porte ce qui est propre au transport JSON (garde 401/403, CSRF
par en-tete `X-CSRF-Token`, enveloppe `{data}`/`{error}`, rejet nomme d'un champ non
scalaire) : un trait plutot qu'une classe de base, parce que PHP n'autorise qu'un seul
parent et que ce parent est deja pris par le controleur HTML.

## Consequences

- (+) Pas de duplication pour `validate()` et consorts, ni pour la validation des entiers
  de stock (`NumericInput`) : un bug corrige a l'un de ces endroits est corrige aussi pour
  l'autre canal, par construction (heritage/appel partage). Ce n'est PAS vrai a l'echelle
  de tout le fichier : la longueur de note, le controle "ingredient actif" au restock et
  la boucle allergenes RESTENT reecrits en double (voir la section Decision ci-dessus,
  liste explicite, relecture point 5).
- (+) Regression testee, pas seulement visee, sur le back-office HTML : la suite de tests
  HTML preexistante (`tests/Unit/Admin/*`, `tests/Unit/Auth/*`, hors dossier `Api/`) reste
  entierement verte apres chaque modification de ce chantier (verifie a chaque etape, pas
  seulement a la fin). Deux methodes EXISTANTES ont neanmoins change : `MeController::show()`
  ajoute le champ `csrf_token` a sa reponse (additif, aucun champ retire) et
  `IngredientController` (restock/inventaire/ajustement) appelle desormais `NumericInput` au
  lieu de son inline d'origine (meme regle, testee par la suite HTML existante ET par
  `tests/Unit/Core/NumericInputTest.php`, 35 cas dont vide/espaces/+5/05/-0/1.5/1e3/
  bornes/tres grands nombres/"5\n"/booleen/flottant JSON/chiffre arabe-indien). Les
  visibilites `private` -> `protected` restantes
  n'ont, elles, change aucun comportement (une visibilite plus large ne peut pas casser un
  appelant existant).
- (+) La borne (kiosk, non authentifiee) ne peut pas atteindre cette API (prefixe hors du
  relais `/api/*` du vhost kiosk) : la surface d'attaque publique n'augmente pas.
- (-) Deux formes de reponse coexistent pour une meme ressource (redirection HTML pour
  `/admin/products`, JSON pour `/admin/api/products`) : cout de lecture pour qui decouvre le
  code, attenue par le docblock de chaque `*ApiController` qui pointe vers son homologue HTML.
- (-) Deux limites restent documentees dans `docs/api/conventions.md` (pas silencieuses) :
  l'upload d'image produit/categorie (multipart — l'API JSON accepte `image_path` deja
  heberge) et la suppression de role (le back-office HTML n'en propose pas non plus). Le
  reste du perimetre HTML (stock avance, recette, commandes, reinitialisation de PIN,
  anonymisation RGPD, statistiques) a ete ferme dans un second chantier (meme jour) : voir
  la section 5.3 de `docs/api/conventions.md` pour le contrat a jour.
- Le domaine commande a impose deux adaptations assumees, documentees dans le docblock
  d'`OrderApiController` et dans `conventions.md` :
  - le HTML sert la saisie comptoir/drive par DEUX pages (`/counter/orders`,
    `/drive/orders`), la source etant deduite du CHEMIN. L'API JSON n'a qu'un seul endpoint
    (`POST /admin/api/orders`) : un role a canal FIXE (`role.order_source`) l'impose, un
    role SANS canal fixe (`admin` au seed 0001 -- `manager`, lui aussi sans canal fixe,
    n'a pas `order.create` et n'atteint pas cet endpoint) doit le CHOISIR dans le corps
    (`source`), et ce choix doit en outre rester dans ses sources VISIBLES
    (`role_visible_source`, relecture adverse point 2) -- une regle que le HTML n'a pas
    a exprimer puisque son choix se fait par l'URL visitee ;
  - la lecture unitaire, les transitions (ready/deliver) ET l'annulation (`cancel`,
    corrige lors de la seconde relecture adverse) renvoient toutes `403` (pas `404`) pour
    un numero INCONNU comme pour un canal non visible, VERIFIE AVANT le PIN sur `cancel`
    (un acteur ne doit pas pouvoir distinguer "n'existe pas" de "existe, PIN faux" via le
    code HTTP) -- pour ne pas reveler par la difference de code qu'une commande d'un
    autre canal existe. **Limite levee (chantier RBAC canal, post-soutenance)** : cote
    HTML, `OrderAdminController::cancel()` applique desormais la MEME garde de
    visibilite de canal (`sourceVisibleToRole()`, verifiee AVANT le PIN, meme reponse
    403 pour un numero inconnu et pour un canal non visible), et `confirmCancel()`
    (page de confirmation GET, en amont de `cancel()`) l'applique aussi, pour ne pas
    reveler numero/statut/total d'une commande hors des canaux visibles du role avant
    meme le PIN. Le paragraphe ci-dessus decrivait une limite CONNUE et assumee au
    moment de ce chantier JSON ; elle ne l'est plus (voir `conventions.md` section 5.3
    pour le contrat a jour).
- Fichiers concernes : `src/app/Controllers/Admin/Api/*` (dont `OrderApiController` et
  `StatsApiController`, ajoutes au second chantier), `src/app/Auth/PinGate.php`,
  `src/public/admin/index.php` (routes), `docs/api/conventions.md` (section 5.3),
  `docs/api/wakdo-admin.postman_collection.json`.

## Addendum (2026-09-26) — Connexion JSON (`/admin/api/auth/*`)

### Contexte de l'addendum

L'API JSON decrite ci-dessus supposait une session DEJA ouverte (obtenue via le formulaire
HTML `POST /login`) : le seul chemin pour l'obtenir en dehors d'un navigateur etait de copier
a la main le cookie de session depuis les outils de developpement — releve en pratique comme
trop fragile pour une demonstration devant jury. Cet addendum AJOUTE trois routes
(`POST /admin/api/auth/login`, `POST /admin/api/auth/logout`, `GET /admin/api/auth/me`,
`App\Controllers\Admin\Api\AuthApiController`) sans remettre en cause la decision ci-dessus :
memes principes (prefixe `/admin/api/`, trait `JsonApiTrait`, enveloppe `{data}`/`{error}`).

### Pourquoi un cookie de session plutot qu'un jeton en local storage

Alternative envisagee et ecartee : repondre au login par un jeton (JWT ou opaque) que le
client stocke lui-meme (`localStorage`/`sessionStorage`) et renvoie dans un en-tete
`Authorization`. Ecartee pour deux raisons, la premiere sourcee, la seconde un compromis
assume plutot qu'une garantie :

1. **Surface d'exposition XSS [CLAIM L2, OWASP Cheat Sheet Series — "HTML5 Security
   Cheat Sheet" / "Session Management Cheat Sheet" -- documentation produit d'un organisme
   reconnu, pas une specification ratifiee (RFC/W3C/ECMA) : niveau L2, pas L1].** Un jeton
   stocke en `localStorage` (ou lu par un script) est
   accessible a TOUT script JavaScript qui s'execute dans la page — y compris un script
   injecte par une faille XSS non liee a l'authentification elle-meme (ex. un champ de
   catalogue mal echappe). Un cookie marque `HttpOnly` (`SessionManager::start()`, deja en
   place depuis ADR-0002) n'est pas lisible par `document.cookie` ni par un script quelconque
   — c'est un MECANISME (une restriction posee par le navigateur sur CE canal de lecture),
   pas une garantie absolue contre toute exploitation : un attaquant qui execute du JavaScript
   dans la page peut encore agir AU NOM de la victime en forcant le navigateur a emettre des
   requetes (le cookie part avec, automatiquement), sans avoir besoin de lire sa valeur. La
   difference reelle est : voler la VALEUR du cookie pour la rejouer ailleurs (hors du
   navigateur, plus tard) n'est pas possible PAR CE CANAL PRECIS (`document.cookie` en
   JavaScript) avec `HttpOnly`, alors que c'est trivial avec un jeton lisible en JS -- ce
   n'est pas une garantie absolue contre toute exfiltration (un autre canal, ex. une fuite
   serveur ou une interception reseau sans HTTPS, resterait un risque distinct), seulement
   la fermeture de CE canal-la.
2. **Le compromis : il faut le CSRF, ferme differemment selon la requete.** Un cookie envoye
   AUTOMATIQUEMENT par le navigateur (contrairement a un jeton que le CLIENT doit
   explicitement ajouter a l'en-tete) ouvre la porte inverse (CSRF). Pour toute ecriture APRES
   connexion (y compris `POST /admin/api/auth/logout`), ce risque est ferme par le jeton CSRF
   synchroniseur en en-tete `X-CSRF-Token` (`App\Auth\Csrf`, illisible sans avoir deja une
   session legitime) ; `SameSite=Strict` y contribue aussi, mais pour une raison PRECISE :
   il empeche le navigateur d'ENVOYER le cookie de session sur une requete ulterieure
   initiee depuis un autre site -- pas d'empecher la CREATION du cookie (cf.
   `docs/api/conventions.md` section 5.3bis pour la source exacte et la distinction).
   Correction : le cookie de session existe DEJA a ce stade, contrairement a ce qu'une
   premiere version de ce paragraphe affirmait. `src/public/admin/index.php` (ligne 77)
   appelle `(new SessionManager($config))->start()` de facon INCONDITIONNELLE, avant le
   routage, sur CHAQUE requete du vhost admin -- `POST /admin/api/auth/login` y compris.
   Une session anonyme (aucun `user_id`) existe donc deja, et son cookie part deja dans la
   reponse HTTP, avant meme que les identifiants soumis ne soient verifies. Ce qui reste vrai
   dans le paragraphe d'origine : cette session anonyme ne porte, sauf appel prealable a
   `Csrf::token()` sur CETTE session precise, aucun jeton CSRF ; `POST /admin/api/auth/login`
   n'a donc PAS de jeton CSRF a verifier a ce stade (rien a valider avant authentification),
   ce qui est different de "pas de cookie". SameSite=Strict s'applique bien a ce cookie
   anonyme des sa creation (il gouverne l'envoi, pas la creation, cf. plus haut) mais ce
   cookie ne porte alors aucun etat sensible a proteger : la fermeture CSRF de
   `POST /admin/api/auth/login` repose sur un mecanisme DIFFERENT (le Content-Type impose,
   qui force un preflight CORS ferme sur `/admin/api/`), detaille et source dans
   `docs/api/conventions.md` section 5.3bis.

Un jeton en local storage aurait supprime le second probleme (rien n'est envoye
automatiquement) au prix du premier (lisible par tout script) ; le choix retenu ici accepte
l'inverse et ferme le second probleme par des mecanismes deja en production (SameSite +
CSRF), plutot que d'echanger un risque contre un autre sans filet.

### Consequences de l'addendum

- (+/-) `AuthService`, `SessionManager` et `PasswordHasher` sont REUTILISES par la connexion
  JSON (aucune duplication de la logique de throttling/anti-enumeration/regeneration de
  session), mais PAS "sans changement" : les trois ont ete modifies EN PLACE au fil de ce
  chantier, pour tout consommateur (HTML compris, pas seulement JSON) :
  - `AuthService::authenticate()` — le chemin "compte verrouille" fait desormais le meme
    travail que "email inconnu" (appel a `verifyDecoy()`, increment du compteur IP) : avant
    ce changement, un compte verrouille ne faisait PLUS progresser le compteur IP, ce qui le
    distinguait d'un email inconnu (enumeration de comptes, corrige dans ce chantier) ;
  - `PasswordHasher::decoyHash()` — le leurre etait mis en cache dans une propriete `static`,
    qui NE SURVIT PAS a la requete suivante sous PHP-FPM classique (chaque requete redeclare
    les classes depuis zero) : le leurre etait donc RECALCULE (`password_hash()` + `verify()`)
    a chaque tentative sur un email inconnu ou un compte verrouille, plus cher qu'une
    verification reelle (`verify()` seul) et distinguable par le temps de reponse. Remplace
    par un cache sur DISQUE (fichier, cle par empreinte des parametres argon2id), qui survit
    entre requetes et entre workers ;
  - `SessionManager::regenerate()` — ajout d'un compteur d'appels (`regenerateCallCount()`),
    sans effet sur le comportement de production, pour que les tests puissent verifier que
    RG-3 (anti-fixation) est reellement declenchee.
- (-) Compromis herite, non introduit par cet addendum mais qui s'applique a cette route
  comme au formulaire HTML : le verrou IP (`IP_THROTTLE_MAX_ATTEMPTS`, RG-8) est compte par
  adresse source, donc partage entre tous les postes d'un meme restaurant derriere un NAT --
  le detail du compromis et le levier de reglage (variable d'environnement, pas une constante)
  sont dans `docs/api/conventions.md`, paragraphe "Compromis assume : le verrou IP se partage
  derriere un NAT de restaurant".
- (+) `AuthApiController extends MeController` (et non `AuthenticatedController`
  directement) : `apiMe()` appelle `MeController::show()` sans le reimplementer (heritage
  reel, meme raisonnement que le reste de cet ADR).
- (-) `AuthApiController::authService()` DUPLIQUE le hook prive equivalent
  d'`App\Controllers\AuthController` (meme construction exacte) : les deux controleurs
  n'ont pas de parent commun compatible (`AuthController extends Controller`,
  `AuthApiController extends MeController extends AuthenticatedController`), donc ce hook de
  quatre lignes n'a pas pu etre factorise sans reintroduire une dependance croisee entre les
  deux hierarchies. Nomme ici explicitement (meme discipline que la section Decision
  ci-dessus).
- (-) `/admin/api/auth/*` reste volontairement HORS de la matrice CSRF/permission generique
  de `RouteMatrixTest` (modele structurellement different : pas de permission, pas de jeton
  CSRF synchroniseur pour `login`) — teste a part dans `AuthApiControllerTest`, documente
  dans `RouteMatrixTest` lui-meme.
