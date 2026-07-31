# ADR-0016 — Modifier une commande avant paiement, et le verrou qui va avec

- Statut : Accepte
- Date : 2026-07-31

## Contexte

Le flux borne fait **deux appels HTTP** : creation (`POST /api/orders`, statut
`pending_payment`) puis encaissement (`POST /api/orders/{numero}/pay`). Entre les deux,
le client peut repartir sur son panier, le modifier, et revenir payer.

Ce que le code faisait alors, precisement. La cle d'idempotence renvoyait la commande
existante **sans regarder les lignes envoyees** : l'ancien panier aurait donc ete
facture. La borne contournait ce piege en effacant la cle a chaque entree sur l'ecran de
paiement — ce qui creait une SECONDE commande et laissait la premiere en attente
jusqu'au balayage de 2h ([ADR-0014](0014-expiration-commandes-pending.md)).

Il faut etre juste sur le point de depart : **le client obtenait ce qu'il voulait**. Le
defaut n'etait pas fonctionnel, il etait economique et cosmetique — chaque hesitation
brulait un numero de commande, salissait le compteur « en attente » du tableau de bord,
et faisait reposer le nettoyage sur une tache de nuit. Ce lot supprime l'orpheline **a la
source** plutot que de la ramasser le lendemain.

Deux questions a trancher : ou vit la modification, et comment elle cohabite avec un
encaissement concurrent.

## Decision

### (a) La cle d'idempotence identifie une SESSION DE PAIEMENT, pas une tentative

`POST /api/orders` avec une cle deja connue se comporte desormais selon l'etat de la
commande qu'elle porte :

| Etat de la commande | Comportement |
|---|---|
| `pending_payment` | **`replaceItems`** : les lignes sont remplacees et les totaux recalcules. Meme commande, meme numero. |
| encaissee (`paid`/`preparing`/`ready`/`delivered`) | renvoi tel quel, aucune ecriture (vrai renvoi de tentative apres une reponse perdue) |
| `cancelled` | **`ORDER_CANCELLED`** (409) : la cle est definitivement consommee |

La garantie qui compte (RG-T19) est preservee et meme renforcee : **une seule commande
par session de paiement**, quoi que fasse le client. Ce qui change est le sens du mot
idempotent : ce n'est plus « meme cle, meme reponse quel que soit le corps », c'est
« meme cle, meme commande, dont le contenu reflete la derniere soumission ». Le
renoncement est assume et ecrit : un renvoi de tentative repose les memes lignes, donc
quelques ecritures de plus pour un resultat observable identique.

Le cas `cancelled` merite son code d'erreur. `idempotency_key` est **UNIQUE** : une cle
ne peut porter qu'une seule commande, a vie. Si la commande derriere la cle est annulee
par un equipier ou expiree par le planificateur pendant que le client hesite, il se
retrouverait **bloque** — commande impayable, cle interdisant d'en creer une autre. La
borne reprend alors **une seule fois**, avec une cle neuve. La reprise est limitee a ce
code precis : une reprise aveugle masquerait un vrai probleme, par exemple un article
devenu indisponible que le client doit voir pour corriger son panier.

### (b) Le verrou de ligne, etabli par l'experience et non par l'intuition

Le projet garde toutes ses transitions **dans le WHERE de leur UPDATE** (0 ligne
affectee = course perdue) et n'utilisait, avant ce lot, **aucun**
`SELECT ... FOR UPDATE`. Sortir de cette regle demande des preuves, pas une conviction.
La question a donc ete tranchee par deux experiences independantes menees sur la vraie
base (MariaDB 11.4.10, REPEATABLE-READ, autocommit=1 — la configuration de production
relevee ce jour-la), l'une sur des tables jetables neutres, l'autre en rejouant la
logique metier reelle avec des commandes jetables.

