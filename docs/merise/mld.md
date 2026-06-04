# Modele Logique des Donnees (MLD) - Wakdo

**Phase Merise** : P1 - Conception, etape 5 (apres MCD, MCT, MLT)
**Statut** : v0.1
**Date** : 2026-05-28
**Branche** : `feat/p1-conception`
**Auteur methodologie** : BYAN

---

## 1. Objet du document

Le MLD transcrit le MCD en schema relationnel formel : 1 entite -> 1 table, chaque association traduite selon sa cardinalite, contraintes referentielles materialisees, index dimensionnes pour les acces frequents.

C'est l'etape qui transforme la modelisation conceptuelle en specification implementable. Le DDL SQL (`db/migrations/0001_init_schema.sql`) sera derive directement de ce document a P2.

**Source** : `dictionary.md` (types et contraintes par attribut), `mcd.md` (entites + cardinalites + decisions reportees), `mct.md` (operations + entites manipulees), `mlt.md` (regles de gestion + transitions + protection concurrence).

**Cibles** :
- MariaDB 11.4 LTS (cf. `docker-compose.yml` service `wakdo-db`)
- Engine InnoDB (ACID, FKs, row-level locking, CHECK depuis 10.2.1)
- Charset `utf8mb4` collation `utf8mb4_unicode_ci`

---

## 2. Conventions de notation

### Notation relationnelle

```
TABLE_NAME (col1, col2, #col_fk, [col_optionnelle])

  PK : col1
  UK : col2
  FK : col_fk -> AUTRE_TABLE(id)
```

| Symbole | Signification |
|---|---|
| `col` | Colonne NOT NULL |
| `[col]` | Colonne nullable |
| `#col` | Colonne FK (sans le diese : non-FK) |

Cette notation reste proche de l'usage Merise francais (UNIRIS, ouvrages Nanci/Espinasse) : la cle primaire est soulignee dans les documents classiques, ici on prefixe par `PK` pour la portabilite ASCII.

### Types

Les types SQL exacts sont definis dans `dictionary.md` section 2 (Conventions generales) et reprecises dans chaque section de cette MLD. Conventions retenues :

- `INT UNSIGNED AUTO_INCREMENT` pour toutes les PK techniques
- `INT UNSIGNED` pour tous les montants en centimes (anti-FLOAT cf. dictionary note 1)
- `VARCHAR(<n>)` avec longueur calibree selon dictionary note 7
- `ENUM(...)` pour les valeurs metier stables (cf. dictionary note 2)
- `DATETIME` pour les timestamps (pas TIMESTAMP qui ferait du fuseau auto-implicite)

---

## 3. Regles de traduction MCD -> MLD

Les regles classiques de passage MCD -> MLD appliquees :

### 3.1 Entite -> Table

Chaque entite du MCD devient une table. L'identifiant conceptuel `id` devient PK technique `INT UNSIGNED AUTO_INCREMENT`. Les attributs gardent leurs noms et types.

### 3.2 Association `(1,1) - (1,N)` -> FK simple

L'entite cote `(1,1)` porte la FK vers l'entite cote `(0,N)` ou `(1,N)`. Exemple :

```
CATEGORIE (1,1) <--regroupe--> (0,N) PRODUIT

devient

CATEGORIE (id, libelle, ...)            -- pas de FK
PRODUIT   (id, #categorie_id, ...)      -- FK vers CATEGORIE
```

### 3.3 Association `(0,N) - (0,N)` ou `(1,N) - (1,N)` -> Table de jointure

L'association devient sa propre table avec PK composite des deux FKs. Exemple :

```
MENU (1,N) <--compose--> (0,N) PRODUIT (via MENU_PRODUIT)

devient

MENU_PRODUIT (#menu_id, #produit_id, role, position)
  PK composite : (menu_id, produit_id)
```

### 3.4 Association porteuse d'attributs -> Table associative

Si une association MCD porte des attributs propres (`role`, `position` sur `compose`), elle devient table meme si elle pourrait theoriquement etre une FK. Cas applique a `MENU_PRODUIT` et `ROLE_PERMISSION`.

### 3.5 Polymorphisme -> 2 FKs nullables + discriminateur

`LIGNE_COMMANDE` -> (`PRODUIT` ou `MENU`) traduit en 2 colonnes FK nullable + 1 colonne discriminateur :

