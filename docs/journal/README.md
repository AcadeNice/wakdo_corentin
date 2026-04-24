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

*Mis a jour a chaque nouvelle entree.*

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
