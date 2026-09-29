# Soutenance orale - Plan definitif (40 min + 40 min de questions)

> Auteur : BYAN
> Projet : Wakdo - borne de commande fast-food
> Cadre : RNCP 37805 - Titre Developpeur Web - Bloc 1 (Front-End) + Bloc 2 (Back-End) + Bloc 5 (DevOps, option)
> Date de l'oral : lundi 5 octobre 2026, 8h30
> Format : 40 min d'expose, puis 40 min de questions du jury
> Statut : aide-memoire d'orateur. Ce n'est pas un jeu de diapositives.
> Chiffres arretes au 2026-09-26 sur `origin/dev`, au commit `1dd2620` (demande de fusion #166).
> Recomptes le 2026-09-27 sur le commit deploye `main` `11271f7` (fusion de la demande #177) :
> routes, controleurs, depots, vues, decisions d'architecture, migrations, jeux de donnees,
> entites, commits et suites de tests. Le 2026-09-27, sur le commit deploye `main` `1ccc9db`
> (release #182) : premiere passe d'audit d'accessibilite et de balayage de mise en page.
> Le 2026-09-28, sur le commit dev `bb1a7f8` (#183) : balayage rejoue (4 807 verifications,
> 3 echecs restants sur la page Sante corriges), et l'artefact `preuves/rapports/resume.json`
> regenere le meme jour (horodatage interne `2026-09-28T10:22:25Z`). **Nouveau recomptage
> complet le 2026-09-28**, sur le commit deploye `main` `04bece0` (release #194) : tous les
> chiffres du present document (section 1.1 comprise) refletent cet etat, y compris
> l'artefact `preuves/rapports/resume.json` regenere ce jour-la. La mesure de temps du canal
> auxiliaire (section 1.2) date du 2026-09-26 et n'a pas ete rejouee depuis : aucun fichier
> ne la versionne. **Le 2026-09-29**, deux correctifs de contre-audit sont merges (menu
> deja commande modifiable sans erreur serveur, journal d'audit `pin.failed` sans l'adresse
> saisie, migration 0019) : les comptes qui en dependent sont mis a jour dans ce document
> (18 migrations, 40 operations du MCT, deja a jour dans `docs/merise/mct.md` depuis le
> 28/09 mais restees a 35 ici) ; le nombre de tests n'est pas rejoue dans cette passe.

---

## 0. L'axe directeur

Cet oral ne deroule pas un catalogue de fonctionnalites. Il defend **une seule these** :

> **L'accessibilite, la securite et la chaine de livraison ont ete traitees par conception
> et mesurees, pas ajoutees apres coup.**

Tout le reste - le modele de donnees, l'API, le parcours client - est convoque pour
etayer cette these, dans cet ordre. Les trois blocs sont couverts parce que le jury
note par bloc, mais chacun est aborde par l'angle de l'axe :

| Bloc | Ce que l'axe en fait |
|---|---|
| B1 Front-End | L'accessibilite est la porte d'entree du front. Le parcours borne se raconte par les contraintes d'acces, pas par les ecrans. |
| B2 Back-End | La securite est la porte d'entree du back. Le modele de donnees et l'API se racontent par ce qu'ils empechent. |
| B5 DevOps | La chaine de livraison est ce qui rend les deux premiers verifiables a chaque modification. |

Phrase de transition recurrente : *"Ca, c'est ce que le systeme fait. Voyons maintenant
comment je le mesure."*

Trois mots a placer tot et a tenir : **par conception, mesure, assume**.

---

## 1. Fiche de cadrage (a relire 5 min avant de passer)

| Element | Valeur |
|---|---|
| Titre vise | Developpeur Web - RNCP 37805 |
| Blocs | B1 Front-End + B2 Back-End + B5 DevOps (option) |
| Seuil de validation | 50 % minimum par bloc ET 50 % de moyenne globale |
| Deux adresses publiques | borne client (B1) + back-office et API (B2), un seul depot |
| These en une phrase | "Wakdo est une borne de commande fast-food construite sans framework, dont l'accessibilite, la securite et la chaine de livraison sont traitees des la conception et verifiees par des mesures reproductibles." |

### 1.1 Chiffres a connaitre par coeur

Chaque ligne porte la commande qui la revalide. **Ce projet bouge vite** : pres de
20 demandes de fusion sont entrees entre chaque recomptage. Relancer ces commandes
la veille, et corriger ce qui a bouge. Un chiffre annonce de memoire et dementi par le
depot coute plus cher que le chiffre lui-meme.

| Metrique | Valeur | Comment la revalider |
|---|---|---|
| Commits (sur `main`, commit deploye `04bece0`, 28/09) | 242. **Etat au 29/09** (commit `main` `dc1829d`) : **244** | `git rev-list --count 04bece0` ; a la veille, revalider avec `git rev-list --count main` |
| Demandes de fusion fusionnees | 192 (derniere au 28/09 : #194, la release deployee `04bece0`). **Etat au 29/09** : **194** fusionnees, derniere **#196** (release deployee `dc1829d`) | API de la forge : `GET /api/v1/repos/AcadeNice/corentin_wakdo/pulls?state=closed`, compter les entrees `merged: true` (le decompte par `git log` ne voit pas les anciennes fusions, dont le titre ne finit pas par `(#n)`) |
| Repartition des commits | 111 `feat`, 42 `docs`, 41 `fix`, 11 `chore`, 8 `ci`, 4 `test`, 2 `refactor` | `git log --pretty=%s 04bece0 \| grep -oE '^[a-z]+' \| sort \| uniq -c` |
| Lignes PHP livrees | 27 168 sur 137 fichiers | `find src -name '*.php' -type f -exec cat {} + \| wc -l` |
| Lignes JavaScript livrees | 8 711 sur 30 fichiers | `find src -name '*.js' -type f -exec cat {} + \| wc -l` |
| dont borne client | 3 311 sur 21 modules | `wc -l src/public/borne/assets/js/*.js` |
| Lignes CSS | 5 792 (borne 2 322 + back-office 3 470) | `wc -l src/public/*/assets/css/*.css` |
| Methodes de test PHP | 1 295 sur 116 fichiers | `grep -rhoE 'public function test[A-Za-z0-9_]*' tests --include='*.php' \| wc -l` |
| Appels de test JavaScript | 450 sur 29 fichiers | `grep -rhoE "\b(it\|test)\(" tests/js --include='*.test.js' \| wc -l` |
| Scenarios de bout en bout | 92 sur 22 specs | `grep -rhoE '^\s*test\(' tests/e2e --include='*.spec.js' \| wc -l` |
| Analyse statique | PHPStan niveau 6, sans erreur | `phpstan.neon` (`level: 6`) |
| Entites du modele | 23 | `docs/merise/dictionary.md` (3.1 a 3.23) |
| Migrations / jeux de donnees | 17 / 10, idempotents | `ls db/migrations/*.sql \| wc -l` ; `ls db/seeds/*.sql \| wc -l` |
| Roles / permissions | 5 / 23 | `db/seeds/0001_rbac_and_reference.sql` |
| Routes declarees | 158 (77 GET, 67 POST, 9 PUT, 5 DELETE) dans `src/app/Core/routes.php` | `grep -c "router->add(" src/app/Core/routes.php` |
| Controleurs | 23 au premier niveau (2 abstraits, 14 `AdminController`, 6 `Controller`, 1 `AuthenticatedController`) + 11 sous `Admin/Api` (10 concrets + 1 trait `JsonApiTrait`) | `ls src/app/Controllers/*.php src/app/Controllers/Admin/Api/*.php \| wc -l` |
| Depots / vues | 11 / 41 | `find src/app -name '*Repository.php' \| wc -l` ; `find src/app/Views -name '*.php' \| wc -l` |
| Regles transverses de securite | 22 (RG-T01 a RG-T22) | `docs/merise/mlt.md`, lignes 41-62 |
| Decisions d'architecture | 20 | `ls docs/adr/0*.md \| wc -l` |
| Entrees de journal de bord | 16 | `ls docs/journal/2026-*.md \| wc -l` |
| Services conteneurises | 5 | `docker-compose.yml` |
| Travaux d'integration continue | 5 (`secret-scan`, `php-lint`, `static-tests`, `js-tests`, `shell-tests`) + deploiement continu separe | `.forgejo/workflows/ci.yml` (+ `deploy.yml` pour le CD) |
| Taches planifiees actives | 4 (+ 3 modeles commentes) | `docker/cron/crontab` |

**Sur les suites de tests - une precaution de formulation.** La derniere execution
complete mesuree donne **2 386 tests PHP pour 7 886 assertions** (avec la base de test)
et **446 tests JavaScript** (mesure du 2026-09-28, sur le commit deploye `04bece0`).
Ces chiffres continuent de croitre a chaque demande de fusion : ce sont donc un
**plancher**, pas un plafond. Deux options a l'oral, au choix :

- relancer les suites la veille et annoncer le resultat exact ;
- dire *"plus de 2 380 tests PHP et plus de 440 tests JavaScript, derniere execution
  complete le 28 septembre"*.

L'ecart entre le comptage statique de methodes et le nombre de tests executes s'explique
et doit etre su : une methode associee a un fournisseur de donnees s'execute une fois par
jeu de donnees et compte pour autant de tests.

### 1.2 Les trois mesures qui portent l'axe

A savoir reciter sans notes. Attention : les deux premieres viennent de **deux outils
distincts**, et les confondre serait une erreur qu'un jury technique reperera.

| Mesure | Outil | Resultat | Source |
|---|---|---|---|
| Audit d'accessibilite | axe-core 4.13.0 via Playwright, regles WCAG 2.0 A/AA et WCAG 2.1 A/AA | **19 ecrans** (6 borne, 13 back-office), **0 violation** toutes gravites, **935 rapports de contraste**, **0 sous le seuil**, minimum releve **3,59**. Resolutions : 1080x1920 pour la borne, 1440x900 pour le back-office. **Un seul role : administrateur** | `docs/soutenance/preuves/rapports/resume.json`, campagne du 2026-09-28 |
| Balayage de mise en page et d'ergonomie | outil ecrit pour le projet (`tests/e2e/backoffice-sweep/`), 12 familles de verifications | **4 807 verifications, 0 echec** - 5 roles connectes plus l'etat non connecte, toutes les pages atteignables (111 pages-roles), **4 largeurs** (1366, 1024, 768, 390 px). Trajectoire : **104 echecs avant la refonte, 11 apres le premier lot, 0 le 26/09 ; le 28/09, 3 echecs sur la page Sante, qui n'avait pas encore ete balayee, corriges (#183), 0 a nouveau** | mesure du 2026-09-28 sur `1ccc9db` plus la correction #183. Les sorties de cet outil ne sont pas versionnees : le relancer pour produire le rapport |
| Canal auxiliaire par le temps sur la connexion | mesure directe des 4 chemins | compte inexistant 257,4 ms / mot de passe faux 251,4 ms / compte verrouille 253,3 ms / connexion reussie 252,6 ms. **Ecart maximal 6,0 ms pour un bruit de mesure de 13,8 ms** | mesure du 2026-09-26 |

La lecture a donner au jury pour la troisieme : *"l'ecart entre les chemins est plus
petit que le bruit de ma propre mesure. Autrement dit, le temps de reponse ne permet
pas de distinguer un compte qui existe d'un compte qui n'existe pas."*

> Regle d'or : annoncer un chiffre seulement si on en est sur. En cas de doute sur
> l'audit d'accessibilite ou le balayage de mise en page, dire "de l'ordre de" et
> proposer d'ouvrir ou de relancer l'outil source. La mesure du canal auxiliaire par le
> temps n'est PAS versionnee (aucun fichier, aucun script reproductible dans le depot) :
> pour celle-la, dire "de l'ordre de" sans promettre d'ouvrir quoi que ce soit.

---

## 2. Plan minute par minute

| Plage | Duree | Section | Bloc | A l'ecran |
|---|---|---|---|---|
| 00:00 - 02:00 | 2 min | A. Ouverture et these | - | Titre + la phrase de these |
| 02:00 - 05:00 | 3 min | B. Le produit, le perimetre, et ce qui en est exclu | - | Perimetre inclus / exclu |
| 05:00 - 08:00 | 3 min | C. La demarche et la position sur l'assistance IA | transverse | Chaine Merise + une decision d'architecture |
| 08:00 - 15:00 | 7 min | D. L'accessibilite par conception (demonstration borne) | B1 | Borne en direct + le rapport de mesure |
| 15:00 - 19:00 | 4 min | E. Le front sous le capot et le choix des bibliotheques | B1 | Un module JavaScript + la fiche C2.d |
| 19:00 - 23:00 | 4 min | F. Le modele de donnees et l'API | B2 | Modele conceptuel + une route |
| 23:00 - 30:00 | 7 min | G. La securite par conception (demonstration API + roles) | B2 | Insomnia en direct |
| 30:00 - 35:30 | 5 min 30 | H. Conteneurs, integration continue, deploiement | B5 | Schema des 5 services + le pipeline |
| 35:30 - 37:00 | 1 min 30 | I. Limites assumees et conclusion | - | La liste des limites |

**Total : 37 minutes.** Marge de 3 minutes sur les 40 annoncees.

### 2.1 Que compresser si le retard s'installe

Dans cet ordre, sans hesiter :

1. **Section E** (front sous le capot) : la reduire a 2 minutes en gardant seulement
   l'argument sur les bibliotheques. C'est la section la plus facile a sacrifier.
2. **Section F** : passer le modele de donnees en 2 minutes, en montrant le diagramme
   sans detailler les domaines.
3. **Section B** : reduire le perimetre a la liste des exclusions.

Garder D, G et H intactes en toute circonstance : ce sont les trois piliers de l'axe,
un par bloc.

### 2.2 Le signal de mi-parcours

A 19:00, l'oral doit avoir quitte le front. Si a 19:00 on est encore sur la borne,
couper la section E et passer directement a F.

---

## 3. Detail des sections

### Section A - Ouverture et these (00:00 - 02:00)

**A dire :**

- Qui je suis, le titre vise, les trois blocs presentes.
- Mon parcours : administration systeme et reseau. C'est de la que vient mon interet
  pour la partie livraison et exploitation, et c'est pour ca que j'ai pris le Bloc 5
  en option.
- La these, mot pour mot (section 0). La poser des la premiere minute.
- Le plan : accessibilite, puis securite, puis chaine de livraison.

**Note orateur :** annoncer tout de suite qu'il y aura deux demonstrations en direct
(la borne, puis l'API) et une lecture de rapports de mesure. Le jury saura qu'il n'aura
pas a reclamer de preuve : elle vient.

---

### Section B - Le produit, le perimetre, et ce qui en est exclu (02:00 - 05:00)

**A dire :**

- Wakdo est une borne de commande pour un fast-food. Tous les modes de service
  (sur place, a emporter, drive) sont en emballages papier sur plateau ou en sac ;
  la distinction sur place / a emporter est surtout fiscale (taux de TVA).
- Trois canaux de prise de commande : la borne client autonome, le comptoir tenu
  par un equipier, le drive.
- **Ce qui est inclus** : catalogue, composition de menus, panier, encaissement simule,
  back-office de gestion, ecran de cuisine, gestion du stock et des allergenes.
- **Ce qui est exclu, et pourquoi** - a afficher aussi longtemps que la liste incluse :

| Exclu | Motif |
|---|---|
| Paiement bancaire reel | Remplace par un numero de commande. Integrer un prestataire de paiement aurait consomme le budget d'heures sans rien demontrer de plus sur les trois blocs. |
| Compte client et fidelite | La borne sert un client anonyme. Ajouter un compte client aurait ouvert un deuxieme domaine d'authentification sans enrichir la demonstration. |
| Multi-langue | Francais uniquement. |
| Mode hors-ligne | La borne suppose le reseau disponible. |
| Multi-restaurants et multi-bornes | Le modele de donnees ne porte pas d'identifiant d'etablissement. C'est une limite structurelle, pas un reglage. |
| Orchestration type Kubernetes, environnements de pre-production, deploiement sans interruption | Le projet tourne sur un hote unique. Ces briques auraient demande une infrastructure que je n'ai pas. |
| Supervision type Prometheus et Grafana | Ecarte pour le temps. |

**Note orateur :** le referentiel valorise un perimetre maitrise. Dire *"j'ai choisi de
ne pas faire X parce que Y"* est plus solide que de laisser le jury decouvrir l'absence.
La colonne "motif" est la partie qui compte : une exclusion sans motif est un trou.

**Piege connu :** dans le dossier ecrit, la section 7 liste bien ces exclusions mais ne
porte un motif que sur une seule d'entre elles. Si le jury demande "pourquoi" sur une
autre, la reponse est dans la contrainte de budget d'heures et de perimetre du
referentiel - la donner oralement, sans renvoyer au document.

**Trois ecarts assumes avec le kit du sujet**, a citer si on les evoque : une API interne
plutot que des fichiers JSON fournis, un chevalet avec numero de commande, et un ecran de
paiement simule. Ils sont documentes dans le dossier.

---

### Section C - La demarche et la position sur l'assistance IA (05:00 - 08:00)

**A dire, premiere moitie (90 s) - la methode :**

- **Merise Agile** : le dictionnaire de donnees d'abord, le modele ensuite, enrichi au
  fil des lots. Source unique : `docs/PROJECT_CONTEXT.md`, complete par `docs/merise/`
  (dictionnaire, modele conceptuel des donnees, modele logique, modele conceptuel des
  traitements avec ses 40 operations).
- **Developpement pilote par les tests** sur les chemins sensibles : commande, stock,
  authentification, droits. Exemple concret (2026-09-29) : le contre-audit a rejoue
  `MenuRepositoryDbTest` sur une base reelle et reproduit une erreur serveur 500 des
  qu'un menu deja commande etait modifie (delete-and-reinsert contre une FK `RESTRICT`) ;
  le correctif reconcilie les emplacements en place et refuse en 409 le retrait d'un
  emplacement deja commande, verifie par un test qui echouait avant le correctif.
- **Tracabilite** : 20 decisions d'architecture datees et motivees, 16 entrees de
  journal de bord, 242 commits en convention de nommage au 28/09/2026 (244 au 29/09),
  chaque changement passe par une demande de fusion (192 fusionnees au 28/09/2026,
  194 au 29/09, derniere #196).

**A dire, seconde moitie (90 s) - l'assistance IA, avant qu'on me la demande :**

C'est la section qui doit etre dite **spontanement**, pas subie. La position :

> *"Ce projet a ete construit avec l'assistance d'une IA, dans un cadre autorise par
> le centre de formation et documente dans le dossier, section 17. Je l'ai pratiquee
> comme une pratique dirigee : l'outil redige, code et relit ; les decisions
> d'architecture, de perimetre et de securite sont les miennes et sont tracees en
> decisions d'architecture. Pour ne pas m'en tenir a une declaration, je me suis
> adosse a des exercices de maitrise : je peux expliquer n'importe quelle partie du
> code et la modifier devant vous. Je vous propose de le verifier quand vous voudrez."*

Trois appuis a avoir en tete :

1. La repartition est ecrite noir sur blanc dans le dossier (section 17.3 pour ce que
   l'outil fait, 17.4 pour ce qu'il ne fait pas).
2. La methodologie est versionnee et lisible : `.claude/CLAUDE.md`, `.claude/rules/`,
   le journal, les decisions d'architecture.
3. L'engagement de la section 17.8 est testable : chaque ligne commise a ete lue et
   comprise, et je sais raisonner sans assistance sur un sujet du projet.

**Note orateur :** le ton fait tout. Ni excuse, ni bravade. On enonce, on donne la
preuve disponible, et on **invite** a la verification. Un candidat qui propose lui-meme
le test desamorce la question piege.

**Note orateur, piege a eviter :** ne pas affirmer que les echanges avec l'outil sont
auditables. Le dossier l'ecrit en section 17.9, mais les journaux correspondants ne
sont pas versionnes (section 17.6) : je ne peux donc pas les produire. Ce qui est
auditable et livrable, ce sont les regles, les decisions d'architecture et le journal.

---

### Section D - L'accessibilite par conception (08:00 - 15:00) - BLOC 1

> Premier pilier de l'axe. Sept minutes, dont environ quatre de demonstration.
> Deroulement : on montre, puis on mesure, puis on nomme ce que la mesure ne couvre pas.

**D.1 - Le parcours en direct (08:00 - 12:00)**

Derouler la borne ecran par ecran, en verbalisant a chaque fois **la contrainte
d'acces**, pas l'esthetique :

1. **Accueil** - ecran d'attente qui propose deux choix, sur place ou a emporter
   (`index.html`, l.115-145). Les boutons de quantite de la borne font 56 px de cote
   (`style.css`, `.qty-btn`), avec un plancher a 44 px sur les elements interactifs -
   au-dela du seuil de 24 px du critere WCAG 2.2 sur la taille des cibles, parce que la
   borne s'utilise debout, parfois avec des gants.
2. **Categories** - navigation tactile. Le lien d'evitement est le **premier element**
   du corps de page, sur les 5 pages de la borne comme dans le gabarit du back-office.
3. **Produits** - grille de tuiles. Un produit en rupture porte **trois** signaux et
   pas seulement une couleur : le grisage, un badge texte "Indisponible", et le suffixe
   correspondant dans son intitule accessible (`page-products.js`).
4. **Composition d'un menu** - modales successives (taille, accompagnement, boisson).
   Le piege a tabulation est ecrit a la main dans `page-product-menu.js`
   (`trapFocus`, l.531-542) : boucle sur la touche de tabulation, plus la fermeture a
   l'echappement (`escHandler`, l.283-287). Le composeur n'a ni clic sur le fond ni
   retour du focus au declencheur : Annuler et Ajouter renvoient tous deux vers
   `products.html` (l.498-499, l.554). Ces deux comportements-la existent sur l'autre
   modale de la borne, celle de confirmation d'un geste destructeur (ex. Abandon de
   commande) : `confirm-modal.js` ferme au clic sur le fond (l.62) et rend le focus a
   l'element declencheur a la fermeture (`previouslyFocused`, l.19 et l.44-46).
5. **Panier** - panneau persistant, compteur de quantite. Le total anime respecte la
   preference systeme de mouvement reduit.
6. **Paiement puis confirmation** - mode de service, numero de commande.

**Le geste a faire en direct - la police adaptee a la dyslexie :**

C'est la preuve la plus simple a montrer. Cliquer le bouton en bas a gauche : toute
l'interface bascule en OpenDyslexic (deux graisses, auto-hebergees, licence versionnee).
Recharger la page : le choix a ete conserve. Puis dire :

> *"La police est auto-hebergee, pas chargee depuis un service tiers. Et le module qui
> porte cette bascule est partage entre la borne et le back-office par un lien
> symbolique versionne - une seule implementation, deux interfaces. Un test compare les
> deux fichiers octet pour octet, donc une divergence future casserait la suite avant de
> devenir un defaut silencieux."*

**D.2 - La mesure (12:00 - 14:00)**

Passer du recit a la preuve. Ouvrir `docs/soutenance/preuves/rapports/resume.json`.

Distinguer clairement les deux outils - c'est ce qui montre qu'on sait ce qu'on mesure :

- **axe-core 4.13.0**, pilote par Playwright dans un vrai navigateur, sur les regles
  WCAG 2.0 niveaux A et AA et les ajouts WCAG 2.1 niveaux A et AA. Resultat :
  **19 ecrans**, **0 violation** toutes gravites, **935 rapports de contraste**,
  **0 sous le seuil**, minimum releve **3,59**.
  Pourquoi un vrai navigateur et pas un rendu simule : sans rendu, il n'y a ni couleur
  calculee ni geometrie, donc aucun rapport de contraste calculable. C'est la raison
  pour laquelle les tests unitaires du depot ne peuvent pas porter cette preuve.
- **Le balayage de mise en page**, ecrit pour le projet, qui mesure ce qu'axe ne regarde
  pas : chevauchement d'elements, texte coupe, defilement horizontal, taille de cible,
  alignement, ordre de tabulation et visibilite du focus, contraste des messages
  d'erreur reellement declenches. **4 807 verifications, 0 echec**, sur 5 roles connectes
  plus l'etat non connecte, et **4 largeurs** : 1366, 1024, 768 et 390 px.

**La trajectoire est le vrai argument.** Dire cette suite de trois nombres :
**104 echecs avant la refonte, 11 apres le premier lot, 0 aujourd'hui.** Elle montre que
la mesure a servi a corriger, et pas seulement a constater - sans la deuxieme mesure, je
n'aurais pas su que le premier lot laissait 11 problemes.

**Deux anecdotes a garder en reserve** (elles valent plus qu'un tableau, si le jury
accroche) :

- **La mesure qui se trompait.** Les premiers passages rapportaient 4 violations sur la
  modale, avec une couleur de fond differente a chaque execution. Deux resultats
  differents pour le meme element : la mesure etait prise **pendant** l'animation
  d'ouverture, sur un fond transitoire. Correction : attendre la fin de toutes les
  animations avant de mesurer. Resultat, 0 violation. **4 fausses violations qui
  accusaient le code a tort** - la lecon, c'est qu'une mesure mal cadree produit du faux
  positif aussi bien que du faux negatif.
- **L'angle mort du jeton de couleur.** La correction d'un jaune trop clair passait sur
  la page qui avait echoue. En relisant **tous** les usages de ce jeton, on en a trouve
  un autre, sur un ecran qui ne faisait pas partie des 11 audites, ou la nouvelle valeur
  retombait sous le seuil. Valeur finale choisie pour tenir aux deux endroits. Une
  correction verifiee seulement la ou ca a echoue laisse un angle mort.

**D.3 - Ce que la mesure ne couvre pas (14:00 - 15:00)**

A dire avant que le jury ne le demande :

- Un outil automatise ne couvre qu'une partie des criteres : il decide ce qui est
  decidable par programme. Il voit un contraste insuffisant ou une etiquette absente ;
  il ne juge pas la pertinence d'une alternative textuelle, la logique de l'ordre de
  lecture, ni la clarte d'un intitule. **Un ecran a zero violation n'est pas un ecran
  conforme.**
- **Pas de test avec un lecteur d'ecran reel** (NVDA, VoiceOver, TalkBack), ni avec des
  personnes en situation de handicap. C'est la limite principale de mon travail sur ce
  sujet, et je ne la presente pas comme couverte.
- L'audit porte sur 19 ecrans (13 du back-office) et un seul role. Le back-office compte
  36 vues ; le tableau de bord du stock (`admin-ingredients`) fait partie des 19 ecrans
  mesures, mais ses sous-pages (reappro, ajustement, inventaire, mouvements), l'ecran de
  cuisine, les comptes et les roles n'y sont pas. Les autres roles et les autres ecrans
  sont couverts par le balayage, pas par axe.
- Un seul moteur de rendu (Chromium). Les couleurs calculees peuvent varier a la marge
  ailleurs, notamment sur les fonds composites.
- Un point de durcissement identifie et **non fait** : sur la modale, l'arriere-plan est
  masque aux technologies d'assistance par attribut, mais ses elements restent
  techniquement atteignables au clavier. L'attribut `inert` reglerait cela au niveau du
  navigateur, et rendrait le point verifiable par l'outil. C'est la prochaine etape.

Ce que je revendique : zero regression detectable automatiquement, sur les deux
interfaces, avec une barriere qui fait echouer la suite en nommant la regle si une
violation apparait.

**Criteres servis :** Cr 1.a (integration conforme), Cr 1.c (accessibilite),
Cr 1.e (semantique), Cr 2.a (JavaScript moderne), Cr 2.c (echanges asynchrones).

---

### Section E - Le front sous le capot et le choix des bibliotheques (15:00 - 19:00) - BLOC 1

**A dire (2 min) :**

- Le front de la borne est en HTML, CSS et JavaScript natifs : 3 311 lignes sur
  21 modules, sans framework, sans outil de construction, sans dependance livree au
  navigateur. Les modules ES6 sont charges nativement par le navigateur.
- Deux modules concentrent la logique reutilisable : `state.js` (panier en stockage
  local, montants en centimes entiers, echappement HTML centralise) et `data.js`
  (acces a l'API, avec memoisation de la promesse pour que plusieurs appelants
  simultanes partagent une seule requete reseau).
- Le panier ne declenche aucun appel reseau : rien ne part au serveur tant que le
  client n'a pas paye. C'est le point d'architecture qui amene la section suivante.

**A dire (2 min) - le critere sur les bibliotheques externes, traite de front :**

C'est un ecart connu, et il vaut la peine de l'ouvrir soi-meme.

> *"Le critere Cr 2.d demande d'integrer une bibliotheque JavaScript tierce. J'en ai
> integre une pour les modales, `a11y-dialog`, puis je l'ai **retiree** apres avoir relu
> le libelle exact du referentiel et pese le choix. La borne reste sans dependance
> livree au navigateur, pour trois raisons : la surface tient en cinq ecrans, la
> politique de securite du contenu du serveur de la borne interdit toute source de
> script tierce, et je voulais pouvoir auditer chaque ligne. Consequence assumee : le
> critere Cr 2.d.2, qui evalue la conformite d'une integration a la documentation d'une
> bibliotheque, n'a pas d'objet dans mon projet. Je le dis plutot que de le maquiller.
> En revanche Cr 2.d.3, qui porte sur ma capacite a expliquer le fonctionnement, je le
> couvre sur mes propres modules : demandez-moi d'expliquer la memoisation de promesse
> dans `data.js`."*

Nuance a apporter si le jury insiste : les outils de **mesure** (axe-core, PHPUnit,
PHPStan, Playwright) sont bien des dependances tierces, mais ils ne sont pas livres au
navigateur. Ce sont des outils de verification, pas du code de production.

La fiche complete de cet arbitrage, avec le niveau de confiance annonce comme faible,
est dans `docs/soutenance/preuves/05-librairies-js-c2d.md`.

**Ce qu'il faut savoir expliquer si on ouvre `data.js`** - la memoisation porte sur la
**promesse**, pas sur le resultat : plusieurs appelants declenches au meme moment
partagent alors une seule requete reseau, et la promesse est reinitialisee en cas
d'echec pour autoriser un nouvel essai. C'est le point de conception le plus fin de la
couche, et c'est celui a proposer au jury.

**Note orateur :** cette section est la premiere a compresser en cas de retard, mais
l'argument sur les bibliotheques doit passer. Si on compresse, garder ces deux minutes
et supprimer le reste.

---

### Section F - Le modele de donnees et l'API (19:00 - 23:00) - BLOC 2

**F.1 - Le modele (19:00 - 21:00)**

- **23 entites**, reparties en cinq domaines :
  - Catalogue : `category`, `product`, `menu`, `menu_slot`, `menu_slot_option`,
    `category_ingredient_family`
  - Ingredients et stock : `ingredient`, `product_ingredient`, `allergen`,
    `ingredient_allergen`, `stock_movement`
  - Commande : `customer_order`, `order_item`, `order_item_selection`,
    `order_item_modifier`
  - Droits : `user`, `role`, `permission`, `role_permission`, `role_visible_source`
  - Securite : `audit_log`, `login_throttle`, `pin_throttle`
- Le point a souligner : les trois entites du domaine securite viennent de la
  modelisation de la menace, pas du besoin fonctionnel. Un modele qui porte ses propres
  contre-mesures, c'est ce que veut dire "par conception".
- Construction de la base : **18 migrations et 10 jeux de donnees de reference**, tous
  idempotents et rejouables, suivis par une table de migrations. Le saut sur le numero
  0004 est une decision tracee, pas un oubli.
- Montrer le diagramme du modele conceptuel (`docs/merise/_diagrams/`).

**F.2 - L'API (21:00 - 23:00)**

- Routeur ecrit pour le projet (`src/app/Core/Router.php`) : une association entre
  methode HTTP et chemin d'un cote, controleur et action de l'autre.
  **158 routes declarees** (77 `GET`, 67 `POST`, 9 `PUT`, 5 `DELETE`), dans
  `src/app/Core/routes.php` (extrait de `src/public/admin/index.php` par la PR #175,
  pour que le routeur puisse etre reconstruit hors requete HTTP, ADR-0019). Les 9 `PUT`
  et 5 `DELETE` appartiennent tous a l'API JSON du back-office, portee par 10
  controleurs dedies sous `src/app/Controllers/Admin/Api/`, et tracee par la decision
  d'architecture 0017.
- Enveloppe de reponse uniforme : `{ "data": ... }` en succes,
  `{ "data": null, "error": { "code", "message" } }` en echec. Codes HTTP coherents :
  201 a la creation, 409 sur conflit, 422 sur validation, 403 sur droit refuse.
- Le trajet complet d'une requete, a savoir dire d'une traite :
  *"point d'entree, routeur, controleur, depot, base - puis retour en HTML ou en JSON."*

> **Attention, contradiction connue avec le dossier ecrit.** Le dossier affirme que "le
> contrat `PUT`/`DELETE` de l'API d'administration reste au stade prevu". C'est faux
> depuis les demandes de fusion #151 et #164 : le contrat est livre, teste et
> demontrable en collection Postman. Si le jury lit cette phrase, repondre :
> *"cette phrase du dossier a pris du retard sur le code ; l'API est livree, je vous la
> montre tout de suite."* Puis enchainer sur la demonstration - c'est une occasion, pas
> un probleme.

**Livre le 28/09 sur la page Sante de l'API** (`/admin/health`), a citer brievement si le
jury s'y arrete : le trajet d'une requete montre desormais la reponse reelle, lecture
comme ecriture, capturee depuis le systeme (et non simulee) ; la carte des routes est
rangee par action plutot que par methode HTTP brute (158 routes, 80 actions) ; les pages
d'erreur du back-office s'affichent en HTML lisible, l'API gardant, elle, du JSON ; et
`GET /admin/api/ingredients/{id}/movements` expose l'historique des mouvements de stock
d'un ingredient. Sources : `docs/journal/2026-09-28--trajet-reponses-reelles.md`,
`docs/api/conventions.md`.

**Criteres servis :** Cr 3.a (analyse et modele), Cr 3.b (construction de la base),
Cr 3.c (SQL), Cr 4.b (developpement serveur), Cr 4.c (heritage),
Cr 4.d (separation modele / vue / controleur).

---

### Section G - La securite par conception (23:00 - 30:00) - BLOC 2

> Deuxieme pilier de l'axe, et le moment le plus technique de l'oral.
> Structure : la methode, puis quatre mecanismes, puis la demonstration.

**G.1 - La methode, d'abord (23:00 - 24:30)**

Ce qui distingue une securite pensee d'une securite ajoutee, c'est qu'elle commence par
une analyse, pas par une liste de correctifs.

- Le dossier porte une **modelisation de la menace** (section 19) construite en trois
  niveaux : 5 frontieres de confiance, un registre de 9 risques, et une analyse par
  categories de menaces (usurpation, alteration, repudiation, divulgation, deni de
  service, elevation de privilege).
- Les donnees sont classees en 4 niveaux de sensibilite. Les empreintes de mot de passe
  et de code personnel sont au niveau le plus restreint : hors journaux, hors API.
- Le resultat de cette analyse, ce sont **22 regles transverses** (RG-T01 a RG-T22),
  chacune rattachee a un mecanisme concret - une colonne, une contrainte, une
  transaction - plutot qu'a une intention.
- Honnetete a poser tout de suite : les vraisemblances du registre sont evaluees a dire
  d'expert sur un projet fictif. Ce ne sont pas des mesures.

**G.2 - Quatre mecanismes, expliques a fond (24:30 - 27:00)**

Ne pas reciter les 22 regles. En choisir quatre et les tenir.

1. **Le serveur recalcule le prix (RG-T16, RG-T18).** Le message envoye par la borne ne
   transporte qu'un identifiant de produit et une quantite. Le prix est relu en base.
   Un client qui modifie le message ne change pas ce qu'il paie. Complement : une liste
   blanche de colonnes empeche d'injecter un champ non prevu, y compris `role_id`.
2. **Le stock se decremente de facon atomique, sans plancher (RG-T20).** A l'encaissement,
   chaque ingredient consomme par la commande est decremente par une seule instruction
   SQL (`UPDATE ingredient SET stock_quantity = stock_quantity - :u WHERE id = :id`),
   dans la meme transaction que le passage a `preparing`, et les ingredients sont
   traites dans un ordre stable (tri croissant sur `id`, `OrderRepository::consumption`)
   pour ecarter tout probleme d'ordonnancement entre deux encaissements concurrents. Il
   n'y a ni lecture prealable ni plancher : deux bornes qui vendent en meme temps le
   dernier article appliquent chacune leur decrement sans se bloquer, `stock_quantity`
   pouvant alors passer sous zero. A dire : *"le decrement ne s'arrete pas a zero par
   construction - c'est la disponibilite produit (RG-T21) qui masque, avant la
   commande, ce qui est deja en rupture ; elle ne protege pas deux commandes qui
   epuisent le meme article au meme instant. La survente residuelle est mesuree et
   remontee aux managers, pas empechee : une decision de gestion assumee, pas un
   oubli."*
3. **L'encaissement est idempotent (RG-T19).** Une cle d'idempotence unique en base :
   si le client retape "Payer", la seconde requete renvoie la commande existante au
   lieu d'en creer une deuxieme.
4. **Les actions sensibles demandent un code personnel, et laissent une trace
   (RG-T13, RG-T14, RG-T22).** Au comptoir, plusieurs equipiers se relaient sur la meme
   session. Une annulation de commande ou un changement de droits exige une
   re-autorisation par identifiant d'equipier plus code personnel ; la ligne de journal
   d'audit est ecrite **dans la meme transaction** que l'action - soit les deux, soit
   aucune. Un compteur de tentatives separe de celui de la connexion protege ce code.
   Le contenu de cette ligne est lui-meme minimise (RGPD art. 5.1.c) : depuis le
   2026-09-29, un code personnel faux n'ecrit plus l'adresse saisie au formulaire, mais
   seulement le contexte de l'action et, quand elle correspond a un compte existant, son
   identifiant stable (`PinGate::auditFailedPin()`, migration `0019`).

**G.3 - Le detail qui montre le niveau : le canal auxiliaire par le temps (27:00 - 28:00)**

C'est le morceau a garder pour un jury technique.

> *"Une page de connexion peut trahir l'existence d'un compte sans rien afficher :
> il suffit qu'elle reponde plus vite quand l'adresse est inconnue, parce qu'elle
> n'a pas eu a verifier d'empreinte. J'ai traite ce canal : sur un compte inconnu ou
> verrouille, le code verifie quand meme une empreinte leurre, et il incremente le
> meme compteur. Le leurre est calibre sur une empreinte **stockee**, pas sur la
> configuration courante - parce que c'est l'empreinte elle-meme qui porte son cout de
> calcul. J'ai mesure les quatre chemins : 257,4 ms, 251,4 ms, 253,3 ms, 252,6 ms.
> L'ecart maximal est de 6,0 ms, pour un bruit de mesure de 13,8 ms. L'ecart est sous
> le bruit : le temps ne distingue pas les chemins."*

Le code : `src/app/Auth/AuthService.php`, methode `authenticate`, et
`PasswordHasherInterface::verifyDecoy`. Les empreintes sont en argon2id.

Le meme raisonnement s'applique au compte **verrouille**. S'il ne payait pas le cout du
leurre, il repondrait plus vite qu'un compte inconnu. Et s'il n'incrementait pas le
compteur d'adresse, il n'atteindrait pas le seuil de blocage que, lui, un compte inconnu
finit par atteindre - ce qui suffirait a le distinguer. Les deux chemins font donc le
meme travail observable.

Le reste du socle, a citer en rafale si on a le temps : requetes preparees de bout en
bout sans emulation, echappement en sortie, cookies de session en `HttpOnly` +
`Secure` conditionnel + `SameSite=Strict`, jeton anti-falsification sur chaque ecriture
du back-office, politique de securite du contenu posee par le serveur web, recherche de
secrets sur tout l'historique a chaque demande de fusion.

**G.4 - Demonstration : les droits, en direct (28:00 - 30:00)**

Voir la section 4.2 pour le deroulement precis. L'idee a verbaliser :

> *"Le code ne teste pas un nom de role, il teste une permission. Je vais vous le
> montrer : le meme appel, avec deux comptes differents, donne 403 pour l'un et passe
> pour l'autre - et ce n'est pas moi qui l'affirme, c'est le serveur qui repond."*

**Criteres servis :** Cr 3.d (donnees personnelles), Cr 4.e (securite),
Cr 4.f (discipline de versionnement), Cr 4.g (livraison testee).

---

### Section H - Conteneurs, integration continue, deploiement (30:00 - 35:30) - BLOC 5

> Troisieme pilier. C'est le bloc choisi en option, et celui qui correspond a mon
> parcours : le dire.

**H.1 - Les conteneurs (30:00 - 32:00)**

- **5 services** : `wakdo-web` (Apache), `wakdo-app` (PHP-FPM 8.3), `wakdo-db`
  (MariaDB 11.4), `wakdo-cron` (taches planifiees), et `wakdo-migrate`, un service a
  **execution unique** qui applique les migrations puis les jeux de donnees, puis
  s'arrete.
- Ce service a execution unique est le point a defendre : il fait qu'une seule commande,
  `docker compose up -d`, monte la base, la met a niveau, la remplit et demarre
  l'application. Parce que les migrations sont idempotentes, la relancer ne casse rien.
- Un seul reseau Docker (`wakdo_internal`, `docker-compose.yml`) : la base n'est pas
  exposee non pas par une segmentation reseau, mais faute de section `ports` sur
  `wakdo-db` ; seul `wakdo-web` publie un port sur l'hote.
- Pas de machine virtuelle : choix assume, les conteneurs suffisent pour ce perimetre.
- **4 taches planifiees actives** (plus 3 modeles commentes, laisses pour la suite) dans
  une fenetre de maintenance 01h30-09h30, choisie parce que le service client ferme a
  01h00 : expiration des commandes en attente a 02h00, sauvegarde de la base a 03h00,
  purge du journal d'audit a 04h15, purge des compteurs de tentatives a 04h45.
  L'expiration est placee **avant** la sauvegarde pour que le cliche de la nuit
  contienne l'etat nettoye - c'est le genre d'arbitrage a dire. La sauvegarde verifie sa
  propre taille minimale avant de se declarer reussie.

**H.2 - L'integration continue (32:00 - 34:00)**

- **5 travaux** sur Forgejo Actions, a chaque demande de fusion :
  1. `secret-scan` - recherche de secrets sur tout l'historique (gitleaks 8.21.2).
  2. `php-lint` - controle de syntaxe sur chaque fichier PHP.
  3. `static-tests` - PHPStan niveau 6, puis PHPUnit avec une base MariaDB ephemere
     reellement migree et remplie.
  4. `js-tests` - la suite de la borne.
  5. `shell-tests` - les fonctions pures du filet de la demo (instantane + remise a
     zero), sans conteneur ni base.
- **Deux travaux supplementaires, separes, pour le deploiement** (`deploy.yml`) :
  declenches sur `main`, ils portent la livraison continue vers l'hote de production
  (detail en H.3 : pourquoi deux travaux et pas un).
- **Le detail a mettre en avant** : PHPUnit tourne avec `--fail-on-skipped`. Sans base,
  les tests d'integration s'auto-ignoreraient et le pipeline passerait au vert en ayant
  saute precisement les chemins de securite. Avec ce drapeau, un test ignore fait
  echouer l'integration. A dire : *"un vert qui ment coute plus cher qu'un rouge. J'ai
  ferme cette porte."*
- La configuration de tests refuse aussi les tests douteux, les avertissements et les
  tests qui n'assertent rien (`failOnRisky`, `failOnWarning`,
  `beStrictAboutTestsThatDoNotTestAnything`).
- Les outils sont figes a une version precise (PHPUnit 11.5.2, PHPStan 1.12.27,
  gitleaks 8.21.2) : un pipeline reproductible ne suit pas la derniere version publiee.

**H.3 - Le deploiement (34:00 - 35:30)**

- Le deploiement part sur chaque arrivee dans `main`, et peut etre relance a la demande.
- **La contrainte reelle, a expliquer telle quelle** : le projet tourne sur un hote
  unique, qui porte a la fois la production et l'executeur d'integration. L'executeur
  dispose du socket Docker - il en a besoin pour lancer ses conteneurs - mais il ne le
  transmet pas aux travaux. Lui donner reviendrait a donner les pleins pouvoirs sur
  l'hote a tout code qui passe en integration. Le deploiement ne pilote donc pas Docker :
  il **demande** a l'hote de se deployer, par un canal qui ne peut declencher qu'une
  seule commande.
- Le deploiement est scinde en deux travaux, deliberement : le serveur Forgejo de ce
  projet n'expose pas les journaux de travaux par son interface de programmation, donc
  seul le statut est lisible. Separer la verification de la cle du deploiement lui-meme
  fait porter le diagnostic par **le nom du travail rouge**. Methode utilisee cinq fois
  dans une meme session, decisive a chaque fois.
- La verification de cle compare la partie **publique** derivee du secret a celle
  autorisee sur l'hote : si le secret est tronque ou mal colle, l'echec est immediat et
  nomme, au lieu d'une erreur de connexion muette plus loin.
- Apres bascule, le deploiement interroge `/api/health` pendant jusqu'a deux minutes
  avant de se declarer reussi.

**Note orateur :** cette section est celle ou mon parcours d'administrateur systeme se
voit. Les deux arbitrages a raconter - ne pas transmettre le socket, et decouper pour
diagnostiquer sans journaux - sont des raisonnements d'exploitation, pas de
developpement. C'est la qu'il faut ralentir.

**Criteres servis :** Cr 7.a (analyse infrastructure et securite), Cr 7.b (scripts et
taches planifiees), Cr 7.c (conteneurisation, une commande), Cr 7.d (integration et
deploiement continus).

---

### Section I - Limites assumees et conclusion (35:30 - 37:00)

**A dire - la liste, sans la deguiser (1 min) :**

Cette liste est le contenu de la section 6. La mettre a l'ecran et la lire.

> *"Avant de conclure, voici ce que je n'ai pas fait, pour que vous n'ayez pas a le
> chercher."*

**Conclusion (30 s) :**

Reprendre les trois piliers en une phrase chacune, puis :

> *"L'accessibilite, je la mesure. La securite, je la modelise avant de la coder. La
> chaine de livraison, elle verifie les deux a chaque modification. Je reste a votre
> disposition pour ouvrir n'importe quel fichier et modifier n'importe quelle partie
> devant vous."*

---

## 4. La demonstration en direct

Deux demonstrations, quatre minutes chacune environ, plus une demonstration des droits
de deux minutes. Chacune a son repli.

### 4.1 Demonstration 1 - la borne (dans la section D, 08:00 - 12:00)

**Ordre des ecrans et reperes de temps :**

| Repere | Ecran | Ce que je dis en le faisant |
|---|---|---|
| 08:00 | Accueil | Taille de cible, puis bascule de police adaptee et rechargement pour montrer la persistance |
| 08:40 | Categories | Lien d'evitement en premier element, navigation au clavier |
| 09:20 | Produits | Rupture signalee par trois canaux, pas par la seule couleur |
| 10:00 | Composition d'un menu | Boucle de tabulation et fermeture a l'echappement dans la modale de composition |
| 11:00 | Panier | Mouvement reduit respecte sur le total anime |
| 11:30 | Paiement et confirmation | Numero de commande, remise a zero |

**Repli, par ordre de preference :**

1. La borne de production ne repond pas -> basculer sur une pile locale
   (`docker compose up -d`), lancee **avant** l'oral et laissee ouverte dans un onglet.
2. Aucune pile ne repond -> les captures de
   `docs/soutenance/preuves/captures-responsive/` et `docs/design/screens/`. Commenter
   chaque capture avec le meme texte.
3. Ne pas s'excuser plus d'une phrase. Dire : *"je passe sur les captures, le propos
   ne change pas"*, et enchainer.

**Avertissement sur les captures :** les captures d'annexe du dossier datent du
24 septembre, et l'interface du back-office a ete refondue le 26. Si on montre une
capture de back-office, le jury peut voir autre chose que l'ecran reel. **A recapturer
avant l'oral**, ou a ne montrer que les captures de la borne.

**A preparer la veille :** les deux onglets ouverts, la pile locale deja demarree, les
captures ouvertes dans un troisieme onglet.

### 4.2 Demonstration 2 - l'API et les droits (dans la section G, 28:00 - 30:00)

L'outil de demonstration en direct est **Insomnia**, avec une collection construite par
l'auteur lui-meme. Les deux collections livrees dans `docs/api/` restent la reference et
le point de depart : une pour Postman (`wakdo-admin.postman_collection.json`) et une au
format Bruno (`docs/api/bruno/`) ; toutes deux sont importables dans Insomnia (Postman
directement, Bruno via ses fichiers `.bru`). Le mode d'emploi de reference, ecrit pour
Postman et Bruno, est dans `docs/api/demo-api.md` : les noms de variables d'environnement
(`baseUrl`, `email`, `csrf`, `pin_email`, `pin`...) et l'enchainement des requetes s'y
retrouvent a l'identique dans la collection Insomnia.

**Deroulement, en quatre gestes qui representent six requetes HTTP (les deux derniers
gestes enchainent chacun une reconnexion puis une action) :**

| Repere | Geste | Requetes | Ce que ca montre |
|---|---|---|---|
| 28:00 | Connexion administrateur | 1. `POST {{ _.baseUrl }}/admin/api/auth/login` (compte admin) | La connexion JSON, le cookie de session, le jeton anti-falsification renvoye |
| 28:30 | Lecture des statistiques | 2. `GET {{ _.baseUrl }}/admin/api/stats` | Une lecture autorisee, enveloppe `{ "data": ... }` |
| 29:00 | Cuisine tente d'annuler | 3. `POST {{ _.baseUrl }}/admin/api/auth/login` (compte cuisine) puis 4. `POST {{ _.baseUrl }}/admin/api/orders/K1/cancel` | **403** : la cuisine *voit* les commandes (`order.read`) mais ne peut pas les annuler — la permission est decoupee au geste pres, et c'est le code qui la verifie, pas le nom du role |
| 29:30 | Comptoir cree une commande vide | 5. `POST {{ _.baseUrl }}/admin/api/auth/login` (compte comptoir) puis 6. `POST {{ _.baseUrl }}/admin/api/orders` avec `{"items": []}` | **422** et non 403 : la permission passe, c'est la validation qui refuse |

**La requete du geste 29:00, telle qu'a preparer dans Insomnia :**

```
POST {{ _.baseUrl }}/admin/api/orders/K1/cancel
Headers: X-CSRF-Token: {{ _.csrf }}
Body (JSON): { "pin_email": "<email du compte cuisine>", "pin": "<PIN du compte cuisine>" }
```

Connecte avec le compte cuisine (role `kitchen`), cette requete rend **403 FORBIDDEN**
(`"Permission manquante"`). La raison tient a l'ordre des controles dans
`OrderApiController::apiCancel` : `guardApi('order.cancel')` (l.252-254) s'execute AVANT
toute recherche de la commande et avant la verification du jeton CSRF — le role `kitchen`
ne recoit que `order.read` au seed (`db/seeds/0001_rbac_and_reference.sql`), pas
`order.cancel`, donc c'est le controle de permission qui refuse, pas une verification sur
la commande `K1` elle-meme (celle-ci n'est meme pas recherchee a ce stade). A l'inverse,
un numero de commande VIDE ne peut pas produire ce 403 : le segment de route `{number}` est
compile en `(?P<number>[^/]+)` (`Router::compile`, `src/app/Core/Router.php`), qui exige au
moins un caractere hors `/` ; une URL avec un segment vide (`/admin/api/orders//cancel`) ne
correspond a aucune route enregistree, donc le routeur renvoie **404** avant meme
d'instancier le controleur (`Router::dispatch`, branche `pathMatched === false`) — ce 404
vient du routeur, pas de la logique metier de `apiCancel`.

Le quatrieme geste (comptoir, commande vide) est le plus fin : il montre qu'une permission
est accordee **sans** creer de commande reelle, donc sans effet de bord sur la base de
demonstration. La difference entre les deux codes tient a l'ordre : le controle de droit
s'execute avant la validation du corps. Le test
`OrderApiControllerTest::testStoreWithoutPermissionReturns403EvenWithEmptyItems` verifie
qu'un role sans la permission recoit bien 403 sur la meme requete.

Si le temps le permet, ajouter un `PUT` ou un `DELETE` : c'est precisement la partie que
le dossier ecrit declare a tort comme non livree (voir section F.2).

Les comptes de demonstration, un par poste, sont dans `docs/demo/comptes-demo.md`
(administrateur, responsable, cuisine, comptoir, second comptoir, drive) et la matrice
complete des droits dans `docs/demo/matrice-rbac.md`. Ne pas lire les identifiants a
voix haute : les afficher si le jury veut se connecter lui-meme.

**Pourquoi un second compte comptoir** - a dire si on a 20 secondes : deux equipiers du
**meme role**, avec des codes personnels differents. Le journal d'audit distingue lequel
des deux a agi, alors que leurs droits sont identiques. C'est la difference entre savoir
quel role a agi et savoir **qui** a agi.

**Repli :**

1. Insomnia ne demarre pas -> la meme sequence en ligne de commande avec
   `curl`, preparee dans un fichier texte a copier-coller.
2. L'API de production ne repond pas -> la pile locale.
3. Rien ne repond -> ouvrir le test
   `OrderApiControllerTest::testStoreWithoutPermissionReturns403EvenWithEmptyItems` et
   le lire a l'ecran. Un test qui exprime la regle vaut demonstration, a condition de
   dire qu'il tourne a chaque demande de fusion.

**A preparer la veille :** collection Insomnia construite et importee, environnement
renseigne (`baseUrl`, comptes, `pin_email`/`pin`), les six requetes ci-dessus deja
ouvertes dans l'ordre, et la sequence `curl` de secours dans un fichier.

**Precaution sur la collection Bruno du depot** (si on l'ouvre directement, en plus ou a
la place d'Insomnia, pour verifier la reference) : cet outil ecrit sur disque les
variables posees par un script, y compris dans le fichier d'environnement versionne.
Lancer cette collection sur une **copie** hors du depot (`--env-file`), comme decrit dans
`docs/api/demo-api.md`. La collection Insomnia de la demonstration, elle, est un espace
de travail personnel non versionne : ce risque particulier ne s'y applique pas, mais toute
valeur y restant apres l'oral (jeton, cookie) doit etre effacee comme pour tout outil.

### 4.3 Demonstration 3 - modifier du code en direct (sur demande du jury)

Elle n'est pas planifiee dans les 40 minutes : elle arrive en questions. La preparation
est en section 8. La proposer soi-meme si la question sur l'IA tombe.

### 4.4 Regles communes aux demonstrations

- Tout ouvrir **avant** de commencer : rien ne se lance pendant l'oral.
- Avoir les captures et les collections **en local**, sans dependre du reseau.
- Ecarter toute commande destructive sur l'hote de production. En particulier, ne pas
  utiliser `docker compose down -v` : les deux fichiers de composition partagent le meme
  projet et ses volumes, et le drapeau supprimerait la base et les images envoyees.
- Verifier la veille sans rien modifier : l'etat des services, la reponse de
  `/api/health`, puis la borne et le back-office.

---

## 5. Correspondance competences et moments de l'oral

| Bloc | Critere | Demontre en | Preuve montrable |
|---|---|---|---|
| B1 | Cr 1.a integration conforme | D.1 | Borne en direct + maquette |
| B1 | Cr 1.c accessibilite | D.1, D.2 | Rapport axe-core, 0 violation sur 19 ecrans |
| B1 | Cr 1.e semantique | D.1 | Source HTML, lien d'evitement en premier element |
| B1 | Cr 2.a JavaScript moderne | E | 21 modules ES6 natifs |
| B1 | Cr 2.b validation de formulaire | G.2 | Validation serveur (RG-T18) |
| B1 | Cr 2.c echanges asynchrones | E | `data.js`, memoisation de promesse |
| B1 | Cr 2.d bibliotheques externes | E | **Non couvert au sens strict, argumente** |
| B2 | Cr 3.a analyse et modele | F.1 | Dictionnaire, 23 entites |
| B2 | Cr 3.b construction de la base | F.1, H.1 | 18 migrations, 10 jeux de donnees |
| B2 | Cr 3.c SQL | G.2 | Decrement atomique, depots PDO |
| B2 | Cr 3.d donnees personnelles | G.1 | Classification 4 niveaux, anonymisation |
| B2 | Cr 4.b developpement serveur | F.2 | 158 routes, API JSON complete |
| B2 | Cr 4.c heritage | F.2 | Hierarchie des controleurs a 4 niveaux |
| B2 | Cr 4.d separation des responsabilites | F.2 | Controleur, depot, vue |
| B2 | Cr 4.e securite | G | 22 regles transverses, modelisation de la menace |
| B2 | Cr 4.f versionnement | C | 242 commits, 192 demandes de fusion au 28/09 (244 / 194 au 29/09) |
| B2 | Cr 4.g livraison testee | H.2 | Suites PHP et JS, PHPStan niveau 6 |
| B5 | Cr 7.a analyse infrastructure | H.3 | Arbitrage sur le socket Docker |
| B5 | Cr 7.b scripts et taches planifiees | H.1 | 4 taches actives, scripts de migration |
| B5 | Cr 7.c conteneurisation | H.1 | 5 services, une seule commande |
| B5 | Cr 7.d integration et deploiement | H.2, H.3 | 5 travaux, deploiement en deux etapes |

---

## 6. Les limites, nommees avant que le jury ne les trouve

> Un trou annonce coute moins cher qu'un trou decouvert. Cette liste est a dire en
> section I, et a re-servir telle quelle si une question s'en approche.

### 6.1 Ce qui n'est pas couvert

| Limite | Statut | Ce que je reponds |
|---|---|---|
| Bibliotheque JavaScript tierce (Cr 2.d.2) | Non couvert au sens strict | Integree puis retiree sur decision. Motifs : perimetre, politique de securite du contenu, controle du code. Confiance annoncee faible dans `05-librairies-js-c2d.md`. |
| Test avec un lecteur d'ecran reel ou des utilisateurs en situation de handicap | Non fait | L'audit automatise ne couvre qu'une partie des criteres. Je ne presente pas une conformite, je presente une absence de regression detectable. |
| Couverture de tests chiffree | Non mesuree | L'instrumentation de couverture n'est pas activee. J'ai des nombres de tests et un choix argumente de ce qui est teste, pas un pourcentage. |
| Audit d'accessibilite sur toute l'application | Partiel | 13 ecrans du back-office sur 36 vues (plus 6 ecrans de la borne), et un seul role pour axe. Les autres roles passent par le balayage. |
| Piege a tabulation verifiable par l'outil | Identifie, non fait | L'arriere-plan des modales est masque par attribut mais reste atteignable au clavier. L'attribut `inert` est la prochaine etape. |
| Paiement bancaire reel | Hors perimetre | Simule par un numero de commande. Le reste du flux (recalcul du prix, stock, idempotence) est reel. |
| Multi-restaurants, multi-bornes | Hors perimetre structurel | Le modele ne porte pas d'identifiant d'etablissement. Ce n'est pas un reglage a activer. |
| Supervision, orchestration, pre-production, deploiement sans interruption | Hors perimetre | Hote unique, et budget d'heures. |
| Montee en charge horizontale | Limite technique reelle | Les sessions sont des fichiers dans le conteneur applicatif. Detail en question Q4.4. |
| Politique de securite du contenu du back-office | Moins stricte que celle de la borne | Le serveur de la borne interdit toute source de script tierce et les styles en ligne. Celui du back-office autorise les styles en ligne, a cause du rendu serveur des vues. Ecart connu. |
| Validation W3C du back-office | Non faite | La campagne de validation porte sur la borne statique seulement. |
| Zoom et redimensionnement du texte | Non evalues | La borne vise un ecran fixe en orientation portrait. Choix d'ergonomie a assumer. |

### 6.2 Les ecarts entre les documents ecrits et le code

**C'est la section a relire le plus attentivement.** Le code a beaucoup bouge entre le
22 et le 26 septembre (20 demandes de fusion), et les documents n'ont pas tous suivi. Si
le jury lit le dossier pendant que je parle, il peut tomber sur l'un de ces points. La
bonne reponse est *"le code fait foi, et voici pourquoi le document a pris du retard"*.

| Ecart | Le document dit | Le code dit |
|---|---|---|
| **API `PUT`/`DELETE`** | "reste au stade prevu" (dossier, C4.b) | **Livree** : 57 routes sous `/admin/api/`, dont 9 `PUT` et 5 `DELETE`, 10 controleurs dedies, decision d'architecture 0017. **C'est l'ecart le plus important** : le dossier declare absente une fonctionnalite demontrable. |
| Nombre de tests | 755 PHP / 233 JS (dossier, deux endroits) | Derniere execution mesuree (28/09/2026, commit `04bece0`) : 2 386 PHP / 7 886 assertions / 446 JS. Comptage statique aujourd'hui : 1 295 methodes / 450 appels. |
| Decisions d'architecture | "seize" (dossier, quatre endroits) | **20**. Les 0018-0020 portent les familles d'ingredients, la page Sante de l'API et l'annulation par le responsable. |
| Migrations | "dix fichiers, 0001 a 0011" (dossier, C3.b) | **18 fichiers**, 0001 a 0019 (sans 0004). Le saut sur 0004 reste volontaire et trace. |
| Fichiers PHP de l'application | "103 fichiers" avec une repartition detaillee | **135** sous `src/app`, dont **33 controleurs + 1 trait** (23 au premier niveau + 10 controleurs et 1 trait `JsonApiTrait` sous `Admin/Api`). |
| Modules de la borne | "18 modules, 2 979 lignes" | **21 modules, 3 311 lignes**. |
| Feuille de style du back-office | "2 714 lignes" | **3 470 lignes** (refonte du 26 septembre, complements du 28). |
| Operations du modele de traitements | "28 operations" | **40**, depuis la version 0.5 (`docs/merise/mct.md` §14). |
| Dependances de developpement | "deux" | **trois** (`@axe-core/playwright` en plus). Le fond tient : zero dependance de production. |
| Nombre d'entites | 21 entites classifiees (dossier, section 19.4) | **23**. `pin_throttle` et `category_ingredient_family` sont desormais classees en INTERNAL (`PROJECT_CONTEXT.md` 19.4, corrige). |
| Empreintes de mot de passe | "bcrypt ou argon2" (dossier, sections 7, 16, 18) | **argon2id**, et le dossier lui-meme l'ecrit en section 19.3. |
| Services conteneurises | 4 en section 16, 5 ailleurs | **5**, dont un a execution unique. |
| Regles transverses | "RG-T13 a RG-T21" en introduction de la section 19 | **22 regles**, RG-T22 comprise. |
| Auditabilite des echanges avec l'IA | Annoncee en section 17.9 | Les journaux ne sont pas versionnes (section 17.6). Ne pas promettre cette preuve. |

**Un point deja regle, un point restant a verifier avant le 5 octobre** :

1. **Regle.** Le dossier indiquait que la production servait encore le mode de deboguage
   detaille et que `/api/health` annonçait un environnement de developpement. C'est
   desormais un fait etabli et verifiable en direct : `/api/health` annonce
   `"app_env":"production"` (`src/app/Controllers/HealthController.php:47`). Le dossier
   reste a corriger sur ce point s'il n'est pas deja mis a jour.
2. **A verifier.** Les captures d'annexe du back-office datent du 24 septembre, avant la
   refonte du 26. Les recapturer, ou dater la mesure dans le dossier.

### 6.3 Ce que je revendique quand meme

Pour ne pas finir la liste sur une note basse, enchainer :

> *"Ces ecarts sont des retards de documentation sur un code qui a continue d'avancer -
> dans le sens ou le code en fait plus que le document ne le dit, pas l'inverse. Je les
> ai repertories plutot que de les laisser se faire trouver. Ce qui est mesure -
> l'accessibilite, les tests, l'analyse statique - l'est sur le code d'aujourd'hui."*

---

## 7. Les 40 minutes de questions

> 54 questions en 9 familles. Chaque reponse tient en 3 a 5 lignes et s'appuie sur un
> fait verifiable. Les 11 questions marquees **(rude)** sont celles qu'on prefere ne pas
> voir venir : ce sont celles a preparer en priorite.
>
> Les 40 minutes de questions ne permettront pas de toutes les couvrir. Si le temps
> manque pour reviser, prendre les 11 questions rudes, puis la famille 7.8 (usage de
> l'IA) et la famille 7.9 (ecarts au sujet) : ce sont celles ou une hesitation coute le
> plus cher.

### 7.1 Architecture

**Q1.1 - Comment une requete traverse votre application ?**
Point d'entree `src/public/admin/index.php`, qui charge les 158 routes declarees dans
`src/app/Core/routes.php` (extrait de `index.php` par la PR #175, pour que le routeur
puisse etre reconstruit hors requete HTTP, ADR-0019). Le routeur (`src/app/Core/Router.php`)
compile chaque chemin, compare methode et chemin, et distingue 404 (chemin inconnu) de 405
(chemin connu, mauvaise methode). Il instancie le controleur et appelle l'action. Le
controleur verifie la permission, valide les entrees, appelle le depot ; le depot parle a
la base en requete preparee. Retour en HTML ou en JSON.

**Q1.2 - Ou est la separation modele / vue / controleur ?**
Les depots (11 classes `Repository`) portent l'acces aux donnees et ne contiennent pas
de HTML. Les vues sont sous `src/app/Views/` (41 fichiers : 36 vues de back-office, 3
vues d'authentification, 2 gabarits de page). Les controleurs sont sous
`src/app/Controllers/`. Formule : *"le controleur ne sait pas parler SQL et ne sait pas
dessiner du HTML ; il coordonne."*

**Q1.3 - Ou est l'heritage dans votre code ?**
Une hierarchie a quatre niveaux. `src/app/Core/Controller.php` est abstraite ;
`AuthenticatedController` en herite et ajoute la verification de session ; `AdminController`
herite a son tour d'`AuthenticatedController` et ajoute le controle de permission, le rendu
de vue et les messages ; les controleurs concrets heritent de l'un des trois. Sur les 23
fichiers du premier niveau : 2 abstraits (`AdminController`, `AuthenticatedController`),
14 qui etendent `AdminController`, 6 qui etendent `Controller` directement, 1
(`MeController`) qui etend `AuthenticatedController` directement. C'est le critere Cr 4.c.

**Q1.4 - Pourquoi un routeur ecrit a la main plutot qu'une bibliotheque ?**
Le sujet du Bloc 2 demande une realisation sans framework. Ecrire le routeur m'obligeait
a traiter moi-meme la distinction 404/405, les segments dynamiques et l'ordre de
resolution - trois choses qu'une bibliotheque aurait masquees. C'est trace dans la
decision d'architecture 0001.

### 7.2 Securite

**Q2.1 - Comment empechez-vous un client de modifier le prix ?**
Le message envoye ne contient qu'un identifiant de produit et une quantite. Le serveur
relit le prix en base et recalcule (RG-T16, RG-T18). Une liste blanche de colonnes
empeche en plus d'injecter un champ non prevu, `role_id` compris.

**Q2.2 - Et si deux bornes commandent le dernier article en meme temps ?**
Le decrement est un seul ordre SQL par ingredient, sans plancher
(`UPDATE ingredient SET stock_quantity = stock_quantity - :u WHERE id = :id`), dans la
meme transaction que le changement d'etat de la commande (RG-T20). Les deux
encaissements appliquent chacun leur delta sans se bloquer : ni lecture-gate ni conflit
SQL, et `stock_quantity` peut passer sous zero. Ce que RG-T21 empeche, c'est de
commander un produit deja en rupture avant l'encaissement (la disponibilite compare le
stock au seuil critique) ; ca ne protege pas deux commandes simultanees sur le dernier
exemplaire. Nuance honnete : le registre des risques (`PROJECT_CONTEXT.md`, risque R5)
classe le risque residuel de survente comme moyen et **accepte** - la survente est
mesuree, pas empechee, c'est une decision metier.

**Q2.3 - Que se passe-t-il si le client double-clique sur Payer ?**
Une cle d'idempotence unique en base (RG-T19). La seconde requete renvoie la commande
existante au lieu d'en creer une nouvelle.

**Q2.4 - Comment gerez-vous les droits ?**
Le code teste une **permission**, pas un nom de role : `$this->guard('category.manage')`
en tete de chaque action sensible. 5 roles, 23 permissions, liees en base et rechargees
a chaque requete - donc retirer un droit prend effet sans re-connexion. Je peux le
demontrer en direct avec deux comptes.

**Q2.5 - Comment stockez-vous les mots de passe ?** **(rude si le dossier dit bcrypt)**
argon2id, avec un cout memoire de 65 536 et un cout en temps de 4. Attention : le
document de cadrage ecrit encore "bcrypt ou argon2" a deux endroits - c'est un retard de
documentation, le code et la section 19.3 du dossier disent argon2id. Les empreintes de
mot de passe et de code personnel sont classees au niveau le plus restreint : hors
journaux, hors API.

**Q2.6 - Votre page de connexion revele-t-elle si un compte existe ?**
Non, et je l'ai mesure. Message d'erreur identique dans tous les cas ; sur un compte
inconnu ou verrouille, le code verifie quand meme une empreinte leurre et incremente le
meme compteur. Quatre chemins mesures : 257,4 / 251,4 / 253,3 / 252,6 ms. Ecart maximal
6,0 ms pour un bruit de 13,8 ms - l'ecart est sous le bruit.

**Q2.7 - Pourquoi un code personnel en plus du mot de passe ?**
Au comptoir, plusieurs equipiers se relaient sur la meme session ouverte. Le mot de passe
identifie la session, le code personnel identifie **qui agit** au moment d'une action
sensible. La ligne d'audit est ecrite dans la meme transaction que l'action : soit les
deux, soit aucune (RG-T13, RG-T14). Un compteur de tentatives separe protege ce code
(RG-T22).

**Q2.8 - Qu'avez-vous fait contre l'injection SQL et le script inter-sites ?**
Requetes preparees PDO, sans emulation, valeurs liees et non concatenees (RG-T06). Pour
les identifiants dynamiques d'un tri, qui ne peuvent pas etre lies comme des valeurs, une
liste blanche de jetons (RG-T17). Cote affichage, echappement en sortie :
`htmlspecialchars` au back-office, `textContent` sur la borne, avec une fonction
d'echappement centralisee dans `state.js` (RG-T15).

**Q2.9 - Le mot de passe administrateur est en clair dans un fichier du depot. Pourquoi ?** **(rude)**
Parce que c'est voulu et documente. La production est un site de **demonstration** pour
cette soutenance : `docs/demo/comptes-demo.md` publie un compte par poste pour que vous
puissiez vous connecter vous-meme et constater ce que les droits autorisent et refusent,
plutot que de me croire sur parole. Ce ne sont pas des identifiants d'exploitation : le
risque assume est qu'on abime des donnees de demonstration, pas qu'on accede a des
donnees reelles. Les vrais secrets, eux, sont hors du depot - dans un fichier
d'environnement non versionne et un coffre auto-heberge - et une recherche de secrets
tourne sur tout l'historique a chaque demande de fusion.

**Q2.10 - Et le respect du reglement sur les donnees personnelles ?**
Classification en 4 niveaux ; les donnees identifiantes sont au niveau confidentiel.
La suppression d'un compte se fait par anonymisation plutot que par effacement dur, pour
preserver l'integrite des commandes passees : l'adresse devient une valeur neutre, la
ligne reste, une date d'anonymisation est posee (decision d'architecture 0007). Le role
est denormalise dans le journal d'audit pour qu'il survive a l'anonymisation. Purges de
retention automatisees : journal d'audit environ 12 mois, compteurs de tentatives 24 h.

### 7.3 Accessibilite

**Q3.1 - Qu'est-ce qui vous permet de dire que votre site est accessible ?**
Je ne dis pas qu'il est accessible : je dis qu'il ne porte aucune violation detectable
automatiquement. axe-core 4.13.0 sur les regles WCAG 2.0 A/AA et 2.1 A/AA, 19 ecrans,
0 violation toutes gravites, 935 rapports de contraste, 0 sous le seuil. Et 4 807
verifications sans echec au balayage de mise en page, sur 5 roles et 4 largeurs.

**Q3.2 - Quelle est la limite de votre audit ?** **(rude)**
Un outil automatise ne decide que ce qui est decidable par programme. Il voit un
contraste insuffisant ou une etiquette absente ; il ne juge pas la pertinence d'une
alternative textuelle, ni l'ordre de lecture, ni la clarte d'un intitule. Un ecran a
zero violation n'est pas un ecran conforme. Et je n'ai pas teste avec un lecteur d'ecran
reel ni avec des personnes en situation de handicap.

**Q3.3 - Donnez un exemple concret d'accessibilite pensee des la conception.**
La preference systeme de mouvement reduit. Une regle universelle dans les deux feuilles
de style ramene toute animation a une duree negligeable quand l'utilisateur a demande
moins de mouvement, et le module d'animation du total interroge la meme preference en
JavaScript. Detail a citer : la duree est ramenee a 0,01 ms et non a zero, pour que
l'evenement de fin d'animation se declenche quand meme - sinon du code qui l'attend
resterait bloque.

**Q3.4 - Comment garantissez-vous que l'accessibilite ne regresse pas ?**
L'audit est rejoue a chaque execution de la suite de bout en bout, avec une barriere qui
fait echouer le test en **nommant** la regle si une violation apparait. La liste des
tolerances acceptees est vide aujourd'hui sur les 19 ecrans. La trajectoire le montre :
104 echecs avant la refonte, 11 apres le premier lot, 0 aujourd'hui.

**Q3.5 - Vos cibles tactiles font quelle taille ?**
Sur la borne, les boutons de quantite font 56 px de cote, avec un plancher a 44 px sur
les elements interactifs. Sur le back-office, le plancher applique est celui du critere
WCAG 2.2 sur la taille des cibles : 24 px, avec ses trois exceptions implementees - lien
en ligne dans une phrase, controle natif dessine par le navigateur, et exception
d'espacement. La borne est plus genereuse parce qu'elle s'utilise debout.

**Q3.6 - Comment signalez-vous une rupture de stock sans utiliser que la couleur ?**
Trois signaux : le grisage, un badge texte "Indisponible", et le suffixe correspondant
dans l'intitule accessible du bouton, plus l'etat desactive. Une personne daltonienne
recoit la meme information que les autres. Meme principe sur la categorie active, qui
porte une bordure **et** un fond, pas seulement une couleur de bordure.

**Q3.7 - Ces mesures ont-elles trouve des defauts, ou confirment-elles ce que vous saviez ?** **(bonne question, y aller franchement)**
Elles ont trouve des defauts, et c'est leur interet. Trois exemples : la preference de
mouvement reduit etait absente de l'ensemble du CSS avant ce lot ; une cible du
back-office mesurait environ 26 par 20 px a 390 px de large, donc sous le seuil ; et un
jeton de couleur corrige sur la page qui avait echoue retombait sous le seuil sur un
autre ecran, que j'ai trouve en relisant tous ses usages. Sans mesure, ces trois-la
passaient.

### 7.4 DevOps

**Q4.1 - Comment deploie-t-on votre application ?**
Une seule commande : `docker compose up -d`. Un service a execution unique applique les
18 migrations et les 10 jeux de donnees, tous idempotents, puis s'arrete ; l'application
demarre ensuite. En production, le deploiement part sur chaque arrivee dans `main` et
peut etre relance a la demande.

**Q4.2 - Pourquoi l'integration ne pilote-t-elle pas Docker directement ?** **(rude, et c'est la bonne question)**
Parce que l'executeur d'integration et la production sont sur le meme hote. L'executeur
dispose du socket Docker - il en a besoin pour lancer ses conteneurs - mais il ne le
transmet pas aux travaux. Lui donner reviendrait a donner les pleins pouvoirs sur l'hote
a tout code qui passe en integration, y compris celui d'une demande de fusion. Le
deploiement **demande** donc a l'hote de se deployer, par un canal qui ne peut declencher
qu'une seule commande.

**Q4.3 - Que verifie votre integration continue, exactement ?**
Cinq travaux : recherche de secrets sur tout l'historique, controle de syntaxe PHP,
puis PHPStan niveau 6 et PHPUnit sur une base MariaDB ephemere reellement migree, la
suite JavaScript, et les fonctions pures du filet de la demo (shell). Le point important
est le drapeau `--fail-on-skipped` : sans base,
les tests d'integration s'auto-ignoreraient et le pipeline passerait au vert en ayant
saute les chemins de securite.

**Q4.4 - Qu'est-ce qui casserait si vous passiez a 50 bornes ?** **(rude)**
Trois choses, dans cet ordre. Un, les **sessions** : ce sont des fichiers dans le
conteneur applicatif, donc ajouter un second conteneur casserait les sessions du
personnel tant qu'il n'y a pas de magasin de sessions partage - c'est la premiere chose
a changer. Deux, la **base unique** : le decrement de stock atomique deviendrait le point
de contention, et il faudrait mesurer avant de decider quoi faire. Trois, l'**absence de
supervision** : a 50 bornes je ne saurais pas laquelle est en panne. En revanche la borne
elle-meme tient la charge plus facilement que le back-office : le panier vit dans le
navigateur et ne declenche aucun appel reseau tant que le client n'a pas paye.

**Q4.5 - Comment gerez-vous les secrets ?**
Hors du depot : un fichier d'environnement non versionne, et un coffre auto-heberge pour
la conservation et la rotation. Le depot ne contient que des modeles d'exemple. Une
recherche de secrets tourne sur tout l'historique a chaque demande de fusion, ce qui
attraperait un secret commis par erreur meme ancien. Le fichier de deploiement contient
la partie **publique** d'une cle, ce qui permet de verifier que le secret configure est
le bon sans exposer la cle privee.

**Q4.6 - Qu'avez-vous automatise en dehors de l'integration ?**
Quatre taches planifiees, dans une fenetre 01h30-09h30 choisie parce que le service
client ferme a 01h00 : expiration des commandes restees en attente a 02h00, sauvegarde de
la base a 03h00 avec rotation et controle de taille minimale, purge du journal d'audit a
04h15, purge des compteurs de tentatives a 04h45. L'expiration est **avant** la
sauvegarde pour que le cliche de la nuit contienne l'etat nettoye.

### 7.5 Base de donnees

**Q5.1 - Presentez votre modele de donnees.**
23 entites en cinq domaines : catalogue, ingredients et stock, commande, droits,
securite. Le dictionnaire de donnees a ete pose avant le modele, et le modele a ete
enrichi lot par lot. Point a souligner : les trois entites du domaine securite - journal
d'audit et les deux compteurs de tentatives - viennent de la modelisation de la menace,
pas du besoin fonctionnel.

**Q5.2 - Pourquoi figer le libelle et le prix dans la ligne de commande ?**
Pour que l'historique reste fidele. Si le prix du catalogue change demain, la commande
d'hier doit continuer a afficher ce qui a ete paye. Le libelle, le prix unitaire et le
taux de TVA sont copies dans `order_item` au moment de la commande et ne sont plus
modifies (RG-T05). C'est de l'immuabilite comptable.

**Q5.3 - Pourquoi des montants en centimes entiers ?**
Pour eviter les flottants sur des montants. Un flottant ne represente pas exactement
certaines valeurs decimales, et l'erreur s'accumule sur une somme de lignes. Les
montants sont stockes et calcules en entiers, et convertis en euros seulement a
l'affichage (RG-T04).

**Q5.4 - Comment construisez-vous la base sur une machine neuve ?**
18 migrations puis 10 jeux de donnees, appliques dans l'ordre lexicographique par un
service a execution unique, avec un suivi en table. Tous sont idempotents : les rejouer
ne casse rien, ce qui rend l'operation sure a relancer. L'integration continue applique
exactement la meme sequence sur une base ephemere avant de lancer les tests.

**Q5.5 - Pourquoi le stock est-il en pourcentage et la disponibilite calculee ?**
La disponibilite d'un produit n'est pas stockee : elle est deduite du stock de ses
ingredients non retirables, en prenant le plus contraignant (RG-T21). Stocker la
disponibilite aurait cree une donnee derivee a maintenir en coherence, donc une source
de divergence. La calculer coute une jointure et supprime le probleme.

### 7.6 Choix techniques

**Q6.1 - Pourquoi pas de framework PHP ?**
Le sujet du Bloc 2 demande une realisation sans framework, et cela force a traiter
soi-meme le routage, la separation des responsabilites, l'acces aux donnees et la
securite. Decision d'architecture 0001. Ce que j'y ai gagne : je sais expliquer chaque
couche parce que je l'ai ecrite.

**Q6.2 - Pourquoi pas de framework JavaScript ?**
Le parcours tient en cinq ecrans. Un framework de composants aurait apporte une etape de
construction, une transpilation et des montees de version pour un volume qui ne le
justifie pas. Et la politique de securite du contenu de la borne interdit toute source
de script tierce, ce qui exclut un chargement depuis un reseau de diffusion.

**Q6.3 - Vous n'avez donc integre aucune bibliotheque externe. Et le critere Cr 2.d ?** **(rude)**
Je l'assume et je le dis avant qu'on me le demande. J'ai integre `a11y-dialog` pour les
modales, puis je l'ai retiree apres avoir relu le libelle exact du referentiel. Le
critere Cr 2.d.2, qui evalue la conformite d'une integration a la documentation d'une
bibliotheque, n'a pas d'objet chez moi. Le niveau de confiance est annonce comme faible
dans la fiche `05-librairies-js-c2d.md`. Ce que je peux couvrir, c'est Cr 2.d.3 :
demandez-moi d'expliquer la memoisation de promesse dans `data.js`.

**Q6.4 - Pourquoi PHP 8.3 et MariaDB 11.4 ?**
Des versions avec support a long terme, pour que le projet ne soit pas perime a la
soutenance. Et pour PHP, le typage strict et les fonctionnalites modernes du langage,
que j'utilise partout - c'est ce qui rend l'analyse statique au niveau 6 tenable.

**Q6.5 - Pourquoi PHPStan niveau 6 et pas plus haut ?**
Le niveau 6 exige que les types soient declares partout, y compris dans les tableaux.
Monter au-dessus demanderait un travail d'annotation sur du code de vue au rendement
faible ici. Le niveau 6 est atteint sans fichier de tolerance accumulee, et il tourne a
chaque demande de fusion. Precision si le jury ouvre `phpstan.neon` : il porte bien un
bloc d'exclusion, mais il ne concerne que des symboles PHPUnit absents en local, pas du
code de production.

### 7.7 Tests et qualite

**Q7.1 - Votre couverture de tests mesure quoi exactement ?** **(rude)**
Je n'ai pas de taux de couverture : je n'ai pas active l'instrumentation qui le calcule,
et c'est un manque. Ce que j'ai, ce sont des nombres de tests - plus de 2 380 tests PHP
pour plus de 7 800 assertions, plus de 440 tests JavaScript, 92 scenarios de bout en
bout - et surtout le **choix de ce qui est teste** : les chemins de commande, de stock,
d'authentification et de droits. Un taux eleve sur du code sans risque ne m'aurait rien
appris ; un test d'integration qui verifie qu'un role sans permission recoit 403, si.

**Q7.2 - Comment savez-vous que vos tests d'integration tournent vraiment ?**
C'est precisement le piege que j'ai ferme. Sans base de donnees, ils s'ignoreraient
tout seuls et le pipeline serait vert. Le drapeau `--fail-on-skipped` fait echouer
l'integration des qu'un test est ignore. L'integration monte une base MariaDB ephemere,
lui applique les 18 migrations et les 10 jeux de donnees, puis lance la suite. La
configuration refuse aussi les tests douteux et ceux qui n'assertent rien.

**Q7.3 - Avez-vous pratique le developpement pilote par les tests ?**
Sur les chemins sensibles, oui : le test d'abord, sur la commande, le stock, les
compteurs de tentatives et les droits. Honnetement, pas partout : sur des ecrans de
back-office, le test est venu apres. Je prefere le dire que pretendre une discipline
uniforme.

**Q7.4 - Vous annoncez 1 295 methodes mais plus de 2 300 tests. D'ou vient l'ecart ?**
Des jeux de donnees de test. Une methode associee a un fournisseur de donnees s'execute
une fois par jeu, et compte pour autant de tests. C'est ce qui permet de couvrir de
nombreux cas de validation sans dupliquer le code du test.

### 7.8 Usage de l'IA

**Q8.1 - Avez-vous utilise une IA sur ce projet ?**
Oui, et c'est documente en section 17 du dossier. Le centre de formation l'autorise
explicitement. Je l'ai pratiquee comme une pratique dirigee : l'outil redige, code et
relit ; les decisions d'architecture, de perimetre et de securite sont les miennes et
sont tracees en decisions d'architecture.

**Q8.2 - Qu'est-ce que vous avez code vous-meme ?** **(rude)**
La reponse honnete : le code a ete produit avec assistance, sous ma direction et ma
validation. Ce que j'ai fait moi-meme, ce sont les choix - le modele de donnees a partir
du dictionnaire, les 22 regles transverses, l'arbitrage sur le socket Docker, le retrait
de la bibliotheque externe. Ce qui compte pour vous, c'est que je comprenne le code et
que je sache le faire evoluer. Ouvrons un fichier : lequel voulez-vous ?

**Q8.3 - Sauriez-vous modifier telle partie devant nous ?** **(rude - et c'est une chance)**
Oui, allons-y. Je remonte la pile : migration, depot, controleur, vue, test. Voir la
section 8 pour les quatre modifications preparees. Ne pas repondre "oui" en restant
assis : ouvrir l'editeur en le disant.

**Q8.4 - Comment savez-vous que le code produit avec assistance est correct ?**
Trois filets. Je lis et je teste avant de committer. L'integration continue verifie a
chaque demande de fusion : syntaxe, analyse statique au niveau 6, la suite PHP complete,
la suite JavaScript, recherche de secrets. Et les chemins sensibles ont des tests
d'integration qui tournent contre une vraie base, sans possibilite d'etre ignores.

**Q8.5 - Si on vous enleve l'IA, savez-vous travailler ?**
Oui, plus lentement. Je m'appuie sur ce qu'utilisait un developpeur avant : la
documentation officielle, la lecture d'une trace d'erreur, le debogage pas a pas, et la
documentation de mon propre projet - 20 decisions d'architecture, le modele Merise, le
journal. Les schemas du projet sont en tete parce que je l'ai construit. A eviter :
dire "je n'ai pas besoin de l'IA", c'est invalidable en une question.

**Q8.6 - L'IA a-t-elle decide de l'architecture de votre base ?**
Non. Le modele part du dictionnaire de donnees et des besoins fonctionnels du projet ;
chaque cardinalite et chaque transition d'etat est un arbitrage que j'ai pose. L'outil a
formalise les diagrammes a partir de ces arbitrages. C'est ecrit en section 17.4 du
dossier, dans la liste de ce que l'outil ne fait pas.

**Q8.7 - Vos echanges avec l'IA sont-ils verifiables ?** **(piege - eviter de sur-promettre)**
Partiellement, et je prefere etre precis. Ce qui est versionne et que je peux vous
montrer : les regles de methodologie, les 20 decisions d'architecture, le journal de
bord, les commits (242 au 28/09, 244 au 29/09). Ce qui ne l'est pas : les journaux de
conversation eux-memes.
Le dossier laisse entendre en section 17.9 qu'ils sont auditables ; c'est une imprecision
que j'ai relevee, ils ne sont pas versionnes.

**Q8.8 - Donnez-moi une decision que vous avez prise contre l'avis de l'outil.**
Le retrait de la bibliotheque `a11y-dialog`. L'assistant l'avait integree et la
presentait comme couvrant le critere Cr 2.d. J'ai relu le libelle exact du referentiel,
pese le cout d'une dependance livree au navigateur contre la politique de securite du
contenu que j'avais posee, et j'ai tranche dans l'autre sens - en acceptant que le
critere reste non couvert au sens strict. C'est trace dans le journal du 22 septembre.

### 7.9 Ecarts au sujet et perimetre

**Q9.1 - Le paiement n'est pas reel. Qu'est-ce que ca enleve a votre demonstration ?**
La partie prestataire de paiement, et rien d'autre. Tout ce qui l'entoure est reel : le
serveur recalcule le prix, le stock se decremente de facon atomique dans la transaction,
l'idempotence empeche la double facturation. Ce sont ces trois mecanismes que le jury
evalue, et ils ne dependent pas du prestataire.

**Q9.2 - Qu'est-ce qui n'est pas fini ?**
Le taux de couverture des tests n'est pas mesure. Il n'y a pas de supervision. La
politique de securite du contenu du back-office est moins stricte que celle de la borne.
Le durcissement du piege a tabulation par l'attribut `inert` est identifie et non fait.
Et les documents ecrits ont pris du retard sur le code a une quinzaine d'endroits que
j'ai repertories - je peux vous donner la liste.

**Q9.3 - Votre dossier dit que l'API `PUT`/`DELETE` n'est pas faite. Elle l'est ou pas ?** **(rude, et facile a transformer)**
Elle est faite. Le dossier a ete ecrit avant les demandes de fusion #151 et #164 :
57 routes sous `/admin/api/`, dont 9 `PUT` et 5 `DELETE`, portees par 10 controleurs
dedies et tracees par la decision d'architecture 0017. C'est un retard de documentation
dans le sens favorable - le code en fait plus que le document ne le dit. Je vous le
montre tout de suite en collection Postman.

**Q9.4 - Vous presentez trois blocs. Lequel est le plus faible ?**
Le Bloc 1, sur le critere des bibliotheques externes - c'est le seul endroit ou je sais
qu'un critere n'a pas d'objet dans mon projet. Sur le reste du Bloc 1, l'accessibilite
est la partie que j'ai le plus mesuree. Repondre sans esquiver vaut davantage que de
dire "ils sont equivalents".

**Q9.5 - Pourquoi avoir choisi le Bloc 5 en option ?**
Parce que je viens de l'administration systeme et reseau, et que c'est le bloc ou mon
experience se voit. Les deux arbitrages dont je suis le plus satisfait sont des
raisonnements d'exploitation : ne pas transmettre le socket Docker aux travaux
d'integration, et decouper le deploiement en deux etapes pour pouvoir diagnostiquer
sans acces aux journaux.

---

## 8. Se preparer a modifier du code en direct

> Le jury peut demander "ajoutez-moi X". Repeter chacun de ces quatre parcours **au
> moins une fois** avant l'oral, chronometre.

### 8.1 Ajouter un champ a une entite (exemple : une description sur une categorie)

Je remonte la pile, en le disant a voix haute :

1. **Base** : nouvelle migration `db/migrations/0020_category_description.sql` (la
   derniere migration livree est `0019_pin_failed_audit_minimisation.sql`, la prochaine est donc
   `0020`), `ALTER TABLE category ADD COLUMN description VARCHAR(255) NULL`, avec une
   garde d'idempotence sur `information_schema`.
2. **Depot** : `src/app/Catalogue/CategoryRepository.php`, ajouter la colonne aux
   requetes et a la liste blanche de colonnes (RG-T16).
3. **Controleur** : `CategoryController::validate()`, valider la longueur.
4. **Vue** : le formulaire puis la liste, sous `src/app/Views/admin/categories/`.
5. **Test** : une assertion dans le test du depot.

### 8.2 Ajouter une route

1. Les routes sont declarees dans `src/app/Core/routes.php` (l'unique point d'entree PHP,
   `src/public/admin/index.php`, se contente d'un `require` sur ce fichier ; la borne, elle,
   ne passe par aucun routeur PHP - ce sont des pages HTML statiques) :
   `$router->add('GET', '/api/categories/{id}', [CatalogueController::class, 'category']);`
2. L'action `category(array $params)` dans le controleur, qui lit `$params['id']`,
   appelle le depot et renvoie `$this->json([...])`.
3. **Declarer aussi la route dans `src/app/Health/RouteSecurity.php`** (sans_compte,
   permission, csrf, pin, reauth) : cette table est la source unique lue par la carte
   de la page Sante, et trois tests figent la coherence entre le routeur et cette
   table (`tests/Unit/Health/RoutesPhpTest.php`, `RouteMapTest.php`,
   `RouteSecurityCoverageTest.php`) — une route absente de l'une des deux fait
   echouer ces tests.
4. **Relancer `tests/e2e/run-health-capture.sh`** pour regenerer les reponses
   capturees de la page Sante ; sans cette etape, `tests/Unit/Health/CapturedResponsesTest.php`
   echoue en CI (le fichier de capture ne connait pas la nouvelle route).
5. Un test d'integration sur la nouvelle route.

### 8.3 Changer une regle de validation

Un seul endroit : la methode `validate()` du controleur concerne. Profiter de la
question pour dire que la validation serveur est centralisee (RG-T18) : *"le client peut
mentir, c'est le serveur qui tranche."*

### 8.4 Ajouter une permission

1. Une migration qui insere la ligne dans `permission`.
2. Les lignes `role_permission` pour les roles concernes.
3. Le `guard('...')` dans le controleur.
4. Un test qui verifie qu'un role sans la permission recoit 403.

C'est la modification la plus interessante a proposer soi-meme : elle traverse la base,
le code et le test, et elle illustre le fait qu'on teste une permission et pas un role.

### 8.5 Si je bloque

Etre methodique plutot que rapide : *"Je localise le fichier - ici ce serait le
controleur X - puis je suis la pile depot, vue, test. Laissez-moi l'ouvrir."* Le jury
evalue la demarche de navigation autant que la vitesse.

---

## 9. Liste de verification avant l'oral

### La veille

- [ ] Relancer les commandes de la section 1.1 et corriger tout chiffre qui aurait bouge.
      **Ce point est le plus important de la liste** : le depot bouge de plusieurs
      demandes de fusion par jour.
- [ ] Relancer les deux suites de tests et noter les totaux exacts.
- [ ] Verifier que `/api/health` n'annonce pas un environnement de developpement et que
      le mode de deboguage detaille est desactive en production. C'est une adresse
      publique que le jury peut ouvrir pendant l'oral.
- [ ] Verifier l'etat de la production **sans rien modifier** : etat des services,
      reponse de `/api/health`, borne, back-office. Ecarter toute commande destructive
      (en particulier la suppression de volumes).
- [ ] Demarrer une pile locale de secours et la laisser tourner.
- [ ] Construire/importer la collection Insomnia, renseigner l'environnement, ouvrir les
      six requetes de la section 4.2 dans l'ordre (quatre gestes, deux d'entre eux
      enchainant une reconnexion puis une action).
- [ ] Preparer la sequence `curl` de secours dans un fichier texte.
- [ ] Ouvrir `docs/demo/comptes-demo.md` pour avoir les comptes sous la main.
- [ ] Recapturer les ecrans du back-office si on compte montrer des captures : celles du
      dossier datent d'avant la refonte du 26 septembre.
- [ ] Rendre les diagrammes Merise en image.
- [ ] Relire les titres des 20 decisions d'architecture.
- [ ] Repeter les deux demonstrations, chronometre.
- [ ] Repeter une modification en direct (section 8.4 de preference).
- [ ] Relire la section 6 a voix haute : c'est celle qu'on oublie sous stress.
- [ ] Facultatif : relancer le balayage du back-office si on veut pouvoir montrer son
      rapport, ses sorties n'etant pas versionnees.

### Le jour J

- [ ] Tester le branchement de l'ecran tot.
- [ ] Ouvrir a l'avance : borne, back-office connecte, outil d'API pret, modele de
      donnees, rapport de mesure d'accessibilite.
- [ ] Avoir les captures et les collections en local, sans dependance au reseau.
- [ ] Montre ou chronometre visible. Repere a 19:00 : on doit avoir quitte le front.
- [ ] Bouteille d'eau.

---

## 10. Ou trouver quoi

| Besoin | Fichier |
|---|---|
| Source unique du projet | `docs/PROJECT_CONTEXT.md` |
| Modelisation de la menace | `docs/PROJECT_CONTEXT.md`, section 19 |
| Position sur l'assistance IA | `docs/PROJECT_CONTEXT.md`, section 17 |
| Regles transverses de securite | `docs/merise/mlt.md`, lignes 41-62 |
| Modele de donnees | `docs/merise/dictionary.md`, `mcd.md`, `mld.md`, `mct.md` |
| Diagrammes | `docs/merise/_diagrams/` |
| Audit d'accessibilite mesure | `docs/soutenance/preuves/06-audit-accessibilite-mesure.md` |
| Rapports bruts d'accessibilite | `docs/soutenance/preuves/rapports/resume.json` |
| Releve de contrastes | `docs/soutenance/preuves/rapports/contrastes-mesures.csv` |
| Conformite RGAA | `docs/soutenance/preuves/04-accessibilite-rgaa.md` |
| Argumentaire sur les bibliotheques | `docs/soutenance/preuves/05-librairies-js-c2d.md` |
| Captures de secours | `docs/soutenance/preuves/captures-responsive/`, `docs/design/screens/` |
| Ecarts avec la maquette | `docs/design/maquette-vs-build.md` |
| Tests d'accessibilite | `tests/e2e/a11y.spec.js` |
| Balayage du back-office | `tests/e2e/backoffice-sweep/` |
| Collections d'API | `docs/api/wakdo-admin.postman_collection.json`, `docs/api/bruno/` |
| Mode d'emploi de la demonstration d'API | `docs/api/demo-api.md` |
| Conventions de l'API | `docs/api/conventions.md` |
| Comptes de demonstration | `docs/demo/comptes-demo.md` |
| Matrice des droits | `docs/demo/matrice-rbac.md` |
| Decisions d'architecture | `docs/adr/` (20 decisions) |
| Journal de bord | `docs/journal/` (16 entrees) |
| Integration continue | `.forgejo/workflows/ci.yml` |
| Deploiement | `.forgejo/workflows/deploy.yml` |
| Conteneurs | `docker-compose.yml`, `docker/` |
| Taches planifiees | `docker/cron/crontab` |
| Politique de securite | `SECURITY.md` |