```
LIGNE_COMMANDE (id, #commande_id, type_item, [#produit_id], [#menu_id], ...)
  CHECK ((type_item='produit' AND produit_id IS NOT NULL AND menu_id IS NULL)
      OR (type_item='menu'    AND menu_id IS NOT NULL    AND produit_id IS NULL))
```

Cf. `docs/notes/polymorphic-fk-snapshots.md` pour la justification.

### 3.6 Audit (event sourcing) -> Table dediee

`COMMANDE_EVENT` est une table append-only, traduction directe de l'entite MCD 3.7. Aucune denormalisation `user_id` sur `commande` (cf. dictionary note 10).

---

## 4. Schema relationnel formel

Les 11 tables qui composent le schema Wakdo, ordonnees par dependance (les tables sans FK d'abord, puis les tables qui dependent d'elles).

### 4.1 `categorie`

```
categorie (id, libelle, slug, image_path, ordre, est_actif, created_at, updated_at)

  PK : id
  UK : libelle
  UK : slug
```

Types :
- `id INT UNSIGNED AUTO_INCREMENT`
- `libelle VARCHAR(80) NOT NULL`
- `slug VARCHAR(60) NOT NULL`
- `image_path VARCHAR(255) NULL` (cf. dictionary note 11)
- `ordre SMALLINT UNSIGNED NOT NULL DEFAULT 0`
- `est_actif TINYINT(1) NOT NULL DEFAULT 1`
- `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

Aucune FK. Table racine du sous-domaine Catalogue.

### 4.2 `produit`

```
produit (id, #categorie_id, libelle, [description], prix_ttc_cents, [image_path],
         est_disponible, ordre, created_at, updated_at)

  PK : id
  FK : categorie_id -> categorie(id) ON DELETE RESTRICT
  IDX : (categorie_id, est_disponible, ordre)
```

Types :
- `id INT UNSIGNED AUTO_INCREMENT`
- `categorie_id INT UNSIGNED NOT NULL`
- `libelle VARCHAR(120) NOT NULL`
- `description TEXT NULL`
- `prix_ttc_cents INT UNSIGNED NOT NULL CHECK (prix_ttc_cents > 0)`
- `image_path VARCHAR(255) NULL`
- `est_disponible TINYINT(1) NOT NULL DEFAULT 1`
- `ordre SMALLINT UNSIGNED NOT NULL DEFAULT 0`
- `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

**ON DELETE RESTRICT** sur `categorie_id` : impossible de supprimer une categorie qui contient des produits (protection metier, evite les orphelins).

### 4.3 `menu`

```
menu (id, #categorie_id, libelle, [description], prix_ttc_cents, [image_path],
      est_disponible, ordre, created_at, updated_at)

  PK : id
  FK : categorie_id -> categorie(id) ON DELETE RESTRICT
  IDX : (categorie_id, est_disponible, ordre)
```

Types : identiques a `produit` (meme structure, semantique distincte cf. dictionary note 3).

### 4.4 `menu_produit` (table associative)

```
menu_produit (#menu_id, #produit_id, role, position)

  PK : (menu_id, produit_id)
  FK : menu_id    -> menu(id)    ON DELETE CASCADE
  FK : produit_id -> produit(id) ON DELETE RESTRICT
  IDX : (menu_id, position)
```

Types :
- `menu_id INT UNSIGNED NOT NULL`
- `produit_id INT UNSIGNED NOT NULL`
- `role ENUM('burger','accompagnement','boisson','sauce','dessert') NOT NULL`
- `position SMALLINT UNSIGNED NOT NULL DEFAULT 0`

**ON DELETE CASCADE** sur `menu_id` : si un menu est supprime, ses compositions le sont aussi.
**ON DELETE RESTRICT** sur `produit_id` : impossible de supprimer un produit utilise dans un menu (protection integrite menu).

Pas d'`updated_at` (table de jointure, cf. dictionary note 5 : les jointures sont supprimees+recreees, pas modifiees).

### 4.5 `commande`

```
commande (id, numero, source, mode_consommation, statut,
          total_ht_cents, total_tva_cents, total_ttc_cents, tva_taux_pourmille,
          [paye_a], created_at, updated_at)

  PK : id
  UK : numero
  IDX : (source, created_at)
  IDX : (statut, created_at)
  IDX : created_at
  CHECK : source != 'drive' OR mode_consommation = 'drive'
  CHECK : total_ttc_cents = total_ht_cents + total_tva_cents
```

