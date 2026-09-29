# ADR-0019 — Page « Santé de l'API » : une carte des routes qui ne peut pas diverger du code

- Statut : Accepte, complete le 2026-09-28 (reponses reelles + carte par action), precise le
  2026-09-29 (sonde publique sans version PHP)
- Date : 2026-09-27

> **Complement (2026-09-29, commit `08d7a96`).** Le contexte de cette fiche dit que
> `/api/health` "doit rester tel quel" et les consequences que "la sonde du deploiement
> continu reste strictement inchangee" : c'etait vrai pour son ROLE (verifier le commit
> servi), pas pour la totalite de son corps. `App\Controllers\HealthController` renvoyait
> aussi `php_version` (`PHP_VERSION`) a tout visiteur anonyme, y compris depuis l'hote
> borne — une fuite d'information inutile (OWASP A05) sans lien avec le role de la sonde.
> Ce champ est retire (6 cles restantes : `status`, `app_env`, `db`, `categories`, `version`,
> `deployed_at`) ; `version` et `deployed_at`, qui portent le role de verification de
> deploiement, ne changent pas. La page authentifiee `/admin/health` (point (d) ci-dessous,
> `App\Health\HealthReport`, derriere `role.manage`) garde `php_version` pour l'exploitant,
> depuis sa propre source. Trouve par la suite de tests de securite executables
> (`docs/soutenance/preuves/10-tests-securite.md`, ecart m5).

> **Complement (2026-09-28).** Le trajet d'un appel affichait jusque-la un corps ecrit a la
> main pour illustrer une reponse ; certains refus illustres etaient meme faux au regard du
> serveur reel. Un programme de capture (`tests/e2e/health-capture.spec.js`, lance par
> `tests/e2e/run-health-capture.sh`) appelle pour de vrai chacune des 158 routes, en succes
> puis sur chaque refus propose par le trajet, et enregistre le resultat dans
> `App\Health\CapturedResponses` / `src/app/Health/captured-responses.json` (hors racine web,
> servi par la page deja gardee `role.manage`) : **158 succes obtenus sur 158, 670 refus
> obtenus sur 689 tentes** ; un refus non observe l'affiche explicitement plutot que
> d'inventer un code. Le trajet continue de ne jamais rien ecrire depuis la page (garanti par
> `buildTrajetRequest`, verifie par un test navigateur) ; `tests/Unit/Health/CapturedResponsesTest.php`
> (CI) verifie que chaque route a sa capture (ou une raison ecrite), qu'aucune capture ne vise
> une route disparue, et que le fichier ne contient ni jeton ni mot de passe. Independamment,
> la carte a ete rangee **par action** plutot qu'a plat : les 158 routes se lisent desormais en
> 80 actions (une colonne par surface : page, formulaire, API JSON, borne). Le rangement par
> action et le programme de capture sont livres par #188 et #189 (157 routes a ce moment) ;
> la 158e route (`/admin/api/ingredients/{id}/movements`) arrive par #191, le meme jour. Le
> champ `commit` de `captured-responses.json` (`edea94c`) date de #190, avant #191 : il ne
> reflete donc que 157 routes au registre du routeur a ce commit precis, meme si le fichier
> contient bien 158 captures (la capture a ete rejouee apres #191 sans que ce champ de
> provenance soit remis a jour — a corriger au prochain rafraichissement). Detail :
> `docs/journal/2026-09-28--trajet-reponses-reelles.md`.

## Contexte

Une page de visualisation de l'API a d'abord ete publiee hors de l'application : la liste des
routes, et pour chacune le trajet d'un appel a travers les couches, avec chaque refus possible.
Ses donnees avaient ete extraites du code par un programme, puis recoupees a la main. Cette page
a deux defauts de fond.

- **Elle vieillit en silence.** C'est une photographie d'un commit. La prochaine route ajoutee au
  routeur n'y figurera pas, et rien ne le signalera.
- **Elle ne montre pas l'API vivante.** Une page hebergee ailleurs ne peut pas interroger le
  serveur de production : elle decrit ce que l'API ferait, elle ne montre pas ce qu'elle fait.

La demande est de la ramener dans le back-office. Deux contraintes en decoulent.

- `/api/health` existe et doit rester tel quel : c'est la sonde que le deploiement continu
  interroge pour verifier le commit servi. Le transformer en page lui ferait perdre ce role.
- La politique de securite de contenu du back-office interdit tout script en ligne et toute
  ressource externe.

## Decision

### (a) Une page `/admin/health`, sous la permission `role.manage`

La page vit dans la convention des pages d'administration, a cote de la sonde `/api/health` qui
reste inchangee. Sa permission est `role.manage` : la page est la carte des routes et des
permissions qu'elles exigent, ce qui releve du domaine de cette permission. Aucune permission
n'est ajoutee : le catalogue reste a 23.

Le site de la borne ne relaie que `/api` vers PHP ; `/admin/health` n'est donc pas joignable
depuis l'origine de la borne, par construction.

### (b) Les routes sont lues dans le routeur, pas recopiees

