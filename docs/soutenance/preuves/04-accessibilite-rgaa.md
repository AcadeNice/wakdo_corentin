# Preuve 04 — Accessibilite RGAA (competence C1.c)

Titre professionnel RNCP 37805 — Bloc 1 (developpement front-end)
Perimetre principal : front borne de commande (`src/public/borne/`), 5 pages HTML + module a11y dedie + design system CSS.

**Extension back-office (section 10).** Le gabarit admin (`src/app/Views/admin/layout.php`) et ses assets (`src/public/admin/assets/`) etaient restes hors de cette preuve malgre 2460 lignes de CSS, 7 modules JS et 34 vues PHP pas encore couverts. La section 10 documente desormais la parite d'accessibilite back-office (police dyslexique, focus visible, favicon, semantique) et explique pourquoi les criteres de referencement naturel ne s'y appliquent pas.

Terme du referentiel : **RGAA** (Referentiel General d'Amelioration de l'Accessibilite). Les references de theme et de critere (ex. RGAA 1.4.1) suivent la structuration RGAA. Les seuils de contraste s'appuient sur les niveaux AA, que le RGAA reprend.

---

## 1. Portee et methode

L'accessibilite du front borne repose sur trois couches, toutes verifiees dans le code reel :

1. **Le HTML semantique** des 5 ecrans : landmarks (`main`, `nav`, `header`, `aside`), titres, `role`, `aria-label`, `aria-live`, `alt`.
2. **Le module dedie** `src/public/borne/assets/js/a11y.js` : bascule de police pour personnes dyslexiques (OpenDyslexic), avec persistance.
3. **Le design system** `src/public/borne/assets/css/style.css` : styles de focus clavier, contraste, classe `.sr-only`, `@font-face` OpenDyslexic et classe de bascule.

Chaque affirmation est mappee au code de critere attendu (Cr 1.c.x) et sourcee en `fichier:ligne`.

---

## 2. Cr 1.c.1 — Attributs des elements visuels renseignes pour lecteurs d'ecran

### Images (RGAA theme 1)

- **Images informatives avec `alt` pertinent.** Les cartes categorie sont generees depuis le catalogue et portent un `alt` egal au libelle de la categorie : `page-categories.js:69-70`. Une categorie sans visuel n'emet aucune balise `<img>` plutot qu'une image sans source (`page-categories.js:68-71`). Les boutons de choix d'accueil : `index.html:78-82` (`alt="Table et chaises - Sur place"`) et `index.html:92-96` (`alt="Sac a emporter"`).
- **Image decorative neutralisee.** La photo de fond d'accueil porte `alt=""` **et** `aria-hidden="true"` : `index.html:52-57`. Le contenu utile vit dans la carte, l'image n'est donc pas annoncee.
- **Logo.** Le logo d'en-tete porte `alt="Wakdo"` sur les pages concernees : `categories.html:27-31`, `products.html:31-35`, `payment.html:29-33`, `confirmation.html:22-26`.
- **Icones SVG purement decoratives** marquees `aria-hidden="true"` + `focusable="false"` : SVG carte/especes de paiement `payment.html:61,77`, coche de confirmation `confirmation.html:34`.
- **Images injectees dynamiquement.** Les cartes produit et categorie generees par JS recoivent un `alt` egal au nom de l'article. Le repli en cas d'echec de chargement passe par l'attribut `data-fallback`, lu par un ecouteur delegue au niveau du document — et non par un `onerror` en ligne, que la CSP stricte de la borne interdit : `page-products.js:81-87` et `page-categories.js:69-70` (`data-fallback="logo" data-fallback-alt="Image non disponible"`), coeur du repli `img-fallback.js:23-36`. L'icone corbeille du panneau de commande est decorative : `alt=""` + `aria-hidden="true"`, l'information etant portee par l'`aria-label` du bouton parent : `order-panel.js:132-139`.

### aria-label et roles sur les controles

- **Liens-boutons d'accueil** : `role="button"` + `aria-label` explicite (`index.html:72-77`, `86-91`).
- **Retours de navigation** : `aria-label="Retour a l'accueil"` (`categories.html:24`), `aria-label="Retour aux categories"` (`products.html:23-28`, `payment.html:22-26`).
- **Cartes produit dynamiques** : `aria-label` combinant nom + prix, et `aria-disabled="true"` sur une tuile en rupture : `page-products.js:75-76`.
- **Stepper de quantite** dans le panneau commande : chaque groupe `role="group"` + `aria-label="Quantite de <libelle>"`, boutons `aria-label="Diminuer/Augmenter la quantite de <libelle>"`, retrait `aria-label="Retirer <libelle> de la commande"` : `order-panel.js:115-137`.
- **Boutons de paiement** : `aria-label="Payer par carte bancaire"` / `aria-label="Payer en especes"` : `payment.html:58,74`.
- **Bouton d'information allergenes** : `aria-label="Informations allergenes"` + `title` : `allergens.js:62-63`.

### Landmarks et regions vivantes

