# Dictionnaire de donnees - Wakdo

**Phase Merise** : P1 - Conception, etape 1 (data dictionary first, mantra #33)
**Statut** : v0.1 (squelette MCD a venir, mantra "Incremental Design")
**Date** : 2026-04-30
**Branche** : `feat/p1-stubs-and-dictionary`

---

## 1. Objet du document

Ce dictionnaire liste **toutes les entites de donnees** identifiees pour Wakdo, avec
leurs attributs, types, contraintes et sources. Il sert de base au MCD (entites + relations),
puis au MLD (passage relationnel), puis au DDL (SQL CREATE TABLE).

**Methodologie** : derivation bottom-up depuis les sources disponibles :
- **Source ecole** : `docs/merise/_sources/categories.json` + `produits.json` (66 produits, 9 categories)
- **Brief metier** : `docs/PROJECT_CONTEXT.md` (composition de menu, parcours commande, RBAC,
  modes de consommation)
- **Maquette** : `docs/design/maquette-borne.pdf` (UX kiosk, ecrans visibles)

Tout ecart entre la source ecole et le modele final est documente dans la section "Notes
de modelisation" en bas de ce document.

---

## 2. Conventions generales

### Naming

- **Tables** : `snake_case` au singulier (ex : `categorie`, `produit`, `menu_produit`).
  Le singulier reflete la perspective "1 ligne = 1 instance de l'entite" (convention courante
  dans les ecoles francaises de gestion). Le code applicatif (PHP, JS) utilisera ces noms
  tels quels.
- **Colonnes** : `snake_case`. Suffixes typiques : `_id` (FK), `_at` (timestamp), `_cents`
  (montant monetaire en centimes), `_path` (chemin de fichier), `_taux` (pourcentage ou
  fraction).
- **Cles primaires** : colonne `id` (INT UNSIGNED AUTO_INCREMENT). Pas de cle composite en
  PK, sauf sur les tables de jointure pure.
- **Cles etrangeres** : `<table_referencee>_id` (ex : `categorie_id` dans `produit`).

### Types par defaut

| Categorie | Type MariaDB | Justification |
|---|---|---|
| Identifiants | `INT UNSIGNED AUTO_INCREMENT` | 4 milliards d'ids = largement suffisant pour ce projet |
| Libelles courts | `VARCHAR(120)` | Couvre la plupart des noms produits (ex : `"Signature Beef BBQ Burger (2 viandes)"` = 41 chars) |
| Descriptions | `TEXT` | Longueur variable, pas de limite stricte |
| Montants monetaires | `INT UNSIGNED` (centimes) | Evite les bugs d'arrondi des FLOAT (cf. note 1 en bas) |
| Booleens | `TINYINT(1)` | Convention MariaDB pour `BOOLEAN` (alias) |
| Timestamps | `DATETIME` | Lisible humainement, gere les timezones via app |
| Enumerations | `ENUM('a','b','c')` | Contrainte SGBD, lisible (cf. note 2) |
| Chemins de fichiers | `VARCHAR(255)` | Limite POSIX courante pour un chemin simple |

### Charset et collation

- **Charset** : `utf8mb4` (RFC 3629 - UTF-8 reel sur 4 octets, supporte les emoji et caracteres
  asiatiques). MariaDB gere `utf8mb4` en natif.
- **Collation** : `utf8mb4_unicode_ci` (insensible a la casse, comparaison conforme Unicode).

### Champs d'audit (presents sur toutes les tables metier sauf jointures pures)

| Colonne | Type | Defaut | Role |
|---|---|---|---|
| `created_at` | `DATETIME` | `CURRENT_TIMESTAMP` | Date de creation, non modifiee par la suite (ecriture unique a l'insertion) |
| `updated_at` | `DATETIME` | `CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | Date de derniere modification, mise a jour automatique |

### Soft delete

Pas de soft delete generalise pour MVP. Les entites qui peuvent etre desactivees temporairement
ont une colonne `est_actif` ou `est_disponible` (boolean). La suppression dure (`DELETE`)
reste possible mais reservee a des operations admin avec sauvegarde prealable.

---

## 3. Entites

### 3.1 `categorie`

Regroupement metier des produits et menus pour l'affichage sur la borne.

| Attribut | Type | NULL | Defaut | Contrainte | Source ecole | Notes |
|---|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | `id` (1-9) | identique source |
| `libelle` | VARCHAR(60) | NO | - | UNIQUE | `title` | renomme depuis `title` (semantique francaise) |
| `slug` | VARCHAR(60) | NO | - | UNIQUE | derive de `title` (kebab-case lowercase) | utile pour URL `/api/categories/burgers` |
| `image_path` | VARCHAR(255) | YES | NULL | - | `image` | normalisation post-import (kebab-case lowercase) |
| `ordre` | SMALLINT UNSIGNED | NO | 0 | - | (enrichi) | ordre d'affichage sur la borne, ajustable depuis admin |
| `est_actif` | TINYINT(1) | NO | 1 | - | (enrichi) | permet de desactiver une categorie sans la supprimer |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | - | - | audit |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | - | - | audit |

**Exemples** : `menus`, `boissons`, `burgers`, `frites`, `encas`, `wraps`, `salades`,
`desserts`, `sauces`. Volume : 9 lignes a l'init (seed depuis `categories.json`).

---

### 3.2 `produit`

Article unitaire vendable a la carte ou comme composant d'un menu.

| Attribut | Type | NULL | Defaut | Contrainte | Source ecole | Notes |
|---|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | `id` (14-66 selon categorie) | identique source |
| `categorie_id` | INT UNSIGNED | NO | - | FK -> `categorie(id)`, ON DELETE RESTRICT | (enrichi : derive de la cle d'objet du JSON) | source absente, deduit de la position dans `produits.json` |
| `libelle` | VARCHAR(120) | NO | - | INDEX | `nom` | renomme depuis `nom` (coherence francaise) |
| `description` | TEXT | YES | NULL | - | (enrichi) | absente de la source ecole, alimente plus tard via admin |
| `prix_ttc_cents` | INT UNSIGNED | NO | - | CHECK > 0 | `prix` (FLOAT) | conversion FLOAT -> INT centimes au seed (cf. note 1) |
| `image_path` | VARCHAR(255) | YES | NULL | - | `image` | normalisation post-import |
| `est_disponible` | TINYINT(1) | NO | 1 | - | (enrichi) | rupture manuelle depuis admin (= booleen, pas de gestion stock numerique en MVP) |
| `ordre` | SMALLINT UNSIGNED | NO | 0 | - | (enrichi) | ordre dans la categorie |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | - | - | audit |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | - | - | audit |

**Volume** : 53 lignes a l'init (66 lignes dans `produits.json` moins les 13 menus qui vont dans `menu`). Cf. note 3 pour la separation produit/menu.

---

### 3.3 `menu`

Combo prix fixe = burger + accompagnement + boisson + sauce (composition modelisee dans
`menu_produit`).

| Attribut | Type | NULL | Defaut | Contrainte | Source ecole | Notes |
|---|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | `id` (1-13 dans categorie `menus`) | |
| `categorie_id` | INT UNSIGNED | NO | - | FK -> `categorie(id)`, ON DELETE RESTRICT | implicite (categorie `menus`) | |
| `libelle` | VARCHAR(120) | NO | - | INDEX | `nom` | ex : "Menu Le 280", "Menu Big Mac" |
| `description` | TEXT | YES | NULL | - | (enrichi) | |
| `prix_ttc_cents` | INT UNSIGNED | NO | - | CHECK > 0 | `prix` | |
| `image_path` | VARCHAR(255) | YES | NULL | - | `image` | reutilise typiquement l'image du burger dominant |
| `est_disponible` | TINYINT(1) | NO | 1 | - | (enrichi) | |
| `ordre` | SMALLINT UNSIGNED | NO | 0 | - | (enrichi) | |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | - | - | audit |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | - | - | audit |

**Volume** : 13 lignes a l'init.

---

### 3.4 `menu_produit` (jointure)

Composition d'un menu : pour chaque menu, la liste des produits avec leur role.

| Attribut | Type | NULL | Defaut | Contrainte | Notes |
|---|---|---|---|---|---|
| `menu_id` | INT UNSIGNED | NO | - | FK -> `menu(id)`, ON DELETE CASCADE | |
| `produit_id` | INT UNSIGNED | NO | - | FK -> `produit(id)`, ON DELETE RESTRICT | RESTRICT pour eviter qu'un produit retire ne casse silencieusement les menus existants |
| `role` | ENUM('burger','accompagnement','boisson','sauce','dessert') | NO | - | - | role metier du produit dans le menu |
| `position` | SMALLINT UNSIGNED | NO | 0 | - | ordre d'affichage dans le menu (ex : burger en 1, frites en 2, etc.) |

**Cle primaire** : composite `(menu_id, produit_id)`.

**Volume estime** : 13 menus x 3-4 produits chacun = 40-50 lignes a l'init.

**Decision YAGNI** : pas de colonne `quantite` (cf. discussion Session 5). Si un menu duo
arrivait, il serait modelise comme un nouveau menu distinct, ou la colonne serait ajoutee
via `ALTER TABLE` avec backfill.

---

### 3.5 `commande`

Transaction client : 1 commande = 1 panier valide a un instant donne.

| Attribut | Type | NULL | Defaut | Contrainte | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `numero` | VARCHAR(20) | NO | - | UNIQUE | format humain ex : `K-2026-04-30-001`, genere a la creation |
| `source` | ENUM('kiosk','comptoir','drive') | NO | - | INDEX | canal de saisie de la commande (cf. note 8) |
| `mode_consommation` | ENUM('sur_place','a_emporter','drive') | NO | - | - | mode de consommation fiscal et operationnel (impacte la TVA, cf. note 9) |
| `statut` | ENUM('pending_payment','paid','preparing','ready','delivered','cancelled') | NO | 'pending_payment' | INDEX | machine a etats (cf. MCT) |
| `total_ht_cents` | INT UNSIGNED | NO | - | CHECK >= 0 | snapshot calcule a la validation |
| `total_tva_cents` | INT UNSIGNED | NO | - | CHECK >= 0 | snapshot |
| `total_ttc_cents` | INT UNSIGNED | NO | - | CHECK > 0 | snapshot, doit valoir total_ht_cents + total_tva_cents (verification au MLT) |
| `tva_taux_pourmille` | SMALLINT UNSIGNED | NO | - | - | TVA en pour mille (ex : 100 pour 10%, 55 pour 5,5%). Stocke en INT pour eviter les arrondis FLOAT |
| `paye_a` | DATETIME | YES | NULL | - | timestamp du passage en `paid` (NULL avant) |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | INDEX | utilise pour les agregations stats live |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | - | audit |

**Volume estime** : ~100-300 commandes/jour en pic, sur 6 mois de demo = ~10k lignes max.

**TVA en restauration France** (cf. service-public.fr article F31407, 2024) :
- 10% sur la consommation immediate (sur place ou plats chauds a emporter)
- 5,5% sur les produits a emporter destines a la consommation differee

Le taux est snapshote au moment de la commande pour preserver l'integrite historique
si la legislation evolue.

---

### 3.6 `ligne_commande`

Detail d'une commande : produits unitaires OU menus, avec snapshot prix et libelle au moment
de la transaction.

| Attribut | Type | NULL | Defaut | Contrainte | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `commande_id` | INT UNSIGNED | NO | - | FK -> `commande(id)`, ON DELETE CASCADE | si la commande disparait, ses lignes aussi |
| `type_item` | ENUM('produit','menu') | NO | - | - | discriminateur |
| `produit_id` | INT UNSIGNED | YES | NULL | FK -> `produit(id)`, ON DELETE RESTRICT | non-null SI type_item = 'produit' |
| `menu_id` | INT UNSIGNED | YES | NULL | FK -> `menu(id)`, ON DELETE RESTRICT | non-null SI type_item = 'menu' |
| `libelle_snapshot` | VARCHAR(120) | NO | - | - | copie du libelle au moment de la commande (preserve si on renomme) |
| `prix_unitaire_ttc_cents_snapshot` | INT UNSIGNED | NO | - | CHECK > 0 | copie du prix au moment de la commande |
| `quantite` | SMALLINT UNSIGNED | NO | 1 | CHECK > 0 | si le client commande 3 cocas, 1 ligne avec `quantite=3` |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | - | - |

**Contrainte CHECK applicative ou triggers** :
`(type_item='produit' AND produit_id IS NOT NULL AND menu_id IS NULL) OR (type_item='menu' AND menu_id IS NOT NULL AND produit_id IS NULL)`. Cette contrainte est verifiable cote MariaDB
via CHECK (depuis 10.2) ou cote PHP au moment de l'insertion.

**Volume** : ~3-5 lignes par commande -> 30k-50k lignes sur 6 mois.

**Snapshots** : `libelle_snapshot` et `prix_unitaire_ttc_cents_snapshot` permettent de retrouver
la facturation exacte d'une commande historique meme si le produit a ete renomme/repricaye depuis.
Argumentaire jury : integrite des donnees comptables.

---

### 3.7 `commande_event`

Journal d'audit append-only : 1 ligne par changement d'etat d'une commande. Pattern
event sourcing simplifie (cf. note 10). Trace **qui** a fait **quoi**, **quand**, sur quelle
commande, avec quel contexte. Aucun update / delete autorise (immuable).

| Attribut | Type | NULL | Defaut | Contrainte | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `commande_id` | INT UNSIGNED | NO | - | FK -> `commande(id)`, ON DELETE CASCADE | si la commande disparait, son journal aussi |
| `event_type` | ENUM('CREATED','PAID','PREPARING_STARTED','READY','DELIVERED','CANCELLED') | NO | - | INDEX | type d'evenement, aligne sur la machine a etats |
| `from_statut` | ENUM('pending_payment','paid','preparing','ready','delivered','cancelled') | YES | NULL | - | statut avant transition (NULL pour CREATED) |
| `to_statut` | ENUM('pending_payment','paid','preparing','ready','delivered','cancelled') | NO | - | - | statut apres transition |
| `user_id` | INT UNSIGNED | YES | NULL | FK -> `user(id)`, ON DELETE SET NULL | NULL si auto-validation kiosk ou system event ; sinon = equipier qui a declenche |
| `payload` | JSON | YES | NULL | - | contexte additionnel : raison annulation, methode paiement, montant rembourse, etc. |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | INDEX | timestamp immuable de l'evenement |

**Cle primaire** : `id`.

**Index supplementaires** :
- `(commande_id, created_at)` pour requete "historique d'une commande"
- `(user_id, created_at)` pour requete "actions d'un equipier sur une periode"

**Volume** : ~5-8 events par commande (1 CREATED + 1 PAID + 1 PREPARING + 1 READY + 1 DELIVERED, plus eventuels CANCELLED). Sur 6 mois, ~50k-80k lignes.

**ON DELETE SET NULL sur `user_id`** : si un user est supprime (rare, cf. soft delete), les events restent (audit preserve) mais l'attribution est perdue. Le brief peut imposer `ON DELETE RESTRICT` si l'integrite de l'audit est critique.

---

### 3.8 `user`

Utilisateur du back-office (admin, manager, equipier) - **pas** les clients de la borne, qui
ne sont pas authentifies.

| Attribut | Type | NULL | Defaut | Contrainte | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `email` | VARCHAR(254) | NO | - | UNIQUE | longueur max RFC 5321 |
| `password_hash` | VARCHAR(255) | NO | - | - | hash argon2id (cf. `PASSWORD_ALGO` dans `.env`), longueur 96 chars typique mais marge 255 |
| `nom` | VARCHAR(60) | NO | - | - | |
| `prenom` | VARCHAR(60) | NO | - | - | |
| `role_id` | INT UNSIGNED | NO | - | FK -> `role(id)`, ON DELETE RESTRICT | un user ne peut pas exister sans role |
| `est_actif` | TINYINT(1) | NO | 1 | - | desactivation sans suppression |
| `last_login_at` | DATETIME | YES | NULL | - | utile pour audit et detection comptes dormants |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | - | - |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | - | - |

**Volume** : 5-20 lignes (equipe restaurant + 1-2 admins).

**Reference RFC 5321 sur la longueur email** : la limite locale-part = 64, domaine = 255,
total = 254 (incluant le `@`). VARCHAR(254) est la valeur conforme spec.

---

### 3.9 `role`

Roles utilisables dans le back-office (RBAC). Creables / modifiables / desactivables depuis
l'UI admin (les permissions sont statiques, declarees en migration).

| Attribut | Type | NULL | Defaut | Contrainte | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `code` | VARCHAR(40) | NO | - | UNIQUE | identifiant code (ex : `admin`, `manager`, `equipier`) |
| `libelle` | VARCHAR(80) | NO | - | - | nom affichable (ex : `Administrateur`) |
| `description` | TEXT | YES | NULL | - | |
| `est_actif` | TINYINT(1) | NO | 1 | - | desactivation sans suppression (preserve l'historique des users qui avaient ce role) |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | - | - |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | - | audit |

**Volume** : 3-5 lignes (admin, manager, equipier-comptoir, equipier-drive). Extensible
via UI admin sans deploiement.

---

### 3.10 `permission`

Permissions granulaires assignables aux roles (ex : `produit.create`, `commande.read`).

| Attribut | Type | NULL | Defaut | Contrainte | Notes |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | |
| `code` | VARCHAR(60) | NO | - | UNIQUE | format `<resource>.<action>` (ex : `produit.update`) |
| `libelle` | VARCHAR(120) | NO | - | - | nom affichable |
| `description` | TEXT | YES | NULL | - | |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | - | - |

**Volume** : ~20-40 lignes selon granularite (CRUD sur produit, menu, categorie, user, role,
commande, stats).

---

### 3.11 `role_permission` (jointure)

Mapping N-N entre roles et permissions.

| Attribut | Type | NULL | Defaut | Contrainte |
|---|---|---|---|---|
| `role_id` | INT UNSIGNED | NO | - | FK -> `role(id)`, ON DELETE CASCADE |
| `permission_id` | INT UNSIGNED | NO | - | FK -> `permission(id)`, ON DELETE CASCADE |

**Cle primaire** : composite `(role_id, permission_id)`.

**Volume** : ~50-100 lignes selon les attributions (admin couvre potentiellement toutes les
permissions, les autres roles un sous-ensemble).

---

## 4. Notes de modelisation

> Le diagramme entites-relations et les justifications de cardinalites sont documentes dans [`mcd.md`](mcd.md) (diagrammes drawio des 4 sous-domaines + recapitulatif global). Le dictionnaire ne dedouble pas cette vue pour eviter d'avoir deux sources de verite divergeantes.

### Note 1 - Pourquoi `INT UNSIGNED` en centimes pour les prix

Stocker un prix en `FLOAT` ou `DECIMAL(10,2)` est techniquement valide mais introduit deux
risques :

1. **Arrondi FLOAT** : `0.1 + 0.2 = 0.30000000000000004` en flottants IEEE 754. Sommer 100
   lignes de commande peut produire des ecarts de centimes vs la realite metier.
2. **Conversion FLOAT -> string** : differents drivers PHP/MariaDB peuvent serialiser les
   floats avec une precision variable.

Stocker en `INT UNSIGNED` (centimes : 880 pour 8,80 EUR) elimine ces risques. La conversion
en EUR pour l'affichage se fait cote PHP a la sortie : `number_format($cents / 100, 2)`.

Reference : David Goldberg, *What Every Computer Scientist Should Know About Floating-Point
Arithmetic*, ACM Computing Surveys, 1991. (Le sujet est devenu un classique de la litterature
informatique.)

### Note 2 - Pourquoi `ENUM` plutot que table de reference

Les ENUM (`mode_consommation`, `statut`, `role` dans `menu_produit`, `type_item`) auraient pu
etre des tables de reference (ex : `mode_consommation_referentiel`). Choix retenu : ENUM.

Avantages ENUM dans ce contexte :
- Valeurs stables et limitees (3-7 valeurs max), peu probables d'evoluer
- Contrainte SGBD au lieu de FK runtime, requetes plus simples
- Lisibilite directe en SQL : `WHERE mode_consommation = 'sur_place'`

Cout d'un changement futur : un `ALTER TABLE ... MODIFY COLUMN ... ENUM(...)` pour ajouter une
valeur. Acceptable car les changements sont attendus rarement.

Si plus tard ces ENUMs prennent des libelles ou descriptions multilingues, on les passera en
tables. Pas pour MVP.

### Note 3 - Pourquoi `produit` ET `menu` separes (pas une table unique avec STI)

Option consideree : Single Table Inheritance avec une colonne `type ENUM('produit','menu')`
sur une seule table. Cout : NULLs fantomes sur les colonnes specifiques (un produit n'a pas
de composition).

Option retenue : 2 tables separees (`produit`, `menu`). Avantages :
- Semantique claire (un menu n'est pas un "produit avec composition", c'est une autre nature)
- Contraintes specifiques possibles (ex : un menu doit avoir au moins 1 entree dans
  `menu_produit`, contrainte applicative)
- Pas de NULL sur les colonnes specifiques

Cout : la table `ligne_commande` doit gerer 2 FKs (produit_id OU menu_id) avec une regle
d'exclusivite. Acceptable et courant en e-commerce.

### Note 4 - Pas de gestion stock numerique

Choix MVP : un boolean `est_disponible` suffit. La rupture est geree manuellement par
l'equipier-comptoir depuis le back-office. Si une feature `quantite_stock` est ajoutee
plus tard, ce sera une nouvelle colonne avec sa propre logique de decrement/realimentation.

### Note 5 - Audit fields uniformes

Les tables metier portent `created_at` et `updated_at`. Cette uniformite permet :
- Diagnostic ("quand cette donnee a-t-elle ete modifiee ?")
- Tri par recence dans le back-office sans table dediee
- Synchronisation eventuelle avec un cache

Les tables de jointure pure (`menu_produit`, `role_permission`) n'ont pas de `updated_at` :
les jointures sont supprimees+recreees au lieu d'etre modifiees.

### Note 6 - Polymorphisme `ligne_commande` -> (`produit` ou `menu`)

Pattern utilise : 2 colonnes nullables avec un discriminateur `type_item`. Avantages :
- FKs reelles vers les tables ciblees (integrite referentielle)
- Lisible en SQL (`JOIN produit ON l.produit_id = p.id` selon `type_item`)

Alternative consideree : une colonne `item_id` + `item_type` sans FK reelle (Rails-style
polymorphic association). Inconvenient : pas d'integrite referentielle SGBD.

Choix retenu : 2 colonnes + 2 FKs + contrainte CHECK. Cout : 1 colonne supplementaire
(`menu_id` souvent NULL, `produit_id` parfois NULL), gain : integrite forte.

### Note 7 - Limites RFC pour les emails et libelles

- `email` : VARCHAR(254) (RFC 5321)
- `libelle` produit/menu : VARCHAR(120) - couvre la quasi-totalite des libelles observes dans
  la source ecole (max observe : 41 chars). Marge 3x.
- `slug` : VARCHAR(60) - coherent avec les conventions URL kebab-case courantes.

### Note 8 - `source` vs `mode_consommation` (separation canal / fiscalite)

Deux dimensions distinctes que la modelisation Wakdo separe explicitement :

| | `source` | `mode_consommation` |
|---|---|---|
| Nature | canal de saisie de la commande (input) | mode de consommation (output) |
| Valeurs | kiosk, comptoir, drive | sur_place, a_emporter, drive |
| Decision metier | qui a saisi la commande, authentification, analytics | TVA applicable, gestion capacite salle |

Les deux dimensions sont independantes pour `kiosk` et `comptoir` (un client a la borne peut choisir sur_place OU a_emporter ; idem au comptoir). Le `drive` est le seul cas ou les deux dimensions sont identiques : `source=drive` implique `mode_consommation=drive`.

Cette contrainte croisee est verifiee a l'ecriture (MLT - precondition de l'operation `creer_commande`). En SQL elle pourrait etre exprimee par un CHECK : `CHECK (source != 'drive' OR mode_consommation = 'drive')`.

### Note 9 - TVA en restauration rapide chez Wakdo

Wakdo est un fast-food, pas un restaurant a service a table : quel que soit le `mode_consommation`, tout est servi en emballages papier (sur plateau pour `sur_place`, en sac pour `a_emporter` et `drive`). La distinction `sur_place` vs `a_emporter` ne porte donc pas sur le service mais sur :

- **TVA applicable** : 10% pour la consommation immediate sur place, 5,5% pour les produits a emporter destines a la consommation differee (cf. service-public.fr article F31407, 2024)
- **Occupation salle** : le client `sur_place` consomme une place assise (utile si une feature capacite est ajoutee plus tard)

Le taux de TVA est snapshote dans `commande.tva_taux_pourmille` au moment de la transaction pour preserver l'integrite historique si la legislation evolue.

### Note 10 - Pattern event sourcing simplifie via `commande_event`

Plutot que d'ajouter des colonnes `saisi_par_id`, `valide_par_id`, `prepare_par_id`, `livre_par_id` sur `commande` (denormalisation lourde, 4 FKs), Wakdo retient une table d'audit dediee `commande_event` (cf. entite 3.7).

**Principe** : `commande` porte uniquement l'**etat courant** (`statut`). Chaque transition d'etat insere une ligne dans `commande_event` (append-only, immuable). Pour reconstituer l'historique d'une commande : `SELECT * FROM commande_event WHERE commande_id = ? ORDER BY created_at`.

**Avantages** :
- Tracabilite complete sans charger `commande` de colonnes peu remplies
- Extensible : ajouter un nouveau type d'evenement (REFUNDED, RECLAIMED, ...) = ajouter une valeur a l'ENUM `event_type`, sans migration intrusive
- Compatible avec analytics fines : "temps moyen entre PAID et READY par equipier" via JOIN sur `(user_id, event_type)`

**Couts assumes** :
- Pattern d'ecriture systematique a respecter : chaque service qui modifie `commande.statut` doit aussi inserer dans `commande_event`. A encapsuler dans un repository pour eviter les oublis.
- Volume table x5-x8 par rapport a `commande`
- Requete "qui a saisi cette commande" demande un join (pas de denormalisation `saisi_par_id` directe)

Si le cout SQL devient penible plus tard, on pourra dupliquer `saisi_par_id` sur `commande` comme colonne denormalisee, sans changer le pattern event.

**Defendable a l'oral** comme "audit log applicatif" ou "event sourcing simplifie", aligne sur les pratiques de tracabilite des SI en production.

### Note 11 - Stockage des images : path en VARCHAR vs BLOB en DB

Les colonnes `image_path` (entites `categorie`, `produit`, `menu`) stockent un **chemin relatif** au public root (ex : `/uploads/produits/burger-classique.jpg`), pas un chemin absolu serveur. Le PHP resout via un prefixe configure dans `.env` (`UPLOAD_DIR=public/uploads`).

#### Pourquoi pas un BLOB en BDD ?

L'alternative consistant a stocker les images en LONGBLOB dans MariaDB a ete consideree puis ecartee :

| Critere | `image_path` VARCHAR (retenu) | BLOB en DB |
|---|---|---|
| Performance kiosk | Apache sert le fichier en ms (cache OS) | PHP lit la DB + streame, latence multipliee |
| Cache HTTP | ETag, Last-Modified, cache browser, CDN natifs | A reimplementer cote PHP |
| Backup BDD | Quelques Mo (paths uniquement) | Croissance Go (66 produits x ~200 Ko + variantes responsive) |
| Replication / dump | Rapide | Lente, ralentit les ACK |
| Pipeline image | `convert`, `webp`, optimisation = outils filesystem standards | A reinventer en PHP |
| Cout cloud (si migration) | Storage S3-like cheap | BDD storage cher |

Pour un MVP fast-food avec borne tactile reactive, le filesystem est le choix par defaut documente dans la litterature web (cf. references). Le BLOB en DB se justifie pour des cas specifiques (fichiers sensibles avec acces controle par ligne, garantie ACID sur le contenu) qui ne s'appliquent pas a un catalogue produit public.

#### Le "leak" de path n'en est pas un

Argument souvent entendu : "stocker un chemin en DB expose la structure du serveur". Analyse :

- `image_path` contient un chemin **relatif** (`/uploads/produits/...`), pas absolu.
- Cette URL est par definition **publique** : la borne kiosk affiche `<img src="/uploads/produits/burger.jpg">` que n'importe quel visiteur voit dans le HTML.
- Pour acceder a la colonne `image_path` en DB, un attaquant doit deja avoir une breche DB (SQLi, credentials voles). A ce stade il a deja toutes les donnees metier (commandes, password_hash, etc.) ; connaitre `/uploads/produits/` est l'info la moins critique de la DB.

#### Les vrais risques securite filesystem (traites par ailleurs)

1. **Path traversal a l'upload** : valider que le nom de fichier upload passe par `basename()` + regex `^[a-z0-9_-]+\.(jpg|png|webp)$` cote service admin.
2. **MIME type spoof** : verifier le vrai MIME via `finfo_file()` (extension `.jpg` ne suffit pas). Desactiver l'execution PHP dans `/uploads/` via Apache (`php_flag engine off` + `FilesMatch .(php|phtml|phar)$ deny`).
3. **Stockage hors-webroot pour les fichiers sensibles** : pas applicable au catalogue public, mais regle de principe pour PDF de facturation, exports stats, etc.
4. **Validation taille** : `UPLOAD_MAX_SIZE_MB` dans `.env` + verification PHP cote upload.
5. **Nom non-predictible pour fichiers sensibles** : UUID au lieu du nom metier si l'image contient des donnees sensibles. Pas applicable a un catalogue public.

#### Sources

- OWASP File Upload Cheat Sheet (section "Filesystem storage")
- MariaDB Knowledge Base - LONGBLOB performance considerations
- Apache HTTP Server documentation - `mod_xsendfile` et serving static content

---

## 5. A faire au prochain sprint (MCD)

- Tracer le MCD avec les cardinalites precises (entites + associations + roles + cardinalites
  min/max)
- Cross-validation MCD <-> MCT (mantra #34) : verifier que chaque traitement metier identifie
  manipule des entites existantes et que chaque entite participe a au moins un traitement
- Decider du nommage final des associations (`compose`, `passe_commande`, `contient`, etc.)
- Eventuellement normaliser plus loin (3NF) si une derive est detectee
