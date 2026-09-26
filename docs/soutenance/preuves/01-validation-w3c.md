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
- **Niveau 2 — DOM rendu** : le HTML apres execution du JavaScript (grille categories, cartes produit), capture via Playwright contre la borne en ligne avec des donnees reelles. C'est ce que verrait un jury en validant la page rendue.

Depuis le passage de l'ecran categories sur `GET /api/categories`, cet ecran releve du niveau 2 comme l'ecran produits : sa grille est peuplee par `page-categories.js`, la page servie ne contient plus aucune carte.

---

## 2. Resultat

**Campagne du 2026-09-26**, rejouee contre le code courant (la borne a recu cinq lots
depuis la campagne precedente ; les captures du DOM rendu dataient, elles, du
2026-07-31).

| Niveau | Pages | Erreurs | Avertissements |
|---|---|---|---|
| Pages servies | 5 (index, categories, products, payment, confirmation) | **0** | 0 |
| DOM rendu (donnees reelles) | 3 (accueil, categories, produits) | **0** | 1 (voir 4.3) |
| DOM rendu, modale allergenes ouverte | 1 | **0** | 1 (le meme, voir 4.3) |

Resultat **identique** a celui des campagnes precedentes : le seul message reste
l'avertissement `aria-disabled` du lien « Payer » (section 3.3), assume. Les cinq lots
livres sur la borne depuis n'ont introduit ni erreur ni avertissement nouveau.

### Une commande, les deux niveaux

```bash
# Niveau 1 sur les fichiers servis, puis pile jetable + capture du DOM rendu +
# validation des captures. Depose les artefacts dans w3c/, puis demonte tout.
tests/e2e/run-w3c.sh
```

Ce script est nouveau (2026-09-26). Avant lui, le niveau 2 etait capture **a la main** :
les fichiers de `w3c/dom-rendu/` ne pouvaient donc pas etre refaits a l'identique, et ils
ont derive du code — la reserve datee ci-dessous en portait la trace. La capture vit
desormais dans `tests/e2e/w3c-capture.spec.js`, qui n'ecrit que si la variable `W3C_OUT`
est posee (meme garde que `A11Y_OUT` pour l'audit d'accessibilite) : un passage ordinaire
des tests de bout en bout ne reecrit pas le dossier de preuves.

Sorties brutes du validateur versionnees comme artefacts :

- `w3c/borne-statique.json` — `{"messages":[]}` (aucun message sur les 5 pages servies).
- `w3c/borne-rendu.json` — 0 erreur, 1 avertissement sur `produits`.
- `w3c/dom-rendu/` — le HTML rendu (accueil, categories, produits) reellement soumis au validateur.
- `w3c/borne-modale-allergenes.json` — 0 erreur sur le DOM **modale allergenes ouverte**
  (capture `w3c/dom-rendu/produits-modale-allergenes.html`).

Les quatre captures et les trois sorties de validateur portent la campagne du
2026-09-26 ; les versions precedentes restent consultables dans l'historique git.

**Ajout du 2026-07-31 — la modale allergenes, du DOM genere resté hors validation.**
Les captures precedentes figeaient l'etat FERME de la page produits : elles contenaient les
boutons "i" mais pas le panneau que le clic construit. Le balisage de la modale echappait donc
au validateur, alors qu'il est entierement genere en JavaScript (F11b l'a de plus reecrit :
liste par produit, bandeaux d'etat, avertissement de traces). Capture faite avec une modale
ouverte, puis validee : **le seul message est l'avertissement `aria-disabled` deja
present sur le lien Payer du panneau de commande**, identique a celui de `borne-rendu.json`.
Le nouveau balisage n'introduit aucun message. La capture du 2026-09-26 ouvre la modale du
premier produit de la categorie 2 (« Allergenes - Coca Cola ») ; celle du 2026-07-31 ouvrait
celle d'un burger. Le balisage valide est le meme, seule la liste d'allergenes differe.

