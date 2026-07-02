# Preuve 03 — Conformite cross-navigateurs (C1.b)

**Bloc 1 — Developpement de la partie front-end d'une application web.**
Competence **C1.b** : concevoir des interfaces compatibles avec les differents
navigateurs, et corriger les incompatibilites en s'appuyant sur la documentation.

Criteres couverts :

- **Cr 1.b.2** — les proprietes CSS utilisees sont compatibles entre navigateurs.
- **Cr 1.b.3** — en cas d'incompatibilite, une correction / alternative est
  apportee en s'appuyant sur la documentation.

Fichiers examines (code reel) :

- `src/public/borne/assets/css/style.css` (2001 lignes) — front borne de commande.
- `src/public/admin/assets/css/admin.css` (2178 lignes) — back-office.

Perimetre honnete de cette preuve : elle documente le *choix* et le *fallback*
des proprietes CSS a partir de la lecture du code source et de la matrice de
support publiee (MDN / caniuse). Elle ne rapporte pas de campagne de tests
multi-navigateurs executee sur un parc reel de terminaux ; ce point est traite
en section « Limites et perimetre de verification ».

---

## 1. Contexte de compatibilite

Le projet est constitue de deux feuilles de style manuscrites, sans framework
CSS ni build (pas de PostCSS/Autoprefixer). Le prefixage et les replis sont
donc ecrits a la main, ce qui rend la strategie de compatibilite lisible et
verifiable ligne par ligne.

Deux cibles distinctes, donc deux profils de compatibilite :

- **Borne** : ecran tactile portrait 1080x1920, moteur de rendu maitrise
  (kiosk). En-tete du fichier : `src/public/borne/assets/css/style.css:14`.
- **Back-office** : navigateur de poste, desktop-first optimise 1280px+.
  En-tete : `src/public/admin/assets/css/admin.css:1-5`.

Toutes les couleurs, tailles et rayons sont centralises en custom properties
(`:root`), ce qui est en soi un choix de compatibilite (voir tableau).

---

## 2. Inventaire des fonctionnalites CSS modernes reellement employees (Cr 1.b.2)

Support navigateur : les colonnes ci-dessous synthetisent la matrice de support
publiee par MDN et caniuse. N'ayant pas ouvert ces pages pendant la redaction
(pas de fetch reseau), ces cellules de support sont taguees `[UNVERIFIED]` : ce
sont des rappels de connaissance a re-verifier avant l'oral sur caniuse.com. Les
colonnes Usage (fichier:ligne) et Strategie, elles, sont verifiees directement
dans le code source.

