# Contre-audit independant et durcissement de la suite de securite

**Date** : 2026-09-29
**Branche** : `dev` (commits directs, fusionnes vers `main` par #196) puis `docs/contre-audit`
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
  (deux importants, neuf mineurs), tous corriges le jour meme en TDD par quatre commits
  (`08d7a96`, `ef7fd37`, `fce3085`, `33538c6`) ; la suite a ensuite ete rejouee et confirme
  0 test marque en echec restant.
- Passage des demonstrations d'API prevues a l'oral de Postman/Bruno vers **Insomnia**
  (import des collections existantes), et rappel ecrit de l'interdiction de toucher a la
  configuration Traefik de l'hote de production pendant ces verifications.

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
- **Decision : Insomnia pour la demonstration en direct.** Alternative : garder Postman/Bruno
  tels que deja documentes. Raison : les deux collections existantes restent la reference
  versionnee et s'importent telles quelles dans Insomnia ; l'outil de demonstration lui-meme
  n'a pas besoin d'etre versionne.

## Defauts trouves en chemin

- **Modifier un menu deja commande renvoyait une 500.** `MenuRepositoryDbTest`, rejoue sur une
  base reelle par le contre-audit, a reproduit l'erreur : la mise a jour d'un menu supprimait
  puis reinserait ses emplacements (delete-and-reinsert), ce qui violait une contrainte de
  cle etrangere `RESTRICT` des qu'un emplacement etait deja reference par une commande.
  Corrige (`27671b0`) : les emplacements sont desormais reconcilies en place, et le retrait
  d'un emplacement deja commande est refuse en 409 au lieu de planter en 500 ; un test qui
  echouait avant le correctif le prouve. La vraie 500 (base arretee) reste demontree
  separement (`2fe8a4a`), pour ne pas perdre cette preuve en fermant l'ecart.
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
  sur l'API publique, mot de passe oublie sans limite de frequence...). Detail complet et
  preuve par test : `docs/soutenance/preuves/10-tests-securite.md`.

## Garde-fous

- `MenuRepositoryDbTest` (test d'integration sur base reelle) protege desormais la
  reconciliation en place des emplacements de menu.
- La migration `0019_pin_failed_audit_minimisation.sql` nettoie les lignes deja ecrites, en
  plus du correctif applicatif : un rejeu ne trouve plus rien a modifier (idempotent).
- La suite `tests/e2e/security-*.spec.js` (100 tests Playwright + 5 tests PHP,
  `bash tests/e2e/run-security.sh`) reste dans le depot et se rejoue a la demande contre une
  pile jetable, en trois phases (principale, reinitialisation, base arretee) ; elle confirme
  0 test marque en echec restant apres les quatre commits de correctif.
- Rappel ecrit et tenu pendant toute la session : aucune commande destructive ni modification
  de la configuration Traefik de l'hote de production.
- Le risque residuel sur la commande anonyme est ecrit plutot que presente comme regle : les
  plafonds bornent ce qu'UNE commande consomme, pas le nombre de commandes successives depuis
  la meme source ; l'identite de l'IP cliente releve de Traefik et n'est pas verifiee par ce
  projet.

## Mesures

(Chiffres mesures le 2026-09-29 sur le commit `2fe8a4a`.)

- Tests PHP : 2 508 tests, 8 626 assertions, 0 echec, 0 depreciation ; PHPStan niveau 6,
  0 erreur.
- Tests JavaScript : 461, 0 echec.
- Suite navigateur complete (36 fichiers de specs, pile jetable) : 197 reussis, 12 sautes
  (tests qui exigent une phase ou un reglage particulier), 0 echec ; balayage du back-office :
  4 812 verifications, 0 echec.
- Suite de securite `tests/e2e/run-security.sh` (3 phases, `APP_DEBUG=false`) : phase
  principale 95 reussis (8 sautes, joues dans les phases suivantes), phase reinitialisation
  4 reussis, phase base arretee 4 reussis ; 0 echec, 0 test marque en echec restant — plus
  5 tests PHP de securite (compris dans les 2 508 ci-dessus).
- Reponses capturees de la page Sante : 158 succes sur 158, 670 refus obtenus sur 689 tentes
  (capture du 2026-09-29 sur le commit `33538c6`, `src/app/Health/captured-responses.json`).
- Routes : 158. Tables : 24. Migrations : 19 fichiers (0001 a 0020, sans 0004). Decisions
  d'architecture : 20.
- Audit d'accessibilite : rejoue le 2026-09-29 (09:49 UTC) apres les correctifs, sur le
  commit `3fd08c4` : 19 ecrans, 935 mesures, 0 violation — chiffres inchanges par rapport
  au 28/09 (seule la date change dans `docs/soutenance/preuves/rapports/resume.json`).

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

- **Q** : « Pourquoi etre passe a Insomnia si Postman et Bruno etaient deja documentes ? »
  **R** : Les deux collections restent la reference versionnee dans le depot et s'importent
  telles quelles dans Insomnia. Le choix de l'outil de demonstration en direct n'a pas besoin
  d'etre versionne ; Insomnia a ete retenu pour la presentation elle-meme, pas en remplacement
  de la documentation.

- **Q** : « Avez-vous verifie que les correctifs de securite n'ont pas casse l'accessibilite ? »
  **R** : Oui : l'audit a ete rejoue le 29/09 (commit `3fd08c4`), apres les quatre correctifs
  de securite et les deux de contre-audit. Resultat identique au 28/09 : 19 ecrans,
  935 mesures, 0 violation. Les correctifs du jour ne touchaient pas au rendu (en-tetes
  serveur, requetes, journal d'audit, contraintes de base), donc l'absence de regression
  n'est pas une surprise, mais je prefere le verifier que le supposer.