**Le fait de moteur, mesure deux fois et concordant.** L'instantane de lecture InnoDB est
cree **paresseusement**, a la premiere lecture NON verrouillante de la transaction. Ni
`BEGIN` ni un `UPDATE` ne le posent. Temoin decisif : la meme sequence avec un `SELECT`
simple place AVANT l'`UPDATE` lit des lignes **perimees**, alors que sans ce `SELECT`
elle lit des lignes **fraiches**. Le chemin « UPDATE bloque puis relache » a aussi ete
mesure (1,99 s d'attente reelle) : la lecture y reste fraiche.

**Ce que ce fait implique pour le code ACTUEL.** La transaction de `pay()` commencait par
son UPDATE de garde, donc le calcul de consommation ouvrait un instantane frais et voyait
les lignes remplacees. Sur cette base, le motif du projet paraissait suffire. **La
seconde experience a montre pourquoi cette suffisance ne vaut rien.**

**La piece decisive.** Meme ordonnancement, gardes actives, **une seule lecture simple
ajoutee** dans la transaction d'encaissement avant son UPDATE. Resultat mesure : le
calcul de consommation lit les ANCIENNES lignes, le stock est debite pour un produit que
le client n'a pas commande (frites decomptees, nuggets servis), et **les deux UPDATE
rendent 1 ligne affectee** — aucune garde ne signale quoi que ce soit. Les deux scenarios
ne different que par ce `SELECT` ajoute, et le resultat passe de coherent a incoherent.
La protection du motif n'est donc pas structurelle : **elle est accidentelle**, suspendue
a un detail que rien n'ecrit — le fait que `pay()` n'ait aucune lecture simple avant sa
garde. Un `SELECT` de journalisation, ou une revalidation de rupture, suffirait a casser
la coherence argent/stock sans qu'aucune relecture ne le voie.

**Et le signal du motif tombe de toute facon.** Mesure chiffree : sur une commande dont
les totaux ne bougent pas, le MEME `UPDATE` rend **1 ligne affectee** puis **0** selon
que `updated_at = NOW()` a franchi une seconde. Le compteur compte les lignes CHANGEES,
pas les lignes TROUVEES. « 0 ligne = course perdue » est donc non seulement ambigu sur un
remplacement, mais **dependant de l'heure**. C'est le signal meme du motif maison qui ne
tient pas ici.

**Detail a ne pas exploiter.** La serialisation qui existait deja entre les deux
operations n'etait pas voulue : c'est la cle etrangere `order_item.order_id` qui verrouille
la ligne parente `customer_order` lors de l'INSERT (verification de contrainte). Sonde a
l'appui, le DELETE d'un enfant ne la verrouille pas — il y a donc une **fenetre reelle
entre le DELETE et l'INSERT** d'un remplacement. S'appuyer sur cet effet de bord serait
bati sur du sable.

**Decision.** `lockOrder()` prend un verrou de ligne explicite, appele par les deux seules
operations concernees (M1 et T2), en **premiere instruction** de leur transaction. La
serialisation devient explicite : quand `lockOrder` rend la main, toute modification
concurrente a deja committe, donc tout instantane ouvert ensuite la contient.

**Ce qui reste a surveiller, et qui est teste.** Le verrou n'immunise que s'il est pris
AVANT toute lecture non verrouillante : une lecture simple placee avant lui figerait
l'instantane trop tot et ramenerait exactement le defaut mesure. Cet invariant est plus
naturel et plus lisible que « aucun SELECT avant la garde » — un lecteur qui voit
`lockOrder` en tete comprend qu'il doit y rester — mais il n'est pas auto-verifiant. Un
test de non-regression le verrouille donc explicitement : il echoue si une lecture du
contenu de la commande precede la prise de verrou.

Le prix paye est nomme : une exception au motif unique du projet, confinee a une methode
privee documentee. C'est ce que je defends a l'oral, mesures a l'appui.

### (c) L'encaissement relit son total sous le verrou

`pay()` lisait son total AVANT sa transaction et le renvoyait. Tant qu'une commande en
attente etait figee, c'etait sans consequence. Elle devient modifiable : ce total peut
desormais etre perime, et le facturer serait un ecart entre le montant annonce au client
et le contenu reel de sa commande. La lecture verrouillee fait autorite.

## Alternatives ecartees

**Ne rien faire, laisser le balayage de 2h ramasser les orphelines.** Defendable : le
client n'etait pas lese. Ecarte parce que le defaut se voyait la ou ca compte pour une
soutenance — le tableau de bord comptait des commandes « en attente » qui n'existaient
pas vraiment, et la numerotation avancait a chaque hesitation. Le balayage reste en
place comme filet ; il n'est plus le mecanisme principal.

**Fusionner creation et encaissement en un seul appel**, comme le fait deja le comptoir
(`createStaffOrder`). Supprimerait le probleme a la racine : sans etat `pending_payment`
cote borne, ni orpheline ni modification a gerer. Ecarte parce que cet etat intermediaire
**modelise le vrai flux d'un terminal de paiement** (on cree la commande, le terminal
autorise, on confirme) et parce qu'ADR-0014 et le diagramme d'etats sont batis dessus.
Simplifier ici, c'est perdre de la fidelite de modelisation pour eviter un travail.

