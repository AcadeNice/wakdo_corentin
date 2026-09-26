# Matrice RBAC prouvée (rôle × permission + scénarios de démonstration)

Author: BYAN

Source de vérité : `db/seeds/0001_rbac_and_reference.sql` (`role_permission`) et
les routes réelles de `src/public/admin/index.php` (pages serveur + API JSON
`/admin/api/*`). Les libellés français des permissions reprennent le mapping
déjà affiché aux administrateurs dans le formulaire de rôle
(`src/app/Views/admin/roles/form.php`, tableau `$permMap`) : la base stocke les
codes et des libellés anglais (`permission.label`), la présentation française
est une couche d'affichage déjà en place, reprise ici pour rester lisible par
un jury non technique.

## 1. Tableau rôle × permission (23 permissions)

Légende : `X` = permission accordée (`role_permission`, seed 0001) ; case vide
= non accordée.

| Domaine | Permission (libellé FR) | Code | admin | manager | kitchen | counter | drive |
|---|---|---|:-:|:-:|:-:|:-:|:-:|
| Produits | Voir | `product.read` | X | X | X | X | X |
| Produits | Créer | `product.create` | X | X | | | |
| Produits | Modifier | `product.update` | X | X | | | |
| Produits | Supprimer | `product.delete` | X | | | | |
| Menus | Voir | `menu.read` | X | X | X | X | X |
| Menus | Créer | `menu.create` | X | X | | | |
| Menus | Modifier | `menu.update` | X | X | | | |
| Menus | Supprimer | `menu.delete` | X | | | | |
| Catalogue & recettes | Gérer les catégories | `category.manage` | X | X | | | |
| Catalogue & recettes | Gérer les ingrédients et recettes | `ingredient.manage` | X | X | | | |
| Stock | Voir | `stock.read` | X | X | X | X | X |
| Stock | Faire l'inventaire | `stock.count` | X | X | X | X | X |
| Stock | Réapprovisionner | `stock.manage` | X | X | | | |
| Commandes | Voir | `order.read` | X | | X | X | X |
| Commandes | Créer | `order.create` | X | | | X | X |
| Commandes | Livrer | `order.deliver` | X | | | X | X |
| Commandes | Annuler | `order.cancel` | X | | | X | X |
| Comptes | Voir | `user.read` | X | X | | | |
| Comptes | Créer | `user.create` | X | | | | |
| Comptes | Modifier | `user.update` | X | | | | |
| Comptes | Désactiver | `user.deactivate` | X | | | | |
| Rôles & statistiques | Gérer les rôles | `role.manage` | X | | | | |
| Rôles & statistiques | Voir les statistiques | `stats.read` | X | X | | | |

Totaux par rôle (recoupent `role_permission`, vérifié par requête SQL en
section 5) : admin 23, manager 13, kitchen 5, counter 8, drive 8. `counter` et
`drive` détiennent exactement le même sous-ensemble de 8 permissions — leur
différence n'est pas une différence de droits mais de **source de commande**
(auto-taguée par le chemin de la requête, `/counter/orders` vs `/drive/orders`)
et de **visibilité** (`role_visible_source`, colonne suivante).

### Sources de commande visibles par rôle (`role_visible_source`, distinct de `role_permission`)

| Rôle | Sources visibles à l'écran cuisine (`/kitchen/display`) |
|---|---|
| admin / manager | Aucune ligne en base → vue globale (les 3 sources), `OrderQueryRepository::visibleSources()` |
| kitchen | `kiosk`, `counter`, `drive` (les 3) |
| counter | `kiosk`, `counter` |
| drive | `drive` |

Ce filtrage (RG-T12, `docs/merise/mlt.md`) s'applique à `/kitchen/display`
(`OrderQueryRepository::paidQueue()`) ET, depuis la relecture adverse qui a suivi
la première version de cette matrice, à `/admin/orders`
(`OrderQueryRepository::recentVisible()`, filtrée par `role_visible_source` EN
SQL — avant le `LIMIT`, pas par un filtre PHP après coup, pour qu'un rôle à
canal restreint ne tombe pas sur une liste vide si ses commandes sont plus
anciennes que les N plus récentes tous canaux confondus). Voir la note de
`docs/demo/comptes-demo.md`, section « Vérification », mise à jour en
conséquence.

## 2. Scénarios de démonstration par rôle

Pour chaque scénario : action, résultat ATTENDU (déduit de `role_permission` +
de la garde de route citée), puis résultat VÉRIFIÉ en section 5 (pile
jetable). `pin_email`/`pin` désignent les champs du formulaire/JSON PIN
équipier (RG-T13) ; ils identifient l'acteur qui SIGNE l'action, indépendamment
de la session connectée.

### Administrateur (`admin@wakdo.local`, référence)

