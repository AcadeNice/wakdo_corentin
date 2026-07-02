# Preuve 04 — Accessibilite RGAA (competence C1.c)

Titre professionnel RNCP 37805 — Bloc 1 (developpement front-end)
Perimetre : front borne de commande (`src/public/borne/`), 5 pages HTML + module a11y dedie + design system CSS.

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

- **Images informatives avec `alt` pertinent.** Chaque carte categorie porte un `alt` decrivant la categorie : `categories.html:56-60` (`alt="Menus"`), et les 8 autres cartes suivent le meme motif (`categories.html:64-174`). Les boutons de choix d'accueil : `index.html:78-82` (`alt="Table et chaises - Sur place"`) et `index.html:92-96` (`alt="Sac a emporter"`).
- **Image decorative neutralisee.** La photo de fond d'accueil porte `alt=""` **et** `aria-hidden="true"` : `index.html:52-57`. Le contenu utile vit dans la carte, l'image n'est donc pas annoncee.
- **Logo.** Le logo d'en-tete porte `alt="Wakdo"` sur les pages concernees : `categories.html:27-31`, `products.html:31-35`, `payment.html:29-33`, `confirmation.html:22-26`.
- **Icones SVG purement decoratives** marquees `aria-hidden="true"` + `focusable="false"` : SVG carte/especes de paiement `payment.html:61,77`, coche de confirmation `confirmation.html:34`.
- **Images injectees dynamiquement.** Les cartes produit generees par JS recoivent un `alt` egal au nom du produit et un fallback qui reecrit le `alt` en cas d'echec de chargement : `page-products.js:78-86` (`alt="${escHtml(product.nom)}"`, `onerror` posant `this.alt='Image non disponible'`). L'icone corbeille du panneau de commande est decorative : `alt=""` + `aria-hidden="true"`, l'information etant portee par l'`aria-label` du bouton parent : `order-panel.js:132-139`.

### aria-label et roles sur les controles

- **Liens-boutons d'accueil** : `role="button"` + `aria-label` explicite (`index.html:72-77`, `86-91`).
- **Retours de navigation** : `aria-label="Retour a l'accueil"` (`categories.html:24`), `aria-label="Retour aux categories"` (`products.html:23-28`, `payment.html:22-26`).
- **Cartes produit dynamiques** : `aria-label` combinant nom + prix, et `aria-disabled="true"` sur une tuile en rupture : `page-products.js:75-76`.
- **Stepper de quantite** dans le panneau commande : chaque groupe `role="group"` + `aria-label="Quantite de <libelle>"`, boutons `aria-label="Diminuer/Augmenter la quantite de <libelle>"`, retrait `aria-label="Retirer <libelle> de la commande"` : `order-panel.js:115-137`.
- **Boutons de paiement** : `aria-label="Payer par carte bancaire"` / `aria-label="Payer en especes"` : `payment.html:58,74`.
- **Bouton d'information allergenes** : `aria-label="Informations allergenes"` + `title` : `allergens.js:35-36`.

### Landmarks et regions vivantes

- **Landmarks** sur chaque page : `main` avec `aria-label` (ex. `index.html:46`, `products.html:39`, `payment.html:37`, `confirmation.html:29`), `nav` etiquetees (`index.html:66`, `categories.html:48`, `products.html:41`).
- **`aria-live`** : le panneau de commande annonce ses mises a jour (`aside ... aria-live="polite"`, `products.html:61`) ; la banniere de confirmation est un `role="status" aria-live="polite"` (`confirmation.html:31`) ; les blocs d'erreur sont `role="alert"` (`products.html:53`, `payment.html:46`).
- **`lang="fr"`** present sur les 5 pages (verifie : 1 occurrence par fichier).

### Correction recente de conformite ARIA

