# Index RNCP 37805 — Titre Developpeur Web B2

> Index texte compact du referentiel officiel (20 pages).
> Source primaire : `docs/_ref/rncp-37805-referentiel.pdf` (Webecom, V09-11-22).
> Usage : grep rapide des criteres, verification des mappings CDCF/CDCT, preparation oral.
>
> Rappel : chaque libelle ci-dessous est la transcription textuelle du PDF officiel.
> En cas de doute sur un critere, relire la source primaire avant d'agir.

---

## Structure globale

| Bloc | Nom | Statut pour Wakdo |
|---|---|---|
| Bloc 1 | Developpement Front End | **Tronc commun obligatoire** |
| Bloc 2 | Developpement Back End | **Tronc commun obligatoire** |
| Bloc 3 | Framework (option) | Non choisie |
| Bloc 4 | Design d'interfaces UX/UI (option) | Non choisie |
| **Bloc 5** | **DevOps (option 3)** | **Option choisie** |

### Regles de validation (page 19)

- **50 % minimum par bloc** pour le valider
- **50 % moyenne globale** pour obtenir le titre
- Ponderation : controle continu 30 % / stage 20 % / examens jury 50 %
- Titre obtenu = **tronc commun (Bloc 1 + Bloc 2)** + **un bloc optionnel** + **stage en entreprise**

---

## Bloc 1 — Developpement Front End

### Activite 1 — Traduction de la maquette en code interpretable par les navigateurs

Domaines : Integration Web / responsive / Normes & accessibilite / Standardisation / Referencement naturel.

#### C1.a — Utiliser HTML et CSS (avec ou sans framework) pour integrer les maquettes

| Id | Critere |
|---|---|
| Cr 1.a.1 | L'integration est conforme a la maquette |
| Cr 1.a.2 | Le code respecte les normes W3C et les normes d'accessibilite |
| Cr 1.a.3 | Le code passe avec succes les tests du validateur |
| Cr 1.a.4 | Le code est commente et correctement indente |
| Cr 1.a.5 | Les balises semantiques sont utilisees a bon escient |

#### C1.b — Produire l'encodage responsive (smartphones, tablettes, desktop)

| Id | Critere |
|---|---|
| Cr 1.b.1 | Le codage de l'application s'adapte correctement aux differentes resolutions d'ecran |
| Cr 1.b.2 | Les proprietes utilisees sont compatibles avec les differents navigateurs |
| Cr 1.b.3 | En cas d'incompatibilite du navigateur d'une propriete, le candidat apporte une correction ou utilise une alternative, en s'appuyant sur la documentation |

#### C1.c — Considerer la diversite des publics, notamment en situation de handicap (RGAA)

| Id | Critere |
|---|---|
| Cr 1.c.1 | Les attributs des elements visuels sont correctement renseignes pour les logiciels de lecture d'ecran |
| Cr 1.c.2 | Une police specifique pour les personnes dyslexiques est prevue et integree (OpenDys) |
| Cr 1.c.3 | Les informations importantes ne sont pas uniquement transmises par un code couleur mais sont textuellement exprimees |
| Cr 1.c.4 | L'utilisateur peut naviguer, acceder aux fonctionnalites et au contenu en utilisant le clavier |

#### C1.d — Travailler sur une logique d'integration reutilisable (classes generiques)

| Id | Critere |
|---|---|
| Cr 1.d.1 | Le nommage des classes CSS est pertinent et propose une approche flexible, reutilisable |
| Cr 1.d.2 | Le code CSS est organise et commente |
| Cr 1.d.3 | Les classes sont regroupees par thematiques |
| Cr 1.d.4 | Le code CSS produit est synthetique et ne presente pas de repetitions |

#### C1.e — Travailler le referencement naturel (SEO)