Les declarations de routes quittent le controleur frontal pour un fichier chargeable
(`src/app/Core/routes.php`) qui retourne une fonction. Le controleur frontal l'appelle au meme
endroit ; la page, elle, le charge sur un routeur neuf pour lister ce qui est reellement
declare. Une route ajoutee au code apparait donc sur la page sans aucune autre modification.

### (c) Les exigences de chaque route ont une seule source, que les tests utilisent aussi

La permission, le jeton anti-rejeu, le code personnel et l'absence de compte d'une route ne se
lisent pas dans le routeur : ils sont appliques dans le code de chaque action. Ils sont donc
declares dans une table unique, `App\Health\RouteSecurity`.

Le risque d'une telle table est evident : elle peut mentir. Il est traite par trois mecanismes.

- **Couverture dans les deux sens.** Un test echoue si une route du routeur n'a pas sa ligne, ou
  si une ligne ne correspond a aucune route.
- **La table n'est plus dupliquee dans les tests.** Le test de matrice de l'API d'administration
  portait jusqu'ici sa propre copie des routes et de leurs permissions. Il lit desormais la table.
  Afficher une exigence et la tester reviennent a lire la meme ligne.
- **Chaque badge est verifie par un test de comportement.** Pour chaque route, la permission
  annoncee est verifiee dans les deux sens (toutes les permissions sauf celle-la : refus ; celle-la
  seule : pas de refus), le jeton annonce est exige, le code personnel annonce est exige, et une
  route qui n'annonce pas de code n'en exige pas.

La consequence est la propriete qui justifie la page : **quand elle affiche qu'une route exige
une chose, un test verifie que la route l'exige.**

### (d) L'etat en direct, en comptes seulement

La page affiche la version servie, l'environnement, l'etat reel de l'affichage des erreurs, la
joignabilite de la base avec un temps de reponse mesure, les migrations et jeux de donnees
appliques, et l'activite des dernieres 24 heures. Elle relit ces valeurs toutes les 15 secondes
par `GET /admin/api/health`.

Seuls des comptes sont exposes, aucune ligne nominative. Une base injoignable produit un etat
degrade sans detail d'erreur, pour la meme raison que la sonde du deploiement.

### (e) Des appels reels, tous refuses avant toute ecriture

La page lance de vrais appels vers l'API et affiche le statut et le temps reellement obtenus :
catalogue servi, appel sans session, ecriture sans jeton, ecriture au mauvais type de contenu,
route inconnue, methode refusee.

Declencher de vraies requetes depuis une page d'administration n'est acceptable que si aucune ne
peut modifier une donnee. La propriete est obtenue par l'ordre des controles : les ecritures
visees sont refusees au jeton ou au type de contenu, qui sont examines avant la validation et
avant toute requete d'ecriture. Elle est verifiee par un test qui fait passer chaque sonde par le
vrai routeur et les vrais controleurs, et constate a la fois le statut attendu et l'absence de
toute requete d'ecriture.

La cible des deux sondes d'ecriture est une route gardee par `role.manage`, la permission meme de
la page : quiconque voit la page franchit la permission, et le refus observe est bien celui qu'on
veut montrer.

## Alternatives ecartees

**Transformer `/api/health` en page.** Ecarte : le deploiement continu lit ce point pour verifier
le commit servi. Une page HTML a sa place le priverait de sa verification.

**Deduire les exigences en analysant le code des controleurs au moment de l'affichage.** C'est ce
qu'avait fait le programme d'extraction de la premiere page, et il s'est trompe deux fois (un
code personnel annonce sur le telechargement du modele d'import, un autre sur la definition de
son propre code, qui demande en fait le mot de passe). Une analyse de texte au moment de
l'affichage garderait ces erreurs, sans test pour les attraper.

**Une permission dediee.** Ecarte : elle ajouterait une 24e permission pour une page de
consultation, contre le principe d'un catalogue stable deja defendu en ADR-0015.

**Laisser la page publique, sans compte.** Ecarte. Le depot est public et la carte des routes y
figure deja, mais l'etat de la base et l'activite recente n'ont pas a etre servis a un visiteur
anonyme.

## Consequences

- (+) La page ne peut pas diverger du code : les routes sont lues dans le routeur, et chaque
  exigence affichee est la ligne meme que les tests verifient.
- (+) Le test de matrice perd sa copie de la table : une source de moins a tenir alignee.
- (+) Le controleur frontal redevient court ; les routes vivent dans un fichier qui se charge et
  se teste seul.
- (+) La sonde du deploiement continu reste strictement inchangee.
- (-) La table des exigences est une declaration : ce sont les tests qui la rendent vraie. Une
  exigence ajoutee dans une action sans mise a jour de la table ferait echouer les tests de
  comportement, pas le test de couverture ; c'est le comportement voulu, mais il faut le savoir.
- (-) Les appels reels ajoutent un peu de trafic et des lignes au journal d'acces a chaque
  lancement. Ils ne sont declenches que par un clic.
- (-) La page relit l'etat toutes les 15 secondes tant qu'elle est ouverte et visible ; la
  relecture est suspendue quand l'onglet est masque.
