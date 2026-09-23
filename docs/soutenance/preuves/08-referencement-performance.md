# Preuve 08 — Referencement, organisation CSS et poids des pages (C1.d, C1.e)

**Bloc 1 — Developpement de la partie front-end d'une application web**
**Criteres vises : Cr 1.d.2, Cr 1.e.2, Cr 1.e.3, Cr 1.e.5, Cr 1.e.7, Cr 1.e.8.**
Perimetre : les 5 pages de la borne (`src/public/borne/*.html`), leur feuille de style et les
fichiers JavaScript qui completent le balisage apres chargement. Etat au 2026-09-23 (lot F33).

La borne porte `noindex, nofollow` : elle n'a pas vocation a etre indexee. Le balisage de
referencement est donc demonstratif : il est construit et controle comme pour un site public,
sans en attendre de trafic.

## 1. Garde automatique et mesure

Chaque point ci-dessous est verifie par un test qui tourne en integration continue (job
`js-tests`) et echoue au premier ecart :

| Test | Ce qu'il garantit |
|---|---|
| `tests/js/borne-pages.test.js` | titres et descriptions (1.e.5), schema.org par page (1.e.3), exergue (1.e.2), `title` des liens et `alt` des images (1.e.7), banniere (1.e.8) |
| `tests/js/seo.test.js` | section de carte construite depuis les produits charges, insertion sans doublon, titre de la page produits |
| `tests/js/page-categories.test.js`, `tests/js/category-strip.test.js` | `title` des liens generes par JavaScript, mode de chargement des images |
| `tests/js/stylesheet-sections.test.js` | numerotation 1 a N des sections de `style.css` et sommaire exact (1.d.2) |

Mesure de chargement (hors integration continue, un temps depend de la machine) :
`tests/e2e/perf-accueil.spec.js`, lancee avec `PERF=1` dans la pile de test jetable (section 6).

Validateur W3C (moteur Nu 26.6.24, commande de `01-validation-w3c.md` section 2) rejoue le
2026-09-23 sur les 5 pages modifiees : `{"messages":[]}`, 0 erreur, 0 avertissement.

## 2. Cr 1.e.5 — Titres et descriptions uniques, de longueur mesuree

Reperes d'usage courant dans les guides de referencement, non normatifs : environ 50 a 60
caracteres pour le titre, 150 a 160 pour la description. Le test accepte 30 a 60 et 120 a 160,
et exige l'unicite des deux champs d'une page a l'autre.

| Page | Titre (`<title>`) | Car. | Description | Car. |
|---|---|---|---|---|
| `index.html` | Bienvenue chez Wakdo : commandez sur place ou a emporter | 56 | Commandez vos menus, burgers, boissons et desserts sur la borne... | 151 |
| `categories.html` | Carte Wakdo : choisissez une categorie de produits | 50 | Parcourez la carte Wakdo par categorie... | 150 |
| `products.html` | Produits Wakdo : composez et ajoutez a votre commande | 53 | Choisissez vos produits Wakdo dans la categorie selectionnee... | 145 |
| `payment.html` | Paiement de votre commande Wakdo : carte ou especes | 51 | Verifiez le recapitulatif de votre commande Wakdo... | 145 |
| `confirmation.html` | Commande Wakdo confirmee : votre numero de retrait | 50 | Votre commande Wakdo est enregistree et part en preparation... | 143 |

Avant le lot : titres de 16 a 20 caracteres (« Wakdo - Bienvenue ») et descriptions de 26 a 63.
La page produits, qui ne connait sa categorie qu'apres le chargement, prend ensuite le titre
« Nos Burgers - Borne de commande Wakdo » (`seo.js`, `categoryPageTitle`).

## 3. Cr 1.e.3 — Donnees structurees schema.org

