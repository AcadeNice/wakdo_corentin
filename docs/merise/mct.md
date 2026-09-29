# Modele Conceptuel des Traitements (MCT) — Wakdo

**Phase Merise** : P1 - Conception, etape 3 (apres le MCD)
**Version** : v0.7 — prod-like, machine a 6 etats (+ couche security-by-design 2026-06-11)
**Historique** : v0.3 (2026-09-24) — mise en coherence avec le code livre (2a09597) : CREATE_ORDER (3.3) ne decrit plus que la creation, nouvelle operation PAY_ORDER (3.3ter) pour l'encaissement, CREATE_COUNTER_ORDER (4.1) en deux transactions, DISPLAY_CONFIRMATION (3.4) sur le statut `preparing`, COMPOSE_CART (3.2) sans modificateur d'ingredient cote borne, CANCEL_ORDER (7.1) sur quatre statuts avec re-credit conditionne aux mouvements `sale`, modificateurs d'ingredient envoyes par la saisie comptoir et drive (et non par la borne), role `kitchen` qui marque une commande prete, tableau des operations complete (30 operations), matrice de verification croisee MCT -> MCD (section 15) mise a jour, dont `pin_throttle`. v0.4 (2026-09-28) — audit final sur pieces : `manager` ajoute a CANCEL_ORDER/LIST_ORDERS_DISPLAY/MARK_READY (a `order.read` + `order.cancel` depuis ADR-0020) ; operation `ADJUST` (ajustement libre de stock) et operations RBAC/catalogue manquantes ajoutees (35 operations) ; DELETE_PRODUCT et MANAGE_CATEGORY realignes sur le comportement reel du code (pas de pre-controle PHP listant les blocages, pas de proposition de desactivation en cascade) ; LOAD_CATALOGUE sans controle horaire applicatif ; READ_STATS decrit sur les seuls indicateurs codes ; footnotes de la section 15 renumerotees. v0.5 (2026-09-29) — contre-audit independant (base MariaDB jetable) : RESET_USER_PIN (10.6) corrigee (met `pin_hash` a NULL, pas de PIN de remplacement ; audit `user.update` avec `details={"fields":["pin_hash"]}`, pas `user.reset_pin`) ; SET_OWN_PIN (10.7) corrigee (ecrit bien une ligne `audit_log` `pin.set`) ; CREATE_PRODUCT (8.1) corrigee (categorie EXISTANTE, pas necessairement active) ; DELETE_MENU (8.6) alignee sur l'absence de pre-verification et le message HTTP 409 exact ; MANAGE_CATEGORY (8.7) corrigee (aucune suppression physique, seule la bascule `is_active` existe) ; MANAGE_INGREDIENT (8.8) complete (ecrit `audit_log` `ingredient.allergens`) ; nouvelle section 5.2 MARK_READY (fiche absente malgre la mention dans le tableau et le texte) ; tableau §14 complete de 5 operations absentes (IMPORT... deja present ; ajout de `ENRICH_INGREDIENT`, `REORDER_CATALOGUE`, `TRACK_ORDER_PUBLIC`, `READ_HEALTH`, `EXPIRE_ORDER`) et recompte (40 operations). v0.6 (2026-09-29) — correctif merge apres le contre-audit : UPDATE_MENU (8.5) corrigee, les emplacements sont reconcilies en place (`MenuRepository::reconcileSlots()`) au lieu d'un delete-and-reinsert qui levait une erreur serveur 500 sur un menu deja commande (FK `order_item_selection.menu_slot_id` RESTRICT) ; ajout de l'ERR-1 (409 si un emplacement retire est deja reference). v0.7 (2026-09-29) — deux correctifs de securite merges : AUTHENTICATE_USER (12.1) precisee (session_epoch pose a la connexion ; role_id/session_epoch relus en base a chaque requete ulterieure, RG-T02, commit `ef7fd37`) ; RESET_PASSWORD (12.3) durcie (throttle par adresse et par IP, invalidation des sessions ouvertes via `session_epoch`, commit `ef7fd37`) ; nouvelle entite security-by-design `password_reset_throttle` (24e entite, section 15 note 6). 40 operations inchangees (aucune nouvelle operation MCT, seules des regles de securite existantes sont renforcees).
**Date** : 2026-06-04 (premiere redaction ; derniere mise a jour 2026-09-29)
**Branche** : premiere redaction sur `feat/p1-conception` ; etat actuel sur `docs/contre-audit` (29/09), en production apres la release du 29/09
**Statut** : prod-like — toutes les decisions D1-D8 + stock appliquees (voir `docs/journal/2026-06-04--conception-prodlike-revision.md` pour D1-D3 et `docs/journal/2026-06-04--p1-merise-v0.2-rewrite-and-forgejo-migration.md` pour D4-D8 + stock ; ADR-0020 remplace D5 sur l'annulation) ; operations security-by-design ajoutees (ERASE_USER_PII, RESET_PASSWORD, ensemble sensible protege par PIN, ecritures audit_log, throttling d'authentification) — 40 operations (PAY_ORDER, MARK_READY et ADJUST notamment, voir section 14)
**Auteur** : BYAN (couche methodologie)

---

## 1. Objectif

Le MCT (Modele Conceptuel des Traitements) decrit les **operations metier** du domaine
Wakdo sous la forme canonique Merise : **evenement declencheur -> operation -> resultat emis**.

Il repond a la question : que se passe-t-il dans le domaine, et quand ?
Il ne repond pas a : qui fait quoi, sur quel poste de travail, dans quel ordre organisationnel
(le niveau MOT est volontairement saute — raccourci agile, coherent avec le cadre RNCP
solo).

Le MCT couvre :
- Le cycle de vie de la commande de bout en bout (kiosk, comptoir, drive)
- La gestion du catalogue (manager / admin)
- La gestion des utilisateurs et des roles (admin)
- L'authentification back-office (tous les acteurs back-office)

**Acteurs identifies** :

| Acteur | Code | Interface |
|-------|------|-----------|
| Client (kiosk) | CUSTOMER | Borne tactile (public, non authentifie) |
| Personnel comptoir | COUNTER | Back-office, role `counter` |
| Personnel drive | DRIVE | Back-office, role `drive` |
| Personnel cuisine | KITCHEN | Back-office, role `kitchen` : consulte la file de preparation et marque une commande prete (MARK_READY) ; ne cree, ne remet ni n'annule de commande |
| Manager | MANAGER | Back-office, role `manager` |
| Administrateur | ADMIN | Back-office, role `admin` |
| Systeme | SYS | API interne / logique PHP |

**Reference croisee MCD** : chaque operation reference des entites du MCD (section 14).
Le MCT est coherent avec la machine a etats de `customer_order.status`, realignee le
2026-07-31 sur le code livre (detail transition par transition : `mlt.md` section 14) :

```
pending_payment -> preparing -> ready -> delivered
      |               |            |
      +---------------+------------+-----------> cancelled (from any non-terminal state)
```

`paid` reste dans l'ENUM pour les commandes anterieures au realignement (aucun chemin de
code actuel ne l'ecrit plus : l'encaissement pose directement `preparing`) ; `ready` est une
etape optionnelle (`DELIVER_ORDER` accepte aussi bien `paid`, `preparing` que `ready`).

**Etats de preparation reintroduits** (retour oral #8, migration `0009_order_prep_states.sql`) :
`preparing` et `ready` ne sont PAS supprimes — ils sont livres. La v0.2 de ce document
(juin 2026) les avait retires ; le retour d'usage a montre qu'un KDS purement visuel ne
suffisait pas, l'equipe voulant voir et faire avancer l'etat de preparation plutot que de
deduire un delai depuis `paid_at`. La migration ajoute `preparing`/`ready` a l'ENUM
`customer_order.status` et les horodatages `preparing_at`/`ready_at` (memes conventions que
`paid_at`/`delivered_at`). Le KPI `delivered_at - paid_at` (SLA approx. 10 min) reste mesure
de bout en bout. Detail complet : `mlt.md` section 14 (realignee le 2026-07-31) et
`docs/uml/state-commande.md`.

**Operations** : `MARK_IN_PREPARATION` reste absente comme operation distincte —
l'encaissement pose directement `preparing` (`paid_at` et `preparing_at` dans la meme
transaction), sans etape manuelle intermediaire. `MARK_READY`, en revanche, est reintroduite :
le personnel (ecran cuisine ou comptoir/drive) peut marquer une commande `paid`/`preparing`
comme `ready` avant la remise, geste routinier sans PIN et sans mouvement de stock.
`DELIVER_ORDER` n'est donc plus la seule action faisant avancer le statut pour le personnel ;
elle reste la seule action vers l'etat terminal `delivered` (condition mise a jour en 6.1).

*Note soldee en v0.3 (2026-09-24)* : cette version 0.2 laissait hors perimetre les operations 3.3
(CREATE_ORDER) et 4.1 (CREATE_COUNTER_ORDER), qui decrivaient l'encaissement comme un temps unique
`pending_payment -> paid`. Elles sont desormais realignees : creation en `pending_payment`, puis
PAY_ORDER (3.3ter) vers `preparing`.

**Couche security-by-design (2026-06-11)** : deux operations sont ajoutees — `RESET_PASSWORD` (12.3)
et `ERASE_USER_PII` (10.5, anonymisation RGPD). Un sous-ensemble d'operations est **protege par PIN** :
les sessions back-office restent partagees par poste de travail, mais un PIN par membre du personnel
re-autorise l'ensemble sensible — `CANCEL_ORDER` (7.1), `UPDATE_PRODUCT`/`DELETE_PRODUCT` (8.2/8.3),
`DELETE_MENU` (8.6), `INVENTORY_COUNT` (9.2), `ADJUST` (9.4), gestion des utilisateurs (10.1-10.3), `MANAGE_RBAC`
(10.4), `ERASE_USER_PII` (10.5), `RESET_USER_PIN` (10.6). Ces actions hors stock ajoutent une ligne `audit_log` immuable
(acteur, action, cible) ; les actions de stock enregistrent l'attribution dans `stock_movement`. La logique
de traitement (PIN, audit, throttling, idempotence, decrement atomique du stock, disponibilite produit
calculee) est specifiee dans `mlt.md` (regles RG-T13-T21). Cela ajoute les entites 20 `audit_log`
et 21 `login_throttle` au modele.

