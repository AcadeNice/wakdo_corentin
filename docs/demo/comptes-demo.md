# Comptes de démonstration RBAC (soutenance du jury)

Author: BYAN

## Pourquoi ces identifiants sont publics

La production Wakdo est un site de démonstration pour la soutenance du 5/10.
Les identifiants ci-dessous sont **volontairement publics**, au même titre que
le compte historique `admin@wakdo.local` (`db/seeds/0001_rbac_and_reference.sql`,
déjà documenté dans `docs/SESSION_RESUME.md`) : le jury doit pouvoir se
connecter lui-même, avec un compte par poste, et constater ce que le RBAC
autorise et refuse pour chaque rôle — sans qu'on le lui affirme sur un
diaporama. Ce ne sont pas des identifiants d'exploitation réelle : évitez de
reprendre ces mots de passe/PIN pour un compte qui protège de vraies données.

Les comptes non-admin sont créés par le seed rejouable
`db/seeds/0009_demo_accounts.sql` (idempotent : un compte déjà modifié par le
jury n'est pas écrasé, voir l'en-tête du fichier). Les hash sont argon2id,
générés hors seed avec les mêmes paramètres que `PasswordHasher`
(`memory_cost=65536`, `time_cost=4`, `threads=1`), dans un conteneur
`php:8.3-fpm-alpine3.20` jetable, en dehors de tout conteneur de production.

## Tableau des comptes

| Compte | E-mail | Mot de passe | PIN | Rôle | Peut faire | Ne peut pas faire |
|---|---|---|---|---|---|---|
| Administrateur (déjà existant, seed 0001) | `admin@wakdo.local` | `WakdoAdmin2026!` | *(pas de PIN défini par défaut)* | admin | Les 23 permissions du catalogue, y compris supprimer un produit/menu, gérer les utilisateurs et les rôles (RBAC), annuler une commande. | Aucun refus côté permission (rôle `admin` = croisement complet du catalogue, seed 0001) ; les gardes CSRF/PIN des actions sensibles s'appliquent comme pour tout rôle. |
| Responsable | `manager@wakdo.local` | `WakdoManager2026!` | `1010` | manager | Créer/lire/modifier produits et menus (pas les supprimer) ; gérer catégories, ingrédients et recettes ; lire, compter et réapprovisionner le stock ; lire la liste des comptes ; lire les statistiques. | Supprimer un produit ou un menu (`product.delete`/`menu.delete`, réservés à admin) ; créer, livrer ou **annuler une commande** (aucun `order.*` — décision D5, séparation des pouvoirs) ; créer/modifier/désactiver un utilisateur ; gérer les rôles (`role.manage`). |
| Équipier cuisine | `cuisine@wakdo.local` | `WakdoCuisine2026!` | `2020` | kitchen | Lire le catalogue (produits/menus) ; lire le stock et faire l'inventaire ; lire les commandes (`order.read`) — ce qui donne accès à l'écran cuisine `/kitchen/display` (file filtrée sur les 3 canaux, kitchen voit tout) et fait avancer une commande payée à « prête » ; ainsi qu'à `/admin/orders`, filtrée aux mêmes 3 sources (voir la note de vérification ci-dessous). | Créer une commande (`order.create` absent du seed 0001 pour ce rôle — c'est cette permission manquante, seule, qui bloque `/counter/orders`/`/drive/orders` : `AdminController::guard('order.create')` refuse avant même que `channelGuard()` s'exécute ; le canal fixe n'entre pas en jeu ici, `order_source` de `kitchen` est NULL comme `admin`/`manager`), la livrer (`order.deliver`) ou l'annuler (`order.cancel`) ; modifier le catalogue ou le stock ; consulter les statistiques ; gérer comptes/rôles. |
| Équipier comptoir (1er équipier) | `comptoir@wakdo.local` | `WakdoComptoir2026!` | `3030` | counter | Lire le catalogue ; lire le stock et faire l'inventaire ; créer, livrer et annuler une commande comptoir (`/counter/orders`) ; lire `/admin/orders`, filtrée aux sources `kiosk`+`counter` (`role_visible_source`) ; écran cuisine filtré aux mêmes sources. | Modifier le catalogue (produits/menus/ingrédients) ; réapprovisionner le stock (`stock.manage`) ; consulter les statistiques ; gérer comptes/rôles ; **accéder à `/drive/orders`** (canal fixe `counter`, `channelGuard()` refuse l'autre canal — RG-T12, corrigé). |
| Équipier comptoir (2e équipier) | `comptoir2@wakdo.local` | `WakdoComptoirB2026!` | `3131` | counter | Les mêmes droits que `comptoir@wakdo.local` (même rôle). Sert à démontrer que deux équipiers d'un même poste ont des identités et des PIN distincts : l'audit (`audit_log.actor_user_id`) distingue lequel des deux a réalisé une action sensible, même s'ils partagent le même poste physique et la même session. | Identique à `comptoir@wakdo.local`. |
| Équipier drive | `drive@wakdo.local` | `WakdoDrive2026!` | `4040` | drive | Les mêmes permissions que le comptoir (catalogue lecture seule, stock lecture+inventaire, commande créer/livrer/annuler) via `/drive/orders`, avec la source de commande auto-taguée `drive` ; `/admin/orders` et écran cuisine filtrés à la seule source `drive`. | Identique à `comptoir@wakdo.local` (modifier catalogue, réapprovisionner, statistiques, comptes/rôles) ; **accéder à `/counter/orders`** (canal fixe `drive`, symétrique du refus ci-dessus). |