Types :
- `id INT UNSIGNED AUTO_INCREMENT`
- `numero VARCHAR(20) NOT NULL`
- `source ENUM('kiosk','comptoir','drive') NOT NULL`
- `mode_consommation ENUM('sur_place','a_emporter','drive') NOT NULL`
- `statut ENUM('pending_payment','paid','preparing','ready','delivered','cancelled') NOT NULL DEFAULT 'pending_payment'`
- `total_ht_cents INT UNSIGNED NOT NULL CHECK (total_ht_cents >= 0)`
- `total_tva_cents INT UNSIGNED NOT NULL CHECK (total_tva_cents >= 0)`
- `total_ttc_cents INT UNSIGNED NOT NULL CHECK (total_ttc_cents > 0)`
- `tva_taux_pourmille SMALLINT UNSIGNED NOT NULL`
- `paye_a DATETIME NULL` (NULL avant paiement, timestamp du passage en `paid`)
- `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

**CHECK croise** `source/mode_consommation` (cf. dictionary note 8) : empeche les combinaisons invalides au niveau SGBD plutot que de se reposer uniquement sur le code applicatif.

**CHECK montants** : invariant `TTC = HT + TVA` verifie en base (defense-in-depth contre les bugs de calcul applicatif).

Aucune FK directe vers `user` : la tracabilite passe par `commande_event` (cf. 4.7).

### 4.6 `ligne_commande`

```
ligne_commande (id, #commande_id, type_item, [#produit_id], [#menu_id],
                libelle_snapshot, prix_unitaire_ttc_cents_snapshot, quantite, created_at)

  PK : id
  FK : commande_id -> commande(id) ON DELETE CASCADE
  FK : produit_id  -> produit(id)  ON DELETE RESTRICT
  FK : menu_id     -> menu(id)     ON DELETE RESTRICT
  IDX : commande_id
  CHECK : (type_item='produit' AND produit_id IS NOT NULL AND menu_id IS NULL)
       OR (type_item='menu'    AND menu_id    IS NOT NULL AND produit_id IS NULL)
```

Types :
- `id INT UNSIGNED AUTO_INCREMENT`
- `commande_id INT UNSIGNED NOT NULL`
- `type_item ENUM('produit','menu') NOT NULL`
- `produit_id INT UNSIGNED NULL`
- `menu_id INT UNSIGNED NULL`
- `libelle_snapshot VARCHAR(120) NOT NULL`
- `prix_unitaire_ttc_cents_snapshot INT UNSIGNED NOT NULL CHECK (prix_unitaire_ttc_cents_snapshot > 0)`
- `quantite SMALLINT UNSIGNED NOT NULL DEFAULT 1 CHECK (quantite > 0)`
- `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

**ON DELETE CASCADE** sur `commande_id` : si la commande disparait, ses lignes aussi.
**ON DELETE RESTRICT** sur `produit_id` et `menu_id` : impossible de supprimer un produit/menu reference par une commande historique (preserve les references meme si on snapshote).

**CHECK polymorphisme** : exclusivite mutuelle `produit_id` / `menu_id` selon `type_item` (cf. dictionary note 6).

### 4.7 `commande_event`

```
commande_event (id, #commande_id, event_type, [from_statut], to_statut,
                [#user_id], [payload], created_at)

  PK : id
  FK : commande_id -> commande(id) ON DELETE CASCADE
  FK : user_id     -> user(id)     ON DELETE SET NULL
  IDX : (commande_id, created_at)
  IDX : (user_id, created_at)
  IDX : (event_type, created_at)
```

Types :
- `id INT UNSIGNED AUTO_INCREMENT`
- `commande_id INT UNSIGNED NOT NULL`
- `event_type ENUM('CREATED','PAID','PREPARING_STARTED','READY','DELIVERED','CANCELLED') NOT NULL`
- `from_statut ENUM('pending_payment','paid','preparing','ready','delivered','cancelled') NULL`
- `to_statut ENUM('pending_payment','paid','preparing','ready','delivered','cancelled') NOT NULL`
- `user_id INT UNSIGNED NULL`
- `payload JSON NULL`
- `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

**ON DELETE CASCADE** sur `commande_id` : si la commande est purgee, son journal disparait avec elle.
**ON DELETE SET NULL** sur `user_id` : si un equipier est supprime, les events restent (l'audit reste consultable, l'attribution individuelle est perdue).

**Pas d'`updated_at`** : table append-only. Aucun UPDATE applicatif autorise (cf. mlt.md RG-T10).

**Pas de CHECK croise from_statut/to_statut** : la verification de la machine a etats est applicative (mlt section 12), un CHECK SQL serait trop rigide (event_type peut prendre des valeurs non encore prevues).

### 4.8 `user`

```
user (id, email, password_hash, nom, prenom, #role_id, est_actif, [last_login_at],
      created_at, updated_at)

  PK : id
  UK : email
  FK : role_id -> role(id) ON DELETE RESTRICT
  IDX : (est_actif, role_id)
