# Preuve 01 — Validation W3C du balisage (competence C1.a)

**Bloc 1 — Developpement de la partie front-end d'une application web.**

Criteres couverts :

- **Cr 1.a.2** — le code respecte les normes W3C (et les normes d'accessibilite).
- **Cr 1.a.3** — le code passe avec succes les tests du validateur.
- **Cr 1.a.5** — les balises semantiques sont utilisees a bon escient (recoupe la preuve `04-accessibilite-rgaa.md`).

Perimetre : les 5 pages de la borne client (`src/public/borne/*.html`), interface front-end evaluee au titre du Bloc 1. Le back-office (vues PHP) releve principalement des blocs back-end ; il n'est pas l'objet de cette preuve.

---

## 1. Outil et methode

Le validateur employe est le **W3C Nu Html Checker** (`vnu`), c'est-a-dire **le meme moteur que le validateur en ligne `validator.w3.org/nu`**. Il est execute **en local** via l'image conteneur officielle `ghcr.io/validator/validator` (version `vnu 26.6.24`), pour deux raisons :

1. **Reproductibilite** : une commande unique rejoue la validation, hors ligne, sans dependre de la disponibilite du service en ligne.
2. **Contrainte du service en ligne** : `validator.w3.org` est protege par un pare-feu applicatif (challenge Cloudflare) qui bloque les requetes automatisees ; le moteur Nu local donne un resultat identique sans ce blocage. Le meme controle reste faisable a la main a l'oral en collant l'URL de la borne dans `validator.w3.org`.

La validation est menee a **deux niveaux**, car la borne rend une partie de son contenu cote client (JavaScript) :

- **Niveau 1 — pages servies** : le HTML tel que le serveur l'envoie (fichiers `src/public/borne/*.html`).
- **Niveau 2 — DOM rendu** : le HTML apres execution du JavaScript (cartes produit, grille peuplee), capture via Playwright contre la borne en ligne avec des donnees reelles. C'est ce que verrait un jury en validant la page rendue.

---

## 2. Resultat

| Niveau | Pages | Erreurs | Avertissements |
|---|---|---|---|
| Pages servies | 5 (index, categories, products, payment, confirmation) | **0** | 0 |
| DOM rendu (donnees reelles) | 3 (accueil, categories, produits) | **0** | 1 (voir 4.3) |

Sorties brutes du validateur versionnees comme artefacts :

- `w3c/borne-statique.json` — `{"messages":[]}` (aucun message sur les 5 pages servies).
- `w3c/borne-rendu.json` — 0 erreur, 1 avertissement sur `produits`.
- `w3c/dom-rendu/` — le HTML rendu (accueil, categories, produits) reellement soumis au validateur.

### Commande reproductible (pages servies)

```bash
docker run --rm -v "$PWD/src/public/borne":/data:ro --entrypoint java \
  ghcr.io/validator/validator:latest -jar /vnu.jar --format json \
  /data/index.html /data/categories.html /data/products.html \
  /data/payment.html /data/confirmation.html
# -> {"version":"26.6.24 ...","messages":[]}
```

---

## 3. Corrections apportees pour atteindre le vert (Cr 1.a.2)

La validation a d'abord revele des erreurs reelles, corrigees avant d'obtenir le resultat ci-dessus. Les documenter fait partie de la preuve : la conformite est le fruit d'un diagnostic, pas d'une affirmation.

### 3.1 Pages servies — `aria-label` sur des elements generiques (3 erreurs)

Le validateur signalait un `aria-label` pose sur des elements sans role semantique adapte (`span`, `div`), ce que la specification ARIA n'autorise pas de facon fiable :

- `products.html` et `payment.html` — badge de mode de consommation (`<span class="mode-badge">`).
- `payment.html` — recapitulatif de commande (`<div class="payment-recap">`).

**Correction** :

- Badges de mode : `aria-label` **retire**. Le badge affiche deja la valeur dynamique (« Sur place » / « A emporter ») ecrite par `nav.js` ; l'`aria-label` la **masquait** pour le lecteur d'ecran (il annoncait « Mode de consommation » au lieu de la valeur). Le retrait corrige donc a la fois la conformite ET l'accessibilite reelle.
- Recapitulatif : passage en `role="region"` (region nommee legitime), qui autorise l'`aria-label` conserve.

### 3.2 DOM rendu — structure des cartes produit (2 classes d'erreurs)

La grille produits generee par `page-products.js` produisait un balisage invalide, repete sur chaque carte :

- `<a class="product-card">` place en **enfant direct d'un `<ul>`** — un `<ul>` n'accepte que des `<li>`.
- `<button>` (bouton « i » allergenes) place **a l'interieur du `<a>`** — un element interactif ne peut pas descendre d'un lien.

**Correction** (`page-products.js` + `style.css`) : chaque carte est enveloppee dans un `<li class="product-card-cell">` (seul enfant valide du `<ul>`), et le bouton allergenes devient un **frere** du lien (positionne par-dessus la carte en CSS, `position: relative` sur la cellule). L'apparence et les interactions sont inchangees ; les 151 tests JS restent verts.

### 3.3 Avertissement residuel assume (DOM rendu)

Le seul message restant est un **avertissement** (pas une erreur) : `aria-disabled="true"` sur le lien « Payer » quand le panier est vide (`order-panel.js`). C'est un choix d'accessibilite **delibere** : `.disabled` est sans effet sur un `<a>`, l'etat desactive est donc porte par `aria-disabled` (le lecteur d'ecran annonce « desactive »). Le validateur le note en information ; la page passe (0 erreur). A assumer a l'oral comme un compromis conscient, pas un oubli.