| Id | Critere |
|---|---|
| Cr 1.e.1 | Les textes sont hierarchises et correctement titres |
| Cr 1.e.2 | Les expressions cles sont mises en exergue |
| Cr 1.e.3 | Le balisage d'enrichissement de contenu est compris via schema.org |
| Cr 1.e.4 | La semantique des balises est respectee (article, aside, nav) |
| Cr 1.e.5 | Les balises meta sont uniques sur chaque page et contiennent un nombre de caracteres optimise |
| Cr 1.e.6 | Les pages canoniques sont renseignees |
| Cr 1.e.7 | Les attributs alternatifs des images sont presents ainsi que les titres des liens |
| Cr 1.e.8 | Les temps de chargement des pages sont optimises (poids images, sprites...) |
| Cr 1.e.9 | Le favicon est integre |
| Cr 1.e.10 | La navigation entre les differentes pages du site est implementee |
| Cr 1.e.11 | Les ancres sont utilisees pour la navigation au sein d'une meme page |

### Activite 2 — Developpement de fonctionnalites front end (navigateur)

Domaines : Interactions/animations JS / Validation de donnees / Fonctionnalites asynchrones / Librairies.

#### C2.a — Enrichir l'interface en JavaScript

| Id | Critere |
|---|---|
| Cr 2.a.1 | Les syntaxes modernes (ES5, ES6 et superieures) et les fonctions natives du langage sont acquises |
| Cr 2.a.2 | La manipulation des elements du document (DOM) en termes de contenu comme de style est maitrisee |
| Cr 2.a.3 | Les animations JavaScript developpees permettent une meilleure experience utilisateur |
| Cr 2.a.4 | Les animations sont fonctionnelles et leurs comportements sont geres sur les differents navigateurs |
| Cr 2.a.5 | Le code est developpe en utilisant la programmation procedurale, fonctionnelle ou orientee objet, et la programmation evenementielle |

#### C2.b — Valider les saisies utilisateur dans les formulaires

| Id | Critere |
|---|---|
| Cr 2.b.1 | Les donnees saisies par les utilisateurs dans les espaces interactifs sont controlees pendant la saisie en temps reel |
| Cr 2.b.2 | Les methodes de controle mises en oeuvre sont coherentes en fonction de la nature des donnees a traiter |
| Cr 2.b.3 | L'envoi des informations au serveur n'est effectif que lorsque les donnees correspondent au format attendu. Le cas echeant, des messages previennent l'utilisateur des erreurs de saisie a corriger |

#### C2.c — Developper des fonctionnalites asynchrones avec le serveur (API)

| Id | Critere |
|---|---|
| Cr 2.c.1 | Les developpements des requetes asynchrones sont fonctionnels et correctement mis en oeuvre |
| Cr 2.c.2 | Les requetes HTTP asynchrones n'exposent pas de donnees sensibles ou personnelles |
| Cr 2.c.3 | Les reponses renvoyees par le serveur sont traitees et utilisees |
| Cr 2.c.4 | Dans le cas d'un renvoi d'erreurs, celles-ci sont traitees de maniere a ne pas interrompre l'execution du script |

#### C2.d — Optimiser avec des librairies JavaScript externes

| Id | Critere |
|---|---|
| Cr 2.d.1 | Les librairies utilisees repondent a une problematique specifique |
| Cr 2.d.2 | La librairie est correctement implementee d'apres les recommandations d'utilisation de sa documentation |
| Cr 2.d.3 | Le candidat peut clairement expliquer le fonctionnement global de la librairie et son utilisation |

---

## Bloc 2 — Developpement Back End

### Activite 3 — Data : analyse, modelisation, traitement

Domaines : Modelisation donnees / Construction BDD / Exploitation BDD / Cadre legal.

#### C3.a — Synthetiser les donnees utiles a l'application (formaliser le modele)

| Id | Critere |
|---|---|
| Cr 3.a.1 | Les donnees necessaires a l'application sont correctement identifiees |
| Cr 3.a.2 | Les donnees sont retranscrites sur un schema decrivant les differentes tables et les relations entre elles |
| Cr 3.a.3 | Le candidat exploite dans son modele de donnees des informations externes provenant d'une API |
| Cr 3.a.4 | (Cr 3.a.4 cite dans le PDF — libelle proche de Cr 3.a.1) |

