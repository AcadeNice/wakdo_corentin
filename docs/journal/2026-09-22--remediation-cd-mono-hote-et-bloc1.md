# 2026-09-22 — Deploiement continu mono-hote, mise en verite documentaire et remediation Bloc 1 (#131-#142)

**Auteur : BYAN.** Session longue couvrant douze demandes de fusion. Trois fils :
la documentation remise en accord avec le code, le deploiement continu rendu
operationnel pour la premiere fois du projet, et la remediation du Bloc 1 apres
un audit des trois blocs contre le referentiel RNCP 37805.

Entree descriptive : on decrit ce qui est livre et mesure, pas ce qui est promis.
Les erreurs commises pendant la session sont consignees au meme titre que les
reussites — elles font partie du travail reel.

---

## 1. Ce qui a ete livre

| PR | Objet | Etat |
|----|-------|------|
| #131 | Mise en verite de la conception Merise et des preuves + 4 documents orphelins | fusionnee |
| #132 | Deploiement continu mono-hote + documentation alignee sur la machine | fusionnee |
| #133 | Depot d'images securise et rangement du catalogue (81 tests ajoutes) | fusionnee |
| #134 | Sept regles BYAN non versionnees | fusionnee |
| #135 | Release v0.3.0 vers `main` | fusionnee |
| #136 | Echec rapide sur la cle de deploiement + relance a la demande | fusionnee |
| #137 | Release v0.3.1 | fusionnee |
| #138 | Adresse explicite de l'hote de deploiement | fusionnee |
| #139 | Release v0.3.2 — **premier deploiement automatique du projet** | fusionnee |
| #140 | Lien d'evitement sur 6 pages + accessibilite du back-office | fusionnee |
| #141 | Audit d'accessibilite mesure (axe-core) | fusionnee |
| #142 | Complement de #141, fusionnee trop tot : contrastes, retrait de la librairie, total anime | fusionnee |

---

## 2. Chantier A — La documentation remise en accord avec le code

Point de depart : l'audit montrait que la documentation affirmait des choses que
le code contredisait, **toujours en sous-estimant ce qui etait livre**.

Corrige :

