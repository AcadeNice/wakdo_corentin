# Preuve 05 — Librairies JavaScript (C2.d)

Titre RNCP 37805, Bloc 1 (developpement front-end).
Competence C2.d verbatim : « Optimiser les temps de developpement en utilisant
des ressources externes (librairies JavaScript) pour resoudre des
problematiques complexes ».

Criteres evalues : Cr 2.d.1 (les librairies utilisees repondent a une
problematique specifique), Cr 2.d.2 (elles sont implementees selon leur
documentation), Cr 2.d.3 (le candidat sait expliquer leur fonctionnement).

Ce document expose une position assumee et la defend sans la survendre. La
confiance sur la couverture stricte du critere est faible et elle est dite
comme telle en section 4.

---

## 1. Ce que le referentiel exige

La competence C2.d demande d'integrer des **ressources externes** —
concretement des librairies JavaScript tierces (par exemple un framework de
composants, une librairie de graphiques, un utilitaire de dates) — pour
accelerer le developpement sur une problematique jugee complexe. Le mot
« externes » est dans l'enonce. Les trois criteres se lisent naturellement sur
une dependance tierce : elle repond a un besoin precis (Cr 2.d.1), elle est
cablee conformement a sa documentation officielle (Cr 2.d.2), et le candidat en
maitrise le fonctionnement (Cr 2.d.3).

## 2. Le choix assume du projet : zero dependance externe

La borne (kiosk client, Bloc 1) est ecrite en JavaScript vanilla, sans aucune
librairie tierce ajoutee. C'est un choix, pas un oubli.

Preuves de l'absence de dependance externe :

- Aucun import externe dans le code de la borne. Une recherche sur
  `http`, `cdn`, `unpkg`, `jsdelivr`, `node_modules`, `npm:` dans
  `src/public/borne/assets/js/*.js` ne remonte rien : tous les imports sont
  relatifs (`./data.js`, `./state.js`, `./nav.js`...). Verifie sur les 14
  modules.
- Aucune balise `<script src="http...">` ni reference CDN dans les 5 pages
  HTML de la borne (`src/public/borne/*.html`). Tous les scripts sont charges
  en `type="module"` depuis `assets/js/` (ex. `products.html:64-68`).
- `package.json` (racine) ne declare aucune dependance de production :
  seulement deux `devDependencies` d'outillage de test
  (`@playwright/test`, `jsdom`) — `package.json:devDependencies`. Aucun
  framework front, aucun utilitaire runtime.
- Aucun bundler dans le depot (pas de `webpack.config.js`, `vite.config.*`,
  `rollup.config.*`, `.babelrc`, `tsconfig.json`). Le navigateur charge les
  modules ES6 nativement.

Note d'honnetete : `node_modules/lodash` est present sur la machine, mais c'est
une dependance transitive de `@playwright/test` (outil de test E2E). Il n'est
jamais importe par un module de la borne (la recherche `require(` /
`from 'lodash'` dans `src/public/borne/assets/js/` ne remonte rien). Il ne fait
donc pas partie du code livre au navigateur.

Raisons reelles du choix :

- **Surface fonctionnelle limitee.** Le parcours borne tient en 5 ecrans
  (accueil, categories, produits, paiement, confirmation). Un framework de
  composants apporterait un cout d'outillage (build, transpilation, montee de
  version) disproportionne pour ce volume.
- **Posture securite : pas de CDN.** Le projet applique une politique
  Content-Security-Policy stricte cote back-office :
  `docker/apache/vhost.conf:177` fixe `script-src 'self'` (aucune source de
  script tierce autorisee), avec un commentaire d'intention explicite
  « pas de CDN, pas d'analytics » (`docker/apache/vhost.conf:175`). Charger une
  librairie depuis un CDN entrerait en conflit avec cette ligne directrice.
  (Reserve importante detaillee en section 4 : ce header CSP est actuellement
  pose sur le vhost admin, pas sur le vhost borne.)
- **Controle total du code.** Chaque comportement est ecrit et lisible dans le
  depot ; il n'y a pas de couche tierce a auditer ou a maintenir a jour.
- **Pas de bundler.** Les modules ES6 natifs (`import`/`export`) charges par le
  navigateur suffisent, ce qui evite toute etape de build.

## 3. Defense : la MEME competence, demontree par des bibliotheques internes

L'esprit de C2.d — reutiliser des briques pour reduire le temps de
developpement et resoudre une problematique de facon factorisee — est demontre
par une architecture de **modules ES6 internes** jouant le role de
bibliotheques partagees.