> Note : le PDF affiche Cr 3.a.4 et Cr 3.a.2 en tete de tableau puis Cr 3.a.3. L'ordre a l'ecran n'est pas strictement numerique. A verifier visuellement si un doute.

#### C3.b — Construire la BDD via un outil d'administration

| Id | Critere |
|---|---|
| Cr 3.b.1 | Le nommage des tables et des champs est coherent avec la typologie des donnees |
| Cr 3.b.2 | Le type des champs est choisi en adequation avec la nature des donnees (varchar, boolean, integer...) |
| Cr 3.b.3 | La mise en relation des tables est correctement effectuee |

#### C3.c — Interroger la BDD en SQL

| Id | Critere |
|---|---|
| Cr 3.c.1 | Le candidat effectue les principales operations de manipulation des donnees (lister, ajouter, modifier, supprimer) |
| Cr 3.c.2 | Le candidat affine ses requetes en utilisant des systemes de tri et de filtres |
| Cr 3.c.3 | Les requetes sont optimisees par l'utilisation de cles etrangeres et de liaisons de tables |

#### C3.d — Respecter le cadre legal (RGPD) — **obligatoire**

| Id | Critere |
|---|---|
| Cr 3.d.1 | Le candidat a identifie, avec le client, les donnees sensibles et reglementees qui doivent beneficier d'un traitement specifique |
| Cr 3.d.2 | L'application informe l'utilisateur du stockage, de l'utilisation et du cadre de partage de ses donnees personnelles |
| Cr 3.d.3 | L'utilisateur dispose d'un droit de consultation, modification et de suppression de ses donnees personnelles |
| Cr 3.d.4 | Les donnees sensibles sont protegees |

### Activite 4 — Developpement back end (serveur)

Domaines : Conceptualisation / Programmation cote serveur / POO / MVC / Securite / Travail en equipe et versionning.

#### C4.a — Conceptualiser l'application, formaliser le schema fonctionnel

| Id | Critere |
|---|---|
| Cr 4.a.1 | Le candidat a pose les bonnes questions au client dans sa demarche de comprehension du fonctionnement de l'application a developper |
| Cr 4.a.2 | Le candidat est force de proposition lors de ses echanges |
| Cr 4.a.3 | Toutes les fonctionnalites necessaires au bon fonctionnement de l'application sont correctement listees et detaillees |
| Cr 4.a.4 | Le schema fonctionnel decrit en detail l'enchainement des vues en fonction des differentes actions et interactions |

#### C4.b — Developper cote serveur

| Id | Critere |
|---|---|
| Cr 4.b.1 | La syntaxe et les fonctions natives du langage sont acquises |
| Cr 4.b.2 | Le code est indente, les commentaires aident a la comprehension du code |
| Cr 4.b.3 | Les dossiers et fichiers du projet sont organises |
| Cr 4.b.4 | Les conventions de nommage sont respectees pour l'ensemble du code |
| Cr 4.b.5 | Les limites du code sont connues |
| Cr 4.b.6 | Les erreurs de codage sont traitees |

#### C4.c — POO et heritages pour produire un code reutilisable

| Id | Critere |
|---|---|
| Cr 4.c.1 | La portee des attributs et des methodes est coherente |
| Cr 4.c.2 | Le code implemente des classes generiques et l'heritage est correctement mis en place |
| Cr 4.c.3 | Les classes sont implementees en utilisant les namespaces et chargees par l'intermediaire d'un autoloader, a defaut elles sont chargees manuellement dans un fichier de configuration |

#### C4.d — Architecture Modele-Vue-Controleur

| Id | Critere |
|---|---|
| Cr 4.d.1 | Le modele gere les interactions avec la base de donnees |
| Cr 4.d.2 | Les controleurs implementent la logique et preparent les variables necessaires au rendu de la vue |
| Cr 4.d.3 | La vue recoit et permet l'affichage des donnees transmises par le controleur et remplit son role principal d'affichage |