| Fonctionnalite CSS | Usage (fichier:ligne) | Support navigateurs `[UNVERIFIED]` | Strategie |
|---|---|---|---|
| Custom properties `var()` | borne `style.css:26-77` (bloc `:root`), 477 occurrences de `var(--` ; admin `admin.css:14-66`, 369 occurrences | Chrome/Edge/Firefox/Safari modernes ; non supporte IE11 | OK sur cibles retenues. Choix structurant : un seul point de verite pour la charte. IE11 hors perimetre (kiosk + postes recents). |
| `var()` avec valeur de repli (2e argument) | admin `admin.css:1549` (`var(--radius-pill, 9999px)`), `admin.css:1631-1632`, `admin.css:1691` | idem `var()` | Degradation : si la variable est absente, la 2e valeur s'applique. Repli defensif documente (Cr 1.b.3). |
| Flexbox (`display: flex`) | borne `style.css:157` et 52 occurrences ; admin `admin.css:131` et 58 occurrences | tres large ; supporte Safari >= 9, IE11 partiel/bogue | OK. Fondation de mise en page, support de reference. |
| `gap` sur conteneur flex | borne `style.css:202`, `style.css:1531`, `admin.css:527`, etc. | Grid : large ; **flex-gap** : Safari **< 14.1** ne le gere pas `[UNVERIFIED]` | **Fallback explicite** via `@supports not (gap: 1rem)` — voir section 3. |
| CSS Grid (`display: grid`) | borne `style.css:342`, `style.css:592`, `style.css:1120` ; admin `admin.css:119` (shell `grid-template-areas`), `admin.css:507`, `admin.css:1571` | large ; Safari >= 10.1, pas IE11 (ancienne syntaxe -ms-) | OK sur cibles. `grid-template-areas` (`admin.css:123-125`) pour le shell. |
| `grid-template-columns: repeat(auto-fill, minmax(...))` | borne `style.css:1122` ; admin `admin.css:1034`, `admin.css:1573` | suit le support Grid | OK. Grilles fluides sans media query. |
| `minmax(min(260px, 100%), 1fr)` (fonction `min()` imbriquee) | admin `admin.css:1989` | fonctions math CSS : Safari >= 11.1, Chrome/Firefox modernes `[UNVERIFIED]` | OK. `min()` evite le debordement des cartes sur tres petits ecrans. |
| `position: sticky` | borne `style.css:284` (header), `style.css:1542` (panneau), `style.css:1753` ; admin `admin.css:1697` | large ; Safari a longtemps exige `-webkit-sticky` `[UNVERIFIED]` | OK sur moteurs recents. **Point d'attention** : pas de repli `-webkit-sticky` ecrit (voir section 5). |
| `inset: 0` (raccourci) | borne `style.css:165`, `style.css:981`, `style.css:995`, `style.css:1226` ; admin `admin.css:1431`, `admin.css:1619`, `admin.css:1833` | recent ; equivaut a top/right/bottom/left | OK. Alternative equivalente `top/right/bottom/left: 0` disponible si besoin (degradation triviale). |
| `aspect-ratio` | borne `style.css:666`, `style.css:1175` ; admin `admin.css:1610` | recent : Chrome >= 88, Safari >= 15 `[UNVERIFIED]` | Degradation acceptable : les conteneurs concernes ont aussi une taille (largeur 100% + `object-fit`) ; l'absence d'`aspect-ratio` degrade la proportion sans casser la mise en page. |
| `object-fit` / `object-position` | borne `style.css:168-169`, `style.css:242`, `style.css:677` (7 occurrences) ; admin `admin.css:1015`, `admin.css:1622` | large hors IE ; IE ne gere pas `object-fit` | OK. Sur les cibles retenues, supporte. |
| `:focus-visible` | borne `style.css:226` et 18 occurrences ; admin `admin.css:1559`, `admin.css:1599` (4 occurrences) | recent : Chrome >= 86, Safari >= 15.4 `[UNVERIFIED]` | **Degradation geree par co-selecteur** : chaque regle associe `:hover, :focus-visible` (ex. `style.css:225-226`). Sur un moteur sans `:focus-visible`, le selecteur invalide est ignore mais `:hover` reste ; le focus clavier reste par ailleurs signale par les regles `outline` dediees (`style.css:1819-1826`). |
| `accent-color` | borne `style.css:1335` (case a cocher) | recent : Chrome >= 93, Safari >= 15.4 `[UNVERIFIED]` | Degradation cosmetique : sur un moteur ancien la case garde sa couleur native ; la fonction (cocher/decocher) est intacte. |
| `filter: grayscale()` | borne `style.css:639` ; admin `admin.css:1677` (tuile en rupture) | large ; historiquement `-webkit-filter` sur vieux WebKit `[UNVERIFIED]` | Degradation : la rupture reste signalee par `opacity` + le badge « Indisponible » (`style.css:651`), donc le sens ne repose pas sur le seul filtre (aussi une exigence a11y). |
| `appearance: none` + fleche SVG en `data:` URI | admin `admin.css:630-633`, `admin.css:933-936` | `appearance` standard recent ; historiquement `-webkit-/-moz-appearance` `[UNVERIFIED]` | **Point d'attention** : pas de prefixe `-webkit-appearance` ecrit ; sur un vieux moteur le select garde sa fleche native, sans perte de fonction (voir section 5). |
| `font-variant-numeric: tabular-nums` | admin `admin.css:1137`, `admin.css:1656`, `admin.css:1756`, `admin.css:1794`, `admin.css:1811` | recent `[UNVERIFIED]` | Degradation cosmetique : sans support, les chiffres ne sont pas a chasse fixe ; les montants restent lisibles. |
| `scrollbar-width: none` (Firefox) + `::-webkit-scrollbar { display:none }` | borne `style.css:1765` **et** `style.css:1768-1770` | proprietaire par moteur | **Double declaration croisee** : la version standard Firefox et la pseudo WebKit sont ecrites ensemble pour couvrir les deux familles (Cr 1.b.3). |
| `::-webkit-scrollbar` (theme scrollbar admin) | admin `admin.css:1285-1301` | WebKit/Blink uniquement | Degradation : sur Firefox la scrollbar garde son style natif ; contenu non affecte. |
| `-webkit-tap-highlight-color: transparent` | borne `style.css:220`, `style.css:366`, `style.css:496` (7 occurrences) | WebKit mobile ; ignore ailleurs | Prefixe intentionnel, ignore sans effet de bord par les autres moteurs. |
| `-webkit-overflow-scrolling: touch` | admin `admin.css:1542` | WebKit iOS ancien | Prefixe intentionnel, ignore ailleurs. Ameliore le defilement inertiel sur iOS. |
| `-webkit-font-smoothing: antialiased` | admin `admin.css:75` | WebKit/Blink | Cosmetique, ignore ailleurs. |
| `calc()` | borne `style.css:1544` ; admin `admin.css:149`, `admin.css:1701`, etc. (5 occurrences) | tres large ; Safari >= 6.1 | OK. |
| `@media` (points de rupture) | borne 7 requetes (`style.css:405`, `423`, `446`, `949`, `960`, `1495`, `1731`) ; admin 3 (`admin.css:1489`, `1819`, `2094`) | tres large tous moteurs | OK. Responsive natif. |
| `@font-face` + `font-display: swap` (OpenDyslexic auto-heberge) | borne `style.css:1926-1939` | `@font-face` tres large ; `font-display` recent | OK. Police servie en local (pas de CDN), format woff2. |
| `@keyframes` / `animation` | borne `style.css:887-893`, `style.css:1028-1031`, `style.css:1048-1051` | tres large tous moteurs | OK. Entrees discretes (pop, fade, slide). |