---

## 4. Balises semantiques (Cr 1.a.5)

Le balisage s'appuie sur les landmarks HTML5 a leur role : `header`, `nav`, `main`, `section`, `aside`, `footer`, avec une hierarchie de titres coherente (un seul `<h1>` par page). Le detail par element est documente dans `04-accessibilite-rgaa.md` (section Cr 1.c.1 et theme 12). Aucun `<div>` generique n'a ete signale la ou une balise semantique s'imposait.

---

## 5. Reserves honnetes

1. **Back-office non couvert ici.** Les 29 vues PHP du back-office ne sont pas validees dans cette preuve (perimetre = front borne, Bloc 1). Leur balisage a ete relu (doctype + `lang` portes par les deux layouts, `<th>` de colonne d'action parfois vides — valide mais signale par un audit a11y) mais sans passage au validateur.
2. **Etats interactifs non exhaustivement rendus.** Le DOM rendu valide couvre l'accueil, la liste categories et la grille produits peuplee. Les modales (composeur de menu, options produit, allergenes) n'ont pas ete soumises page par page ; leur structure a toutefois ete relue en statique : le composeur (`page-product-menu.js`) et le panneau commande (`order-panel.js`) utilisent des `<ul>` a enfants `<li>` conformes.
3. **Le validateur en ligne reste a rejouer a l'oral.** La preuve locale utilise le meme moteur ; montrer `validator.w3.org` en direct sur l'URL de la borne renforce la demonstration.

---

## 6. Points de defense a l'oral

1. **Rejouer la commande** (section 2) devant le jury : resultat `{"messages":[]}` sur les 5 pages, reproductible.
2. **Raconter le diagnostic** (section 3) : les erreurs trouvees puis corrigees montrent la maitrise du critere, mieux qu'un vert obtenu sans histoire. Insister sur le badge de mode : la correction W3C a aussi corrige un defaut d'accessibilite.
3. **Distinguer servi vs rendu** : savoir expliquer pourquoi une page peut etre valide « a la source » mais generer un DOM invalide cote client, et comment on valide le rendu (capture Playwright puis validateur).
4. **Assumer l'avertissement** du lien Payer desactive comme un compromis d'accessibilite documente.
