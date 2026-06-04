# Modele Logique des Traitements (MLT) - Wakdo

**Phase Merise** : P1 - Conception, etape 4 (derive du MCT)
**Statut** : v0.1
**Date** : 2026-05-21
**Branche** : `feat/p1-conception`
**Auteur methodologie** : BYAN

---

## 1. Objet du document

Le MLT (Modele Logique des Traitements) raffine chaque operation du MCT en precisant :
- les **preconditions** (ce qui doit etre vrai avant l'execution)
- les **regles de traitement** (validations, calculs, logique metier)
- les **postconditions** (l'etat garanti apres succes)
- les **sorties** (donnees produites ou evenements emis)

Il fait le lien entre le MCT (niveau conceptuel) et l'implementation PHP/SQL (niveau physique).
Toutes les references aux entites/attributs sont celles du dictionnaire de donnees
(`docs/merise/dictionary.md`) et du MCD (`docs/merise/mcd.md`).

**Conventions de ce document** :
- `[PRE]` : precondition - doit etre satisfaite pour que l'operation s'execute
- `[RG]` : regle de gestion - logique metier appliquee pendant l'execution
- `[POST]` : postcondition - etat de la base garanti apres succes
- `[OUT]` : sortie - donnee ou evenement produit
- `[ERR]` : cas d'erreur - sortie alternative si une condition echoue

---

## 2. Domaine 1 - Parcours commande (borne kiosk)

### 2.1 CHARGER_CATALOGUE

**Correspond a MCT section 3.1**

| Tag | Contenu |
|-----|---------|
| **[PRE-1]** | La requete provient d'un client sur la borne (endpoint public, pas d'authentification requise) |
| **[PRE-2]** | La plage horaire courante est comprise dans la fenetre de service (10h00-01h00) ; hors fenetre, la borne affiche un message de fermeture |
| **[RG-1]** | Lecture de toutes les `categorie` avec `est_actif = 1`, ordonnees par `categorie.ordre ASC` |
| **[RG-2]** | Pour chaque categorie, lecture des `produit` avec `est_disponible = 1` et `categorie_id = categorie.id`, ordonnes par `produit.ordre ASC` |
| **[RG-3]** | Lecture de tous les `menu` avec `est_disponible = 1`, avec jointure sur `menu_produit` pour la composition (roles et positions) |
| **[RG-4]** | Les prix sont retournes en centimes (INT) ; la conversion en EUR est effectuee cote front |
| **[POST-1]** | Aucune ecriture en base. L'etat de la base est inchange. |
| **[OUT-1]** | Reponse JSON : `{data: {categories: [...], produits: {...}, menus: [...]}}` |
| **[ERR-1]** | Si la BDD est inaccessible : reponse `{data: null, error: {code: "DB_ERROR", message: "..."}}` et le front bascule sur le JSON fallback statique |

---

### 2.2 COMPOSER_PANIER

**Correspond a MCT section 3.2**

| Tag | Contenu |
|-----|---------|
| **[PRE-1]** | Le catalogue a ete charge en memoire front (CHARGER_CATALOGUE effectue) |
| **[PRE-2]** | L'article selectionne (produit ou menu) est present dans le catalogue charge et a `est_disponible = 1` |
| **[RG-1]** | Le panier est une structure en memoire JavaScript (tableau d'items). Aucune persistance BDD a ce stade. |
| **[RG-2]** | Chaque item du panier contient : `type` (`produit` ou `menu`), `item_id`, `libelle`, `prix_unitaire_ttc_cents`, `quantite`, `options` (taille si applicable) |
| **[RG-3]** | Option grande taille : si `type = 'produit'` et le produit appartient aux categories `frites` ou `boissons`, l'option `grande_taille` ajoute 50 centimes au `prix_unitaire_ttc_cents` de cet item |
| **[RG-4]** | Si un item de meme `(type, item_id, options)` existe deja dans le panier, sa quantite est incrementee plutot qu'un nouvel item est ajoute |
| **[RG-5]** | Le total du panier est recalcule apres chaque modification : `SUM(prix_unitaire_ttc_cents * quantite)` sur tous les items |
| **[POST-1]** | Aucune ecriture en base. Etat panier en memoire mis a jour. |
| **[OUT-1]** | Affichage du recapitulatif panier avec le total TTC |
| **[ERR-1]** | Si un produit est passe a `est_disponible = 0` entre le chargement du catalogue et la validation, la verification se produit a l'etape PASSER_COMMANDE (validation serveur) |

---

### 2.3 PASSER_COMMANDE

**Correspond a MCT section 3.3**

| Tag | Contenu |
|-----|---------|
| **[PRE-1]** | Le panier contient au moins 1 item (`items.length >= 1`) |
| **[PRE-2]** | Le numero de commande saisi par le client est non vide (validation front) |
| **[PRE-3]** | Le body JSON POST est valide (schema validation cote API) |
| **[RG-1]** | Pour chaque item du panier, le systeme verifie en base que le produit ou menu est encore `est_disponible = 1`. Si un item n'est plus disponible, la commande est rejetee avec un message liste des articles indisponibles. |
| **[RG-2]** | Determination du `tva_taux_pourmille` selon `mode_consommation` : `sur_place` = 1000 (10%), `a_emporter` = 550 (5,5%), `drive` = 550 (5,5%). Ref : service-public.fr article F31407 |
| **[RG-3]** | Calcul des montants (tout en centimes, entiers) : `total_ttc_cents = SUM(prix_unitaire_ttc_cents * quantite)` ; `total_ht_cents = ROUND(total_ttc_cents * 1000 / (1000 + tva_taux_pourmille))` ; `total_tva_cents = total_ttc_cents - total_ht_cents` |
| **[RG-4]** | Generation du numero de commande : format `K-YYYY-MM-DD-NNN` ou NNN est le compteur du jour de service courant (SELECT COUNT + 1 sur le `service_day` en cours, avec verrou pour eviter les doublons en concurrence) |
| **[RG-5]** | Insertion atomique (transaction) : INSERT `commande` puis INSERT N lignes `ligne_commande`. En cas d'echec partiel, rollback complet. |
| **[RG-6]** | Les snapshots `libelle_snapshot` et `prix_unitaire_ttc_cents_snapshot` sont copies depuis les entites courantes au moment de l'insertion (integrite historique). Ces valeurs ne sont pas modifiees apres insertion. |
| **[RG-7]** | La commande est inseree avec `statut = 'pending_payment'`. Une fois le numero de commande saisi par le client (substitut de paiement RNCP), le statut est mis a jour en `paid` dans la meme transaction. La transition `pending_payment -> paid` est atomique : aucun autre acteur ne peut observer le statut `pending_payment`. |
| **[POST-1]** | Une ligne `commande` existe en base avec `statut = 'paid'`, `source = 'kiosk'`, tous les montants calcules. La phase `pending_payment` n'est pas observable en dehors de la transaction. |
| **[POST-2]** | `N` lignes `ligne_commande` existent en base, referençant chacune soit un `produit_id` soit un `menu_id` (contrainte d'exclusivite verifiee) |
| **[POST-3]** | `commande.numero` est unique en base (contrainte UNIQUE sur la colonne) |
| **[OUT-1]** | Reponse HTTP 201 : `{data: {id: int, numero: string, statut: 'paid'}}` |
| **[OUT-2]** | Evenement logique COMMANDE_CREEE disponible pour le domaine preparation (la vue preparation se rafraichit - polling ou push selon implementation) |
| **[ERR-1]** | Panier vide : HTTP 422, `{error: {code: "EMPTY_CART"}}` |
| **[ERR-2]** | Article indisponible : HTTP 422, `{error: {code: "ITEM_UNAVAILABLE", items: [...]}}` |
| **[ERR-3]** | Erreur BDD / timeout : HTTP 500 avec rollback, `{error: {code: "DB_ERROR"}}` |

---

### 2.4 AFFICHER_CONFIRMATION

**Correspond a MCT section 3.4**

| Tag | Contenu |
|-----|---------|
| **[PRE-1]** | La reponse API PASSER_COMMANDE a retourne HTTP 201 avec un objet `{id, numero, statut}` |
| **[RG-1]** | Le numero de commande est affiche en grand sur l'ecran de confirmation |
| **[RG-2]** | Apres un delai configurable (suggestion : 15 secondes), la borne se reinitialise automatiquement pour le client suivant |
| **[POST-1]** | Aucune ecriture en base |
| **[OUT-1]** | Ecran de confirmation affiche avec le numero |
| **[ERR-1]** | Si la reponse API est en erreur : affichage d'un message d'erreur generic et proposition de recommencer |

---

## 3. Domaine 2 - Parcours commande (comptoir et drive)

### 3.1 SAISIR_COMMANDE_MANUELLE

**Correspond a MCT section 4.1**

| Tag | Contenu |
|-----|---------|
| **[PRE-1]** | L'acteur est authentifie (session valide, `est_actif = 1`) |
| **[PRE-2]** | L'acteur possede la permission `commande.create` (verifiee via `role_permission`) |
| **[PRE-3]** | Le panier compose contient au moins 1 article |
| **[RG-1]** | Logique de creation identique a PASSER_COMMANDE (RG-1 a RG-7), a la difference suivante : la `source` est `comptoir` ou `drive` selon le canal selectionne par l'equipier. La meme sequence `pending_payment -> paid` est appliquee de facon atomique dans la transaction. |
| **[RG-2]** | Le `mode_consommation` est saisi par l'equipier (sur_place / a_emporter / drive) |
| **[RG-3]** | Le format du numero de commande est identique : `K-YYYY-MM-DD-NNN` (meme generateur, meme compteur du jour de service) |
| **[POST-1]** | Une ligne `commande` existe en base avec `statut = 'paid'`, `source = 'comptoir'` ou `'drive'`. Le statut `pending_payment` est transitoire et non observable hors transaction. |
| **[POST-2]** | `N` lignes `ligne_commande` existent, avec snapshots |
| **[OUT-1]** | Confirmation affichee dans le back-office, numero de commande communique au client |
| **[ERR-1]** | Memes cas d'erreur que PASSER_COMMANDE (ERR-1, ERR-2, ERR-3) |

---

## 4. Domaine 3 - Preparation (cuisine)

### 4.1 LISTER_COMMANDES_A_PREPARER

**Correspond a MCT section 5.1**

| Tag | Contenu |
|-----|---------|
| **[PRE-1]** | L'acteur est authentifie, `est_actif = 1`, role `preparation` ou `admin` |
| **[PRE-2]** | L'acteur possede la permission `commande.read` |
| **[RG-1]** | Requete : `SELECT commande.*, ligne_commande.* FROM commande JOIN ligne_commande ON ... WHERE commande.statut = 'paid' ORDER BY commande.created_at ASC` |
| **[RG-2]** | Tous les canaux sont confondus (kiosk + comptoir + drive) |
| **[RG-3]** | Pour chaque commande, les lignes sont affichees avec `libelle_snapshot` et `quantite` (les snapshots sont utilises, pas de re-jointure sur produit/menu) |
| **[POST-1]** | Aucune ecriture en base |
| **[OUT-1]** | Liste des commandes au statut `paid`, ordonnee par heure croissante |

---

### 4.2 MARQUER_EN_PREPARATION

**Correspond a MCT section 5.2**

| Tag | Contenu |
|-----|---------|
| **[PRE-1]** | L'acteur est authentifie, permission `commande.update` |
| **[PRE-2]** | La commande ciblee existe et son `statut = 'paid'` |
| **[RG-1]** | `UPDATE commande SET statut = 'preparing', updated_at = NOW() WHERE id = :id AND statut = 'paid'` |
| **[RG-2]** | La clause `AND statut = 'paid'` dans le UPDATE protege contre les mises a jour concurrentes (si deux equipiers cliquent simultanement, seul le premier reussit - le second recoit 0 rows affected) |
| **[POST-1]** | `commande.statut = 'preparing'`, `commande.updated_at` mis a jour |
| **[OUT-1]** | HTTP 200 ou redirection avec message de succes. La commande disparait de la liste "a preparer" et apparait dans la liste "en preparation". |
| **[ERR-1]** | Si `statut != 'paid'` au moment du UPDATE (concurrence) : HTTP 409 `{error: {code: "INVALID_TRANSITION"}}` |

---

### 4.3 MARQUER_PRETE

**Correspond a MCT section 5.3**

| Tag | Contenu |
|-----|---------|
| **[PRE-1]** | L'acteur est authentifie, permission `commande.update` |
| **[PRE-2]** | La commande ciblee existe et son `statut = 'preparing'` |
| **[RG-1]** | `UPDATE commande SET statut = 'ready', updated_at = NOW() WHERE id = :id AND statut = 'preparing'` |
| **[RG-2]** | Meme protection contre la concurrence que MARQUER_EN_PREPARATION |
| **[POST-1]** | `commande.statut = 'ready'`, `commande.updated_at` mis a jour |
| **[OUT-1]** | La commande devient visible dans la vue "pretes" de l'accueil |
| **[ERR-1]** | Transition invalide : HTTP 409 `{error: {code: "INVALID_TRANSITION"}}` |

---

## 5. Domaine 4 - Remise au client (accueil)

### 5.1 LISTER_COMMANDES_PRETES

**Correspond a MCT section 6.1**

| Tag | Contenu |
|-----|---------|
| **[PRE-1]** | L'acteur est authentifie, permission `commande.read` |
| **[RG-1]** | `SELECT commande.*, ligne_commande.* FROM commande JOIN ligne_commande ON ... WHERE commande.statut = 'ready' ORDER BY commande.updated_at ASC` |
| **[RG-2]** | Tri par `updated_at` croissant : les commandes pretes depuis le plus longtemps apparaissent en premier |
| **[POST-1]** | Aucune ecriture en base |
| **[OUT-1]** | Liste des commandes au statut `ready` |

---

### 5.2 DECLARER_LIVREE

**Correspond a MCT section 6.2**

| Tag | Contenu |
|-----|---------|
| **[PRE-1]** | L'acteur est authentifie, permission `commande.update` |
| **[PRE-2]** | La commande ciblee existe et son `statut = 'ready'` |
| **[RG-1]** | `UPDATE commande SET statut = 'delivered', updated_at = NOW() WHERE id = :id AND statut = 'ready'` |
| **[RG-2]** | `delivered` est un statut terminal : aucune transition n'est prevue depuis ce statut (contrainte applicative, non enfoercee en base) |
| **[POST-1]** | `commande.statut = 'delivered'`. Cycle de vie termine. La commande passe dans l'historique. |
| **[OUT-1]** | Confirmation de livraison affichee |
| **[ERR-1]** | Transition invalide : HTTP 409 `{error: {code: "INVALID_TRANSITION"}}` |

---

## 6. Domaine 5 - Annulation

### 6.1 ANNULER_COMMANDE

**Correspond a MCT section 7.1**

| Tag | Contenu |
|-----|---------|
| **[PRE-1]** | L'acteur est authentifie, permission `commande.cancel` |
| **[PRE-2]** | La commande ciblee existe |
| **[PRE-3]** | `commande.statut` est dans `['pending_payment', 'paid', 'preparing', 'ready']`. Seuls les statuts finaux `delivered` et `cancelled` ne permettent pas la transition vers `cancelled` : une commande reste annulable tant qu'elle n'a pas ete remise (modification, annulation ou remboursement a la demande du client). |
| **[RG-1]** | `UPDATE commande SET statut = 'cancelled', updated_at = NOW() WHERE id = :id AND statut IN ('pending_payment', 'paid', 'preparing', 'ready')` |
| **[RG-2]** | La commande n'est pas supprimee physiquement : elle reste en base pour l'historique et les stats (les commandes annulees sont exclues du CA mais comptees dans les volumes). |
| **[RG-3]** | Les lignes `ligne_commande` ne sont pas supprimees (ON DELETE CASCADE n'est pas declenche) : elles permettent de savoir ce qui avait ete commande. |
| **[POST-1]** | `commande.statut = 'cancelled'`, etat terminal |
| **[OUT-1]** | Confirmation d'annulation |
| **[ERR-1]** | Tentative d'annulation d'une commande deja remise ou annulee (`delivered`, `cancelled`) : HTTP 422 `{error: {code: "CANNOT_CANCEL_IN_STATE"}}` |
| **[ERR-2]** | Transition invalide (concurrence) : HTTP 409 `{error: {code: "INVALID_TRANSITION"}}` |

---

## 7. Domaine 6 - Gestion du catalogue (admin)

### 7.1 CREER_PRODUIT

**Correspond a MCT section 8.1**

| Tag | Contenu |
|-----|---------|
| **[PRE-1]** | L'acteur est authentifie, permission `produit.create` |
| **[PRE-2]** | Le `categorie_id` fourni correspond a une `categorie` existante et active |
| **[RG-1]** | Validation du formulaire : `libelle` non vide, `prix_ttc_cents > 0`, `categorie_id` valide |
| **[RG-2]** | Si une image est uploadee : validation du type MIME (JPEG, PNG, WEBP uniquement), taille max configurable (suggestion : 2 Mo), stockage dans le volume `wakdo_uploads`, enregistrement du chemin relatif dans `image_path` |
| **[RG-3]** | `est_disponible = 1` par defaut a l'insertion |
| **[RG-4]** | `ordre` est affecte a la valeur MAX(ordre) + 1 pour la categorie ciblee, ou 0 si premiere insertion |
| **[POST-1]** | Un enregistrement `produit` existe en base avec tous les champs valides |
| **[OUT-1]** | Redirection vers la liste des produits de la categorie, message de succes |
| **[ERR-1]** | Validation echouee : affichage des erreurs de champ inline |
| **[ERR-2]** | Image invalide (type ou taille) : message d'erreur specifique |

---

### 7.2 MODIFIER_PRODUIT

**Correspond a MCT section 8.2**

| Tag | Contenu |
|-----|---------|
| **[PRE-1]** | L'acteur est authentifie, permission `produit.update` |
| **[PRE-2]** | Le `produit.id` cible existe en base |
| **[RG-1]** | Memes validations que CREER_PRODUIT sur les champs modifies |
| **[RG-2]** | Si une nouvelle image est uploadee, l'ancienne image est supprimee du filesystem (nettoyage du volume) |
| **[RG-3]** | Les `libelle_snapshot` et `prix_unitaire_ttc_cents_snapshot` dans les `ligne_commande` historiques ne sont pas modifies par ce traitement (integrite des commandes passees) |
| **[POST-1]** | `produit` mis a jour, `updated_at` rafraichi |
| **[OUT-1]** | Redirection vers la liste, message de succes |

---

### 7.3 SUPPRIMER_PRODUIT

**Correspond a MCT section 8.3**

| Tag | Contenu |
|-----|---------|
| **[PRE-1]** | L'acteur est authentifie, permission `produit.delete` |
| **[PRE-2]** | Le `produit.id` cible existe en base |
| **[RG-1]** | Verification prealable (PHP) : le produit est-il reference dans `menu_produit` ? Si oui, afficher un message "Ce produit est utilise dans X menu(s) : [liste]. Retirez-le d'abord des menus." et bloquer. |
| **[RG-2]** | La FK `menu_produit.produit_id` est definie avec `ON DELETE RESTRICT` en base : meme si la verification applicative est contournee, la base bloque la suppression. |
| **[RG-3]** | Si le produit est reference dans des `ligne_commande` historiques (FK `ON DELETE RESTRICT`), la suppression est egalement bloquee. Gestion recommandee : desactiver le produit (`est_disponible = 0`) plutot que le supprimer. |
| **[POST-1]** | Si aucune contrainte : le produit est supprime de la base |
| **[OUT-1]** | Redirection vers la liste, message de succes |
| **[ERR-1]** | Produit utilise dans un menu : HTTP 422 ou affichage inline avec liste des menus bloquants |
| **[ERR-2]** | Produit dans des commandes historiques : message "Ce produit a deja ete commande. Desactivez-le plutot que de le supprimer." |

---

### 7.4 CREER_MENU

**Correspond a MCT section 8.4**

| Tag | Contenu |
|-----|---------|
| **[PRE-1]** | L'acteur est authentifie, permission `menu.create` |
| **[PRE-2]** | Au moins un produit de role `burger` est inclus dans la composition |
| **[PRE-3]** | Tous les `produit_id` de la composition existent et sont `est_disponible = 1` |
| **[RG-1]** | Validation : `libelle` non vide, `prix_ttc_cents > 0`, composition valide (au moins burger) |
| **[RG-2]** | Transaction : INSERT `menu`, puis INSERT N lignes `menu_produit` avec `menu_id`, `produit_id`, `role`, `position` |
| **[RG-3]** | Les roles valides pour `menu_produit.role` sont : `burger`, `accompagnement`, `boisson`, `sauce`, `dessert` (ENUM en base) |
| **[POST-1]** | Un enregistrement `menu` et ses lignes `menu_produit` existent en base |
| **[OUT-1]** | Redirection vers la liste des menus, message de succes |
| **[ERR-1]** | Composition invalide (pas de burger) : message d'erreur metier |
| **[ERR-2]** | Produit de la composition indisponible : avertissement (le menu peut etre cree avec ce produit, mais sera potentiellement affiche comme "incomplet" sur la borne) |

---

### 7.5 MODIFIER_MENU

**Correspond a MCT section 8.5**

| Tag | Contenu |
|-----|---------|
| **[PRE-1]** | L'acteur est authentifie, permission `menu.update` |
| **[PRE-2]** | Le `menu.id` cible existe |
| **[RG-1]** | Memes validations que CREER_MENU sur les champs modifies |
| **[RG-2]** | Si la composition est modifiee : `DELETE FROM menu_produit WHERE menu_id = :id`, puis INSERT des nouvelles lignes (pattern delete-and-reinsert, atomique en transaction) |
| **[RG-3]** | Les snapshots dans `ligne_commande` ne sont pas affectes |
| **[POST-1]** | `menu` mis a jour, composition `menu_produit` reconstruite |
| **[OUT-1]** | Redirection, message de succes |

---

### 7.6 SUPPRIMER_MENU

**Correspond a MCT section 8.6**

| Tag | Contenu |
|-----|---------|
| **[PRE-1]** | L'acteur est authentifie, permission `menu.delete` |
| **[PRE-2]** | Le `menu.id` cible existe |
| **[RG-1]** | Verification prealable : le menu est-il reference dans des `ligne_commande` historiques ? FK `ON DELETE RESTRICT`. Si oui, proposer la desactivation (`est_disponible = 0`) plutot que la suppression. |
| **[RG-2]** | Si aucune `ligne_commande` ne le reference : DELETE du menu (cascade automatique sur `menu_produit` via `ON DELETE CASCADE`) |
| **[POST-1]** | Menu et ses lignes `menu_produit` supprimes |
| **[OUT-1]** | Redirection, message de succes |
| **[ERR-1]** | Menu present dans des commandes historiques : message "Ce menu a deja ete commande. Desactivez-le plutot que de le supprimer." |

---

### 7.7 GERER_CATEGORIE

**Correspond a MCT section 8.7**

| Tag | Contenu |
|-----|---------|
| **[PRE-1]** | L'acteur est authentifie, permission `categorie.manage` |
| **[RG-CREATE]** | `libelle` et `slug` non vides et uniques en base. `ordre` affecte a MAX + 1. |
| **[RG-UPDATE]** | Mises a jour de `libelle`, `slug`, `image_path`, `ordre`, `est_actif` |
| **[RG-DEACTIVATE]** | La desactivation d'une categorie (`est_actif = 0`) ne desactive pas automatiquement les produits/menus enfants en base (pas de CASCADE sur `est_actif`). La logique PHP doit proposer a l'admin de desactiver aussi les produits/menus enfants, ou la borne filtre `categorie.est_actif = 1` ce qui masque de facto les produits de la categorie. |
| **[RG-DELETE]** | Suppression physique bloquee si des `produit` ou `menu` ont `categorie_id = categorie.id` (FK `ON DELETE RESTRICT`). Proposer la desactivation. |
| **[POST-CREATE]** | Nouveau enregistrement `categorie` en base |
| **[POST-UPDATE]** | `categorie` mis a jour, `updated_at` rafraichi |
| **[OUT-1]** | Confirmation, retour a la liste des categories |

---

## 8. Domaine 7 - Gestion des utilisateurs et roles (admin)

### 8.1 CREER_USER

**Correspond a MCT section 9.1**

| Tag | Contenu |
|-----|---------|
| **[PRE-1]** | L'acteur est authentifie, permission `user.create` |
| **[PRE-2]** | L'email fourni n'existe pas dans `user.email` (contrainte UNIQUE) |
| **[PRE-3]** | Le `role_id` fourni correspond a un `role` existant et actif |
| **[RG-1]** | Validation : `email` conforme RFC 5321 (validation PHP `FILTER_VALIDATE_EMAIL`), `nom` et `prenom` non vides, `role_id` valide |
| **[RG-2]** | Hash du mot de passe : `password_hash($password, PASSWORD_ARGON2ID)`. Longueur min du mot de passe : 8 caracteres. |
| **[RG-3]** | `est_actif = 1` par defaut |
| **[RG-4]** | `last_login_at = NULL` a la creation |
| **[POST-1]** | Enregistrement `user` en base avec `password_hash` argon2id, `role_id` valide |
| **[OUT-1]** | Redirection vers la liste des utilisateurs, message de succes |
| **[ERR-1]** | Email deja existant : message "Cet email est deja utilise" |
| **[ERR-2]** | Mot de passe trop court : message de validation inline |

---

### 8.2 MODIFIER_USER

**Correspond a MCT section 9.2**

| Tag | Contenu |
|-----|---------|
| **[PRE-1]** | L'acteur est authentifie, permission `user.update` |
| **[PRE-2]** | Le `user.id` cible existe |
| **[RG-1]** | Si un nouveau mot de passe est fourni (champ non vide) : rehachage via `PASSWORD_ARGON2ID` et remplacement du hash existant |
| **[RG-2]** | Si le mot de passe n'est pas modifie (champ vide) : le hash existant est conserve sans modification |
| **[RG-3]** | L'email peut etre modifie sous contrainte UNIQUE (verification avant UPDATE) |
| **[POST-1]** | `user` mis a jour, `updated_at` rafraichi |
| **[OUT-1]** | Redirection, message de succes |

---

### 8.3 DESACTIVER_USER

**Correspond a MCT section 9.3**

| Tag | Contenu |
|-----|---------|
| **[PRE-1]** | L'acteur est authentifie, permission `user.update` |
| **[PRE-2]** | L'acteur ne cible pas son propre compte (protection : `$targetUserId !== $currentUserId`) |
| **[RG-1]** | `UPDATE user SET est_actif = 0, updated_at = NOW() WHERE id = :id` |
| **[RG-2]** | La session eventuellemement active de cet utilisateur sera invalidee au prochain acces : le middleware verifie `user.est_actif = 1` a chaque requete authentifiee |
| **[POST-1]** | `user.est_actif = 0`. L'utilisateur ne peut plus se connecter. Son historique reste intact. |
| **[OUT-1]** | Redirection, message de succes |
| **[ERR-1]** | Tentative d'auto-desactivation : HTTP 403 `{error: {code: "SELF_DEACTIVATION_FORBIDDEN"}}` |

---

### 8.4 GERER_MATRICE_RBAC

**Correspond a MCT section 9.4**

| Tag | Contenu |
|-----|---------|
| **[PRE-1]** | L'acteur est authentifie, permission `role.manage` |
| **[PRE-2]** | Le `role.id` cible existe |
| **[PRE-3]** | Les `permission_id` soumis existent tous en base |
| **[RG-1]** | Transaction : `DELETE FROM role_permission WHERE role_id = :id`, puis INSERT des nouvelles lignes `(role_id, permission_id)` pour chaque permission selectionnee |
| **[RG-2]** | Les permissions ne sont pas modifiables via cette operation : elles sont uniquement lues pour construire le formulaire de selection |
| **[RG-3]** | La modification prend effet immediatement pour les nouvelles requetes ; les sessions actives des users portant ce role verront la modification au prochain acces (la session stocke le `role_id` mais les permissions sont rechargees depuis la base a chaque verification) |
| **[POST-1]** | La table `role_permission` reflete exactement les permissions selectionnees pour ce role |
| **[OUT-1]** | Redirection, message de succes |

---

## 9. Domaine 8 - Authentification back-office

### 9.1 AUTHENTIFIER_USER

**Correspond a MCT section 10.1**

| Tag | Contenu |
|-----|---------|
| **[PRE-1]** | Le formulaire de connexion a ete soumis avec un email et un mot de passe |
| **[PRE-2]** | Le token CSRF du formulaire est valide (protection anti-CSRF) |
| **[RG-1]** | Lookup : `SELECT * FROM user WHERE email = :email AND est_actif = 1 LIMIT 1` |
| **[RG-2]** | Verification du mot de passe : `password_verify($password, $user->password_hash)`. Si echec : meme message d'erreur generic que si l'email n'existe pas (protection contre l'enumeration d'emails). |
| **[RG-3]** | Si succes : `session_regenerate(true)` (regeneration de l'ID de session, protection contre la fixation de session) |
| **[RG-4]** | Stockage en session : `$_SESSION['user_id']`, `$_SESSION['role_id']`, `$_SESSION['logged_in_at']` |
| **[RG-5]** | Mise a jour : `UPDATE user SET last_login_at = NOW() WHERE id = :id` |
| **[RG-6]** | Timeouts de session : idle timeout 4h (detection via timestamp de derniere activite en session), absolute timeout 10h (detection via `logged_in_at`) |
| **[POST-1]** | Session PHP ouverte avec `user_id` et `role_id`. `user.last_login_at` mis a jour. |
| **[OUT-1]** | Redirection vers la vue par defaut du role (preparation -> file d'attente, accueil -> commandes pretes, admin -> dashboard) |
| **[ERR-1]** | Identifiants incorrects ou compte inactif : message generic "Email ou mot de passe incorrect" (pas de distinction pour eviter l'enumeration) |
| **[ERR-2]** | Token CSRF invalide : HTTP 403 |

---

### 9.2 DECONNECTER_USER

**Correspond a MCT section 10.2**

| Tag | Contenu |
|-----|---------|
| **[PRE-1]** | Une session valide est ouverte (`session_id()` non vide, `$_SESSION['user_id']` present) |
| **[RG-1]** | `$_SESSION = []` (vider les donnees de session) |
| **[RG-2]** | Si le cookie de session existe, l'expirer : `setcookie(session_name(), '', time() - 3600, '/', '', true, true)` |
| **[RG-3]** | `session_destroy()` |
| **[POST-1]** | Session PHP detruite. Aucun acces authentifie possible avec l'ancien cookie. |
| **[OUT-1]** | Redirection vers la page de connexion |

---

## 10. Traitements automatises - Crons (hors interactions utilisateur)

Ces traitements sont executes par le service `wakdo-cron` (container Alpine + PHP CLI) dans
la fenetre de maintenance 01h30-09h30 (hors service actif). Ils sont hors scope MCT
(traitements techniques, pas de declencheur utilisateur) mais sont documentes ici pour
coherence avec PROJECT_CONTEXT section 7 (Bloc 5 DevOps).

### 10.1 Agregation des stats (cron 04h30)

| Tag | Contenu |
|-----|---------|
| **[TRIGGER]** | Cron : `30 4 * * *` |
| **[RG-1]** | Calcul du `service_day` ecoule : `J-1` si execution a 04h30 (dans la fenetre 01h-10h du jour J, le `service_day` a agregger est J-1) |
| **[RG-2]** | `service_day` pour une commande : `CASE WHEN HOUR(created_at) < 10 THEN DATE(created_at - INTERVAL 1 DAY) ELSE DATE(created_at) END` |
| **[RG-3]** | Agregations calculees par `service_day` : nombre de commandes, CA TTC (somme `total_ttc_cents` des commandes `statut != 'cancelled'`), top produits (par `libelle_snapshot`, COUNT occurrences dans `ligne_commande`) |
| **[POST-1]** | Stats disponibles pour la vue dashboard admin (requetes directes sur `commande` filtrees par `service_day` ou table d'agregation si implementee) |

### 10.2 Purge des sessions expirees (cron toutes les 15 min)

| Tag | Contenu |
|-----|---------|
| **[TRIGGER]** | Cron : `*/15 * * * *` |
| **[RG-1]** | Si les sessions PHP sont stockees en fichiers (defaut) : `find /tmp/sessions -mmin +240 -delete` (suppression des fichiers de session vieux de plus de 4h) |
| **[RG-2]** | Si les sessions sont en base (option) : `DELETE FROM php_sessions WHERE updated_at < NOW() - INTERVAL 4 HOUR` |
| **[POST-1]** | Sessions expirees supprimees. Les utilisateurs inactifs depuis plus de 4h seront forces a se reconnecter. |

### 10.3 Backup BDD (cron 03h00)

| Tag | Contenu |
|-----|---------|
| **[TRIGGER]** | Cron : `0 3 * * *` |
| **[RG-1]** | `mysqldump` de la base `wakdo` vers un fichier date dans le volume backup |
| **[RG-2]** | Retention : conservation des 7 derniers dumps (suppression des plus anciens) |
| **[POST-1]** | Dump SQL disponible pour restauration |

---

## 11. Tableau recapitulatif des regles de gestion transverses

Ces regles s'appliquent a plusieurs operations et sont centralisees ici pour eviter la
repetition.

| Code RG | Libelle | Operations concernees |
|---------|---------|----------------------|
| **RG-T01** | Verification CSRF sur tous les formulaires POST/PUT/DELETE du back-office | AUTH, toutes ops admin |
| **RG-T02** | Verification session active + `est_actif = 1` sur chaque requete authentifiee | Toutes ops domaines 2-7 |
| **RG-T03** | Verification permission via `role_permission` avant execution de l'operation | Toutes ops domaines 2-7 |
| **RG-T04** | Tous les montants monetaires sont manipules en centimes (INT). Conversion EUR uniquement en sortie. | 2.3, 3.1, 7.1, 7.4 |
| **RG-T05** | Les snapshots (`libelle_snapshot`, `prix_unitaire_ttc_cents_snapshot`) ne sont pas modifies apres insertion dans `ligne_commande` (integrite historique des commandes). | 2.3, 7.2, 7.5 |
| **RG-T06** | Toutes les requetes SQL passent par PDO avec prepared statements. Aucune concatenation de donnees utilisateur dans une requete SQL. | Toutes operations |
| **RG-T07** | Les transitions de statut `commande` incluent `AND statut = <statut_attendu>` dans la clause WHERE pour proteger contre les mises a jour concurrentes | 4.2, 4.3, 5.2, 6.1 |
| **RG-T08** | Les operations de creation/modification de catalogue ou users se font en transaction atomique quand elles touchent plusieurs tables | 2.3, 7.4, 7.5, 8.4 |
| **RG-T09** | Contrainte croisee `(source, mode_consommation)` sur `commande` : si `source = 'drive'`, alors `mode_consommation = 'drive'` (verification a la creation). Materialisable en CHECK SQL : `CHECK (source != 'drive' OR mode_consommation = 'drive')`. | 2.3, 3.1 |
| **RG-T10** | Toute operation qui modifie `commande.statut` doit aussi inserer une ligne dans `commande_event` dans la meme transaction (event_type aligne sur la transition, from_statut, to_statut, user_id de l'acteur ou NULL si auto, payload JSON optionnel). Append-only : aucun UPDATE / DELETE applicatif. A encapsuler dans un repository pour eviter les oublis. | 2.3, 3.1, 4.2, 4.3, 5.2, 6.1 |

---

## 12. Coherence avec la machine a etats (recap MLT)

Synthese des transitions de statut `commande` couvertes par le MLT, avec les operations MLT
correspondantes et les protections associees.

| Transition | Operation MLT | Condition SQL | Protection concurrence | Event audit insere |
|------------|---------------|---------------|------------------------|--------------------|
| `-> pending_payment` (creation) | PASSER_COMMANDE (2.3), SAISIR_COMMANDE_MANUELLE (3.1) | INSERT avec statut `pending_payment` | Transaction atomique | `CREATED` |
| `pending_payment -> paid` (paiement) | PASSER_COMMANDE (2.3), SAISIR_COMMANDE_MANUELLE (3.1) | UPDATE dans la meme transaction | Transaction atomique | `PAID` |
| `paid -> preparing` | MARQUER_EN_PREPARATION (4.2) | `WHERE statut = 'paid'` | AND statut dans WHERE | `PREPARING_STARTED` |
| `preparing -> ready` | MARQUER_PRETE (4.3) | `WHERE statut = 'preparing'` | AND statut dans WHERE | `READY` |
| `ready -> delivered` | DECLARER_LIVREE (5.2) | `WHERE statut = 'ready'` | AND statut dans WHERE | `DELIVERED` |
| `pending_payment/paid/preparing/ready -> cancelled` | ANNULER_COMMANDE (6.1) | `WHERE statut IN ('pending_payment', 'paid', 'preparing', 'ready')` | AND statut dans WHERE | `CANCELLED` |

Statuts terminaux (aucune transition prevue depuis ce statut) : `delivered`, `cancelled`.

Note : la transition `pending_payment -> paid` est interne a l'operation de creation et non
observable par les autres acteurs. Le statut `pending_payment` ne sera visible dans aucune file
d'attente metier (preparation, accueil) : ces vues filtrent sur `paid`, `preparing`, `ready`.

---

## 13. Points d'incoherence signales et arbitrages attendus

Ces points ont ete identifies lors de la construction du MLT. Ils reprennent et completent
les points signales au MCT section 14.

### 13.1 Colonne `source` vs `mode_consommation` sur `commande` - RESOLU (2026-05-28)

**Decision actee** : ajout d'une colonne `source ENUM('kiosk','comptoir','drive')` sur `commande`, en plus de `mode_consommation`. Deux dimensions distinctes maintenues :

- `mode_consommation` (sur_place / a_emporter / drive) : visee fiscale, determine le taux de TVA (10% sur_place, 5,5% a_emporter en restauration rapide FR)
- `source` (kiosk / comptoir / drive) : visee operationnelle, trace le canal de saisie

**Contrainte croisee** : `source = drive` implique `mode_consommation = drive`. Pour `kiosk` et `comptoir`, les deux dimensions sont independantes. Verifiee dans la regle [RG-T09] ci-dessous (section 11).

Dictionnaire et MCD amendes (cf. dictionary 3.5 + notes 8/9, MCD 4.2).

### 13.2 Tracabilite acteur sur `commande` - RESOLU (2026-05-28)

**Decision actee** : pas de colonnes `created_by_user_id` / `prepared_by_user_id` etc. directes sur `commande`. A la place, **table d'audit dediee `commande_event`** (cf. dictionary 3.7, MCD 4.2.bis, dictionary note 10). Pattern event sourcing simplifie.

- Append-only : aucun UPDATE / DELETE applicatif sur `commande_event`
- Chaque operation qui modifie `commande.statut` insere une ligne avec event_type, from_statut, to_statut, user_id (NULL si auto), payload (JSON nullable)
- Tracabilite complete sans denormalisation

Pattern d'ecriture documente dans la regle [RG-T10] (section 11).

### 13.3 Statut `pending_payment` - RESOLU

Le statut `pending_payment` est maintenu dans la machine canonique. Il represente la phase
de composition de la commande avant paiement, conformement a la regle metier confirmee
(le client compose sa commande, PUIS il paie). La transition `pending_payment -> paid` est
atomique dans les operations de creation, ce statut est donc non observable par les files
d'attente metier. Il est reserve pour une evolution vers un paiement reel asynchrone sans
migration destructive de l'ENUM. Ce point est clos.

### 13.4 (Information) `service_day` non persiste en colonne

PROJECT_CONTEXT documente la logique `service_day` (section 2). Elle n'est pas
materialisee comme colonne dans le dictionnaire. Pour les requetes de stats frequentes,
une colonne calculee (colonne generee MariaDB, syntaxe `AS (expression) VIRTUAL/STORED`)
pourrait etre envisagee au DDL pour eviter de recalculer a chaque requete. Non bloquant pour MVP.
