# Preuves Bloc 1 — Titre RNCP 37805 (developpement front-end)

Ce dossier rassemble les preuves techniques du **Bloc 1** (developpement de la partie front-end), ancrees dans le code reel du projet Wakdo et, quand c'est pertinent, dans des artefacts executables (sortie de validateur, captures multi-resolutions).

Perimetre principal : la **borne de commande client** (`src/public/borne/`), interface front-end evaluee au titre du Bloc 1.

## Couverture par critere

| Critere | Intitule | Preuve | Statut |
|---|---|---|---|
| Cr 1.a.2 / 1.a.3 | Normes W3C + passage du validateur | [`01-validation-w3c.md`](01-validation-w3c.md) | Conforme (0 erreur, 5 pages) |
| Cr 1.a.5 | Balises semantiques | `01` + [`04`](04-accessibilite-rgaa.md) | Conforme |
| Cr 1.b.1 | Adaptation aux resolutions (responsive) | [`02-matrice-responsive.md`](02-matrice-responsive.md) | Couvert (borne) ; partiel (ossature admin) |
| Cr 1.b.2 / 1.b.3 | Compatibilite navigateurs + correction documentee | [`03-conformite-cross-browser.md`](03-conformite-cross-browser.md) | Couvert (perimetre assume) |
| Cr 1.c.1 a 1.c.4 | Accessibilite RGAA (lecteurs d'ecran, OpenDyslexic, couleur, clavier) | [`04-accessibilite-rgaa.md`](04-accessibilite-rgaa.md) | Conforme, avec reserves |
| Cr 2.d.1 a 2.d.3 | Librairies JavaScript externes | [`05-librairies-js-c2d.md`](05-librairies-js-c2d.md) | Faible (choix vanilla assume) |

## Artefacts

- `w3c/borne-statique.json` — sortie du validateur W3C Nu sur les 5 pages servies (`messages: []`).
- `w3c/borne-rendu.json` — sortie sur le DOM rendu (0 erreur, 1 avertissement assume).
- `w3c/dom-rendu/` — le HTML rendu (JS execute) reellement soumis au validateur.
- `captures-responsive/` — 9 captures Playwright (accueil / categories / produits x mobile 390 / tablette 768 / desktop 1366).

## Reproductibilite

- **W3C** : moteur Nu (identique a `validator.w3.org/nu`) execute en local via `ghcr.io/validator/validator` ; commande dans `01-validation-w3c.md` section 2.
- **Captures responsive** : Playwright (image officielle `mcr.microsoft.com/playwright:v1.49.1-jammy`) contre la borne en ligne, viewport pilote par script.

## Reserves honnetes consolidees (a ne pas survendre)

- **Accessibilite** : aucun audit avec un lecteur d'ecran reel (NVDA/VoiceOver) ; ratios de contraste non mesures a l'outil. La demarche est structuree et testee, pas certifiee RGAA.
- **Cross-navigateurs** : pas de campagne de test sur parc reel ; les tableaux de support sont tagues `[UNVERIFIED]`, a reconfirmer sur caniuse avant l'oral. La strategie (fallback `@supports`, prefixes) est verifiable dans le code.
- **Responsive** : l'ossature de la sidebar admin n'est pas adaptee au mobile portrait etroit (coherent avec sa cible desktop/tablette).
- **C2.d** : aucune librairie JS externe n'est integree (choix vanilla). La competence de reutilisation est demontree par des modules internes ; le critere « externe » n'est pas rempli a la lettre. Confiance faible, assumee.

## Findings releves pendant l'exercice (hors perimetre preuve, a traiter separement)

1. **CSP borne** : le header `Content-Security-Policy` est pose sur le VirtualHost admin (`docker/apache/vhost.conf:177`) mais **pas** sur le vhost borne (`:41-113`). La posture « pas de CDN » est coherente avec le code de la borne mais n'est pas techniquement active cote borne. Piste de durcissement.
2. **Donnees de dev polluees** : `/api/categories` expose des categories de test (« La onzieue », « Test ») visibles dans le bandeau produits. Menage de seed a prevoir avant une demo.

## Reste a la charge du candidat (hors code)

- Demonstration live a l'oral (validateur W3C sur l'URL, bascule OpenDyslexic, navigation clavier, redimensionnement).
- Stage en entreprise (element bloquant du titre, independant de ces preuves).