Lecture : la tres grande majorite des proprietes sont dans le socle largement
supporte (flex, grid, var, media, calc, object-fit). Trois proprietes sont
« recentes » (`aspect-ratio`, `:focus-visible`, `accent-color`) et sont
employees en amelioration progressive : leur absence degrade l'apparence, pas la
fonction. Une seule incompatibilite bien connue a justifie un **fallback
explicite conditionnel** : le `gap` en contexte flex, traite ci-dessous.

---

## 3. Cr 1.b.3 — Le fallback explicite du flex-gap (`@supports`)

C'est la preuve centrale de la correction d'incompatibilite documentee.

### 3.1 Le probleme

La propriete `gap` a d'abord ete specifiee pour CSS Grid, puis etendue au
Flexbox. Le support du `gap` **en contexte flex** est arrive plus tard que le
support du `gap` en grid. En particulier, Safari (moteur WebKit) ne gere le
flex-`gap` qu'a partir de la version **14.1** `[UNVERIFIED — a confirmer sur
caniuse.com, requete « flexbox-gap »]`. Sur une version anterieure, un
`display: flex; gap: 1rem;` ne produit pas d'espacement entre les enfants :
ils se collent, ce qui casse la lisibilite des rangees de boutons de la borne.

Le projet utilise `gap` sur des conteneurs flex a de nombreux endroits (ex.
`.welcome__choices` `style.css:200-204`, `.composer-footer__row`
`style.css:1480-1486`). Il fallait donc un repli pour les moteurs sans
flex-gap.

### 3.2 La correction (extrait de code reel)

`src/public/borne/assets/css/style.css:1989-2001` :

```css
/* ============================================================
   13. COMPATIBILITE NAVIGATEURS (Cr 1.b.3)
   ============================================================ */

/* 'gap' sur conteneurs flex : non reconnu par d'anciens navigateurs (ex. Safari
   anterieur a 14.1). La detection @supports applique un repli par marges sur les
   enfants directs si la propriete n'est pas supportee (alternative documentee). */
