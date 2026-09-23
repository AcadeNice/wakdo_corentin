# Preuves Bloc 1 — Titre RNCP 37805 (developpement front-end)

Ce dossier rassemble les preuves techniques du **Bloc 1** (developpement de la partie front-end), ancrees dans le code reel du projet Wakdo et, quand c'est pertinent, dans des artefacts executables (sortie de validateur, captures multi-resolutions).

Perimetre principal : la **borne de commande client** (`src/public/borne/`), interface front-end evaluee au titre du Bloc 1.

## Couverture par critere

| Critere | Intitule | Preuve | Statut |
|---|---|---|---|
| Cr 1.a.2 / 1.a.3 | Normes W3C + passage du validateur | [`01-validation-w3c.md`](01-validation-w3c.md) | Conforme (0 erreur, 5 pages) |
| Cr 1.a.5 | Balises semantiques | `01` + [`04`](04-accessibilite-rgaa.md) | Conforme |
| Cr 1.b.1 | Adaptation aux resolutions (responsive) | [`02-matrice-responsive.md`](02-matrice-responsive.md) | Couvert (borne et back-office, ossature comprise ; aucun defilement horizontal mesure : borne a 360, 390 et 768 px, 15 pages du back-office a 360 et 390 px) |
| Cr 1.b.2 / 1.b.3 | Compatibilite navigateurs + correction documentee | [`03-conformite-cross-browser.md`](03-conformite-cross-browser.md) | Couvert (perimetre assume) |
| Cr 1.c.1 a 1.c.4 | Accessibilite RGAA (lecteurs d'ecran, OpenDyslexic, couleur, clavier) | [`04-accessibilite-rgaa.md`](04-accessibilite-rgaa.md) + [`06-audit-accessibilite-mesure.md`](06-audit-accessibilite-mesure.md) | Conforme, avec reserves — couvre desormais le back-office ; contraste mesure a l'outil et corrige (06) |
| Cr 1.d.2 | Code CSS organise et commente | [`08-referencement-performance.md`](08-referencement-performance.md) section 7 | Couvert (21 sections numerotees + sommaire, garde par test) |
| Cr 1.e.2 | Expressions cles mises en exergue | `08` section 4 | Couvert (strong/em sur les choix, la consigne, le retrait) |
| Cr 1.e.3 | Donnees structurees schema.org | `08` section 3 | Couvert (5 pages, graphe relie par @id, section de carte construite depuis les produits) |
| Cr 1.e.5 | Balises meta uniques et mesurees | `08` section 2 | Couvert (titres 50-56 car., descriptions 143-151 car., uniques) |
| Cr 1.e.7 | Alternatives des images et titres des liens | `08` section 5 + [`04`](04-accessibilite-rgaa.md) | Couvert (alt partout ; title sur tous les liens, reprenant leur intitule) |
| Cr 1.e.8 | Temps de chargement optimises | `08` section 6 | Couvert (banniere 1 256 918 -> 95 546 octets en WebP ; chargement complet de l'accueil 6,7 s -> 0,95 s, mesure a 1,6 Mbit/s) |
| Cr 1.e.11 | Ancres intra-page et lien d'evitement | [`04-accessibilite-rgaa.md`](04-accessibilite-rgaa.md) section 10 | Couvert (5 pages borne + back-office) |
| Cr 2.b.1 | Controle des saisies en temps reel | [`09-controle-saisie-temps-reel.md`](09-controle-saisie-temps-reel.md) | Couvert (tous les formulaires du back-office et de connexion, regles alignees sur le serveur, ecarts residuels listes ; anomalie du modal PIN corrigee sur 5 formulaires) |
| Cr 2.a.3 / 2.a.4 | Animations JavaScript, mouvement reduit, comportement cross-navigateurs | [`07-animations-js.md`](07-animations-js.md) | Couvert (animation du total panier, testee ; support navigateur documente sans campagne live) |
| Cr 2.d.1 a 2.d.3 | Librairies JavaScript externes | [`05-librairies-js-c2d.md`](05-librairies-js-c2d.md) | Faible (choix vanilla assume) |

## Artefacts

- `w3c/borne-statique.json` — sortie du validateur W3C Nu sur les 5 pages servies (`messages: []`).
- `w3c/borne-rendu.json` — sortie sur le DOM rendu (0 erreur, 1 avertissement assume).
- `w3c/dom-rendu/` — le HTML rendu (JS execute) reellement soumis au validateur.
- `captures-responsive/` — 9 captures Playwright de la borne (accueil / categories / produits x mobile 390 / tablette 768 / desktop 1366 ; produits mobile et tablette refaites le 2026-09-23 apres correctif) + 3 captures du back-office a 390 px.
- `captures-controle-saisie/` — controle de saisie pendant la frappe, modal PIN avant / apres correctif, et message du serveur apres un PIN refuse (fiche 09).

## Reproductibilite

- **W3C** : moteur Nu (identique a `validator.w3.org/nu`) execute en local via `ghcr.io/validator/validator` ; commande dans `01-validation-w3c.md` section 2.
- **Captures responsive** : Playwright (image officielle `mcr.microsoft.com/playwright:v1.49.1-jammy`) contre la borne en ligne, viewport pilote par script.

## Reserves honnetes consolidees (a ne pas survendre)

- **Accessibilite** : aucun audit avec un lecteur d'ecran reel (NVDA/VoiceOver) — reserve ouverte. Les ratios de contraste, eux, ont ete mesures a l'outil (`axe-core`, 407 mesures sur 11 ecrans, [`06-audit-accessibilite-mesure.md`](06-audit-accessibilite-mesure.md)) : 10 noeuds trouves sous le seuil AA ont ete corriges et remesures conformes — reserve resolue, plus une reserve ouverte sur ce theme. La demarche est structuree et testee, pas certifiee RGAA.
- **Cross-navigateurs** : pas de campagne de test sur parc reel ; les tableaux de support sont tagues `[UNVERIFIED]`, a reconfirmer sur caniuse avant l'oral. La strategie (fallback `@supports`, prefixes) est verifiable dans le code.
- **C2.d** : aucune librairie JS externe n'est integree (choix vanilla). La competence de reutilisation est demontree par des modules internes ; le critere « externe » n'est pas rempli a la lettre. Confiance faible, assumee.
- **C2.a (animations)** : l'animation du total panier ([`07-animations-js.md`](07-animations-js.md)) est testee automatiquement (24 tests dedies) independamment du navigateur, mais le support des API utilisees (`requestAnimationFrame`, `performance.now`, `matchMedia`) n'a pas ete reconfirme sur caniuse et le validateur W3C n'a pas ete rejoue pour ce lot precis — meme reserve cross-navigateurs que le reste du dossier. Le choix `aria-live="off"` sur le total anime est un raisonnement documente, pas une mesure sur lecteur d'ecran reel.

## Findings releves pendant l'exercice (hors perimetre preuve, a traiter separement)

1. **CSP borne** : le header `Content-Security-Policy` est pose sur le VirtualHost admin (`docker/apache/vhost.conf:177`) mais **pas** sur le vhost borne (`:41-113`). La posture « pas de CDN » est coherente avec le code de la borne mais n'est pas techniquement active cote borne. Piste de durcissement.
2. **Donnees de dev polluees** : `/api/categories` expose des categories de test (« La onzieue », « Test ») visibles dans le bandeau produits. Menage de seed a prevoir avant une demo.

## Reste a la charge du candidat (hors code)

- Demonstration live a l'oral (validateur W3C sur l'URL, bascule OpenDyslexic, navigation clavier, redimensionnement).
- Stage en entreprise (element bloquant du titre, independant de ces preuves).