Deux modules concentrent la logique reutilisable :

- **`state.js`** — bibliotheque d'etat panier + utilitaires. 13 exports
  (`state.js:25-194`) : `getMode`/`setMode`, `getCart`/`setCart`/`addToCart`/
  `removeFromCart`/`updateQuantity`/`clearCart`, `computeMenuLineCents`/
  `getTotalCents`/`getCartCount`, `formatPrice`, `escHtml`. C'est l'equivalent
  fonctionnel d'un mini-store client (persistance `localStorage`, prix en
  centimes entiers, echappement HTML anti-XSS centralise).
- **`data.js`** — couche d'acces aux donnees. 10 exports (`data.js:49-234`) :
  `loadCategories`, `loadProducts`, `loadProductsById`, `loadMenu`,
  `loadAllergens`, `getProductsByCategory`, `getCategoryById`, `findProduct`,
  et les tables `CATEGORY_ID_TO_SLUG` / `CATEGORY_SLUG_TO_ID`. Elle resout une
  problematique reelle et non triviale : memoisation par promesse pour deduire
  les requetes concurrentes vers `/api/*` (`data.js:25-31`), et rapprochement
  de la forme canonique de l'API vers la forme historique de la borne
  (`data.js:1-15`).

Ces bibliotheques sont importees et reutilisees dans tout le parcours, ce qui
est exactement le levier « ecrire une fois, reutiliser partout » que vise la
competence. Chaine d'imports verifiee :

| Consommateur | Importe depuis `state.js` / `data.js` | Ligne |
|---|---|---|
| `page-products.js` | `getProductsByCategory`, `getCategoryById`, `CATEGORY_ID_TO_SLUG`, `loadAllergens` (data) ; `formatPrice`, `escHtml` (state) | `page-products.js:10-11` |
| `page-payment.js` | `getTotalCents`, `formatPrice`, `getCart`, `getMode`, `clearCart`, `escHtml` (state) | `page-payment.js:12` |
| `checkout.js` | `getCart`, `getMode` (state) ; `loadMenu` (data) | `checkout.js:21-22` |
| `order-panel.js` | bloc d'imports state (`order-panel.js:12-21`) | `order-panel.js:12` |
| `nav.js` | `getMode`, `setMode`, `getCartCount` (state) | `nav.js:17` |
| `page-product-menu.js` | `loadMenu`, `loadProductsById` (data) ; `addToCart`, `computeMenuLineCents`, `formatPrice`, `escHtml` (state) | `page-product-menu.js:22-23` |
| `product-options.js` | `addToCart`, `formatPrice`, `escHtml` (state) | `product-options.js:18` |
| `category-strip.js` | `loadCategories` (data) ; `escHtml` (state) | `category-strip.js:12-13` |
| `confirm-modal.js` | `escHtml` (state) | `confirm-modal.js:10` |

Le mecanisme est du module ES6 natif standard. `src/public/borne/assets/js/`
porte son propre `package.json` avec `{"type":"module"}` (`package.json` du
dossier borne), qui marque ces fichiers comme ESM pour Node (execution des
tests) ; le navigateur, lui, les charge via `<script type="module">`
independamment de ce fichier. Cette meme couche ESM est reutilisee telle quelle
par la suite de tests unitaires (`tests/js/state.test.js`, `data.test.js`,
etc.), preuve supplementaire que ce sont bien des bibliotheques importables.

Ampleur mesuree : environ 2368 lignes de JavaScript reparties sur 14 modules
(`wc -l src/public/borne/assets/js/*.js`), tous articules autour de ce noyau
`state.js` / `data.js`. L'organisation en briques reutilisables est donc reelle
et a l'echelle du projet.

## 4. La tension, nommee sans detour

Il faut le dire clairement : cette architecture satisfait l'**esprit** de C2.d
(reutilisation pour reduire le temps de developpement) mais pas la **lettre** du
critere, qui parle de ressources « externes », c'est-a-dire tierces. Des
bibliotheques internes ne sont pas des librairies externes.

Un jury peut legitimement objecter que :

- aucune librairie tierce n'a ete integree, donc Cr 2.d.1 (« les librairies
  utilisees repondent a une problematique specifique ») et Cr 2.d.2
  (« implementees selon leur documentation ») n'ont pas de reference externe a
  evaluer ;