- **`mct.md`, `mcd.md`, `dictionary.md`, `mld.md`** affirmaient que les etats de
  commande `preparing` et `ready` etaient supprimes. La migration
  `0009_order_prep_states.sql` les a livres. Les quatre documents decrivent
  desormais la meme machine a etats que le code — la validation croisee Merise
  (mantra #34) tient a nouveau.
- **`functional-schema.md`** annoncait les parcours comptoir, drive et cuisine
  comme « evolutions a venir » alors que leurs controleurs et leurs vues sont
  routes. Ce fichier EST l'artefact du critere Cr 4.a.4.
- **`mlt.md`** portait une note « ecart connu » devenue fausse une fois les
  autres fichiers corriges : soldee et datee.
- **Fiche de preuve RGAA** : tous les numeros de ligne cites avaient derive avec
  les commits. Un jury qui verifiait `style.css:1977` tombait sur une
  declaration de police au lieu du commentaire annonce. Remplaces par des
  citations de texte cherchables, qui ne derivent pas.
- **Index du referentiel** : annoncait 44 criteres au Bloc 1 et 35 au Bloc 2. Le
  recompte ligne a ligne donne **42 et 41**, soit **96 au total** avec le Bloc 5,
  et non 92.
- **Quatre documents n'avaient jamais ete versionnes** : le plan d'oral 40 min et
  les diapositives (produits le 2026-06-29 pour l'oral blanc), le trace du flux
  borne, et une entree de journal du 2026-06-04. 1570 lignes qui n'existaient
  pour personne.

---

## 3. Chantier B — Le deploiement continu, rendu operationnel

### L'etat trouve

`main` etait figee au 2026-06-23, **37 commits en retard** sur `dev`. Le fichier
`deploy.yml` existait sur `dev`, son declencheur ecoutait `push` sur `main`, et
il etait **absent de `main`** : il ne pouvait donc jamais se declencher. Zero
execution depuis sa creation.

Le depot GitHub public, livrable exige par le kit d'examen, montrait la meme
version de juin.

### La conception retenue, et pourquoi

La topologie documentee annoncait trois machines dont une production distante.
La realite : **un seul hote**, qui porte a la fois le runner d'integration et la
production.

Quatre jobs de sonde poussees sur la forge ont mesure le privilege reel :

| Condition | Resultat |
|---|---|
| Le job telecharge-t-il depuis le reseau | vert |
| Le socket Docker est-il dans le conteneur de job | **rouge** |
| Le demon Docker repond-il depuis le job | **rouge** |
| Un conteneur frere peut-il etre lance | **rouge** |

Le conteneur du runner a le socket — il en a besoin pour lancer les conteneurs de
job. **Les jobs ne l'ont pas.** Un job d'integration est donc non privilegie, et
c'est voulu : lui donner le socket reviendrait a accorder les pleins pouvoirs sur
la machine de production a tout code passant en integration.

D'ou la conception : le job **demande** a l'hote de se deployer, par un canal
restreint a une seule commande (commande forcee cote hote). La cle de
deploiement ne permet ni terminal, ni tunnel, ni copie de fichier.

### La lecon reseau, couteuse

La premiere version deduisait l'adresse de l'hote depuis la route par defaut du
conteneur de job. **Elle n'y mene pas.** Mesure par trois jobs booleens : la
passerelle n'est ni `172.17.0.1` ni sur `10.44.0.0/24`, et la machine qu'elle
designe ne presente pas la cle d'hote de stark. Seule l'adresse principale
repond avec la cle attendue. L'adresse est desormais **explicite**, pas devinee.

### Ce que le mecanisme fait aujourd'hui

1. Un commit arrive sur `main`.
2. L'integration le teste (scan de secrets, lint PHP, PHPStan, PHPUnit, tests JS).
3. Un premier job verifie que le secret contient bien la cle autorisee sur l'hote,
   et echoue vite avec un message clair sinon.
4. Le second ouvre le canal restreint ; l'hote execute `scripts/deploy.sh`.
5. Le job **verifie son propre resultat** : il interroge `/api/health` pendant
   deux minutes et echoue si l'application en ligne ne sert pas le commit attendu.

Premiere execution reussie le **2026-09-22 a 12h19**, verifiee sur la machine :

```
main        1e28a8f
src/VERSION 1e28a8f  2026-09-22T12:19:02
deploy.log  [12:19:02] deploy 1e28a8f (branche main)
/api/health "version":"1e28a8f"
```

### Garde-fou ajoute

`scripts/deploy.sh` refuse de partir si l'arbre de travail n'est pas propre. Sur
un hote unique, ce repertoire est aussi l'espace de travail : sans cette garde, un
fichier jamais committe partirait en production sans trace dans l'historique.

---

## 4. Chantier C — Depot d'images securise

Du code ecrit le 2026-09-18 entre 19h12 et 20h12 etait reste **non committe et
sans aucun test**. Le journal de git est formel : aucune tentative de commit n'a
jamais eu lieu, et rien ne s'y opposait (le controle pre-commit passe, aucune
session de mode strict ouverte).

Complete en TDD : **81 tests ajoutes** (69 PHP, 12 JS), dont 30 sur le seul
composant de depot.

Analyse de securite : le nom de fichier fourni par le client n'est jamais lu, le
nom de destination est regenere aleatoirement et son extension vient du contenu
reel du fichier. La table des extensions autorisees prime sur la configuration
d'environnement. La suppression est ancree par une expression stricte : elle ne
peut pas effacer une image du catalogue livre avec le projet.

**Defaut revele par l'ecriture des tests** : la classe etait declaree `final`
alors que son propre commentaire designe deux de ses methodes comme points
d'extension pour les tests. Aucun test de succes ne pouvait passer. Le code livre
sans test cachait un defaut que seule l'ecriture des tests pouvait faire
apparaitre.

---

## 5. Chantier D — Remediation Bloc 1

### Lien d'evitement (Cr 1.e.11)

Ce critere etait **entierement absent** : aucun lien d'ancrage dans tout le
projet. Un lien « Aller au contenu » est desormais le premier element focalisable
des cinq pages de la borne et du gabarit du back-office, invisible a la souris,
visible au clavier. Un sommaire d'ancres a ete ajoute a la vue admin la plus
longue.

### Le back-office sort de son angle mort

2460 lignes de CSS, 7 modules JS et 34 vues n'avaient jamais ete auditees. La
bascule vers la police adaptee aux personnes dyslexiques, la police elle-meme et
l'icone de site y sont maintenant disponibles, **partagees** avec la borne par
liens symboliques relatifs plutot que dupliquees. Verifie jusqu'en production :
les deux hotes servent le meme fichier de police, octet pour octet. Les derniers
selecteurs `:focus` nus passent en `:focus-visible`.

**Choix assume** : aucune balise canonique ni description de page n'est ajoutee
au back-office. Il porte `noindex, nofollow` deliberement, et les criteres de
referencement naturel visent l'interface client. Les ajouter serait du
remplissage.

### Audit d'accessibilite mesure (Cr 1.c.3)

La fiche de preuve admettait elle-meme que les ratios de contraste n'avaient
jamais ete mesures avec un outil dedie, reserve taguee non verifiee.

`axe-core` a ete branche sur les tests de bout en bout existants, contre une pile
jetable, jamais contre la production. **11 ecrans, 407 ratios mesures**, regles
WCAG 2.0 et 2.1 niveaux A et AA.

Resultat initial : **0 critique, 10 serieuses**, toutes sur la regle de contraste.

Trois causes, corrigees et remesurees :

| Ou | Avant | Apres | Ratio |
|---|---|---|---|
| Borne, texte attenue | `#767676` | `#6E6E6E` | 4,16 vers 4,67 |
| Back-office, sous-titres | `#6B7280` | `#69707D` | 4,43 vers 4,57 |
| Back-office, le « do » de Wakdo | `#C8920A` | `#AE7F09` | 2,77 vers 3,59 |

**Remesure : 0 violation, 407 mesures sur 407 conformes.** Le dossier conserve
les DEUX campagnes, avant et apres.

Le defaut le plus interessant du lot : le token de texte attenue portait un
commentaire affirmant « contraste AA minimum sur blanc ». C'etait **vrai sur
blanc** (4,54 pour un seuil de 4,50) et **faux des que le fond changeait**.
Aucune relecture humaine n'attrape ca.

Le jaune de marque `#FFC72C` n'est pas touche : seuls des accents secondaires
ont bouge, d'un a deux pour cent.

### Animations JavaScript (Cr 2.a.3 et 2.a.4)

Le referentiel demande des animations **JavaScript developpees**. Les trois
animations du projet etaient en CSS, et les quatre appels a
`requestAnimationFrame` servaient tous a differer un focus, pas a animer.

Le total du panneau de commande compte desormais vers sa nouvelle valeur au lieu
de sauter. Ecrit a la main : fonction d'attenuation, duree bornee, annulation
reelle si un nouvel ajout arrive avant la fin. Le texte porte la valeur exacte
des sa creation — l'animation ameliore un etat deja correct, elle n'est jamais la
condition pour l'obtenir.

`prefers-reduced-motion` etait **absent des 4511 lignes de CSS**. Il est
desormais respecte des deux cotes, avec une regle universelle cote CSS qui
couvrira aussi les animations futures.

Point traite sans qu'il soit demande : le panneau de commande est une region
annoncee aux lecteurs d'ecran. Animer un texte trente fois en quelques centaines
de millisecondes y aurait noye l'utilisateur sous les annonces. Le total est
sorti du flux d'annonces individuelles sans sortir de l'arbre d'accessibilite.

---

## 6. Mesures

| Indicateur | Debut de session | Fin de session |
|---|---|---|
| Tests PHP | 678 | **755** (1962 assertions) |
| Tests JavaScript | 191 | **233** |
| PHPStan niveau 6 | 0 erreur | 0 erreur |
| Violations d'accessibilite mesurees | non mesure | **0 sur 11 ecrans** |
| Ratios de contraste mesures | 0 | **407** |
| Retard de `main` sur `dev` | 37 commits | 3 commits |
| Deploiements automatiques executes | 0 | 1, verifie |

---

## 7. Criteres RNCP fermes ou renforces

| Critere | Avant | Apres |
|---|---|---|
| `Cr 7.d.3` integration et deploiement continus | jamais execute | operationnel et verifie |
| `Cr 4.g.3` application en ligne exempte de bugs | aucune preuve | ferme par le meme mecanisme |
| `Cr 4.g.4` testee en production | aucune preuve | ferme par le meme mecanisme |
| `Cr 1.e.11` ancres intra-page | **absent** | couvert sur 6 pages |
| `Cr 1.c.1` a `Cr 1.c.4` accessibilite | borne seule | borne + back-office |
| `Cr 1.c.3` contraste | affirme, non mesure | **mesure, 0 violation** |
| `Cr 2.a.3` animations JavaScript | aucune | livree et testee |
| `Cr 2.a.4` comportement navigateurs | partiel | couvert et documente |
| `Cr 4.a.4` schema fonctionnel | sous-declarait l'existant | a jour |
| Validation croisee Merise (mantra #34) | rompue | retablie |

---

## 8. Decisions prises pendant la session

1. **Deploiement par canal restreint** plutot que par acces direct a Docker
   depuis l'integration. Le job d'integration reste non privilegie.
2. **Adresse de l'hote explicite** plutot que deduite de la route par defaut,
   apres mesure que cette deduction etait fausse.
3. **Aucune balise de referencement sur le back-office**, qui est deliberement
   non indexe.
4. **La borne reste 100 % JavaScript vanilla.** Une librairie externe
   (`a11y-dialog`) a ete integree puis **retiree** sur decision de l'auteur : le
   critere C2.d sera traite par un expose oral, pas par une integration. Les
   outils de MESURE (axe-core, PHPUnit, PHPStan) ne sont pas concernes : ils ne
   sont jamais livres au navigateur.
5. **Correction de suivi** : l'alerte « stage en entreprise absent, bloquant pour
   le titre », portee depuis juin, etait **fausse**. L'alternance satisfait
   l'exigence d'experience professionnelle.

---

## 9. Erreurs commises pendant la session, et corrigees

Consignees parce qu'elles font partie du travail reel.

- **Deduction fausse sur le reseau.** La premiere version du deploiement
  supposait que la route par defaut d'un conteneur de job menait a l'hote. Elle
  n'y mene pas. Corrige apres mesure, au prix de plusieurs allers-retours.
- **Cle de deploiement supprimee sans verification.** Une paire de cles venait
  d'etre creee ; elle a ete ecrasee sans controle prealable.
- **Valeur de correction insuffisante.** La couleur proposee pour le « do »
  (`#B8860B`) corrigeait l'ecran qui echouait mais tombait a 2,94 sur un fond
  dore doux utilise ailleurs. Detectee en verifiant **tous** les usages du token,
  pas seulement celui qui echouait.
- **Chiffres relayes sans verification.** « Le motif est duplique dans cinq
  fichiers » : c'est quatre. « 217 tests JS existants » : c'etait 209. « Les
  horodatages sont dans le dictionnaire » : il affirmait le contraire.
- **`git add` masque.** Une commande dont les erreurs etaient redirigees a echoue
  en silence, puis un `git checkout` a ecrase cinq fichiers de corrections. Refaits
  a l'identique.

---

## 10. Pieges decouverts, a retenir

- **`vendor/` etait ignore par git.** La regle de la section Composer attrapait
  aussi le dossier de librairies front. Une dependance embarquee n'aurait jamais
  ete versionnee : l'import aurait echoue sur toute machine fraiche, alors qu'il
  fonctionnait en local ou le fichier existe sur le disque.
- **L'arbre de travail EST la production.** `docker-compose.prod.yml` monte
  `./src` directement : toute modification de PHP, CSS ou JavaScript part en ligne
  immediatement, committee ou non.
- **Le serveur Forgejo n'expose pas les journaux de job par son interface de
  programmation.** Methode qui fonctionne : un job par hypothese, le NOM du job
  rouge porte la reponse. Utilisee cinq fois dans la session, decisive a chaque
  fois.
- **Les noms des deux depots sont inverses** : la forge dit `corentin_wakdo`, le
  depot GitHub public dit `wakdo_corentin`.
- **Les bornes de ressources du runner ne sont pas en vigueur.** Le compose les
  porte depuis le 2026-09-16 ; le conteneur a demarre le 2026-09-10 et n'a jamais
  ete recree.

---

## 11. Reserves honnetes

- **Aucun audit sur lecteur d'ecran reel.** L'outil de mesure analyse le document,
  il ne remplace pas un test avec une technologie d'assistance.
- **Le HTML rendu du back-office n'a pas ete passe au validateur W3C.** La
  campagne existante se limite a la borne.
- **5 vues du back-office sur 34** ont ete analysees.
- **L'outil de mesure ne voit pas a l'interieur d'une image de fond.** Deux
  chevrons de listes deroulantes y sont encodes : leur contraste n'est pas couvert
  par l'audit. Verification manuelle : 4,8 sur blanc, au-dessus du seuil de 3
  exige pour un element non textuel.
- **Le piege de tabulation n'est verifie qu'en environnement simule**, pas dans un
  navigateur reel.

---

## 12. Ce qui reste a faire

### Code — a ma charge

| Priorite | Sujet | Critere vise |
|---|---|---|
| P1 | `app_env` vaut `dev` en production ; 15 reglages de securite tournent sur les defauts du code au lieu d'etre fixes | Bloc 5, question de jury evidente |
| P2 | Validation en temps reel sur les formulaires du back-office, tenue par un seul champ aujourd'hui | `Cr 2.b.1` |
| P2 | Banniere d'accueil de 1,2 Mo, non differee ; aucune image au format moderne | `Cr 1.e.8` |
| P2 | Attributs `title` sur les liens ; balisage schema.org au-dela de l'accueil | `Cr 1.e.7`, `Cr 1.e.3` |
| P2 | Numerotation de sections cassee dans `style.css` (deux « 12. », deux « 13. ») | `Cr 1.d.2` |
| P3 | Gate de couverture en integration continue | qualite |
| P3 | Menage : branches de sonde a supprimer ; le script d'ouverture de PR force l'ecrasement meme vers `main` | proprete du depot |

### Machine — a ta charge

| Priorite | Sujet |
|---|---|
| P2 | Recreer le conteneur du runner pour appliquer les bornes de ressources ecrites le 2026-09-16, et fixer explicitement la concurrence a un job |

### Soutenance — a nous deux

| Priorite | Sujet | Etat |
|---|---|---|
| P1 | **Dossier unique complet** sur Google Docs : plan valide, 125 a 165 pages, table de tracabilite des 96 criteres | plan fige, connexion verifiee, **manque 2 paragraphes de ta main sur ton parcours** |
| P1 | **Exercices de maitrise du code** : trouve-ou, explique, modifie chronometre, casse-et-repare. Commencer par le parcours de commande | a lancer |
| P1 | **Expose d'une librairie JavaScript** pour `C2.d` | **en attente** : librairie imposee ou libre ? quel format ? |
| P2 | Repetition de la demonstration en direct | a planifier |

### Strategie retenue pour l'oral

Transparence assumee sur l'usage de l'IA, formulee comme un **outil dirige et
verifie**, pas comme une delegation. La formulation n'est pas « j'ai utilise
l'IA » mais « j'ai dirige un outil, j'ai verifie sa sortie, voici ou je l'ai
corrige ».

Les preuves existent et sont verifiables : le journal, les decisions
d'architecture, le dossier de preuves, et les moments ou l'assistant s'est trompe
et ou la correction est venue de l'auteur du projet. La section 9 de cette entree
en liste cinq.

Risque nomme : annoncer la maitrise puis bloquer sur une modification en direct
est le pire des deux mondes. Les exercices ne sont pas un bonus, ils sont la
preuve de la phrase.

---

## 13. Liens vers les artefacts

- `docs/soutenance/preuves/` — dossier de preuves Bloc 1, fiches 01 a 07
- `docs/soutenance/preuves/rapports/` — 13 artefacts de mesure, dont 407 ratios en CSV
- `docs/architecture/deployment.md` — le deploiement continu et sa topologie reelle
- `docs/architecture/forgejo-actions-runner.md` — l'etat mesure du runner
- `.forgejo/workflows/deploy.yml` — le mecanisme de deploiement
- `scripts/deploy.sh` — le script et sa garde d'arbre propre
- `tests/e2e/run-a11y.sh` — le lanceur de l'audit d'accessibilite
