# Dictionnaire de Donnees — Wakdo

**Phase Merise** : P1 - Conception, etape 1 (dictionnaire de donnees d'abord, mantra #33)
**Version** : v0.3 — prod-like, 22 entites (19 prod-like + couche security-by-design, incl. les entites `login_throttle` et `pin_throttle`)
**Date** : 2026-06-04 (ajouts security-by-design 2026-06-11)
**Branche** : `feat/p1-conception`
**Statut** : prod-like — toutes les decisions D1-D8 + stock appliquees (voir `docs/notes/revue-alignement-p1.md` §7) ; couche security-by-design en cours (voir note 13) ; colonnes additives post-v0.3 des migrations 0003/0005/0006/0007 alignees sur le deploye (voir note 14)
**Auteur** : BYAN (couche methodologie)

---

## 1. Objectif

Ce dictionnaire liste **toutes les entites de donnees** identifiees pour Wakdo, avec leurs attributs,
types, contraintes et sources. Il sert de base au MCD (entites + relations),
puis au MLD (mapping relationnel), puis au DDL (SQL CREATE TABLE).

**Methodologie** : derivation bottom-up depuis les sources disponibles :
- **Source ecole** : `docs/merise/_sources/categories.json` + `produits.json`
  (66 produits, 9 categories)
- **Brief metier** : `docs/PROJECT_CONTEXT.md` (composition du menu, flux de commande, RBAC,
  modes de service)
- **Maquette** : `docs/design/maquette-borne.pdf` (UX borne, ecrans visibles)

Tous les ecarts entre la source ecole et le modele final sont documentes dans la
section "Notes de modelisation" en bas de ce document.

Pour le diagramme entite-relation et les justifications de cardinalite, voir [`mcd.md`](mcd.md).
Ce dictionnaire ne duplique pas cette vue afin d'eviter des sources de verite divergentes.

---

## 2. Conventions generales

### Nommage

- **Tables** : `snake_case`, singulier (ex. `category`, `product`, `customer_order`).
  Le singulier reflete la perspective "1 ligne = 1 instance de l'entite" (convention relationnelle
  standard). Le code applicatif (PHP, JS) utilise ces noms tels quels via le mapping ORM.
- **Colonnes** : `snake_case`. Suffixes typiques : `_id` (FK), `_at` (timestamp),
  `_cents` (montant monetaire en centimes entiers), `_path` (chemin de fichier), `_rate` (taux ou
  fraction stocke en entier pour-mille).
- **Cles primaires** : colonne `id` (INT UNSIGNED AUTO_INCREMENT). Pas de PK composite sauf
  sur les tables de jointure pures.
- **Cles etrangeres** : `<referenced_table>_id` (ex. `category_id` dans `product`).
- **Valeurs ENUM** : anglais, snake_case (ex. `pending_payment`, `dine_in`, `kiosk`).
- **Chaines cote code** (ENUM, codes de permission, codes de role) : anglais uniquement, coherentes
  entre la BDD, PHP et l'API JSON.

### Types par defaut

| Categorie | Type MariaDB | Justification |
|---|---|---|
| Identifiants | `INT UNSIGNED AUTO_INCREMENT` | 4 milliards d'ids — suffisant pour ce projet |
| Libelles courts | `VARCHAR(120)` | Couvre la plupart des noms de produits (max observe : 41 caracteres dans la source ecole) |
| Descriptions | `TEXT` | Longueur variable, sans limite stricte |
| Montants monetaires | `INT UNSIGNED` (cents) | Evite les bugs d'arrondi FLOAT (voir note 1) |
| Booleens | `TINYINT(1)` | Convention MariaDB pour `BOOLEAN` (alias) |
| Horodatages | `DATETIME` | Lisible par l'humain, fuseau horaire gere au niveau applicatif |
| Enumerations | `ENUM('a','b','c')` | Contrainte au niveau SGBD, lisible (voir note 2) |
| Chemins de fichiers | `VARCHAR(255)` | Limite standard de longueur de chemin POSIX |

### Charset et collation

- **Charset** : `utf8mb4` (RFC 3629 — vrai UTF-8 sur 4 octets, supporte emoji et caracteres asiatiques).
  MariaDB gere `utf8mb4` nativement.
- **Collation** : `utf8mb4_unicode_ci` (insensible a la casse, comparaison conforme Unicode).

### Champs d'audit (presents sur toutes les tables metier sauf les tables de jointure pures)

| Colonne | Type | Default | Role |
|---|---|---|---|
| `created_at` | `DATETIME` | `CURRENT_TIMESTAMP` | Timestamp de creation, ecrit une fois a l'insertion |
| `updated_at` | `DATETIME` | `CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | Timestamp de derniere modification, mis a jour automatiquement |

### Soft delete

Pas de soft delete generalise. Les entites qui peuvent etre temporairement desactivees portent une
colonne booleenne `is_active` ou `is_available`. Le `DELETE` dur reste possible mais est
reserve aux operations admin avec sauvegarde prealable.

---

## 3. Entites

### 3.1 `category`

Regroupement metier de produits et de menus pour l'affichage sur la borne.

| Attribut | Type | NULL | Default | Contrainte | Source ecole | Notes |
|---|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | `id` (1-9) | identique a la source |
| `name` | VARCHAR(60) | NO | — | UNIQUE | `title` | renomme depuis `title` |
| `slug` | VARCHAR(60) | NO | — | UNIQUE | derive de `title` (kebab-case minuscule) | utilise pour l'URL `/api/categories/burgers` |
| `image_path` | VARCHAR(255) | YES | NULL | — | `image` | chemin relatif, voir note 8 |
| `display_order` | SMALLINT UNSIGNED | NO | 0 | — | (ajoute) | ordre d'affichage sur la borne, ajustable depuis l'admin |
| `is_active` | TINYINT(1) | NO | 1 | — | (ajoute) | desactiver sans supprimer |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | — | audit |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | — | — | audit |

**Exemples** : `menus`, `drinks`, `burgers`, `fries`, `snacks`, `wraps`, `salads`,
`desserts`, `sauces`. Volume : 9 lignes a l'init (seed depuis `categories.json`).

---

### 3.2 `product`

Un article vendable unique, disponible a la carte ou comme composant dans un slot de menu.

| Attribut | Type | NULL | Default | Contrainte | Source ecole | Notes |
|---|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | `id` | identique a la source |
| `category_id` | INT UNSIGNED | NO | — | FK -> `category(id)`, ON DELETE RESTRICT | (derive de la cle d'objet JSON) | |
| `name` | VARCHAR(120) | NO | — | INDEX | `nom` | renomme depuis `nom` |
| `description` | TEXT | YES | NULL | — | (ajoute) | renseigne plus tard via l'admin |
| `price_cents` | INT UNSIGNED | NO | — | CHECK > 0 | `prix` (FLOAT) | conversion FLOAT -> INT centimes au seed (voir note 1) |
| `maxi_variant_product_id` | INT UNSIGNED | YES | NULL | FK -> `product(id)`, ON DELETE SET NULL | (migration 0006) | auto-reference : variante servie quand un menu est commande au format Maxi (ex. Moyenne Frite -> Grande Frite). Data-driven (la regle vit dans la donnee). SET NULL = degradation gracieuse : si la variante Grande est retiree du catalogue, le produit de base reste vendable, il perd seulement sa substitution Maxi. Voir note 14 |
| `size_cl` | SMALLINT UNSIGNED | YES | NULL | — | (migration 0007) | variante de TAILLE a la carte : volume en centilitres d'une boisson fontaine (ex. 30 / 50 cl). NULL = produit sans dimension taille (bouteille, non-boisson). La ligne de base ET la variante portent leur volume pour l'affichage du picker. Voir note 14 |
| `base_product_id` | INT UNSIGNED | YES | NULL | FK -> `product(id)`, ON DELETE CASCADE | (migration 0007) | auto-reference vers la ligne de base d'une variante de taille. NULL = produit de base ou autonome (visible dans la grille catalogue) ; NON NULL = variante de taille (masquee de la grille, atteinte via le picker). CASCADE : une variante de taille n'a pas de sens sans sa base (suppression de la base -> suppression de ses variantes). Voir note 14 |
| `vat_rate` | SMALLINT UNSIGNED | NO | 100 | CHECK IN (55, 100) | (ajoute) | taux de TVA en pour-mille : 100 = 10%, 55 = 5,5%. Defaut 10%. Voir note 9 |
| `image_path` | VARCHAR(255) | YES | NULL | — | `image` | chemin relatif, voir note 8 |
| `is_available` | TINYINT(1) | NO | 1 | — | (ajoute) | bascule de disponibilite manuelle depuis l'admin |
| `display_order` | SMALLINT UNSIGNED | NO | 0 | — | (ajoute) | ordre d'affichage au sein de la categorie |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | — | audit |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | — | — | audit |

**Volume** : ~53 lignes a l'init (66 lignes dans `produits.json` moins 13 menus deplaces vers `menu`).

---

### 3.3 `menu`

Combo a prix fixe construit autour d'un burger specifique, avec des slots selectionnables par le client
(boisson, accompagnement, sauce). Deux paliers de prix : Normal et Maxi.

| Attribut | Type | NULL | Default | Contrainte | Source ecole | Notes |
|---|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | `id` (1-13 dans la categorie `menus`) | |
| `category_id` | INT UNSIGNED | NO | — | FK -> `category(id)`, ON DELETE RESTRICT | implicite (categorie `menus`) | |
| `burger_product_id` | INT UNSIGNED | NO | — | FK -> `product(id)`, ON DELETE RESTRICT | (ajoute) | le burger fixe qui ancre ce menu ; pilote la personnalisation des ingredients |
| `name` | VARCHAR(120) | NO | — | INDEX | `nom` | ex. "Menu Le 280" |
| `description` | TEXT | YES | NULL | — | (ajoute) | |
| `price_normal_cents` | INT UNSIGNED | NO | — | CHECK > 0 | `prix` | prix format Normal. Remplace le `prix_ttc_cents` unique. |
| `price_maxi_cents` | INT UNSIGNED | NO | — | CHECK > 0 | (ajoute) | prix format Maxi (~+150 centimes vs normal ; voir note 7) |
| `image_path` | VARCHAR(255) | YES | NULL | — | `image` | reutilise generalement l'image du burger |
| `is_available` | TINYINT(1) | NO | 1 | — | (ajoute) | |
| `display_order` | SMALLINT UNSIGNED | NO | 0 | — | (ajoute) | |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | — | audit |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | — | — | audit |

**Volume** : 13 lignes a l'init. Remplace l'ancien modele `menu_produit` a composition fixe.

---

### 3.4 `menu_slot`

Un slot selectionnable au sein d'un menu (ex. "slot boisson", "slot accompagnement", "slot sauce").
Chaque slot contraint les produits parmi lesquels le client peut choisir, exprimes via
la table de jointure `menu_slot_option`.

| Attribut | Type | NULL | Default | Contrainte | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `menu_id` | INT UNSIGNED | NO | — | FK -> `menu(id)`, ON DELETE CASCADE | un slot appartient a exactement un menu |
| `name` | VARCHAR(80) | NO | — | — | ex. "Drink", "Side", "Sauce" |
| `slot_type` | ENUM('drink','side','sauce','dessert','extra') | NO | — | — | role semantique de ce slot |
| `is_required` | TINYINT(1) | NO | 1 | — | indique si le client doit remplir ce slot |
| `display_order` | SMALLINT UNSIGNED | NO | 0 | — | ordre d'affichage dans le constructeur de menu |

**Pas de champs d'audit** : un slot fait partie de la definition du menu ; cree et mis a jour avec le menu.
**Index composite** : `(menu_id, display_order)`.

---

### 3.5 `menu_slot_option`

Produits eligibles pour un slot de menu donne. Table de jointure pure.

| Attribut | Type | NULL | Default | Contrainte | Notes |
|---|---|---|---|---|---|
| `menu_slot_id` | INT UNSIGNED | NO | — | FK -> `menu_slot(id)`, ON DELETE CASCADE | |
| `product_id` | INT UNSIGNED | NO | — | FK -> `product(id)`, ON DELETE RESTRICT | RESTRICT : retirer un produit ne doit pas casser silencieusement les menus |

**Cle primaire** : composite `(menu_slot_id, product_id)`.

**Volume** : ~3-5 options par slot, ~3 slots par menu, 13 menus = ~120-200 lignes a l'init.

---

### 3.6 `ingredient`

Ingredient elementaire utilise dans la composition des produits. Porte les donnees de stock.

| Attribut | Type | NULL | Default | Contrainte | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `name` | VARCHAR(120) | NO | — | UNIQUE | ex. "Sesame Bun", "Cheddar Slice", "Ketchup Portion" |
| `unit` | VARCHAR(40) | NO | — | — | libelle de l'unite de conditionnement : piece / portion / sachet 1kg / pot / bouteille (libelle libre, pas un ENUM — les unites varient par ingredient) |
| `stock_quantity` | INT (signed) | NO | 0 | — | stock courant en unites. INT signe sans `CHECK >= 0` : il PEUT devenir negatif quand les ventes depassent le stock compte (ampleur de la survente, remontee aux managers). Le systeme ne bloque pas une commande sur le stock. |
| `stock_capacity` | INT | NO | — | CHECK > 0 | niveau "plein" de reference en unites = les 100% servant a calculer le pourcentage de stock. Le `CHECK > 0` protege aussi la division du pourcentage contre la division par zero |
| `pack_size` | SMALLINT UNSIGNED | NO | 1 | CHECK > 0 | unites par pack de reapprovisionnement (ex. 100 pour un sac de 100 portions) |
| `pack_label` | VARCHAR(80) | YES | NULL | — | libelle humain du pack (ex. "Sac 100 portions") |
| `energy_kcal_100g` | SMALLINT UNSIGNED | YES | NULL | — | enrichissement nutritionnel (migration 0005) : apport energetique pour 100 g, importe depuis l'API externe OpenFoodFacts (Cr 3.a.3). Nullable : un ingredient non enrichi reste valide. Voir note 14 |
| `nutrition_source` | VARCHAR(120) | YES | NULL | — | enrichissement nutritionnel (migration 0005) : provenance de la donnee (ex. "OpenFoodFacts"). Voir note 14 |
| `nutrition_fetched_at` | DATETIME | YES | NULL | — | enrichissement nutritionnel (migration 0005) : horodatage de l'import, pour tracer la fraicheur. Voir note 14 |
| `low_stock_pct` | SMALLINT UNSIGNED | NO | 10 | CHECK BETWEEN 0 AND 100 | bande d’alerte, pourcentage de capacite : `stock_quantity <= stock_capacity * low_stock_pct/100` declenche l'indicateur de stock bas |
| `critical_stock_pct` | SMALLINT UNSIGNED | NO | 5 | CHECK BETWEEN 0 AND 100 | seuil de rupture automatique, pourcentage de capacite : `stock_quantity <= stock_capacity * critical_stock_pct/100` rend le produit calcule en rupture |
| `is_active` | TINYINT(1) | NO | 1 | — | desactiver les ingredients obsoletes sans supprimer |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | audit |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | — | audit |

**CHECK au niveau table** : `critical_stock_pct < low_stock_pct` (le seuil critique se situe sous la bande d’alerte).

**Regle de decrement de stock** : a la transition `paid`, chaque ingredient est decremente de
`product_ingredient.quantity_normal` ou `quantity_maxi` (selectionne par `order_item.format`)
multiplie par `order_item.quantity`, puis ajuste par les lignes `order_item_modifier`. Voir note 7.
**Regle de reapprovisionnement** : `stock_quantity += N * pack_size` (reapprovisionne en packs complets).
**Regle d'annulation** : le stock est recredite quand une commande `paid` est annulee.
**Modele de stock (base sur le pourcentage, trois bandes)** : le seuil d'alerte absolu est remplace par un
modele en pourcentage ancre sur `stock_capacity` (la reference 100%). Le pourcentage de stock est
calcule, non stocke : `stock_pct = ROUND(stock_quantity / stock_capacity * 100)`. Le
`CHECK > 0` sur `stock_capacity` protege cette division contre la division par zero. Trois bandes :
- **Normal** — au-dessus de la bande d’alerte : rien n'est signale.
- **Low** — `stock_quantity <= stock_capacity * low_stock_pct/100` : commandable + alerte manager.
  Le manager retire le produit via `product.is_available=0`, ou reapprovisionne pour lever l'alerte.
- **Critical** — `stock_quantity <= stock_capacity * critical_stock_pct/100` : le produit
  passe automatiquement en rupture (disponibilite calculee, voir regle RG-T21 dans `mlt.md`) ; aucune colonne stockee supplementaire.

---

### 3.7 `product_ingredient`

Composition par defaut d'un produit (burger, wrap, etc.) en termes d'ingredients.
Porte les metadonnees de personnalisation pour le configurateur d'ingredients.

| Attribut | Type | NULL | Default | Contrainte | Notes |
|---|---|---|---|---|---|
| `product_id` | INT UNSIGNED | NO | — | FK -> `product(id)`, ON DELETE CASCADE | |
| `ingredient_id` | INT UNSIGNED | NO | — | FK -> `ingredient(id)`, ON DELETE RESTRICT | RESTRICT : impossible de retirer un ingredient encore reference dans une recette de produit |
| `quantity_normal` | SMALLINT UNSIGNED | NO | 1 | CHECK > 0 | unites consommees en format Normal (ex. 2 pour double cheese) |
| `quantity_maxi` | SMALLINT UNSIGNED | NO | 1 | CHECK > 0 | unites consommees en format Maxi. Egale `quantity_normal` pour les ingredients invariants au format (burger, sauce) ; superieure pour les ingredients d'accompagnement et de boisson (le Maxi agrandit uniquement l'accompagnement + la boisson). Voir note 7. |
| `is_removable` | TINYINT(1) | NO | 1 | — | le client peut retirer cet ingredient sans frais |
| `is_addable` | TINYINT(1) | NO | 0 | — | le client peut ajouter une unite supplementaire de cet ingredient |
| `extra_price_cents` | INT UNSIGNED | NO | 0 | CHECK >= 0 | supplement en centimes quand `is_addable=1` et que le client l'ajoute (0 = extra gratuit) |

**Cle primaire** : composite `(product_id, ingredient_id)`.

**Volume** : ~5-10 ingredients par produit, ~53 produits = ~300-500 lignes au seed.

---

### 3.8 `allergen`

Catalogue des 14 allergenes reglementes (Reglement INCO (UE) 1169/2011).

| Attribut | Type | NULL | Default | Contrainte | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `code` | VARCHAR(30) | NO | — | UNIQUE | code lisible par machine, ex. `gluten`, `milk`, `nuts` |
| `name` | VARCHAR(80) | NO | — | — | nom d'affichage, ex. "Gluten", "Lait", "Fruits a coque" |
| `description` | TEXT | YES | NULL | — | guidance optionnelle pour le personnel |

**Volume** : 14 lignes au seed (fixe par le reglement UE 1169/2011, liste confirmee au moment du seed).
Les allergenes d'un produit sont **calcules** en joignant `product_ingredient` ->
`ingredient_allergen` -> `allergen` ; pas de ressaisie manuelle par produit.

---

### 3.9 `ingredient_allergen`

Indique quels allergenes contient chaque ingredient. Table de jointure pure.

| Attribut | Type | NULL | Default | Contrainte | Notes |
|---|---|---|---|---|---|
| `ingredient_id` | INT UNSIGNED | NO | — | FK -> `ingredient(id)`, ON DELETE CASCADE | |
| `allergen_id` | INT UNSIGNED | NO | — | FK -> `allergen(id)`, ON DELETE RESTRICT | |

**Cle primaire** : composite `(ingredient_id, allergen_id)`.

---

### 3.10 `customer_order`

Transaction client : 1 commande = 1 panier valide a un instant donne.
(Rationale du nom de table : voir note de modelisation 3.)

| Attribut | Type | NULL | Default | Contrainte | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `order_number` | VARCHAR(20) | NO | — | UNIQUE | numero lisible : prefixe canal + id sequentiel, soit `K<id>` / `C<id>` / `D<id>` (K=kiosk, C=counter, D=drive). Ecrit en deux temps (INSERT puis UPDATE avec `LAST_INSERT_ID()`). Voir note 4. |
| `idempotency_key` | VARCHAR(36) | YES | NULL | UNIQUE | UUID genere par le client pour dedupliquer un `POST /api/orders` reessaye (anti-double-charge). UNIQUE rejette les doublons ; plusieurs NULL autorises. Security-by-design, voir note 13 |
| `source` | ENUM('kiosk','counter','drive') | NO | — | INDEX | canal de saisie (qui a saisi la commande). Valeurs en anglais, voir note 5. |
| `acting_user_id` | INT UNSIGNED | YES | NULL | FK -> `user(id)`, ON DELETE SET NULL | personnel back-office (counter/drive) ayant cree la commande, capture sous PIN. NULL pour `kiosk` (anonyme). Imputabilite ciblee sans imposer un login par personne sur la borne. Voir note 13 |
| `service_mode` | ENUM('dine_in','takeaway','drive') | NO | — | — | mode de consommation, conserve pour les stats/KPI uniquement. Aucun role fiscal (voir note 9). La source `drive` implique le service_mode `drive` (contrainte croisee appliquee au niveau applicatif). |
| `service_tag` | VARCHAR(20) | YES | NULL | — | numero de chevalet pour le service EN SALLE (migration 0003), saisi a la borne quand le client choisit `dine_in` ; permet d'apporter la commande a la bonne table (B4). NULL pour `takeaway` / `drive`. Voir note 14 |
| `status` | ENUM('pending_payment','paid','delivered','cancelled') | NO | 'pending_payment' | INDEX | machine a 4 etats : `pending_payment -> paid -> delivered` (+ `cancelled`). Voir note 6. |
| `total_ht_cents` | INT UNSIGNED | NO | — | CHECK >= 0 | total hors TVA, snapshot a la validation de la commande |
| `total_vat_cents` | INT UNSIGNED | NO | — | CHECK >= 0 | montant de TVA, snapshot |
| `total_ttc_cents` | INT UNSIGNED | NO | — | CHECK > 0 | total TTC ; doit egaler total_ht_cents + total_vat_cents (verifie a la couche MLT) |
| `paid_at` | DATETIME | YES | NULL | — | timestamp de la transition vers `paid` (NULL avant paiement) |
| `delivered_at` | DATETIME | YES | NULL | — | timestamp de la transition vers `delivered` (NULL avant la remise) |
| `cancelled_at` | DATETIME | YES | NULL | — | timestamp d'annulation (NULL si non annulee) |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | INDEX | utilise pour les agregations de stats en direct ; sert aussi de base a `service_day` |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | — | audit |

**Retire de v0.1** : `tva_taux_pourmille` (deplace au niveau ligne — `order_item.vat_rate_snapshot`),
`paye_a` (renomme `paid_at`). Etats machine `preparing` et `ready` retires (voir note 6).

**Calcul de `service_day`** (regroupement KPI) :
```
CASE WHEN HOUR(created_at) < 10 THEN DATE(created_at) - INTERVAL 1 DAY ELSE DATE(created_at) END
```
Calcule au moment de la requete, non stocke comme colonne (la formule de colonne generee avec `INTERVAL 4 HOUR
30 MINUTE` dans le MLD v0.1 etait incorrecte et est retiree). Coupure : 10:00.

**Volume** : ~100-300 commandes/jour au pic, ~10k lignes sur une demo de 6 mois.

---

### 3.11 `order_item`

Ligne d'une commande : un seul produit ou un menu, avec prix, libelle et taux de TVA
snapshotes au moment de la transaction.

| Attribut | Type | NULL | Default | Contrainte | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `order_id` | INT UNSIGNED | NO | — | FK -> `customer_order(id)`, ON DELETE CASCADE | |
| `item_type` | ENUM('product','menu') | NO | — | — | discriminateur |
| `product_id` | INT UNSIGNED | YES | NULL | FK -> `product(id)`, ON DELETE RESTRICT | non nul si `item_type = 'product'` |
| `menu_id` | INT UNSIGNED | YES | NULL | FK -> `menu(id)`, ON DELETE RESTRICT | non nul si `item_type = 'menu'` |
| `format` | ENUM('normal','maxi') | NO | 'normal' | — | s'applique aux items menu (Normal / Maxi). Pour les produits autonomes, la valeur est `normal` (pas d'agrandissement individuel dans ce modele). Voir note 7. |
| `label_snapshot` | VARCHAR(120) | NO | — | — | libelle au moment de la commande (preserve si le produit est renomme) |
| `unit_price_cents_snapshot` | INT UNSIGNED | NO | — | CHECK > 0 | prix unitaire TTC au moment de la commande |
| `vat_rate_snapshot` | SMALLINT UNSIGNED | NO | — | CHECK IN (55, 100) | taux de TVA en pour-mille au moment de la commande (snapshote depuis `product.vat_rate`) |
| `quantity` | SMALLINT UNSIGNED | NO | 1 | CHECK > 0 | quantite commandee (ex. 3 Cocas = 1 ligne avec quantity=3) |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | audit |

**Contrainte CHECK** (applicative ou MariaDB CHECK >= 10.2) :
`(item_type='product' AND product_id IS NOT NULL AND menu_id IS NULL)
OR (item_type='menu' AND menu_id IS NOT NULL AND product_id IS NULL)`

**Volume** : ~3-5 lignes par commande -> 30k-50k lignes sur 6 mois.

---

### 3.12 `order_item_selection`

Les choix reels effectues par le client pour chaque slot d'une ligne de menu.
1 ligne = 1 slot rempli pour 1 order_item de type `menu`.

| Attribut | Type | NULL | Default | Contrainte | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `order_item_id` | INT UNSIGNED | NO | — | FK -> `order_item(id)`, ON DELETE CASCADE | doit referencer un order_item avec item_type='menu' |
| `menu_slot_id` | INT UNSIGNED | NO | — | FK -> `menu_slot(id)`, ON DELETE RESTRICT | quel slot a ete rempli |
| `product_id` | INT UNSIGNED | NO | — | FK -> `product(id)`, ON DELETE RESTRICT | produit choisi par le client pour ce slot |
| `label_snapshot` | VARCHAR(120) | NO | — | — | libelle du produit au moment de la commande |

**Volume** : ~2-3 selections par ligne de menu.
**Usage KPI** : permet d'analyser quelles combinaisons boisson/accompagnement sont les plus choisies.

---

### 3.13 `order_item_modifier`

Modifications au niveau ingredient appliquees par le client a un produit ou au burger fixe
d'un menu : retrait (gratuit) ou ajout (avec supplement optionnel).

| Attribut | Type | NULL | Default | Contrainte | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `order_item_id` | INT UNSIGNED | NO | — | FK -> `order_item(id)`, ON DELETE CASCADE | la ligne de commande modifiee (produit ou menu) |
| `ingredient_id` | INT UNSIGNED | NO | — | FK -> `ingredient(id)`, ON DELETE RESTRICT | l'ingredient modifie |
| `action` | ENUM('remove','add') | NO | — | — | `remove` = retrait gratuit ; `add` = unite supplementaire (peut avoir un supplement) |
| `extra_price_cents` | INT UNSIGNED | NO | 0 | CHECK >= 0 | snapshot de `product_ingredient.extra_price_cents` au moment de la commande (0 pour les retraits) |

**Regle de rattachement du modificateur** (voir note de modelisation 10) :
- Pour un produit autonome (`item_type='product'`) : le modificateur cible le produit
  directement via `order_item_id`.
- Pour un menu (`item_type='menu'`) : le modificateur cible le burger fixe de la ligne de menu
  via le meme `order_item_id`. Le burger est identifie par `menu.burger_product_id`,
  permettant a l'affichage cuisine de resoudre sans ambiguite quels ingredients sont modifies.
  Aucune FK supplementaire n'est necessaire : etant donne `order_item_id`, le burger est
  `order_item.menu_id -> menu.burger_product_id`.

**Impact stock** : chaque modificateur affecte le stock d'ingredient a la transition `paid`
(`remove` -> pas de decrement pour cet ingredient ; `add` -> decrement supplementaire).

---

### 3.14 `user`

Utilisateur back-office (admin, manager, personnel cuisine, counter, drive). Les clients de la borne
ne sont pas authentifies et n'ont pas de ligne ici.

| Attribut | Type | NULL | Default | Contrainte | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `email` | VARCHAR(254) | NO | — | UNIQUE | longueur max selon RFC 5321 |
| `password_hash` | VARCHAR(255) | NO | — | — | hash argon2id (voir `PASSWORD_ALGO` dans `.env`) ; longueur typique 96 caracteres, marge jusqu'a 255 |
| `first_name` | VARCHAR(60) | NO | — | — | |
| `last_name` | VARCHAR(60) | NO | — | — | |
| `role_id` | INT UNSIGNED | NO | — | FK -> `role(id)`, ON DELETE RESTRICT | un utilisateur ne peut exister sans role |
| `is_active` | TINYINT(1) | NO | 1 | — | desactivation sans suppression |
| `last_login_at` | DATETIME | YES | NULL | — | utile pour l'audit et la detection de comptes dormants |
| `pin_hash` | VARCHAR(255) | YES | NULL | — | hash argon2id du PIN par membre du personnel qui autorise les actions sensibles (prix/RBAC/utilisateur/annulation/inventaire). NULL = aucun PIN defini. Security-by-design, voir note 13 |
| `failed_login_attempts` | SMALLINT UNSIGNED | NO | 0 | — | logins echoues consecutifs ; pilote le throttling degressif (note 13) |
| `last_failed_login_at` | DATETIME | YES | NULL | — | timestamp du dernier login echoue |
| `lockout_until` | DATETIME | YES | NULL | — | fin de la fenetre de throttling courante (backoff degressif, pas un verrouillage dur indefini) |
| `password_reset_token_hash` | VARCHAR(255) | YES | NULL | — | hash du token de reset (pas le token brut) ; NULL quand aucun reset en attente |
| `password_reset_expires_at` | DATETIME | YES | NULL | — | expiration du token de reset |
| `anonymized_at` | DATETIME | YES | NULL | — | marqueur tombstone RGPD : quand renseigne, les colonnes PII sont mises a NULL/remplacees (note 13). La ligne est conservee pour l'integrite referentielle |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | audit |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | — | audit |

**Volume** : 5-20 lignes (equipe du restaurant + 1-2 admins).

Longueur d'email RFC 5321 : local-part <= 64, domaine <= 255, total <= 254 (incluant `@`).
VARCHAR(254) est la valeur conforme a la spec.

**Colonnes PII** : `email`, `first_name`, `last_name`. Soumises a l'anonymisation RGPD
(voir note 13). `password_hash` et `pin_hash` sont des credentials, tenus hors des logs et
des reponses d'API.

---

### 3.15 `role`

Roles back-office (RBAC). Creables / modifiables / desactivables depuis l'UI admin.
Le seed fournit 5 roles ; des roles personnalises (ex. "chef-patissier") peuvent etre ajoutes sans deploiement.

| Attribut | Type | NULL | Default | Contrainte | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `code` | VARCHAR(40) | NO | — | UNIQUE | code machine, ex. `admin`, `manager`, `kitchen`, `counter`, `drive` |
| `label` | VARCHAR(80) | NO | — | — | nom d'affichage, ex. `Administrator`, `Kitchen Staff` |
| `description` | TEXT | YES | NULL | — | |
| `default_route` | VARCHAR(120) | YES | NULL | — | ecran d'atterrissage pour ce role (ex. `/admin/orders`, `/kitchen/display`). Rend le routage dynamique — pas de noms de role en dur dans le routage front-end. |
| `order_source` | ENUM('kiosk','counter','drive') | YES | NULL | — | `source` auto-taggee quand ce role cree une commande (NULL pour admin/manager qui peuvent creer au nom de n'importe quel canal) |
| `is_active` | TINYINT(1) | NO | 1 | — | la desactivation preserve l'historique des utilisateurs ayant detenu ce role |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | audit |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | — | audit |

**Roles du seed** :
| Code | `default_route` | `order_source` |
|---|---|---|
| `admin` | `/admin/dashboard` | NULL |
| `manager` | `/admin/stats` | NULL |
| `kitchen` | `/kitchen/display` | NULL |
| `counter` | `/counter/orders` | `counter` |
| `drive` | `/drive/orders` | `drive` |

**Regle d'architecture RBAC (P2)** : le code applicatif teste les permissions, pas les noms de role.
Ajouter un nouveau role avec les bonnes permissions ne requiert aucun changement de code (pilote par permission,
non par nom de role — selon le modele RBAC Sandhu/NIST).

---

### 3.16 `role_visible_source`

Definit quelles sources de commande sont visibles sur le tableau de bord de preparation pour un role donne.
Table de jointure pure.

| Attribut | Type | NULL | Default | Contrainte | Notes |
|---|---|---|---|---|---|
| `role_id` | INT UNSIGNED | NO | — | FK -> `role(id)`, ON DELETE CASCADE | |
| `source` | ENUM('kiosk','counter','drive') | NO | — | — | source visible pour ce role sur l'affichage kitchen/counter/drive |

**Cle primaire** : composite `(role_id, source)`.

**Donnees du seed** :
| Role | Sources visibles |
|---|---|
| `kitchen` | kiosk, counter, drive (toutes) |
| `counter` | kiosk, counter |
| `drive` | drive |

---

### 3.17 `permission`

Permissions granulaires assignables aux roles. Le catalogue est fixe au seed (pas de creation via UI).

| Attribut | Type | NULL | Default | Contrainte | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `code` | VARCHAR(60) | NO | — | UNIQUE | format `<resource>.<action>` |
| `label` | VARCHAR(120) | NO | — | — | nom d'affichage |
| `description` | TEXT | YES | NULL | — | |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | audit |

**Catalogue de permissions fixe** (23 codes — gele avant le DDL) :

| Code | Accorde a (defaut seed) |
|---|---|
| `product.create` | admin, manager |
| `product.read` | admin, manager, kitchen, counter, drive |
| `product.update` | admin, manager |
| `product.delete` | admin |
| `menu.create` | admin, manager |
| `menu.read` | admin, manager, kitchen, counter, drive |
| `menu.update` | admin, manager |
| `menu.delete` | admin |
| `category.manage` | admin, manager |
| `ingredient.manage` | admin, manager |
| `stock.read` | admin, manager, kitchen, counter, drive |
| `stock.count` | admin, manager, kitchen, counter, drive |
| `stock.manage` | admin, manager |
| `order.read` | admin, manager, kitchen, counter, drive |
| `order.create` | admin, counter, drive |
| `order.deliver` | admin, counter, drive |
| `order.cancel` | admin, counter, drive |
| `user.create` | admin |
| `user.read` | admin, manager |
| `user.update` | admin |
| `user.deactivate` | admin |
| `role.manage` | admin |
| `stats.read` | admin, manager |

**Volume** : 23 lignes au seed.

---

### 3.18 `role_permission`

Mapping N-N entre roles et permissions. Table de jointure pure.

| Attribut | Type | NULL | Default | Contrainte | Notes |
|---|---|---|---|---|---|
| `role_id` | INT UNSIGNED | NO | — | FK -> `role(id)`, ON DELETE CASCADE | |
| `permission_id` | INT UNSIGNED | NO | — | FK -> `permission(id)`, ON DELETE CASCADE | |

**Cle primaire** : composite `(role_id, permission_id)`.

**Volume** : ~50-100 lignes au seed (admin couvre tout ; les autres couvrent un sous-ensemble).

---

### 3.19 `stock_movement`

Journal d'audit append-only de tous les changements de stock par ingredient.
1 ligne par mouvement (vente, annulation, reapprovisionnement, correction d'inventaire).

| Attribut | Type | NULL | Default | Contrainte | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `ingredient_id` | INT UNSIGNED | NO | — | FK -> `ingredient(id)`, ON DELETE RESTRICT | ingredient affecte |
| `movement_type` | ENUM('sale','cancellation','restock','inventory_correction') | NO | — | INDEX | nature du mouvement |
| `delta` | INT | NO | — | — | changement signe : negatif pour la consommation (vente), positif pour reapprovisionnement/annulation/correction |
| `order_id` | INT UNSIGNED | YES | NULL | FK -> `customer_order(id)`, ON DELETE SET NULL | commande liee pour les mouvements `sale` et `cancellation` ; NULL pour restock/correction |
| `user_id` | INT UNSIGNED | YES | NULL | FK -> `user(id)`, ON DELETE SET NULL | utilisateur ayant declenche le mouvement (NULL pour les decrements de vente automatises) |
| `note` | VARCHAR(255) | YES | NULL | — | note humaine optionnelle (ex. raison de la correction, reference de pack) |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | INDEX | timestamp immuable |

**Immuabilite** : aucun UPDATE ni DELETE sur cette table. Les corrections sont de nouvelles lignes avec
`movement_type='inventory_correction'` et un delta signe.

**Mouvements automatiques** (declenches aux transitions de statut) :
- transition `paid` : 1 ligne `sale` par unite d'ingredient consommee (en tenant compte des modificateurs).
- `cancelled` (depuis `paid`) : 1 ligne `cancellation` par unite d'ingredient recreditee.

**Mouvements manuels** :
- `restock` : le manager ou l'admin enregistre une livraison (`+= N * pack_size`).
- `inventory_correction` : comptage physique matin/soir ; le systeme enregistre l'ecart
  (delta = reel - theorique).

**Volume** : ~5-15 mouvements par commande sur tous les ingredients ; un index sur
`(ingredient_id, created_at)` est recommande pour les requetes d'historique par ingredient.

---

### 3.20 `audit_log`

Journal append-only des **actions back-office sensibles**, pour l'imputabilite la ou elle importe
(menace interne, manipulation d'argent, changements RBAC). Complete `stock_movement` (specifique au
stock) ; couvre les evenements catalogue/prix, utilisateur, role/permission et annulation de commande.
Ajout security-by-design (voir note 13).

| Attribut | Type | NULL | Default | Contrainte | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `actor_user_id` | INT UNSIGNED | YES | NULL | FK -> `user(id)`, ON DELETE SET NULL | personnel ayant effectue l'action, capture via PIN pour les operations sensibles. NULL si non attribuable a un individu |
| `actor_role_id` | INT UNSIGNED | YES | NULL | FK -> `role(id)`, ON DELETE SET NULL | contexte de role au moment de l'action (denormalise pour que la trace survive a l'anonymisation de l'utilisateur) |
| `action_code` | VARCHAR(60) | NO | — | INDEX | code d'operation MCT / de permission, ex. `product.update`, `order.cancel`, `role.manage`, `user.deactivate` |
| `entity_type` | VARCHAR(40) | YES | NULL | — | nom de la table affectee, ex. `product`, `customer_order`, `role`, `user` |
| `entity_id` | INT UNSIGNED | YES | NULL | — | PK de la ligne affectee |
| `summary` | VARCHAR(255) | YES | NULL | — | courte description non personnelle, ex. "price_cents 880 -> 920", "added permission stock.manage" |
| `details` | JSON | YES | NULL | — | diff before/after optionnel. Pour les actions ciblant un utilisateur, stocke les **noms de champs** modifies, pas les valeurs PII |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | INDEX | timestamp immuable |

**Immuabilite** : aucun UPDATE ni DELETE au niveau applicatif (meme discipline que `stock_movement`).
**Index** : `(actor_user_id, created_at)`, `(entity_type, entity_id)`, `(action_code, created_at)`.
**Retention** : fenetre propre (~12 mois, interet legitime / tracabilite fiscale), decouplee
du cycle de vie des PII utilisateur (note 13). Une purge planifiee (cron) retire les lignes au-dela de la fenetre.

**Operations journalisees** (ensemble sensible) : `UPDATE_PRODUCT` (8.2, incl. prix), `DELETE_PRODUCT`
(8.3), `DELETE_MENU` (8.6), `CANCEL_ORDER` (7.1), `RESTOCK` (9.1), `INVENTORY_COUNT` (9.2),
`CREATE_USER` / `UPDATE_USER` / `DEACTIVATE_USER` (10.1-10.3), `MANAGE_RBAC` (10.4).

**Volume** : faible (~10-50 actions sensibles/jour) — des ordres de grandeur sous `stock_movement`.

---

### 3.21 `login_throttle`

Throttle anti-brute-force par IP source. Complete le compteur par compte deja present sur `user`
(`failed_login_attempts` / `lockout_until`), une ligne par IP source. Ajout security-by-design
(voir note 13).

| Attribut | Type | NULL | Default | Contrainte | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `ip_address` | VARCHAR(45) | NO | — | UNIQUE | IP source, une ligne par IP, upsertee ; 45 caracteres contiennent un litteral IPv6 complet |
| `failed_attempts` | SMALLINT UNSIGNED | NO | 0 | — | logins echoues consecutifs depuis cette IP dans la fenetre courante |
| `window_started_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | debut de la fenetre de comptage courante |
| `lockout_until` | DATETIME | YES | NULL | — | fin de la fenetre de backoff degressif ; NULL = non throttle |
| `last_attempt_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | timestamp de la derniere tentative echouee |

**Pas de FK** : une IP n'est pas une entite modelisee. Les lignes sont appended/upsertees par IP ; la fenetre se reinitialise
a son expiration. Un cron quotidien purge les lignes sans lockout actif dont le `last_attempt_at` est plus ancien
que 24h.

---

### 3.22 `pin_throttle`

Throttle du PIN d'action sensible (RG-T22), complement de RG-T13. Une ligne par utilisateur AGISSANT
(l'identite de session qui soumet email+PIN), STRICTEMENT SEPAREE des compteurs de connexion
(`user.failed_login_attempts` / `login_throttle`) : un echec de PIN n'incremente aucun compteur de login.
Ajout security-by-design (voir note 13).

| Attribut | Type | NULL | Default | Contrainte | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `actor_user_id` | INT UNSIGNED | NO | — | UNIQUE, FK -> `user(id)` ON DELETE CASCADE | l'utilisateur agissant (session), une ligne par acteur, upsertee |
| `failed_attempts` | SMALLINT UNSIGNED | NO | 0 | — | echecs de PIN consecutifs de cet acteur dans la fenetre courante |
| `window_started_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | debut de la fenetre de comptage courante |
| `lockout_until` | DATETIME | YES | NULL | — | fin de la fenetre de backoff degressif ; NULL = non throttle |
| `last_attempt_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | timestamp de la derniere tentative echouee |

**FK ON DELETE CASCADE** (contrairement a `login_throttle`) : la cle est un utilisateur back-office
authentifie, donc supprimer/anonymiser le compte purge proprement sa ligne de throttle. Memes bornes de
backoff que RG-8 mais PROPRES au PIN (PIN_THROTTLE_*, plus permissives). Meme purge cron quotidienne que
`login_throttle` (lignes sans lockout actif > 24h).

---

## 4. Notes de modelisation

### Note 1 — Pourquoi `INT UNSIGNED` en centimes pour les prix

Stocker un prix en `FLOAT` ou `DECIMAL(10,2)` est techniquement valide mais introduit deux risques :

1. **Arrondi FLOAT** : `0.1 + 0.2 = 0.30000000000000004` en virgule flottante IEEE 754.
   Sommer 100 lignes de commande peut produire des ecarts au niveau du centime vs la realite metier.
2. **Conversion FLOAT-vers-chaine** : differentes versions de driver PHP/MariaDB peuvent serialiser les floats
   avec une precision variable.

Stocker en `INT UNSIGNED` (centimes : 880 pour 8,80 EUR) elimine ces risques. La conversion en EUR
pour l'affichage se fait en PHP a la sortie : `number_format($cents / 100, 2)`.

Reference : David Goldberg, *What Every Computer Scientist Should Know About Floating-Point
Arithmetic*, ACM Computing Surveys, 1991.

### Note 2 — Pourquoi `ENUM` plutot qu'une table de reference

Les ENUMs (`service_mode`, `status`, `item_type`, `action`, `slot_type`) auraient pu etre des tables de
reference. Choix retenu : ENUM.

Avantages dans ce contexte :
- Les valeurs sont stables et limitees (3-7 valeurs max), peu susceptibles d'evoluer frequemment.
- Contrainte au niveau SGBD au lieu d'une FK a l'execution ; requetes plus simples.
- Directement lisible en SQL : `WHERE status = 'paid'`.

Cout d'un changement futur : `ALTER TABLE ... MODIFY COLUMN ... ENUM(...)` pour ajouter une valeur.
Acceptable etant donne que les changements sont attendus comme rares.

Si ces ENUMs requierent plus tard des libelles ou descriptions multilingues, ils seront migres vers des
tables de reference. Hors perimetre pour cette iteration.

### Note 3 — Pourquoi `customer_order` et non `order`

`ORDER` est un mot reserve SQL (utilise dans `ORDER BY`). Trois approches existent :
- Quoter le nom partout : `` `order` `` — requiert un quoting dans chaque instruction SQL,
  source d'erreurs et non portable entre dialectes SGBD.
- Utiliser un alias au niveau ORM : possible mais ajoute une couche de mapping.
- Renommer : `customer_order` (choisi) — sans ambiguite, auto-documente, sans quoting requis.

Alternative consideree et rejetee : `purchase` (moins specifique au domaine),
`transaction` (egalement reserve ou ambigu). `customer_order` correspond au langage du domaine
et evite tous les conflits.

`order_item` est conserve comme nom de table de ligne : `item` n'est pas reserve, et le
prefixe `order_` rend claire la relation parent.

### Note 4 — Prefixe de numero de commande par canal (existant : prefixe + id)

Format reel (decision utilisateur) : prefixe canal + id de la commande, soit `K<id>` / `C<id>` /
`D<id>` (kiosk / counter / drive). Implemente dans `src/app/Order/OrderRepository.php` : la commande
est inseree avec un `order_number` provisoire vide, puis l'`order_number` est ecrit en `prefix .
LAST_INSERT_ID()` (ex. `K42`, `C7`, `D13`).

Rationale : le prefixe encode le canal, utile pour une identification visuelle rapide par le personnel
cuisine et comptoir sans interroger la colonne `source`. Le suffixe est l'id sequentiel auto-incremente :
pas de compteur quotidien a tenir ni de `service_day` a gerer cote numerotation (rasoir d'Ockham,
mantra #37).

Ecart assume avec la cible v0.x initiale `K-AAAA-MM-JJ-NNN` (compteur journalier par canal) :
cette derniere n'a pas ete retenue a l'implementation, jugee plus lourde sans valeur metier
proportionnelle pour le volume attendu. La forme acte ici est celle qui tourne.

Compromis connu : un numero `prefixe + id` est sequentiel, donc devinable (un client peut incrementer
l'id). Couple a l'endpoint de paiement anonyme cote borne (lecture/encaissement par `order_number`
sans authentification), c'est une surface a surveiller. Piste d'amelioration : numero non sequentiel
(ex. suffixe aleatoire court) si le suivi anonyme par numero devait s'ouvrir davantage.

Alternative rejetee : prefixe neutre `W` pour tous les canaux (plus simple, mais perd la lisibilite
du canal pour le personnel).

### Note 5 — `source` vs `service_mode` (canal vs mode de consommation)

Deux dimensions distinctes, gardees separees :

| | `source` | `service_mode` |
|---|---|---|
| Nature | canal de saisie (qui a saisi la commande) | mode de consommation (ou le client mange) |
| Valeurs | kiosk, counter, drive | dine_in, takeaway, drive |
| Sert a | authentification, analytics, filtrage de permission | KPI, capacite (aucun role fiscal) |

Les deux dimensions sont independantes pour `kiosk` et `counter` (un client borne peut choisir
`dine_in` ou `takeaway`). `drive` est le seul cas ou les deux dimensions s'alignent :
`source=drive` implique `service_mode=drive`. Cette contrainte croisee est verifiee au niveau applicatif.

### Note 6 — Machine a 4 etats reduite

v0.1 avait 6 etats (`pending_payment`, `paid`, `preparing`, `ready`, `delivered`, `cancelled`).
v0.2 reduit a 4 etats : `pending_payment -> paid -> delivered` (+ `cancelled`).

Rationale (Decision 4 de `revue-alignement-p1.md` §7) : dans un contexte fast-food, l'affichage
cuisine (KDS) est un systeme visuel — le personnel voit le ticket et agit. `preparing` et `ready` etaient
des etats intermediaires qui ajoutaient de la complexite sans valeur metier proportionnelle. L'unique
action cuisine est `deliver` (le personnel counter/drive remet la commande), fusionnant
`preparing + ready + delivered` en un seul geste. Le KPI est le temps total : `delivered_at - paid_at`
(SLA ~10 min). Le codage couleur du KDS est calcule depuis `now - paid_at`, sans etat stocke supplementaire.

**Etats et timestamps retires** : `preparing_at`, `ready_at` ne sont pas stockes.

### Note 7 — Cascade de format Normal / Maxi

Le format Maxi agrandit uniquement l'accompagnement et la boisson. Le burger est inchange et la portion
de sauce est inchangee (un pot de sauce est identique dans les deux formats). Ce perimetre est explicite afin que le
modele de stock reste fidele.

**Cote prix** — non modelise au niveau du prix de composant individuel :
- `menu` porte deux prix : `price_normal_cents` et `price_maxi_cents`.
- `order_item.format` enregistre le format choisi par le client (`normal` ou `maxi`).
- `order_item.unit_price_cents_snapshot` capture le prix reellement paye (Normal ou Maxi).
- Aucun prix individuel par composant de slot n'est stocke ; le differentiel de prix est un attribut
  au niveau menu, coherent avec la maniere dont les menus fast-food tendent a etre tarifes en pratique.

**Cote stock** — modelise via un multiplicateur de format sur la recette :
- `product_ingredient` porte `quantity_normal` et `quantity_maxi`.
- A la transition `paid`, le decrement utilise `quantity_maxi` quand `order_item.format='maxi'`,
  sinon `quantity_normal`.
- Pour les ingredients burger et sauce, `quantity_maxi = quantity_normal` (invariants au format).
- Pour les ingredients accompagnement et boisson, `quantity_maxi > quantity_normal` (le Maxi consomme plus).
- Le format se propage de la ligne de menu (`order_item.format`) a ses selections de slot ; une
  ligne de produit autonome est par defaut a `normal`.
- Un seul produit par choix (ex. un produit `Fries`), pas de produits medium/large separes.

Calibration : le supplement Maxi est dans la fourchette ~1,50 EUR pour ce modele (derive de
donnees internes ; recoupe avec l'ordre de grandeur du marche pour plausibilite. Wakdo est un pastiche
fictif, donc les prix exacts ne sont pas copies d'une chaine reelle).

Calibration : le supplement Maxi est dans la fourchette ~1,50 EUR pour ce modele (derive de
donnees internes ; recoupe avec l'ordre de grandeur du marche pour plausibilite. Wakdo est un pastiche
fictif, donc les prix exacts ne sont pas copies d'une chaine reelle).

### Note 8 — Stockage des images : chemin en VARCHAR vs BLOB en BDD

Les colonnes `image_path` (`category`, `product`, `menu`) stockent un **chemin relatif** depuis la
racine publique (ex. `/uploads/products/classic-burger.jpg`), pas un chemin serveur absolu.
PHP resout via un prefixe depuis `.env` (`UPLOAD_DIR=public/uploads`).

Le stockage BLOB a ete considere et rejete :

| Critere | `image_path` VARCHAR (choisi) | BLOB en BDD |
|---|---|---|
| Performance borne | Apache sert les fichiers en ms (cache OS) | PHP lit la BDD + streame, latence multipliee |
| Cache HTTP | ETag, Last-Modified, cache navigateur, CDN natifs | doit etre reimplemente en PHP |
| Taille de backup BDD | Megaoctets (chemins seulement) | Gigaoctets (66 produits x ~200 Ko + variantes responsive) |
| Pipeline d'images | `convert`, `webp`, optimisation = outils standard du systeme de fichiers | doit etre reinvente en PHP |

Sources : OWASP File Upload Cheat Sheet ; MariaDB Knowledge Base — LONGBLOB performance ;
documentation Apache HTTP Server — serving static content.

### Note 9 — Regle de TVA dans le fast-food francais (fact-checked)

```
FACT-CHECK
Claim audited : "TVA 10% sur place / 5,5% a emporter" (dictionary v0.1 note 9)
Domain         : compliance (fiscal)
Verdict        : CLAIM INEXACT — superseded
Source         : BOFiP BOI-ANNX-000495 + BOI-TVA-LIQ-30-10-10 (official doctrine impots.gouv.fr)
Actual rule    : 10% for immediate consumption (dine-in OR hot takeaway);
                 5.5% for products in resealable containers (bottle, can) / deferred consumption
Confidence     : 95% (L1, official text)
```

**Consequence sur le modele** : le taux de TVA est un attribut du `product` (`vat_rate` en pour-mille :
100 = 10%, 55 = 5,5%), pas de la commande ni du mode de service. Defaut : 100 (10%).
Le taux de 5,5% s'applique aux produits en contenants refermables (eau en bouteille, bouteilles de jus).
La TVA est calculee ligne par ligne ; le taux est snapshote sur `order_item.vat_rate_snapshot`
au moment de la transaction pour preserver l'integrite historique si la legislation change.

`service_mode` est conserve sur `customer_order` pour les stats et le KPI uniquement (planification de capacite,
repartition du chiffre d'affaires par mode). Il n'a aucun role de calcul fiscal.

### Note 10 — Configurateur d'ingredients et rattachement du modificateur

`order_item_modifier` se rattache a une ligne `order_item` via `order_item_id`, que
la ligne soit un produit autonome ou un menu.

Pour un **produit autonome** (`item_type='product'`) : `order_item_id` identifie directement
le produit modifie.

Pour un **menu** (`item_type='menu'`) : le produit modifiable est le burger fixe, identifie
via `order_item.menu_id -> menu.burger_product_id`. L'affichage cuisine resout :
`modifier.order_item_id -> order_item -> menu -> menu.burger_product_id -> product.name`.
Aucune colonne FK supplementaire n'est necessaire sur `order_item_modifier`. Cela garde la table modificateur
simple et evite une colonne nullable `target_product_id` qui ne serait peuplee que pour les
lignes de menu.

Contrainte appliquee au niveau applicatif : les lignes `order_item_modifier` pour une ligne de menu referencent
uniquement des ingredients appartenant a `menu.burger_product_id` via `product_ingredient`.

### Note 11 — Eligibilite `menu_slot` : filtre par categorie vs liste de produits explicite

Deux options ont ete considerees :
- **Filtre par categorie** : `menu_slot.category_id` pointe vers une categorie ; tous les produits de cette
  categorie sont eligibles. Simple, mais une categorie peut contenir des produits non proposes dans ce slot
  (ex. une boisson premium ajoutee a la categorie "drinks" ne devrait pas apparaitre automatiquement dans
  tous les slots de menu).
- **Liste de produits explicite** `menu_slot_option(menu_slot_id, product_id)` (choisie) : chaque
  produit eligible est liste explicitement par slot. Plus verbeux au moment du seed mais precis —
  pas d'eligibilite accidentelle quand le catalogue grandit. Permet des overrides de tarification par slot
  a l'avenir sans changement structurel.

La liste explicite ajoute une entite (`menu_slot_option`, entite 3.5) mais elimine une classe
de bugs de justesse. Coherent avec l'ambition prod-like de ce modele.

### Note 12 — `commande_event` retire

v0.1 portait une table d'audit append-only `commande_event` (pattern event sourcing).
Retiree en v0.2 (Decision 1, `revue-alignement-p1.md` §7).

Rationale : dans un contexte restaurant, le compte back-office est partage par poste de travail, non
individuel. L'attribution par personne d'une transition d'etat n'a aucune valeur metier. Le besoin reel
(durees de phase, stats par heure de la journee) est couvert par les timestamps de phase sur `customer_order`
(`paid_at`, `delivered_at`, `cancelled_at`) sans la complexite d'un event store.

La machine a 4 etats combinee a 3 timestamps de phase fournit toutes les donnees KPI necessaires :
- Temps de remise : `delivered_at - paid_at`
- Taux et timing d'annulation : `cancelled_at - created_at`
- Volume par heure : calcul `HOUR(created_at)` / `service_day`

Pour l'audit de stock, `stock_movement` (entite 3.19) fournit la trace d'audit append-only
la ou elle est genuinement necessaire (reconciliation d'inventaire).

### Note 13 — Ajouts de donnees security-by-design (2026-06-11)

Ces ajouts etendent le modele prod-like avec une couche security-by-design. Ils ne
remplacent aucune decision v0.2 ; ils ajoutent imputabilite, cycle de vie d'auth et resistance a l'abus.

**Imputabilite — compte partage hybride + PIN.** Les sessions back-office restent partagees par
poste de travail pour le flux de routine (un terminal fast-food est partage, les `equipiers` tournent). Un
PIN par membre du personnel (`user.pin_hash`, argon2id) autorise un ensemble defini d'**actions sensibles**
(editions prix/menu 8.2/8.3/8.6, annulation de commande 7.1, correction d'inventaire 9.2, gestion
des utilisateurs 10.1-10.3, RBAC 10.4). Ces actions ecrivent le `user_id` agissant dans `audit_log`
(3.20). Cela resout la justification circulaire qui avait retire `commande_event` en v0.1
(les events etaient juges inutiles parce que les comptes etaient partages) : l'imputabilite est enregistree
la ou elle importe, a friction quasi nulle pour les 95% de routine. `customer_order.acting_user_id`
capture le personnel pour les commandes counter/drive prises sous PIN ; les commandes borne restent anonymes.

**Cycle de vie d'auth.** `password_reset_token_hash` + `password_reset_expires_at` permettent un parcours
de reset (le token est stocke hashe, le token brut est envoye par e-mail une seule fois). La resistance au brute-force utilise
un throttling degressif plutot qu'un verrouillage dur indefini : `failed_login_attempts` +
`lockout_until` implementent un backoff degressif par (compte + IP source), de sorte qu'une serie
de fautes de frappe ne verrouille pas toute une cuisine en plein service (15 h continues). Les logins echoues sont
ecrits dans `audit_log`.

**Anonymisation RGPD vs retention d'audit.** Les PII de `user` (`email`, `first_name`, `last_name`)
sont soumises au droit a l'effacement (Cr 3.d). L'effacement **anonymise** plutot qu'il ne supprime durement :
la ligne est conservee, `email` devient un placeholder unique non identifiant (`anon-<id>@wakdo.invalid`,
domaine reserve RFC 2606), les noms sont effaces, `password_hash`/`pin_hash` sont invalides, et
`anonymized_at` est renseigne. `audit_log` conserve sa propre fenetre de retention (~12 mois,
interet legitime / tracabilite fiscale) et continue de pointer vers le principal anonymise, de sorte que
effacement et imputabilite coexistent sans casser l'integrite referentielle.

**Resistance a l'abus sur la borne anonyme.** `customer_order.idempotency_key` (UUID client,
UNIQUE) deduplique un `POST /api/orders` reessaye de sorte qu'un retry reseau ne cree pas de
commande payee dupliquee. Le stock est decremente avec une seule instruction atomique
(`UPDATE ingredient SET stock_quantity = stock_quantity - :units WHERE id = :id`) : aucune operation
ne depend d'une lecture de stock, donc la ligne s'auto-verrouille pour la duree de l'ecriture — pas de lost update et
pas de souci d'ordre des deadlocks. Cela remplace l'approche pessimiste anterieure `SELECT ... FOR UPDATE`
(regle de la couche traitement, voir `mlt.md`) ; elle n'ajoute aucune colonne ici.

**Modele de stock en pourcentage + disponibilite calculee.** `ingredient` porte `stock_capacity` (la
reference 100%), `low_stock_pct` (bande d’alerte) et `critical_stock_pct` (seuil de rupture
automatique) — voir 3.6. `stock_quantity` est signe et peut devenir negatif (ampleur de survente remontee aux
managers) ; le systeme ne bloque pas une commande sur le stock. La commandabilite effective du produit est
calculee (regle RG-T21 dans `mlt.md`) : `product.is_available = 1` ET chaque ingredient non retirable
(`is_removable=0`) de son `product_ingredient` a
`stock_quantity > stock_capacity * critical_stock_pct/100`. A la bande critique, un produit
passe automatiquement en rupture sans ecriture ni cascade ; un retrait manuel (`product.is_available=0`) est
une surcharge forte ; un reapprovisionnement au-dessus de la bande critique rend le produit a nouveau commandable de lui-meme.

**Throttle anti-brute-force par IP.** `login_throttle` (3.21) suit `failed_attempts` et
`lockout_until` par IP source (une ligne upsertee par IP), completant le compteur par compte
sur `user`. Cela ajoute une seconde dimension de throttling, de sorte qu'une seule IP martelant de nombreux comptes soit
ralentie independamment du compteur de n'importe quel compte. Un cron quotidien purge les lignes inactives et non verrouillees.

**Throttle du PIN d'action sensible (par acteur).** `pin_throttle` (3.22) suit `failed_attempts` et
`lockout_until` par utilisateur AGISSANT (l'identite de session qui valide une action sensible),
dans une table separee des compteurs de connexion. La dimension est l'acteur (et non l'email cible,
contournable par rotation, ni l'IP, qui penaliserait tous les equipiers d'un poste partage) ; le verrou
est un backoff degressif aux bornes propres (PIN_THROTTLE_*). Meme purge cron que `login_throttle`. RG-T22.

References : `docs/notes/revue-alignement-p1.md` §7 (decisions D), carte d'impact security-by-design
(2026-06-11). Modele de menace et matrice de classification des donnees : `PROJECT_CONTEXT.md` §19 (a venir).

### Note 14 — Colonnes additives post-v0.3 (migrations 0003 / 0005 / 0006 / 0007)

Ces colonnes etendent le schema apres v0.3 par des migrations purement additives (ajout de colonnes
nullables et de FK auto-referentes ; aucune donnee existante a retro-remplir, aucune table nouvelle).
Le runner applique les `*.sql` dans l'ordre lexicographique via `schema_migrations`. Elles sont alignees
ici sur le schema reellement deploye.

**Migration 0003 — `customer_order.service_tag` VARCHAR(20) NULL (AFTER service_mode).** Numero de
chevalet pour le service en salle (mode `dine_in`), saisi a la borne ; NULL pour `takeaway` / `drive`.
Permet d'apporter la commande a la bonne table (B4). Entite 3.10.

**Migration 0005 — enrichissement nutritionnel de `ingredient` (AFTER pack_label).**
`energy_kcal_100g` SMALLINT UNSIGNED NULL, `nutrition_source` VARCHAR(120) NULL,
`nutrition_fetched_at` DATETIME NULL. Donnees importees depuis l'API externe OpenFoodFacts (Cr 3.a.3 :
exploitation d'informations externes dans le modele de donnees). Opt-in et egress maitrise : aucun appel
automatique au runtime borne ; la passerelle (`App\Catalogue\OpenFoodFactsGateway`) est invoquee seulement
par `IngredientController::enrich` (action explicite manager/admin). Toutes nullables : un ingredient non
enrichi reste valide. Entite 3.6.

**Migration 0006 — `product.maxi_variant_product_id` INT UNSIGNED NULL, FK -> `product(id)` ON DELETE
SET NULL (AFTER price_cents).** Auto-reference : variante servie quand un menu est commande au format
Maxi (ex. Moyenne Frite -> Grande Frite), substituee cote serveur dans `OrderRepository::resolveSelections`
sans choix supplementaire. Approche data-driven (la regle vit dans la donnee, pas dans le code), et le
decrement de stock frappe alors le bon produit. SET NULL plutot que RESTRICT : si la variante Grande est
supprimee du catalogue, le produit de base reste vendable et perd seulement sa substitution Maxi
(degradation gracieuse) ; la reference est un confort metier, pas une integrite forte de commande (les
commandes figent deja leurs snapshots). Entite 3.2.

**Migration 0007 — variante de TAILLE de `product` (AFTER price_cents).** `size_cl` SMALLINT UNSIGNED
NULL, `base_product_id` INT UNSIGNED NULL avec FK -> `product(id)` ON DELETE CASCADE. La dimension taille
des boissons fontaine (la maquette borne propose 30 / 50 cl) est modelisee en lignes produit distinctes
(meme approche que Moyenne/Grande Frite) : le domaine commande facture deja par `product_id`, le flux reste
inchange, la borne resout la taille choisie en `product_id`. `base_product_id` NULL = produit de base ou
autonome (visible dans la grille catalogue) ; NON NULL = variante de taille (masquee de la grille, atteinte
via le picker). CASCADE plutot que SET NULL (a la difference de 0006) : une variante de taille n'a aucun
sens sans sa base (une "Coca Cola 50 cl" orpheline n'est pas commercialisable), donc supprimer la base
emporte ses variantes de taille. Les deux groupings coexistent sur une boisson sans se confondre :
`base_product_id` pilote la selection de taille a la carte (picker 30/50 cl) ; `maxi_variant_product_id`
(0006) pilote la substitution Maxi en menu. Entite 3.2.

References : `db/migrations/0003_order_service_tag.sql`, `0005_ingredient_nutrition.sql`,
`0006_product_maxi_variant.sql`, `0007_product_size_variant.sql`.

---

## 5. Synthese du decompte des entites

| # | Entite | Type | Remplace / nouveau |
|---|---|---|---|
| 1 | `category` | business | v0.1 `categorie` (renommee + traduite) |
| 2 | `product` | business | v0.1 `produit` (+ `vat_rate`) |
| 3 | `menu` | business | v0.1 `menu` (+ FK burger, 2 prix) |
| 4 | `menu_slot` | business | nouveau — remplace la composition fixe `menu_produit` |
| 5 | `menu_slot_option` | join | nouveau — liste d'eligibilite par slot |
| 6 | `ingredient` | business | nouveau — configurateur d'ingredients + stock |
| 7 | `product_ingredient` | join | nouveau — recette + metadonnees de personnalisation |
| 8 | `allergen` | reference | nouveau — INCO 1169/2011 |
| 9 | `ingredient_allergen` | join | nouveau — mappe les allergenes aux ingredients |
| 10 | `customer_order` | business | v0.1 `commande` (renommee, machine a 4 etats, timestamps de phase) |
| 11 | `order_item` | business | v0.1 `ligne_commande` (+ format, vat_rate_snapshot) |
| 12 | `order_item_selection` | business | nouveau — choix de slot de menu du client |
| 13 | `order_item_modifier` | business | nouveau — modifications au niveau ingredient |
| 14 | `user` | business | v0.1 `user` (noms de champs traduits) |
| 15 | `role` | business | v0.1 `role` (+ default_route, order_source) |
| 16 | `role_visible_source` | join | nouveau — filtre de tableau de bord par role |
| 17 | `permission` | reference | v0.1 `permission` (traduite, catalogue gele) |
| 18 | `role_permission` | join | v0.1 `role_permission` (inchangee) |
| 19 | `stock_movement` | audit | nouveau — journal d'audit de stock append-only |
| 20 | `audit_log` | audit | nouveau (security-by-design) — journal append-only d'actions sensibles |
| 21 | `login_throttle` | security | nouveau (security-by-design) - throttle anti-brute-force par IP |
| 22 | `pin_throttle` | security | nouveau (security-by-design) - throttle du PIN d'action sensible par acteur (RG-T22) |

**Retire de v0.1** : `commande_event` (remplace par les timestamps de phase sur `customer_order`),
`menu_produit` (remplace par le modele `menu_slot` + `menu_slot_option`).

**Total : 22 entites** (19 prod-like v0.2 + `audit_log`, `login_throttle` et `pin_throttle`
de la couche security-by-design).

Le security-by-design ajoute aussi des colonnes (au-dela des deux nouvelles entites) : cycle de vie d'auth de `user` +
`pin_hash` + `anonymized_at` (3.14), `customer_order.acting_user_id` + `idempotency_key` (3.10),
et le modele de stock en pourcentage sur `ingredient` (3.6) — `stock_capacity`, `critical_stock_pct`,
plus le renommage de `low_stock_threshold` en `low_stock_pct`. `login_throttle` (3.21) est la 21e
entite et `pin_throttle` (3.22) la 22e. Voir note 13.

---

*Pour le diagramme ER et les justifications de cardinalite, voir [`mcd.md`](mcd.md) — le diagramme est
la source de verite unique pour la representation graphique.*