Des `aria-label` avaient ete poses sur des `span`/`div` generiques (badge de mode, recap de paiement). Ils ont ete retires pour respecter la specification W3C/ARIA (un `aria-label` sur un element sans role semantique n'est pas fiable selon les navigateurs). Etat actuel verifie : les badges de mode ne portent plus que `data-mode-badge` et laissent le lecteur d'ecran annoncer le **texte visible reel** (`products.html:49`, `payment.html:34`). Le bloc recapitulatif de paiement conserve, lui, un `role="region"` legitime avec son `aria-label` (`payment.html:42`).

**Verdict Cr 1.c.1 : conforme** sur le perimetre borne, avec une reserve honnete listee en section 8 (contenus dynamiques dependant du navigateur, non audites avec un lecteur d'ecran reel — [UNVERIFIED]).

---

## 3. Cr 1.c.2 — Police pour personnes dyslexiques (OpenDyslexic)

Fonctionnalite complete, prevue **et** integree, avec bascule utilisateur persistante.

- **Polices auto-hebergees.** Deux fichiers `woff2` presents sur disque : `opendyslexic-latin-400-normal.woff2` (112 Ko), `opendyslexic-latin-700-normal.woff2` (117 Ko), plus la licence `LICENSE-OpenDyslexic.txt` (OFL 1.1) sous `src/public/borne/assets/fonts/`.
- **`@font-face`** declares (poids 400 et 700) avec `font-display: swap` : `style.css:1926-1939`.
- **Bascule par classe racine.** `html.dys-font` redefinit `--font-family-base` vers la pile OpenDyslexic, appliquee a toute l'interface : `style.css:1944-1946`.
- **Module de bascule** `a11y.js` : lit la preference (`isDyslexiaEnabled`, l.27-33), applique/retire la classe sur `<html>` (`applyDyslexiaPreference`, l.40-44), persiste dans `localStorage` (`persistDyslexiaPreference`, l.51-59), construit un bouton `aria-pressed` refletant l'etat (`buildDyslexiaToggle`, l.68-98), injecte le bouton de facon idempotente (`initDyslexiaToggle`, l.106-125) et s'auto-initialise au `DOMContentLoaded` (l.129-131).
- **Bouton present sur chaque ecran** : le tag `<script type="module" src="assets/js/a11y.js">` est charge par les 5 pages (`index.html:104`, `categories.html:184`, `products.html:68`, `payment.html:92`, `confirmation.html:70`).
- **Robustesse.** L'acces storage est encapsule en `try/catch` : mode prive ou quota indisponible retombe sur la police de base sans erreur (l.28-32, l.52-58).
- **Style du bouton** : controle fixe en bas-gauche, `z-index` eleve, hors collision avec le bouton Retour et le panneau panier : `style.css:1952-1970`.
- **Tests unitaires** (jsdom, sans navigateur) : `tests/js/a11y.test.js` couvre lecture de preference, application de classe, injection idempotente, reflet `aria-pressed`, cycle de clic + persistance, et le cas storage qui jette (l.39-106).

**Verdict Cr 1.c.2 : conforme.** La bascule est le point le plus solide de la preuve : integree, persistee, testee.

---

## 4. Cr 1.c.3 — Information importante pas uniquement par la couleur

L'information ne repose pas sur la seule couleur : un libelle textuel ou une icone accompagne l'indice chromatique dans chaque cas identifie.

- **Produit en rupture de stock.** La tuile grisee (couleur) est doublee d'un badge textuel `Indisponible` (`page-products.js:87`, CSS `style.css:650-662`), d'un suffixe dans l'`aria-label` (` - indisponible`, `page-products.js:75`) et d'un `aria-disabled="true"` (`page-products.js:76`). Le grisage seul ne fait pas foi.
- **Categorie active dans le bandeau.** L'etat actif combine une bordure epaissie **et** un fond distinct `#FFF8E6`, precisement pour ne pas dependre de la seule bordure coloree : `style.css:1790-1794` (commentaire « 2e cue (pas que la couleur de bordure) »).
- **Selection de carte composeur.** L'etat selectionne cumule bordure jaune fonce, halo et fond legerement teinte, et il est expose a la technologie d'assistance via `aria-pressed` (documente `style.css:1166-1171`, `1133`).
- **Bascule de police active.** L'etat actif change la couleur du bouton mais est aussi expose par `aria-pressed` et par un libelle texte qui reste visible : `style.css:1977-1982` (commentaire « not signalled by colour alone (RGAA 1.4.1) »).
- **Contraste.** Le token de texte attenue est fixe a `#767676`, choisi pour le seuil de contraste AA sur blanc (`style.css:36`) ; l'accent de selection utilise le jaune fonce `--color-brand-yellow-dk` pour le contraste (`style.css:1168`).

**Verdict Cr 1.c.3 : conforme** sur les etats identifies. Reserve : les ratios de contraste exacts n'ont pas ete mesures avec un outil dedie ([UNVERIFIED], section 8).

---

## 5. Cr 1.c.4 — Navigation clavier complete (focus visible, pas de piege)

### Focus visible

- **Focus clavier stylise** sur la grande majorite des controles interactifs via `:focus-visible` (halo jaune ou `outline` epais) : 18 regles `:focus-visible` dans `style.css` (choix accueil `226`, retour `314`, carte categorie `373`, boutons primaire/secondaire `507`/`536`, carte produit `623`, quantite `724`, paiement `824`, carte composeur `1155`, taille `1372`, controles du panneau + bandeau `1819-1826`, saisie chevalet `1902-1905`, bascule a11y `1984-1987`).
- **`outline: none` systematiquement compense.** Chaque `outline: none` s'accompagne dans la meme regle d'un indicateur de substitution (halo `box-shadow` ou changement de bordure) — verifie regle par regle (ex. `style.css:226-232`, `507-512`, `1155-1160`). Il n'existe pas de suppression globale du focus : le reset (`style.css:83-87`) ne touche que `box-sizing`/`margin`/`padding`.

### Navigation native, pas de piege

- **Navigation par liens HTML natifs.** L'accueil et les cartes categorie sont de simples `<a href>` : la tabulation et l'activation clavier fonctionnent sans JavaScript (`index.html:72-98`, `categories.html:51-174`). Le commentaire de code le souligne (`index.html:44`, `categories.html:44`).
- **Cartes produit focusables au clavier.** Bien que le clic ouvre une modale, la carte reste un `<a>` avec `href` pour conserver focus et activation clavier (`page-products.js:70-74`, commentaire l.72-73).
- **Modales sans piege bloquant, avec focus gere.** La modale de confirmation d'un geste destructeur (`confirm-modal.js`) :
  - piege le `Tab`/`Shift+Tab` en boucle sur ses boutons (l.50-59) ;
  - se ferme sur `Echap` (l.51) et sur clic-fond (l.62) ;
  - restaure le focus au declencheur a la fermeture (`previouslyFocused`, l.19 + l.44-46) ;
  - met le fond en `aria-hidden` pendant l'ouverture (l.36-37) ;
  - place le focus initial sur « Annuler » pour qu'un `Entree` accidentel ne declenche pas l'action destructrice (l.69-70).
  Ce comportement est un piege **voulu et sortable** (Echap + boucle), pas un piege bloquant : c'est la definition attendue d'une modale accessible.
- **Modale allergenes** : fermeture `Echap` (`allergens.js:18-22, 116`), fermeture clic-fond (l.110-114), `role="dialog"` + `aria-modal="true"` (l.61-63), bouton de fermeture etiquete (l.68-72).
- **Tests** : `tests/js/confirm-modal.test.js` verifie `role="dialog"` + `aria-modal`, la fermeture par Echap et clic-fond sans effet destructeur (l.23-62).

**Verdict Cr 1.c.4 : conforme avec reserve.** Le focus reste visible et non perdu sur le perimetre lu ; la coherence de **style** du focus est partielle (voir section 8 : quelques controles secondaires reposent sur l'anneau natif du navigateur, non stylise).

---

## 6. Checklist RGAA auto-evaluee

Verdicts : **conforme** (preuve dans le code) · **partiel** (couvert mais reserve honnete) · **non applicable**.

### Theme 1 — Images

| Critere | Preuve (fichier:ligne) | Verdict | Commentaire |
|---|---|---|---|
| Image informative a une alternative | `categories.html:56-60,64-174` ; `index.html:78-96` | conforme | `alt` decrivant chaque categorie / illustration de mode. |
| Image decorative correctement ignoree | `index.html:52-57` | conforme | `alt=""` + `aria-hidden="true"` sur le fond d'accueil. |
| Alternative des images injectees | `page-products.js:78-86` | conforme | `alt` = nom produit ; `onerror` reecrit `alt` en fallback. |
| Icones/SVG decoratifs ignores | `payment.html:61,77` ; `confirmation.html:34` ; `order-panel.js:138` | conforme | `aria-hidden="true"` + `focusable="false"` / `alt=""`. |

### Theme 3 — Couleurs

| Critere | Preuve (fichier:ligne) | Verdict | Commentaire |
|---|---|---|---|
| Info pas donnee par la seule couleur | `page-products.js:75,87` ; `style.css:1790-1794`,`1977-1982` | conforme | Rupture = badge texte + aria ; actif = fond + libelle + `aria-pressed`. |
| Contraste texte suffisant | `style.css:36` (`#767676`) ; `style.css:1168` | partiel | Tokens choisis pour AA, mais ratios non mesures a l'outil ([UNVERIFIED]). |

### Theme 10 — Presentation / focus

| Critere | Preuve (fichier:ligne) | Verdict | Commentaire |
|---|---|---|---|
| Focus clavier visible | `style.css` 18 regles `:focus-visible` (226,314,373,507,536,623,724,824,1155,1372,1819-1826,1902,1984) | conforme | Halo jaune / `outline` epais decale. |
| `outline:none` compense | `style.css:226-232,507-512,1155-1160` | conforme | Chaque suppression a un substitut visible ; pas de reset global du focus. |
| Coherence du style de focus | `style.css:1855` (`.size-btn`), `1199`/`1249` (allergenes) | partiel | Quelques controles secondaires reposent sur l'anneau natif (non perdu, mais non stylise). |

### Theme 11 — Formulaires / controles

| Critere | Preuve (fichier:ligne) | Verdict | Commentaire |
|---|---|---|---|
| Controle a une etiquette | `payment.html:58,74` ; `order-panel.js:115-137` ; `allergens.js:35` | conforme | `aria-label` sur boutons paiement, stepper, retrait, info allergenes. |
| Champ de saisie etiquete | `style.css:1888-1905` (chevalet) | partiel | Le champ chevalet a un focus visible ; le libelle textuel proche vit dans une modale JS hors des 5 pages lues, a verifier. |
| Etat desactive expose | `order-panel.js:182` ; `style.css:519-527,1724-1727` | conforme | `aria-disabled` sur « Payer » panier vide et boutons desactives. |

### Theme 12 — Navigation

| Critere | Preuve (fichier:ligne) | Verdict | Commentaire |
|---|---|---|---|
| Landmarks / zones | `index.html:46,66` ; `products.html:39,41,61` ; `payment.html:37,48` | conforme | `main`, `nav`, `aside`, `header` etiquetes. |
| Navigation clavier sans piege bloquant | `index.html:72-98` ; `page-products.js:70-74` ; `confirm-modal.js:50-59` | conforme | Liens natifs ; modales sortables (Echap + boucle Tab). |
| Titre de page pertinent | `index.html:20` ; `categories.html:8` ; `payment.html:8` ; `confirmation.html:8` | conforme | `<title>` distinct par ecran, mis a jour dynamiquement (`page-products.js:48`). |
| Langue de la page | 5 pages `html lang="fr"` | conforme | Verifie : 1 occurrence par fichier. |

### Fonctionnalite — Police OpenDyslexic (Cr 1.c.2)

| Critere | Preuve (fichier:ligne) | Verdict | Commentaire |
|---|---|---|---|
| Police auto-hebergee + `@font-face` | `assets/fonts/*.woff2` ; `style.css:1926-1939` | conforme | Poids 400/700, `font-display: swap`, licence OFL presente. |
| Bascule utilisateur persistante | `a11y.js:40-125` ; `style.css:1944-1946` | conforme | Classe `.dys-font` sur `<html>`, persistance `localStorage`. |
| Presence sur tous les ecrans | 5 pages chargent `a11y.js` | conforme | `index:104`,`categories:184`,`products:68`,`payment:92`,`confirmation:70`. |
| Etat expose a l'assistance | `a11y.js:73,89-95` | conforme | `aria-pressed` reflete l'etat ; couvert par tests. |
| Couverture de test | `tests/js/a11y.test.js:39-106` | conforme | 7 cas jsdom (dont storage en echec). |

---

## 7. Zones non applicables ou hors perimetre

- **Multimedia (theme 4), tableaux de donnees (theme 5), cadres (theme 6), scripts complexes hors modale** : non applicable au front borne, qui ne comporte ni video, ni tableau de donnees, ni iframe.
- **Documents en telechargement (theme 13)** : non applicable (aucun PDF/document servi par la borne).
- **Consultation / zoom (theme 10 avance)** : la borne cible un ecran tactile fixe 1080x1920 ; le zoom navigateur n'est pas le mode d'usage principal. `touch-action: manipulation` (`style.css:92`) previent le pinch-zoom accidentel — choix d'ergonomie borne a assumer a l'oral, car il peut interroger le critere de redimensionnement.

---

## 8. Reserves honnetes (a ne pas survendre au jury)

1. **Aucun audit avec lecteur d'ecran reel.** Le mapping ARIA est correct dans le code, mais le rendu effectif sous NVDA/VoiceOver/TalkBack n'a pas ete teste sur cette borne. Toute affirmation de restitution vocale reelle est [UNVERIFIED].
2. **Ratios de contraste non mesures a l'outil.** Les tokens sont choisis pour viser AA (`#767676` sur blanc, jaune fonce pour les accents), mais aucun rapport d'outil (ex. verificateur de contraste) n'est joint. Verdict « partiel » assume sur le critere contraste.
3. **Coherence du style de focus partielle.** Le focus n'est pas perdu (pas de reset global), mais quelques controles secondaires (`.size-btn` `style.css:1855`, bouton info allergenes `style.css:1199`, fermeture modale allergenes `style.css:1249`) reposent sur l'anneau de focus natif du navigateur plutot que sur le halo jaune maison. C'est conforme (focus visible) mais visuellement heterogene.
4. **Champ chevalet hors des 5 pages lues.** Le picker de chevalet (sur place) a un focus visible en CSS (`style.css:1888-1905`) mais son etiquette textuelle vit dans une modale JS non incluse dans les 5 pages de ce perimetre ; verdict « partiel » par prudence.
5. **Contenu genere = surface a re-tester.** Les cartes produit et le panneau commande sont construits en JavaScript. Les attributs ARIA sont poses dans le code (`page-products.js`, `order-panel.js`), mais leur presence a l'ecran depend de l'execution correcte du rendu ; a demontrer en live plutot qu'a affirmer.

---

## 9. Points de defense a l'oral

1. **Montrer la bascule OpenDyslexic en direct.** C'est la preuve la plus forte : cliquer le bouton bas-gauche, montrer le changement de police sur toute l'interface, recharger la page pour prouver la persistance `localStorage`. Enchainer sur le test unitaire `tests/js/a11y.test.js` (7 cas, dont le mode prive qui jette).
2. **Expliquer le principe « pas la couleur seule ».** Prendre l'exemple d'une tuile en rupture : montrer le badge `Indisponible`, le grisage, et l'`aria-disabled` — trois signaux, un seul resterait insuffisant (RGAA 1.4.1). Idem pour la categorie active (bordure + fond `#FFF8E6`).
3. **Demontrer la modale accessible au clavier.** Ouvrir la confirmation d'Abandon, tabuler pour montrer la boucle de focus, appuyer sur Echap pour sortir, verifier que le focus revient au bouton declencheur. Insister : le focus initial est sur « Annuler » pour ne pas confirmer un geste destructeur par inadvertance.
4. **Assumer la difference RGAA vs WCAG.** Le referentiel opposable en France est le RGAA ; il s'appuie sur WCAG mais ajoute une methodologie de test. Le code cite explicitement des criteres RGAA en commentaire (`style.css:16,131,1977`).
5. **Etre transparent sur les reserves (section 8).** Un jury valorise l'honnetete : dire clairement que l'audit lecteur d'ecran et la mesure de contraste a l'outil restent a faire, et que ce sont les prochaines etapes d'un vrai chantier de conformite. Ne pas revendiquer une conformite RGAA totale certifiee — revendiquer une **demarche d'accessibilite structuree et testee** sur le perimetre borne.
6. **Relier a la correction ARIA recente.** Montrer que l'on comprend la specification : les `aria-label` parasites sur des `span`/`div` sans role ont ete retires car un `aria-label` sur un element non semantique n'est pas fiable ; on laisse desormais le lecteur annoncer le texte visible reel du badge de mode.

---

Perimetre couvert : Cr 1.c.1 (conforme), Cr 1.c.2 (conforme), Cr 1.c.3 (conforme, reserve contraste), Cr 1.c.4 (conforme, reserve coherence de focus). Toutes les preuves sont sourcees en `fichier:ligne` et verifiees dans le code du depot.