Chaque page porte un bloc JSON-LD adapte a son role. Les blocs sont relies par leurs
identifiants `@id` (noeuds nommes au sens de JSON-LD 1.1, recommandation du W3C) : le
restaurant designe sa carte par le meme identifiant que celui du bloc de `categories.html`, et le
test verifie cette egalite.

| Page | Type(s) | Liens entre blocs |
|---|---|---|
| `index.html` | `WebSite` + `FastFoodRestaurant` | le site est publie par le restaurant ; le restaurant `hasMenu` -> la carte |
| `categories.html` | `Menu` (`#carte`) | cible de `hasMenu` |
| `products.html` | `MenuSection` | `isPartOf` -> la carte ; remplace apres chargement par la vraie section |
| `payment.html` | `CheckoutPage` | `isPartOf` -> le site |
| `confirmation.html` | `WebPage` | `isPartOf` -> le site |

Sur la page produits, `seo.js` (fichier du projet, sans librairie) remplace le bloc de depart par
la categorie affichee et un `MenuItem` par produit, avec son offre : prix decimal en euros
(`1250` centimes -> `"12.50"`), devise `EUR`, disponibilite `InStock` ou `OutOfStock`. Un
produit en rupture reste a la carte (RG-T21) : son offre le dit indisponible. Les adresses sont
calculees depuis le lien canonique de la page, comme celles des blocs fixes. Le chevron ouvrant
`<` est encode (`\u003c`) : un nom de produit contenant `</script>` reste une donnee.

Correction de fond : l'ancien bloc de l'accueil declarait `"paymentAccepted": "Sur place, A
emporter, Drive"`. Ce sont des modes de consommation, pas des moyens de paiement. La propriete
vaut desormais `"Carte bancaire, Especes"`, les deux moyens proposes a l'ecran de paiement ; le
test verifie qu'aucun mode de consommation n'y revient.

## 4. Cr 1.e.2 — Expressions cles mises en exergue

| Page | Balisage | Pourquoi ces mots |
|---|---|---|
| `index.html` | `<strong>sur place</strong>`, `<strong>l'emporter</strong>` | les deux choix que l'ecran demande de faire |
| `categories.html` | `<strong>une categorie</strong>` | la consigne de l'ecran |
| `confirmation.html` | `<strong>` numero de commande, montant, delai ; `<em>en preparation</em>` | ce que le client doit retenir pour le retrait |
| `payment.html` | `<strong>` sur le total (recapitulatif rendu par `page-payment.js`) | le montant a regler |

## 5. Cr 1.e.7 — Alternatives des images et titres des liens

