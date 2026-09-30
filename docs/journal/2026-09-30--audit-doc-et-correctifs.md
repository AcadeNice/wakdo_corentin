# Cinq audits de documentation independants, huit defauts de code corriges en deux revues adversariales

**Date** : 2026-09-30
**Auteur** : BYAN
**Branche** : `docs/audit-3009` pour la documentation (commits directs) ; `fix/audit-3009` pour le
code des correctifs — aucune des deux n'est fusionnee vers `dev` ni `main` a la date
d'ecriture. La production (`main`) reste au commit `aab4e96` (release du 29/09, PR #198),
sans aucun des correctifs de cette journee.
**Duree estimee** : une journee de travail assiste

---

## La demande

A trois jours de l'oral, l'auteur a demande une derniere passe de verite sur la
documentation, apres celle du 29/09 (contre-audit, voir l'entree de la veille) : relire
chaque affirmation de la documentation contre le code livre, plutot que de se fier a la
derniere correction en date. Comme la veille, la regle restait la meme : un correcteur ne
valide pas fiablement son propre travail.

## Ce qui a ete fait

- **Cinq audits de documentation independants**, un par angle (metier, PHP, front, securite,
  API), chacun mene en lecture seule contre le code de production (`aab4e96`) sans memoire
  des passes precedentes. A eux cinq : **53 constats**, dont **9 classes BLOQUANT-ORAL**
  (1 metier — la TVA presentee comme liee au mode de service, deja corrige avant cette
  journee — 6 PHP, 1 front, 1 securite) et **8 defauts de code reels**, hors documentation
  (D-1 a D-7, plus une restriction manquante A-2, relevee par l'audit API en lisant le code
  de production).
- Chaque defaut de code a ete corrige **en TDD**, sur la branche `fix/audit-3009` : test
  rouge d'abord, puis le correctif qui le fait passer.
- **Deux tours de revue adversariale independante**, chacun par un agent qui n'avait pas
  ecrit le correctif qu'il relit :
  - La **premiere revue** (`revue-code.md`) a **bloque D-5** (le verrou de session PHP
    restait tenu pendant l'envoi SMTP differe du courriel de reinitialisation : une
    deuxieme requete sur le meme cookie rouvrait le canal par le temps que ce correctif
    devait justement fermer) et **D-7** (le jeton de reinitialisation restait journalise
    par l'en-tete `Referer`, pas seulement par la ligne de requete deja traitee). Elle a
    aussi fait **reprendre D-1** (la limite se contournait par la remise a zero partagee
    avec le budget du PIN) et **D-2** (le code etait juste mais `docs/merise/mlt.md`
    RG-9 disait encore l'inverse). D-3, D-4, D-6 et A-2 etaient approuves des ce premier
    tour, avec des points mineurs.
  - La **seconde revue** (`revue-code-2.md`) n'a **rien bloque** (« verdict global :
    CHANGES, aucun point bloquant ») mais a demande un correctif supplementaire,
    **D-7.b** : fermer le canal du jeton avait eu un effet de bord — l'hote admin
    n'envoyait plus `Referrer-Policy` du tout sur les reponses que seul Apache sert
    (fichiers statiques, erreurs 403/502/503), une regression par rapport a l'etat
    d'avant D-7.
- Chaque correctif retenu, avec son commit : **A-2** (`494fa17`, fusionne par `4cc9e43`) ;
  **D-1** (`1fcfbf4`, repris par `93bdf34` puis durci par `382acaa` et `a64606d`) ; **D-2**
  (`4f37f7c`, documentation corrigee dans `85eb9dc`) ; **D-3** (`706c21a`, teste plus
  rigoureusement par `ba7646b`) ; **D-4** (`3a27160`, generalise par `7877da2`, couvre un
  role vise desactive par `954951f`, portee des sources visibles fermee par `c5a8fc4`) ;
  **D-5** (`5c38f60`, ferme par `abe99c2`) ; **D-6** (`4804524`, message et commentaire
  corriges par `ff1450b`, `b978523`, `e15fd55`) ; **D-7** (`bd1180a`, D-7.a par `4289ae9`,
  D-7.b par `a8af3d2`). Fusions intermediaires : `b256945`, `cf31966`, `ccae19e`, `836be11`,
  `17c8fe5`.
- **Documentation recalee** en consequence : `docs/merise/mct.md` (cinq occurrences de
  « re-autorise par PIN » remplacees par « re-authentifie », le PIN identifiant l'acteur
  sans verifier sa permission), `docs/merise/mlt.md` (RG-T15 : la borne construit ses
  gabarits par `innerHTML` echappe par `escHtml()`, pas par `textContent`), les en-tetes de
  `docs/merise/{mcd,mld,mct}.md` et `docs/soutenance/preuves/README.md` (etat fusionne dans
  `main`, production a `aab4e96`), `docs/api/conventions.md` (`Referrer-Policy` differencie
  par hote depuis D-7.b, requetes 1/3/5 de la demo API portent aussi `Content-Type`), le
  plan d'oral (`docs/soutenance/oral-blanc-plan-40min.md` : Insomnia construit a la main,
  la nuance sur « prete » sans permission dediee, le script de mesure du canal par le temps
  non versionne) et `slides.html`.
- La fiche de preuve 10 (`docs/soutenance/preuves/10-tests-securite.md`) recoit une nouvelle
  section 8 qui documente les huit correctifs avec leur preuve de revue, et sa reserve
  D-7.b est fermee avec le detail du correctif `a8af3d2`.

## Incidents de la journee

- **Deux `docker compose up` sans projet Compose dedie ont ete refuses par Docker** : le nom
  de conteneur qu'ils tentaient de recreer collisionnait avec celui d'un conteneur de
  production deja en cours d'execution. Docker a refuse la collision de nom plutot que de
  l'ecraser ; aucun conteneur de production n'a ete touche.
- **Une boucle de redemarrage d'un conteneur jetable de demonstration a declenche l'alerte
  de supervision.** Le conteneur en cause n'etait pas un conteneur de production ; il a ete
  identifie et arrete.
- **Une erreur de ma part** : un message de suivi a ete reformule sans relancer la suite de
  tests derriere, ce qu'une relecture plus attentive aurait evite. La suite complete,
  rejouee ensuite, a attrape **3 echecs** que ce message aurait laisses passer sans
  correction.

## Pourquoi — decisions et alternatives

- **Decision : cinq audits par angle plutot qu'un seul audit generaliste.** Alternative
  ecartee : une seule relecture transverse. Raison : un audit generaliste dilue son
  attention sur un projet de cette taille ; un audit par angle (metier, PHP, front,
  securite, API) approfondit chaque surface avec le vocabulaire qui lui est propre.
- **Decision : corriger les huit defauts de code trouves, pas seulement les documenter.**
  Alternative ecartee : ecrire les limites dans la documentation sans toucher au code.
  Raison : plusieurs de ces defauts sont des failles reelles (contournement de la
  limitation de connexion, escalade de privilege, jeton dans un journal) ; les documenter
  sans les corriger les aurait laisses en l'etat pour l'oral et au-dela.
- **Decision : deux tours de revue adversariale, pas un seul.** Alternative ecartee :
  s'arreter a la premiere revue, qui n'avait plus de point bloquant apres les reprises.
  Raison : la premiere revue avait elle-meme genere un nouveau correctif (D-7.a) sans le
  revalider par un tiers ; la seconde a trouve la regression que ce correctif avait
  introduite (D-7.b), qu'aucune suite existante ne couvrait encore.
- **Decision : garder l'erreur d'evaluation initiale sur A-2 dans le journal plutot que de
  la lisser.** L'impact de A-2 avait d'abord ete surestime — le risque n'est pas que le
  paiement public rejoue un encaissement (les commandes comptoir et drive sont deja
  encaissees a leur creation, avant meme ce chemin), mais que la route publique
  `POST /api/orders/{n}/pay` laisse lire, sans authentification, le statut et le total
  d'une commande de n'importe quel canal. Corriger l'evaluation avant d'ecrire le
  correctif final a change la formulation de la fiche, pas le correctif lui-meme.

## Chiffres finaux (mesures le 30/09, code au commit `17c8fe5`)

- PHPStan (niveau 6) : 0 erreur.
- Tests PHP (PHPUnit) : 2 610 tests, 8 924 assertions, 0 echec, 0 depreciation.
- Tests JavaScript (`node:test`) : 492 tests, 0 echec (inchange par rapport au 29/09).
- Tests shell : 2 fichiers verts (nombre de cas non recompte ce jour-la, reste a 47
  assertions au 29/09).
- Suite navigateur complete (36 fichiers de specs, pile jetable) : 212 reussis, 11 sautes,
  0 echec ; balayage du back-office : 4 812 verifications, 0 echec.
- Suite de securite `tests/e2e/run-security.sh` (3 phases, `APP_DEBUG=false`) : phase
  principale 108 reussis (8 sautes, joues dans les phases suivantes), phase
  reinitialisation 5 reussis, phase base arretee 4 reussis ; 117 reussis, 0 echec.
- Comptes du depot : 158 routes, 24 tables, 20 fichiers de migration (0001 a 0021, sans
  0004 ; la prochaine sera 0022), 20 fiches de decision, 17 entrees de journal, 141
  fichiers PHP sous `src/app`.

**Non rejoues le 30/09** : l'audit d'accessibilite (19 ecrans, 934 mesures de contraste,
0 violation, mesure du 29/09) et la capture des reponses de la page Sante (158 succes, 670
refus obtenus sur 689 tentes, capture du 29/09) — aucune route n'a ete ajoutee le 30/09,
ces deux mesures restent valides telles quelles.

**Production** : sert encore `aab4e96` (release du 29/09) au moment d'ecrire cette entree.
Les huit correctifs de cette journee (D-1 a D-7, A-2) seront livres par la release du
30/09, dont le commit n'est pas encore connu.

## Questions anticipees du jury

- **Q** : « Encore une journee de corrections trois jours avant l'oral, ca n'inquiete pas
  sur la stabilite du projet ? »
  **R** : Le rythme est le meme que la veille : un audit independant trouve, un correctif
  le ferme en TDD, une revue adversariale verifie que le correctif ferme vraiment ce qu'il
  pretend fermer. Je prefere ce rythme soutenu jusqu'a la derniere minute a une pause qui
  laisserait un defaut trouve non corrige.
- **Q** : « La seconde revue a trouve une regression introduite par la premiere. Ca ne
  remet pas en cause la methode ? »
  **R** : Au contraire : c'est la preuve que la methode fonctionne. Un correctif ecrit dans
  l'urgence pour fermer un point bloquant peut introduire un effet de bord ailleurs ; c'est
  exactement ce qu'un second tour de revue, independant du premier, est fait pour attraper.
  Sans lui, D-7.b serait reste en production non detecte.
- **Q** : « Pourquoi ecrire les deux incidents Docker et l'erreur de message dans le
  journal ? »
  **R** : Parce que le journal sert aussi a la relecture RNCP, et qu'un incident de session
  sans consequence sur la production (nom de conteneur en collision, alerte de supervision
  sur un conteneur jetable, un message a reformuler) est plus utile ecrit que tu.

## Liens vers artefacts

- Rapports d'audit : `audit-doc-3009/{metier,php,front,secu,api}.md` (hors depot, dossier de
  travail de cette session).
- Revues adversariales : `audit-doc-3009/revue-code.md`, `audit-doc-3009/revue-code-2.md`.
- Commits de code : `494fa17`, `1fcfbf4`, `4f37f7c`, `706c21a`, `3a27160`, `5c38f60`,
  `bd1180a`, `4804524`, `93bdf34`, `4289ae9`, `7877da2`, `954951f`, `9ed68b6`, `ff1450b`,
  `abe99c2`, `ba7646b`, `c5a8fc4`, `b978523`, `e15fd55`, `382acaa`, `a64606d`, `a8af3d2`,
  et les fusions `b256945`, `cf31966`, `ccae19e`, `836be11`, `17c8fe5`.
- Documentation : `docs/soutenance/preuves/10-tests-securite.md` (section 8), `docs/api/
  conventions.md`, `docs/merise/{mcd,mld,mct,mlt}.md`, `docs/soutenance/oral-blanc-plan-
  40min.md`, `docs/soutenance/slides.html`, `docs/domaines/users.md`,
  `docs/PROJECT_CONTEXT.md`.
