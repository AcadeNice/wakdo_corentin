# Page « Santé de l'API » : une carte des routes qui ne peut pas diverger du code

**Date** : 2026-09-27
**Branche** : `feat/admin-health`
**PR** : #175
**Duree estimee** : 2h de travail assiste, quatre chantiers en parallele

---

## Ce qui a ete fait

Une page du back-office, `/admin/health`, sous la permission `role.manage`, en quatre blocs :

- **l'etat en direct** : version servie, environnement, affichage des erreurs, base joignable
  avec un temps de reponse mesure, migrations et jeux de donnees appliques, activite des 24
  dernieres heures en comptes seulement ; relu toutes les 15 secondes par `GET /admin/api/health` ;
- **sept appels reels** lances depuis la page, dont cinq refus attendus (401, 403 jeton, 415,
  404, 405), avec leur vrai statut et leur vrai temps ;
- **le trajet anime d'un appel**, couche par couche, avec la simulation de chaque refus ;
- **la carte des 157 routes**, lue en direct dans le routeur.

Pour y arriver, les declarations de routes ont quitte le controleur frontal pour
`src/app/Core/routes.php`, et les exigences de chaque route (sans compte, permission, jeton,
code personnel) vivent dans une table unique, `App\Health\RouteSecurity`, que les tests lisent
aussi.

Mesures du dernier passage complet : **PHPStan niveau 6 sans erreur ; PHPUnit 2345 tests, 7761
assertions, 0 echec** (1725 avant ce lot) ; **399 tests JavaScript, 0 echec** (374 avant) ;
audit d'accessibilite mesure **19 ecrans, 943 contrastes, 0 violation**.

## Pourquoi — decisions et alternatives

**`/api/health` n'a pas bouge.** C'est la sonde que le deploiement continu interroge pour
verifier le commit servi. La page vit donc a cote, dans la convention `/admin/*`.

**`role.manage`, pas une 24e permission.** La page est la carte des routes et des permissions
qu'elles exigent ; c'est le domaine de cette permission, et le catalogue reste a 23.

**Une table des exigences, prouvee par le comportement.** Les exigences d'une route ne se
lisent pas dans le routeur : elles sont appliquees dans chaque action. Il fallait donc les
declarer, avec le risque qu'une declaration mente. Trois garde-fous : couverture dans les deux
sens entre le routeur et la table ; suppression de la copie que le test de matrice de l'API
portait jusqu'ici ; et des tests de comportement pour chaque badge affiche.

**Des sondes sans danger par construction.** Les deux sondes d'ecriture visent une route gardee
par `role.manage` : quiconque voit la page franchit la permission, et le refus observe est
celui du jeton ou du type de contenu, examines avant toute ecriture.

## Comment — points techniques cles

**Le deplacement des routes ne change rien, et c'est teste.** Un test compare les 155 routes
chargees depuis `routes.php` a une liste extraite independamment de `origin/dev` : meme ordre,
memes gestionnaires. Seules les deux nouvelles routes s'y ajoutent.

**La matrice de securite a grossi, pas maigri.** Le test de matrice de l'API d'administration
passe de 227 a 230 cas : les 52 routes d'avant, plus `/admin/api/health`. Sa table dupliquee a
disparu : il lit la table unique et le routeur.

**Chaque badge du back-office HTML est verifie.** 91 routes : session exigee ou non ;
permission refusee sans la bonne et accordee avec elle seule (78 routes) ; jeton exige sur 42
ecritures ; code personnel exige sur 12 routes, et seulement si le prix ou la TVA change sur 3
autres ; 28 ecritures qui passent sans code, pour qu'aucune exigence ne soit annoncee a tort
dans l'autre sens. Les cas sont generes depuis la table : ajouter une route a la table suffit a
l'ajouter aux tests. 15 defauts introduits volontairement dans une copie privee ont ete
detectes, les 15.