- **Landmarks** sur chaque page : `main` avec `aria-label` (ex. `index.html:46`, `products.html:39`, `payment.html:37`, `confirmation.html:29`), `nav` etiquetees (`index.html:66`, `categories.html:53`, `products.html:41`).
- **`aria-live`** : le panneau de commande annonce ses mises a jour (`aside ... aria-live="polite"`, `products.html:61`) ; la banniere de confirmation est un `role="status" aria-live="polite"` (`confirmation.html:31`) ; les blocs d'erreur sont `role="alert"` (`products.html:53`, `payment.html:46`).
- **`lang="fr"`** present sur les 5 pages (verifie : 1 occurrence par fichier).

### Correction recente de conformite ARIA

Des `aria-label` avaient ete poses sur des `span`/`div` generiques (badge de mode, recap de paiement). Ils ont ete retires pour respecter la specification W3C/ARIA (un `aria-label` sur un element sans role semantique n'est pas fiable selon les navigateurs). Etat actuel verifie : les badges de mode ne portent plus que `data-mode-badge` et laissent le lecteur d'ecran annoncer le **texte visible reel** (`products.html:49`, `payment.html:34`). Le bloc recapitulatif de paiement conserve, lui, un `role="region"` legitime avec son `aria-label` (`payment.html:42`).

**Verdict Cr 1.c.1 : conforme** sur le perimetre borne, avec une reserve honnete listee en section 8 (contenus dynamiques dependant du navigateur, non audites avec un lecteur d'ecran reel — [UNVERIFIED]).

---

## 3. Cr 1.c.2 — Police pour personnes dyslexiques (OpenDyslexic)

Fonctionnalite complete, prevue **et** integree, avec bascule utilisateur persistante.

- **Polices auto-hebergees.** Deux fichiers `woff2` presents sur disque : `opendyslexic-latin-400-normal.woff2` (112 Ko), `opendyslexic-latin-700-normal.woff2` (117 Ko), plus la licence `LICENSE-OpenDyslexic.txt` (OFL 1.1) sous `src/public/borne/assets/fonts/`.
- **`@font-face`** declares (poids 400 et 700) avec `font-display: swap` : `style.css` (cherchez « self-hosted under assets/fonts »).
- **Bascule par classe racine.** `html.dys-font` redefinit `--font-family-base` vers la pile OpenDyslexic, appliquee a toute l'interface : `style.css` (cherchez « html.dys-font »).
- **Module de bascule** `a11y.js` : lit la preference (`isDyslexiaEnabled`, l.27-33), applique/retire la classe sur `<html>` (`applyDyslexiaPreference`, l.40-44), persiste dans `localStorage` (`persistDyslexiaPreference`, l.51-59), construit un bouton `aria-pressed` refletant l'etat (`buildDyslexiaToggle`, l.68-98), injecte le bouton de facon idempotente (`initDyslexiaToggle`, l.106-125) et s'auto-initialise au `DOMContentLoaded` (l.129-131).
- **Bouton present sur chaque ecran** : le tag `<script type="module" src="assets/js/a11y.js">` est charge par les 5 pages (`index.html:104`, `categories.html:62`, `products.html:68`, `payment.html:92`, `confirmation.html:70`).
- **Robustesse.** L'acces storage est encapsule en `try/catch` : mode prive ou quota indisponible retombe sur la police de base sans erreur (l.28-32, l.52-58).
- **Style du bouton** : controle fixe en bas-gauche, `z-index` eleve, hors collision avec le bouton Retour et le panneau panier : `style.css` (cherchez « .a11y-toggle »).
- **Tests unitaires** (jsdom, sans navigateur) : `tests/js/a11y.test.js` couvre lecture de preference, application de classe, injection idempotente, reflet `aria-pressed`, cycle de clic + persistance, et le cas storage qui jette (l.39-106).

**Verdict Cr 1.c.2 : conforme.** La bascule est le point le plus solide de la preuve : integree, persistee, testee.

---

## 4. Cr 1.c.3 — Information importante pas uniquement par la couleur

L'information ne repose pas sur la seule couleur : un libelle textuel ou une icone accompagne l'indice chromatique dans chaque cas identifie.

- **Produit en rupture de stock.** La tuile grisee (couleur) est doublee d'un badge textuel `Indisponible` (`page-products.js:87`, CSS `style.css` — cherchez « .product-card--unavailable » pour le grisage et « .product-card__badge » pour le badge), d'un suffixe dans l'`aria-label` (` - indisponible`, `page-products.js:75`) et d'un `aria-disabled="true"` (`page-products.js:76`). Le grisage seul ne fait pas foi.
- **Categorie active dans le bandeau.** L'etat actif combine une bordure epaissie **et** un fond distinct `#FFF8E6`, precisement pour ne pas dependre de la seule bordure coloree : `style.css` (cherchez « 2e cue »).
- **Selection de carte composeur.** L'etat selectionne cumule bordure jaune fonce, halo et fond legerement teinte, et il est expose a la technologie d'assistance via `aria-pressed` (documente dans `style.css`, cherchez « jaune fonce : contraste » et « Uses aria-pressed »).
- **Bascule de police active.** L'etat actif change la couleur du bouton mais est aussi expose par `aria-pressed` et par un libelle texte qui reste visible : `style.css` (cherchez « not signalled by colour alone »).
- **Contraste.** Le token de texte attenue est `--color-text-muted` (`style.css`, cherchez ce nom) ; l'accent de selection utilise le jaune fonce `--color-brand-yellow-dk` pour le contraste (meme fichier, cherchez « jaune fonce : contraste »). Valeur et ratios exacts : voir `06-audit-accessibilite-mesure.md`, qui mesure ce token a l'outil et documente sa correction (`#767676` -> `#6E6E6E`, ainsi que deux tokens equivalents cote back-office).

**Verdict Cr 1.c.3 : conforme** sur les etats identifies. **Reserve levee** (etait :
« les ratios de contraste exacts n'ont pas ete mesures avec un outil dedie »). Les
ratios ont depuis ete mesures a l'outil `axe-core` sur des ecrans reels, et les 10 noeuds
trouves sous le seuil AA — dont un dans ce perimetre borne — ont ete corriges puis
remesures conformes. La campagne courante (2026-09-26) porte sur **18 ecrans et
858 mesures**, contre 11 ecrans et 407 a la campagne d'origine. Detail complet, chiffres
avant/apres, et methode : `06-audit-accessibilite-mesure.md`.

---

## 5. Cr 1.c.4 — Navigation clavier complete (focus visible, pas de piege)

### Focus visible

- **Focus clavier stylise** sur la grande majorite des controles interactifs via `:focus-visible` (halo jaune ou `outline` epais) : 19 regles `:focus-visible` dans `style.css` (18 avant ce lot, plus `.skip-link` ajoute pour Cr 1.e.11, voir plus bas dans cette section), reperables par selecteur (choix accueil `.choice-btn`, retour `.site-header__back`, carte categorie `.category-card`, boutons `.btn--primary`/`.btn--secondary`, carte produit `.product-card`/`.product-card--unavailable`, quantite `.qty-btn`, paiement `.payment-choice`, carte composeur `.composer-card`, taille `.composer-taille__btn`, controles du panneau + bandeau `.order-panel__pay`/`.order-panel__abandon`/`.order-panel__remove`/`.category-strip__item`/`.category-strip__arrow`, saisie chevalet `.chevalet__input`, bascule a11y `.a11y-toggle`, lien d'evitement `.skip-link`).
- **`outline: none` systematiquement compense.** Chaque `outline: none` s'accompagne dans la meme regle d'un indicateur de substitution (halo `box-shadow` ou changement de bordure) — verifie regle par regle (ex. `.choice-btn:focus-visible`, `.btn--primary:focus-visible`, `.composer-card:focus-visible` dans `style.css`). Il n'existe pas de suppression globale du focus : le reset (`style.css`, cherchez « box-sizing: border-box ») ne touche que `box-sizing`/`margin`/`padding`.

### Navigation native, pas de piege

- **Navigation par liens HTML natifs.** Les choix d'accueil sont de simples `<a href>` servis directement dans le HTML : la tabulation et l'activation clavier y fonctionnent sans JavaScript (`index.html:72-98`, commentaire `index.html:44`). Les cartes categorie, elles, sont **generees par JavaScript** depuis `GET /api/categories` (`page-categories.js:63-79`) : cet ecran depend donc du JS pour s'afficher, comme les ecrans produits et paiement. Une fois rendues, ce sont de vrais `<a href>` — le focus, la tabulation et l'activation clavier sont natifs, pas simules (`page-categories.js:73`).

  **Reserve assumee, a defendre a l'oral.** La page portait auparavant une liste de neuf cartes ecrite en dur, qui s'affichait sans JavaScript mais ne refletait pas le catalogue reel : une categorie desactivee, renommee ou ajoutee en back-office restait fausse a l'ecran. Le choix retenu est d'afficher le catalogue juste plutot que de fonctionner sans JavaScript sur un ecran qui, de toute facon, ne permet pas de commander sans JavaScript (composeur, panier et paiement en dependent). Un repli statique aurait reintroduit exactement la donnee codee en dur que ce lot supprime.
- **Cartes produit focusables au clavier.** Bien que le clic ouvre une modale, la carte reste un `<a>` avec `href` pour conserver focus et activation clavier (`page-products.js:70-74`, commentaire l.72-73).
- **Modales sans piege bloquant, avec focus gere.** La modale de confirmation d'un geste destructeur (`confirm-modal.js`) :
  - piege le `Tab`/`Shift+Tab` en boucle sur ses boutons (l.50-59) ;
  - se ferme sur `Echap` (l.51) et sur clic-fond (l.62) ;
  - restaure le focus au declencheur a la fermeture (`previouslyFocused`, l.19 + l.44-46) ;
  - met le fond en `aria-hidden` pendant l'ouverture (l.36-37) ;
  - place le focus initial sur « Annuler » pour qu'un `Entree` accidentel ne declenche pas l'action destructrice (l.69-70).
  Ce comportement est un piege **voulu et sortable** (Echap + boucle), pas un piege bloquant : c'est la definition attendue d'une modale accessible.
- **Modale allergenes** : fermeture `Echap` (`allergens.js:44-48, 209`), fermeture clic-fond (l.188-192), `role="dialog"` + `aria-modal="true"` (l.113-115), bouton de fermeture etiquete (l.121-123). Depuis F11b, l'avertissement "information non disponible" porte `role="alert"` (l.175) : un lecteur d'ecran l'annonce sans attendre que le client parcoure le panneau, ce qui compte pour une information de securite alimentaire.
- **Tests** : `tests/js/confirm-modal.test.js` verifie `role="dialog"` + `aria-modal`, la fermeture par Echap et clic-fond sans effet destructeur (l.23-62).

### Lien d'evitement et sommaire d'ancres (Cr 1.e.11, renforce Cr 1.c.4)

Critere releve absent lors de l'audit Bloc 1 (aucun `href="#"` dans tout le depot avant ce lot). Deux mecanismes distincts, l'un sur chaque perimetre :

- **Lien d'evitement sur les 5 pages borne.** Premier element du `<body>`, avant tout en-tete : `index.html`, `categories.html`, `products.html`, `payment.html`, `confirmation.html` (cherchez, dans chaque fichier, `class="skip-link"`). Cible un `<main id="main-content">` reellement present sur la meme page. Style dans `style.css` (cherchez `.skip-link`) : hors flot par defaut comme `.sr-only`, replace en haut de l'ecran au focus clavier via `:focus-visible` — meme convention que les 18 autres regles `:focus-visible` du fichier, pas un mecanisme different.
- **Lien d'evitement sur le gabarit admin.** Meme principe, un seul point d'injection puisque `layout.php` enveloppe toutes les pages back-office : `src/app/Views/admin/layout.php` (cherchez `class="skip-link"`), cible `<main class="content" id="main-content">`. Style dans `src/public/admin/assets/css/admin.css` (cherchez `.skip-link:focus-visible`).
- **Sommaire d'ancres sur la vue admin la plus longue.** `src/app/Views/admin/ingredients/index.php` (283 lignes avant ce lot, la plus longue vue du back-office) : un `<nav class="toc" aria-label="Sommaire de la page">` (cherchez ce texte) liste deux liens vers les deux sections reelles de la page, `#ingredients-a-reapprovisionner` et `#ingredients-tous`, posees comme `id` sur les `<section>` correspondantes.
- **Tests.** Borne : `tests/js/skip-link.test.js` (jsdom, lit les 5 fichiers HTML reels sur disque, verifie que le lien d'evitement est le PREMIER element du `<body>`, que sa cible existe, et la regle CSS associee). Admin : `tests/Unit/Admin/DashboardControllerTest.php` (cherchez `testShellHasSkipLinkFaviconAndDyslexiaToggleBeforeMainLandmark` — verifie aussi que le lien precede la topbar dans le HTML rendu, pas seulement sa presence) et `tests/Unit/Admin/IngredientControllerTest.php` (cherchez `testIndexRendersTableOfContentsLinkingBothRealSections` — verifie que les deux ancres du sommaire resolvent vers des id qui existent reellement).
- **Validation W3C rejouee.** La commande reproductible de `01-validation-w3c.md` (`ghcr.io/validator/validator`, moteur Nu) a ete relancee sur les 5 pages borne apres l'ajout du lien d'evitement : sortie identique a avant ce lot, `{"messages":[]}`.

**Verdict Cr 1.c.4 : conforme avec reserve.** Le focus reste visible et non perdu sur le perimetre lu ; la coherence de **style** du focus est partielle (voir section 8 : quelques controles secondaires reposent sur l'anneau natif du navigateur, non stylise). Le lien d'evitement (Cr 1.e.11) renforce ce critere sans lever la reserve, qui porte sur un point distinct (les controles secondaires cites).

---

## 6. Checklist RGAA auto-evaluee

Verdicts : **conforme** (preuve dans le code) · **partiel** (couvert mais reserve honnete) · **non applicable**.

### Theme 1 — Images

| Critere | Preuve (fichier:ligne) | Verdict | Commentaire |
|---|---|---|---|
| Image informative a une alternative | `page-categories.js:69-70` ; `index.html:78-96` | conforme | `alt` = libelle de categorie (genere depuis le catalogue) / illustration de mode. |
| Image decorative correctement ignoree | `index.html:52-57` | conforme | `alt=""` + `aria-hidden="true"` sur le fond d'accueil. |
| Alternative des images injectees | `page-products.js:81-87` ; `page-categories.js:69-70` ; `img-fallback.js:23-36` | conforme | `alt` = nom de l'article ; repli par `data-fallback` delegue, aucun `onerror` en ligne (CSP stricte). |
| Icones/SVG decoratifs ignores | `payment.html:61,77` ; `confirmation.html:34` ; `order-panel.js:138` | conforme | `aria-hidden="true"` + `focusable="false"` / `alt=""`. |

### Theme 3 — Couleurs

| Critere | Preuve (fichier:ligne) | Verdict | Commentaire |
|---|---|---|---|
| Info pas donnee par la seule couleur | `page-products.js:75,87` ; `style.css` (cherchez « 2e cue » et « not signalled by colour alone ») | conforme | Rupture = badge texte + aria ; actif = fond + libelle + `aria-pressed`. |
| Contraste texte suffisant | `style.css` (cherchez « --color-text-muted »), `06-audit-accessibilite-mesure.md` | conforme | Mesure a l'outil (`axe-core`, 858 ratios sur 18 ecrans au 2026-09-26) : un noeud sous le seuil trouve sur ce perimetre au premier passage, corrige et remesure conforme. |

### Theme 10 — Presentation / focus

| Critere | Preuve (fichier:ligne) | Verdict | Commentaire |
|---|---|---|---|
| Focus clavier visible | `style.css`, 19 regles `:focus-visible` reperables par selecteur (`.choice-btn`, `.site-header__back`, `.category-card`, `.btn--primary`, `.btn--secondary`, `.product-card`, `.product-card--unavailable`, `.qty-btn`, `.payment-choice`, `.composer-card`, `.composer-taille__btn`, `.order-panel__pay`, `.order-panel__abandon`, `.order-panel__remove`, `.category-strip__item`, `.category-strip__arrow`, `.chevalet__input`, `.a11y-toggle`, `.skip-link`) | conforme | Halo jaune / `outline` epais decale. |
| `outline:none` compense | `style.css` (`.choice-btn:focus-visible`, `.btn--primary:focus-visible`, `.composer-card:focus-visible`) | conforme | Chaque suppression a un substitut visible ; pas de reset global du focus. |
| Coherence du style de focus | `style.css` (`.size-btn`, `.allergen-info-btn`, `.allergen-modal-close`) | partiel | Quelques controles secondaires reposent sur l'anneau natif (non perdu, mais non stylise). |

### Theme 11 — Formulaires / controles

| Critere | Preuve (fichier:ligne) | Verdict | Commentaire |
|---|---|---|---|
| Controle a une etiquette | `payment.html:58,74` ; `order-panel.js:115-137` ; `allergens.js:62` | conforme | `aria-label` sur boutons paiement, stepper, retrait, info allergenes. |
| Champ de saisie etiquete | `style.css` (cherchez « .chevalet__input ») | partiel | Le champ chevalet a un focus visible ; le libelle textuel proche vit dans une modale JS hors des 5 pages lues, a verifier. |
| Etat desactive expose | `order-panel.js:182` ; `style.css` (cherchez « .btn--primary[aria-disabled » et « .order-panel__pay[aria-disabled ») | conforme | `aria-disabled` sur « Payer » panier vide et boutons desactives. |

### Theme 12 — Navigation

| Critere | Preuve (fichier:ligne) | Verdict | Commentaire |
|---|---|---|---|
| Landmarks / zones | `index.html:46,66` ; `products.html:39,41,61` ; `payment.html:37,48` | conforme | `main`, `nav`, `aside`, `header` etiquetes. |
| Navigation clavier sans piege bloquant | `index.html:72-98` ; `page-products.js:70-74` ; `confirm-modal.js:50-59` | conforme | Liens natifs ; modales sortables (Echap + boucle Tab). |
| Titre de page pertinent | `index.html:20` ; `categories.html:8` ; `payment.html:8` ; `confirmation.html:8` | conforme | `<title>` distinct par ecran, mis a jour dynamiquement (`page-products.js:48`). |
| Langue de la page | 5 pages `html lang="fr"` | conforme | Verifie : 1 occurrence par fichier. |
| Lien d'evitement (Cr 1.e.11) | 5 pages borne + `admin/layout.php` (cherchez `class="skip-link"` dans chaque fichier) | conforme | Absent avant ce lot (zero `href="#"` dans tout le depot). Voir section 5 pour le detail. |

### Fonctionnalite — Police OpenDyslexic (Cr 1.c.2)

| Critere | Preuve (fichier:ligne) | Verdict | Commentaire |
|---|---|---|---|
| Police auto-hebergee + `@font-face` | `assets/fonts/*.woff2` ; `style.css` (cherchez « self-hosted under assets/fonts ») | conforme | Poids 400/700, `font-display: swap`, licence OFL presente. |
| Bascule utilisateur persistante | `a11y.js:40-125` ; `style.css` (cherchez « html.dys-font ») | conforme | Classe `.dys-font` sur `<html>`, persistance `localStorage`. |
| Presence sur tous les ecrans | 5 pages chargent `a11y.js` | conforme | `index:104`,`categories:184`,`products:68`,`payment:92`,`confirmation:70`. |
| Etat expose a l'assistance | `a11y.js:73,89-95` | conforme | `aria-pressed` reflete l'etat ; couvert par tests. |
| Couverture de test | `tests/js/a11y.test.js:39-106` | conforme | 7 cas jsdom (dont storage en echec). |

---

## 7. Zones non applicables ou hors perimetre

- **Multimedia (theme 4), tableaux de donnees (theme 5), cadres (theme 6), scripts complexes hors modale** : non applicable au front borne, qui ne comporte ni video, ni tableau de donnees, ni iframe.
- **Documents en telechargement (theme 13)** : non applicable (aucun PDF/document servi par la borne).
- **Consultation / zoom (theme 10 avance)** : la borne cible un ecran tactile fixe 1080x1920 ; le zoom navigateur n'est pas le mode d'usage principal. `touch-action: manipulation` (`style.css`, cherchez « touch-action: manipulation ») previent le pinch-zoom accidentel — choix d'ergonomie borne a assumer a l'oral, car il peut interroger le critere de redimensionnement.

---

## 8. Reserves honnetes (a ne pas survendre au jury)

1. **Aucun audit avec lecteur d'ecran reel.** Le mapping ARIA est correct dans le code, mais le rendu effectif sous NVDA/VoiceOver/TalkBack n'a pas ete teste sur cette borne. Toute affirmation de restitution vocale reelle est [UNVERIFIED].
2. **Ratios de contraste non mesures a l'outil — RESOLU, voir `06-audit-accessibilite-mesure.md`.** Cette reserve disait que les tokens etaient choisis pour viser AA sans rapport d'outil joint. Depuis, `axe-core` a mesure 407 ratios sur 11 ecrans reels ; 10 noeuds sous le seuil AA ont ete trouves (dont un sur ce perimetre borne, `#767676` sur un fond gris a 4,16:1), corriges (`#767676` -> `#6E6E6E`), puis remesures conformes sur les 11 ecrans. La campagne a ete rejouee et **elargie a 18 ecrans (858 mesures) le 2026-09-26**, apres la refonte du back-office : le resultat reste a 0 violation. Le detail, les chiffres avant/apres et la methode sont dans le document cite.
3. **Coherence du style de focus partielle.** Le focus n'est pas perdu (pas de reset global), mais quelques controles secondaires (`.size-btn`, bouton info allergenes `.allergen-info-btn`, fermeture modale allergenes `.allergen-modal-close`, tous reperables par selecteur dans `style.css`) reposent sur l'anneau de focus natif du navigateur plutot que sur le halo jaune maison. C'est conforme (focus visible) mais visuellement heterogene.
4. **Champ chevalet hors des 5 pages lues.** Le picker de chevalet (sur place) a un focus visible en CSS (`style.css`, cherchez « .chevalet__input ») mais son etiquette textuelle vit dans une modale JS non incluse dans les 5 pages de ce perimetre ; verdict « partiel » par prudence.
5. **Contenu genere = surface a re-tester.** Les cartes produit et le panneau commande sont construits en JavaScript. Les attributs ARIA sont poses dans le code (`page-products.js`, `order-panel.js`), mais leur presence a l'ecran depend de l'execution correcte du rendu ; a demontrer en live plutot qu'a affirmer.

---

## 9. Points de defense a l'oral

1. **Montrer la bascule OpenDyslexic en direct.** C'est la preuve la plus forte : cliquer le bouton bas-gauche, montrer le changement de police sur toute l'interface, recharger la page pour prouver la persistance `localStorage`. Enchainer sur le test unitaire `tests/js/a11y.test.js` (7 cas, dont le mode prive qui jette).
2. **Expliquer le principe « pas la couleur seule ».** Prendre l'exemple d'une tuile en rupture : montrer le badge `Indisponible`, le grisage, et l'`aria-disabled` — trois signaux, un seul resterait insuffisant (RGAA 1.4.1). Idem pour la categorie active (bordure + fond `#FFF8E6`).
3. **Demontrer la modale accessible au clavier.** Ouvrir la confirmation d'Abandon, tabuler pour montrer la boucle de focus, appuyer sur Echap pour sortir, verifier que le focus revient au bouton declencheur. Insister : le focus initial est sur « Annuler » pour ne pas confirmer un geste destructeur par inadvertance.
4. **Assumer la difference RGAA vs WCAG.** Le referentiel opposable en France est le RGAA ; il s'appuie sur WCAG mais ajoute une methodologie de test. Le code cite explicitement des criteres RGAA en commentaire dans `style.css` (cherchez « Accessibility (RGAA Cr 1.c.2) », « Screen-reader only » et « not signalled by colour alone »).
5. **Etre transparent sur les reserves (section 8).** Un jury valorise l'honnetete : dire clairement que l'audit lecteur d'ecran reel reste a faire (reserve n° 1, encore ouverte). La mesure de contraste a l'outil, elle, a ete faite depuis (reserve n° 2, resolue — voir `06-audit-accessibilite-mesure.md`) : le dire aussi, et raconter ce qu'elle a trouve, vaut mieux que de la passer sous silence. Ne pas revendiquer une conformite RGAA totale certifiee — revendiquer une **demarche d'accessibilite structuree et testee** sur le perimetre borne.
6. **Relier a la correction ARIA recente.** Montrer que l'on comprend la specification : les `aria-label` parasites sur des `span`/`div` sans role ont ete retires car un `aria-label` sur un element non semantique n'est pas fiable ; on laisse desormais le lecteur annoncer le texte visible reel du badge de mode.
7. **Back-office : montrer la parite, pas seulement l'affirmer (section 10).** Basculer la police OpenDyslexic en admin, tabuler pour montrer le lien d'evitement en tout premier arret, montrer le sommaire d'ancres de la page Ingredients. Expliquer pourquoi le `noindex, nofollow` de l'admin n'est pas une lacune : c'est une decision assumee, argumentee en section 10.

---

## 10. Back-office (admin) — parite d'accessibilite

Audit Bloc 1 : le gabarit admin (`src/app/Views/admin/layout.php`) et ses assets (`src/public/admin/assets/`) etaient restes hors de cette preuve. Cette section documente le rattrapage sur quatre points reels, et pose noir sur blanc pourquoi un cinquieme point (le referencement naturel) ne s'applique pas ici — par decision, pas par oubli.

### 10.1 Police pour personnes dyslexiques (parite avec Cr 1.c.2)

Le module `assets/js/a11y.js` est **reutilise physiquement**, pas reecrit : `src/public/admin/assets/js/a11y.js` est un lien symbolique vers le fichier de la borne (`../../../borne/assets/js/a11y.js`), rendu possible par `Options ... +FollowSymLinks` deja active sur les deux vhosts (`docker/apache/vhost.conf`, cherchez `+FollowSymLinks`) et sans consequence CSP puisque le fichier est servi depuis l'origine admin elle-meme (le navigateur voit une ressource same-origin, la question cross-origin ne se pose pas). Meme raisonnement pour les polices : `src/public/admin/assets/fonts` est un lien symbolique vers `assets/fonts` de la borne (les deux `.woff2` OpenDyslexic + la licence OFL).

- **`@font-face` propre a l'admin.** Deux poids (400/700), `font-display: swap` : `admin.css` (cherchez `@font-face`, juste au-dessus de `html.dys-font`).
- **Bascule par classe racine adaptee au design system admin.** `html.dys-font` redefinit `--font` (le token de police de l'admin, distinct de `--font-family-base` cote borne) : `admin.css` (cherchez `html.dys-font`).
- **Chargement sur chaque page admin.** Une seule injection suffit : `layout.php` (cherchez `<script type="module" src="/assets/js/a11y.js">`) enveloppe toutes les vues back-office.
- **Position du bouton adaptee au layout admin.** Bas-droite (`admin.css`, cherchez `.a11y-toggle`) plutot que bas-gauche comme sur la borne : la sidebar admin occupe tout le bord gauche sur chaque page (`admin.css`, cherchez `.sidebar {`), le coin bas-gauche n'y est donc pas libre.
- **Non-regression testee, pas seulement la premiere fois.** `tests/Unit/Admin/AdminAccessibilityAssetsTest.php` (cherchez `testDyslexiaToggleScriptIsSharedWithBorneNotDuplicated`) compare le contenu du fichier admin a celui de la borne octet pour octet : une divergence future (edition d'un seul cote) ferait echouer ce test avant de devenir un bug silencieux en production.

### 10.2 Focus visible harmonise (parite avec Cr 1.c.4)

Avant ce lot, `admin.css` melangeait deux conventions : `:focus-visible` sur les controles recents (tuiles POS, onglets), `:focus` nu sur quatre selecteurs plus anciens. Corrige par simple changement de pseudo-classe (le comportement visuel au clavier est inchange, seul le declenchement au clic souris disparait sur ces quatre champs — coherent avec le reste du fichier) :

- `.topbar-search input:focus-visible`
- `.search-field input:focus-visible`
- `.filter-select:focus-visible`
- `.form-input:focus-visible, .form-select:focus-visible, .form-textarea:focus-visible`

Verifie par regex negative (pas seulement les quatre selecteurs cites) : `tests/Unit/Admin/AdminAccessibilityAssetsTest.php` (cherchez `testAdminStylesheetHasNoBareFocusSelectorLeft`).

### 10.3 Favicon

Absent du gabarit admin avant ce lot. Ajoute par le meme mecanisme de partage physique que les polices : `layout.php` (cherchez `<link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">`), `src/public/admin/assets/images/favicon.svg` est un lien symbolique vers le fichier de la borne. Verifie par `tests/Unit/Admin/AdminAccessibilityAssetsTest.php` (cherchez `testFaviconIsReachableFromAdminOrigin`).

### 10.4 Semantique du gabarit — verification, aucune correction necessaire

`layout.php` utilise deja `<header class="topbar">`, `<nav class="sidebar">` et `<main class="content">` a bon escient : chacun correspond a une seule zone reelle et distincte du gabarit (bandeau superieur, navigation laterale, contenu de page). Aucun `<article>` n'a ete ajoute : rien dans le gabarit ne correspond a un contenu autonome et redistribuable au sens de cet element, l'en introduire un aurait ete du remplissage sans valeur semantique. Ce point est une verification qui conclut a la conformite existante, pas une correction.

### 10.5 Pourquoi le referencement naturel (Cr 1.e, hors 1.e.11) ne s'applique pas au back-office — decision assumee

Le gabarit admin porte `<meta name="robots" content="noindex, nofollow">` depuis avant ce lot (decision deja en place, pas introduite ici). C'est une decision correcte a assumer telle quelle, pas une lacune a combler :

- **Le back-office n'est pas une ressource publique.** Chaque page est derriere authentification (session + permission, `AdminController::guard()`). Un moteur de recherche sans identifiants valides ne peut pas atteindre une page utile de l'admin ; l'indexer ne referencerait donc que des pages de connexion ou des redirections, ce qui n'apporte aucune valeur de decouvrabilite.
- **Un `canonical` ou une `description` sur une page `noindex` seraient contradictoires.** Le `canonical` dit a un moteur « indexe cette version-ci » ; la `description` sert a composer un extrait dans une page de resultats. Les deux instructions n'ont d'effet que si la page finit par etre indexee — ce que le `noindex` interdit explicitement sur la meme page. Les ajouter aurait ete remplir des cases pour la forme, un jury averti le remarquerait.
- **Exposer la structure d'URL de l'admin serait plutot un risque qu'un gain.** `/admin/ingredients`, `/admin/users`, `/admin/roles` : rendre ces chemins decouvrables via un moteur de recherche elargit la surface visible depuis l'exterieur pour un gain de trafic organique nul, puisque personne ne peut de toute facon consulter ces pages sans session valide.
- **Les criteres de referencement naturel de C1.e visent l'interface client.** La borne, elle, porte `canonical` + `description` par page (verifie dans `01-validation-w3c.md` et le code des 5 pages) : c'est l'interface destinee a etre potentiellement decouverte, donc celle ou ces criteres ont un effet reel.

**Verdict Cr 1.e (hors 1.e.11) : non applicable au back-office, par decision documentee.** Cr 1.e.11 (lien d'evitement / ancres), lui, reste un critere d'accessibilite clavier valable sur toute interface authentifiee ou non — c'est pour ca qu'il est traite en section 5, applique aux deux perimetres, sans lien avec cette decision de noindex.

### 10.6 Tests ajoutes et chiffres

- `tests/js/skip-link.test.js` — 6 cas (5 pages + regle CSS).
- `tests/Unit/Admin/DashboardControllerTest.php` — 1 cas ajoute (`testShellHasSkipLinkFaviconAndDyslexiaToggleBeforeMainLandmark`).
- `tests/Unit/Admin/IngredientControllerTest.php` — 1 cas ajoute (`testIndexRendersTableOfContentsLinkingBothRealSections`).
- `tests/Unit/Admin/AdminAccessibilityAssetsTest.php` — 6 cas (nouveau fichier, garde de non-regression sur les assets partages et le CSS).

Point de mesure apres ce lot : 755 tests PHP (etaient 747), 209 tests JS (etaient 203), PHPStan niveau 6 a zero erreur, validateur W3C Nu rejoue sur les 5 pages borne (`{"messages":[]}`, inchange).

**Point de mesure au 2026-09-26** (apres la refonte du back-office et la regeneration du dossier de preuves) : **1 677 tests PHP, 4 855 assertions, `OK`** ; **356 tests JS** ; PHPStan niveau 6 a zero erreur ; validateur W3C Nu rejoue sur les 5 pages borne et sur 4 captures de DOM rendu (0 erreur, 1 avertissement assume).

### 10.7 Le back-office est desormais mesure, pas seulement relu (2026-09-26)

Cette section 10 documentait la parite d'accessibilite du back-office par **lecture du
code** : gabarit, assets partages, focus, semantique. L'audit mesure (`axe-core`), lui, ne
couvrait que **cinq** ecrans du back-office. Il en couvre **douze** depuis le 2026-09-26,
dont les cinq surfaces que le navigateur construit de toutes pieces : les lignes de recette
du formulaire produit, le bloc de slot du formulaire menu, la caisse comptoir, son composeur
de menu, et la caisse drive.

Deux resultats a retenir pour l'oral :

- **0 violation WCAG AA** sur les douze ecrans, contrastes compris (858 mesures au total
  avec la borne).
- **Un ecart trouve, corrige, et invisible pour l'outil** : le bloc de slot du formulaire
  menu etait un `<fieldset>` sans `<legend>`, donc un groupe de champs sans nom pour une
  technologie d'assistance. Aucune regle du jeu WCAG A/AA active ne couvre ce cas ; c'est
  la lecture du balisage nouvellement audite qui l'a leve. `menu-form.js` pose desormais
  une legende, et `tests/js/menu-form.test.js` la garde. Detail :
  `06-audit-accessibilite-mesure.md`, section 5 ter.

**Reserve honnete.** Le rendu HTML du back-office n'a pas ete soumis au validateur W3C Nu : la preuve `01-validation-w3c.md` scope explicitement cette campagne a la borne, et l'admin est du HTML rendu serveur. Les balises ajoutees ici (lien d'evitement, `id`, `<link rel="icon">`, `<script type="module">`) suivent une syntaxe standard deja utilisee ailleurs dans le depot, mais leur passage reel au validateur reste [UNVERIFIED]. Depuis le 2026-09-26, l'outillage existe pour lever cette reserve si besoin : `tests/e2e/run-w3c.sh` monte une pile qui sert le back-office, et `tests/e2e/w3c-capture.spec.js` sait serialiser un DOM rendu — il suffirait d'y ajouter les vues admin. Ce n'est pas fait : le perimetre du Bloc 1 reste la borne.

---

Perimetre couvert : Cr 1.c.1 (conforme), Cr 1.c.2 (conforme), Cr 1.c.3 (conforme, reserve contraste), Cr 1.c.4 (conforme, reserve coherence de focus), Cr 1.e.11 (conforme, borne + admin, section 5), back-office (parite d'accessibilite, section 10 ; Cr 1.e hors 1.e.11 non applicable par decision documentee). Les preuves de ce document combinent deux conventions : les citations historiques `fichier:ligne` (perimetre borne d'origine) et des citations par texte cherchable pour tout ce qui a ete ajoute depuis (moins sensible a la derive des numeros de ligne au fil des commits). Toutes sont verifiees dans le code du depot.
