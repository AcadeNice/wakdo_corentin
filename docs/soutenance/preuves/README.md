# Preuves Bloc 1 — Titre RNCP 37805 (developpement front-end)

Ce dossier rassemble les preuves techniques du **Bloc 1** (developpement de la partie front-end), ancrees dans le code reel du projet Wakdo et, quand c'est pertinent, dans des artefacts executables (sortie de validateur, captures multi-resolutions).

Perimetre principal : la **borne de commande client** (`src/public/borne/`), interface front-end evaluee au titre du Bloc 1.

## Couverture par critere

| Critere | Intitule | Preuve | Statut |
|---|---|---|---|
| Cr 1.a.2 / 1.a.3 | Normes W3C + passage du validateur | [`01-validation-w3c.md`](01-validation-w3c.md) | Conforme (0 erreur, 5 pages) |
| Cr 1.a.5 | Balises semantiques | `01` + [`04`](04-accessibilite-rgaa.md) | Conforme |
| Cr 1.b.1 | Adaptation aux resolutions (responsive) | [`02-matrice-responsive.md`](02-matrice-responsive.md) | Couvert (borne et back-office, ossature comprise ; aucun defilement horizontal mesure : borne a 360, 390, 768 et 1366 px, 16 pages du back-office a 360 et 390 px) |
| Cr 1.b.2 / 1.b.3 | Compatibilite navigateurs + correction documentee | [`03-conformite-cross-browser.md`](03-conformite-cross-browser.md) | Couvert (perimetre assume) |
| Cr 1.c.1 a 1.c.4 | Accessibilite RGAA (lecteurs d'ecran, OpenDyslexic, couleur, clavier) | [`04-accessibilite-rgaa.md`](04-accessibilite-rgaa.md) + [`06-audit-accessibilite-mesure.md`](06-audit-accessibilite-mesure.md) | Conforme, avec reserves — couvre desormais 12 ecrans du back-office dont la caisse comptoir/drive ; contraste mesure a l'outil et corrige (06) |
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

**Tous regeneres le 2026-09-26** contre le code courant (`dev`), apres la refonte du back-office (demandes de fusion #158 a #166) et les cinq lots livres sur la borne depuis le 2026-09-23. Les versions precedentes restent consultables dans l'historique git.

- `w3c/borne-statique.json` — sortie du validateur W3C Nu sur les 5 pages servies (`messages: []`).
- `w3c/borne-rendu.json` — sortie sur le DOM rendu (0 erreur, 1 avertissement assume).
- `w3c/borne-modale-allergenes.json` — idem, modale allergenes ouverte.
- `w3c/dom-rendu/` — les 4 fichiers de HTML rendu (JS execute) reellement soumis au validateur.
- `rapports/` — audit d'accessibilite mesure : `resume.json` (18 ecrans), `contrastes-mesures.csv` (858 mesures), `axe-<ecran>.json` (x18).
- `captures-responsive/` — **52 captures** Playwright : la borne (5 ecrans x 360 / 390 / 768 / 1366 px) et le back-office (16 ecrans x 360 / 390 px).
- `captures-controle-saisie/` — controle de saisie pendant la frappe, modal PIN avant / apres correctif, et message du serveur apres un PIN refuse (fiche 09). Les trois captures « apres » sont regenerees ; `modal-pin-avant.png` ne l'est pas, et ne peut pas l'etre : elle montre le defaut corrige depuis.

## Reproductibilite

Trois commandes, une par famille d'artefacts. Chacune monte sa propre pile jetable, tourne sous l'identifiant de l'appelant, et demonte tout en sortant. La production reste hors de leur chemin.

| Commande | Ce qu'elle regenere |
|---|---|
| `tests/e2e/run-w3c.sh` | les deux niveaux de validation W3C + les 4 captures de DOM rendu |
| `tests/e2e/run-a11y.sh` | l'audit d'accessibilite mesure (18 ecrans) dans `rapports/` |
| `tests/e2e/run-captures.sh` | les 52 captures adaptatives + les 3 captures de controle de saisie |

- **W3C** : moteur Nu (identique a `validator.w3.org/nu`) execute en local via `ghcr.io/validator/validator` ; detail dans `01-validation-w3c.md` section 2.
- **Accessibilite mesuree** : `axe-core` 4.13.0 dans Chromium 131 (image officielle `mcr.microsoft.com/playwright:v1.49.1-jammy`) ; detail dans `06-audit-accessibilite-mesure.md` section 2.
- **Captures** : meme image Playwright, largeurs pilotees par `tests/e2e/responsive.spec.js` ; detail dans `02-matrice-responsive.md` section 5.

## Reserves honnetes consolidees (a ne pas survendre)

- **Accessibilite** : aucun audit avec un lecteur d'ecran reel (NVDA/VoiceOver) — reserve ouverte. Les ratios de contraste, eux, ont ete mesures a l'outil (`axe-core`, **858 mesures sur 18 ecrans** au 2026-09-26, [`06-audit-accessibilite-mesure.md`](06-audit-accessibilite-mesure.md)) : 10 noeuds trouves sous le seuil AA au premier passage ont ete corriges et remesures conformes — reserve resolue, plus une reserve ouverte sur ce theme. La demarche est structuree et testee, pas certifiee RGAA.
- **Tailles de cible** : le « 0 violation » de l'audit ne les couvre pas. Le critere de taille minimale de cible releve de **WCAG 2.2**, hors des quatre familles de regles activees (qui s'arretent a WCAG 2.1). A dire avant qu'on ne le demande.
- **Cross-navigateurs** : pas de campagne de test sur parc reel ; les tableaux de support sont tagues `[UNVERIFIED]`, a reconfirmer sur caniuse avant l'oral. La strategie (fallback `@supports`, prefixes) est verifiable dans le code.
- **C2.d** : aucune librairie JS externe n'est integree (choix vanilla). La competence de reutilisation est demontree par des modules internes ; le critere « externe » n'est pas rempli a la lettre. Confiance faible, assumee.
- **C2.a (animations)** : l'animation du total panier ([`07-animations-js.md`](07-animations-js.md)) est testee automatiquement (24 tests dedies) independamment du navigateur, mais le support des API utilisees (`requestAnimationFrame`, `performance.now`, `matchMedia`) n'a pas ete reconfirme sur caniuse et le validateur W3C n'a pas ete rejoue pour ce lot precis — meme reserve cross-navigateurs que le reste du dossier. Le choix `aria-live="off"` sur le total anime est un raisonnement documente, pas une mesure sur lecteur d'ecran reel.

## Findings releves pendant l'exercice (hors perimetre preuve, a traiter separement)

1. ~~**CSP borne** : le header `Content-Security-Policy` est pose sur le VirtualHost admin mais pas sur le vhost borne. Piste de durcissement.~~ **Corrige depuis la demande de fusion #123** (`feat(borne): CSP stricte same-origin + replis d'image CSP-safe`) : le vhost borne pose desormais son propre header `Content-Security-Policy` (`docker/apache/vhost.conf`, `script-src 'self'`, sans `unsafe-inline`), distinct de celui du vhost admin. Verifie le 2026-09-24 sur le fichier courant.
2. **Donnees de dev polluees** : `/api/categories` exposait des categories de test (« La onzieue », « Test ») visibles dans le bandeau produits. **Precision du 2026-09-26** : ces categories avaient ete creees **a la main** dans l'instance qui tourne, elles ne sont pas dans les fichiers de donnees de demonstration (`db/seeds/`). Verifie sur la capture `w3c/dom-rendu/categories.html`, prise contre une pile montee depuis ces fichiers : elle porte les 9 categories du catalogue et aucune categorie de test. Le menage reste donc a faire dans l'instance de demonstration avant l'oral, pas dans le depot.

## Reste a la charge du candidat (hors code)

- Demonstration live a l'oral (validateur W3C sur l'URL, bascule OpenDyslexic, navigation clavier, redimensionnement).
- ~~Stage en entreprise (element bloquant du titre, independant de ces preuves).~~ **Correction du 2026-09-24** : Corentin est en alternance (admin systeme), pas en stage ; l'alternance satisfait l'exigence d'experience professionnelle du titre. Cette ligne etait une fausse alerte.