```bash
# Capture + validation en une commande (voir section 2). La validation seule, si les
# captures sont deja sur disque :
docker run --rm -v "$PWD/docs/soutenance/preuves/w3c/dom-rendu":/data:ro --entrypoint java \
  ghcr.io/validator/validator:latest -jar /vnu.jar --format json \
  /data/produits-modale-allergenes.html
# -> 1 message : info/warning aria-disabled (pre-existant, panneau de commande)
```

**Niveau 1 rejoue le 2026-09-23 apres le lot F33** (titres et descriptions, donnees schema.org sur les 5 pages, banniere en `<picture>` avec `fetchpriority`, attributs `title` des liens) : meme commande, meme moteur (26.6.24), resultat `{"messages":[]}`, 0 erreur et 0 avertissement.

**Niveau 1 rejoue apres le passage de l'ecran categories en dynamique** : la commande ci-dessous a ete relancee sur le nouveau balisage, resultat identique (`{"messages":[]}`), artefact inchange au bit pres.

**Reserve du niveau 2 — levee le 2026-09-26.** Elle disait ceci : « `w3c/dom-rendu/categories.html` est la capture d'AVANT le passage en dynamique (elle contient encore la grille servie en dur) ; la recapture est faite en une seule campagne a la fin des lots qui touchent au balisage de la borne, et apres nettoyage des donnees de demonstration. » Les deux conditions sont remplies. Les lots borne sont livres ; et la crainte des categories de test tombe d'elle-meme, parce que la capture est prise contre une **pile jetable montee depuis les fichiers de donnees de demonstration** (`db/seeds/`) : elle porte les 9 categories du catalogue (Menus, Boissons, Burgers, Frites, Encas, Wraps, Salades, Desserts, Sauces) et aucune categorie creee a la main pendant des essais. Les quatre captures ont ete refaites par `tests/e2e/run-w3c.sh` ; `categories.html` porte desormais la grille reellement construite par `page-categories.js` depuis `GET /api/categories`. Deux consequences a assumer devant le jury :

- Les captures viennent de la **pile de test jetable**, pas de la borne en ligne. Le lien canonique reste celui du domaine de production, parce qu'il est ecrit en dur dans les pages servies ; le reste du document est celui de la pile de test.
- L'artefact ne peut plus deriver en silence : la capture est un fichier de test versionne, rejouable en une commande.

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
2. **Etats interactifs non exhaustivement rendus.** Le DOM rendu valide couvre l'accueil, la liste categories, la grille produits peuplee et la **modale allergenes ouverte**. Les deux autres modales (composeur de menu, options produit) n'ont pas ete soumises au validateur ; leur structure a toutefois ete relue en statique (le composeur `page-product-menu.js` et le panneau commande `order-panel.js` utilisent des `<ul>` a enfants `<li>` conformes) et la modale d'options est, elle, mesuree par l'audit d'accessibilite (`06-audit-accessibilite-mesure.md`, ecran `produits-modale-options`). Ajouter ces deux etats a `tests/e2e/w3c-capture.spec.js` est desormais une modification d'une dizaine de lignes.
3. **Le validateur en ligne reste a rejouer a l'oral.** La preuve locale utilise le meme moteur ; montrer `validator.w3.org` en direct sur l'URL de la borne renforce la demonstration.

---

## 6. Points de defense a l'oral

1. **Rejouer la commande** (section 2) devant le jury : resultat `{"messages":[]}` sur les 5 pages, reproductible.
2. **Raconter le diagnostic** (section 3) : les erreurs trouvees puis corrigees montrent la maitrise du critere, mieux qu'un vert obtenu sans histoire. Insister sur le badge de mode : la correction W3C a aussi corrige un defaut d'accessibilite.
3. **Distinguer servi vs rendu** : savoir expliquer pourquoi une page peut etre valide « a la source » mais generer un DOM invalide cote client, et comment on valide le rendu (capture Playwright puis validateur).
4. **Assumer l'avertissement** du lien Payer desactive comme un compromis d'accessibilite documente.
