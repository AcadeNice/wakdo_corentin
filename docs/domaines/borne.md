# Domaine — Borne (kiosk)

## Perimetre
Front client tactile (Bloc 1) : parcours welcome -> categories -> produit -> panier ->
confirmation. HTML/CSS/JS vanilla, servi en statique par Apache.

## Ce qui est livre
- Pages : `index`, `categories`, `products`, `product`, `cart`, `payment`,
  `confirmation` (`src/public/borne/`).
- JS modules ES6 (`assets/js/`) : `data.js` (chargement + conversion vers la forme
  borne), `state.js` (panier), `page-*.js`, `nav.js`, et `allergens.js` (modale
  allergenes PAR PRODUIT sur carte et fiche).
- Donnees : lues depuis l'API DB-backed (`/api/categories`, `/products`, `/menus`,
  `/allergens`). La bascule depuis les JSON statiques de P5 est faite ; `data.js` reste
  le point unique de conversion vers la forme heritee de la borne.

## Regles metier / conventions
- Allergenes : **calcules par produit** depuis la recette (`product_ingredient` ->
  `ingredient_allergen` -> `allergen`), servis par `/api/products` et `/api/menus` dans
  les champs `allergens` + `allergens_complete`. Trois etats a l'ecran, qu'il ne faut
  pas confondre : revu avec allergenes (on les nomme), revu sans allergene (on affirme
  l'absence), non revu (on renvoie vers l'equipe). Un avertissement de traces accompagne
  les trois. Detail : [ADR-0015](../adr/0015-allergenes-calcules-par-produit.md).
  Sur un menu, la liste est celle du **burger impose** (meme granularite que
  `is_orderable`) ; l'accompagnement et la boisson portent les leurs.
- `/api/allergens` (les 14 categories INCO) reste utilise, pour les **descriptions**
  reglementaires affichees a cote de chaque allergene du produit.
- CSP-safe pour le code projet : pas de script inline ajoute (donnees via `data-*`,
  `addEventListener`). La modale construit son DOM par `createElement`/`textContent`,
  ce qui neutralise aussi toute injection.
- **Session de paiement (F18)** : la cle d'idempotence (`sessionStorage`) survit aux
  allers-retours entre le panier et l'ecran de paiement. Une session = UNE commande, dont
  le contenu suit le panier (le serveur la met a jour au lieu d'en creer une seconde).
  Liberee au succes, et sur `ORDER_CANCELLED` — cas ou la commande a ete annulee ou
  expiree pendant que le client hesitait : `checkout.js` reprend alors **une seule fois**
  avec une cle neuve. Toute autre erreur remonte au client sans reprise, pour qu'il voie
  le vrai probleme (article indisponible, par exemple).
  Detail : [ADR-0016](../adr/0016-modification-commande-avant-paiement.md).

## Tests
Harnais front `node:test` + jsdom : `tests/js/allergens.test.js` (les trois etats,
bouton "i", descriptions reprises de la reference, fermeture bouton/overlay/Echap,
idempotence) et `tests/js/data.test.js` (conversion des champs allergenes, avec un
defaut PRUDENT : une API muette vaut "non revu", pas "revu et vide"). Job CI `js-tests`
(Node 20).

## Decisions
Swap point P5 -> API au P4 (cf. `data.js` + journaux). Modele = app self-hostable
([ADR-0009](../adr/0009-compose-standalone-et-prod-gitignore.md)).

## Tables (lecture)
`category`, `product`, `menu`, `allergen`, plus `product_ingredient` et
`ingredient_allergen` pour le calcul des allergenes et `ingredient` pour l'etat de revue
(`allergens_reviewed_at`). Lecture seule : le double de test du controleur catalogue
leve une exception sur toute ecriture, ce qui garde le contrat.
