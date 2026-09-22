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

**Decision, en deux temps.** `lockOrder()` prend un verrou de ligne explicite, appele par
les deux operations concernees (M1 et T2), en **premiere instruction** de leur
transaction. La serialisation devient explicite : quand `lockOrder` rend la main, toute
modification concurrente a deja committe.

Mais un verrou de ligne seul laissait subsister le piege de la lecture precoce : une
relecture adversariale l'a **mesure** sur cette conception meme — avec une lecture simple
posee avant le verrou, le `FOR UPDATE` rendait le NOUVEAU total (420) tandis que la
lecture qui suivait rendait les ANCIENNES lignes. Pire, le verrou donnait alors au
relecteur une **fausse impression de protection structurelle**, ce qui rendait le piege
plus facile a poser qu'avant.

D'ou le second temps : **les lectures du contenu de la commande sont elles-memes
verrouillantes** (`order_item`, `order_item_selection`, `order_item_modifier` dans
`consumption()`). Une lecture verrouillante lit la derniere version committee et non
l'instantane de la transaction, quoi qu'il se passe avant elle. La fraicheur devient donc
**structurelle** au lieu de dependre de l'ordre des instructions. Le catalogue
(`product_ingredient`) n'est PAS verrouille : c'est de la donnee de reference partagee,
pas le contenu de cette commande, et la verrouiller depuis ce chemin bloquerait des
lectures de catalogue sans rapport.

Un test de non-regression garde en plus l'ordre (le verrou avant toute lecture du
contenu), en ceinture et bretelles.

Le prix paye est nomme : une exception au motif unique du projet, confinee a une methode
privee documentee et a une methode de calcul. C'est ce que je defends a l'oral, mesures a
l'appui.

### (b bis) L'en-tete de service suit le panier, sinon le marqueur fiscal est faux

Le defaut le plus grave de la premiere version de ce lot ne demandait meme pas de
concurrence, et une relecture adversariale l'a trouve. `replaceItems` ne rafraichissait
que les lignes et les totaux : `service_mode` et `service_tag` restaient ceux de la
creation.

Sequence, entierement realisable par des gestes normaux : le client choisit « sur place »,
saisit le chevalet 12, son paiement echoue (la borne conserve panier ET cle), il retourne
a l'accueil, passe « a emporter », et paie. La commande etait alors **encaissee en
`dine_in` avec le chevalet 12**. Or le projet traite ce marqueur comme la distinction
fiscale (TVA salle contre vente a emporter) : il aurait ete faux sur une commande payee,
donc sur le chiffre d'affaires. Et un equipier aurait porte un plateau a la table 12 que
personne n'occupe. Le sens miroir cassait aussi : passer de « a emporter » a « sur place »
jetait le chevalet saisi, laissant le client attendre a une table non identifiable.
Aucun code d'erreur, aucune trace : l'ecart etait silencieux.

L'en-tete est donc rafraichi **dans la meme transaction** que les lignes, en rejouant
exactement la validation de la creation (`resolveHeader`, extrait pour etre partage) : le
mode est valide contre son ensemble, le chevalet n'est conserve qu'en salle, et RG-T09
(source `drive` impose le mode `drive`) est enoncee dans le code plutot que laissee a la
contrainte de base — sinon une evolution du flux rendrait un 500 au lieu d'un refus
metier lisible.

### (b ter) La cle vit le temps du PANIER, pas le temps de l'onglet

Garder la cle pour pouvoir modifier la commande la fait aussi durer **au-dela du client**,
et une borne est partagee. Sequence trouvee en relecture : le client A laisse une commande
en attente et s'en va ; le client B abandonne le panier de A, compose le sien, paie. La
commande de B venait s'installer **dans la commande de A** — heritant son mode de service,
son `created_at` (donc son horloge d'expiration), et son numero deja affiche a A. Si A
revenait consulter son suivi, il lisait la commande de B.

Correctif : `clearCheckoutKey()` est appele partout ou `clearCart()` l'est hors succes —
l'abandon du panneau de commande et le bouton « Nouvelle commande ». **Panier vide vaut
session de paiement soldee.** Le cas « deux bornes / deux onglets avec la meme cle » n'est
pas atteignable (`sessionStorage` est par onglet, la cle est tiree au hasard) : le seul
partage reel etait celui-ci, deux clients successifs dans le meme onglet.

### (b quater) Le balayage porte sur la derniere activite

`expireStalePending` selectionnait sur `created_at`, que `replaceItems` ne rafraichit pas.
Une commande creee a 10h00 et **modifiee a 12h01** satisfaisait encore le predicat a 12h02 :
elle mourait sous le client, juste apres que le serveur lui a confirme sa modification. Le
balayage affirmait dans son audit une expiration « restee en attente » alors que la
commande venait d'etre touchee.

Le predicat devient `GREATEST(created_at, updated_at)`. L'intention du balayage — liberer
ce qui est reellement laisse en plan — est respectee au lieu d'etre contredite. Amende a
[ADR-0014](0014-expiration-commandes-pending.md), dont c'est une consequence directe :
rendre une commande modifiable change ce que « abandonnee » veut dire.

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
- (-) La borne conserve sa cle plus longtemps qu'avant. Bornee au PANIER : liberee au
  succes, sur `ORDER_CANCELLED`, a l'abandon du panier et sur « Nouvelle commande ».
- (-) **Reliquat pre-existant, inscrit ici pour ne pas le decouvrir en soutenance.** La
  garde de rupture (RG-T21) est evaluee AVANT le verrou et `pay()` ne la rejoue pas : deux
  clients peuvent chacun passer la garde sur la derniere portion d'un ingredient et etre
  tous deux encaisses. Comme `stock_quantity` est un entier signe sans contrainte
  `>= 0`, le stock passe simplement en negatif, sans erreur, et personne n'est prevenu a
  l'encaissement. La fenetre existait deja entre creation et paiement ; F18 ne l'aggrave
  pas materiellement. La corriger est une autre fonctionnalite (reserver le stock a
  l'encaissement, ou ajouter la contrainte pour rendre la survente visible).

Fichiers : `src/app/Order/OrderRepository.php` (`replaceItems`, `lockOrder`,
`resolveAndTotal`, `insertLines`, `createPending`, `pay`),
`src/app/Controllers/OrderController.php`, `src/public/borne/assets/js/checkout.js`,
`src/public/borne/assets/js/page-payment.js`. Regles : `docs/merise/mlt.md` 3.3bis.
Cycle de vie : `docs/uml/state-commande.md` (boucle M1).