**L'absence d'ecriture des sondes est mesuree sur le serveur.** Chaque sonde passe par le vrai
routeur, les vrais controleurs et une vraie MariaDB ; les compteurs du serveur (INSERT, UPDATE,
DELETE, REPLACE) sont releves avant et apres. C'est plus sur qu'un comptage de lignes : une
ecriture qui remettrait une valeur a l'identique serait vue.

**Verifie dans un vrai navigateur.** Sur une pile jetable : page servie en 200, 157 routes
affichees, les 7 sondes conformes (de 3 a 19 ms), une relecture de l'etat en 16,5 secondes, aucune
erreur de script ni violation de la politique de securite de contenu ; pour le compte manager,
page et API refusees en 403 et lien absent de la navigation.

## L'ecart que les tests ont trouve

**`POST /logout` etait annonce « session requise ».** Le controleur ne verifie que le jeton :
une deconnexion sans session ne fait rien et renvoie a `/login`. L'erreur venait de la premiere
version de la page, publiee hors de l'application, ou elle avait ete forcee a la main. Le test de
comportement l'a signalee le jour meme ; la table et la premiere page ont ete corrigees. C'est
exactement le defaut que ces tests existent pour attraper : une affirmation fausse, montree au
jury comme un fait.

## Criteres RNCP couverts

- **Securite** : cartographie verifiee des exigences de chaque route ; sondes reelles de refus ;
  aucune donnee nominative exposee ; page reservee a `role.manage`.
- **Tests** : couverture bidirectionnelle, matrice de comportement generee, preuve d'absence
  d'ecriture mesuree sur le serveur, verification en navigateur reel.
- **DevOps** : sonde du deploiement preservee ; etat en direct de la version servie, des
  migrations et de la base.
- **Accessibilite** : la page entre au perimetre de l'audit mesure le jour de sa creation,
  0 violation, et un contraste insuffisant evite avant la mesure.

## Questions anticipees du jury

**« Pourquoi une table plutot que lire les exigences dans le code ? »** Parce qu'on ne sait pas
les lire de facon fiable : la premiere page, qui les deduisait du texte des controleurs, s'est
trompee trois fois. La table se declare, et ce sont les tests de comportement qui la rendent
vraie.

**« Ces appels depuis la page ne sont-ils pas dangereux ? »** Chaque sonde est refusee avant toute
ecriture, par l'ordre des controles, et un test le mesure sur les compteurs du serveur.

**« Qui peut voir cette page ? »** Un role qui detient `role.manage`. Le manager ne la voit pas,
verifie en navigateur.

## Points d'amelioration conscients

- **Le conteneur applicatif ne monte pas `db/`.** La page connait les migrations appliquees, lues
  en base, mais pas le nombre de fichiers presents : elle l'affiche comme non visible au lieu
  d'inventer un chiffre.
- **La table des exigences reste une declaration.** Une exigence ajoutee dans une action sans mise
  a jour de la table fait echouer les tests de comportement, pas le test de couverture.
- **Le code de la page n'a pas ete ecrit strictement tests d'abord** : code et tests JavaScript
  ont ete ecrits dans la meme passe. Les tests couvrent la construction des etapes, les filtres,
  les requetes des sondes et l'echec de relecture.
- **L'audit du jour n'a ete joue qu'une fois**, sans le double passage identique de la campagne
  precedente.

## Liens vers artefacts

- Fiche de decision : `docs/adr/0019-page-sante-api-carte-vivante.md`
- Code : `src/app/Core/routes.php`, `src/app/Health/`, `src/app/Controllers/HealthPageController.php`,
  `src/app/Controllers/Admin/Api/HealthApiController.php`, `src/public/admin/assets/js/health.js`
- Tests : `tests/Unit/Health/`, `tests/Unit/Admin/HtmlRoute*`, `tests/Integration/HealthProbesSafetyDbTest.php`,
  `tests/Unit/Admin/Api/RouteMatrixTest.php`, `tests/js/health.test.js`
- Preuve : `docs/soutenance/preuves/06-audit-accessibilite-mesure.md` section 5 quater,
  `docs/soutenance/preuves/rapports/axe-admin-sante-api.json`
