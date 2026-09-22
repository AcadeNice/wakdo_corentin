# Journal du projet Wakdo

Ce dossier contient les retrospectives de chaque session de travail et de chaque feature livree. Il est destine :

1. A moi pour la revision de l'oral de certification RNCP
2. Au jury qui souhaite tracer la demarche projet et la reflexion technique

Chaque fichier suit le meme template (voir ci-dessous) pour faciliter la relecture.

---

## Organisation

```
docs/journal/
  README.md                            # ce fichier (index + template)
  YYYY-MM-DD--nom-de-la-session.md     # un fichier par session significative ou feature mergee
```

Nommage : `YYYY-MM-DD--slug-court.md` (ex : `2026-04-23--cadrage-projet.md`).

Les fichiers sont ordonnes chronologiquement par leur nom.

---

## Index des sessions

| Date | Fichier | Sujet | Branche / PR |
|---|---|---|---|
| 2026-04-23 | [cadrage-projet](2026-04-23--cadrage-projet.md) | Analyse brief RNCP, decisions d'architecture, bootstrap Git | `main` (commit initial) |
| 2026-04-24 | [infra-docker](2026-04-24--infra-docker.md) | Stack Docker complete (compose + 4 services), referentiel RNCP integre, cross-check mappings Cr 4.f | `feat/infra-docker` |
| 2026-04-30 | [smoke-test-infra](2026-04-30--smoke-test-infra.md) | Smoke test bout-en-bout sur serveur reel : fusion .env, switch FQDN sur stark.a3n.fr, subnet explicite RFC 1918, fix init cron + healthz | `feat/infra-docker` |
| 2026-06-04 | [conception-prodlike-revision](2026-06-04--conception-prodlike-revision.md) | Revue d'alignement P1 + decisions prod-like du modele de donnees (drop commande_event, nommage EN, TVA par produit apres fact-check BOFiP, perso menus/ingredients, allergenes, ~16 entites) | `feat/p1-conception` |
| 2026-06-15 | [p3-throttle-pin-rg-t22](2026-06-15--p3-throttle-pin-rg-t22.md) | P3 securite : throttle du PIN d'action sensible (RG-T22) — design multi-agents + verification adversariale, dimension "utilisateur agissant", entite 22 `pin_throttle` | `feat/p3-pin-throttle` -> `dev` |
| 2026-06-16 | [audit-reel-livrables-p2-p3](2026-06-16--audit-reel-livrables-p2-p3.md) | Verification sur pieces des livrables du 2026-06-15 (sweep 10 dimensions + adversarial) : socle SbD confirme, miss confirmes par gravite (php.ini non deploye, CI sans tests DB, XSS kiosk, liens morts...) et remediations | `docs/journal-audit-2026-06-16` -> `dev` (PR #19/#20/#21) |
| 2026-06-04 | [p1-merise-v0.2-rewrite-and-forgejo-migration](2026-06-04--p1-merise-v0.2-rewrite-and-forgejo-migration.md) | P1 Merise v0.2 (prod-like) reecrit + migration vers Forgejo auto-heberge | `feat/p1-conception` |
| 2026-06-17 | [makefile-to-compose-migrate](2026-06-17--makefile-to-compose-migrate.md) | Du Makefile a `docker compose up` : service one-shot `wakdo-migrate` (migrate + seed idempotents) | `feat/compose-migrate` -> `dev` |
| 2026-06-17 | [session-infra-doc-e2e](2026-06-17--session-infra-doc-e2e.md) | Session infra compose, documentation Forge, amorce des tests E2E Playwright | `dev` (PR #37/#38/#39 et suivantes) |
| 2026-06-18 | [front-login-ui-admin-p4-commande](2026-06-18--front-login-ui-admin-p4-commande.md) | Page login, refonte UI admin (equipiers non-techniques), humanisation des libelles, amorce P4 commande (creation + encaissement) | `dev` (PR #48 a #58) |
| 2026-06-25 | [audit-remediation-et-features-94-105](2026-06-25--audit-remediation-et-features-94-105.md) | Synthese #94-#105 : CD push-based vers Vision + preuve de version, modeles compose prod, SMTP reel reset (Brevo), durcissement borne (Maxi 50cl, rupture non commandable, panier persistant, confirm abandon, allergenes /api), POS tactile comptoir/drive, page Stock en tableau de bord | `dev`/`main` (PR #94 a #105) |

*Mis a jour a chaque nouvelle entree. Les entrees sont ordonnees par leur nom de fichier (date) ; cet index les liste dans l'ordre de redaction.*

---

## Template d'une entree

Copier ce bloc pour chaque nouvelle session ou feature :

```markdown
# [Titre clair de la session ou feature]

**Date** : YYYY-MM-DD
**Branche** : `feat/xxx` ou `main`
**PR** : #n (ou "commit direct" si applicable)
**Duree estimee** : Xh

---

## Ce qui a ete fait

Description factuelle : quels fichiers, quelle feature, quel resultat concret.
Rester descriptif, pas interpretatif. Le "pourquoi" vient apres.

---

## Pourquoi — decisions et alternatives

Pour chaque choix technique significatif :

- **Decision** : [ce qui a ete retenu]
- **Alternatives considerees** : [les autres pistes]
- **Raison du choix** : [contraintes, tradeoffs, criteres]

C'est la section la plus importante pour l'oral. Le jury testera souvent : *"Pourquoi X plutot que Y ?"*

---

## Comment — points techniques cles

2 a 4 decisions d'implementation qui meritent une explication detaillee.
Extraits de code courts si pertinent, liens vers les fichiers concernes.

---

## Criteres RNCP couverts

Mapping explicite avec le referentiel (RNCP 37805) :

- **Bloc X - Critere Y.z** : [comment ce livrable y repond, avec reference au fichier]
- ...

---

## Questions anticipees du jury

Les questions que le jury pourrait poser sur cette session, avec les reponses preparees :

- **Q** : "..."
  **R** : [reponse concise, tenue]

- **Q** : "..."
  **R** : ...

---

## Points d'amelioration conscients

Ce qui a ete laisse volontairement imparfait, avec la raison. Montrer la maturite technique : savoir ce qui n'est pas optimal et pourquoi on a choisi de ne pas l'optimiser maintenant.

- [Point] : [pourquoi c'est laisse en l'etat + quand ca sera traite]

---

## Liens vers artefacts

- Commit(s) : `abc1234`, `def5678`
- Fichiers principaux : `path/to/file.php`, ...
- Documentation associee : `docs/xxx.md`
```

---

## Regles de redaction

1. **Factuel d'abord** : decrire ce qui a ete fait avant d'expliquer pourquoi.
2. **Pas d'emoji** (mantra IA-23).
3. **Sources citees** pour toute affirmation technique absolue (voir `.claude/rules/fact-check.md`).
4. **Liens vers les fichiers** avec chemins relatifs depuis la racine (ex : `src/Core/Router.php:42`).
5. **Honnetete technique** : si une decision a ete prise sans comprendre parfaitement, le dire. Le jury valorise la lucidite plus que la perfection.