```

Types :
- `id INT UNSIGNED AUTO_INCREMENT`
- `email VARCHAR(254) NOT NULL` (RFC 5321)
- `password_hash VARCHAR(255) NOT NULL` (argon2id, cf. `.env` `PASSWORD_ALGO`)
- `nom VARCHAR(60) NOT NULL`
- `prenom VARCHAR(60) NOT NULL`
- `role_id INT UNSIGNED NOT NULL`
- `est_actif TINYINT(1) NOT NULL DEFAULT 1`
- `last_login_at DATETIME NULL`
- `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

**ON DELETE RESTRICT** sur `role_id` : impossible de supprimer un role qui a encore des users (passer par `est_actif = 0` sur le role avant de supprimer).

### 4.9 `role`

```
role (id, code, libelle, [description], est_actif, created_at, updated_at)

  PK : id
  UK : code
```

Types :
- `id INT UNSIGNED AUTO_INCREMENT`
- `code VARCHAR(40) NOT NULL`
- `libelle VARCHAR(80) NOT NULL`
- `description TEXT NULL`
- `est_actif TINYINT(1) NOT NULL DEFAULT 1`
- `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

Aucune FK. Table racine du sous-domaine RBAC.

### 4.10 `permission`

```
permission (id, code, libelle, [description], created_at)

  PK : id
  UK : code
```

Types :
- `id INT UNSIGNED AUTO_INCREMENT`
- `code VARCHAR(60) NOT NULL` (format `<resource>.<action>`)
- `libelle VARCHAR(120) NOT NULL`
- `description TEXT NULL`
- `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

Pas d'`updated_at` : les permissions sont declarees en migration et ne sont pas modifiees via UI (cf. RBAC statique cote permissions, dictionary 3.10 et MCD 4.3).

### 4.11 `role_permission` (table associative)

```
role_permission (#role_id, #permission_id)

  PK : (role_id, permission_id)
  FK : role_id       -> role(id)       ON DELETE CASCADE
  FK : permission_id -> permission(id) ON DELETE CASCADE
  IDX : permission_id  (acces inverse "quels roles ont cette permission ?")
```

Types :
- `role_id INT UNSIGNED NOT NULL`
- `permission_id INT UNSIGNED NOT NULL`

**ON DELETE CASCADE des deux cotes** : suppression d'un role ou d'une permission supprime ses mappings.

Pas de timestamps (table de jointure pure, cf. dictionary note 5).

---

## 5. Recapitulatif des contraintes referentielles

| FK | Reference | ON DELETE | Justification |
|---|---|---|---|
| `produit.categorie_id` | `categorie(id)` | RESTRICT | Pas d'orphelin produit |
| `menu.categorie_id` | `categorie(id)` | RESTRICT | Idem |
| `menu_produit.menu_id` | `menu(id)` | CASCADE | Composition disparait avec le menu |
| `menu_produit.produit_id` | `produit(id)` | RESTRICT | Pas de cascade : un produit reference dans un menu ne peut pas etre supprime sans amender la composition |
| `commande.--` | (aucune FK vers user) | - | Tracabilite via commande_event |
| `ligne_commande.commande_id` | `commande(id)` | CASCADE | Lignes disparaissent avec la commande |
| `ligne_commande.produit_id` | `produit(id)` | RESTRICT | Preserve l'integrite historique |
| `ligne_commande.menu_id` | `menu(id)` | RESTRICT | Idem |
| `commande_event.commande_id` | `commande(id)` | CASCADE | Journal disparait avec la commande |
| `commande_event.user_id` | `user(id)` | SET NULL | Audit conserve, attribution individuelle perdue |
| `user.role_id` | `role(id)` | RESTRICT | Pas d'user sans role |
| `role_permission.role_id` | `role(id)` | CASCADE | Mapping disparait avec le role |
| `role_permission.permission_id` | `permission(id)` | CASCADE | Mapping disparait avec la permission |

