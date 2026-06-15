# Modele Logique des Traitements (MLT) — Wakdo

**Phase Merise** : P1 - Conception, etape 4 (derivee du MCT)
**Version** : v0.2 — prod-like, machine a 4 etats (+ couche security-by-design 2026-06-11)
**Date** : 2026-06-04 (ajouts security-by-design 2026-06-11)
**Branche** : `feat/p1-conception`
**Statut** : prod-like — toutes les decisions D1-D8 + stock appliquees (voir `docs/notes/revue-alignement-p1.md` §7) ; regles security-by-design ajoutees (RG-T13-T21 : PIN, audit, escaping, allowlists, idempotence, decrement atomique, disponibilite produit calculee (RG-T21) ; ops RESET_PASSWORD, ERASE_USER_PII, throttling d'authentification ; table de throttle par IP `login_throttle`)
**Auteur** : BYAN (couche methodologie)

---

## 1. Objectif

Le MLT (Modele Logique des Traitements) affine chaque operation du MCT en specifiant :
- **preconditions** — ce qui doit etre vrai avant l'execution
- **regles de gestion** — validation, calcul, logique metier
- **postconditions** — l'etat garanti apres succes
- **sorties** — donnees produites ou evenements emis
- **cas d'erreur** — sorties alternatives lorsqu'une condition echoue

Il fait le lien entre le MCT (niveau conceptuel) et l'implementation PHP/SQL (niveau physique).
Toutes les references aux entites/attributs utilisent les noms de `docs/merise/dictionary.md` (anglais,
snake_case). Tous les montants monetaires sont en centimes entiers.

**Conventions de tags** :
- `[PRE]` — precondition ; doit etre satisfaite pour que l'operation s'execute
- `[RG]` — regle de gestion ; logique appliquee pendant l'execution
- `[POST]` — postcondition ; etat de la base garanti apres succes
- `[OUT]` — sortie ; donnee ou evenement produit
- `[ERR]` — cas d'erreur ; sortie alternative lorsqu'une condition echoue

---

## 2. Regles de gestion transverses

Ces regles s'appliquent a plusieurs operations et sont centralisees ici pour eviter la repetition.

| Code de regle | Libelle | Operations concernees |
|-----------|-------|----------------------|
| **RG-T01** | Token CSRF verifie sur chaque formulaire POST/PUT/DELETE du back-office | AUTH, toutes ops admin |
| **RG-T02** | Session active + `user.is_active = 1` verifies a chaque requete authentifiee | Tous domaines 3-10 |
| **RG-T03** | Permission verifiee via `role_permission` avant l'execution de l'operation | Tous domaines 3-10 |
| **RG-T04** | Tous les montants monetaires sont manipules en centimes entiers ; conversion EUR uniquement en sortie | 3.3, 4.1, 8.1, 8.4 |
| **RG-T05** | Les snapshots (`label_snapshot`, `unit_price_cents_snapshot`, `vat_rate_snapshot`) sur `order_item` ne sont pas modifies apres l'INSERT (integrite historique des commandes passees — garantie de conception) | 3.3, 4.1, 8.2, 8.5 |
| **RG-T06** | Toutes les requetes SQL utilisent PDO avec des requetes preparees ; aucune donnee utilisateur concatenee dans le SQL | Toutes operations |
| **RG-T07** | Les instructions UPDATE de transition d'etat incluent `AND status = <expected_status>` dans la clause WHERE (protection de concurrence optimiste contre la double transition) | 6.1, 7.1 |
| **RG-T08** | Les operations touchant plusieurs tables s'executent dans une transaction de base de donnees atomique ; un echec partiel declenche un rollback complet | 3.3, 4.1, 7.1, 8.4, 9.1, 9.2 |
| **RG-T09** | Contrainte croisee sur `customer_order` : `source = 'drive'` implique `service_mode = 'drive'` ; verifiee a la creation de la commande. Materialisable en CHECK MariaDB : `CHECK (source != 'drive' OR service_mode = 'drive')`. | 3.3, 4.1 |
| **RG-T10** | Le calcul de TVA se fait ligne par ligne : chaque `order_item` porte son propre `vat_rate_snapshot` (entier pour-mille snapshote depuis `product.vat_rate`). Les totaux de commande (`total_ht_cents`, `total_vat_cents`, `total_ttc_cents`) sont la somme des montants au niveau des lignes. | 3.3, 4.1 |
| **RG-T11** | Le decrement de stock a la transition `pending_payment -> paid` et le re-credit a `paid -> cancelled` sont dans la meme transaction de base de donnees que la mise a jour du statut (pas de decrement orphelin). | 3.3, 4.1, 7.1 |
| **RG-T12** | Filtre du tableau de bord par source : les sources visibles de chaque role sont lues depuis `role_visible_source` ; la requete utilise `WHERE customer_order.source IN (role_visible_sources)`. | 6.1 |
| **RG-T13** | **PIN d'action sensible** (security-by-design) : l'ensemble des operations sensibles requiert une re-autorisation par PIN propre a chaque membre du personnel avant l'execution : verifier le PIN soumis contre `user.pin_hash` (`password_verify`, argon2id). En cas de succes, le `user_id` agissant est capture pour le journal d'audit ; en cas d'echec, l'operation est rejetee. Ensemble sensible : 7.1 (annulation), 8.2/8.3 (mise a jour/suppression produit), 8.6 (suppression menu), 9.2 (correction d'inventaire), 10.1/10.2/10.3 (gestion utilisateur), 10.4 (RBAC), 10.5 (effacement PII). Les sessions restent partagees par poste de travail pour les 95% de routine. | 7.1, 8.2, 8.3, 8.6, 9.2, 10.1-10.5 |
| **RG-T14** | **Ecriture du journal d'audit** : les operations sensibles hors stock ajoutent une ligne `audit_log` immuable dans la meme transaction que leur effet : `actor_user_id` (issu du PIN RG-T13), `actor_role_id`, `action_code` (code de permission/operation), `entity_type` + `entity_id` de la ligne affectee, `summary` (description de changement non personnelle), `details` JSON (**noms** des champs modifies pour les actions ciblant un utilisateur, pas les valeurs PII). Aucun UPDATE/DELETE sur `audit_log`. Les actions de stock (9.1 restock, 9.2 inventaire) enregistrent leur attribution via `stock_movement.user_id` (capture par PIN), qui fournit deja la trace de stock append-only — elles ne sont pas doublement journalisees. | 7.1, 8.2, 8.3, 8.6, 10.1-10.5, 12.1 |
| **RG-T15** | **Echappement en sortie** (anti-XSS) : les champs de texte libre (`product.name`/`description`, `ingredient.name`, `user.first_name`/`last_name`, notes) sont echappes selon le contexte au rendu. Les vues admin rendues cote serveur utilisent `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')` ; le kiosk en vanilla-JS injecte le texte via `textContent` (ou un echappeur explicite), pas `innerHTML`. | Toutes les vues rendant du texte stocke |
| **RG-T16** | **Allowlist d'affectation de masse** : les instructions INSERT/UPDATE ne lient qu'une allowlist de colonnes explicite par operation issue de la requete ; les champs supplementaires/inconnus sont ecartes. Empeche l'alteration de `price_cents`, `vat_rate`, `role_id`, `is_active`, `status` via des champs de formulaire injectes. | 8.1, 8.2, 8.4, 8.5, 10.1, 10.2 |
| **RG-T17** | **Allowlist d'identifiants dynamiques** : les tokens de colonne/direction utilises dans un `ORDER BY` / `GROUP BY` dynamique sont resolus contre une allowlist fixe de noms de colonnes avant la construction de la requete (RG-T06 couvre les valeurs via les parametres lies ; les identifiants SQL ne peuvent pas etre lies, ils sont donc en allowlist). | 5.1, 9.3, 11.1 |
| **RG-T18** | **Validation cote serveur et bornes de longueur** : chaque entree est re-validee cote serveur independamment des verifications cote client — type, plage, longueur max (correspondant aux tailles VARCHAR du dictionnaire), appartenance a l'enum, existence de FK. La validation cote client est une aide UX, pas une frontiere de confiance. | Toutes operations d'ecriture |
| **RG-T19** | **Idempotence** : `POST /api/orders` porte un `idempotency_key` (UUID) genere par le client. Avant de creer, le rechercher sur `customer_order.idempotency_key` (UNIQUE) ; si une ligne existe, retourner cette commande au lieu de creer un doublon (retry reseau rejoue). | 3.3, 4.1 |
| **RG-T20** | **Decrement de stock atomique** : pendant la transition `paid`, chaque `ingredient` affecte est decremente par une unique instruction auto-verrouillante `UPDATE ingredient SET stock_quantity = stock_quantity - :units WHERE id = :id` — pas de lecture-gate prealable, pas de `SELECT ... FOR UPDATE`. Les commandes concurrentes sur le meme ingredient appliquent leurs deltas sans perte de mise a jour et sans souci d'ordonnancement de deadlock. `stock_quantity` est signe et peut devenir negatif quand les ventes depassent le stock compte (l'ampleur de la survente est remontee aux managers) ; le decrement ne bloque pas sur un plancher. | 3.3, 4.1 |
| **RG-T21** | **Disponibilite produit calculee** : la commandabilite effective d'un produit est calculee, pas stockee. Il est commandable lorsque `product.is_available = 1` ET que chaque ingredient non retirable (`is_removable = 0`) de son `product_ingredient` a `stock_quantity > stock_capacity * critical_stock_pct / 100`. A la bande critique, un ingredient requis met le produit en rupture sans ecriture et sans cascade ; un reapprovisionnement au-dessus de la bande critique le rend commandable a nouveau de lui-meme ; un retrait manuel (`product.is_available = 0`) est une surcharge forte ; un ingredient retirable/optionnel a la bande critique ne bloque pas le produit (seul son supplement devient indisponible). | 3.1, 3.3, 4.1, 5.1 |

---

## 3. Domaine 1 — Cycle de vie de la commande (kiosk)

### 3.1 LOAD_CATALOGUE

**Correspond a la section 3.1 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | La requete provient de l'endpoint kiosk (public, aucune authentification requise) |
| **[PRE-2]** | L'heure courante est dans la fenetre de service (10:00-01:00) ; en dehors de la fenetre le kiosk affiche un message de fermeture |
| **[RG-1]** | Lire toutes les lignes `category` avec `is_active = 1`, triees par `category.display_order ASC` |
| **[RG-2]** | Pour chaque categorie, lire les lignes `product` avec `is_available = 1` et `category_id` correspondant, triees par `product.display_order ASC` |
| **[RG-3]** | Lire toutes les lignes `menu` avec `is_available = 1` ; pour chaque menu, charger les lignes `menu_slot` triees par `menu_slot.display_order ASC` ; pour chaque slot, charger les produits eligibles via `menu_slot_option JOIN product` (ou `product.is_available = 1`) |
| **[RG-4]** | Pour chaque produit, calculer les allergenes en joignant `product_ingredient -> ingredient_allergen -> allergen` (pas de ressaisie manuelle par produit) |
| **[RG-5]** | Pour chaque produit avec des lignes `product_ingredient`, charger la composition `ingredient` (pour le configurateur) |
| **[RG-6]** | Les prix sont retournes en centimes entiers ; la conversion EUR est effectuee cote client |
| **[POST-1]** | Aucune ecriture en base ; etat de la base inchange |
| **[OUT-1]** | Reponse JSON : `{data: {categories: [...], products: {...}, menus: [{..., slots: [{..., options: [...]}]}]}}` |
| **[ERR-1]** | Base inaccessible : reponse `{data: null, error: {code: "DB_ERROR"}}` et le front-end bascule sur un JSON statique |

---

### 3.2 COMPOSE_CART

**Correspond a la section 3.2 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | Catalogue charge en memoire front-end (LOAD_CATALOGUE termine) |
| **[PRE-2]** | L'article selectionne (produit ou menu) est present dans le catalogue charge avec `is_available = 1` |
| **[RG-1]** | Le panier est une structure JavaScript en memoire (tableau d'articles) ; aucune persistance en base a ce stade |
| **[RG-2]** | Chaque article contient : `type` (`product` ou `menu`), `item_id`, `label`, `unit_price_cents` (snapshot depuis le catalogue), `quantity`, `format` (`normal` ou `maxi`, pour les menus), `slot_selections` (tableau de `{menu_slot_id, product_id, label}` pour les articles menu), `modifiers` (tableau de `{ingredient_id, action, extra_price_cents}`) |
| **[RG-3]** | Format Normal/Maxi (articles menu uniquement) : `normal` utilise `menu.price_normal_cents` ; `maxi` utilise `menu.price_maxi_cents`. Aucun changement de prix de composant individuel n'est stocke ; le differentiel de prix est au niveau du menu. |
| **[RG-4]** | Regles de modificateur d'ingredient : `action = 'remove'` requiert `is_removable = 1` sur `product_ingredient` (gratuit) ; `action = 'add'` requiert `is_addable = 1` (peut porter un `extra_price_cents`). Ces contraintes sont verifiees au moment de la composition du panier contre le catalogue charge. |
| **[RG-5]** | Si un article avec les memes `(type, item_id, format, slot_selections, modifiers)` existe deja dans le panier, sa quantite est incrementee plutot que d'ajouter un nouvel article |
| **[RG-6]** | Total du panier recalcule apres chaque changement : `SUM(unit_price_cents * quantity + modifier_extras)` sur tous les articles |
| **[POST-1]** | Aucune ecriture en base ; etat en memoire du panier mis a jour |
| **[OUT-1]** | Recapitulatif du panier affiche avec total TTC |
| **[ERR-1]** | Si un produit passe a `is_available = 0` entre le chargement du catalogue et la soumission de la commande, la validation cote serveur dans CREATE_ORDER le detecte |

---

### 3.3 CREATE_ORDER

**Correspond a la section 3.3 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | Le panier contient au moins 1 article (`items.length >= 1`) |
| **[PRE-2]** | Le numero de commande saisi par le client est non vide (validation front-end) |
| **[PRE-3]** | Le corps JSON du POST est valide (validation de schema a la couche API) |
| **[RG-1]** | Verification de disponibilite cote serveur : pour chaque article, verifier `product.is_available = 1` ou `menu.is_available = 1`. Si un article est indisponible, rejeter avec la liste des articles indisponibles. |
| **[RG-2 — service_day]** | Le `service_day` d'une commande donnee est calcule a l'execution de la requete comme : `CASE WHEN HOUR(created_at) < 10 THEN DATE(created_at) - INTERVAL 1 DAY ELSE DATE(created_at) END`. La coupure est a 10:00. Ce n'est PAS stocke comme colonne — calcule uniquement a l'execution de la requete. La formule v0.1 avec `INTERVAL 4 HOUR 30 MINUTE` etait incorrecte et est abandonnee. |
| **[RG-3 — order number]** | Format du numero de commande : `K-YYYY-MM-DD-NNN` ou NNN est le compteur sequentiel pour le service_day courant pour la source `kiosk` (SELECT COUNT + 1 avec un verrou au niveau table ou un insert serialise pour eviter une generation en double sous concurrence). La source est `kiosk` (definie par l'endpoint kiosk, derivee du point d'entree public). |
| **[RG-4 — VAT by line]** | Pour chaque `order_item` : `vat_rate_snapshot` est copie depuis `product.vat_rate`. Montants de ligne : `unit_ttc = unit_price_cents_snapshot` ; `unit_ht = ROUND(unit_ttc * 1000 / (1000 + vat_rate_snapshot))` ; `unit_vat = unit_ttc - unit_ht`. Totaux de commande : `total_ttc_cents = SUM(unit_ttc * quantity)` sur toutes les lignes ; `total_ht_cents = SUM(unit_ht * quantity)` ; `total_vat_cents = total_ttc_cents - total_ht_cents`. Invariant : `total_ttc_cents = total_ht_cents + total_vat_cents` (verifie avant l'INSERT). |
| **[RG-5 — atomic transaction]** | Toutes les ecritures dans une seule transaction de base de donnees : (1) INSERT `customer_order` (status `pending_payment`, source `kiosk`, service_mode depuis le panier, totaux calcules) ; (2) INSERT des lignes `order_item` (label_snapshot, unit_price_cents_snapshot, vat_rate_snapshot, quantity, format, item_type, product_id ou menu_id) ; (3) INSERT des lignes `order_item_selection` pour chaque slot rempli dans un article menu (order_item_id, menu_slot_id, product_id, label_snapshot) ; (4) INSERT des lignes `order_item_modifier` pour chaque modification d'ingredient (order_item_id, ingredient_id, action, extra_price_cents snapshot) ; (5) pour chaque ingredient consomme : calculer units = `(order_item.format = 'maxi' ? product_ingredient.quantity_maxi : product_ingredient.quantity_normal) * order_item.quantity`, ajuste par les modificateurs (remove => pas de decrement pour cet ingredient ; add => decrement supplementaire) ; appliquer le decrement atomique `UPDATE ingredient SET stock_quantity = stock_quantity - :units WHERE id = :id` (instruction unique auto-verrouillante, sans lecture-gate prealable, RG-T20) ; `stock_quantity` est signe et peut devenir negatif (ampleur de survente, remontee aux managers) — le decrement ne se conditionne pas a un plancher ; INSERT `stock_movement` (type `sale`, delta = -units, order_id, user_id = NULL pour le kiosk) ; (6) UPDATE `customer_order` SET status = `paid`, `paid_at = NOW()`. Les six etapes committent ensemble ou sont entierement annulees. |
| **[RG-6 — cross-constraint]** | La source `kiosk` n'implique aucune contrainte particuliere de service_mode ; le client selectionne `dine_in` ou `takeaway`. La contrainte croisee drive (RG-T09) ne s'applique pas aux commandes provenant du kiosk. |
| **[RG-7 — immutability]** | Apres l'INSERT, `label_snapshot`, `unit_price_cents_snapshot` et `vat_rate_snapshot` ne sont pas modifies meme si le produit source est renomme ou voit son prix change plus tard (voir RG-T05). |
| **[RG-8 — idempotency]** | Le corps porte un `idempotency_key` client (UUID). Avant toute ecriture, `SELECT id, order_number, status FROM customer_order WHERE idempotency_key = :key`. Si trouve, sauter la creation et retourner cette commande (deduplique un retry rejoue — RG-T19). La cle est stockee sur la nouvelle ligne `customer_order`. |
| **[RG-9 — server-side modificateur re-validation]** | Les modificateurs d'ingredient dans le corps sont re-valides cote serveur contre `product_ingredient` : un `action='remove'` requiert `is_removable=1` ; un `action='add'` requiert `is_addable=1` et snapshote le `extra_price_cents` courant. Les verifications cote client (3.2 RG-4) ne sont pas dignes de confiance ; un POST forge ajoutant un ingredient non addable est rejete (HTTP 422). |
| **[RG-10 — atomic stock decrement]** | Aucune operation ne se conditionne a une lecture de stock, donc le decrement est une instruction atomique unique `UPDATE ingredient SET stock_quantity = stock_quantity - :units WHERE id = :id` (RG-T20). La ligne s'auto-verrouille pour la duree de la mise a jour, donc les commandes kiosk concurrentes sur le meme ingredient appliquent leurs deltas sans perte de mise a jour et sans souci d'ordonnancement de deadlock ; `stock_quantity` est signe et peut devenir negatif (ampleur de survente remontee aux managers). |
| **[POST-1]** | Une ligne `customer_order` existe avec `status = 'paid'`, `source = 'kiosk'`, tous les totaux calcules, `paid_at` defini, `idempotency_key` stocke. La phase `pending_payment` n'est pas observable hors de la transaction. |
| **[POST-2]** | N lignes `order_item` existent, chacune referencant soit un `product_id` (item_type='product') soit un `menu_id` (item_type='menu') — contrainte d'exclusivite verifiee. |
| **[POST-3]** | `customer_order.order_number` est unique dans la base (contrainte UNIQUE). |
| **[POST-4]** | `ingredient.stock_quantity` decremente pour chaque unite d'ingredient consommee ; une ligne `stock_movement` de type `sale` par ingredient affecte. |
| **[OUT-1]** | HTTP 201 : `{data: {id: int, order_number: string, status: 'paid'}}` |
| **[OUT-2]** | Evenement logique ORDER_CREATED disponible pour le domaine de preparation (l'affichage de preparation se rafraichit via polling ou push serveur selon l'implementation) |
| **[ERR-1]** | Panier vide : HTTP 422, `{error: {code: "EMPTY_CART"}}` |
| **[ERR-2]** | Article indisponible : HTTP 422, `{error: {code: "ITEM_UNAVAILABLE", items: [...]}}` |
| **[ERR-3]** | Erreur DB / timeout : HTTP 500 avec rollback, `{error: {code: "DB_ERROR"}}` |

---

### 3.4 DISPLAY_CONFIRMATION

**Correspond a la section 3.4 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | CREATE_ORDER a retourne HTTP 201 avec `{id, order_number, status: 'paid'}` |
| **[RG-1]** | Numero de commande affiche de maniere proeminente sur l'ecran de confirmation |
| **[RG-2]** | Apres un delai configurable (suggestion : 15 secondes), le kiosk se reinitialise automatiquement pour le client suivant |
| **[POST-1]** | Aucune ecriture en base |
| **[OUT-1]** | Ecran de confirmation affiche avec le numero de commande |
| **[ERR-1]** | Si la reponse de l'API est une erreur : message d'erreur generique affiche avec une option de reessai |

---

## 4. Domaine 2 — Cycle de vie de la commande (comptoir et drive)

### 4.1 CREATE_COUNTER_ORDER

**Correspond a la section 4.1 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | L'acteur est authentifie (session valide, `user.is_active = 1`) |
| **[PRE-2]** | L'acteur detient la permission `order.create` (verifiee via `role_permission`) |
| **[PRE-3]** | Le panier contient au moins 1 article |
| **[RG-1]** | Logique de creation identique a CREATE_ORDER (RG-1 a RG-7 s'appliquent), avec les differences suivantes : `source` est auto-tagguee depuis `role.order_source` (role comptoir -> `counter`, role drive -> `drive`) ; `service_mode` est selectionne par le membre du personnel (`dine_in` / `takeaway` / `drive`) ; `user_id` est defini a l'id de l'utilisateur authentifie dans les lignes `stock_movement` (au lieu de NULL pour le kiosk). |
| **[RG-2 — cross-constraint]** | Si `source = 'drive'` alors `service_mode` doit etre `'drive'` (RG-T09) ; verifie avant l'INSERT. HTTP 422 si viole. |
| **[RG-3 — order number]** | Format : `C-YYYY-MM-DD-NNN` pour la source comptoir ; `D-YYYY-MM-DD-NNN` pour la source drive. Le compteur sequentiel NNN est par source par service_day. |
| **[RG-4 — stock]** | Meme logique de decrement de stock que CREATE_ORDER RG-5 ; `stock_movement.user_id` est defini a l'id du membre du personnel authentifie. |
| **[RG-5 — staff attribution + decrement]** | `customer_order.acting_user_id` est defini a l'id du membre du personnel authentifie (imputabilite ciblee sur les commandes comptoir/drive ; les commandes kiosk restent NULL). La re-validation des modificateurs cote serveur (3.3 RG-9), l'idempotence (RG-T19) et le decrement de stock atomique (RG-T20) s'appliquent a l'identique. Aucun PIN n'est requis pour creer une commande (la permission `order.create` suffit) ; la creation de commande n'est pas dans l'ensemble des actions sensibles. |
| **[POST-1]** | Une ligne `customer_order` avec `status = 'paid'`, `source = 'counter'` ou `'drive'`, `paid_at` defini, `acting_user_id` defini. |
| **[POST-2]** | N lignes `order_item` avec snapshots. Selections de slot et modificateurs ecrits a l'identique du flux kiosk. |
| **[POST-3]** | Stock decremente ; mouvements journalises avec l'acteur `user_id`. |
| **[OUT-1]** | HTTP 201 : `{data: {id: int, order_number: string, status: 'paid'}}`. Numero de commande communique au client. |
| **[ERR-1]** | Memes cas d'erreur que CREATE_ORDER (ERR-1, ERR-2, ERR-3) |
| **[ERR-2]** | Violation de contrainte croisee (`source = drive` mais `service_mode != drive`) : HTTP 422, `{error: {code: "INVALID_SERVICE_MODE"}}` |

---

## 5. Domaine 3 — Affichage de preparation (cuisine)

### 5.1 LIST_ORDERS_DISPLAY

**Correspond a la section 5.1 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | L'acteur est authentifie, `is_active = 1` |
| **[PRE-2]** | L'acteur detient la permission `order.read` |
| **[RG-1 — source filter]** | Recuperer les sources visibles pour le role de l'acteur : `SELECT source FROM role_visible_source WHERE role_id = :role_id`. La cuisine voit les trois ; le comptoir voit `kiosk` et `counter` ; le drive voit `drive`. |
| **[RG-2 — query]** | `SELECT customer_order.*, order_item.* FROM customer_order JOIN order_item ON order_item.order_id = customer_order.id WHERE customer_order.status = 'paid' AND customer_order.source IN (:visible_sources) ORDER BY customer_order.paid_at ASC` |
| **[RG-3 — item detail]** | Pour chaque ligne de commande de type `menu`, charger aussi les lignes `order_item_selection` (choix de slot). Pour toutes les lignes, charger les lignes `order_item_modifier` (modifications d'ingredient). L'affichage utilise les snapshots (`label_snapshot`, `quantity`, `format`) ; aucune re-jointure sur les tables `product` ou `menu` necessaire. |
| **[RG-4 — KDS colour]** | Indicateur de couleur calcule au rendu : `elapsed = NOW() - customer_order.paid_at` ; vert si elapsed < seuil SLA (configurable, approx. 10 min) ; ambre si en approche ; rouge si depasse. Non stocke ; calcule cote client ou en PHP avant la reponse. |
| **[RG-5 — read only]** | Le personnel de cuisine n'effectue aucune transition de statut depuis cette vue. Aucun UPDATE n'est emis par cette operation. |
| **[POST-1]** | Aucune ecriture en base |
| **[OUT-1]** | Liste des commandes au statut `paid`, filtree par role, triee par `paid_at` croissant, avec le detail complet des articles (selections, modificateurs, couleur KDS) |

---

## 6. Domaine 4 — Remise au client

### 6.1 DELIVER_ORDER

**Correspond a la section 6.1 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | L'acteur est authentifie, detient la permission `order.deliver` |
| **[PRE-2]** | La commande ciblee existe et `status = 'paid'` |
| **[PRE-3]** | La source de la commande est dans les sources visibles de l'acteur (verifiee via `role_visible_source`) |
| **[RG-1]** | `UPDATE customer_order SET status = 'delivered', delivered_at = NOW(), updated_at = NOW() WHERE id = :id AND status = 'paid'` |
| **[RG-2 — concurrency]** | La clause `AND status = 'paid'` dans l'UPDATE protege contre une double remise concurrente : si deux membres du personnel cliquent simultanement, seul le premier reussit (le second recoit 0 ligne affectee). |
| **[RG-3]** | `delivered` est un statut terminal : aucune transition ulterieure n'est definie depuis ce statut (contrainte applicative, pas appliquee comme trigger DB). |
| **[POST-1]** | `customer_order.status = 'delivered'`, `delivered_at` defini, cycle de vie complet. La commande passe a l'historique. |
| **[OUT-1]** | HTTP 200 avec confirmation. La commande disparait de la file `paid`. |
| **[ERR-1]** | Transition invalide (le statut n'etait pas `paid` au moment de l'execution de l'UPDATE — concurrence) : HTTP 409, `{error: {code: "INVALID_TRANSITION"}}` |
| **[ERR-2]** | Source de commande hors des sources visibles de l'acteur : HTTP 403, `{error: {code: "FORBIDDEN"}}` |

---

## 7. Domaine 5 — Annulation

### 7.1 CANCEL_ORDER

**Correspond a la section 7.1 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | L'acteur est authentifie, detient la permission `order.cancel` |
| **[PRE-2]** | La commande ciblee existe |
| **[PRE-3]** | `customer_order.status` est dans `['pending_payment', 'paid']`. Les statuts terminaux `delivered` et `cancelled` ne peuvent pas transiter vers `cancelled`. |
| **[RG-1 — status update]** | `UPDATE customer_order SET status = 'cancelled', cancelled_at = NOW(), updated_at = NOW() WHERE id = :id AND status IN ('pending_payment', 'paid')` |
| **[RG-2 — concurrency]** | La clause `AND status IN (...)` protege contre une annulation concurrente (voir RG-T07). |
| **[RG-3 — stock re-credit — conditional]** | Le re-credit ne s'applique que si la commande etait au statut `paid` avant l'annulation. Les commandes a `pending_payment` n'avaient pas encore decremente le stock (le decrement a lieu a la transition `paid`). Pour chaque ligne `order_item` d'une commande `paid`, recalculer les unites d'ingredient consommees : `(order_item.format = 'maxi' ? product_ingredient.quantity_maxi : product_ingredient.quantity_normal) * order_item.quantity`, ajuste par les lignes `order_item_modifier` (modificateur remove -> l'ingredient n'a pas ete decremente, donc pas de re-credit ; modificateur add -> l'ingredient avait un decrement supplementaire, donc re-credit supplementaire). UPDATE `ingredient.stock_quantity += units`. INSERT `stock_movement` (type `cancellation`, delta = +units, order_id, user_id de l'acteur). |
| **[RG-4 — transaction]** | La mise a jour du statut et le re-credit de stock (quand applicable) s'executent dans la meme transaction de base de donnees (RG-T11). |
| **[RG-5 — history]** | La commande n'est pas physiquement supprimee ; conservee pour l'historique et les stats. Les commandes annulees sont exclues des totaux de chiffre d'affaires mais incluses dans les comptes de volume dans READ_STATS. Les lignes `order_item` ne sont pas supprimees (ON DELETE CASCADE n'est pas declenche) ; elles permettent de reconstruire ce qui a ete commande. |
| **[RG-6 — PIN + audit]** | L'annulation est une action sensible de manipulation d'argent : elle requiert le PIN propre a chaque membre du personnel (RG-T13) et ecrit une ligne `audit_log` dans la meme transaction (RG-T14) : `action_code='order.cancel'`, `entity_type='customer_order'`, `entity_id=:id`, `summary` avec le statut anterieur et le montant re-credite. |
| **[POST-1]** | `customer_order.status = 'cancelled'`, `cancelled_at` defini, etat terminal. Une ligne `audit_log` enregistree avec le personnel agissant. |
| **[POST-2]** | Si le statut anterieur etait `paid` : `ingredient.stock_quantity` re-credite ; une ligne `stock_movement` de type `cancellation` par ingredient affecte. |
| **[OUT-1]** | HTTP 200 avec confirmation d'annulation |
| **[ERR-1]** | Tentative d'annulation d'une commande livree ou deja annulee : HTTP 422, `{error: {code: "CANNOT_CANCEL_IN_STATE", current_status: "..."}}` |
| **[ERR-2]** | Annulation concurrente (0 ligne affectee par l'UPDATE) : HTTP 409, `{error: {code: "INVALID_TRANSITION"}}` |

---

## 8. Domaine 6 — Gestion du catalogue

### 8.1 CREATE_PRODUCT

**Correspond a la section 8.1 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | Acteur authentifie, detient la permission `product.create` |
| **[PRE-2]** | `category_id` reference une categorie existante avec `is_active = 1` |
| **[RG-1]** | Validation du formulaire : `name` non vide, `price_cents > 0`, `category_id` valide, `vat_rate` dans `(55, 100)` |
| **[RG-2]** | Upload d'image (optionnel) : valider le type MIME (JPEG, PNG, WEBP), taille max configurable (suggestion : 2 MB), stocker sous `UPLOAD_DIR/products/`, enregistrer le chemin relatif dans `image_path` |
| **[RG-3]** | `is_available = 1` par defaut a l'INSERT |
| **[RG-4]** | `display_order` defini a `MAX(display_order) + 1` pour la categorie cible, ou 0 si premier produit |
| **[POST-1]** | Une ligne `product` dans la base avec tous les champs valides |
| **[OUT-1]** | Redirection vers la liste des produits de la categorie avec message de succes |
| **[ERR-1]** | Echec de validation : erreurs de champ affichees en ligne |
| **[ERR-2]** | Image invalide (type ou taille) : message d'erreur specifique |

---

### 8.2 UPDATE_PRODUCT

**Correspond a la section 8.2 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | Acteur authentifie, detient la permission `product.update` |
| **[PRE-2]** | Le `product.id` cible existe |
| **[RG-1]** | Memes validations que CREATE_PRODUCT sur les champs modifies |
| **[RG-2]** | Si une nouvelle image est uploadee, l'ancien fichier image est supprime du systeme de fichiers (nettoyage du volume) |
| **[RG-3]** | `label_snapshot`, `unit_price_cents_snapshot`, `vat_rate_snapshot` dans les lignes `order_item` historiques ne sont pas modifies (voir RG-T05) |
| **[RG-4 — PIN + audit + allowlist]** | Un changement de prix/TVA est une action sensible : il requiert le PIN propre a chaque membre du personnel (RG-T13) et ecrit une ligne `audit_log` (RG-T14) avec `action_code='product.update'`, `entity_type='product'`, `entity_id=:id`, et un `summary` enregistrant les valeurs modifiees (ex. `price_cents 880 -> 920`). Seules les colonnes en allowlist (`name`, `description`, `price_cents`, `vat_rate`, `image_path`, `is_available`, `display_order`, `category_id`) sont liees depuis la requete (RG-T16). |
| **[POST-1]** | `product` mis a jour, `updated_at` rafraichi ; une ligne `audit_log` enregistree |
| **[OUT-1]** | Redirection vers la liste des produits avec message de succes |

---

### 8.3 DELETE_PRODUCT

**Correspond a la section 8.3 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | Acteur authentifie, detient la permission `product.delete` |
| **[PRE-2]** | Le `product.id` cible existe |
| **[RG-1]** | Pre-verification (PHP) : le produit est-il reference dans `menu_slot_option.product_id` ? Si oui, afficher un message bloquant listant les menus. |
| **[RG-2]** | Pre-verification (PHP) : le produit est-il le `burger_product_id` d'un `menu` ? Si oui, bloquer avec un message invitant a supprimer ou reaffecter le menu d'abord. |
| **[RG-3]** | Pre-verification (PHP) : le produit est-il reference dans `order_item.product_id` (commandes historiques) ? La FK `ON DELETE RESTRICT` bloque au niveau DB. Reponse recommandee : proposer la desactivation (`is_available=0`) plutot que la suppression. |
| **[RG-4]** | Les contraintes FK (`menu_slot_option.product_id ON DELETE RESTRICT`, `order_item.product_id ON DELETE RESTRICT`) appliquent la contrainte meme si la verification PHP est contournee. |
| **[RG-5 — PIN + audit]** | La suppression est une action sensible : elle requiert le PIN propre a chaque membre du personnel (RG-T13) et ecrit une ligne `audit_log` (RG-T14) avec `action_code='product.delete'`, `entity_type='product'`, `entity_id=:id`, `summary` capturant le nom du produit avant suppression (enregistre avant que la ligne ne soit retiree). |
| **[POST-1]** | Produit supprime si aucune contrainte FK ne bloquait ; une ligne `audit_log` enregistree |
| **[OUT-1]** | Redirection vers la liste des produits avec message de succes |
| **[ERR-1]** | Produit dans un slot de menu : HTTP 422 ou message en ligne avec la liste des menus bloquants |
| **[ERR-2]** | Produit dans des commandes historiques : message proposant la desactivation a la place |

---

### 8.4 CREATE_MENU

**Correspond a la section 8.4 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | Acteur authentifie, detient la permission `menu.create` |
| **[PRE-2]** | `burger_product_id` reference un produit existant et disponible |
| **[PRE-3]** | Au moins un `menu_slot` est defini avec au moins une `menu_slot_option` |
| **[RG-1]** | Validation : `name` non vide, `price_normal_cents > 0`, `price_maxi_cents > 0`, `burger_product_id` valide, toutes les valeurs `product_id` des options de slot existent |
| **[RG-2]** | Transaction : INSERT `menu`, puis INSERT des lignes `menu_slot` (name, slot_type, is_required, display_order), puis INSERT des lignes `menu_slot_option` (menu_slot_id, product_id) |
| **[RG-3]** | Valeurs `slot_type` valides (depuis l'ENUM du dictionnaire) : `drink`, `side`, `sauce`, `dessert`, `extra` |
| **[POST-1]** | Une ligne `menu`, N lignes `menu_slot`, M lignes `menu_slot_option` dans la base |
| **[OUT-1]** | Redirection vers la liste des menus avec message de succes |
| **[ERR-1]** | Configuration invalide (pas de slot, pas d'option) : message d'erreur metier |
| **[ERR-2]** | Produit d'option de slot indisponible : avertissement (le menu peut etre cree ; la disponibilite du produit est verifiee au moment de la commande) |

---

### 8.5 UPDATE_MENU

**Correspond a la section 8.5 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | Acteur authentifie, detient la permission `menu.update` |
| **[PRE-2]** | Le `menu.id` cible existe |
| **[RG-1]** | Memes validations que CREATE_MENU sur les champs modifies |
| **[RG-2]** | Si la configuration de slot est modifiee : `DELETE FROM menu_slot_option WHERE menu_slot_id IN (SELECT id FROM menu_slot WHERE menu_id = :id)`, puis `DELETE FROM menu_slot WHERE menu_id = :id`, puis re-INSERT (pattern delete-and-reinsert, atomique en transaction) |
| **[RG-3]** | Les valeurs `label_snapshot` dans les lignes `order_item_selection` historiques ne sont pas affectees (voir RG-T05) |
| **[POST-1]** | `menu` mis a jour ; `menu_slot` et `menu_slot_option` reconstruits |
| **[OUT-1]** | Redirection avec message de succes |

---

### 8.6 DELETE_MENU

**Correspond a la section 8.6 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | Acteur authentifie, detient la permission `menu.delete` |
| **[PRE-2]** | Le `menu.id` cible existe |
| **[RG-1]** | Pre-verification (PHP) : le menu est-il reference dans `order_item.menu_id` ? FK `ON DELETE RESTRICT`. Si oui, proposer la desactivation (`is_available=0`) au lieu de la suppression. |
| **[RG-2]** | Si aucune reference historique : DELETE `menu` declenche un CASCADE vers `menu_slot` (qui cascade vers `menu_slot_option`) |
| **[RG-3 — PIN + audit]** | La suppression est une action sensible : PIN propre a chaque membre du personnel (RG-T13) + une ligne `audit_log` (RG-T14), `action_code='menu.delete'`, `entity_type='menu'`, `entity_id=:id`, `summary` capturant le nom du menu avant suppression. |
| **[POST-1]** | `menu`, ses lignes `menu_slot` et ses lignes `menu_slot_option` supprimes ; une ligne `audit_log` enregistree |
| **[OUT-1]** | Redirection avec message de succes |
| **[ERR-1]** | Menu dans des commandes historiques : message proposant la desactivation a la place |

---

### 8.7 MANAGE_CATEGORY

**Correspond a la section 8.7 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | Acteur authentifie, detient la permission `category.manage` |
| **[RG-CREATE]** | `name` et `slug` non vides et uniques dans la base ; `display_order` defini a MAX + 1 |
| **[RG-UPDATE]** | UPDATE `name`, `slug`, `image_path`, `display_order`, `is_active` |
| **[RG-DEACTIVATE]** | La desactivation (`is_active=0`) ne desactive pas automatiquement les produits/menus enfants dans la DB (pas de CASCADE sur `is_active`). La couche PHP propose a l'admin de desactiver aussi les produits/menus enfants, ou le filtre kiosk sur `category.is_active = 1` les masque implicitement. |
| **[RG-DELETE]** | Suppression physique bloquee si `product.category_id` ou `menu.category_id` reference cette categorie (FK `ON DELETE RESTRICT`). Proposer la desactivation. |
| **[POST-CREATE]** | Nouvelle ligne `category` dans la base |
| **[POST-UPDATE]** | `category` mise a jour, `updated_at` rafraichi |
| **[OUT-1]** | Confirmation, redirection vers la liste des categories |

---

### 8.8 MANAGE_INGREDIENT

**Correspond a la section 8.8 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | Acteur authentifie, detient la permission `ingredient.manage` |
| **[RG-CREATE-ING]** | `name` non vide et UNIQUE ; `unit` non vide ; `pack_size >= 1` ; `stock_capacity >= 1` (la reference 100%) ; `low_stock_pct` et `critical_stock_pct` dans 0-100 avec `critical_stock_pct < low_stock_pct` (defauts 10 / 5) ; `stock_quantity` par defaut a 0 a la creation |
| **[RG-UPDATE-ING]** | UPDATE `name`, `unit`, `pack_size`, `pack_label`, `stock_capacity`, `low_stock_pct`, `critical_stock_pct`, `is_active` |
| **[RG-DEACTIVATE-ING]** | `is_active=0` masque l'ingredient du configurateur. Suppression physique bloquee si reference dans `product_ingredient` (FK `ON DELETE RESTRICT`) ou `stock_movement` (FK `ON DELETE RESTRICT`). |
| **[RG-COMPOSITION]** | UPDATE `product_ingredient` : pour chaque ingredient de la recette d'un produit, definir `quantity_normal`, `quantity_maxi`, `is_removable`, `is_addable`, `extra_price_cents`. Pattern delete-and-reinsert en transaction. |
| **[RG-ALLERGEN]** | Gerer `ingredient_allergen` : INSERT ou DELETE des paires `(ingredient_id, allergen_id)`. La liste des allergenes est en lecture seule (14 lignes fixees par le reglement UE 1169/2011). |
| **[POST-1]** | Lignes `ingredient` / `product_ingredient` / `ingredient_allergen` mises a jour |
| **[OUT-1]** | Confirmation, redirection vers la liste des ingredients ou le formulaire de composition de produit |

---

## 9. Domaine 7 — Gestion du stock

### 9.1 RESTOCK

**Correspond a la section 9.1 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | Acteur authentifie, detient la permission `stock.manage` |
| **[PRE-2]** | L'ingredient cible existe et `is_active = 1` |
| **[PRE-3]** | Nombre de packs `N >= 1` |
| **[RG-1]** | `delta = N * ingredient.pack_size` |
| **[RG-2]** | Transaction : `UPDATE ingredient SET stock_quantity = stock_quantity + :delta WHERE id = :id` ; INSERT `stock_movement` (ingredient_id, movement_type=`restock`, delta=+delta, order_id=NULL, user_id=acteur, note=optionnelle) |
| **[RG-3]** | `stock_movement` est append-only : aucun UPDATE ou DELETE sur cette table (les corrections sont de nouvelles lignes) |
| **[POST-1]** | `ingredient.stock_quantity` incremente de `delta`. Une ligne `stock_movement` de type `restock` inseree. |
| **[OUT-1]** | Confirmation avec le nouveau niveau de stock affiche |

---

### 9.2 INVENTORY_COUNT

**Correspond a la section 9.2 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | Acteur authentifie, detient la permission `stock.count` |
| **[PRE-2]** | L'ingredient cible existe |
| **[PRE-3]** | `actual_quantity >= 0` (le comptage physique est non negatif) |
| **[RG-1]** | `delta = actual_quantity - ingredient.stock_quantity` (peut etre negatif si actual < theorique) |
| **[RG-2]** | Transaction : `UPDATE ingredient SET stock_quantity = :actual_quantity WHERE id = :id` ; INSERT `stock_movement` (ingredient_id, movement_type=`inventory_correction`, delta=calcule, order_id=NULL, user_id=acteur, note=optionnelle) |
| **[RG-3]** | `delta = 0` est une correction valide (le comptage physique correspond au theorique) ; une ligne de mouvement est tout de meme inseree pour la completude de l'audit |
| **[RG-4 — PIN attribution]** | Une correction d'inventaire peut masquer de la demarque, elle requiert donc le PIN propre a chaque membre du personnel (RG-T13). Le `user_id` capture par PIN est ecrit dans `stock_movement.user_id`, rendant la correction imputable a une personne meme sur un poste de travail partage. Pas de ligne `audit_log` separee (la trace `stock_movement` l'enregistre deja). |
| **[POST-1]** | `ingredient.stock_quantity = actual_quantity`. Une ligne `stock_movement` de type `inventory_correction` inseree avec le `user_id` agissant. |
| **[OUT-1]** | Confirmation avec le niveau de stock reconcilie et l'ecart affiches |

---

### 9.3 READ_STOCK

**Correspond a la section 9.3 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | Acteur authentifie, detient la permission `stock.read` |
| **[RG-1]** | `SELECT * FROM ingredient WHERE is_active = 1 ORDER BY name ASC` |
| **[RG-2]** | Bandes de stock calculees au rendu depuis les seuils en pourcentage : `low_stock: true` quand `stock_quantity <= stock_capacity * low_stock_pct / 100`, `critical_stock: true` quand `stock_quantity <= stock_capacity * critical_stock_pct / 100` ; `stock_pct = ROUND(stock_quantity / stock_capacity * 100)` est aussi retourne. Non stockees comme colonnes. |
| **[RG-3]** | Historique optionnel des mouvements pour un ingredient donne : `SELECT * FROM stock_movement WHERE ingredient_id = :id ORDER BY created_at DESC LIMIT :n` |
| **[RG-4 — attribution visibility]** | Le `stock_movement.user_id` (qui a reapprovisionne / qui a corrige) est inclus pour `manager`/`admin` uniquement ; le personnel de ligne (`kitchen`/`counter`/`drive`) voit les deltas de mouvement sans l'identite de l'acteur. Cela limite l'exposition intra-equipe tout en preservant l'imputabilite pour ceux qui gerent. L'allowlist `details` est appliquee a la couche de requete/serialisation. |
| **[POST-1]** | Aucune ecriture en base |
| **[OUT-1]** | Liste des ingredients avec `stock_quantity`, `stock_capacity`, `stock_pct` calcule, `low_stock_pct`, `critical_stock_pct`, `pack_size`, `pack_label`, drapeaux `low_stock` / `critical_stock` ; historique des mouvements avec l'acteur visible pour manager/admin uniquement |

---

## 10. Domaine 8 — Gestion des utilisateurs et des roles

### 10.1 CREATE_USER

**Correspond a la section 10.1 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | Acteur authentifie, detient la permission `user.create` |
| **[PRE-2]** | L'email n'existe pas deja dans `user.email` (contrainte UNIQUE) |
| **[PRE-3]** | `role_id` reference un role existant et actif |
| **[RG-1]** | Validation : `email` conforme a la RFC 5321 (PHP `FILTER_VALIDATE_EMAIL`), `first_name` et `last_name` non vides, `role_id` valide |
| **[RG-2]** | Hachage du mot de passe : `password_hash($password, PASSWORD_ARGON2ID)`. Longueur minimale du mot de passe : 8 caracteres. |
| **[RG-3]** | `is_active = 1` par defaut ; `last_login_at = NULL` a la creation |
| **[RG-4 — PIN + audit + allowlist]** | Creer un compte back-office est une action sensible : PIN propre a chaque membre du personnel (RG-T13) + une ligne `audit_log` (RG-T14), `action_code='user.create'`, `entity_type='user'`, `entity_id=:new_id`, `details` enregistrant le `role_id` assigne (noms de champs/role, pas le mot de passe). Seules les colonnes en allowlist sont liees (RG-T16) : `email`, `first_name`, `last_name`, `role_id` (+ le mot de passe hache) ; `is_active` et tout autre champ sont definis cote serveur, pas lies a la requete. |
| **[POST-1]** | Une ligne `user` avec `password_hash` argon2id, `role_id` valide ; une ligne `audit_log` enregistree |
| **[OUT-1]** | Redirection vers la liste des utilisateurs avec message de succes |
| **[ERR-1]** | Email en doublon : message "Cet email est deja utilise" |
| **[ERR-2]** | Mot de passe trop court : message de validation en ligne |

---

### 10.2 UPDATE_USER

**Correspond a la section 10.2 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | Acteur authentifie, detient la permission `user.update` |
| **[PRE-2]** | Le `user.id` cible existe |
| **[RG-1]** | Si un nouveau mot de passe est fourni (champ non vide) : re-hacher via `PASSWORD_ARGON2ID` et remplacer le hash existant |
| **[RG-2]** | Si le champ mot de passe est vide : le hash existant est preserve inchange |
| **[RG-3]** | Mise a jour d'email soumise a la contrainte UNIQUE (pre-verification avant l'UPDATE) |
| **[RG-4 — PIN + audit + allowlist]** | Editer un compte (incl. `role_id`, le vecteur d'escalade de privileges) est sensible : PIN propre a chaque membre du personnel (RG-T13) + une ligne `audit_log` (RG-T14), `action_code='user.update'`, `entity_type='user'`, `entity_id=:id`, `details` listant les noms des champs modifies (pas les valeurs, pas de PII). Seules les colonnes en allowlist sont liees (RG-T16) : `first_name`, `last_name`, `email`, `role_id`, `is_active` (+ re-hachage optionnel du mot de passe). |
| **[POST-1]** | `user` mis a jour, `updated_at` rafraichi ; une ligne `audit_log` enregistree |
| **[OUT-1]** | Redirection avec message de succes |

---

### 10.3 DEACTIVATE_USER

**Correspond a la section 10.3 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | Acteur authentifie, detient la permission `user.deactivate` |
| **[PRE-2]** | L'acteur ne cible pas son propre compte (`$targetUserId !== $currentUserId`) |
| **[RG-1]** | `UPDATE user SET is_active = 0, updated_at = NOW() WHERE id = :id` |
| **[RG-2]** | La session potentiellement active de l'utilisateur est invalidee a la requete suivante : le middleware verifie `user.is_active = 1` a chaque requete authentifiee |
| **[RG-3 — PIN + audit]** | Action sensible : PIN propre a chaque membre du personnel (RG-T13) + une ligne `audit_log` (RG-T14), `action_code='user.deactivate'`, `entity_type='user'`, `entity_id=:id`. |
| **[POST-1]** | `user.is_active = 0` ; l'utilisateur ne peut plus se connecter ; l'historique reste intact ; une ligne `audit_log` enregistree |
| **[OUT-1]** | Redirection avec message de succes |
| **[ERR-1]** | Tentative d'auto-desactivation : HTTP 403, `{error: {code: "SELF_DEACTIVATION_FORBIDDEN"}}` |

---

### 10.4 MANAGE_RBAC

**Correspond a la section 10.4 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | Acteur authentifie, detient la permission `role.manage` |
| **[PRE-2]** | Le `role.id` cible existe (pour la mise a jour des permissions) ou les champs du role sont valides (pour la creation de role) |
| **[PRE-3]** | Toutes les valeurs `permission_id` soumises existent dans le catalogue `permission` |
| **[RG-1 — permissions]** | Transaction : `DELETE FROM role_permission WHERE role_id = :id` ; INSERT des nouvelles paires `(role_id, permission_id)` pour chaque permission selectionnee |
| **[RG-2]** | Les permissions ne sont pas modifiables via cette operation : elles sont en lecture seule pour peupler le formulaire de selection. Le catalogue de permissions est fige au seed. |
| **[RG-3]** | L'effet est immediat pour les nouvelles requetes ; les sessions des utilisateurs portant ce role voient le changement a la prochaine verification de permission (les sessions stockent `role_id` ; les permissions sont rechargees depuis la DB a chaque verification). |
| **[RG-4 — custom role]** | Creer un role personnalise : INSERT `role` (code UNIQUE, label, description, default_route nullable, order_source nullable) ; INSERT des lignes `role_visible_source` selon le besoin. |
| **[RG-5 — order_source]** | `role.order_source` controle l'auto-tagging de `customer_order.source` lorsque ce role cree une commande. NULL pour admin et manager (ils peuvent creer au nom de n'importe quel canal). |
| **[RG-6 — PIN + audit change-log]** | Les changements RBAC sont a fort impact (escalade de privileges) : PIN propre a chaque membre du personnel (RG-T13) + une ligne `audit_log` (RG-T14) par changement, `action_code='role.manage'`, `entity_type='role'`, `entity_id=:role_id`. Comme les permissions sont reecrites en delete-and-reinsert (RG-1), le `details` JSON enregistre le **diff** — codes de permission ajoutes et retires — calcule avant la reecriture, de sorte que la trace montre exactement quelles capacites un role a gagnees ou perdues et qui les a accordees. |
| **[POST-1]** | `role_permission` reflete exactement les permissions selectionnees pour ce role ; une ligne `audit_log` enregistree avec le diff de permissions |
| **[OUT-1]** | Redirection avec message de succes |

---

### 10.5 ERASE_USER_PII (anonymisation RGPD)

**Operation security-by-design (pas de predecesseur MCT v0.1 / v0.2). Honore le droit a
l'effacement RGPD (Cr 3.d) sans casser l'integrite referentielle ni la trace d'audit (note 13 du dict.).**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | Acteur authentifie, detient la permission `user.update` (l'effacement est une operation admin) |
| **[PRE-2]** | PIN propre a chaque membre du personnel verifie (RG-T13) — action sensible |
| **[PRE-3]** | Le `user.id` cible existe et `anonymized_at IS NULL` (pas deja anonymise) |
| **[RG-1 — anonymise, not delete]** | En une transaction : `UPDATE user SET email = CONCAT('anon-', id, '@wakdo.invalid'), first_name = '', last_name = '', password_hash = '', pin_hash = NULL, password_reset_token_hash = NULL, is_active = 0, anonymized_at = NOW() WHERE id = :id`. Le domaine placeholder est reserve par la RFC 2606 (`.invalid`), garde `email` UNIQUE et non identifiant. |
| **[RG-2 — preserve links]** | La ligne persiste, donc les FK pointant vers elle (`stock_movement.user_id`, `customer_order.acting_user_id`, `audit_log.actor_user_id`) restent valides et resolvent desormais vers un principal anonymise. L'imputabilite des actions passees est preservee dans sa forme (qui-en-tant-qu'id) sans conserver de PII. |
| **[RG-3 — audit]** | Une ligne `audit_log` (RG-T14) : `action_code='user.erase_pii'`, `entity_type='user'`, `entity_id=:id`. Le `summary`/`details` enregistrent l'evenement d'effacement et sa base legale, pas les valeurs effacees. |
| **[POST-1]** | Ligne `user` anonymisee : champs PII vides/placeholders, identifiants invalides, `anonymized_at` defini, `is_active = 0`. Liens referentiels intacts. |
| **[OUT-1]** | Confirmation ; l'utilisateur disparait des listes actives, demeure comme tombstone anonymise dans l'historique. |
| **[ERR-1]** | Deja anonymise : HTTP 409, `{error: {code: "ALREADY_ANONYMISED"}}` |

---

## 11. Domaine 9 — Stats et KPI

### 11.1 READ_STATS

**Correspond a la section 11.1 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | Acteur authentifie, detient la permission `stats.read` |
| **[RG-1 — service_day]** | Expression `service_day` utilisee dans toutes les agregations de stats : `CASE WHEN HOUR(customer_order.created_at) < 10 THEN DATE(customer_order.created_at) - INTERVAL 1 DAY ELSE DATE(customer_order.created_at) END`. Coupure a 10:00. Pas de colonne stockee. La formule v0.1 avec `INTERVAL 4 HOUR 30 MINUTE` est abandonnee. |
| **[RG-2 — revenue]** | Les requetes de chiffre d'affaires filtrent `status != 'cancelled'` ; elles somment `total_ttc_cents` depuis `customer_order`. Les commandes annulees sont exclues du chiffre d'affaires mais apparaissent dans les comptes de volume avec le filtre `status = 'cancelled'`. |
| **[RG-3 — top products]** | `SELECT label_snapshot, SUM(quantity) AS total_sold FROM order_item JOIN customer_order ON ... WHERE customer_order.status != 'cancelled' GROUP BY label_snapshot ORDER BY total_sold DESC LIMIT 10` |
| **[RG-4 — delivery time KPI]** | Temps de livraison moyen : `AVG(TIMESTAMPDIFF(SECOND, paid_at, delivered_at))` sur les commandes avec `status = 'delivered'`. Reference SLA approx. 10 min (configurable). |
| **[RG-5 — breakdown]** | Ventilations disponibles par `source` (kiosk/counter/drive) et `service_mode` (dine_in/takeaway/drive) pour la planification de capacite. `service_mode` ne porte aucun role fiscal (voir note 9 du dictionnaire). |
| **[POST-1]** | Aucune ecriture en base |
| **[OUT-1]** | Donnees du tableau de bord de stats : chiffre d'affaires par service_day, comptes de commandes, top produits, taux d'annulation, temps de livraison moyen, ventilation par source/service_mode |

---

## 12. Domaine 10 — Authentification back-office

### 12.1 AUTHENTICATE_USER

**Correspond a la section 12.1 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | Formulaire de connexion soumis avec email et mot de passe |
| **[PRE-2]** | Le token CSRF du formulaire est valide (protection anti-CSRF) |
| **[PRE-3 — throttle gate]** | Si le compte est dans une fenetre de throttling (`user.lockout_until IS NOT NULL AND lockout_until > NOW()`), rejeter avec l'erreur generique avant toute verification de mot de passe. Le throttling est aussi cle par IP source via la table `login_throttle` : si une ligne existe pour l'IP source avec `lockout_until IS NOT NULL AND lockout_until > NOW()`, rejeter avec la meme erreur generique, de sorte que les tentatives distribuees sur de nombreux comptes sont ralenties elles aussi. |
| **[RG-1]** | Recherche : `SELECT * FROM user WHERE email = :email AND is_active = 1 LIMIT 1` |
| **[RG-2]** | Verification du mot de passe : `password_verify($password, $user->password_hash)`. En cas d'echec : meme erreur generique que l'email n'existe pas ou que le mot de passe soit faux (protection contre l'enumeration d'emails). Pour garder un timing comparable lorsque l'email est inconnu, un `password_verify` factice contre un hash leurre fixe est execute. |
| **[RG-3]** | En cas de succes : `session_regenerate(true)` (regeneration de l'ID de session, protection contre la fixation de session) |
| **[RG-4]** | Stockage de session : `$_SESSION['user_id']`, `$_SESSION['role_id']`, `$_SESSION['logged_in_at']` |
| **[RG-5]** | UPDATE : `UPDATE user SET last_login_at = NOW() WHERE id = :id` |
| **[RG-6]** | Timeouts de session : timeout d'inactivite 4h (detection via timestamp de derniere activite en session) ; timeout absolu 10h (detection via `logged_in_at`) |
| **[RG-7]** | La cible de redirection est `role.default_route` (dynamique ; aucun nom de role en dur dans la logique de routage) |
| **[RG-8 — failure handling, degressive backoff]** | A une verification echouee, le compteur par compte sur `user` : `UPDATE user SET failed_login_attempts = failed_login_attempts + 1, last_failed_login_at = NOW()`, et une fois un seuil atteint (suggestion : 5) definir `lockout_until = NOW() + INTERVAL (base * 2^(attempts - threshold)) SECOND`, plafonne (suggestion : plafond de quelques minutes). Dans la meme etape, la dimension par IP est enregistree dans la table `login_throttle` : upsert de la ligne cle sur `ip_address` (insert si absente, sinon incrementer `failed_attempts` ; reinitialiser la fenetre quand elle a expire via `window_started_at`), mettre a jour `last_attempt_at = NOW()`, et une fois le seuil IP atteint definir `lockout_until` avec le meme backoff degressif. C'est un backoff degressif, pas un verrouillage indefini — il ralentit la force brute sans laisser une serie de fautes de frappe priver de service une cuisine en plein rush. Ecrire une ligne `audit_log` (`action_code='auth.login_failed'`, `actor_user_id` si l'email a ete resolu, sinon NULL). |
| **[RG-9 — success reset]** | En cas de succes, reinitialiser le compteur par compte `failed_login_attempts = 0`, effacer `lockout_until = NULL`, et effacer aussi la ligne `login_throttle` par IP pour l'IP source (reinitialiser `failed_attempts = 0`, `lockout_until = NULL`, redemarrer `window_started_at`), puis ecrire une ligne `audit_log` (`action_code='auth.login_success'`, `actor_user_id`, `actor_role_id`). |
| **[POST-1]** | Session PHP ouverte avec `user_id` et `role_id` ; `user.last_login_at` mis a jour ; `failed_login_attempts` reinitialise |
| **[OUT-1]** | Redirection vers `role.default_route` |
| **[ERR-1]** | Identifiants incorrects ou compte inactif : message generique "Email ou mot de passe incorrect" (aucune distinction pour eviter l'enumeration) ; compteur d'echec incremente (RG-8) |
| **[ERR-2]** | Token CSRF invalide : HTTP 403 |
| **[ERR-3]** | Compte dans une fenetre de throttling (PRE-3) : meme message generique ; la tentative ne revele pas que le compte existe ou est verrouille |

---

### 12.2 LOGOUT_USER

**Correspond a la section 12.2 du MCT**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | Session valide ouverte (`session_id()` non vide, `$_SESSION['user_id']` present) |
| **[RG-1]** | `$_SESSION = []` (effacer les donnees de session) |
| **[RG-2]** | Si un cookie de session existe, l'expirer : `setcookie(session_name(), '', time() - 3600, '/', '', true, true)` |
| **[RG-3]** | `session_destroy()` |
| **[POST-1]** | Session PHP detruite ; aucun acces authentifie possible avec l'ancien cookie |
| **[OUT-1]** | Redirection vers la page de connexion |

---

### 12.3 RESET_PASSWORD

**Operation security-by-design (pas de predecesseur v0.1). Deux phases : demande, puis confirmation.**

| Marqueur | Contenu |
|-----|---------|
| **[PRE-1]** | Phase de demande : un `user` soumet le formulaire "mot de passe oublie" avec un email ; token CSRF valide |
| **[RG-1 — request, enumeration-safe]** | Rechercher l'email. La meme reponse neutre ("si le compte existe, un email a ete envoye") est retournee que l'email existe ou non, pour eviter l'enumeration de compte. |
| **[RG-2 — token generation]** | Si l'email resout vers un utilisateur actif : generer un token aleatoire cryptographique (ex. 32 octets depuis un CSPRNG) ; stocker son **hash** dans `password_reset_token_hash` et `password_reset_expires_at = NOW() + INTERVAL 1 HOUR`. Le token **brut** est envoye une seule fois dans le lien de reinitialisation (pas stocke en clair). |
| **[PRE-2]** | Phase de confirmation : l'utilisateur ouvre le lien de reinitialisation avec le token brut et soumet un nouveau mot de passe ; token CSRF valide |
| **[RG-3 — confirm]** | Hacher le token soumis et le comparer a `password_reset_token_hash` ou `password_reset_expires_at > NOW()`. En cas de correspondance : `password_hash = password_hash($new, PASSWORD_ARGON2ID)` (longueur min 8), puis effacer `password_reset_token_hash = NULL` et `password_reset_expires_at = NULL`, et reinitialiser `failed_login_attempts = 0`, `lockout_until = NULL`. Usage unique. |
| **[RG-4 — audit]** | Ecrire une ligne `audit_log` (RG-T14), `action_code='auth.password_reset'`, `actor_user_id = :id`. |
| **[POST-1]** | Mot de passe remplace par un nouveau hash argon2id ; token de reinitialisation consomme et efface |
| **[OUT-1]** | Confirmation ; redirection vers la connexion |
| **[ERR-1]** | Token invalide ou expire : message generique invitant a une nouvelle demande de reinitialisation (aucun detail sur la condition qui a echoue) |

Ces traitements sont executes par le conteneur de service `wakdo-cron` dans la fenetre de
maintenance 01:30-09:30 (hors service actif). Ils sont hors du perimetre du MCT (traitements
techniques, pas de declencheur utilisateur) mais sont documentes ici par coherence avec PROJECT_CONTEXT.

### 13.1 Agregation des stats (cron 04:30)

| Marqueur | Contenu |
|-----|---------|
| **[TRIGGER]** | Cron : `30 4 * * *` |
| **[RG-1]** | `service_day` a agreger : calcule par commande (voir RG-1 de READ_STATS). A 04:30 le service_day en cours est le jour calendaire precedent. |
| **[RG-2]** | Agregations par `service_day` : nombre de commandes, chiffre d'affaires TTC (somme `total_ttc_cents` ou `status != 'cancelled'`), top produits (par `label_snapshot`, COUNT dans `order_item`) |
| **[POST-1]** | Stats disponibles pour le tableau de bord admin (requetes directes sur `customer_order` filtrees par `service_day`, ou une table d'agregation si implementee) |

### 13.2 Purge des sessions expirees (cron toutes les 15 min)

| Marqueur | Contenu |
|-----|---------|
| **[TRIGGER]** | Cron : `*/15 * * * *` |
| **[RG-1]** | Sessions basees fichier (par defaut) : `find /tmp/sessions -mmin +240 -delete` |
| **[RG-2]** | Sessions basees DB (option) : `DELETE FROM php_sessions WHERE updated_at < NOW() - INTERVAL 4 HOUR` |
| **[POST-1]** | Sessions expirees supprimees ; les utilisateurs inactifs depuis plus de 4h sont forces de se reconnecter |

### 13.3 Sauvegarde DB (cron 03:00)

| Marqueur | Contenu |
|-----|---------|
| **[TRIGGER]** | Cron : `0 3 * * *` |
| **[RG-1]** | `mysqldump` de la base `wakdo` vers un fichier date dans le volume de sauvegarde |
| **[RG-2]** | Retention : garder les 7 derniers dumps ; supprimer les plus anciens |
| **[POST-1]** | Dump SQL disponible pour restauration |

### 13.4 Purge de retention du journal d'audit (cron quotidien)

| Marqueur | Contenu |
|-----|---------|
| **[TRIGGER]** | Cron : `15 4 * * *` (fenetre de maintenance) |
| **[RG-1]** | `DELETE FROM audit_log WHERE created_at < NOW() - INTERVAL :retention_months MONTH` (suggestion : 12 mois, interet legitime / tracabilite fiscale — configurable dans `.env`). |
| **[RG-2]** | La fenetre est decouplee du cycle de vie des PII utilisateur : l'anonymisation (10.5) retire les PII immediatement sur demande, tandis que la trace d'audit vieillit selon son propre calendrier (note 13 du dict.). |
| **[POST-1]** | Lignes `audit_log` plus anciennes que la fenetre de retention retirees ; imputabilite recente preservee. |

### 13.5 Purge de login_throttle (cron quotidien)

| Marqueur | Contenu |
|-----|---------|
| **[TRIGGER]** | Cron : `45 4 * * *` (fenetre de maintenance) |
| **[RG-1]** | `DELETE FROM login_throttle WHERE (lockout_until IS NULL OR lockout_until < NOW()) AND last_attempt_at < NOW() - INTERVAL 24 HOUR` — purger les lignes sans verrouillage actif dont la derniere tentative echouee est plus ancienne que 24h. |
| **[RG-2]** | Les lignes servant encore un verrouillage actif sont conservees ; le compteur par IP (S1) est borne par cette purge de sorte que la table ne croit pas de maniere illimitee a cause de tentatives ponctuelles. |
| **[POST-1]** | Lignes `login_throttle` obsoletes retirees ; throttles actifs et activite recente preserves. |

---

## 14. Machine a etats — recapitulatif de coherence (MLT)

Recapitulatif des transitions de `customer_order.status` couvertes dans le MLT, avec les operations
correspondantes, la condition SQL, la protection de concurrence et le timestamp de phase defini.

| Transition | Operation MLT | Condition SQL | Protection concurrence | Timestamp de phase pose |
|------------|--------------|---------------|------------------------|---------------------|
| `-> pending_payment` (creation) | CREATE_ORDER (3.3), CREATE_COUNTER_ORDER (4.1) | INSERT avec statut `pending_payment` | Transaction atomique | `created_at` |
| `pending_payment -> paid` | CREATE_ORDER (3.3), CREATE_COUNTER_ORDER (4.1) | UPDATE dans la meme transaction | Transaction atomique | `paid_at` |
| `paid -> delivered` | DELIVER_ORDER (6.1) | `WHERE status = 'paid'` | status dans le WHERE (clause AND) | `delivered_at` |
| `pending_payment/paid -> cancelled` | CANCEL_ORDER (7.1) | `WHERE status IN ('pending_payment', 'paid')` | status dans le WHERE (clause AND) | `cancelled_at` |

Statuts terminaux (aucune transition ulterieure definie depuis ces etats) : `delivered`, `cancelled`.

**Abandonnes depuis v0.1** :
- Transitions `paid -> preparing` et `preparing -> ready` — etats intermediaires retires.
- MARQUER_EN_PREPARATION (section 4.2 du MLT v0.1) — abandonnee.
- MARQUER_PRETE (section 4.3 du MLT v0.1) — abandonnee.
- `preparing` et `ready` dans l'ensemble des etats annulables — l'ensemble annulable est desormais
  `['pending_payment', 'paid']` uniquement.
- Table `commande_event` et RG-T10 v0.1 — remplacees par les timestamps de phase sur `customer_order`.

---

## 15. Notes residuelles et points ouverts

### 15.1 `service_day` — non materialise comme colonne

Le calcul de `service_day` est documente (RG-2 de CREATE_ORDER, RG-1 de READ_STATS) :
`CASE WHEN HOUR(created_at) < 10 THEN DATE(created_at) - INTERVAL 1 DAY ELSE DATE(created_at) END`
(coupure 10:00). Il est calcule a l'execution de la requete, pas stocke. Pour les requetes de stats
a haute frequence, une colonne generee MariaDB `VIRTUAL` ou `STORED` pourrait etre ajoutee au moment du DDL pour eviter
un recalcul par ligne, mais ce n'est pas un bloquant pour le perimetre RNCP.
La formule v0.1 avec `INTERVAL 4 HOUR 30 MINUTE` etait incorrecte et est abandonnee.

### 15.2 `order_item_modifier` pour les articles menu

Pour une ligne de menu (`item_type='menu'`), les modificateurs ciblent le burger fixe identifie via
`order_item.menu_id -> menu.burger_product_id`. La contrainte que les modificateurs ne referencent
que des ingredients appartenant au `product_ingredient` du burger est appliquee a la
couche applicative, pas a la couche FK de la DB (voir note 10 du dictionnaire). C'est un
compromis connu : une FK multi-colonnes ou un trigger DB serait necessaire pour l'appliquer au niveau DB.
Le documenter comme un invariant applicatif est l'approche retenue pour le perimetre de ce projet.

### 15.3 Compteur NNN de numero de commande — concurrence

Le compteur sequentiel NNN par `(source, service_day)` pourrait produire des doublons sous
forte concurrence s'il est implemente naivement comme `SELECT COUNT + 1`. L'implementation
recommandee au moment du DDL/code est soit : (a) un verrou consultatif au niveau table autour de la
sequence count-and-insert ; soit (b) une table de sequence dediee avec un increment atomique.
La contrainte UNIQUE sur `order_number` fournit le garde-fou de dernier recours (l'INSERT echouerait
et l'application reessaie). Ce n'est pas un bloquant pour le volume de la demo RNCP.
