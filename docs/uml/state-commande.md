# Diagramme d'etats-transitions - Commande

**Phase UML** : P1 - Conception, complement UML (apres MCD)
**Statut** : v0.6 - realigne sur le code livre, machine a 6 valeurs
**Historique** : v0.6 (2026-09-29) - numeros de ligne de `OrderRepository.php` recales sur le code du 29/09 apres-midi (`fe8b738`), bornes de commande ajoutees a la garde de T1. v0.4 (2026-09-24) - mise en coherence avec le code livre (2a09597) : references `OrderRepository.php:ligne` recalees sur le code courant (le fichier a evolue depuis le 2026-07-31) ; libelles du diagramme ecrits avec `<br/>`, que Mermaid 11 affiche en retour a la ligne dans un `stateDiagram-v2` (il y affichait `\n` tel quel). v0.5 (2026-09-28) - audit final sur pieces : references de ligne re-recalees (le fichier a encore evolue) ; T5 (annulation) ouverte au manager (ADR-0020) ; l'arc `paid --> preparing` (aucun code ne l'ecrit) retire du diagramme et remplace par une note ; T6 precise sur `GREATEST(created_at, updated_at)` et le delai borne [1, 1440] min ; section 6 mise a jour (dictionnaire/MLD/MCD alignes depuis le 2026-09-22, plus d'ecart a signaler). v0.6 (2026-09-29) - contre-audit independant (base MariaDB jetable) : references de ligne de `cancel()` et `expireStalePending()` corrigees d'un decalage de 1 ligne (`:864-952` et `:975-1057`, le fichier a legerement evolue depuis le dernier recalage).
**Date** : 2026-07-31
**Auteur methodologie** : BYAN

---

## 1. Objet du document

Ce document formalise la **machine a etats** de l'attribut `customer_order.status`.
Il decrit les etats possibles d'une commande, les transitions autorisees, les
**evenements** qui les declenchent et les **gardes** qui les conditionnent.

Il complete le MCD (`docs/merise/mcd.md`), le dictionnaire (`docs/merise/dictionary.md`
3.10) et le MLT (`docs/merise/mlt.md` sections 13 et 14).

> **Realignement du 2026-07-31.** La version v0.2 de ce document decrivait une machine
> a 4 etats et affirmait que `preparing` et `ready` avaient ete supprimes, ainsi que
> `pending_payment` non observable hors transaction. Les trois affirmations sont
> devenues fausses : la migration `0009_order_prep_states.sql` a rendu `preparing` et
> `ready` reels, et la creation de commande committe dans sa propre transaction depuis
> que creation et encaissement sont deux appels HTTP distincts. Chaque transition
> ci-dessous est ancree dans le code qui l'execute.
>
> Portee du realignement : ce document et `mlt.md` sections 13/14, complete depuis
> par le dictionnaire, le MLD et le MCD (alignes sur la machine a 6 valeurs depuis le
> 2026-09-22 — voir section 6, la dette de coherence documentaire est soldee).

---

## 2. Source de verite

La source de verite est le **code**, croise avec l'enumeration reellement en base :

```sql
status enum('pending_payment','paid','preparing','ready','delivered','cancelled')
       NOT NULL DEFAULT 'pending_payment'
```

Les transitions vivent toutes dans `src/app/Order/OrderRepository.php`, un seul
proprietaire pour la machine a etats.

---

## 3. Etats

| Etat | Valeur ENUM | Signification | Ecrit par |
|---|---|---|---|
| En attente de paiement | `pending_payment` | Commande composee, totaux figes, lignes persistees, rien de debite. Etat initial. **Observable** : la creation committe dans sa propre transaction. | `persist()` (`OrderRepository.php:350-394`) |
| Payee | `paid` | **Etat historique.** Aucun chemin de code ne l'ecrit plus depuis que le paiement met directement en preparation. Il subsiste dans l'enumeration et dans les gardes pour les commandes creees AVANT ce changement (11 lignes en base de demonstration au 2026-07-31). | plus aucun code |
| En preparation | `preparing` | Encaissee et en cuisine. C'est ici que le stock est debite. `paid_at` ET `preparing_at` sont poses ensemble ; `paid_at` reste l'horloge de reference du SLA et des indicateurs de vente. | `pay()` (`:639-726`) |
| Prete | `ready` | Preparation terminee, en attente de remise. | `markReady()` (`:790-829`) |
| Remise | `delivered` | Remise au client. Etat **final**. | `deliver()` (`:737-779`) |
| Annulee | `cancelled` | Annulee par un equipier ou un manager, ou expiree par le planificateur. Etat **final**. | `cancel()` (`:864-952`), `expireStalePending()` (`:975-1057`) |

---

## 4. Diagramme d'etats-transitions

```mermaid
stateDiagram-v2
    [*] --> pending_payment : creer la commande<br/>(T1)

    pending_payment --> pending_payment : modifier le panier (M1)<br/>[lignes remplacees,<br/>totaux recalcules<br/>AUCUN effet de stock,<br/>statut inchange]
    pending_payment --> preparing : payer (T2)<br/>[paid_at + preparing_at<br/>poses<br/>stock debite dans<br/>la meme transaction]
    pending_payment --> cancelled : annuler (T5)<br/>[aucun re-credit :<br/>rien n a ete debite]
    pending_payment --> cancelled : expirer (T6)<br/>[planificateur<br/>02h00, sans acteur]

    paid --> ready : marquer prete<br/>(T3)
    paid --> delivered : remettre (T4)
    paid --> cancelled : annuler (T5)

    preparing --> ready : marquer prete<br/>(T3)
    preparing --> delivered : remettre (T4)
    preparing --> cancelled : annuler (T5)<br/>[re-credit du<br/>stock debite]

    ready --> delivered : remettre (T4)
    ready --> cancelled : annuler (T5)<br/>[re-credit du<br/>stock debite]

    delivered --> [*]
    cancelled --> [*]

    note right of paid
        Etat historique : AUCUN chemin de
        code n'ecrit plus la transition
        paid -> preparing. L'arc n'est donc
        pas trace (aucune ligne ne l'emprunte
        aujourd'hui) ; seules les sorties
        depuis paid (T3/T4/T5) restent
        codees pour les commandes anterieures
        au 2026-07-31.
    end note
```

---

## 5. Transitions detaillees

| # | De | Vers | Evenement | Garde | Acteur | Code |
|---|---|---|---|---|---|---|
| T1 | (initial) | `pending_payment` | Creation de la commande composee | Au moins une ligne resolue ; produits disponibles (RG-T21) ; quantite entiere de 1 a 20 par ligne (`INVALID_QUANTITY`), 50 lignes au plus (`TOO_MANY_ITEMS`), 50 articles au plus (`ORDER_TOO_LARGE`), option de menu commandable pour le format servi (`OPTION_UNAVAILABLE`) — bornes du 29/09 ; prix refiges serveur | Client (borne) / Equipier (comptoir, drive, admin) | `persist()` `:350-394` |
| T2 | `pending_payment` | `preparing` | Encaissement | `WHERE status = 'pending_payment'` ; 0 ligne affectee et etat deja encaisse -> sortie idempotente, sinon transition invalide | Client / Equipier | `pay()` `:639-726` |
| T3 | `paid`, `preparing` | `ready` | Preparation terminee | `WHERE status IN ('paid','preparing')` ; permission `order.read` (donc aussi manager/admin, pas seulement la cuisine) | Cuisine / Comptoir / Drive / Manager / Admin | `markReady()` `:790-829` |
| T4 | `paid`, `preparing`, `ready` | `delivered` | Remise physique | `WHERE status IN ('paid','preparing','ready')` ; permission `order.deliver` ; source compatible avec le role (`role_visible_source`, PRE-3) | Comptoir / Drive / Admin (le manager n'a pas `order.deliver`) | `deliver()` `:737-779` |
| T5 | `pending_payment`, `paid`, `preparing`, `ready` | `cancelled` | Annulation | `WHERE status IN (...)` ; permission `order.cancel` + PIN equipier ; re-credit du stock **conditionne a l'existence de mouvements `sale`**, pas au statut lu | Comptoir / Drive / Manager (ADR-0020, migration `0018`) / Admin | `cancel()` `:864-952` |
| T6 | `pending_payment` | `cancelled` | **Expiration automatique** | `GREATEST(created_at, updated_at) < NOW() - INTERVAL :m MINUTE`, avec `:m = ORDER_PENDING_EXPIRY_MINUTES` (defaut 60) borne a [1, 1440] min ; `WHERE status = 'pending_payment'` ; aucun mouvement `sale` ; **aucun effet de stock** | Systeme (planificateur 02h00) | `expireStalePending()` `:975-1057` |

### Boucle sur place (pas une transition)

| # | Etat | Evenement | Garde | Effet | Code |
|---|---|---|---|---|---|
| M1 | `pending_payment` -> `pending_payment` | **Modification du panier avant paiement** (F18) | Verrou de ligne pris, statut relu `= 'pending_payment'` ; panier non vide ; articles non en rupture (RG-T21) | Lignes remplacees en bloc (enfants en CASCADE), totaux recalcules serveur. Statut, numero et cle d'idempotence INCHANGES. **Aucun effet de stock.** | `replaceItems()` |

M1 n'est **pas** une transition d'etat : le statut ne change pas, et l'evenement est
repetable autant de fois que le client modifie son panier. Il figure ici parce qu'il
change le CONTENU et le MONTANT d'une commande deja persistee — donc ce qui sera
facture — et parce qu'il partage un verrou avec T2. Detail :
[ADR-0016](../adr/0016-modification-commande-avant-paiement.md), `mlt.md` 3.3bis.

### Invariants

- `delivered` et `cancelled` sont **finaux** : aucune transition n'en sort.
- Aucun retour en arriere. Une erreur operationnelle se traite par annulation puis
  nouvelle commande, pour preserver les instantanes de prix (`label_snapshot`,
  `unit_price_cents_snapshot`, `vat_rate_snapshot` sur `order_item`).
- **Le stock ne bouge qu'a T2 et T5.** T2 debite (`stock_movement` type `sale`) ; T5
  re-credite (type `cancellation`) **si et seulement si** des mouvements `sale`
  existent pour cette commande. T6 n'ecrit rien sur le stock : une commande en attente
  n'a rien consomme, la re-crediter creerait du stock a partir de rien.
- La decision de re-credit de T5 repose sur l'**existence de mouvements `sale`**, pas
  sur le statut lu hors transaction : insensible a la course
  `pending_payment -> preparing -> cancel`.
- Chaque transition est gardee **dans le WHERE de son UPDATE** : 0 ligne affectee vaut
  course perdue. **Une exception, nommee** : T2 et M1 prennent en plus un verrou de ligne
  explicite (`SELECT ... FOR UPDATE`) au debut de leur transaction. Raison mesuree : sur
  un remplacement, l'UPDATE des totaux peut affecter 0 ligne alors que tout va bien (si
  le nouveau panier coute le meme prix), ce qui rend le compte de lignes affectees
  inutilisable comme garde. Detail et mesures : [ADR-0016](../adr/0016-modification-commande-avant-paiement.md).
- Horodatages : `paid_at` et `preparing_at` a T2, `ready_at` a T3, `delivered_at` a T4,
  `cancelled_at` a T5 et T6. NULL tant que la transition n'a pas eu lieu.
- T6 ecrit une trace `audit_log` avec `action_code = 'order.expire'` et un acteur
  **NULL** : personne n'a annule, la machine a nettoye.
- Une commande expiree **conserve** sa cle d'idempotence et ses lignes : on ferme, on ne
  detruit pas. Un essai tardif avec la meme cle retombe donc sur une commande annulee et
  l'encaissement echoue en conflit ; le client repart d'une commande neuve.

---

## 6. Coherence avec les autres livrables

| Verification | Resultat |
|---|---|
| Tous les etats du diagramme existent dans l'enumeration en base | Oui, 6 valeurs (mesure le 2026-07-31) |
| Tous les etats sont atteignables par du code | Non : `paid` ne l'est plus, conserve pour les commandes anterieures. Dit explicitement en section 3. |
| Annulation possible sauf depuis un etat final | Respectee (T5, T6 ; rien depuis `delivered` ni `cancelled`) |
| Le stock ne bouge qu'a l'encaissement et a l'annulation d'une commande encaissee | Verifie par test (unitaire + integration sur base reelle) |
| `mlt.md` section 13.6 (expiration) | Presente, alignee sur T6 |
| Dictionnaire / MLD / MCD | Alignes depuis le 2026-09-22 : les trois decrivent la meme machine a 6 valeurs (dictionnaire Note 6, `mld.md` §"Machine a 6 etats", `mcd.md` §"Machine a 6 etats"). Plus d'ecart a signaler. |

---

## 7. Arbitrages tranches

**Pourquoi `pending_payment` est observable.** La creation et l'encaissement sont deux
appels HTTP distincts : `persist()` committe une commande complete, puis `pay()` ouvre sa
propre transaction. L'etat intermediaire existe donc vraiment, et un abandon entre les
deux (reseau coupe, borne redemarree, onglet ferme) laisse une commande inerte. C'est
exactement ce que T6 nettoie.

**Pourquoi l'expiration passe par `cancelled` et non par un statut `expired`.** La valeur
existe deja, elle est deja terminale, deja exclue du chiffre d'affaires et de la file
cuisine, deja libellee « Annulee » a l'ecran. Un 7e statut obligerait a reprendre chaque
endroit qui enumere les statuts, pour un gain purement statistique -- alors que le journal
d'audit porte deja la distinction (`order.expire` contre `order.cancel`). Detail et
alternatives ecartees : `docs/adr/0014-expiration-commandes-pending.md`.

**Pourquoi le paiement met directement en preparation.** Retour d'oral du 2026-06-29 : en
fast-food, une commande encaissee part en cuisine sans geste intermediaire. Le bouton
manuel « Commencer » a donc ete retire et `pay()` pose `preparing`. La cuisine ne clique
plus que « Prete ». Consequence sur ce document : `paid` cesse d'etre ecrit.