**Cles** :
- **CASCADE** : la donnee dependante n'a pas de sens hors de son parent (lignes / events / mappings)
- **RESTRICT** : suppression du parent bloquee tant que des references existent (catalogue, role)
- **SET NULL** : preserve la donnee enfant en perdant le lien (audit event sans attribution)

---

## 6. Index complementaires

Au-dela des PK / UK / FK qui creent des index automatiquement, indexes ajoutes pour les requetes frequentes identifiees au MCT/MLT :

| Table | Index | Justification (operation MCT) |
|---|---|---|
| `produit` | `(categorie_id, est_disponible, ordre)` | Chargement catalogue kiosk (op 1) : filtre par categorie + disponible + tri par ordre |
| `menu` | `(categorie_id, est_disponible, ordre)` | Idem produit |
| `menu_produit` | `(menu_id, position)` | Chargement composition d'un menu |
| `commande` | `(source, created_at)` | Stats "par canal" + tri chronologique |
| `commande` | `(statut, created_at)` | Files d'attente preparation/accueil (ops 6, 9) |
| `commande` | `created_at` | Stats agregations live |
| `ligne_commande` | `commande_id` | Recuperation des lignes d'une commande |
| `commande_event` | `(commande_id, created_at)` | Historique d'une commande |
| `commande_event` | `(user_id, created_at)` | Actions d'un equipier sur une periode |
| `commande_event` | `(event_type, created_at)` | Stats "combien de cancellations cette semaine ?" |
| `user` | `(est_actif, role_id)` | Login + permissions check (op 23) |
| `role_permission` | `permission_id` | Acces inverse "quels roles ont cette permission ?" |

**Index NON ajoutes** (volontaire) :
- `commande.numero` : UK suffit, pas de range query attendue dessus
- `commande.mode_consommation` : faible cardinalite (3 valeurs), un index n'est pas rentable, full scan acceptable
- `commande.paye_a` : NULL pour la majorite des lignes (commande encore en cours), index peu utile

---

## 7. Contraintes CHECK (MariaDB 10.2+)

Verification au niveau SGBD pour les invariants critiques. Defense-in-depth contre les bugs applicatifs.

| Table | CHECK | Pourquoi |
|---|---|---|
| `produit` | `prix_ttc_cents > 0` | Prix nul ou negatif = bug |
| `menu` | `prix_ttc_cents > 0` | Idem |
| `commande` | `total_ht_cents >= 0` | Plancher autorise (commande vide transitoire ?) |
| `commande` | `total_tva_cents >= 0` | Idem |
| `commande` | `total_ttc_cents > 0` | TTC nul = bug |
| `commande` | `total_ttc_cents = total_ht_cents + total_tva_cents` | Invariant arithmetique |
| `commande` | `source != 'drive' OR mode_consommation = 'drive'` | Contrainte croisee (dictionary note 8, mlt RG-T09) |
| `ligne_commande` | `prix_unitaire_ttc_cents_snapshot > 0` | Snapshot prix non nul |
| `ligne_commande` | `quantite > 0` | Quantite non nulle |
| `ligne_commande` | `(type_item='produit' AND produit_id IS NOT NULL AND menu_id IS NULL) OR (type_item='menu' AND menu_id IS NOT NULL AND produit_id IS NULL)` | Polymorphisme exclusif (dictionary note 6) |

---

## 8. Cross-validation entites MCD -> tables MLD

| Entite MCD | Table MLD | Notes |
|---|---|---|
| `categorie` (3.1) | `categorie` (4.1) | 1:1 |
| `produit` (3.2) | `produit` (4.2) | 1:1 |
| `menu` (3.3) | `menu` (4.3) | 1:1 |
| `menu_produit` (3.4) | `menu_produit` (4.4) | Entite associative -> table de jointure avec PK composite |
| `commande` (3.5) | `commande` (4.5) | 1:1, attribut `source` ajoute (decision 2026-05-28) |
| `ligne_commande` (3.6) | `ligne_commande` (4.6) | 1:1, polymorphisme materialise par 2 FKs nullables + CHECK |
| `commande_event` (3.7) | `commande_event` (4.7) | 1:1, table append-only, decision 2026-05-28 |
| `user` (3.8) | `user` (4.8) | 1:1 |
| `role` (3.9) | `role` (4.9) | 1:1 |
| `permission` (3.10) | `permission` (4.10) | 1:1 |
| `role_permission` (3.11) | `role_permission` (4.11) | Entite associative -> table de jointure avec PK composite |