- **Images** : chaque `<img>` porte un `alt` ; vide (`alt=""` + `aria-hidden`) quand l'image est
  decorative (fond d'accueil, vignettes du bandeau doublees par leur libelle). Inchange, deja
  couvert par la preuve `04-accessibilite-rgaa.md`.
- **Liens** : les 10 liens ecrits dans les pages (5 liens d'evitement, 2 choix d'accueil, 3
  retours) et les liens generes par JavaScript (cartes de categorie, bandeau, cartes produit)
  portent un `title`. Regle suivie : le `title` reprend au moins l'intitule du lien
  (`aria-label` compris), pour ne pas contredire ce qu'annonce un lecteur d'ecran ; le test le
  verifie lien par lien.

## 6. Cr 1.e.8 — Poids et temps de chargement

**Poids.** La banniere d'accueil `mc-landing-banner.png` pesait 1 256 918 octets. Sa version
WebP pese 95 546 octets (93 Kio, -92 %).

- Conversion : ImageMagick 6 + libwebp, qualite 80 (compression avec perte). Transparence
  conservee (la banniere en a : canal alpha de 0 a 1). Controle a l'oeil sur un recadrage a
  100 % compare a l'original : pas de difference visible. L'AVIF a ete essaye : 163 Ko ici, plus
  lourd que le WebP, non retenu.
- Balisage : `<picture>` propose le WebP, le PNG reste en repli pour un navigateur qui ne lit pas
  le WebP. Verifie dans Chromium (Resource Timing) : une seule ressource de banniere est recue,
  le `.webp` ; le PNG de repli n'est pas telecharge.

**Temps de chargement, mesure.** `tests/e2e/perf-accueil.spec.js`, Chromium de l'image
Playwright 1.49.1, pile de test jetable (serveur local), ecran de 1080 x 1920, cache desactive,
debit reduit par le protocole DevTools a 1,6 Mbit/s descendant, 750 kbit/s montant et 150 ms de
latence. L'accueil d'avant (`index.html` du commit `74d4398`) et celui d'apres sont servis par la
meme pile ; mediane de 5 chargements chacun, le 2026-09-23 :

| Mesure | Avant (PNG) | Apres (WebP) |
|---|---|---|
| Banniere entierement recue (`responseEnd`) | 6 747 ms | 945 ms |
| Page completement chargee (`loadEventEnd`) | 6 749 ms | 945 ms |
| Plus grand element affiche (LCP) | 588 ms | 582 ms |
| Octets recus pour la banniere | 1 256 918 | 95 546 |

Lecture : le chargement complet de l'accueil passe de 6,7 s a 0,95 s dans ces conditions. Le
plus grand element affiche retenu par Chromium est, dans les deux cas, le paragraphe de la
question (`welcome__question`), pas la banniere : ce temps-la ne change donc pas. Le gain porte
sur l'arrivee du fond d'ecran et sur la fin du chargement.

**Chargement des images.**
- Banniere : visible des l'arrivee sur l'ecran, elle est demandee en priorite
  (`fetchpriority="high"`) et n'est pas differee ; `width`/`height` reservent sa place.
- Cartes de categorie : visibles des l'arrivee sur l'ecran de la borne, elles sont chargees sans
  attendre (`decoding="async"` seulement). Un premier essai les differait ; il a ete retire a la
  relecture, pour appliquer la meme regle que la banniere.
- Vignettes du bandeau defilant (page produits) : la plupart sont hors du cadre visible, elles
  sont differees (`loading="lazy"`). Les images de la grille produits l'etaient deja.

## 7. Cr 1.d.2 — Code CSS organise et commente

`src/public/borne/assets/css/style.css` compte 21 sections numerotees, annoncees par un sommaire
en tete de fichier. Avant ce lot, la numerotation etait cassee : deux « 12. », deux « 13. », deux
« 14. », aucun « 9. », et cinq sections ajoutees plus tard n'avaient pas de numero (allergenes,
panneau de commande, bandeau, modale d'options, modale chevalet). Les sections ont ete
renumerotees sans deplacer de regle ; le renvoi interne « voir section 12 » vers les polices est
devenu « section 19 ». `tests/js/stylesheet-sections.test.js` empeche la derive de revenir :
numeros 1 a N sans doublon ni trou, sommaire identique aux bandeaux, aucun renvoi vers une
section absente.

## 8. Reserves

- Les longueurs de titre et de description sont des reperes d'usage, pas une regle du W3C.
- Le balisage schema.org n'a pas ete passe au validateur en ligne de schema.org
  (validator.schema.org) : la structure est controlee par les tests, pas par cet outil. A rejouer
  a l'oral si le jury le demande, en collant le bloc de la page.
- La regle sur le `title` des liens est celle du RGAA sur la pertinence des titres de liens ; le
  texte officiel n'a pas pu etre relu jusqu'a la thematique « Liens » le 2026-09-23, le numero
  exact du test n'est donc pas cite `[UNVERIFIED]`.
- La mesure de chargement est faite sur la pile de test (serveur local, debit simule), pas sur le
  serveur de production ; 5 passages par page.
- Les textes generes par les fichiers JavaScript de la borne et du back-office restent sans
  accents (« Recapitulatif », « Categories ») : a corriger dans un lot a part (F38).