#### C4.e — Identifier un utilisateur et delimiter ses champs d'action (securite)

| Id | Critere |
|---|---|
| Cr 4.e.1 | Le programme protege l'integrite des donnees en empechant toute injection d'elements pouvant les compromettre |
| Cr 4.e.2 | Un utilisateur s'authentifie par l'intermediaire d'un identifiant unique et d'un mot de passe. L'utilisation d'un systeme de session, de token, ou equivalent permet d'identifier l'utilisateur connecte |
| Cr 4.e.3 | L'implementation dans le programme de differents roles permet une delimitation des actions possibles et permissions pour chaque type d'utilisateur (administrateur, auteur...) |

#### C4.f — Travailler en equipe (outils de collaboration et versionning)

> **Attention** : seul Cr 4.f.2 est une maitrise d'outil verifiable par artefact (Git).
> Les trois autres sont des **soft skills evaluees a l'oral**.

| Id | Critere | Nature |
|---|---|---|
| Cr 4.f.1 | Le candidat mobilise et transmet son savoir, son savoir-faire et ses methodes. Il participe activement a la collaboration | Soft skill (oral) |
| Cr 4.f.2 | L'utilisation de l'outil de travail collaboratif est maitrisee (ex : Gitlab) | **Artefact** (Git, PR, branches, hooks) |
| Cr 4.f.3 | Le candidat sait auto evaluer et mesurer la compatibilite de son code avant de le soumettre comme contribution au projet | Soft skill (oral) — avec artefact possible (tests verts avant push) |
| Cr 4.f.4 | Le candidat peut clairement rendre compte de sa participation individuelle au travail collectif | Soft skill (oral) |

#### C4.g — Preparer la livraison

| Id | Critere |
|---|---|
| Cr 4.g.1 | Le candidat s'assure de la conformite des fonctionnalites attendues par le cahier des charges et celles deployees |
| Cr 4.g.2 | Des tests unitaires sont realises et valides |
| Cr 4.g.3 | L'application mise en ligne est exempte de bugs et fonctionnelle |
| Cr 4.g.4 | L'application est testee en production et ne montre pas d'erreurs ou d'effets de bords pouvant nuire a son utilisation |

---

## Bloc 5 — DevOps (option 3)

*Utiliser la methodologie DevOps pour automatiser, conteneuriser et deployer une application en continu.*

### Activite 7 — Automatiser les differentes etapes tout au long du cycle de vie

Domaines : Identification des processus a automatiser / Programmation de scripts / Conteneurisation / Orchestration.

#### C7.a — Identifier les points d'automatisation

| Id | Critere |
|---|---|
| Cr 7.a.1 | Le candidat a bien analyse les contraintes en termes d'infrastructure et de securite |
| Cr 7.a.2 | Le candidat propose un ensemble de solutions pertinentes pour automatiser tout ou partie de l'ensemble du processus |
| Cr 7.a.3 | Le candidat prend en compte les interactions avec les activites connexes, autant sur la partie developpement que sur la partie de l'infrastructure |

#### C7.b — Programmer les actions en script

| Id | Critere |
|---|---|
| Cr 7.b.1 | Le candidat maitrise la syntaxe d'un langage de script |
| Cr 7.b.2 | L'automatisation est fonctionnelle et fiabilisee |
| Cr 7.b.3 | Le candidat planifie des taches repetitives (planificateur de tache, cron tab) |

#### C7.c — Creer un environnement de developpement independant (conteneur, ex : Docker)

| Id | Critere |
|---|---|
| Cr 7.c.1 | La machine virtuelle creee par le candidat est configuree et operationnelle |
| Cr 7.c.2 | Le systeme d'exploitation pour conteneur est installe dans la machine d'hebergement virtuelle |
| Cr 7.c.3 | L'application complete est correctement conteneurisee avec les services et les dependances necessaires au fonctionnement de l'application |
| Cr 7.c.4 | Le fichier de configuration est renseigne et permet de lancer la stack applicative complete avec une seule ligne commande |

