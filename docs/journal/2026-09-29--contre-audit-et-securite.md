# Contre-audit independant et durcissement de la suite de securite

**Date** : 2026-09-29
**Branche** : `docs/contre-audit` (commits directs) — pas encore fusionnee vers `dev` ni `main` a
la date d'ecriture ; la production (`main`) reste au commit `dc1829d` (documentation du
28/09, PR #196), sans aucun des correctifs de cette journee.
**Duree estimee** : une journee de travail assiste

---

## La demande

L'auteur, en preparant les dernieres corrections avant l'oral, a demande une verification
reelle plutot qu'une confirmation de convenance : un correcteur qui relit son propre travail
ne peut pas garantir qu'il n'a rien laisse passer. La veille, une passe de documentation
(#195) avait deja recale plusieurs chiffres sur le code livre — mais cette passe elle-meme
n'avait pas ete verifiee par un tiers.

## Ce qui a ete fait

- **Contre-audit independant a 5 relecteurs neufs**, chacun sans memoire de la session qui a
  produit #195, charges de comparer la documentation au code reel plutot qu'a ce que la veille
  avait ecrit. Le contre-audit a trouve environ 70 ecarts bloquants entre les documents et le
  code, dont une partie introduite PAR la correction de la veille elle-meme (un chiffre corrige
  dans un fichier mais pas dans un autre, par exemple).
- Chaque ecart a ete corrige avec une preuve de code a l'appui (numero de ligne, nom de test,
  ou commit), et non par une simple relecture supplementaire.
- Les corrections de documentation ont pris la forme d'**errata dates** ajoutes aux fiches
  existantes (ADR 0004/0005/0006/0010/0015/0017/0018/0019/0020, `mlt.md`, `mcd.md`, `mld.md`,
  `mct.md`, `dictionary.md`, diagrammes UML) plutot que de reecritures qui auraient efface la
  trace du premier jet — dans la continuite de la pratique deja en place au 28/09.
- En creusant certains de ces ecarts, trois defauts de code reels (pas seulement
  documentaires) ont ete trouves et corriges en TDD (detail dans la section suivante).
- Une suite de tests de securite executables a ete ecrite et executee contre l'application
  **de l'exterieur** (100 tests Playwright + 5 tests PHP, commit `e72ad69`, fiche
  `docs/soutenance/preuves/10-tests-securite.md`) : elle a trouve 11 ecarts supplementaires
  (deux importants, neuf mineurs), tous corriges le jour meme en TDD par trois commits
  (`08d7a96`, `ef7fd37`, `fce3085`) ; un complement ecrit le meme jour (`33538c6`, plafond de
  50 articles par commande) n'en corrige aucun directement mais durcit la meme surface ; la
  suite a ensuite ete rejouee et confirme 0 test marque en echec restant.
- Choix d'**Insomnia** pour les demonstrations d'API prevues a l'oral, en plus de Postman et
  Bruno deja documentes : Insomnia n'a pas de collection generee par ce depot, elle se
  **construit a la main**, requete par requete, en suivant le meme scenario ; les collections
  Postman et Bruno du depot servent de reference pour la construire (`docs/api/demo-api.md`
  section 8). Rappel ecrit et tenu de l'interdiction de toucher a la configuration Traefik de
  l'hote de production pendant ces verifications.
- **Constats de la revue adversariale des six premiers correctifs** (`680820f`) : la table
  `password_reset_throttle` n'etait purgee par aucun cron (ajoutee a
  `docker/cron/scripts/purge-throttle.sh`) et gardait l'adresse tapee en clair (empreinte
  SHA-256, migration `0021`) ; un administrateur qui change le mot de passe d'un compte ferme
  desormais ses sessions ouvertes (incrementation de `session_epoch`) ; le chevalet et le mode
  de service de la borne sont verifies en types stricts.