---

## 2. Conventions de representation

### Format des operations

```
[TRIGGERING EVENT(S)]
        |
        | [SYNCHRONISATION RULE / CONDITION]
        v
   ( OPERATION )
        |
        v
[EMITTED RESULT(S)]
```

**Synchronisations** :
- `AND` : tous les evenements doivent etre presents simultanement pour declencher l'operation.
- `OR` : l'un quelconque des evenements suffit.

**Conditions** : exprimees entre crochets `[condition]` sur l'arc entrant.

### Notation textuelle

Pour chaque operation, le document fournit :
- **Evenement(s) declencheur(s)** : ce qui survient et provoque l'operation.
- **Acteur(s)** : qui initie (ou valide).
- **Synchronisation** : `AND` / `OR` si plusieurs evenements, plus la condition.
- **Operation** : nom et description de ce qu'elle fait.
- **Entites MCD touchees** : lecture (R) ou ecriture (W).
- **Resultat(s)** : ce qui est emis ou produit.

---

## 3. Domaine 1 — Cycle de vie de la commande (kiosk)

### 3.1 LOAD_CATALOGUE

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | Le client ouvre le kiosk (connexion a l'endpoint du kiosk) |
| **Acteur** | CUSTOMER |
| **Synchronisation** | Aucune (evenement unique) |
| **Condition** | Aucune verification d'horaire cote serveur : `LOAD_CATALOGUE` repond a toute heure. Les horaires annonces (10:00-01:00) ne figurent que dans les donnees structurees SEO (`schema.org` `openingHours`, `src/public/borne/index.html`) ; rien ne ferme le kiosk applicativement en dehors de ces heures. |
| **Operation** | LOAD_CATALOGUE |
| **Description** | Recuperation des categories actives, des produits disponibles et des menus disponibles (avec leurs slots et options eligibles) pour affichage sur l'ecran du kiosk. La disponibilite des produits est CALCULEE : un produit est commandable seulement si son flag `is_available` est positionne ET que chaque ingredient non retirable (`is_removable=0`) de son `product_ingredient` est au-dessus de la bande critique (`stock_quantity > stock_capacity * critical_stock_pct/100`). Voir la regle RG-T21 dans `mlt.md`. |
| **Entites MCD** | R: `category` (is_active=1), `product` (is_available=1), `menu` (is_available=1), `menu_slot`, `menu_slot_option`, `ingredient` (is_active=1), `allergen`, `ingredient_allergen` |
| **Resultat** | Catalogue charge ; le kiosk affiche l'ecran d'accueil |

---

### 3.2 COMPOSE_CART

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | Le client selectionne un produit ou un menu sur le kiosk |
| **Acteur** | CUSTOMER |
| **Synchronisation** | Evenement repetable (OR : ajouter produit, ajouter menu, choisir une taille, modifier quantite, retirer un article, choisir un slot de menu, choisir le format Normal/Maxi, abandonner la commande) |
| **Condition** | Le produit ou le menu selectionne a `is_available=1` |
| **Operation** | COMPOSE_CART |
| **Description** | Construction du panier cote borne : ajouter un article (produit autonome, avec sa quantite et sa taille s'il en a plusieurs, ou menu), selectionner les produits des slots (futurs `order_item_selection`), choisir le format Normal ou Maxi pour les menus, recalculer le total TTC affiche. Le panier est conserve dans le `localStorage` du navigateur (`state.js`) ; aucune ecriture en base a ce stade. Modificateurs d'ingredient : la borne n'en construit pas ; la saisie comptoir et drive en envoie (CREATE_COUNTER_ORDER, 4.1). Le serveur les revalide (`OrderRepository::resolveModifiers`). |
| **Entites MCD** | R: `product`, `menu`, `menu_slot`, `menu_slot_option` — W: aucune (etat cote navigateur) |
| **Resultat** | Panier mis a jour, total recalcule, recapitulatif affiche |

---

### 3.3 CREATE_ORDER

| Champ | Valeur |
|-------|-------|
| **Evenements declencheurs** | Le client touche « Payer » puis choisit un mode de paiement (simule, cadre RNCP). En service sur place, il saisit d'abord le numero de son chevalet. |
| **Acteur** | CUSTOMER |
| **Synchronisation** | AND (paiement choisi ; chevalet saisi si le mode est `dine_in`) |
| **Condition** | Le panier contient au moins 1 article. Le mode de service est valide. |
| **Operation** | CREATE_ORDER |
| **Description** | Premier des deux appels de la borne (`POST /api/orders`). Les prix, la TVA et la disponibilite sont relus en base (RG-T16, RG-T21). Dans une transaction : INSERT `customer_order` au statut `pending_payment`, source `kiosk`, totaux HT/TVA/TTC ; numero genere au format prefixe canal + id (`K<id>`, dictionnaire note 4) ; INSERT des lignes `order_item` avec `label_snapshot`, `unit_price_cents_snapshot`, `vat_rate_snapshot`, et des `order_item_selection` pour les menus. Aucun effet sur le stock. La transaction committe : `pending_payment` est un etat observable. Si la cle d'idempotence est deja connue et porte une commande en attente, le panier modifie remplace ses lignes (MODIFY_PENDING_ORDER, `mlt.md` 3.3bis). |
| **Entites MCD** | R: `product`, `menu`, `menu_slot`, `ingredient`, `product_ingredient` — W: `customer_order` (INSERT `pending_payment`), `order_item`, `order_item_selection` |
| **Resultat** | Commande creee au statut `pending_payment` ; son numero est renvoye a la borne, qui enchaine avec PAY_ORDER |

---

### 3.3ter PAY_ORDER

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | CREATE_ORDER a renvoye le numero de la commande (borne), ou CREATE_COUNTER_ORDER vient de creer la commande (comptoir, drive) |
| **Acteur** | CUSTOMER (borne), COUNTER, DRIVE ou ADMIN (dans la meme requete que la creation, via CREATE_COUNTER_ORDER) |
| **Synchronisation** | Aucune |
| **Condition** | La commande existe et est au statut `pending_payment`. Deja encaissee : renvoi de l'etat reel, sans nouvel effet. Annulee : refus. |
| **Operation** | PAY_ORDER |
| **Description** | Second appel de la borne (`POST /api/orders/{number}/pay`). Dans une transaction : verrou de la ligne de commande (`SELECT ... FOR UPDATE`) et relecture du total, transition `pending_payment -> preparing` avec `paid_at` et `preparing_at` poses ensemble, decrement de `ingredient.stock_quantity` pour chaque ingredient consomme et INSERT d'une ligne `stock_movement` de type `sale` par ingredient. La commande part en cuisine sans geste intermediaire. |
| **Entites MCD** | R: `order_item`, `order_item_modifier`, `product_ingredient` — W: `customer_order` (UPDATE `preparing`, `paid_at`, `preparing_at`), `ingredient` (UPDATE stock_quantity), `stock_movement` (INSERT type `sale`) |
| **Resultat** | Commande au statut `preparing`, visible dans la file de preparation ; stock debite |

---

### 3.4 DISPLAY_CONFIRMATION

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | Reponse de PAY_ORDER (HTTP 200) |
| **Acteur** | SYS |
| **Synchronisation** | Aucune |
| **Condition** | La reponse contient le numero de commande et le montant, au statut `preparing` |
| **Operation** | DISPLAY_CONFIRMATION |
| **Description** | La borne memorise le numero et le montant (`sessionStorage`), vide le panier et affiche `confirmation.html`, sans nouvel appel a l'API. Le client lance ensuite une nouvelle commande. |
| **Entites MCD** | R: aucune (les donnees sont dans la reponse API) |
| **Resultat** | Ecran de confirmation affiche ; kiosk disponible pour la commande suivante |

---

## 4. Domaine 2 — Cycle de vie de la commande (comptoir et drive)

### 4.1 CREATE_COUNTER_ORDER

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | Un membre du personnel comptoir ou drive initie une nouvelle commande depuis le back-office |
| **Acteur** | COUNTER ou DRIVE (ADMIN detient aussi `order.create` et choisit alors explicitement `counter` ou `drive` ; MANAGER n'a pas cette permission) |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur est authentifie et detient la permission `order.create`. La `source` est `counter` ou `drive` (auto-taggee depuis `role.order_source` pour un role a canal fixe ; choisie explicitement par un role sans canal fixe comme ADMIN). |
| **Operation** | CREATE_COUNTER_ORDER |
| **Description** | Composition de la commande sur l'ecran de saisie du back-office : selectionner produits et menus, choisir le mode de service (`dine_in`/`takeaway`/`drive`), remplir les slots de menu, choisir des modificateurs d'ingredient (retirer ou ajouter, `counter-order.js`). Une seule requete, deux transactions : la creation (identique a CREATE_ORDER, statut `pending_payment`) puis PAY_ORDER (statut `preparing`, decrement du stock attribue a l'equipier). La `source` est auto-taggee depuis `role.order_source` (counter -> `counter`, drive -> `drive`). Format du numero de commande : prefixe canal + id (`C<id>` comptoir, `D<id>` drive). Contrainte croisee : si `source = 'drive'` alors `service_mode = 'drive'` (verifie a la creation). |
| **Entites MCD** | R: `product`, `menu`, `menu_slot`, `menu_slot_option`, `ingredient`, `product_ingredient` — W: `customer_order` (INSERT `pending_payment` puis UPDATE `preparing`, `paid_at`, `preparing_at`, `acting_user_id`), `order_item`, `order_item_selection`, `order_item_modifier` (INSERT par modification choisie), `ingredient` (stock decrement), `stock_movement` (INSERT type `sale`) |
| **Resultat** | Commande creee au statut `preparing`, numero de commande communique au client |

---

## 5. Domaine 3 — Affichage de preparation (cuisine)

### 5.1 LIST_ORDERS_DISPLAY

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | Le personnel cuisine accede a l'affichage de preparation ou le rafraichit |
| **Acteur** | KITCHEN (ou COUNTER, DRIVE, MANAGER, ADMIN) — `manager` detient `order.read` depuis ADR-0020 (#176) et voit `/admin/orders` comme `admin` |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur est authentifie et detient la permission `order.read`. |
| **Operation** | LIST_ORDERS_DISPLAY |
| **Description** | Lecture des lignes `customer_order` avec statut `paid`, `preparing` ou `ready` (file active de preparation — `OrderQueryRepository::paidQueue`), filtrees par les sources visibles selon le role de l'acteur (depuis `role_visible_source`) : la cuisine voit toutes les sources ; le comptoir voit kiosk+counter ; le drive voit drive. Les commandes sont triees par `paid_at` ascendant (les plus anciennes en premier). Pour chaque commande, afficher : numero de commande, source, contenu (`order_item` avec `label_snapshot`, `quantity`, format, selections de slots, modificateurs d'ingredient). La couleur KDS est calculee a partir de `now - paid_at` par rapport au seuil de SLA (approx. 10 min), non stockee. LIST_ORDERS_DISPLAY elle-meme est en lecture seule ; le meme ecran cuisine expose separement l'operation `MARK_READY` (5.2), qui, elle, ecrit une transition de statut. |
| **Entites MCD** | R: `customer_order` (status IN (`paid`,`preparing`,`ready`)), `order_item`, `order_item_selection`, `order_item_modifier`, `role_visible_source` |
| **Resultat** | Liste d'affichage de preparation montree, triee par heure de paiement ascendante |

---

### 5.2 MARK_READY

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | Le personnel cuisine (ou comptoir/drive/manager/admin) marque une commande prete depuis l'ecran de preparation |
| **Acteur** | KITCHEN (ou COUNTER, DRIVE, MANAGER, ADMIN) |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur est authentifie et detient la permission `order.read` (pas de permission dediee). La commande est visible pour son role (meme filtre `role_visible_source` que LIST_ORDERS_DISPLAY, controle en defense en profondeur cote serveur, pas seulement cote affichage). Aucun PIN requis (hors ensemble sensible RG-T13) : la transition ne touche pas le stock. |
| **Operation** | MARK_READY |
| **Description** | Transition `paid`/`preparing` -> `ready`, avec `AND status IN ('paid','preparing')` dans le WHERE (RG-T07, protection de concurrence). Positionne `ready_at = NOW()` et `updated_at = NOW()`. Idempotente : une commande deja `ready` renvoie le meme resultat sans nouvelle ecriture ; tout autre statut (`pending_payment`, `delivered`, `cancelled`) est refuse (`INVALID_TRANSITION`). Aucun mouvement de stock, aucune ligne `audit_log` (`OrderAdminController::ready`, `OrderRepository::markReady`, route `POST /admin/orders/{number}/ready`). |
| **Entites MCD** | R: `role_visible_source` — W: `customer_order` (UPDATE status `paid`/`preparing` -> `ready`, `ready_at = NOW()`, si non deja `ready`) |
| **Resultat** | Commande au statut `ready`, visible sur l'ecran de preparation, en attente de DELIVER_ORDER |

---

## 6. Domaine 4 — Livraison au client

### 6.1 DELIVER_ORDER

| Champ | Valeur |
|-------|-------|
| **Evenements declencheurs** | 1. La commande est au statut `paid`, `preparing` ou `ready` AND 2. Le personnel comptoir, drive ou admin clique sur « Livre » |
| **Acteur** | COUNTER, DRIVE ou ADMIN (admin detient `order.deliver` sur les 23 permissions du seed ; manager ne l'a pas) |
| **Synchronisation** | AND |
| **Condition** | La commande a le statut `paid`, `preparing` ou `ready`. L'acteur detient la permission `order.deliver`. Le role de l'acteur est coherent avec la source de la commande (le personnel comptoir traite les commandes kiosk+counter ; le personnel drive traite les commandes drive — filtre par role_visible_source). |
| **Operation** | DELIVER_ORDER |
| **Description** | Transition en geste unique vers `delivered`, depuis `paid`, `preparing` ou `ready`. Positionne `delivered_at = NOW()`. La commande passe en historique. Passer par `ready` est optionnel (MARK_READY, 5.2) : la confirmation visuelle de la cuisine suffit avant cette action, avec ou sans ce jalon intermediaire. |
| **Entites MCD** | W: `customer_order` (UPDATE status `paid`/`preparing`/`ready` -> `delivered`, `delivered_at = NOW()`) |
| **Resultat** | Commande au statut `delivered`, cycle de vie complet |

---

## 7. Domaine 5 — Annulation

### 7.1 CANCEL_ORDER

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | Un acteur autorise demande l'annulation d'une commande |
| **Acteur** | COUNTER, DRIVE, MANAGER ou ADMIN — `manager` detient `order.cancel` depuis la migration `0018` / ADR-0020 (#176), qui remplace la decision D5 sur ce point ; il n'a en revanche ni `order.create` ni `order.deliver` |
| **Synchronisation** | Aucune |
| **Condition** | La commande existe. `customer_order.status` est dans `['pending_payment', 'paid', 'preparing', 'ready']`. Les statuts terminaux `delivered` et `cancelled` ne peuvent pas transiter vers `cancelled`. L'acteur detient la permission `order.cancel` et saisit l'email et le PIN d'un equipier actif avec la demande (RG-T13). |
| **Operation** | CANCEL_ORDER |
| **Description** | Transition du statut courant vers `cancelled`. Positionne `cancelled_at = NOW()`. La commande est conservee en base pour l'historique et les stats (pas de suppression physique). Si des mouvements `sale` existent pour la commande (elle a ete encaissee), le stock est recredite : pour chaque ingredient consomme, `ingredient.stock_quantity` est incremente dans la limite de `stock_capacity` et une ligne `stock_movement` de type `cancellation` est inseree par ingredient. Une ligne `audit_log` (`order.cancel`) rattache l'annulation a l'equipier identifie par son PIN. Le recredit, la mise a jour du statut et la trace sont dans la meme transaction. Un PIN refuse ecrit `pin.failed` dans `audit_log` et incremente `pin_throttle`, sans toucher a la commande. |
| **Entites MCD** | R: `order_item`, `order_item_modifier`, `ingredient`, `product_ingredient` — W: `customer_order` (UPDATE status -> `cancelled`, `cancelled_at = NOW()`), `ingredient` (UPDATE stock_quantity, si des mouvements `sale` existent), `stock_movement` (INSERT type `cancellation`, meme condition), `audit_log` (INSERT `order.cancel`) |
| **Resultat** | Commande au statut `cancelled`, visible dans l'historique admin ; message puis retour a la liste des commandes |

---

## 8. Domaine 6 — Gestion du catalogue

### 8.1 CREATE_PRODUCT

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | L'admin ou le manager soumet le formulaire de creation de produit |
| **Acteur** | ADMIN ou MANAGER |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur detient la permission `product.create`. La categorie cible existe (`ProductRepository::categoryExists`, simple existence — une categorie desactivee reste une cible valide, sans controle de `is_active`). `name` est non vide. `price_cents > 0`. |
| **Operation** | CREATE_PRODUCT |
| **Description** | INSERT d'un nouveau `product` avec sa categorie, son nom, son prix en centimes, son taux de TVA en pour-mille (`vat_rate` : 100=10%, 55=5.5%, defaut 100), chemin d'image optionnel. `is_available=1` par defaut. |
| **Entites MCD** | R: `category` (FK validation) — W: `product` (INSERT) |
| **Resultat** | Produit cree, redirection vers la liste des produits |

---

### 8.2 UPDATE_PRODUCT

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | L'admin ou le manager soumet le formulaire de modification de produit |
| **Acteur** | ADMIN ou MANAGER |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur detient la permission `product.update`. Le produit existe. Les nouvelles valeurs respectent les contraintes (`price_cents > 0`, nom non vide). |
| **Operation** | UPDATE_PRODUCT |
| **Description** | UPDATE des colonnes modifiables (`name`, `description`, `price_cents`, `vat_rate`, `image_path`, `is_available`, `display_order`, `category_id`). Les snapshots deja stockes dans `order_item` ne sont pas affectes (integrite historique garantie par conception). Le PIN et l'ecriture `audit_log` (voir security-by-design, section 1) ne sont exiges QUE si `price_cents` ou `vat_rate` changent ; un changement de nom, description, image ou disponibilite passe sans PIN ni trace. |
| **Entites MCD** | W: `product` (UPDATE) |
| **Resultat** | Produit mis a jour, liste des produits rafraichie |

---

### 8.3 DELETE_PRODUCT

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | L'admin confirme la suppression d'un produit |
| **Acteur** | ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur detient la permission `product.delete`. Le produit existe. |
| **Operation** | DELETE_PRODUCT |
| **Description** | Suppression physique tentee directement (`ProductRepository::delete`), sans pre-controle applicatif listant les menus ou commandes bloquants : c'est la contrainte FK `ON DELETE RESTRICT` (`menu_slot_option.product_id`, `order_item.product_id`, `menu.burger_product_id`) qui refuse seule la suppression. L'exception PDO (SQLSTATE 23000) est interceptee et rendue en un message generique invitant a masquer le produit plutot qu'a le supprimer (`ProductController::destroy`) ; `product_ingredient` (recette) est en CASCADE et part avec le produit, sans jouer de role bloquant. |
| **Entites MCD** | W: `product` (DELETE — bloque par FK RESTRICT sur `menu_slot_option`, `order_item`, `menu.burger_product_id` ; CASCADE sur `product_ingredient`) |
| **Resultat** | Produit supprime OU message « produit reference, suppression impossible » (HTTP 409) |

---

### 8.4 CREATE_MENU

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | L'admin ou le manager soumet le formulaire de creation de menu avec sa configuration de slots |
| **Acteur** | ADMIN ou MANAGER |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur detient la permission `menu.create`. `name` est non vide. `price_normal_cents > 0`, `price_maxi_cents > 0`. `burger_product_id` reference un produit existant. Au moins un slot est defini avec au moins une option. |
| **Operation** | CREATE_MENU |
| **Description** | Transaction : INSERT `menu` (avec `burger_product_id`, `price_normal_cents`, `price_maxi_cents`), puis INSERT des lignes `menu_slot` (une par slot : boisson, accompagnement, sauce...), puis INSERT des lignes `menu_slot_option` (produits eligibles par slot). |
| **Entites MCD** | R: `product` (burger FK validation, slot options validation), `category` — W: `menu` (INSERT), `menu_slot` (INSERT), `menu_slot_option` (INSERT) |
| **Resultat** | Menu cree avec sa configuration de slots, visible sur le kiosk |

---

### 8.5 UPDATE_MENU

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | L'admin ou le manager soumet le formulaire de modification de menu |
| **Acteur** | ADMIN ou MANAGER |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur detient la permission `menu.update`. Le menu existe. La configuration mise a jour preserve au moins un slot avec au moins une option. |
| **Operation** | UPDATE_MENU |
| **Description** | UPDATE des colonnes `menu`. Si la configuration des slots est modifiee : les emplacements sont RECONCILIES EN PLACE (`reconcileSlots()`, corrige le 2026-09-29 — voir Erratum ci-dessous), et non plus supprimes puis reinseres : un emplacement soumis est apparie a un emplacement existant du meme `slot_type`, d'abord par NOM identique puis, pour le reste, par POSITION (depuis `e9f00d8`, 29/09 ; lignes lues en `FOR UPDATE`) — UPDATE de ses champs + remplacement total de ses options, un emplacement soumis sans homologue est INSERT, un emplacement existant sans homologue soumis et sans reference dans `order_item_selection` est DELETE. Le tout dans une transaction. Les snapshots dans `order_item` ne sont pas affectes. |
| **Entites MCD** | R : `order_item_selection` (verifie qu'un emplacement retire n'y est pas deja reference, avant toute ecriture sur les emplacements) — W: `menu` (UPDATE), `menu_slot` (UPDATE et/ou INSERT et/ou DELETE), `menu_slot_option` (remplacement total par emplacement apparie ou nouveau) |
| **Resultat** | Menu mis a jour |
| **ERR-1** | Un emplacement retire de la configuration soumise est deja reference par `order_item_selection` (FK `ON DELETE RESTRICT`) : `MenuSlotInUseException`, levee avant toute ecriture sur les emplacements ; l'UPDATE de `menu`, execute juste avant dans la meme transaction (`MenuRepository::update()`), est annule par le rollback. Une commande concurrente qui reference l'emplacement entre ce controle et le DELETE produit la meme exception (violation 23000 traduite, `e9f00d8`). HTML -> formulaire reaffiche avec le message sous le constructeur d'emplacements, HTTP 409 ; API -> `409 CONFLICT`. Rien ne reste ecrit. |

**Erratum (2026-09-29, contre-audit independant)** : avant cette date, `update()` reconstruisait
systematiquement les emplacements en delete-and-reinsert (DELETE de `menu_slot_option`, DELETE de
`menu_slot`, puis re-INSERT). Cela declenchait une erreur SQL non interceptee (SQLSTATE 23000, FK
`order_item_selection.menu_slot_id` en `RESTRICT`) — donc une erreur serveur 500 — des qu'un menu
deja commande au moins une fois avec une selection d'emplacement etait modifie ; reproduit par
`MenuRepositoryDbTest` (base reelle) avant correction. Le correctif (`MenuRepository::reconcileSlots()`,
`MenuSlotInUseException`) est decrit dans la Description et l'ERR-1 ci-dessus.

---

### 8.6 DELETE_MENU

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | L'admin confirme la suppression d'un menu |
| **Acteur** | ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur detient la permission `menu.delete` et s'est re-autorise par PIN (RG-T13). Le menu existe. Aucune pre-verification applicative des references : la suppression est tentee directement, c'est la contrainte FK `ON DELETE RESTRICT` (`order_item.menu_id`) qui refuse seule si le menu est reference (`MenuController::destroy`, meme pattern que DELETE_PRODUCT 8.3). |
| **Operation** | DELETE_MENU |
| **Description** | DELETE `menu` tente directement (dans une transaction avec l'ecriture `audit_log`) ; `menu_slot`/`menu_slot_option` sont en CASCADE et partent avec le menu. Si la FK `order_item.menu_id` (RESTRICT) bloque (SQLSTATE 23000), l'exception est interceptee et rendue en HTTP 409 avec le message exact « Menu référencé par des commandes : suppression impossible. Désactivez-le plutôt. » (`MenuController::destroy`) ; aucune desactivation automatique n'est declenchee, le message invite seulement l'acteur a le faire lui-meme via MANAGE_CATEGORY/le bouton dedie. |
| **Entites MCD** | W: `menu_slot_option` (DELETE CASCADE), `menu_slot` (DELETE CASCADE), `menu` (DELETE — bloque par FK RESTRICT sur `order_item.menu_id`), `audit_log` (INSERT `menu.delete`, si suppression effective) |
| **Resultat** | Menu supprime OU HTTP 409 « Menu référencé par des commandes : suppression impossible. Désactivez-le plutôt. » |

---

### 8.7 MANAGE_CATEGORY

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | L'admin ou le manager cree, modifie ou desactive une categorie |
| **Acteur** | ADMIN ou MANAGER |
| **Synchronisation** | OR (creation, modification, desactivation) |
| **Condition** | L'acteur detient la permission `category.manage`. Pour la desactivation : les produits et menus de la categorie ne sont pas auto-desactives en base (pas de CASCADE sur `is_active`) ; la couche applicative NE propose PAS de les desactiver a la volee (`CategoryController::toggle` bascule `is_active` et affiche un message, sans autre effet). |
| **Operation** | MANAGE_CATEGORY |
| **Description** | CRUD sur `category`, sans route de suppression physique : le back-office HTML n'expose pas de suppression de categorie, et `DELETE /admin/api/categories/{id}` (`CategoryApiController::apiDestroy`) se limite a basculer `is_active=0`, de facon idempotente (une categorie deja masquee le reste, sans erreur) — le verbe HTTP DELETE bascule un drapeau, il n'efface pas la ligne. La desactivation masque la categorie du kiosk ; ses produits/menus restent `is_available=1` en base mais deviennent invisibles cote kiosk (le filtre `category.is_active=1` de LOAD_CATALOGUE les masque implicitement). |
| **Entites MCD** | W: `category` (INSERT / UPDATE / UPDATE `is_active` — pas de DELETE code) |
| **Resultat** | Categorie creee / modifiee / masquee (la suppression physique n'est pas implementee) |

---

### 8.8 MANAGE_INGREDIENT

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | L'admin ou le manager cree, modifie ou desactive un ingredient ; ou gere la composition produit (`product_ingredient`) ou le mapping allergene (`ingredient_allergen`) |
| **Acteur** | ADMIN ou MANAGER |
| **Synchronisation** | OR (creer ingredient, modifier ingredient, modifier composition, modifier mapping allergene) |
| **Condition** | L'acteur detient la permission `ingredient.manage`. |
| **Operation** | MANAGE_INGREDIENT |
| **Description** | CRUD sur `ingredient` (name, unit, pack_size, pack_label, stock_capacity, low_stock_pct, critical_stock_pct, is_active). Gestion de la composition `product_ingredient` (quantity_normal, quantity_maxi, is_removable, is_addable, extra_price_cents) pour tout produit. Gestion du mapping `ingredient_allergen` (14 allergenes reglementes UE) : la sauvegarde re-ecrit `ingredient_allergen` pour l'ingredient, pose `ingredient.allergens_reviewed_at`/`allergens_source`, et ajoute dans la MEME transaction une ligne `audit_log` (`action_code='ingredient.allergens'`) — seule sous-operation de 8.8 qui journalise (`IngredientRepository::setAllergens`, `IngredientRepository.php:228`). Desactiver un ingredient (`is_active=0`) le masque du configurateur sans suppression. La suppression physique de `ingredient` est bloquee s'il est reference dans `product_ingredient` (FK `ON DELETE RESTRICT`) ou `stock_movement` (FK `ON DELETE RESTRICT`). |
| **Entites MCD** | R: `product` (FK validation), `allergen` (FK validation) — W: `ingredient` (INSERT/UPDATE/DELETE conditional), `product_ingredient` (INSERT/UPDATE/DELETE), `ingredient_allergen` (INSERT/DELETE), `audit_log` (INSERT `ingredient.allergens`, seulement pour le mapping allergene) |
| **Resultat** | Ingredient / composition / mapping allergene mis a jour |

---

### 8.9 IMPORT_PRODUCTS (import CSV)

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | L'admin ou le manager depose un fichier CSV de produits (formulaire d'import, 2 temps : apercu puis confirmation) |
| **Acteur** | ADMIN ou MANAGER |
| **Synchronisation** | Sequentielle : `importPreview` (analyse seule) puis `importConfirm` (ecriture) |
| **Condition** | L'acteur detient la permission `product.create`. Le CSV est integralement revalide a la confirmation (defense contre un etat perime entre les deux temps). Si le fichier modifie au moins un prix, le PIN propre a l'equipier est requis (meme regle que UPDATE_PRODUCT) ; sinon aucun PIN. |
| **Operation** | IMPORT_PRODUCTS |
| **Description** | `ProductImportService::apply` cree ou met a jour des `product` (et les `ingredient`/`product_ingredient` que le fichier reference) en UNE transaction (tout ou rien). Une ligne `audit_log` (`action_code='product.import'`, `entity_type='product'`) est ecrite au succes dans tous les cas, qu'un prix ait change ou non, avec un resume chiffre (crees/mis a jour/inchanges/ingredients crees). Routes : `GET /admin/products/import`, `POST /admin/products/import/preview`, `POST /admin/products/import/confirm`, `POST /admin/api/products/import`. |
| **Entites MCD** | R: `category`, `product`, `ingredient` — W: `product` (INSERT/UPDATE), `ingredient` (INSERT), `product_ingredient` (INSERT/UPDATE/DELETE), `audit_log` (INSERT) |
| **Resultat** | Produits crees/mis a jour en bloc ; une ligne `audit_log` enregistree |

---

## 9. Domaine 7 — Gestion du stock

### 9.1 RESTOCK

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | Le manager ou l'admin enregistre une livraison de packs d'ingredient |
| **Acteur** | MANAGER ou ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur detient la permission `stock.manage`. L'ingredient existe et `is_active=1`. Nombre de packs `N >= 1`. |
| **Operation** | RESTOCK |
| **Description** | UPDATE `ingredient.stock_quantity += N * pack_size`, PLAFONNE a `stock_capacity` (migration `0008`, `IngredientRepository::clampToCapacity` : un stock ne depasse pas 100 % de sa reference). INSERT d'une ligne `stock_movement` : type `restock`, delta = le montant reellement applique apres plafonnement (peut etre inferieur a la demande brute si l'ingredient est deja pres du plein), `user_id` de l'acteur, `note` optionnelle (ex. reference de livraison). Les deux ecritures sont dans la meme transaction. Sans PIN : `user_id` est l'acteur de la session (`stock.manage`), pas un acteur resolu par PIN. |
| **Entites MCD** | R: `ingredient` — W: `ingredient` (UPDATE stock_quantity, plafonne), `stock_movement` (INSERT type `restock`, delta applique) |
| **Resultat** | Stock incremente (dans la limite de la capacite), mouvement journalise |

---

### 9.2 INVENTORY_COUNT

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | Un membre du personnel ou un manager enregistre le resultat d'un inventaire physique |
| **Acteur** | KITCHEN, COUNTER, DRIVE, MANAGER ou ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur detient la permission `stock.count`. PIN equipier obligatoire (RG-T13) : l'equipier resolu par le PIN est l'acteur enregistre (`stock_movement.user_id`), pas le compte de session. L'ingredient existe. Comptage physique `actual_quantity >= 0`. |
| **Operation** | INVENTORY_COUNT |
| **Description** | Calcul de `delta = actual_quantity - ingredient.stock_quantity` (peut etre negatif ou positif). UPDATE `ingredient.stock_quantity = actual_quantity`, PLAFONNE a `stock_capacity` (meme `clampToCapacity` que RESTOCK, migration `0008`) : un comptage physique superieur a la capacite configuree est retenu a la capacite. INSERT d'une ligne `stock_movement` : type `inventory_correction`, delta = l'ecart reellement applique (apres plafonnement), `user_id` de l'acteur. Les deux ecritures dans la meme transaction. |
| **Entites MCD** | R: `ingredient` (read current stock_quantity) — W: `ingredient` (UPDATE stock_quantity, plafonne), `stock_movement` (INSERT type `inventory_correction`, delta applique) |
| **Resultat** | Stock reconcilie au comptage physique, ecart journalise |

---

### 9.3 READ_STOCK

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | Un acteur autorise accede a la vue du stock |
| **Acteur** | KITCHEN, COUNTER, DRIVE, MANAGER ou ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur detient la permission `stock.read`. |
| **Operation** | READ_STOCK |
| **Description** | Lecture de la liste `ingredient` (actifs ET inactifs : `IngredientRepository::all()` ne filtre pas sur `is_active`, a la difference du catalogue kiosk) avec le `stock_quantity` courant, `stock_capacity`, `stock_pct` calcule, `low_stock_pct`, `critical_stock_pct`, `pack_size`, `pack_label`. Bandes de stock calculees au moment de l'affichage : `low_stock` lorsque `stock_quantity <= stock_capacity * low_stock_pct/100`, `critical_stock` lorsque `stock_quantity <= stock_capacity * critical_stock_pct/100`. Historique des mouvements pour un ingredient donne : les 50 plus recents, du plus recent au plus ancien, sans filtre de dates (`IngredientRepository::movements`, `GET /admin/ingredients/{id}/movements` et `GET /admin/api/ingredients/{id}/movements`, `stock.read`). |
| **Entites MCD** | R: `ingredient`, `stock_movement` (historique borne aux 50 dernieres lignes) |
| **Resultat** | Liste du stock affichee (actifs et inactifs) avec indicateurs de stock bas ; historique des mouvements sur demande |

---

### 9.4 ADJUST (ajustement libre de stock)

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | Un membre du personnel corrige le niveau de stock d'un ingredient hors flux de reappro/inventaire (ex. casse constatee, erreur de saisie a reprendre) |
| **Acteur** | KITCHEN, COUNTER, DRIVE, MANAGER ou ADMIN (protege par PIN) |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur detient la permission `stock.count`. L'ingredient existe. Le delta signe est non nul. L'acteur s'est re-autorise par PIN (RG-T13, comme l'inventaire — une baisse non attribuee masquerait de la demarque). |
| **Operation** | ADJUST |
| **Description** | UPDATE `ingredient.stock_quantity += delta` (delta positif ou negatif), PLAFONNE a `stock_capacity` (`clampToCapacity`, migration `0008`, meme regle que RESTOCK/INVENTORY_COUNT). INSERT d'une ligne `stock_movement` : type `adjustment`, delta = le montant reellement applique apres plafonnement, `user_id` de l'equipier resolu par PIN. Les deux ecritures dans la meme transaction. Pas de ligne `audit_log` au succes (comme l'inventaire, la trace `stock_movement` suffit). Routes : `GET`/`POST /admin/ingredients/{id}/adjust`, `POST /admin/api/ingredients/{id}/adjust` (migration `0010` pour le type `adjustment`). |
| **Entites MCD** | R: `ingredient` — W: `ingredient` (UPDATE stock_quantity, plafonne), `stock_movement` (INSERT type `adjustment`, delta applique) |
| **Resultat** | Stock corrige (dans la limite de la capacite), mouvement journalise et attribue |

---

### 9.5 SET_STOCK_THRESHOLDS (reglage des seuils)

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | Un manager ou l'admin ajuste les seuils d'alerte de stock d'un ingredient |
| **Acteur** | MANAGER ou ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur detient la permission `stock.manage`. L'ingredient existe. `low_stock_pct` et `critical_stock_pct` dans 0-100 avec `critical_stock_pct < low_stock_pct`. |
| **Operation** | SET_STOCK_THRESHOLDS |
| **Description** | UPDATE `ingredient.stock_capacity`, `low_stock_pct`, `critical_stock_pct` (`IngredientRepository::updateThresholds`, `POST /admin/ingredients/{id}/thresholds`, `PUT /admin/api/ingredients/{id}/thresholds`). Sans PIN ni ecriture `audit_log` : ce n'est pas un mouvement de stock, seulement un parametrage des bandes d'alerte (RG-T21) utilisees par LOAD_CATALOGUE/READ_STOCK. |
| **Entites MCD** | W: `ingredient` (UPDATE stock_capacity, low_stock_pct, critical_stock_pct) |
| **Resultat** | Seuils mis a jour, bandes de stock recalculees a l'affichage suivant |

---

## 10. Domaine 8 — Gestion des utilisateurs et des roles (admin)

### 10.1 CREATE_USER

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | L'admin soumet le formulaire de creation d'utilisateur |
| **Acteur** | ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur detient la permission `user.create`. L'email n'existe pas deja dans `user.email` (contrainte UNIQUE). Un `role_id` valide et actif est selectionne. |
| **Operation** | CREATE_USER |
| **Description** | INSERT de l'utilisateur avec un hash de mot de passe argon2id. L'email est unique. `role_id` est obligatoire (FK NOT NULL). `is_active=1` par defaut. `last_login_at=NULL` a la creation. |
| **Entites MCD** | R: `role` (FK validation) — W: `user` (INSERT) |
| **Resultat** | Utilisateur cree, peut se connecter au back-office |

---

### 10.2 UPDATE_USER

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | L'admin soumet le formulaire de modification d'utilisateur |
| **Acteur** | ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur detient la permission `user.update`. L'utilisateur existe. Si un nouveau mot de passe est fourni, il est re-hashe. |
| **Operation** | UPDATE_USER |
| **Description** | UPDATE des champs modifiables (`first_name`, `last_name`, `email`, `role_id`, `is_active`). Si un nouveau mot de passe est fourni, il remplace le hash existant (rehash argon2id). |
| **Entites MCD** | W: `user` (UPDATE) |
| **Resultat** | Utilisateur mis a jour |

---

### 10.3 DEACTIVATE_USER

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | L'admin clique sur « Desactiver » pour un utilisateur |
| **Acteur** | ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur detient la permission `user.deactivate`. L'admin ne peut pas desactiver son propre compte (protection au niveau applicatif). |
| **Operation** | DEACTIVATE_USER |
| **Description** | UPDATE `is_active=0`. La session active de l'utilisateur est invalidee au prochain acces (le middleware verifie `is_active=1` a chaque requete authentifiee). L'utilisateur n'est pas supprime ; l'historique reste tracable. |
| **Entites MCD** | W: `user` (UPDATE is_active=0) |
| **Resultat** | Utilisateur desactive, acces back-office bloque |

---

### 10.4 MANAGE_RBAC

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | L'admin modifie les affectations de permissions pour un role, ou cree / modifie un role personnalise |
| **Acteur** | ADMIN |
| **Synchronisation** | OR (modifier les permissions du role, creer un role personnalise, modifier les attributs du role) |
| **Condition** | L'acteur detient la permission `role.manage`. Les permissions selectionnees existent dans le catalogue `permission`. |
| **Operation** | MANAGE_RBAC |
| **Description** | Mise a jour de `role_permission` pour un role donne : DELETE des affectations existantes, INSERT des nouvelles (delete-and-reinsert, atomique en transaction). Les permissions elles-memes sont statiques (declarees en migration, non modifiables via l'UI). Couvre egalement : CREATE/UPDATE d'un `role` personnalise (code, label, description, default_route, order_source), UPDATE de `role_visible_source` (sources de tableau de bord visibles pour le role). Regle d'architecture RBAC : le code applicatif teste les permissions, pas les noms de role — ajouter un nouveau role avec les bonnes permissions ne requiert aucun changement de code. |
| **Entites MCD** | R: `role`, `permission` — W: `role_permission` (DELETE + INSERT), `role` (INSERT/UPDATE for custom roles), `role_visible_source` (INSERT/DELETE) |
| **Resultat** | Matrice RBAC mise a jour, effective immediatement pour les nouvelles requetes des utilisateurs porteurs de ce role |

---

### 10.5 ERASE_USER_PII (security-by-design)

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | Une demande d'effacement RGPD est traitee pour un utilisateur back-office |
| **Acteur** | ADMIN (protege par PIN) |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur detient la permission `user.update` et s'est re-autorise via PIN. L'utilisateur cible existe et n'est pas deja anonymise. |
| **Operation** | ERASE_USER_PII |
| **Description** | Le droit a l'effacement RGPD est honore par **anonymisation**, non par suppression physique : les PII (`email`, `first_name`, `last_name`) sont effacees/remplacees par un placeholder non identifiant, les identifiants invalides, `anonymized_at` positionne. La ligne persiste afin que les liens referentiels (`stock_movement`, `customer_order`, `audit_log`) restent valides et se resolvent vers un principal anonymise. Voir `mlt.md` 10.5 et la note 13 du dictionnaire. |
| **Entites MCD** | W: `user` (UPDATE — PII cleared, `anonymized_at` set), `audit_log` (INSERT) |
| **Resultat** | Utilisateur anonymise ; PII supprimees ; liens d'imputabilite preserves ; une ligne `audit_log` enregistree |

---

### 10.6 RESET_USER_PIN (admin, security-by-design)

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | Un equipier a oublie ou souhaite changer son PIN ; l'admin le reinitialise depuis la fiche utilisateur |
| **Acteur** | ADMIN (protege par PIN) |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur detient la permission `user.update` et s'est re-autorise par PIN (RG-T13 : reinitialiser le PIN d'un tiers est une action sensible). L'utilisateur cible existe. |
| **Operation** | RESET_USER_PIN |
| **Description** | UPDATE `user.pin_hash = NULL` (`UserRepository::clearPin`) : l'admin ne pose pas de PIN de remplacement (il n'a pas a connaitre le PIN d'autrui), il efface seulement l'existant, forcant l'equipier a en choisir un nouveau via SET_OWN_PIN (10.7) a sa prochaine action sensible. Une ligne `audit_log` est ecrite dans la meme transaction avec `action_code='user.update'` (pas un code `user.reset_pin` dedie — ce code n'existe pas dans le code livre), `entity_type='user'`, `details={"fields":["pin_hash"]}` (RG-T14 : noms de champs modifies, pas la valeur). Routes : `GET`/`POST /admin/users/{id}/reset-pin` (`UserController::resetPin`), `POST /admin/api/users/{id}/reset-pin` (`UserApiController`). |
| **Entites MCD** | W: `user` (UPDATE pin_hash), `audit_log` (INSERT) |
| **Resultat** | PIN de l'equipier cible reinitialise ; une ligne `audit_log` enregistree |

---

### 10.7 SET_OWN_PIN (tout equipier back-office)

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | Un equipier back-office choisit ou change son propre PIN d'action sensible |
| **Acteur** | COUNTER / DRIVE / KITCHEN / MANAGER / ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | Une session valide est ouverte (aucune permission specifique requise : tout compte actif peut poser son propre PIN). La confirmation exige une re-authentification par MOT DE PASSE (pas par l'ancien PIN, qui peut etre absent ou oublie). |
| **Operation** | SET_OWN_PIN |
| **Description** | UPDATE `user.pin_hash` (argon2id) pour le compte de SESSION uniquement (`GET`/`POST /admin/profile/pin`, sans permission dediee, `reauth: password`). Cette action n'est pas dans l'ensemble sensible RG-T13 (elle EST le mecanisme qui l'alimente), mais elle ecrit bien une ligne `audit_log` (`action_code='pin.set'`, `entity_type='user'`, `entity_id`=l'utilisateur de session, aucune valeur sensible dans le `summary`) juste apres l'UPDATE, hors transaction (`ProfileController::writePinAudit`) ; le libelle distingue premiere definition et changement sans le tracer dans le code, seulement dans le texte du `summary`. |
| **Entites MCD** | W: `user` (UPDATE pin_hash, sur son propre compte), `audit_log` (INSERT `pin.set`) |
| **Resultat** | PIN personnel pose ou change, utilisable des la prochaine action sensible |

---

## 11. Domaine 9 — Stats et KPI

### 11.1 READ_STATS

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | Le manager ou l'admin accede au tableau de bord des stats |
| **Acteur** | MANAGER ou ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur detient la permission `stats.read`. |
| **Operation** | READ_STATS |
| **Description** | Ce qui est reellement code (`OrderQueryRepository::salesKpis`/`salesByDay`, `StatsRepository::counts`/`stockHealth`, `StatsController`) : chiffre d'affaires (TTC) encaisse sur les statuts `paid`/`preparing`/`ready`/`delivered` (exclut `pending_payment` et `cancelled`), nombre de commandes encaissees, panier moyen, CA et nombre du JOUR (`created_at >= CURDATE()`), repartition par statut, serie quotidienne `salesByDay` (7 jours par defaut, CA + nombre de commandes par `DATE(created_at)`) ; compteurs de catalogue (produits/categories/menus/ingredients, total + actif-disponible, dont la disponibilite calculee RG-T21) et sante du stock (repartition par bande + liste d'alerte triee du plus critique au moins critique). Non realises (absents du code) : coupure `service_day` a 10:00, top produits, taux d'annulation, temps de remise moyen, ventilation par `source`/`service_mode` — a ne pas presenter comme livres. |
| **Entites MCD** | R: `customer_order`, `order_item`, `category`, `product`, `menu`, `ingredient` |
| **Resultat** | Tableau de bord des stats affiche (CA, paniers, repartition par statut, serie 7 jours, sante catalogue/stock) |

---

## 12. Domaine 10 — Authentification back-office

### 12.1 AUTHENTICATE_USER

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | Un acteur soumet le formulaire de connexion |
| **Acteur** | COUNTER / DRIVE / KITCHEN / MANAGER / ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | Le compte n'est pas dans une fenetre de throttling (`lockout_until`). L'email existe en base. Le mot de passe correspond au hash argon2id. L'utilisateur `is_active=1`. |
| **Operation** | AUTHENTICATE_USER |
| **Description** | Verification des identifiants. Si valide : regeneration de l'ID de session (protection contre la fixation de session), stockage de `user_id`, `role_id` et `session_epoch` (valeur lue en base a cet instant) en session, UPDATE `last_login_at`, remise a zero du compteur d'echecs de connexion. En cas d'echec : incrementation de `failed_login_attempts` et application d'un backoff degressif (`lockout_until`), erreur generique resistante a l'enumeration. Idle timeout : 4h. Absolute timeout : 10h. A CHAQUE requete authentifiee ulterieure (pas seulement a la connexion), `is_active`, `role_id` et `session_epoch` sont relus en base et compares a la session (RG-T02, corrige le 2026-09-29, commit `ef7fd37`) : avant cette date, seul `is_active` etait ainsi revalide, `role_id` restant celui pose a la connexion jusqu'a une reconnexion. Redirection vers `role.default_route`. Voir `mlt.md` 12.1. |
| **Entites MCD** | R: `user` (verification), `role` (load permissions, default_route), `role_permission`, `login_throttle` (the per-IP throttle gate) — W: `user` (UPDATE last_login_at, `failed_login_attempts`, `lockout_until`), `login_throttle` (upsert `failed_attempts`/`lockout_until` on failure, clear on success), `audit_log` (INSERT login success/failure) |
| **Resultat** | Session ouverte, redirection vers la vue par defaut specifique au role ; ou echec throttle journalise |

---

### 12.2 LOGOUT_USER

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | L'acteur clique sur « Deconnexion » OU la session expire |
| **Acteur** | COUNTER / DRIVE / KITCHEN / MANAGER / ADMIN / SYS (expiration) |
| **Synchronisation** | OR |
| **Condition** | Une session valide est ouverte |
| **Operation** | LOGOUT_USER |
| **Description** | Destruction de la session PHP (`session_destroy()`). Session supprimee cote serveur. Cookie de session invalide. |
| **Entites MCD** | Aucune ecriture en base (la gestion des sessions est en PHP natif, hors base pour ce projet) |
| **Resultat** | Session detruite, redirection vers la page de connexion |

---

### 12.3 RESET_PASSWORD (security-by-design)

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | Un utilisateur demande une reinitialisation de mot de passe, puis la confirme via le lien envoye par email |
| **Acteur** | COUNTER / DRIVE / KITCHEN / MANAGER / ADMIN |
| **Synchronisation** | Sequentielle en deux phases : demande, puis confirmation |
| **Condition** | Demande : l'adresse et l'IP source ne sont pas verrouillees (`password_reset_throttle`, ajoute le 2026-09-29, commit `ef7fd37`) ; l'email soumis est traite de maniere resistante a l'enumeration (meme reponse neutre qu'il existe ou non, verrou compris). Confirmation : un token valide et non expire est presente. |
| **Operation** | RESET_PASSWORD |
| **Description** | La phase de demande verifie d'abord le throttle par adresse ET par IP (rejet neutre au-dela du seuil), puis genere un token aleatoire, stocke son hash + expiration, et envoie le token brut une seule fois par email. La phase de confirmation valide le hash du token + expiration, remplace `password_hash` (argon2id), efface le token, remet a zero le compteur d'echecs de connexion et incremente `user.session_epoch` : toute session deja ouverte du compte devient invalide des la requete suivante (RG-T02). Voir `mlt.md` 12.3. |
| **Entites MCD** | R: `password_reset_throttle` (verouillage par adresse/IP) — W: `user` (UPDATE `password_reset_token_hash` + `password_reset_expires_at` on request; UPDATE `password_hash`, clear token, reset `failed_login_attempts`/`lockout_until`, increment `session_epoch` on confirm), `password_reset_throttle` (upsert par adresse et par IP), `audit_log` (INSERT) |
| **Resultat** | Mot de passe reinitialise via un token a usage unique et a duree limitee ; une ligne `audit_log` enregistree ; sessions ouvertes du compte invalidees |

---

## 13. Machine a etats — customer_order.status

Recapitulatif des transitions couvertes par les operations MCT. **Realigne le 2026-07-31**
sur le code livre (migration `0009_order_prep_states.sql`) ; detail transition par
transition (conditions SQL, protection de concurrence, horodatages) dans `mlt.md` section 14
et `docs/uml/state-commande.md`.

```
               [CUSTOMER / COUNTER / DRIVE]
               CREATE_ORDER
               CREATE_COUNTER_ORDER
                      |
                      v
           [ pending_payment ]  (commande composee, encaissement en attente)
                      |
    [CUSTOMER / COUNTER / DRIVE] PAY_ORDER (encaissement)
    (paid_at ET preparing_at poses ensemble)
                      |
                      v
                [ preparing ]
                      |
      [personnel] MARK_READY (optionnelle, sans PIN)
                      |
                      v
                 [ ready ]
                      |
      [COUNTER / DRIVE] DELIVER_ORDER
                      |
                      v
               [ delivered ]  (terminal, cannot be cancelled)

  Raccourci : DELIVER_ORDER accepte aussi directement `preparing` (MARK_READY est
  optionnelle) et, pour une commande anterieure au 2026-07-31, `paid`.


  Depuis pending_payment / preparing / ready (+ paid, statut historique) :
  [COUNTER, DRIVE, MANAGER, or ADMIN] CANCEL_ORDER
                      |
                      v
               [ cancelled ]  (terminal)

  Depuis pending_payment seulement : [cron, sans acteur humain] EXPIRE_ORDER (13.6,
  `order.expire`) -> [ cancelled ] quand l'encaissement n'arrive pas dans le delai.
```

**Note sur la transition `pending_payment -> preparing`** : dans le contexte RNCP, le
paiement est simule : choix d'un mode de paiement sur la borne (le numero de commande est genere
par le serveur), validation du formulaire de saisie au comptoir et au drive. Pour le kiosk, creation et encaissement sont deux appels HTTP
distincts (`mlt.md` 3.3 POST-1, 3.3bis) : `pending_payment` EST observable entre les deux
appels (la commande reste modifiable — F18 — et peut expirer, purge documentee en `mlt.md`
13.6). L'encaissement pose `paid_at` et `preparing_at` dans la meme transaction et fait
passer directement a `preparing` : `paid` n'est pas observe sur ce chemin.

**`paid` : statut historique.** Il reste dans l'ENUM et dans les gardes `status IN (...)`
pour les commandes creees avant le realignement du 2026-07-31 ; aucun chemin de code actuel
ne l'ecrit plus. Depuis la v0.3 de ce document (2026-09-24), les operations 3.3, 3.3ter et 4.1
decrivent le comportement livre : creation en `pending_payment`, puis PAY_ORDER vers `preparing`.

**Reintroduit par rapport a la v0.2 (juin 2026)** : etats `preparing` et `ready` ; operation
`MARK_READY`. `MARK_IN_PREPARATION` reste absente (l'encaissement pose `preparing`
directement, sans etape manuelle). Le personnel cuisine garde une vue de lecture pour
LIST_ORDERS_DISPLAY (domaine 5) mais peut, depuis le meme ecran, declencher `MARK_READY` ;
`DELIVER_ORDER` reste reserve au personnel comptoir/drive et a l'admin (voir 6.1) ; le
manager, qui n'a pas `order.deliver`, en est exclu.

---

## 14. Tableau recapitulatif des operations

| # | Operation | Domaine | Acteur | Entites W | Entites R |
|---|-----------|--------|-------|------------|------------|
| 1 | LOAD_CATALOGUE | Order kiosk | CUSTOMER | — | category, product, menu, menu_slot, menu_slot_option, ingredient, allergen, ingredient_allergen |
| 2 | COMPOSE_CART | Order kiosk | CUSTOMER | — (navigateur) | product, menu, menu_slot, menu_slot_option |
| 3 | CREATE_ORDER | Order kiosk | CUSTOMER | customer_order, order_item, order_item_selection | product, menu, menu_slot, ingredient, product_ingredient |
| 4 | DISPLAY_CONFIRMATION | Order kiosk | SYS | — | — |
| 5 | CREATE_COUNTER_ORDER | Order counter/drive | COUNTER/DRIVE/ADMIN | customer_order, order_item, order_item_selection, order_item_modifier, ingredient, stock_movement | product, menu, menu_slot, menu_slot_option, ingredient, product_ingredient |
| 6 | LIST_ORDERS_DISPLAY | Preparation | KITCHEN/COUNTER/DRIVE/MANAGER/ADMIN | — | customer_order, order_item, order_item_selection, order_item_modifier, role_visible_source |
| 7 | DELIVER_ORDER | Delivery | COUNTER/DRIVE/ADMIN | customer_order | — |
| 8 | CANCEL_ORDER | Cancellation | COUNTER/DRIVE/MANAGER/ADMIN | customer_order, ingredient, stock_movement, audit_log, pin_throttle | order_item, order_item_modifier, ingredient, product_ingredient, user |
| 9 | CREATE_PRODUCT | Catalogue | ADMIN/MANAGER | product | category |
| 10 | UPDATE_PRODUCT | Catalogue | ADMIN/MANAGER | product | — |
| 11 | DELETE_PRODUCT | Catalogue | ADMIN | product | menu_slot_option, order_item, menu |
| 12 | CREATE_MENU | Catalogue | ADMIN/MANAGER | menu, menu_slot, menu_slot_option | product, category |
| 13 | UPDATE_MENU | Catalogue | ADMIN/MANAGER | menu, menu_slot, menu_slot_option | order_item_selection |
| 14 | DELETE_MENU | Catalogue | ADMIN | menu_slot_option, menu_slot, menu | order_item |
| 15 | MANAGE_CATEGORY | Catalogue | ADMIN/MANAGER | category | product, menu |
| 16 | MANAGE_INGREDIENT | Catalogue | ADMIN/MANAGER | ingredient, product_ingredient, ingredient_allergen | product, allergen |
| 17 | RESTOCK | Stock | MANAGER/ADMIN | ingredient, stock_movement | ingredient |
| 18 | INVENTORY_COUNT | Stock | KITCHEN/COUNTER/DRIVE/MANAGER/ADMIN | ingredient, stock_movement | ingredient |
| 19 | READ_STOCK | Stock | KITCHEN/COUNTER/DRIVE/MANAGER/ADMIN | — | ingredient, stock_movement |
| 20 | CREATE_USER | RBAC | ADMIN | user | role |
| 21 | UPDATE_USER | RBAC | ADMIN | user | — |
| 22 | DEACTIVATE_USER | RBAC | ADMIN | user | — |
| 23 | MANAGE_RBAC | RBAC | ADMIN | role_permission, role, role_visible_source | role, permission |
| 24 | READ_STATS | Stats | MANAGER/ADMIN | — | customer_order, order_item, category, product, product_ingredient, menu, ingredient (`StatsRepository`) |
| 25 | AUTHENTICATE_USER | Auth | ALL BACK | user | user, role, role_permission |
| 26 | LOGOUT_USER | Auth | ALL BACK | — | — |
| 27 | ERASE_USER_PII | RBAC | ADMIN | user, audit_log | user |
| 28 | RESET_PASSWORD | Auth | ALL BACK | user, audit_log, password_reset_throttle | user, password_reset_throttle |
| 29 | PAY_ORDER | Order kiosk / counter / drive | CUSTOMER/COUNTER/DRIVE/ADMIN | customer_order, ingredient, stock_movement | order_item, order_item_modifier, product_ingredient |
| 30 | MARK_READY | Preparation | KITCHEN/COUNTER/DRIVE/MANAGER/ADMIN | customer_order | role_visible_source |
| 31 | ADJUST | Stock | KITCHEN/COUNTER/DRIVE/MANAGER/ADMIN | ingredient, stock_movement | ingredient |
| 32 | IMPORT_PRODUCTS | Catalogue | ADMIN/MANAGER | product, ingredient, product_ingredient, audit_log | category, product, ingredient |
| 33 | SET_STOCK_THRESHOLDS | Stock | MANAGER/ADMIN | ingredient | ingredient |
| 34 | RESET_USER_PIN | RBAC | ADMIN | user, audit_log | user |
| 35 | SET_OWN_PIN | Auth | ALL BACK | user, audit_log | user |
| 36 | ENRICH_INGREDIENT | Catalogue | ADMIN/MANAGER | ingredient | ingredient (source externe OpenFoodFacts, pas une entite MCD) |
| 37 | REORDER_CATALOGUE | Catalogue | ADMIN/MANAGER | product OU category (`display_order`) | product OU category |
| 38 | TRACK_ORDER_PUBLIC | Order kiosk | CUSTOMER | — | customer_order (canal kiosk seulement) |
| 39 | READ_HEALTH | Ops | ADMIN (page back-office) / SYS (sonde publique) | — | category (sonde de connectivite, pas un releve metier) |
| 40 | EXPIRE_ORDER | Order kiosk | SYS (cron `order-expire.php`) | customer_order, audit_log | customer_order |

**Total : 40 operations** (26 prod-like + `ERASE_USER_PII` et `RESET_PASSWORD` de la
couche security-by-design + `PAY_ORDER` et `MARK_READY`, ajoutees au tableau en v0.3 pour refleter le code livre
+ `ADJUST`, `IMPORT_PRODUCTS`, `SET_STOCK_THRESHOLDS`, `RESET_USER_PIN` et `SET_OWN_PIN`, ajoutees en v0.4 : ces
cinq operations existaient deja dans le code livre mais manquaient au tableau
+ `ENRICH_INGREDIENT`, `REORDER_CATALOGUE`, `TRACK_ORDER_PUBLIC`, `READ_HEALTH` et `EXPIRE_ORDER`, ajoutees en
v0.5 sur le meme constat : ces cinq operations existaient deja dans le code livre mais manquaient au tableau).
MODIFY_PENDING_ORDER (`mlt.md` 3.3bis) complete CREATE_ORDER sans figurer comme ligne separee ; l'expiration
planifiee (`mlt.md` 13.6) figure desormais comme ligne 40 (`EXPIRE_ORDER`).

**Ecritures du journal d'audit (security-by-design)** : les operations sensibles 7.1 (annulation), 8.2/8.3
(modification/suppression de produit), 8.6 (suppression de menu), 10.1-10.5 (utilisateur/RBAC/effacement),
10.6 (`RESET_USER_PIN`), 8.9 (`IMPORT_PRODUCTS`), 12.1 (connexion) et `EXPIRE_ORDER` (ligne 40, `action_code='order.expire'`,
cron `mlt.md` 13.6) ecrivent egalement une ligne `audit_log` (entite W non repetee par ligne ci-dessus pour garder
le tableau lisible). Le mapping allergene de 8.8 (`MANAGE_INGREDIENT`) en ecrit une aussi (`ingredient.allergens`) ;
SET_OWN_PIN (10.7) egalement (`pin.set`), bien qu'elle ne fasse pas partie de l'ensemble sensible RG-T13 (elle EST
le mecanisme qui l'alimente). Les operations de stock 9.1/9.2/9.4 (`RESTOCK`/`INVENTORY_COUNT`/`ADJUST`) enregistrent
leur attribution via `stock_movement.user_id`, sans ligne `audit_log` separee. `SET_STOCK_THRESHOLDS` (9.5) et
`ENRICH_INGREDIENT` (ligne 36) ne sont ni sous PIN ni auditees : de simples parametrages/imports externes sans
mouvement ni cible sensible. Ensemble protege par PIN selon `mlt.md` RG-T13.

---

## 15. Verification croisee MCT -> MCD (mantra #34)

Verification que chaque entite MCD participe a au moins une operation MCT.

| Entite MCD | Operations en lecture | Operations en ecriture | Couverture |
|------------|---------------------|----------------------|----------|
| `category` | 1, 9, 12, 15, 24, 37 | 15, 37 | OK |
| `product` | 1, 2, 3, 5, 9, 11, 12, 24, 32, 37 | 9, 10, 11, 32, 37 | OK |
| `menu` | 1, 2, 3, 5, 12, 14, 24 | 12, 13, 14 | OK |
| `menu_slot` | 1, 2, 5 | 12, 13, 14 | OK |
| `menu_slot_option` | 1, 2, 5, 11 | 12, 13, 14 | OK |
| `ingredient` | 1, 3, 5, 8, 16, 17, 18, 19, 24, 32, 36 | 5, 8, 16, 17, 18, 29, 31, 32, 33, 36 | OK |
| `product_ingredient` | 3, 5, 8, 24, 29 | 16, 32 | OK |
| `allergen` | 1 | — (seed statique) | OK (1) |
| `ingredient_allergen` | 1 | 16 | OK |
| `customer_order` | 6, 8, 24, 29, 38 | 3, 5, 7, 8, 29, 30, 40 | OK |
| `order_item` | 6, 8, 14, 24, 29 | 3, 5 | OK |
| `order_item_selection` | 6 | 3, 5 | OK |
| `order_item_modifier` | 6, 8, 29 | 3, 5 (seulement quand le corps en porte) | OK |
| `user` | 8, 25 | 20, 21, 22, 25, 27, 28, 34, 35 | OK |
| `role` | 20, 23, 25 | 23 | OK |
| `role_visible_source` | 6, 30 | 23 | OK |
| `permission` | 23 | — (seed statique) | OK (1) |
| `role_permission` | 25 | 23 | OK |
| `stock_movement` | 8, 19 | 5, 8, 17, 18, 29, 31 | OK |
| `audit_log` | (vue d'audit admin) | 8, 10, 11, 14, 16, 20, 21, 22, 23, 25, 27, 28, 32, 34, 35, 40 | OK (2) |
| `login_throttle` | 25 | 25 | OK (3) |
| `pin_throttle` | 8, 10, 11, 14, 18, 20, 21, 22, 23, 27, 31, 32, 34 | 8, 10, 11, 14, 18, 20, 21, 22, 23, 27, 31, 32, 34 | OK (4) |
| `password_reset_throttle` | 28 | 28 | OK (6) |
| `category_ingredient_family` | (constructeur de recette) | — (seed, migration 0017) | OK (5) |

(1) `allergen` et `permission` sont en lecture seule au niveau MCT : leurs valeurs sont declarees
dans les migrations de seed et ne sont pas modifiables via l'UI. `allergen` est gere indirectement
via `ingredient_allergen` dans MANAGE_INGREDIENT.

(2) `audit_log` (entite 20, security-by-design) est principalement en ecriture : il est ajoute par les
operations sensibles ci-dessus et lu via une vue d'audit admin (une operation de lecture dediee
peut etre formalisee lorsque l'UI d'audit sera specifiee en P3).

(3) `login_throttle` (entite 21, security-by-design) est le verrou de throttling anti-force-brute par IP source :
il est lu ET ecrit (upserte) par `AUTHENTICATE_USER` (25). Sa purge quotidienne
des lignes obsoletes est un cron, documente dans `mlt.md`, hors du perimetre des operations MCT.

(4) `pin_throttle` (entite 22, security-by-design, RG-T22) est le verrou de throttling du PIN d'action
sensible par utilisateur AGISSANT : il est lu (gate avant verification) ET ecrit (upserte sur echec, remis
a zero sur succes) par chaque operation du sous-ensemble sensible sous PIN — annulation (8), produit (10,
11), suppression de menu (14), inventaire (18), utilisateurs et RBAC (20 a 23, 27), ajustement de stock (31),
import CSV quand il porte un changement de prix (32), reinitialisation du PIN d'un tiers (34). Sa purge
quotidienne suit celle de `login_throttle` (cron, `mlt.md`), hors du perimetre des operations MCT.

(5) `category_ingredient_family` (entite 23, ADR-0018) est, comme `allergen` et `permission`, une
table de parametrage sans operation MCT dediee : c'est le constructeur de recette qui la lit pour
filtrer le selecteur d'ingredients par famille (`dictionary.md` 3.23), et elle est alimentee par la
migration 0017 et le seed 0010, pas par une operation metier autonome. Geree indirectement via
MANAGE_INGREDIENT/MANAGE_CATEGORY.

(6) `password_reset_throttle` (entite 24, security-by-design, ajoutee le 2026-09-29, migration
`0020_session_invalidation.sql`) est le verrou de throttling de la demande de reinitialisation de
mot de passe, par ADRESSE et par IP source (deux dimensions dans la meme table, discriminees par
`throttle_kind`) : il est lu (gate avant travail) ET ecrit (upserte sur chaque soumission non
bloquee) par `RESET_PASSWORD` (28). Sa purge suit le meme principe que `login_throttle`/`pin_throttle`
(`docker/cron/scripts/purge-throttle.sh`, ajoutee le 2026-09-29 par `680820f`), hors du perimetre
des operations MCT. Depuis la migration `0021`, la dimension adresse stocke l'empreinte SHA-256 de
l'adresse normalisee, pas l'adresse en clair.

**Conclusion** : 24/24 entites couvertes (19 prod-like + `audit_log` + `login_throttle` +
`pin_throttle` + `category_ingredient_family` + `password_reset_throttle`). Coherence MCT <-> MCD validee.