**Un endpoint dedie `PUT /api/orders/{numero}`.** Plus orthodoxe cote REST. Ecarte : la
borne n'a aucun ecran qui montre une commande en attente a modifier — le panier EST la
vue du client. L'endpoint n'aurait eu aucun appelant, et un endpoint sans appelant est
une surface d'attaque gratuite.

**Rendre la garde auto-changeante plutot que verrouiller** (`SET total = total + 1`, une
colonne de version, ou l'attribut PDO qui compte les lignes APPARIEES au lieu des lignes
changees). Ces trois pistes reparent le danger 1 et restent dans l'idiome maison.
Ecartees pour la raison mesuree en (b) : elles laissent intact le defaut decisif. La
coherence resterait suspendue a l'absence de toute lecture simple avant la garde dans
`pay()` — un invariant qu'aucun lecteur ne voit, et dont la violation ne declenche AUCUN
signal (les deux gardes rendent 1 ligne affectee alors que le stock est faux).

## Consequences

- (+) Une commande par session de paiement, dont le contenu suit le panier. L'orpheline
  disparait a la source.
- (+) Le prix d'un panier modifie est calcule par le MEME chemin qu'a la creation
  (`resolveAndTotal` partage) : un test verrouille l'egalite des deux, donc un panier ne
  peut pas couter deux prix selon le chemin emprunte.
- (+) La garde de rupture RG-T21 s'applique aussi a la modification : un article tombe
  en rupture depuis la creation ne peut pas etre reconduit.
- (+) La modification n'ecrit ni sur `ingredient` ni sur `stock_movement` — verifie par
  test unitaire ET par test d'integration qui compare les quantites et le nombre de
  mouvements avant/apres sur la vraie base.
- (+) Le client dont la commande a ete annulee pendant qu'il hesitait n'est plus bloque.
- (-) **Une exception au motif de concurrence du projet.** Un verrou explicite existe
  desormais, alors qu'un commentaire du code affirmait le contraire (corrige). C'est le
  point a defendre a l'oral, avec la mesure a l'appui.
- (-) L'immunite du verrou suppose qu'il reste la PREMIERE instruction de la transaction :
  une lecture simple placee avant lui figerait l'instantane trop tot. Un test de
  non-regression garde cet invariant, mais il n'est pas auto-verifiant par le langage.
- (-) L'analyse repose sur un comportement du MOTEUR (creation paresseuse de
  l'instantane) que le projet ne controle pas. Si l'isolation passait un jour a
  SERIALIZABLE, elle serait a refaire.
- (-) Un renvoi de tentative reelle (memes lignes) fait quelques ecritures inutiles au
  lieu d'aucune. Assume : la simplicite d'un remplacement systematique vaut mieux qu'une
  comparaison de paniers, dont une erreur ferait payer un contenu perime.
- (-) La borne conserve sa cle plus longtemps qu'avant. Borne par la duree de vie de
  `sessionStorage` (l'onglet), liberee au succes et sur `ORDER_CANCELLED`.

Fichiers : `src/app/Order/OrderRepository.php` (`replaceItems`, `lockOrder`,
`resolveAndTotal`, `insertLines`, `createPending`, `pay`),
`src/app/Controllers/OrderController.php`, `src/public/borne/assets/js/checkout.js`,
`src/public/borne/assets/js/page-payment.js`. Regles : `docs/merise/mlt.md` 3.3bis.
Cycle de vie : `docs/uml/state-commande.md` (boucle M1).
