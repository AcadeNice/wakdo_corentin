# Modele Conceptuel des Traitements (MCT) - Wakdo

**Phase Merise** : P1 - Conception, etape 3 (apres MCD)
**Statut** : v0.1
**Date** : 2026-05-21
**Branche** : `feat/p1-conception`
**Auteur methodologie** : BYAN

---

## 1. Objet du document

Le MCT (Modele Conceptuel des Traitements) decrit les **operations metier** du domaine Wakdo
sous la forme canonique Merise : **evenement declencheur -> operation -> resultat emis**.

Il repond a la question : que se passe-t-il dans le domaine, et quand ?
Il ne repond pas a la question : qui fait quoi, sur quel poste, dans quel ordre organisationnel
(cette dimension est volontairement omise - le MOT est saute, raccourci agile assume, coheret
avec le cadre RNCP solo).

Le MCT couvre :
- Le parcours commande de bout en bout (borne kiosk, comptoir, drive)
- La gestion du catalogue (manager/admin)
- La gestion des utilisateurs et roles (admin)
- La connexion au back-office (tous acteurs back)

**Acteurs identifies** :

| Acteur | Code | Interface |
|--------|------|-----------|
| Client (borne) | CLIENT | Kiosk tactile (public, non authentifie) |
| Accueil | ACCUEIL | Back-office, role `accueil` |
| Preparation (cuisine) | CUISINE | Back-office, role `preparation` |
| Manager / Administrateur | ADMIN | Back-office, role `admin` |
| Systeme | SYS | Logique interne API / PHP |

**Cross-reference MCD** : chaque operation manipule des entites du MCD (section 9). Le MCT est
construit en coherence avec la machine a etats de `commande.statut` :

```
pending_payment -> paid -> preparing -> ready -> delivered
      |             |           |          |
      +-------------+-----------+----------+-> cancelled (depuis tout etat non remis)
```

---

## 2. Conventions de representation

### Format d'une operation

```
[EVENEMENT(S) DECLENCHEUR(S)]
        |
        | [REGLE DE SYNCHRONISATION / CONDITION]
        v
   ( OPERATION )
        |
        v
[RESULTAT(S) EMIS]
```

**Synchronisations** :
- `ET` : tous les evenements doivent etre presents simultanement pour declencher l'operation
- `OU` : l'un quelconque des evenements suffit

**Conditions** : exprimees entre crochets `[condition]` sur l'arc entrant.

### Notation textuelle adoptee

Pour chaque operation, le document presente :
- **Evenement(s) declencheur(s)** : ce qui arrive et provoque l'operation
- **Acteur(s)** : qui est a l'origine (OU qui valide)
- **Synchronisation** : `ET` / `OU` si plusieurs evenements, condition
- **Operation** : nom de l'operation, description de ce qu'elle fait
- **Entites MCD touchees** : lecture (R) ou ecriture (W) sur les entites du MCD
- **Resultat(s)** : ce qui est emis ou produit a l'issue de l'operation

---

## 3. Domaine 1 - Parcours commande (borne kiosk)

Ce domaine couvre le cycle de vie d'une commande initiee depuis la borne client.

### 3.1 Charger le catalogue

| Champ | Valeur |
|-------|--------|
| **Evenement declencheur** | Le client ouvre la borne (connexion au kiosk) |
| **Acteur** | CLIENT |
| **Synchronisation** | Aucune (evenement unique) |
| **Condition** | La borne est en service (dans la plage horaire 10h00-01h00) |
| **Operation** | CHARGER_CATALOGUE |
| **Description** | Recuperation de la liste des categories actives, des produits disponibles et des menus disponibles pour affichage sur la borne |
| **Entites MCD** | R : `categorie` (est_actif=1), `produit` (est_disponible=1), `menu` (est_disponible=1), `menu_produit` |
| **Resultat** | Catalogue charge, borne affiche la page d'accueil |

---

### 3.2 Composer le panier