#### C7.d — Assurer un deploiement continu (CI/CD, ex : GitHub Actions)

| Id | Critere |
|---|---|
| Cr 7.d.1 | L'architecture serveur est mise en place et fonctionnelle |
| Cr 7.d.2 | L'application est testee avant deploiement |
| Cr 7.d.3 | L'integration et le deploiement continus sont testes et l'application est livree |

---

## Mise en situation professionnelle — elements fournis et attendus (synthese)

### Elements fournis au candidat (Blocs 1 + 2)

- Les maquettes a integrer (Bloc 1)
- Le cahier des charges
- Les elements graphiques non optimises a integrer
- Un espace sur le serveur pour le deploiement
- Un acces au serveur (Bloc 2)
- Un acces a une base de donnees (Bloc 2)

### Elements attendus / livrables jury (Blocs 1 + 2)

- Deploiement complet et fonctionnel du site ou de l'application sur le serveur
- Les schemas conceptuels et physiques du modele de donnees (Bloc 2)
- Les schemas fonctionnels de l'application (Bloc 2)
- La base de donnees de l'application (Bloc 2)
- L'application fonctionnelle deployee sur le serveur (Bloc 2)

### Elements fournis / attendus (Bloc 5 DevOps)

**Fournis** : un sujet d'exercice sous forme de demande client, une ou plusieurs applications selon la demande du client, un acces a un serveur hote.

**Attendus** : l'application automatisee, conteneurisee et deployee + tous supports permettant d'appuyer l'argumentation.

---

## Lexique (page 20 du PDF)

| Terme | Definition |
|---|---|
| HTML | Hyper Text Markup Language — langage de balisage utilise pour decrire la structure et le contenu semantique d'une page web |
| CSS | Cascading Style Sheets — langage decrivant la mise en forme d'un document HTML |
| JAVASCRIPT | Langage de programmation utilisable dans un navigateur |
| ES5 / ES6 | Ecma Script — normes syntaxiques et standards des langages de scripts |
| DOM | Document Object Model — interpretation sous forme d'un objet manipulable par JavaScript d'une page web |
| HTTP | Hyper Text Transfert Protocol — protocole de communication entre le client et le serveur |
| FRONT END | Cote client — programme execute dans le navigateur dont le code source est visible publiquement |
| BACK END | Cote serveur — programme execute sur le serveur dont le code source est invisible dans le navigateur |
| FRAMEWORK | Cadre de travail — ensemble d'outils interdependants utilises pour creer rapidement et facilement des applications |
| MVC | Model Vue Controller — patron de conception d'une architecture de code |
| DEVOPS | Pratique technique visant a l'unification du developpement logiciel et de l'administration des infrastructures informatiques |
| **RGPD** | **Reglement general sur la protection des donnees — cadre reglementaire relatif a la protection des personnes physiques a l'egard du traitement des donnees a caractere personnel et a la libre circulation de ces donnees** |
| BRAND BOARD | Proposition coherente d'une identite graphique (non-utilise hors Bloc 4) |
| WIREFRAME | Trame generale schematique de l'agencement d'une maquette (non-utilise hors Bloc 4) |

---

## Stats de couverture pour Wakdo

| Bloc | Competences | Criteres total | Statut |
|---|---|---|---|
| Bloc 1 (tronc) | 9 (C1.a-e + C2.a-d) | 44 | Obligatoire |
| Bloc 2 (tronc) | 11 (C3.a-d + C4.a-g) | 35 | Obligatoire |
| Bloc 5 (option DevOps) | 4 (C7.a-d) | 13 | **Option choisie** |
| **Total Wakdo** | **24 competences** | **~92 criteres** | — |

---

*Index genere le 2026-04-24. Source primaire : `rncp-37805-referentiel.pdf` (PDF officiel Webecom V09-11-22, 20 pages).*
*Cet index est un outil de navigation. En cas d'ambiguite sur un libelle, se referer a la source primaire.*