- la competence teste specifiquement la capacite a s'appuyer sur l'ecosysteme,
  a lire une documentation tierce et a l'integrer proprement — un exercice qui,
  ici, n'est pas realise sur une vraie dependance externe.

Reserve d'honnetete supplementaire sur l'argument securite (section 2) : le
header CSP `script-src 'self'` de `docker/apache/vhost.conf:177` est pose a
l'interieur du bloc `<VirtualHost>` **admin** (docroot
`/var/www/html/public/admin`, lignes 116-183). Le vhost **borne**
(docroot `/var/www/html/public/borne`, lignes 41-113) n'emet **pas** de header
CSP a ce jour. La posture « pas de CDN » est donc une intention documentee et
appliquee cote back-office, et elle est coherente avec l'absence totale de
reference externe dans la borne — mais elle n'est pas, en l'etat, une
contrainte techniquement active sur le vhost de la borne. Presenter la CSP comme
un garde-fou deja en vigueur sur la borne serait inexact.

**Niveau de confiance sur la couverture stricte de C2.d : faible.** La position
defendable est que la competence sous-jacente (structurer et reutiliser du code)
est demontree ; la position difficile a tenir est que le critere « externes »
soit litteralement rempli. Il ne l'est pas.

## 5. Points de defense a l'oral (Cr 2.d.3 — expliquer le fonctionnement)

Cr 2.d.3 porte sur la capacite a **expliquer le fonctionnement** du systeme
reutilise. Meme sans librairie tierce, cette capacite s'evalue sur les
bibliotheques internes. Points a maitriser et a exposer :

- **Le systeme de modules ES6.** Expliquer `export` / `import`, la resolution
  par chemin relatif, et pourquoi `type="module"` change le comportement du
  navigateur (chargement differe, portee de module, `import`/`export` actives).
  Savoir dire pourquoi le dossier borne porte son propre `package.json`
  `{"type":"module"}` (marquage ESM pour Node/tests) alors que la racine reste
  en CommonJS (`package.json` racine : hooks et scripts internes restent
  CommonJS).
- **La memoisation par promesse dans `data.js`** (`data.js:25-31`, `65-106`).
  Etre capable d'expliquer pourquoi on memoise la *promesse* et non le
  *resultat* : N appelants concurrents au meme `DOMContentLoaded` partagent une
  seule requete reseau, et la promesse est reinitialisee en cas d'echec pour
  autoriser un nouvel essai. C'est le point de conception le plus fin de la
  couche.
- **Le rapprochement de forme** (`data.js:1-15`, `49-106`) : la borne consomme
  une API en forme canonique (snake_case, enveloppe `{ data, total }`) et
  `data.js` traduit vers la forme historique attendue par les pages. Savoir
  dire que cette couche est le point unique de rapprochement, donc que changer
  le contrat API n'impacte qu'un fichier.
- **La convention de prix en centimes entiers** (`state.js:7-8`, `133-165`) et
  le formatage `fr-FR` centralise dans `formatPrice` : expliquer pourquoi on
  evite les flottants pour les montants.
- **L'echappement HTML centralise** `escHtml` (`state.js:187-194`) : expliquer
  qu'il est factorise dans `state.js` precisement pour que tous les modules qui
  injectent du markup a partir des donnees catalogue echappent de facon
  identique (defense anti-XSS coherente sur tout le parcours).

La ligne de defense honnete a l'oral : « J'ai fait le choix de ne pas ajouter de
librairie externe pour des raisons de perimetre, de securite et de controle. Je
demontre la competence de reutilisation par une architecture de modules
internes, et je sais en expliquer chaque mecanisme. Je reconnais que cela ne
coche pas la lettre du critere "externe" ; c'est une decision assumee dont je
peux discuter les limites. »

---

## Recapitulatif de couverture

| Critere | Statut | Fondement |
|---|---|---|
| Cr 2.d.1 (probleme specifique) | **Partiel** | Aucune lib externe ; probleme reel resolu par bibliotheques internes (`data.js`, `state.js`). Pas de reference tierce a evaluer. |
| Cr 2.d.2 (selon la doc) | **Non couvert au sens strict** | Aucune documentation tierce a suivre. Les modules ES6 suivent le standard du langage, pas une doc de librairie. |
| Cr 2.d.3 (expliquer le fonctionnement) | **Couvert (sur code interne)** | Le candidat peut expliquer modules ES6, memoisation par promesse, rapprochement de forme, escaping centralise. Applicable a l'interne, pas a une lib externe. |

Confiance globale sur la validation stricte de C2.d : **faible**, assumee.