- **Deux correctifs supplementaires** trouves en poursuivant la meme journee, au-dela des onze
  ecarts de la suite de securite : l'appariement des emplacements d'un menu modifie se faisait
  par position, ce qui pouvait deplacer le mauvais emplacement en cas de reordonnancement
  (corrige par type et nom d'abord, lignes verrouillees en base, `e9f00d8`) ; la disponibilite
  d'une option n'etait pas filtree par format de menu (une option indisponible en Maxi restait
  commandable), et la quantite saisie au comptoir n'etait pas bornee comme a la borne
  (`186c5d7`, plafond de 20 par ligne, messages clairs au lieu d'une correction silencieuse).
- **Second contre-audit, documentaire cette fois** : 5 relecteurs neufs sur la documentation
  seule (pas le code). La correction a d'abord ete confiee a 3 correcteurs sur des perimetres
  de fichiers disjoints (documentation generale et API, conception Merise/UML/ADR, support de
  soutenance) ; l'auteur les a arretes en cours de route, et la passe a ete terminee
  directement par le coordinateur, en relisant et en verifiant dans le code ce que les
  correcteurs avaient deja modifie. Avant cette passe, les mesures (tests, comptes de
  fichiers, chiffres de suites) ont ete figees sur une copie unique du code, pour que tous les
  documents citent les memes chiffres. Le balayage automatique du back-office, rejoue dans le
  cadre de cette mesure figee, a trouve un defaut d'affichage (detail dans la section
  suivante), corrige avant la fin de la journee.

## Pourquoi — decisions et alternatives

- **Decision : un tiers neuf, pas le meme correcteur.** Alternative ecartee : une seconde
  relecture par la meme session que celle qui a produit #195. Raison : elle partage les
  angles morts de la premiere passe — elle relit ce qu'elle a ecrit, pas ce que le code fait
  reellement. Lecon retenue : un correcteur ne valide pas son propre travail.
- **Decision : errata dates plutot que reecriture.** Alternative ecartee : reecrire chaque
  fiche pour qu'elle paraisse juste depuis le depart. Raison : une reecriture efface la trace
  de l'erreur et de sa correction, qui est elle-meme une preuve de demarche pour le jury.
- **Decision : corriger le code trouve en defaut, en TDD, pas seulement le document.**
  Alternative ecartee : documenter l'ecart de comportement sans toucher au code. Raison : deux
  des trois defauts trouves etaient des failles exploitables reelles (une 500 provoquee par une
  saisie normale, une fuite dans le journal d'audit) ; les documenter sans les corriger aurait
  laisse le defaut en production.
- **Decision : suite de securite automatisee plutot qu'un relecteur supplementaire sur ce
  theme.** Alternative ecartee : un sixieme relecteur humain, cible sur la securite. Raison :
  une suite reproductible qui attaque l'application de l'exterieur trouve des classes de
  defauts (en-tetes manquants, sessions non invalidees, absence de plafond) qu'une lecture de
  code ne revele pas necessairement, et elle reste rejouable a chaque changement futur.
- **Decision : Insomnia pour la demonstration en direct, construit a la main.** Alternative
  ecartee : generer aussi une collection Insomnia depuis la source commune
  (`scripts/gen_postman.py`/`scripts/gen_bruno.py`). Raison : les deux collections generees
  (Postman, Bruno) restent la reference versionnee du depot ; la collection Insomnia elle-meme
  n'a pas besoin d'etre versionnee ni generee, elle se construit a la main pendant la
  preparation de l'oral, avec les deux autres comme reference pour ne rien oublier.
- **Decision : une mesure figee, coordonnee, plutot que trois mesures independantes.**
  Alternative ecartee : laisser chaque correcteur relancer les suites de tests de son cote.
  Raison : trois relances independantes des memes suites, a quelques minutes d'intervalle,
  auraient pu produire des chiffres legerement differents (un test intermittent, un compte de
  fichiers qui bouge) et introduire de nouveaux ecarts entre documents — exactement le defaut
  que ce second contre-audit corrigeait. Une seule mesure, sur une copie figee du code, partagee
  par les trois correcteurs, supprime ce risque.

## Defauts trouves en chemin

- **Modifier un menu deja commande renvoyait une 500.** `MenuRepositoryDbTest`, rejoue sur une
  base reelle par le contre-audit, a reproduit l'erreur : la mise a jour d'un menu supprimait
  puis reinserait ses emplacements (delete-and-reinsert), ce qui violait une contrainte de
  cle etrangere `RESTRICT` des qu'un emplacement etait deja reference par une commande.
  Corrige (`27671b0`) : les emplacements sont desormais reconcilies en place, et le retrait
  d'un emplacement deja commande est refuse en 409 au lieu de planter en 500 ; un test qui
  echouait avant le correctif le prouve. La vraie 500 (base arretee) reste demontree
  separement par `security-dbdown.spec.js` ; le test qui la provoquait par une charge normale
  verifie desormais le refus `422` (`2fe8a4a`).
- **Le journal d'audit ecrivait l'adresse tapee lors d'un code personnel faux.** Une tentative
  de PIN echouee ecrivait l'adresse saisie en clair dans `audit_log.summary`, y compris pour
  une adresse qui ne correspond a aucun compte — un champ texte libre qui echappe a la fois a
  la purge de retention et a l'anonymisation RGPD d'un compte efface. Corrige selon l'option
  retenue par l'auteur (option A) : le point d'ecriture unique `PinGate::auditFailedPin()`
  n'ecrit plus que le contexte de l'action et, si l'adresse correspond a un compte existant,
  son identifiant stable (`aa2a843`, migration `0019_pin_failed_audit_minimisation.sql`, qui
  purge aussi les lignes deja ecrites) — RGPD art. 5.1.c (minimisation).
- **Une option de menu en rupture restait commandable.** Trouve par la suite de securite
  (ecart secondaire du commit `fce3085`) : le filtre de disponibilite n'etait pas applique aux
  options de slot d'un menu. Corrige dans le meme commit, avec le test qui le prouve.
- **Onze ecarts de securite supplementaires**, trouves par la suite `security-*.spec.js` en
  attaquant l'application de l'exterieur : le plus important, une commande anonyme sans
  plafond de quantite pouvait vider le stock d'un ingredient partage en deux requetes (I1) ; un
  changement de role n'etait applique qu'a la prochaine connexion, pas a une session deja
  ouverte (i1) ; neuf mineurs (en-tetes de securite manquants, session ouverte sans necessite
  sur l'API publique, mot de passe oublie sans limite de frequence...). Ces onze ecarts sont
  corriges par trois commits (`08d7a96` en-tetes, `ef7fd37` role relu en base + politique de
  session, `fce3085` commande bornee et disponibilite d'option de menu). `33538c6` (plafond de
  50 articles par commande, `ORDER_TOO_LARGE`) est un complement ecrit le meme jour, pas le
  correctif d'un des onze ecarts trouves par la suite. Detail complet et preuve par test :
  `docs/soutenance/preuves/10-tests-securite.md`.
- **Modifier un menu deja commande pouvait deplacer le mauvais emplacement.** L'appariement des
  emplacements d'un menu modifie se faisait par position (l'emplacement n'a pas d'identifiant
  stable cote client) : un reordonnancement pouvait faire correspondre le mauvais emplacement a
  une commande deja passee. Corrige (`e9f00d8`) : l'appariement se fait d'abord par (type, nom),
  puis par position en repli ; les lignes sont verrouillees (`FOR UPDATE`) et une violation de
  cle concurrente est traduite en 409 plutot que de remonter telle quelle.
- **Une option de menu en rupture restait commandable selon le format choisi.** Le champ
  `option_is_orderable` expose par `/api/menus/{id}` ne distinguait pas le format Maxi du
  format standard : une option marquee indisponible en Maxi seulement restait proposee et
  commandable dans ce format, a la borne comme au comptoir. Corrige (`186c5d7`) : un champ
  `option_is_orderable_maxi` distinct grise et deselectionne l'option au changement de format ;
  au comptoir, la quantite saisie est desormais strictement bornee (1 a 20, refus `422` au lieu
  d'une correction silencieuse a 1), meme regle qu'a la borne.
- **Le nom d'une migration recente debordait de sa carte sur la page Sante.** Le balayage
  automatique du back-office (rejoue dans le cadre du second contre-audit, mesure figee sur
  `186c5d7`) a trouve 1 echec sur 214 tests : un debordement horizontal de 14 px sur
  `/admin/health` a une largeur de 1366 px. Cause : le nom du dernier fichier de migration
  (`0021_password_reset_throttle_hash_identifier.sql`) affiche dans la carte KPI « Migrations » ne
  revient pas a la ligne. Corrige (`c2b8c1c`, regle `overflow-wrap` sur `.health-kpi-detail`) ;
  le balayage rejoue sur ce correctif confirme 12 tests sur 12, 4 807 verifications, 0 echec.

## Garde-fous

- `MenuRepositoryDbTest` (test d'integration sur base reelle) protege desormais la
  reconciliation en place des emplacements de menu, y compris l'appariement par (type, nom)
  et le verrouillage des lignes (`e9f00d8`).
- La migration `0019_pin_failed_audit_minimisation.sql` nettoie les lignes deja ecrites, en
  plus du correctif applicatif : un rejeu ne trouve plus rien a modifier (idempotent). Meme
  logique pour la migration `0021` (`680820f`), qui convertit les adresses deja ecrites dans
  `password_reset_throttle` en empreintes SHA-256 ; la purge de cette table est faite par le
  cron (`docker/cron/scripts/purge-throttle.sh`, prouvee par `tests/shell/purge-throttle.test.sh`).
- La suite `tests/e2e/security-*.spec.js` (14 fichiers de specs sur 36, executee par
  `bash tests/e2e/run-security.sh`) reste dans le depot et se rejoue a la demande contre une
  pile jetable, en trois phases (principale, reinitialisation, base arretee) ; elle confirme
  0 test marque en echec restant apres les correctifs.
- Le balayage automatique du back-office (inclus dans la suite navigateur complete) protege
  desormais aussi le rendu de la page Sante contre un debordement horizontal ; c'est lui qui a
  trouve puis confirme la correction du defaut d'affichage de cette journee.
- Rappel ecrit et tenu pendant toute la session : aucune commande destructive ni modification
  de la configuration Traefik de l'hote de production.
- Le risque residuel sur la commande anonyme est ecrit plutot que presente comme regle : les
  plafonds bornent ce qu'UNE commande consomme, pas le nombre de commandes successives depuis
  la meme source ; l'identite de l'IP cliente releve de Traefik et n'est pas verifiee par ce
  projet.

## Mesures

Chiffres ci-dessous : copie figee, mesuree par le coordinateur du second contre-audit (pas
relancee par chaque correcteur, pour eviter qu'une suite rejouee trois fois independamment ne
produise trois chiffres legerement differents). Code valide : `fe8b738` (les rapports
d'accessibilite sont deposes par le commit suivant, `3212b6c`, qui ne touche pas au code).

- PHPStan (niveau 6, sur `186c5d7`) : 0 erreur.
- Tests PHP (PHPUnit, sur `186c5d7`) : 2 535 tests, 8 716 assertions, 0 echec, 0 depreciation.
- Tests JavaScript (`node:test`, sur `186c5d7`) : 492 tests, 0 echec.
- Tests shell : `tests/shell/demo-snapshot-lib.test.sh` 37 assertions, 0 echec ;
  `tests/shell/purge-throttle.test.sh` 10 assertions, 0 echec.
- Suite navigateur complete (sur `186c5d7`, pile jetable) : 214 tests, 202 reussis, 11 sautes,
  1 echec — le debordement horizontal de la carte KPI « Migrations » decrit ci-dessus. Corrige
  par `c2b8c1c` ; balayage du back-office rejoue sur ce commit : 12 tests sur 12,
  4 807 verifications, 0 echec.
- Suite de securite `tests/e2e/run-security.sh` (sur `c2b8c1c`, 3 phases, `APP_DEBUG=false`) :
  phase principale 99 reussis + 8 sautes (107 tests, les sautes se jouent dans les phases
  suivantes), phase reinitialisation 4 sur 4, phase base arretee 4 sur 4 ; 0 echec.
- Reponses capturees de la page Sante (sur `c2b8c1c`, deposees par `fe8b738`) : 158 routes sur
  158 avec leur succes capture ; 670 refus obtenus sur 689 tentes, les 19 autres enregistres
  comme « non reproduit » avec le code reellement observe (meme resultat que la capture du matin
  sur `33538c6`).
- Audit d'accessibilite `tests/e2e/run-a11y.sh` (sur `fe8b738`, 2026-09-29 12:44 UTC, depose
  par `3212b6c`) : 19 ecrans, 934 mesures de contraste, 0 violation, 0 contraste sous le seuil.
- Collections Postman/Bruno : 91 requetes, 91 avec une assertion ; dossiers renommes
  « 1. Connexion » a « 11. Fin de demo ».
- Comptes du depot (sur `fe8b738`) : 158 routes (57 sous `/admin/api`, 10 sous l'API publique
  `/api`) ; 24 tables ; 20 fichiers de migration (0001 a 0021, sans 0004 — la prochaine sera
  0022) ; 20 fiches de decision (`docs/adr`) ; 17 entrees de journal (`docs/journal`, hors
  `README.md`) ; 36 fichiers de tests navigateur (`tests/e2e/*.spec.js`), dont 14
  `security-*.spec.js` ; 138 fichiers PHP sous `src/app`.

## Questions anticipees du jury

- **Q** : « Pourquoi une suite de tests de securite ecrite si pres de la soutenance, et pas
  avant ? »
  **R** : Les protections existaient et etaient deja testees unitairement, ainsi que par la
  capture de la page Sante ; il manquait une suite qui attaque l'application de l'exterieur,
  comme le ferait un client malveillant, sur une pile complete. Le contre-audit independant a
  rendu ce manque visible, donc je l'ai comble avant l'oral plutot qu'apres.

- **Q** : « Un contre-audit qui trouve 70 ecarts apres une passe de correction, ca ne montre
  pas que le travail precedent etait mal fait ? »
  **R** : Ca montre surtout qu'un correcteur ne peut pas auditer fiablement son propre
  travail — c'est la lecon que je retiens et que j'assume. La reponse n'etait pas de me relire
  une troisieme fois, mais de faire relire par quelqu'un qui n'a pas produit le premier
  correctif.

- **Q** : « Ces failles de securite trouvees si pres de l'oral, ca n'inquiete pas sur ce qui
  reste a trouver ? »
  **R** : Une suite qui trouve des defauts et les fait corriger avant la livraison est le
  signe que le filet fonctionne. Je prefere un defaut trouve et corrige, date et rattache a
  un commit, a un defaut non cherche. La suite reste dans le depot et se rejoue a la demande.

- **Q** : « Pourquoi ajouter Insomnia si Postman et Bruno etaient deja documentes ? »
  **R** : Les deux collections generees restent la reference versionnee dans le depot. Le choix
  de l'outil de demonstration en direct n'a pas besoin d'etre genere ni versionne : la
  collection Insomnia se construit a la main pour l'oral, en suivant les deux autres comme
  reference, pas en remplacement de la documentation.

- **Q** : « Avez-vous verifie que les correctifs de securite n'ont pas casse l'accessibilite ? »
  **R** : Oui, a deux reprises : une premiere fois juste apres les quatre correctifs de
  securite (commit `3fd08c4`), puis une seconde fois en fin de journee (commit `3212b6c`, sur
  le code final `fe8b738`), apres les correctifs supplementaires sur les menus et la
  disponibilite d'option. Resultat stable les deux fois : 19 ecrans, 0 violation. La
  seconde mesure comptait vraiment : `fce3085` et `186c5d7` changent le rendu de la borne et du
  comptoir (options grisees, deselection au changement de format, messages de refus dans la
  page), et le balayage navigateur du meme jour a trouve un defaut d'affichage sur la page Sante
  (corrige par `c2b8c1c`).

- **Q** : « Le second contre-audit a trouve un bug d'affichage — ce n'est pas contradictoire
  avec une suite navigateur deja verte ? »
  **R** : Non : la suite etait verte AVANT que le dernier fichier de migration du jour
  (`0021`) n'existe. Des qu'il a ete cree, son nom, plus long que les precedents, a fait
  deborder sa carte sur la page Sante a une largeur d'ecran precise (1366 px) — un cas que la
  suite navigateur a immediatement detecte au rejeu suivant. C'est le comportement attendu
  d'une suite executable : elle retrouve un defaut introduit par un changement recent au lieu
  de rester silencieusement verte.

- **Q** : « Ou en est la production a la fin de cette journee ? »
  **R** : Nulle part encore, et c'est assume : tout le travail du 29/09 (contre-audit, quatre
  defauts de code corriges, onze ecarts de securite, deux correctifs supplementaires, second
  contre-audit documentaire) vit sur la branche `docs/contre-audit`, qui n'est fusionnee ni
  vers `dev` ni vers `main`. La production reste au commit `dc1829d`, celui de la
  documentation du 28/09 (#196). La fusion et la release restent a faire avant l'oral.
