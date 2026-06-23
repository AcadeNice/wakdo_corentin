# Modele Conceptuel des Traitements (MCT) — Wakdo

**Phase Merise** : P1 - Conception, etape 3 (apres le MCD)
**Version** : v0.2 — prod-like, machine a 4 etats (+ couche security-by-design 2026-06-11)
**Date** : 2026-06-04 (ajouts security-by-design 2026-06-11)
**Branche** : `feat/p1-conception`
**Statut** : prod-like — toutes les decisions D1-D8 + stock appliquees (voir `docs/notes/revue-alignement-p1.md` §7) ; operations security-by-design ajoutees (ERASE_USER_PII, RESET_PASSWORD, ensemble sensible protege par PIN, ecritures audit_log, throttling d'authentification) — 28 operations
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
| Personnel cuisine | KITCHEN | Back-office, role `kitchen` (lecture seule sur les commandes) |
| Manager | MANAGER | Back-office, role `manager` |
| Administrateur | ADMIN | Back-office, role `admin` |
| Systeme | SYS | API interne / logique PHP |

**Reference croisee MCD** : chaque operation reference des entites du MCD (section 14).
Le MCT est coherent avec la machine a etats de `customer_order.status` :

```
pending_payment -> paid -> delivered
      |              |
      +--------------+-----------> cancelled (from any non-terminal state)
```

**Etats supprimes** (par rapport a v0.1) : `preparing` et `ready` sont retires.
Justification : dans un contexte fast-food, l'affichage cuisine (KDS) est un systeme visuel ;
le personnel lit le ticket et agit. L'unique geste du personnel est « delivrer ». Le KPI est
le temps total `delivered_at - paid_at` (SLA approx. 10 min). Le code couleur du KDS est calcule a partir de
`now - paid_at` ; aucun etat stocke supplementaire n'est requis.

**Operations supprimees** (par rapport a v0.1) : `MARK_IN_PREPARATION` (`MARQUER_EN_PREPARATION`)
et `MARK_READY` (`MARQUER_PRETE`) sont retirees car leurs etats intermediaires n'existent plus.
`DELIVER_ORDER` devient la seule action faisant avancer le statut pour le personnel comptoir/drive.

**Couche security-by-design (2026-06-11)** : deux operations sont ajoutees — `RESET_PASSWORD` (12.3)
et `ERASE_USER_PII` (10.5, anonymisation RGPD). Un sous-ensemble d'operations est **protege par PIN** :
les sessions back-office restent partagees par poste de travail, mais un PIN par membre du personnel
re-autorise l'ensemble sensible — `CANCEL_ORDER` (7.1), `UPDATE_PRODUCT`/`DELETE_PRODUCT` (8.2/8.3),
`DELETE_MENU` (8.6), `INVENTORY_COUNT` (9.2), gestion des utilisateurs (10.1-10.3), `MANAGE_RBAC`
(10.4), `ERASE_USER_PII` (10.5). Ces actions hors stock ajoutent une ligne `audit_log` immuable
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
| **Condition** | Le kiosk est en service (dans les horaires d'ouverture 10:00-01:00) |
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
| **Synchronisation** | Evenement repetable (OR : ajouter produit, ajouter menu, modifier quantite, retirer un article, choisir un slot de menu, choisir le format Normal/Maxi, ajouter/retirer un modificateur d'ingredient) |
| **Condition** | Le produit ou le menu selectionne a `is_available=1` |
| **Operation** | COMPOSE_CART |
| **Description** | Construction du panier en memoire : ajouter un article (produit autonome ou menu), selectionner les produits des slots (`order_item_selection`), modifier optionnellement les ingredients (`order_item_modifier`), choisir le format Normal ou Maxi pour les menus, recalculer le total TTC. Le panier est une structure volatile cote client ; aucune ecriture en base a ce stade. |
| **Entites MCD** | R: `product`, `menu`, `menu_slot`, `menu_slot_option`, `ingredient`, `product_ingredient` — W: aucune (etat volatile front-end) |
| **Resultat** | Panier mis a jour, total recalcule, recapitulatif affiche |

---

### 3.3 CREATE_ORDER

| Champ | Valeur |
|-------|-------|
| **Evenements declencheurs** | 1. Le client confirme le panier (appuie sur « Valider ») AND 2. Le client saisit son numero de commande (substitut de paiement RNCP) |
| **Acteur** | CUSTOMER |
| **Synchronisation** | AND (les deux actions requises) |
| **Condition** | Le panier contient au moins 1 article. Le numero de commande saisi est non vide. |
| **Operation** | CREATE_ORDER |
| **Description** | Creation atomique de la commande : INSERT `customer_order` avec statut `pending_payment`, source `kiosk`, snapshot des totaux HT/TVA/TTC (calcules ligne par ligne en utilisant `vat_rate` snapshote par article). INSERT des lignes `order_item` avec `label_snapshot`, `unit_price_cents_snapshot`, `vat_rate_snapshot`. INSERT `order_item_selection` pour chaque slot rempli dans un article de menu. INSERT `order_item_modifier` pour chaque modification d'ingredient. Decrement de `ingredient.stock_quantity` pour chaque ingredient consomme (ajuste par les modificateurs : retrait => pas de decrement ; ajout => decrement supplementaire) ; INSERT d'une ligne `stock_movement` de type `sale` par unite d'ingredient affectee. Les decrements de stock et l'insertion de la commande sont dans la meme transaction. Apres que le client a saisi son numero de commande, le statut passe `pending_payment -> paid` dans la meme transaction ; `paid_at` est positionne. Le systeme genere le numero de commande au format `K-YYYY-MM-DD-NNN`. |
| **Entites MCD** | R: `product`, `menu`, `ingredient`, `product_ingredient` (snapshot) — W: `customer_order` (INSERT status `pending_payment` then UPDATE status `paid`, `paid_at`), `order_item` (INSERT N lines), `order_item_selection` (INSERT per menu slot chosen), `order_item_modifier` (INSERT per modification), `ingredient` (UPDATE stock_quantity), `stock_movement` (INSERT type `sale` per unit) |
| **Resultat** | Commande creee (statut `paid` en fin d'operation), numero de commande affiche au client, evenement logique ORDER_CREATED emis vers le domaine de preparation |

---

### 3.4 DISPLAY_CONFIRMATION

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | ORDER_CREATED (reponse API 201 apres CREATE_ORDER) |
| **Acteur** | SYS |
| **Synchronisation** | Aucune |
| **Condition** | La reponse API contient un id, un order_number et le statut `paid` |
| **Operation** | DISPLAY_CONFIRMATION |
| **Description** | Affichage de l'ecran de confirmation sur le kiosk avec le numero de commande. Le kiosk se reinitialise ensuite pour le client suivant. |
| **Entites MCD** | R: aucune (les donnees sont dans la reponse API) |
| **Resultat** | Ecran de confirmation affiche ; kiosk disponible pour la commande suivante |

---

## 4. Domaine 2 — Cycle de vie de la commande (comptoir et drive)

### 4.1 CREATE_COUNTER_ORDER

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | Un membre du personnel comptoir ou drive initie une nouvelle commande depuis le back-office |
| **Acteur** | COUNTER ou DRIVE |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur est authentifie et detient la permission `order.create`. La `source` est `counter` ou `drive` (auto-taggee depuis `role.order_source`). |
| **Operation** | CREATE_COUNTER_ORDER |
| **Description** | Composition manuelle de la commande via le back-office : selectionner produits et menus, choisir le mode de service (`dine_in`/`takeaway`/`drive`), remplir les slots de menu, ajouter des modificateurs d'ingredient. Logique de creation identique a CREATE_ORDER (snapshot, decrement de stock dans la meme transaction, transition atomique `pending_payment -> paid`). La `source` est auto-taggee depuis `role.order_source` (counter -> `counter`, drive -> `drive`). Format du numero de commande : `C-YYYY-MM-DD-NNN` (comptoir) ou `D-YYYY-MM-DD-NNN` (drive). Contrainte croisee : si `source = 'drive'` alors `service_mode = 'drive'` (verifie a la creation). |
| **Entites MCD** | R: `product`, `menu`, `menu_slot`, `menu_slot_option`, `ingredient`, `product_ingredient` — W: `customer_order` (INSERT status `pending_payment` then UPDATE status `paid`, `paid_at`), `order_item`, `order_item_selection`, `order_item_modifier`, `ingredient` (stock decrement), `stock_movement` (INSERT type `sale`) |
| **Resultat** | Commande creee (statut `paid`), numero de commande communique au client |

---

## 5. Domaine 3 — Affichage de preparation (cuisine)

### 5.1 LIST_ORDERS_DISPLAY

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | Le personnel cuisine accede a l'affichage de preparation ou le rafraichit |
| **Acteur** | KITCHEN (ou COUNTER, DRIVE, ADMIN) |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur est authentifie et detient la permission `order.read`. |
| **Operation** | LIST_ORDERS_DISPLAY |
| **Description** | Lecture des lignes `customer_order` avec statut `paid`, filtrees par les sources visibles selon le role de l'acteur (depuis `role_visible_source`) : la cuisine voit toutes les sources ; le comptoir voit kiosk+counter ; le drive voit drive. Les commandes sont triees par `paid_at` ascendant (les plus anciennes en premier). Pour chaque commande, afficher : numero de commande, source, contenu (`order_item` avec `label_snapshot`, `quantity`, format, selections de slots, modificateurs d'ingredient). La couleur KDS est calculee a partir de `now - paid_at` par rapport au seuil de SLA (approx. 10 min), non stockee. Le personnel cuisine n'effectue aucune transition de statut — c'est une operation en lecture seule. |
| **Entites MCD** | R: `customer_order` (status=`paid`), `order_item`, `order_item_selection`, `order_item_modifier`, `role_visible_source` |
| **Resultat** | Liste d'affichage de preparation montree, triee par heure de paiement ascendante |

---

## 6. Domaine 4 — Livraison au client

### 6.1 DELIVER_ORDER

| Champ | Valeur |
|-------|-------|
| **Evenements declencheurs** | 1. La commande est au statut `paid` AND 2. Le personnel comptoir ou drive clique sur « Livre » |
| **Acteur** | COUNTER ou DRIVE |
| **Synchronisation** | AND |
| **Condition** | La commande a le statut `paid`. L'acteur detient la permission `order.deliver`. Le role de l'acteur est coherent avec la source de la commande (le personnel comptoir traite les commandes kiosk+counter ; le personnel drive traite les commandes drive — filtre par role_visible_source). |
| **Operation** | DELIVER_ORDER |
| **Description** | Transition en geste unique `paid -> delivered`. Positionne `delivered_at = NOW()`. La commande passe en historique. Cette operation remplace la sequence en deux etapes de v0.1 (marquer-prete puis livrer) ; la confirmation visuelle de la cuisine (KDS) suffit avant cette action. |
| **Entites MCD** | W: `customer_order` (UPDATE status `paid` -> `delivered`, `delivered_at = NOW()`) |
| **Resultat** | Commande au statut `delivered`, cycle de vie complet |

---

## 7. Domaine 5 — Annulation

### 7.1 CANCEL_ORDER

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | Un acteur autorise demande l'annulation d'une commande |
| **Acteur** | COUNTER, DRIVE ou ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | La commande existe. `customer_order.status` est dans `['pending_payment', 'paid']`. Les statuts terminaux `delivered` et `cancelled` ne peuvent pas transiter vers `cancelled`. L'acteur detient la permission `order.cancel`. |
| **Operation** | CANCEL_ORDER |
| **Description** | Transition du statut courant vers `cancelled`. Positionne `cancelled_at = NOW()`. La commande est conservee en base pour l'historique et les stats (pas de suppression physique). Si le statut courant est `paid`, le stock est recredite : pour chaque ingredient consomme par la commande (en tenant compte des modificateurs), `ingredient.stock_quantity` est incremente ; une ligne `stock_movement` de type `cancellation` est inseree par unite d'ingredient affectee. Le recredit du stock et la mise a jour du statut sont dans la meme transaction. |
| **Entites MCD** | R: `order_item`, `order_item_modifier`, `ingredient`, `product_ingredient` — W: `customer_order` (UPDATE status -> `cancelled`, `cancelled_at = NOW()`), `ingredient` (UPDATE stock_quantity, conditional on status `paid`), `stock_movement` (INSERT type `cancellation`, conditional on status `paid`) |
| **Resultat** | Commande au statut `cancelled`, visible dans l'historique admin |

---

## 8. Domaine 6 — Gestion du catalogue

### 8.1 CREATE_PRODUCT

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | L'admin ou le manager soumet le formulaire de creation de produit |
| **Acteur** | ADMIN ou MANAGER |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur detient la permission `product.create`. La categorie cible existe et `is_active=1`. `name` est non vide. `price_cents > 0`. |
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
| **Description** | UPDATE des colonnes modifiables (`name`, `description`, `price_cents`, `vat_rate`, `image_path`, `is_available`, `display_order`, `category_id`). Les snapshots deja stockes dans `order_item` ne sont pas affectes (integrite historique garantie par conception). |
| **Entites MCD** | W: `product` (UPDATE) |
| **Resultat** | Produit mis a jour, liste des produits rafraichie |

---

### 8.3 DELETE_PRODUCT

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | L'admin confirme la suppression d'un produit |
| **Acteur** | ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur detient la permission `product.delete`. Le produit n'est option de slot dans aucun `menu_slot_option` (FK `ON DELETE RESTRICT`). Le produit n'est reference dans aucune ligne historique `order_item` (FK `ON DELETE RESTRICT`). Verification prealable requise. |
| **Operation** | DELETE_PRODUCT |
| **Description** | Suppression physique du produit si aucune contrainte FK ne la bloque. Si le produit est reference dans un slot de menu ou une ligne de commande historique, la suppression est bloquee. L'alternative recommandee est de le desactiver (`is_available=0`). Bloque egalement si le produit est le `burger_product_id` d'un `menu`. |
| **Entites MCD** | W: `product` (DELETE — blocked if referenced in `menu_slot_option`, `order_item`, or `menu.burger_product_id`) |
| **Resultat** | Produit supprime OU erreur « produit utilise » |

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
| **Description** | UPDATE des colonnes `menu`. Si la configuration des slots est modifiee : DELETE de toutes les lignes `menu_slot_option` pour les slots de ce menu, DELETE des lignes `menu_slot`, puis re-INSERT (pattern delete-and-reinsert, atomique en transaction). Les snapshots dans `order_item` ne sont pas affectes. |
| **Entites MCD** | W: `menu` (UPDATE), `menu_slot` (DELETE + INSERT), `menu_slot_option` (DELETE + INSERT) |
| **Resultat** | Menu mis a jour |

---

### 8.6 DELETE_MENU

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | L'admin confirme la suppression d'un menu |
| **Acteur** | ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur detient la permission `menu.delete`. Le menu n'est reference dans aucune ligne historique `order_item` (FK `ON DELETE RESTRICT`). Verification prealable requise. |
| **Operation** | DELETE_MENU |
| **Description** | Si aucun `order_item` ne reference ce menu : DELETE `menu_slot_option` (CASCADE from `menu_slot`), DELETE `menu_slot` (CASCADE from `menu`), DELETE `menu`. Si des references historiques existent, proposer la desactivation (`is_available=0`) a la place. |
| **Entites MCD** | W: `menu_slot_option` (DELETE CASCADE), `menu_slot` (DELETE CASCADE), `menu` (DELETE — blocked if referenced in `order_item`) |
| **Resultat** | Menu supprime OU erreur « menu present dans des commandes historiques » |

---

### 8.7 MANAGE_CATEGORY

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | L'admin ou le manager cree, modifie ou desactive une categorie |
| **Acteur** | ADMIN ou MANAGER |
| **Synchronisation** | OR (creation, modification, desactivation) |
| **Condition** | L'acteur detient la permission `category.manage`. Pour la desactivation : les produits et menus de la categorie ne sont pas auto-desactives en base (pas de CASCADE sur `is_active`) ; la couche applicative propose de desactiver les produits/menus enfants. |
| **Operation** | MANAGE_CATEGORY |
| **Description** | CRUD sur `category`. La desactivation (`is_active=0`) masque la categorie et ses produits du kiosk sans suppression physique. La suppression physique est bloquee si des produits ou des menus referencent cette categorie (FK `ON DELETE RESTRICT`). |
| **Entites MCD** | W: `category` (INSERT / UPDATE / conditional DELETE) |
| **Resultat** | Categorie creee / modifiee / desactivee |

---

### 8.8 MANAGE_INGREDIENT

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | L'admin ou le manager cree, modifie ou desactive un ingredient ; ou gere la composition produit (`product_ingredient`) ou le mapping allergene (`ingredient_allergen`) |
| **Acteur** | ADMIN ou MANAGER |
| **Synchronisation** | OR (creer ingredient, modifier ingredient, modifier composition, modifier mapping allergene) |
| **Condition** | L'acteur detient la permission `ingredient.manage`. |
| **Operation** | MANAGE_INGREDIENT |
| **Description** | CRUD sur `ingredient` (name, unit, pack_size, pack_label, stock_capacity, low_stock_pct, critical_stock_pct, is_active). Gestion de la composition `product_ingredient` (quantity_normal, quantity_maxi, is_removable, is_addable, extra_price_cents) pour tout produit. Gestion du mapping `ingredient_allergen` (14 allergenes reglementes UE). Desactiver un ingredient (`is_active=0`) le masque du configurateur sans suppression. La suppression physique de `ingredient` est bloquee s'il est reference dans `product_ingredient` (FK `ON DELETE RESTRICT`) ou `stock_movement` (FK `ON DELETE RESTRICT`). |
| **Entites MCD** | R: `product` (FK validation), `allergen` (FK validation) — W: `ingredient` (INSERT/UPDATE/DELETE conditional), `product_ingredient` (INSERT/UPDATE/DELETE), `ingredient_allergen` (INSERT/DELETE) |
| **Resultat** | Ingredient / composition / mapping allergene mis a jour |

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
| **Description** | UPDATE `ingredient.stock_quantity += N * pack_size`. INSERT d'une ligne `stock_movement` : type `restock`, delta `+= N * pack_size`, `user_id` de l'acteur, `note` optionnelle (ex. reference de livraison). Les deux ecritures sont dans la meme transaction. |
| **Entites MCD** | R: `ingredient` — W: `ingredient` (UPDATE stock_quantity), `stock_movement` (INSERT type `restock`) |
| **Resultat** | Stock incremente, mouvement journalise |

---

### 9.2 INVENTORY_COUNT

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | Un membre du personnel ou un manager enregistre le resultat d'un inventaire physique |
| **Acteur** | KITCHEN, COUNTER, DRIVE, MANAGER ou ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur detient la permission `stock.count`. L'ingredient existe. Comptage physique `actual_quantity >= 0`. |
| **Operation** | INVENTORY_COUNT |
| **Description** | Calcul de `delta = actual_quantity - ingredient.stock_quantity` (peut etre negatif ou positif). UPDATE `ingredient.stock_quantity = actual_quantity`. INSERT d'une ligne `stock_movement` : type `inventory_correction`, delta = ecart calcule, `user_id` de l'acteur, `note` optionnelle. Les deux ecritures dans la meme transaction. |
| **Entites MCD** | R: `ingredient` (read current stock_quantity) — W: `ingredient` (UPDATE stock_quantity), `stock_movement` (INSERT type `inventory_correction`) |
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
| **Description** | Lecture de la liste `ingredient` avec le `stock_quantity` courant, `stock_capacity`, `stock_pct` calcule, `low_stock_pct`, `critical_stock_pct`, `pack_size`, `pack_label`. Bandes de stock calculees au moment de l'affichage : `low_stock` lorsque `stock_quantity <= stock_capacity * low_stock_pct/100`, `critical_stock` lorsque `stock_quantity <= stock_capacity * critical_stock_pct/100`. Optionnel : lecture de l'historique `stock_movement` pour un ingredient donne, filtre par plage de dates. |
| **Entites MCD** | R: `ingredient`, `stock_movement` (optional history) |
| **Resultat** | Liste du stock affichee avec indicateurs de stock bas |

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

## 11. Domaine 9 — Stats et KPI

### 11.1 READ_STATS

| Champ | Valeur |
|-------|-------|
| **Evenement declencheur** | Le manager ou l'admin accede au tableau de bord des stats |
| **Acteur** | MANAGER ou ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur detient la permission `stats.read`. |
| **Operation** | READ_STATS |
| **Description** | Requetes d'agregation sur `customer_order` et `order_item`. Agregations cles : nombre de commandes et chiffre d'affaires (TTC) par `service_day` (calcule avec CASE WHEN HOUR(created_at) < 10 THEN DATE(created_at) - INTERVAL 1 DAY ELSE DATE(created_at) END ; coupure a 10:00) ; top produits par COUNT de `label_snapshot` dans `order_item` ; taux d'annulation ; temps de livraison moyen `delivered_at - paid_at` ; ventilation par `source` et `service_mode`. Les requetes excluent les commandes annulees des sommes de chiffre d'affaires mais les incluent dans les comptages de volume. Pas de colonne stockee supplementaire pour `service_day` ; calcul au moment de la requete. |
| **Entites MCD** | R: `customer_order`, `order_item` |
| **Resultat** | Tableau de bord des stats affiche |

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
| **Description** | Verification des identifiants. Si valide : regeneration de l'ID de session (protection contre la fixation de session), stockage de `user_id` et `role_id` en session, UPDATE `last_login_at`, remise a zero du compteur d'echecs de connexion. En cas d'echec : incrementation de `failed_login_attempts` et application d'un backoff degressif (`lockout_until`), erreur generique resistante a l'enumeration. Idle timeout : 4h. Absolute timeout : 10h. Redirection vers `role.default_route`. Voir `mlt.md` 12.1. |
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
| **Condition** | Demande : l'email soumis est traite de maniere resistante a l'enumeration (meme reponse neutre qu'il existe ou non). Confirmation : un token valide et non expire est presente. |
| **Operation** | RESET_PASSWORD |
| **Description** | La phase de demande genere un token aleatoire, stocke son hash + expiration, et envoie le token brut une seule fois par email. La phase de confirmation valide le hash du token + expiration, remplace `password_hash` (argon2id), efface le token et remet a zero le compteur d'echecs de connexion. Voir `mlt.md` 12.3. |
| **Entites MCD** | W: `user` (UPDATE `password_reset_token_hash` + `password_reset_expires_at` on request; UPDATE `password_hash`, clear token, reset `failed_login_attempts`/`lockout_until` on confirm), `audit_log` (INSERT) |
| **Resultat** | Mot de passe reinitialise via un token a usage unique et a duree limitee ; une ligne `audit_log` enregistree |

---

## 13. Machine a etats — customer_order.status

Recapitulatif des transitions couvertes par les operations MCT.

```
               [CUSTOMER / COUNTER / DRIVE]
               CREATE_ORDER
               CREATE_COUNTER_ORDER
                      |
                      v
           [ pending_payment ]  (order composed, payment pending)
                      |
    [CUSTOMER / COUNTER / DRIVE] payment confirmed
    (atomic within CREATE_ORDER / CREATE_COUNTER_ORDER)
                      |
                      v
                 [ paid ]
                      |
      [COUNTER / DRIVE] DELIVER_ORDER
                      |
                      v
               [ delivered ]  (terminal, cannot be cancelled)


  From pending_payment / paid:
  [COUNTER, DRIVE, or ADMIN] CANCEL_ORDER
                      |
                      v
               [ cancelled ]  (terminal)
```

**Note sur la transition `pending_payment -> paid`** : dans le contexte RNCP, le paiement est
remplace par la saisie du numero de commande par le client (kiosk) ou par la validation du personnel
(comptoir/drive). La transition est atomique au sein de CREATE_ORDER et CREATE_COUNTER_ORDER.
Le statut `pending_payment` n'est pas observable en dehors de la transaction.

**Supprime de v0.1** : etats `preparing` et `ready` ; operations `MARK_IN_PREPARATION` et `MARK_READY`.
Le personnel cuisine a une vue en lecture seule des commandes `paid` (LIST_ORDERS_DISPLAY). L'unique
action de livraison (DELIVER_ORDER) condense la sequence en trois etapes de v0.1 en un seul geste.

---

## 14. Tableau recapitulatif des operations

| # | Operation | Domaine | Acteur | Entites W | Entites R |
|---|-----------|--------|-------|------------|------------|
| 1 | LOAD_CATALOGUE | Order kiosk | CUSTOMER | — | category, product, menu, menu_slot, menu_slot_option, ingredient, allergen, ingredient_allergen |
| 2 | COMPOSE_CART | Order kiosk | CUSTOMER | — (volatile) | product, menu, menu_slot, menu_slot_option, ingredient, product_ingredient |
| 3 | CREATE_ORDER | Order kiosk | CUSTOMER | customer_order, order_item, order_item_selection, order_item_modifier, ingredient, stock_movement | product, menu, ingredient, product_ingredient |
| 4 | DISPLAY_CONFIRMATION | Order kiosk | SYS | — | — |
| 5 | CREATE_COUNTER_ORDER | Order counter/drive | COUNTER/DRIVE | customer_order, order_item, order_item_selection, order_item_modifier, ingredient, stock_movement | product, menu, menu_slot, menu_slot_option, ingredient, product_ingredient |
| 6 | LIST_ORDERS_DISPLAY | Preparation | KITCHEN/COUNTER/DRIVE/ADMIN | — | customer_order, order_item, order_item_selection, order_item_modifier, role_visible_source |
| 7 | DELIVER_ORDER | Delivery | COUNTER/DRIVE | customer_order | — |
| 8 | CANCEL_ORDER | Cancellation | COUNTER/DRIVE/ADMIN | customer_order, ingredient, stock_movement | order_item, order_item_modifier, ingredient, product_ingredient |
| 9 | CREATE_PRODUCT | Catalogue | ADMIN/MANAGER | product | category |
| 10 | UPDATE_PRODUCT | Catalogue | ADMIN/MANAGER | product | — |
| 11 | DELETE_PRODUCT | Catalogue | ADMIN | product | menu_slot_option, order_item, menu |
| 12 | CREATE_MENU | Catalogue | ADMIN/MANAGER | menu, menu_slot, menu_slot_option | product, category |
| 13 | UPDATE_MENU | Catalogue | ADMIN/MANAGER | menu, menu_slot, menu_slot_option | — |
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
| 24 | READ_STATS | Stats | MANAGER/ADMIN | — | customer_order, order_item |
| 25 | AUTHENTICATE_USER | Auth | ALL BACK | user | user, role, role_permission |
| 26 | LOGOUT_USER | Auth | ALL BACK | — | — |
| 27 | ERASE_USER_PII | RBAC | ADMIN | user, audit_log | user |
| 28 | RESET_PASSWORD | Auth | ALL BACK | user, audit_log | user |

**Total : 28 operations** (26 prod-like + `ERASE_USER_PII` et `RESET_PASSWORD` de la
couche security-by-design).

**Ecritures du journal d'audit (security-by-design)** : les operations sensibles 7.1 (annulation), 8.2/8.3
(modification/suppression de produit), 8.6 (suppression de menu), 10.1-10.5 (utilisateur/RBAC/effacement) et 12.1 (connexion)
ecrivent egalement une ligne `audit_log` (entite W non repetee par ligne ci-dessus pour garder le tableau lisible).
Les operations de stock 9.1/9.2 enregistrent leur attribution via `stock_movement.user_id`. Ensemble protege par PIN
selon `mlt.md` RG-T13.

---

## 15. Verification croisee MCT -> MCD (mantra #34)

Verification que chaque entite MCD participe a au moins une operation MCT.

| Entite MCD | Operations en lecture | Operations en ecriture | Couverture |
|------------|---------------------|----------------------|----------|
| `category` | 1, 9, 12, 15 | 15 | OK |
| `product` | 1, 2, 3, 5, 9, 11, 12 | 9, 10, 11 | OK |
| `menu` | 1, 2, 3, 5, 12, 14 | 12, 13, 14 | OK |
| `menu_slot` | 1, 2, 5 | 12, 13, 14 | OK |
| `menu_slot_option` | 1, 2, 5, 11 | 12, 13, 14 | OK |
| `ingredient` | 1, 2, 3, 5, 8, 16, 17, 18, 19 | 3, 5, 8, 16, 17, 18 | OK |
| `product_ingredient` | 2, 3, 5, 8 | 16 | OK |
| `allergen` | 1 | — (static seed) | OK (*) |
| `ingredient_allergen` | 1 | 16 | OK |
| `customer_order` | 6, 8, 24 | 3, 5, 7, 8 | OK |
| `order_item` | 6, 8, 14, 24 | 3, 5 | OK |
| `order_item_selection` | 6 | 3, 5 | OK |
| `order_item_modifier` | 6, 8 | 3, 5 | OK |
| `user` | 25 | 20, 21, 22, 25 | OK |
| `role` | 20, 23, 25 | 23 | OK |
| `role_visible_source` | 6 | 23 | OK |
| `permission` | 23 | — (static seed) | OK (*) |
| `role_permission` | 25 | 23 | OK |
| `stock_movement` | 19 | 3, 5, 8, 17, 18 | OK |
| `audit_log` | (vue d'audit admin) | 8, 10, 11, 14, 20, 21, 22, 23, 25, 27, 28 | OK |
| `login_throttle` | 25 | 25 | OK |

(*) `allergen` et `permission` sont en lecture seule au niveau MCT : leurs valeurs sont declarees
dans les migrations de seed et ne sont pas modifiables via l'UI. `allergen` est gere indirectement
via `ingredient_allergen` dans MANAGE_INGREDIENT.

(**) `audit_log` (entite 20, security-by-design) est principalement en ecriture : il est ajoute par les
operations sensibles ci-dessus et lu via une vue d'audit admin (une operation de lecture dediee
peut etre formalisee lorsque l'UI d'audit sera specifiee en P3).

(***) `login_throttle` (entite 21, security-by-design) est le verrou de throttling anti-force-brute par IP source :
il est lu ET ecrit (upserte) par `AUTHENTICATE_USER` (25). Sa purge quotidienne
des lignes obsoletes est un cron, documente dans `mlt.md`, hors du perimetre des operations MCT.

(****) `pin_throttle` (entite 22, security-by-design, RG-T22) est le verrou de throttling du PIN d'action
sensible par utilisateur AGISSANT : il est lu (gate avant verification) ET ecrit (upserte sur echec, remis
a zero sur succes) par les operations sensibles sous PIN (ex. UPDATE_PRODUCT prix/TVA, DELETE_PRODUCT). Sa
purge quotidienne suit celle de `login_throttle` (cron, `mlt.md`), hors du perimetre des operations MCT.

**Conclusion** : 22/22 entites couvertes (19 prod-like + `audit_log` + `login_throttle` + `pin_throttle`).
Coherence MCT <-> MCD validee.
