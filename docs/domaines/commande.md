# Domaine — Commande

## Perimetre
Cycle de vie complet d'une commande, du panier borne (kiosk) a la remise au client, plus
la saisie directe comptoir/drive, la file cuisine, l'annulation et l'expiration
automatique des commandes restees en attente de paiement.

## Ce qui est livre

### Borne (kiosk), API publique anonyme
- `POST /api/orders` (`OrderController::create`) : creation en `pending_payment`,
  idempotente sur `idempotency_key` (RG-T19). Un envoi qui reutilise une cle deja connue
  se comporte selon l'etat de la commande qu'elle porte : `replaceItems` si elle est
  encore `pending_payment` (panier remplace, totaux et en-tete de service recalcules —
  voir [ADR-0016](../adr/0016-modification-commande-avant-paiement.md)), renvoi a
  l'identique si elle est deja encaissee, `409 ORDER_CANCELLED` si elle est `cancelled`.
- `POST /api/orders/{number}/pay` (`OrderController::pay`) : encaissement,
  `pending_payment -> preparing` (le passage intermediaire par `paid` est instantane
  dans la meme transaction), decrement de stock atomique (RG-T20).
- `GET /api/orders/{number}` (`OrderController::show`) : suivi public du statut par
  numero, lecture seule, anonyme.

### Cuisine
- `GET /kitchen/display` (`KitchenController::display`, `order.read`) : file des
  commandes payees, landing du role `kitchen`.
- `POST /admin/orders/{number}/ready` (`OrderAdminController::ready`, `order.read` —
  pas `order.deliver` : marquer une commande prete est un geste de cuisine, pas de
  remise) : `preparing -> ready`.

### Remise au client
- `POST /admin/orders/{number}/deliver` (`OrderAdminController::deliver`,
  `order.deliver`) : `ready -> delivered`, geste unique.

### Comptoir et drive
- `GET|POST /counter/orders[/new]` et `GET|POST /drive/orders[/new]`
  (`CounterOrderController`, `order.create`) : UN controleur, deux canaux ; la source
  (`counter`/`drive`) est deduite du chemin visite, apres un `channelGuard()` qui verifie
  que le role visitant a bien ce canal (fixe ou visible). Encaissement direct, sans PIN
  (`order.create` suffit) : pas d'etape `pending_payment` intermediaire cote comptoir.

### Annulation
- `GET /admin/orders/{number}/cancel` (confirmation) et
  `POST /admin/orders/{number}/cancel` (`OrderAdminController::cancel`, `order.cancel`) :
  `pending_payment|paid -> cancelled`, PIN equipier + `audit_log` + remise en stock
  conditionnelle (si la commande etait encaissee) dans la meme transaction
  (RG-T13/RG-T14). Le refus « canal non visible » et le refus « numero inconnu » rendent
  tous deux `403`, verifie AVANT le PIN, pour ne pas reveler par le code HTTP qu'une
  commande d'un autre canal existe.

### API JSON d'administration (`/admin/api/orders`, [ADR-0017](../adr/0017-api-admin-json.md))
Un seul endpoint de creation (contrairement au HTML qui a une page par canal) :
- `GET /admin/api/orders` / `GET /admin/api/orders/{number}` (`order.read`)
- `POST /admin/api/orders` (`order.create`) : source deduite du role si canal fixe,
  choisie dans le corps sinon (et alors verifiee contre les sources visibles du role)
- `POST /admin/api/orders/{number}/ready` (`order.read`, comme son equivalent HTML)
- `POST /admin/api/orders/{number}/deliver` (`order.deliver`)
- `POST /admin/api/orders/{number}/cancel` (`order.cancel`, PIN)

### Expiration automatique
Tache planifiee (`src/bin/order-expire.php`, cron 02h00,
[ADR-0014](../adr/0014-expiration-commandes-pending.md)) : bascule en `cancelled` les
commandes `pending_payment` dont `GREATEST(created_at, updated_at)` depasse
`ORDER_PENDING_EXPIRY_MINUTES` (defaut 60 ; le predicat porte sur la derniere activite,
pas seulement la creation, depuis [ADR-0016](../adr/0016-modification-commande-avant-paiement.md)).
Ecrit dans `OrderRepository`, pas en SQL brut, pour rester l'unique proprietaire de la
machine a etats ; trace `audit_log` avec acteur NULL (distingue une expiration
automatique d'une annulation humaine).

## Regles metier
- **6 statuts** (`customer_order.status`) : `pending_payment`, `paid`, `preparing`,
  `ready`, `delivered`, `cancelled`. Detail des transitions :
  [docs/uml/state-commande.md](../uml/state-commande.md) ;
  [docs/uml/sequence-passer-commande.md](../uml/sequence-passer-commande.md) pour le
  parcours borne complet.
- RG-T19 (idempotence) et RG-T20 (decrement de stock atomique a l'encaissement, pas de
  verrou prealable sur `ingredient`) : voir `docs/merise/mlt.md` section 2.
- RG-T09 : `source = 'drive'` implique `service_mode = 'drive'`, verifiee a la creation
  et rejouee a la modification (`resolveHeader`, partage entre creation et
  `replaceItems`).
- RG-T12 : filtre par canal du tableau de bord des commandes, base sur les sources
  visibles du role (`role_visible_source`).
- RG-T13/T14 : l'annulation est l'unique action sensible du domaine commande (PIN +
  audit) ; la permission est verifiee sur la SESSION avant le PIN, qui ne fait
  qu'identifier l'acteur ([ADR-0004](../adr/0004-pin-action-sensible-audit.md)).
- Le responsable (`manager`) a `order.read` et `order.cancel` depuis
  [ADR-0020](../adr/0020-responsable-annule-commande.md) (remplace la decision D5 sur ce
  point) : il voit et peut annuler les commandes des trois canaux, sans filtre, et a
  acces (via `order.read`) a l'ecran cuisine et au geste « marquer prete ». Le comptoir
  et le drive gardent leur propre annulation.

## Decisions
[ADR-0014](../adr/0014-expiration-commandes-pending.md) (expiration cron 02h00),
[ADR-0016](../adr/0016-modification-commande-avant-paiement.md) (modification avant
paiement + verrou de ligne), [ADR-0017](../adr/0017-api-admin-json.md) (API JSON,
section commande), [ADR-0020](../adr/0020-responsable-annule-commande.md) (responsable
peut annuler), [ADR-0006](../adr/0006-http-409-conflit-422-validation.md) (409/422).

## Tables
`customer_order`, `order_item`, `order_item_modifier`, `order_item_selection`,
`stock_movement` (decrement/re-credit), `audit_log` (annulation). Detail :
`docs/merise/mlt.md` sections 3 a 7.