| # | Scénario | Attendu |
|---|---|---|
| A1 | Navigateur : `GET /admin/roles` | 200 — `role.manage` détenu, seul rôle à avoir accès à la gestion RBAC. |
| A2 | API : `DELETE /admin/api/products/{id}` avec PIN admin | 200/204 — seul rôle à détenir `product.delete`. |
| A3 | Navigateur : `GET /admin/orders/{number}/cancel` puis annulation avec PIN | 200 — `order.cancel` détenu (contrairement à manager). |

### Responsable (`manager@wakdo.local`)

| # | Scénario | Attendu |
|---|---|---|
| M1 | Navigateur : `GET /admin/products/new` puis `POST /admin/products` | 200/302 — `product.create` détenu. |
| M2 | Navigateur : `GET /admin/orders/{number}/cancel` (tentative d'annulation) | **403** — manager ne détient PAS `order.cancel` (décision D5, séparation des pouvoirs : celui qui gère le catalogue et le stock ne décide pas d'annuler une vente). |
| M3 | API : `DELETE /admin/api/products/{id}` | 403 — pas de `product.delete` (réservé à admin). |
| M4 | Navigateur : `GET /admin/users` | 200 — `user.read` détenu (lecture seule : le bouton « Nouveau compte » n'apparaît pas côté vue, `user.create` absent). |
| M5 | API : `POST /admin/api/users` | 403 — pas de `user.create`. |

### Équipier cuisine (`cuisine@wakdo.local`)

| # | Scénario | Attendu |
|---|---|---|
| K1 | Navigateur : `GET /kitchen/display` | 200 — `order.read` détenu ; file filtrée sur les 3 sources (kitchen voit tout). |
| K2 | Navigateur : `GET /admin/orders` | 200 — même permission `order.read` ; liste désormais filtrée par `role_visible_source` (RG-T12, corrigé) comme l'écran cuisine — kitchen voyant les 3 sources (seed 0001), le contenu affiché ne change pas pour ce rôle précis, mais la garde s'applique désormais uniformément. |
| K3 | API : `POST /admin/api/orders/{number}/deliver` | 403 — pas de `order.deliver`. |
| K4 | API : `POST /admin/api/orders/{number}/cancel` | 403 — pas de `order.cancel`. |
| K5 | Navigateur : `GET /admin/users` | 403 — pas de `user.read`. |

### Équipier comptoir (`comptoir@wakdo.local` / `comptoir2@wakdo.local`)

| # | Scénario | Attendu |
|---|---|---|
| C1 | Navigateur : `GET /counter/orders` puis création d'une commande | 200/302 — `order.create` détenu ; commande créée avec `source = 'counter'`. |
| C2 | Navigateur : `GET /admin/orders/{number}/cancel` puis annulation avec le PIN de `comptoir2@wakdo.local` (session ouverte sous `comptoir@wakdo.local`) | 200 — `order.cancel` détenu ; `audit_log.actor_user_id` porte l'identifiant de `comptoir2`, résolu par le PIN (RG-T13), pas celui de la session connectée. |
| C3 | Navigateur : `GET /admin/users` | 403 — pas de `user.read` (exemple donné dans la commande). |
| C4 | API : `DELETE /admin/api/products/{id}` | 403 — pas de `product.delete` (exemple donné dans la commande). |
| C5 | API : `POST /admin/api/ingredients/{id}/restock` | 403 — pas de `stock.manage` (contrairement à `stock.count`, détenu). |
| C6 | Navigateur : `GET /drive/orders` (canal FIXE `counter`, RG-T12) | **403** — `channelGuard()` refuse la page de l'AUTRE canal, même avec `order.create` détenu (section 3). |

### Équipier drive (`drive@wakdo.local`)

| # | Scénario | Attendu |
|---|---|---|
| D1 | Navigateur : `GET /drive/orders` puis création d'une commande | 200/302 — `order.create` détenu ; commande créée avec `source = 'drive'` (chemin `/drive/...`). |
| D2 | Navigateur : `GET /kitchen/display` | 200, mais file limitée aux commandes `source = 'drive'` (RG-T12) : le drive ne voit dans cet écran QUE les commandes drive — même filtre désormais appliqué à `/admin/orders` (K2 ci-dessus, section 3). |
| D3 | API : `POST /admin/api/orders/{number}/cancel` avec PIN | 200 — `order.cancel` détenu. |
| D4 | Navigateur : `GET /admin/ingredients/new` (créer un ingrédient) | 403 — pas de `ingredient.manage`. |
| D5 | Navigateur : `GET /admin/stats` | 403 — pas de `stats.read`. |
| D6 | Navigateur : `GET /counter/orders` (canal FIXE `drive`, RG-T12) | **403** — symétrique de C6 : `channelGuard()` refuse l'AUTRE canal. |

## 3. Cloisonnement par canal (RG-T12) — faille corrigée après une relecture adverse

La première version de cette matrice documentait ici une limite CONNUE et
NON corrigée : `CounterOrderController` dérivait la source de commande du seul
CHEMIN de la requête (`/drive/...` → `drive`, sinon → `counter`), sans
vérifier que le rôle connecté avait le droit d'être sur cette page. Un compte
`drive` naviguant directement vers `/counter/orders` pouvait donc y créer une
commande taguée `source = 'counter'`, et réciproquement (vérifié en direct lors
d'une relecture de sécurité pré-soutenance).

**Corrigé** : `CounterOrderController::channelGuard()` (appelé par
`index()`/`create()`/`store()`) applique désormais deux gardes, l'une
n'excusant pas l'absence de l'autre :

- un rôle dont `order_source` est FIXE et non nul (`counter`/`drive` pour les
  cinq rôles du seed 0001 ; le formulaire de rôle propose aussi `kiosk`, qui
  n'a AUCUNE page HTML dédiée et reste donc fermé sur les deux — bloquer par
  défaut plutôt que ne reconnaître que `counter`/`drive`) n'accède QU'À la page
  de son propre canal, l'autre rend `403` (scénarios C6/D6 ci-dessus) ;
- même un rôle SANS canal fixe reste borné par ses sources VISIBLES
  (`role_visible_source`) : `admin` garde l'accès aux deux pages parce que sa
  visibilité est globale (aucune ligne en base), pas parce qu'un canal fixe
  NULL suffirait à lui seul à autoriser l'accès inconditionnel.

Même règle côté API JSON (`OrderApiController::apiStore()`) : le canal retenu
(imposé par le rôle ou choisi dans le corps) doit lui aussi être dans les
sources visibles du rôle, sinon `422 VALIDATION_ERROR` — un rôle sans canal
fixe mais à visibilité restreinte ne peut plus choisir un canal qu'il ne voit
pas ailleurs.

`channelGuard()` et `roleFixedSource()` sont couverts unitairement dans
`tests/Unit/Admin/CounterOrderControllerTest.php` (rôles `counter`/`drive`
seedés ET rôle personnalisé `order_source='kiosk'`) et
`tests/Unit/Admin/Api/OrderApiControllerTest.php`. Les scénarios C6/D6
ci-dessus sont vérifiés avec les VRAIS comptes de démonstration
(`comptoir@wakdo.local`, `drive@wakdo.local`) dans `tests/e2e/rbac-demo.spec.js`
(cas ajoutés à la suite des tests « comptoir » et « drive » existants) ; le
cloisonnement par canal est en outre vérifié séparément dans
`tests/e2e/rbac-channel.spec.js`, sur des comptes auto-provisionnés par ce
fichier (pas les comptes de démo), et pour les rôles PERSONNALISÉS (canal fixe
`kiosk`, canal NULL à visibilité restreinte) qui n'ont pas de compte de
démonstration dédié (traçabilité complète en section 4 ci-dessous).

## 4. Traçabilité des tests

- `tests/Integration/RouteMatrixRoleDbTest.php` : rejoue la table des routes de
  `RouteMatrixTest` (reprise par réflexion, pas dupliquée) pour chaque rôle
  RÉEL issu des seeds 0001 + 0009, contre une vraie base migrée/seedée
  (`WAKDO_DB_TESTS=1`).
- `tests/e2e/rbac-demo.spec.js` : connexion Playwright avec chaque compte de
  démonstration sur une pile jetable, vérifie la page d'arrivée
  (`role.default_route`), la navigation visible (liens du menu latéral), au
  moins un refus par rôle, et (depuis la relecture adverse, 2e tour) les
  scénarios C6/D6 de cloisonnement par canal (comptoir refusé sur
  `/drive/orders`, drive refusé sur `/counter/orders`).
- `tests/e2e/rbac-channel.spec.js` : mêmes scénarios de cloisonnement par canal
  (page de l'autre canal refusée, annulation refusée avant le PIN), sur des
  comptes comptoir/drive auto-provisionnés (pas les comptes de démo), plus le
  rôle sans canal fixe (`admin`) qui garde l'accès aux deux pages.
- `tests/Integration/OrderQueryRepositoryVisibleDbTest.php` : `recentVisible()`
  contre une vraie base (filtre avant la limite, plusieurs sources liées,
  chaîne hostile en paramètre, bornage de la limite).
- Résultats numériques de la vérification manuelle (pile jetable, seed rejoué
  deux fois, connexions, PIN, scénarios ci-dessus en `curl`) : voir le rapport
  de livraison (section « Résultats chiffrés »), pas dupliqués ici pour éviter
  la dérive entre deux copies du même chiffre.