**Conclusion** : 11/11 entites tracees. Aucune entite MCD ne reste sans table, aucune table MLD ne sort du modele conceptuel.

---

## 9. Estimation volumes et taille

| Table | Volume 6 mois | Taille moyenne ligne | Taille totale |
|---|---|---|---|
| `categorie` | ~10 | 200 octets | < 1 Ko |
| `produit` | ~70 | 400 octets | ~30 Ko |
| `menu` | ~15 | 400 octets | ~6 Ko |
| `menu_produit` | ~80 | 30 octets | ~2 Ko |
| `commande` | ~30k | 300 octets | ~9 Mo |
| `ligne_commande` | ~150k | 200 octets | ~30 Mo |
| `commande_event` | ~180k | 200 octets | ~36 Mo |
| `user` | ~20 | 500 octets | ~10 Ko |
| `role` | ~5 | 200 octets | ~1 Ko |
| `permission` | ~40 | 300 octets | ~12 Ko |
| `role_permission` | ~80 | 30 octets | ~2 Ko |

**Total : ~75 Mo sur 6 mois**. Largement gerable par MariaDB sur le conteneur Wakdo (volume `wakdo_db_data` named volume, cf. `docker-compose.yml`).

Les indexes ajoutent typiquement 30-50% du volume des tables, soit ~30 Mo supplementaires. **Estimation totale : ~100-110 Mo / 6 mois**.

---

## 10. Decisions reportees au DDL et a P2

Les decisions suivantes sont laissees au DDL (`db/migrations/0001_init_schema.sql`) ou aux phases ulterieures, parce qu'elles concernent l'implementation et pas la modelisation logique :

1. **Triggers ou colonnes generees** : `service_day` (cf. PROJECT_CONTEXT section 2) pourrait etre une `GENERATED ALWAYS AS (DATE_SUB(created_at, INTERVAL 4 HOUR + 30 MINUTE))` virtuelle pour eviter le calcul applicatif. A evaluer en P3 si les stats sont penibles.
2. **Partitionnement** : `commande_event` pourrait etre partitionne par mois si le volume depasse les estimations. Pas pour MVP.
3. **Foreign Key index** : MariaDB cree automatiquement un index sur la FK lors de la declaration, sauf si un index utilisable existe deja. A verifier explicitement dans le DDL.
4. **Collation** : `utf8mb4_unicode_ci` retenu (sensible diacritiques et casse pour les recherches). Si besoin de tri locale francais strict, passer en `utf8mb4_fr_0900_ai_ci` (MySQL 8) ou rester en `unicode_ci`.
5. **Engine** : `InnoDB` par defaut (ACID + FKs). Pas de MEMORY ni Archive.
6. **Charset emoji** : `utf8mb4` (4 octets / char max) couvre les emojis au cas ou ils apparaitraient dans `description` produit ou `payload` JSON.

---

## 11. A faire au prochain sprint (DDL + Seed)

1. **DDL** (`db/migrations/0001_init_schema.sql`) : transcrire ce MLD en CREATE TABLE executables, dans l'ordre des dependances (categorie -> produit/menu -> menu_produit -> commande -> ligne_commande/commande_event ; permission -> role -> role_permission ; user en dernier).

2. **Seed** (`db/seeds/0001_demo_data.sql`) : INSERT pour :
   - 9 categories + 53 produits + 13 menus a partir des JSON sources (`docs/merise/_sources/`), prix normalises en centimes
   - 1 admin par defaut + roles (admin, manager, equipier-comptoir, equipier-drive)
   - Permissions declarees (CRUD produit/menu/categorie/user/role + commande operationnelles)
   - Quelques commandes d'exemple pour les demos

3. **Export fallback JSON** (`scripts/export-fallback.{sh|php}`) : extrait des donnees seed vers `src/public/borne/data/*.json` pour le mode "Bloc 1 isole" (kiosk sans BDD pour les tests).

4. **Tests de validation DDL** : verifier que :
   - Toutes les CHECK contraintes sont declenchees comme attendu (tests d'integration)
   - Les ON DELETE CASCADE / RESTRICT se comportent comme specifie
   - Les indexes accelerent reellement les requetes ciblees (EXPLAIN sur les requetes types du MCT)

5. **Migration tooling** : decider de l'outil (phinx, doctrine migrations, ou homemade PHP script). Cf. PROJECT_CONTEXT pour le choix retenu.
