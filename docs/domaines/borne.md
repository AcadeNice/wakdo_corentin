# Domaine — Borne (kiosk)

## Perimetre
Front client tactile (Bloc 1) : parcours welcome -> categories -> produit -> panier ->
confirmation. HTML/CSS/JS vanilla, servi en statique par Apache.

## Ce qui est livre
- Pages : `index`, `categories`, `products`, `product`, `cart`, `payment`,
  `confirmation` (`src/public/borne/`).
- JS modules ES6 (`assets/js/`) : `data.js` (chargement, point de swap P4), `state.js`
  (panier), `page-*.js`, `nav.js`, et `allergens.js` (modale generale 14 INCO sur carte
  et fiche).
- Donnees : JSON statiques (`data/`) en P5 ; basculent sur `/api/*` DB-backed au swap P4.

## Regles metier / conventions
- Allergenes : info **generale** (les 14 INCO, reglement UE 1169/2011), pas un calcul
  par produit (mapping `ingredient_allergen` differe).
- CSP-safe pour le code projet : pas de script inline ajoute (donnees via `data-*`,
  `addEventListener`). Source allergenes = liste fixe `data/allergens.json`, se branchera
  sur `/api/allergens` au swap P4.

## Tests
Harnais front `node:test` + jsdom (`tests/js/allergens.test.js`) : 14 INCO, bouton "i",
ouverture/fermeture (bouton/overlay/Echap), idempotence. Job CI `js-tests` (Node 20).

## Decisions
Swap point P5 -> API au P4 (cf. `data.js` + journaux). Modele = app self-hostable
([ADR-0009](../adr/0009-compose-standalone-et-prod-gitignore.md)).

## Tables (au swap P4)
`category`, `product`, `menu` + `allergen` (lecture). Aujourd'hui : JSON statiques.