Détail complet des 23 permissions et des scénarios de preuve (navigateur + API,
résultat attendu) : voir `docs/demo/matrice-rbac.md`.

## Pourquoi un second compte comptoir

Au comptoir, plusieurs équipiers se relaient sur le même poste physique
(session partagée). Le PIN d'action sensible (`PinVerifier::resolveActingUser`,
modèle « identifiant équipier + PIN ») existe précisément pour distinguer QUI,
parmi les équipiers d'un même rôle, a réalisé une action sensible — pas
seulement QUEL rôle l'a fait. Avec un seul compte comptoir, cette distinction
ne peut pas être démontrée : avec deux comptes du même rôle et des PIN
distincts, le jury peut annuler une commande en s'identifiant tour à tour sous
chaque PIN et voir l'audit distinguer les deux acteurs, alors que leur rôle et
leurs permissions sont rigoureusement identiques.

## Politique de mot de passe et de PIN appliquée

- Mot de passe : 8 caractères minimum à la création
  (`UserController::validate`, `mb_strlen($password) < 8`). Les mots de passe
  ci-dessus font entre 15 et 19 caractères.
- PIN : chiffres uniquement, entre `STAFF_PIN_MIN_LENGTH` (4, `.env.example`)
  et `STAFF_PIN_MAX_LENGTH` (12) caractères (`PinVerifier::meetsLengthPolicy`).
  Les PIN ci-dessus font 4 chiffres, tous distincts entre eux.

## Vérification (note de transparence)

Une hypothèse simple pourrait être « la cuisine ne voit que l'écran cuisine ».
Elle ne tient pas une fois vérifiée dans le code : le rôle `kitchen` détient
`order.read` (seed 0001), qui donne accès à la fois à `/kitchen/display`
(`KitchenController::display`) et à `/admin/orders` (`OrderAdminController::index`) ;
les deux liens apparaissent dans la navigation (`src/app/Views/admin/layout.php`,
bloc « Pilotage »).

**Mise à jour (relecture adverse post-#152)** : au moment où cette note a été
écrite, `/admin/orders` listait toutes les commandes tous canaux confondus
(`OrderQueryRepository::recent()`, non filtrée), alors que `/kitchen/display`
filtrait déjà par `role_visible_source` — le filtrage RG-T12 ne s'appliquait
donc qu'à l'écran cuisine, pas à la liste admin. **Corrigé depuis** :
`/admin/orders` appelle désormais `OrderQueryRepository::recentVisible()`, qui
filtre par `role_visible_source` EN SQL (avant le `LIMIT`, pour qu'un rôle à
canal restreint ne se retrouve pas avec une liste vide si ses commandes sont
plus anciennes que les 50 plus récentes tous canaux confondus). Le filtrage par
canal (RG-T12) s'applique donc désormais aux deux écrans, uniformément.

De la même façon, la page de saisie comptoir/drive était accessible par le
CHEMIN visité (`/counter/orders` ou `/drive/orders`) sans vérifier que le rôle
connecté avait le droit d'être sur cette page précise : un compte `drive`
pouvait ouvrir `/counter/orders` et y créer une commande taguée `counter`, et
réciproquement. **Corrigé** : `CounterOrderController::channelGuard()` refuse
désormais la page de l'autre canal (`403`) pour un rôle à canal fixe, et borne
même un rôle sans canal fixe à ses sources visibles — voir
`docs/demo/matrice-rbac.md`, section 3, pour le détail et les scénarios de
preuve (C6/D6).
