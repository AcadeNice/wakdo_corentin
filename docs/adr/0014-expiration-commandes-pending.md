# ADR-0014 — Expiration des commandes restees en attente de paiement

- Statut : Accepte
- Date : 2026-07-31

## Contexte
Le flux de commande fait **deux appels HTTP** : creation (`POST /api/orders`) puis
encaissement (`POST /api/orders/{numero}/pay`). La creation committe dans sa propre
transaction, donc le statut `pending_payment` est reellement observable entre les deux.
Un echec du second appel — reseau coupe, borne redemarree, onglet ferme — laisse une
commande complete et inerte.

Ce qu'une telle commande consomme reellement, verifie dans le code : un identifiant et un
numero de commande (attribues des la creation, une purge ne les recyclerait pas), et sa cle
d'idempotence. Elle **ne bloque aucun stock** : le debit vit dans la seule transaction de
l'encaissement, et la disponibilite est calculee depuis le stock, sans notion de
reservation. Elle est exclue de la file cuisine et du chiffre d'affaires, mais elle reste
visible dans la liste admin et **comptee « en attente »** sur le tableau de bord.

Elle est donc inerte mais salissante. Mesure du jour de la decision : 0 commande en attente
sur 19 en base de demonstration. Le filet est **preventif**, il ne rattrape rien a la mise
en service — c'est a dire tel quel plutot que de laisser croire a un nettoyage massif.

Deux questions a trancher : quel etat donner a ces commandes, et ou faire vivre le
balayage.

## Decision

### (a) Passage a `cancelled` avec trace, plutot qu'une suppression ou un statut `expired`

La valeur `cancelled` existe deja dans l'enumeration, elle est deja terminale, deja exclue
du chiffre d'affaires et de la file cuisine, et deja libellee « Annulee » a l'ecran. Cout
de mise en oeuvre : aucune migration, aucun ecran a reprendre, aucune valeur technique
brute exposee a un equipier.

Un statut `expired` obligerait a reprendre chaque endroit qui enumere les statuts (gardes
SQL, filtres de liste, indicateurs, libelles) pour un gain purement statistique — alors que
le journal d'audit porte deja la distinction : `order.expire` avec un acteur NULL contre
`order.cancel` avec l'equipier resolu par PIN.

Une suppression a ete ecartee pour deux raisons. Elle detruirait la trace (les lignes
`order_item` partent en cascade). Et si par accident une commande portait un mouvement de
stock, la contrainte `ON DELETE SET NULL` sur `stock_movement.order_id` le detacherait
silencieusement de sa commande : perte de tracabilite inacceptable dans un registre en
ajout seul.

La retention de l'historique (`ORDER_RETENTION_DAYS`, aujourd'hui declare mais non cable)
reste un sujet distinct : fermer un etat incoherent et purger un historique ancien sont
deux besoins differents.

### (b) Le balayage est du code metier appele par le planificateur, pas du SQL dans un script bash

C'est une **transition de la machine a etats**, et les cinq autres vivent dans
`OrderRepository`. L'ecrire en SQL dans un script donnerait deux proprietaires a la meme
machine a etats. Il faut aussi ecrire une ligne d'audit avec un resume : de la logique
metier, pas une purge de retention comme les deux taches planifiees existantes, qui portent
sur des tables techniques sans etat metier ni trace a produire.

En prime, l'integration continue couvre le PHP (PHPStan niveau 6 et PHPUnit avec la suite
d'integration sur base reelle) et ne couvre pas le bash : aucun analyseur de script n'est
cable dans la chaine.

Consequence assumee : l'image du planificateur embarque desormais PHP en ligne de commande
et monte le code en lecture seule. Ce n'est pas une couche inventee — `PROJECT_CONTEXT.md`
decrivait DEJA ce conteneur comme « Alpine + PHP CLI » alors que le Dockerfile n'avait pas
PHP. Le lot realigne l'image sur l'architecture documentee.

## Consequences
- (+) Une seule maniere de faire transiter une commande : par `OrderRepository`.
- (+) Invariant de stock verifiable a la lecture : la methode ne contient aucune ecriture
  sur `ingredient` ni `stock_movement`, et deux tests le verrouillent (dont un contre la
  vraie base, qui compare les quantites et le nombre de mouvements avant/apres).
- (+) La distinction expiration / annulation humaine reste lisible dans le journal d'audit,
  sans couter un statut supplementaire.
- (+) Une transaction par commande : une ligne problematique ne fait pas perdre le balayage.
- (+) Le passage tombe a 02h00, apres la fermeture du service et avant la sauvegarde de
  03h00 : le dump de la nuit contient l'etat nettoye.
- (-) Un second moteur d'execution (PHP) dans l'image du planificateur : image plus lourde,
  et une version de PHP a suivre en plus de celle de l'application.
- (-) A l'ecran, un equipier voit « Annulee » sans distinguer la machine de l'humain. Choix
  assume : la distinction vit dans l'audit, que le jury peut lire.
- (-) **Etape sur la machine de production a ne pas oublier** : le vrai
  `docker-compose.prod.yml` est ignore par git et propre a chaque hote. La variable
  `ORDER_PENDING_EXPIRY_MINUTES` et le montage `./src:/var/www/html:ro` du service
  planificateur doivent y etre reportes a la main, et l'image reconstruite. Sans cela la
  tache echoue en silence sur la production.
- Coherent avec ADR-0004 (trace d'audit dans la meme transaction que l'effet) et ADR-0006
  (409 sur conflit d'etat). Fichiers : `src/app/Order/OrderRepository.php`,
  `src/bin/order-expire.php`, `docker/cron/Dockerfile`, `docker/cron/crontab`,
  `docker-compose.yml`, `docker-compose.prod.yml.example`. Cycle de vie complet :
  `docs/uml/state-commande.md`, regles : `docs/merise/mlt.md` 13.6.