| Champ | Valeur |
|-------|--------|
| **Evenement declencheur** | Le client selectionne un produit ou un menu sur la borne |
| **Acteur** | CLIENT |
| **Synchronisation** | Evenement repetable (OU : ajout produit, ajout menu, modification quantite, suppression item) |
| **Condition** | Le produit ou menu selectionne est disponible (`est_disponible=1`) |
| **Operation** | COMPOSER_PANIER |
| **Description** | Construction du panier en memoire : ajout d'un article (produit unitaire ou menu), avec eventuellement une option de taille (+0,50 EUR sur accompagnements et boissons), recalcul du total TTC. Le panier est une structure volatile cote client ; aucune ecriture en BDD a ce stade. |
| **Entites MCD** | R : `produit`, `menu`, `menu_produit` - W : aucune (etat volatile front) |
| **Resultat** | Panier mis a jour, total recalcule, affichage recapitulatif |

---

### 3.3 Valider et passer la commande

| Champ | Valeur |
|-------|--------|
| **Evenements declencheurs** | 1. Client confirme le panier (appui sur "Valider") ET 2. Client saisit son numero de commande |
| **Acteur** | CLIENT |
| **Synchronisation** | ET (les deux actions sont requises) |
| **Condition** | Le panier contient au moins 1 article. Le numero saisi est non vide. |
| **Operation** | PASSER_COMMANDE |
| **Description** | Creation de la commande en base : insertion d'une ligne `commande` avec statut `pending_payment`, snapshot du total HT/TVA/TTC au taux en vigueur, source `kiosk`. Creation des lignes `ligne_commande` avec snapshot des libelles et prix. Le systeme genere le numero de commande au format `K-YYYY-MM-DD-NNN`. Le client saisit ensuite son numero de commande (substitut de paiement dans le cadre RNCP) : la commande passe au statut `paid`. La transition `pending_payment -> paid` est atomique dans cette operation. |
| **Entites MCD** | R : `produit`, `menu` (snapshot prix/libelle) - W : `commande` (INSERT statut `pending_payment`, puis UPDATE statut `paid`), `ligne_commande` (INSERT N lignes), `commande_event` (INSERT 2 events : `CREATED` user_id=NULL puis `PAID` user_id=NULL — kiosk = auto-validation, pas d'equipier) |
| **Resultat** | Commande creee (statut `paid` en fin d'operation), numero affiche au client, evenement COMMANDE_CREEE emis vers le domaine preparation |

---

### 3.4 Confirmer la commande au client

| Champ | Valeur |
|-------|--------|
| **Evenement declencheur** | COMMANDE_CREEE (retour API 201 apres PASSER_COMMANDE) |
| **Acteur** | SYS |
| **Synchronisation** | Aucune |
| **Condition** | La reponse API contient un id, un numero et un statut `paid` (la transition `pending_payment -> paid` s'est executee dans PASSER_COMMANDE) |
| **Operation** | AFFICHER_CONFIRMATION |
| **Description** | Affichage de l'ecran de confirmation sur la borne avec le numero de commande. La borne se reinitialise ensuite pour le client suivant. |
| **Entites MCD** | R : aucune nouvelle lecture BDD (les donnees sont dans la reponse API) |
| **Resultat** | Ecran de confirmation affiche, borne disponible pour la commande suivante |

---

## 4. Domaine 2 - Parcours commande (comptoir et drive)

Ce domaine couvre la saisie manuelle d'une commande par un equipier accueil pour un client
au comptoir ou au drive.

### 4.1 Saisir une commande manuelle

| Champ | Valeur |
|-------|--------|
| **Evenement declencheur** | L'equipier accueil initie une nouvelle commande depuis le back-office |
| **Acteur** | ACCUEIL |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur est authentifie et possede la permission `commande.create`. La source est `comptoir` ou `drive`. |
| **Operation** | SAISIR_COMMANDE_MANUELLE |
| **Description** | Composition du panier via le back-office : selection de produits et menus, choix du mode de consommation, choix de la source (`comptoir` ou `drive`). Logique identique a PASSER_COMMANDE cote kiosk, a la difference que l'acteur est un equipier authentifie. La transition `pending_payment -> paid` est atomique dans cette operation (l'equipier valide le paiement du client). |
| **Entites MCD** | R : `produit`, `menu`, `menu_produit` - W : `commande` (INSERT statut `pending_payment`, puis UPDATE statut `paid`, source `comptoir` ou `drive`), `ligne_commande` (INSERT), `commande_event` (INSERT 2 events : `CREATED` user_id=acteur puis `PAID` user_id=acteur) |
| **Resultat** | Commande creee (statut `paid` en fin d'operation), numero imprime ou annonce au client |

---

## 5. Domaine 3 - Preparation (cuisine)

### 5.1 Consulter les commandes a preparer

| Champ | Valeur |
|-------|--------|
| **Evenement declencheur** | L'equipier cuisine accede a sa vue ou rafraichit la liste |
| **Acteur** | CUISINE |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur est authentifie et possede la permission `commande.read`. |
| **Operation** | LISTER_COMMANDES_A_PREPARER |
| **Description** | Lecture des commandes de statut `paid` triees par `created_at` croissant (heure de passage croissante, tous canaux confondus). Affichage du numero, du contenu (lignes avec libelle snapshot), et de la source (kiosk/comptoir/drive). |
| **Entites MCD** | R : `commande` (statut=`paid`), `ligne_commande` |
| **Resultat** | Liste des commandes en attente de preparation affichee, triee par heure croissante |

---

### 5.2 Marquer une commande en preparation

| Champ | Valeur |
|-------|--------|
| **Evenement declencheur** | L'equipier cuisine clique sur "Prendre en charge" pour une commande |
| **Acteur** | CUISINE |
| **Synchronisation** | Aucune |
| **Condition** | La commande est au statut `paid`. L'acteur possede la permission `commande.update`. |
| **Operation** | MARQUER_EN_PREPARATION |
| **Description** | Transition de statut `paid` -> `preparing` sur la commande. Mise a jour de `updated_at`. La commande disparait de la file "a preparer" et passe dans la file "en preparation". |
| **Entites MCD** | W : `commande` (UPDATE statut `paid` -> `preparing`), `commande_event` (INSERT event `PREPARING_STARTED` user_id=acteur) |
| **Resultat** | Commande au statut `preparing`, evenement COMMANDE_EN_PREPARATION emis |

---

### 5.3 Marquer une commande prete

| Champ | Valeur |
|-------|--------|
| **Evenement declencheur** | L'equipier cuisine clique sur "Pret" pour une commande en preparation |
| **Acteur** | CUISINE |
| **Synchronisation** | Aucune |
| **Condition** | La commande est au statut `preparing`. L'acteur possede la permission `commande.update`. |
| **Operation** | MARQUER_PRETE |
| **Description** | Transition de statut `preparing` -> `ready`. Mise a jour de `updated_at`. La commande est desormais visible pour l'accueil qui peut la remettre au client. |
| **Entites MCD** | W : `commande` (UPDATE statut `preparing` -> `ready`), `commande_event` (INSERT event `READY` user_id=acteur) |
| **Resultat** | Commande au statut `ready`, evenement COMMANDE_PRETE emis vers l'accueil |

---

## 6. Domaine 4 - Remise au client (accueil)

### 6.1 Consulter les commandes pretes

| Champ | Valeur |
|-------|--------|
| **Evenement declencheur** | L'equipier accueil accede a sa vue ou rafraichit la liste |
| **Acteur** | ACCUEIL |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur est authentifie et possede la permission `commande.read`. |
| **Operation** | LISTER_COMMANDES_PRETES |
| **Description** | Lecture des commandes de statut `ready`. Affichage du numero de commande, contenu, source. |
| **Entites MCD** | R : `commande` (statut=`ready`), `ligne_commande` |
| **Resultat** | Liste des commandes pretes affichee |

---

### 6.2 Declarer une commande livree

| Champ | Valeur |
|-------|--------|
| **Evenements declencheurs** | 1. La commande est au statut `ready` ET 2. L'equipier accueil clique sur "Livree" |
| **Acteur** | ACCUEIL |
| **Synchronisation** | ET |
| **Condition** | La commande est au statut `ready`. L'acteur possede la permission `commande.update`. |
| **Operation** | DECLARER_LIVREE |
| **Description** | Transition de statut `ready` -> `delivered`. Fin du cycle de vie de la commande. La commande passe en historique. |
| **Entites MCD** | W : `commande` (UPDATE statut `ready` -> `delivered`), `commande_event` (INSERT event `DELIVERED` user_id=acteur) |
| **Resultat** | Commande au statut `delivered`, cycle de vie termine |

---

## 7. Domaine 5 - Annulation

### 7.1 Annuler une commande

| Champ | Valeur |
|-------|--------|
| **Evenement declencheur** | Un acteur autorise demande l'annulation d'une commande |
| **Acteur** | ACCUEIL ou ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | La commande est dans un statut annulable : `pending_payment`, `paid`, `preparing` ou `ready`. Seuls les statuts finaux `delivered` et `cancelled` ne peuvent pas transitionner vers `cancelled` : une commande reste annulable tant qu'elle n'a pas ete remise au client (modification, annulation ou remboursement). L'acteur possede la permission `commande.cancel`. |
| **Operation** | ANNULER_COMMANDE |
| **Description** | Transition du statut courant vers `cancelled`. Mise a jour de `updated_at`. La commande reste en base pour l'historique et les stats (pas de suppression physique). |
| **Entites MCD** | W : `commande` (UPDATE statut -> `cancelled`), `commande_event` (INSERT event `CANCELLED` user_id=acteur, `payload` peut contenir la raison) |
| **Resultat** | Commande au statut `cancelled`, visible dans l'historique admin |

---

## 8. Domaine 6 - Gestion du catalogue (admin)

### 8.1 Creer un produit

| Champ | Valeur |
|-------|--------|
| **Evenement declencheur** | L'admin soumet le formulaire de creation de produit |
| **Acteur** | ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur possede la permission `produit.create`. La categorie ciblee existe et est active. Le libelle est non vide. Le prix est strictement positif. |
| **Operation** | CREER_PRODUIT |
| **Description** | Insertion d'un nouveau produit en base avec sa categorie, son libelle, son prix en centimes, son image (upload optionnel). `est_disponible` a `1` par defaut. |
| **Entites MCD** | R : `categorie` (validation FK) - W : `produit` (INSERT) |
| **Resultat** | Produit cree, retour a la liste des produits |

---

### 8.2 Modifier un produit

| Champ | Valeur |
|-------|--------|
| **Evenement declencheur** | L'admin soumet le formulaire de modification d'un produit existant |
| **Acteur** | ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur possede la permission `produit.update`. Le produit existe. Les nouvelles valeurs respectent les contraintes (prix > 0, libelle non vide). |
| **Operation** | MODIFIER_PRODUIT |
| **Description** | Mise a jour des colonnes modifiables (`libelle`, `description`, `prix_ttc_cents`, `image_path`, `est_disponible`, `ordre`, `categorie_id`). Les snapshots deja stockes dans `ligne_commande` ne sont pas affectes (integrite historique garantie par le design). |
| **Entites MCD** | W : `produit` (UPDATE) |
| **Resultat** | Produit mis a jour, liste produits rafraichie |

---

### 8.3 Supprimer un produit

| Champ | Valeur |
|-------|--------|
| **Evenement declencheur** | L'admin confirme la suppression d'un produit |
| **Acteur** | ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur possede la permission `produit.delete`. Le produit n'est pas compose dans un menu actif (FK `menu_produit.produit_id` avec ON DELETE RESTRICT). Verification prealable requise. |
| **Operation** | SUPPRIMER_PRODUIT |
| **Description** | Suppression physique du produit si aucune contrainte FK ne bloque. Si le produit est reference dans un menu, la suppression est bloquee (RESTRICT en base). La consequence metier est que l'admin doit d'abord retirer le produit de tous les menus qui le contiennent. |
| **Entites MCD** | W : `produit` (DELETE - bloque si reference dans `menu_produit`) |
| **Resultat** | Produit supprime OU erreur "produit utilise dans un menu" |

---

### 8.4 Creer un menu

| Champ | Valeur |
|-------|--------|
| **Evenement declencheur** | L'admin soumet le formulaire de creation de menu avec sa composition |
| **Acteur** | ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur possede la permission `menu.create`. Le libelle est non vide. Le prix est strictement positif. Au moins un produit de role `burger` est associe (contrainte metier : un menu sans burger n'a pas de sens). |
| **Operation** | CREER_MENU |
| **Description** | Insertion du menu (`menu`) puis insertion des lignes de composition (`menu_produit`) : pour chaque produit selectionne, un enregistrement avec son role (burger, accompagnement, boisson, sauce) et sa position. |
| **Entites MCD** | R : `produit` (validation des composants), `categorie` - W : `menu` (INSERT), `menu_produit` (INSERT N lignes) |
| **Resultat** | Menu cree avec sa composition, visible sur la borne |

---

### 8.5 Modifier un menu

| Champ | Valeur |
|-------|--------|
| **Evenement declencheur** | L'admin soumet le formulaire de modification d'un menu existant |
| **Acteur** | ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur possede la permission `menu.update`. Le menu existe. La composition modifiee conserve au moins un produit de role `burger`. |
| **Operation** | MODIFIER_MENU |
| **Description** | Mise a jour des colonnes du menu. Si la composition est modifiee : suppression de toutes les lignes `menu_produit` pour ce menu puis reinsertion (pattern delete-and-reinsert, plus simple que le diff ligne a ligne). Les snapshots deja commandes ne sont pas affectes. |
| **Entites MCD** | W : `menu` (UPDATE), `menu_produit` (DELETE + INSERT) |
| **Resultat** | Menu mis a jour |

---

### 8.6 Supprimer un menu

| Champ | Valeur |
|-------|--------|
| **Evenement declencheur** | L'admin confirme la suppression d'un menu |
| **Acteur** | ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur possede la permission `menu.delete`. La suppression d'un menu ne bloque pas les `ligne_commande` historiques (FK avec ON DELETE RESTRICT sur `ligne_commande.menu_id`). Verification prealable requise. |
| **Operation** | SUPPRIMER_MENU |
| **Description** | Suppression en cascade des lignes `menu_produit` (ON DELETE CASCADE), puis suppression du menu si aucune `ligne_commande` historique ne le reference. |
| **Entites MCD** | W : `menu_produit` (DELETE CASCADE), `menu` (DELETE - bloque si reference dans `ligne_commande`) |
| **Resultat** | Menu supprime OU erreur "menu present dans des commandes historiques" |

---

### 8.7 Gerer les categories

| Champ | Valeur |
|-------|--------|
| **Evenement declencheur** | L'admin cree, modifie ou desactive une categorie |
| **Acteur** | ADMIN |
| **Synchronisation** | OU (create, update, desactivation) |
| **Condition** | L'acteur possede la permission `categorie.manage`. Pour une desactivation : les produits et menus de la categorie sont desactives en cascade applicative (pas de FK CASCADE ici, logique PHP). |
| **Operation** | GERER_CATEGORIE |
| **Description** | CRUD sur l'entite `categorie`. La desactivation d'une categorie (`est_actif=0`) masque ses produits de la borne sans suppression physique. La suppression physique est bloquee si des produits ou menus y sont rattaches (ON DELETE RESTRICT). |
| **Entites MCD** | W : `categorie` (INSERT / UPDATE / DELETE conditionnel) |
| **Resultat** | Categorie creee / mise a jour / desactivee |

---

## 9. Domaine 7 - Gestion des utilisateurs et roles (admin)

### 9.1 Creer un utilisateur

| Champ | Valeur |
|-------|--------|
| **Evenement declencheur** | L'admin soumet le formulaire de creation d'utilisateur |
| **Acteur** | ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur possede la permission `user.create`. L'email n'existe pas deja en base. Un role valide est selectionne. |
| **Operation** | CREER_USER |
| **Description** | Insertion de l'utilisateur avec hash du mot de passe (argon2id). L'email est unique. Le `role_id` est obligatoire (FK NOT NULL). |
| **Entites MCD** | R : `role` (validation FK) - W : `user` (INSERT) |
| **Resultat** | Utilisateur cree, peut se connecter au back-office |

---

### 9.2 Modifier un utilisateur

| Champ | Valeur |
|-------|--------|
| **Evenement declencheur** | L'admin soumet le formulaire de modification |
| **Acteur** | ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur possede la permission `user.update`. L'utilisateur existe. Si le mot de passe est fourni, il est rehache. |
| **Operation** | MODIFIER_USER |
| **Description** | Mise a jour des champs modifiables (`nom`, `prenom`, `email`, `role_id`, `est_actif`). Si un nouveau mot de passe est saisi, il remplace le hash existant. |
| **Entites MCD** | W : `user` (UPDATE) |
| **Resultat** | Utilisateur mis a jour |

---

### 9.3 Desactiver un utilisateur

| Champ | Valeur |
|-------|--------|
| **Evenement declencheur** | L'admin clique sur "Desactiver" pour un utilisateur |
| **Acteur** | ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur possede la permission `user.update`. L'admin ne peut pas se desactiver lui-meme (protection applicative). |
| **Operation** | DESACTIVER_USER |
| **Description** | Mise a jour de `est_actif=0`. La session active de l'utilisateur est invalidee au prochain acces (verification `est_actif` dans le middleware d'authentification). L'utilisateur n'est pas supprime, son historique reste tracable. |
| **Entites MCD** | W : `user` (UPDATE est_actif=0) |
| **Resultat** | Utilisateur desactive, acces back-office bloque |

---

### 9.4 Gerer la matrice role-permissions

| Champ | Valeur |
|-------|--------|
| **Evenement declencheur** | L'admin modifie l'assignation des permissions pour un role |
| **Acteur** | ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'acteur possede la permission `role.manage`. Les permissions selectionnees existent en base. |
| **Operation** | GERER_MATRICE_RBAC |
| **Description** | Mise a jour de la table `role_permission` pour un role donne : suppression des anciennes assignations et insertion des nouvelles (pattern delete-and-reinsert). Les permissions elles-memes sont statiques (declarees en migration, non modifiables via UI). |
| **Entites MCD** | R : `role`, `permission` - W : `role_permission` (DELETE + INSERT) |
| **Resultat** | Matrice RBAC mise a jour, prise en effet au prochain acces des utilisateurs portant ce role |

---

## 10. Domaine 8 - Authentification back-office

### 10.1 Se connecter au back-office

| Champ | Valeur |
|-------|--------|
| **Evenement declencheur** | Un acteur soumet le formulaire de connexion |
| **Acteur** | ACCUEIL / CUISINE / ADMIN |
| **Synchronisation** | Aucune |
| **Condition** | L'email existe en base. Le mot de passe correspond au hash argon2id. L'utilisateur est actif (`est_actif=1`). |
| **Operation** | AUTHENTIFIER_USER |
| **Description** | Verification des identifiants. Si valides : regeneration de l'identifiant de session (protection contre la fixation de session), stockage du `user_id` et du `role_id` en session, mise a jour de `last_login_at`. Idle timeout : 4h. Absolute timeout : 10h. |
| **Entites MCD** | R : `user` (verification), `role` (chargement permissions) - W : `user` (UPDATE last_login_at) |
| **Resultat** | Session ouverte, redirection vers la vue correspondant au role |

---

### 10.2 Se deconnecter du back-office

| Champ | Valeur |
|-------|--------|
| **Evenement declencheur** | L'acteur clique sur "Deconnexion" ou la session expire |
| **Acteur** | ACCUEIL / CUISINE / ADMIN / SYS (expiration) |
| **Synchronisation** | OU |
| **Condition** | Une session valide est ouverte |
| **Operation** | DECONNECTER_USER |
| **Description** | Destruction de la session PHP (`session_destroy()`). La session est supprimee cote serveur. Le cookie de session est invalide. |
| **Entites MCD** | Aucune ecriture en base (la gestion de session est en PHP natif, hors BDD pour MVP) |
| **Resultat** | Session detruite, redirection vers la page de connexion |

---

## 11. Machine a etats de commande.statut

Synthese des transitions couvertes par les operations du MCT.

```
                  [CLIENT / ACCUEIL]
                  PASSER_COMMANDE
                  SAISIR_COMMANDE_MANUELLE
                        |
                        v
              [ pending_payment ]  (commande composee, paiement en attente)
                        |
          [CLIENT / ACCUEIL] paiement confirme
          (atomique dans PASSER_COMMANDE / SAISIR_COMMANDE_MANUELLE)
                        |
                        v
                   [ paid ]
                        |
          [CUISINE] MARQUER_EN_PREPARATION
                        |
                        v
                  [ preparing ]
                        |
              [CUISINE] MARQUER_PRETE
                        |
                        v
                   [ ready ]
                        |
            [ACCUEIL] DECLARER_LIVREE
                        |
                        v
                  [ delivered ]  (terminal, non annulable)


  Depuis pending_payment / paid / preparing / ready :
  [ACCUEIL ou ADMIN] ANNULER_COMMANDE
                        |
                        v
                  [ cancelled ]  (terminal)
```

**Note sur la transition `pending_payment -> paid`** : dans le cadre RNCP, le paiement est
remplace par la saisie du numero de commande par le client (borne) ou par la validation de
l'equipier (comptoir/drive). La transition est atomique au sein des operations PASSER_COMMANDE
et SAISIR_COMMANDE_MANUELLE. Le statut `pending_payment` est visible en base le temps de la
transaction, et le statut final stocke est `paid`. Ce decoupage en deux etats reflete la
semantique metier (le client compose SA commande, PUIS il paie) et preserve la capacite
d'evolution vers un paiement reel sans migration destructive.

---

## 12. Tableau de synthese des operations

| # | Operation | Domaine | Acteur | Entites W | Entites R |
|---|-----------|---------|--------|-----------|-----------|
| 1 | CHARGER_CATALOGUE | Commande kiosk | CLIENT | - | categorie, produit, menu, menu_produit |
| 2 | COMPOSER_PANIER | Commande kiosk | CLIENT | - (volatile) | produit, menu, menu_produit |
| 3 | PASSER_COMMANDE | Commande kiosk | CLIENT | commande, ligne_commande, commande_event | produit, menu |
| 4 | AFFICHER_CONFIRMATION | Commande kiosk | SYS | - | - |
| 5 | SAISIR_COMMANDE_MANUELLE | Commande comptoir/drive | ACCUEIL | commande, ligne_commande, commande_event | produit, menu, menu_produit |
| 6 | LISTER_COMMANDES_A_PREPARER | Preparation | CUISINE | - | commande, ligne_commande |
| 7 | MARQUER_EN_PREPARATION | Preparation | CUISINE | commande, commande_event | - |
| 8 | MARQUER_PRETE | Preparation | CUISINE | commande, commande_event | - |
| 9 | LISTER_COMMANDES_PRETES | Remise client | ACCUEIL | - | commande, ligne_commande |
| 10 | DECLARER_LIVREE | Remise client | ACCUEIL | commande, commande_event | - |
| 11 | ANNULER_COMMANDE | Annulation | ACCUEIL / ADMIN | commande, commande_event | - |
| 12 | CREER_PRODUIT | Catalogue | ADMIN | produit | categorie |
| 13 | MODIFIER_PRODUIT | Catalogue | ADMIN | produit | - |
| 14 | SUPPRIMER_PRODUIT | Catalogue | ADMIN | produit | menu_produit |
| 15 | CREER_MENU | Catalogue | ADMIN | menu, menu_produit | produit, categorie |
| 16 | MODIFIER_MENU | Catalogue | ADMIN | menu, menu_produit | - |
| 17 | SUPPRIMER_MENU | Catalogue | ADMIN | menu_produit, menu | ligne_commande |
| 18 | GERER_CATEGORIE | Catalogue | ADMIN | categorie | produit, menu |
| 19 | CREER_USER | RBAC | ADMIN | user | role |
| 20 | MODIFIER_USER | RBAC | ADMIN | user | - |
| 21 | DESACTIVER_USER | RBAC | ADMIN | user | - |
| 22 | GERER_MATRICE_RBAC | RBAC | ADMIN | role_permission | role, permission |
| 23 | AUTHENTIFIER_USER | Auth | ALL BACK | user | user, role |
| 24 | DECONNECTER_USER | Auth | ALL BACK | - | - |

**Total : 24 operations** couvrant la totalite du cycle de vie metier Wakdo.

---

## 13. Cross-validation MCT -> MCD (mantra #34)

Verification que chaque entite du MCD participe a au moins une operation du MCT.

| Entite MCD | Operations qui la lisent | Operations qui l'ecrivent | Couverture |
|------------|--------------------------|--------------------------|------------|
| `categorie` | 1, 12, 15, 18 | 18 | OK |
| `produit` | 1, 2, 3, 5, 12, 14 | 12, 13, 14 | OK |
| `menu` | 1, 2, 3, 5, 15, 17 | 15, 16, 17 | OK |
| `menu_produit` | 1, 2, 5, 14 | 15, 16, 17 | OK |
| `commande` | 6, 9 | 3, 5, 7, 8, 10, 11 | OK |
| `ligne_commande` | 6, 9, 17 | 3, 5 | OK |
| `commande_event` | - (lecture via SELECT historique non listee comme operation) | 3, 5, 7, 8, 10, 11 | OK |
| `user` | 23 | 19, 20, 21, 23 | OK |
| `role` | 19, 22, 23 | 22 | OK |
| `permission` | 22 | - (statique, migration) | OK (*) |
| `role_permission` | - | 22 | OK |

(*) `permission` est en lecture seule via les operations MCT : ses valeurs sont declarees en
migration SQL et ne sont pas modifiables via UI (RBAC statique cote permissions, dynamique
cote roles). Cette decision est documentee dans le MCD section 4.3.

**Conclusion** : 11/11 entites couvertes. Coherence MCT <-> MCD validee.

---

## 14. Points d'incoherence detectes et signalement

Les points suivants necessite une attention ou une decision de l'auteur :

### 14.1 Divergence `commande.statut` entre dictionnaire et PROJECT_CONTEXT - RESOLUE

- **Machine canonique retenue** : `pending_payment -> paid -> preparing -> ready -> delivered` (transitions nominales) ; `cancelled` atteignable depuis tout etat non remis (`pending_payment`, `paid`, `preparing`, `ready`), pour couvrir modification, annulation et remboursement client.
- **Arbitrage** : la regle metier confirmee impose deux phases successives : le client compose sa commande (statut `pending_payment`), puis il paie (statut `paid`). PROJECT_CONTEXT utilisait un terme `pending` simplifie qui ne refletait pas cette distinction. La machine canonique du dictionnaire est la source de verite. La transition `pending_payment -> paid` est atomique dans les operations PASSER_COMMANDE et SAISIR_COMMANDE_MANUELLE dans le cadre RNCP (substitut de paiement = saisie du numero). Ce point est considere comme clos.

### 14.2 Absence d'acteur `user` lie a `commande` - RESOLUE (2026-05-28)

**Decision actee** : pas de colonne `user_id` directe sur `commande`, mais une table d'audit dediee `commande_event` (cf. dictionnaire 3.7, MCD 4.2.bis). Pattern event sourcing simplifie. Chaque operation qui modifie `commande.statut` insere une ligne dans `commande_event` avec l'utilisateur a l'origine de la transition (NULL si auto-validation kiosk). Tracabilite complete sans denormalisation lourde sur `commande`.

### 14.3 Colonne `source` absente de `commande` dans le dictionnaire - RESOLUE (2026-05-28)

**Decision actee** : ajout d'une colonne `source ENUM('kiosk','comptoir','drive')` sur `commande`, en plus de `mode_consommation`. Les deux dimensions sont **distinctes** :
- `source` = canal de saisie (kiosk / comptoir / drive) - input
- `mode_consommation` = mode de consommation fiscal (sur_place / a_emporter / drive) - output

Contrainte croisee : `source = drive` implique `mode_consommation = drive` (verifiee au MLT lors de la creation de commande). Pour `kiosk` et `comptoir`, les deux dimensions sont independantes. Documente dans le dictionnaire notes 8 et 9.

### 14.4 Stats et `service_day`

PROJECT_CONTEXT documente une logique `service_day` (section 2). Le MCT ne couvre pas
l'agregation des stats (cron 04h30). Ce traitement est volontairement hors scope MCT (c'est
un traitement technique automatise, pas un traitement metier interactif). Il sera documente
dans le MLT (section cron).