@supports not (gap: 1rem) {
    .welcome__choices > *,
    .composer-footer__row > * {
        margin: var(--space-2);
    }
}
```

### 3.3 Pourquoi cette solution est correcte

- **Feature detection, pas user-agent sniffing.** `@supports not (gap: 1rem)`
  interroge le moteur sur sa capacite reelle, et non sur son nom ou sa version.
  C'est la methode recommandee : robuste dans le temps (un futur moteur qui
  gagne le support desactive automatiquement le repli), et sans liste de
  navigateurs a maintenir.
- **Repli additif, pas destructeur.** Le bloc n'ajoute une `margin` sur les
  enfants directs **que** lorsque `gap` n'est pas supporte. Sur un moteur qui
  gere le flex-gap (la cible principale), le bloc est inerte : sans regression,
  sans double marge.
- **Reutilise le systeme de tokens.** La marge de repli vaut `var(--space-2)`
  (0.5rem, `style.css:56`), coherente avec l'echelle d'espacement du reste de la
  feuille. Le repli reste donc aligne sur la charte.
- **Cible les conteneurs sensibles.** Le repli est applique aux deux rangees ou
  l'ecrasement serait le plus visible pour l'utilisateur (choix d'accueil, pied
  d'actions du composeur), et non indistinctement a tout le document.

### 3.4 Reference documentaire (Cr 1.b.3)

Sources a citer et a re-verifier avant l'oral (non ouvertes pendant la
redaction, donc `[UNVERIFIED]`) :

- MDN — `@supports` (at-rule / feature queries) :
  `developer.mozilla.org/en-US/docs/Web/CSS/@supports` `[UNVERIFIED]`.
- MDN — `gap` (et son extension au contexte flex) :
  `developer.mozilla.org/en-US/docs/Web/CSS/gap` `[UNVERIFIED]`.
- caniuse.com — requete « flexbox-gap » pour la version exacte de bascule de
  Safari (annoncee ici comme 14.1) `[UNVERIFIED]`.

Le seuil « Safari < 14.1 » est ecrit dans le commentaire du code source
(`style.css:1993-1994`). Il doit etre confirme sur caniuse avant l'oral ; il est
donne ici comme rappel de connaissance, et non comme fait sourced.

---

## 4. Autres corrections / replis presents dans le code (Cr 1.b.3)

Le `@supports` n'est pas l'unique mecanisme de compatibilite du projet. On
releve trois autres patterns defensifs, verifies dans le code :

1. **Scrollbar cross-moteur (double declaration).** Le bandeau de categories de
   la borne masque sa scrollbar sur les deux familles de moteurs : la propriete
   standard Firefox `scrollbar-width: none` (`style.css:1765`) **et** la pseudo
   WebKit/Blink `::-webkit-scrollbar { display: none }` (`style.css:1768-1770`)
   sont ecrites ensemble. Chaque moteur applique celle qu'il connait et ignore
   l'autre.

2. **`var()` avec valeur de repli.** Dans le back-office, plusieurs proprietes
   fournissent une seconde valeur a `var()` :
   `var(--radius-pill, 9999px)` (`admin.css:1549`),
   `var(--color-yellow-soft, #FFF3D1)` (`admin.css:1631`),
   `var(--color-brand-dark, var(--color-text))` (`admin.css:1691`, repli
   chaine). Si la variable n'est pas definie dans la portee, la valeur litterale
   de repli s'applique — degradation propre.

3. **Amelioration progressive systematique.** Les proprietes recentes ne portent
   pas une fonction critique seule : `:focus-visible` est couple a
   `:hover` (ex. `style.css:225-226`) et double par des regles `outline`
   dediees ; `filter: grayscale()` sur une tuile en rupture est double par
   `opacity` et un badge texte « Indisponible » (`style.css:637-662`) ;
   `accent-color` et `font-variant-numeric` ne portent que du cosmetique. Le
   sens et l'action survivent a l'absence de la propriete.

---

## 5. Limites et perimetre de verification (honnetete)

Ce que cette preuve **etablit** :

- L'inventaire des proprietes employees et leur localisation exacte dans le code
  (colonnes Usage verifiees).
- L'existence, la localisation et le fonctionnement d'un fallback explicite et
  documente pour l'incompatibilite flex-gap (`@supports`, `style.css:1996`), qui
  couvre directement Cr 1.b.3.
- Trois patterns defensifs additionnels (scrollbar croisee, `var()` de repli,
  amelioration progressive).

Ce que cette preuve **ne couvre pas** (a dire au jury) :

- **Pas de campagne de tests multi-navigateurs executee.** Aucun rapport de test
  sur un parc reel (BrowserStack, matrice Chrome/Firefox/Safari/Edge, versions
  datees) n'est produit ici. Les colonnes « Support navigateurs » sont des
  rappels de connaissance taguees `[UNVERIFIED]`, a confirmer sur caniuse avant
  l'oral. C'est le principal manque a combler pour une couverture pleine de
  C1.b.
- **Points d'attention non repliques explicitement :**
  - `position: sticky` est utilise sans repli `-webkit-sticky` (borne header
    `style.css:284`, panneau `style.css:1542`, admin `admin.css:1697`). Sur un
    tres vieux Safari, l'element defilerait au lieu de coller — degradation
    acceptable (le contenu reste accessible), mais non testee.
  - `appearance: none` sur les selects du back-office (`admin.css:630`,
    `admin.css:933`) n'a pas de prefixe `-webkit-appearance`/`-moz-appearance`.
    Sur un moteur ancien, la fleche native du select coexisterait avec la fleche
    SVG custom — defaut cosmetique, sans perte de fonction.
- **Perimetre navigateurs assume.** Les deux cibles (borne kiosk + postes du
  back-office) sont des environnements recents et maitrises. IE11 est
  explicitement hors perimetre (usage de custom properties et de Grid moderne).
  Ce choix est defendable pour l'usage vise mais doit etre enonce comme un
  perimetre, et non comme une compatibilite universelle.

---

## 6. Mapping critere -> preuve (recapitulatif)

| Critere | Statut | Preuve principale |
|---|---|---|
| **Cr 1.b.2** — proprietes CSS compatibles | **Couvert** (avec perimetre navigateurs assume) | Inventaire section 2 : socle largement supporte + proprietes recentes en amelioration progressive. Support a re-confirmer sur caniuse (`[UNVERIFIED]`). |
| **Cr 1.b.3** — correction/alternative documentee | **Couvert** | Fallback flex-gap `@supports not (gap: 1rem)` — `style.css:1996-2001` (extrait section 3), plus 3 patterns defensifs section 4. Reference doc MDN/caniuse a citer, `[UNVERIFIED]` avant fetch. |

---

## 7. Points de defense a l'oral

1. **« Montrez-moi une incompatibilite que vous avez corrigee. »** Ouvrir
   `borne/assets/css/style.css` a la ligne 1996. Expliquer le probleme (Safari
   < 14.1 ignore le flex-gap, les boutons se collent) puis la correction
   (`@supports not` + marge de repli sur les enfants directs).

2. **« Pourquoi `@supports` et pas un test de version de navigateur ? »** Parce
   que c'est de la feature detection : on interroge la capacite du moteur, pas
   son nom. Robuste dans le temps, zero liste a maintenir, et le repli
   s'auto-desactive des que le moteur gagne le support.

3. **« Ce repli ne casse-t-il pas la mise en page sur les navigateurs
   recents ? »** Non : `@supports not (...)` n'active le bloc que si la propriete
   est absente. Sur la cible principale (moteur recent) le bloc est inerte, donc
   sans double marge, sans regression.

4. **« Comment gerez-vous les proprietes tres recentes comme `aspect-ratio` ou
   `:focus-visible` ? »** Amelioration progressive : elles n'apparaissent pas
   comme unique porteuse d'une fonction. `:focus-visible` est couple a
   `:hover` et double par des regles `outline` ; `aspect-ratio` s'appuie sur un
   conteneur qui a deja une taille + `object-fit`. L'absence degrade
   l'apparence, pas l'usage.

5. **« Avez-vous teste sur de vrais navigateurs ? »** Reponse honnete : la
   strategie de compatibilite est ecrite et verifiable dans le code (fallback,
   detection, prefixes croises), mais je n'ai pas de rapport de campagne de test
   multi-navigateurs a presenter. C'est le prolongement naturel de cette
   preuve : passer la matrice caniuse au vert et executer un run sur
   Chrome/Firefox/Safari/Edge datés.

6. **« Pourquoi deux feuilles de style separees ? »** Deux cibles, deux profils
   de compatibilite : la borne (kiosk tactile portrait, moteur maitrise) et le
   back-office (poste desktop). Chaque feuille assume son perimetre navigateurs,
   ce qui evite de brider la borne pour un navigateur que le poste seul
   rencontrerait.

7. **Point de franchise a anticiper.** Si le jury pointe `position: sticky` sans
   `-webkit-sticky` ou `appearance` sans prefixe : reconnaitre que ce sont des
   degradations cosmetiques assumees (contenu et fonction preserves), et non des
   oublis masques. Le montrer renforce la credibilite plus que de le cacher.
