# Domaine — Stock & recettes (ingredients)

## Perimetre
Gestion des ingredients, du stock (reappro + inventaire), des mouvements de stock, et de
la composition des produits (recettes). Sous-tend la disponibilite produit calculee.

## Ce qui est livre
- `IngredientRepository` : CRUD, stock %/bande calcules, `restock` (tx), `inventoryCount`
  (tx, ecrit une ligne meme a delta=0, RG-3), `adjust` (tx, correction delta signee),
  `movements` (page ET API), `isReferenced`.
- `IngredientController` : CRUD (`ingredient.manage`, sans PIN), RESTOCK
  (`POST /admin/ingredients/{id}/restock`, `stock.manage`, sans PIN), THRESHOLDS
  (`POST /admin/ingredients/{id}/thresholds`, `stock.manage`, sans PIN — reglage
  capacite/alerte/critique), INVENTORY_COUNT
  (`POST /admin/ingredients/{id}/inventory`, `stock.count` + PIN), ADJUST
  (`POST /admin/ingredients/{id}/adjust`, `stock.count` + PIN — correction libre,
  meme garde-fou que l'inventaire), mouvements (`GET /admin/ingredients/{id}/movements`,
  `stock.read`), ENRICH (`POST /admin/ingredients/{id}/enrich`, `ingredient.manage`,
  sans PIN — enrichissement nutritionnel opt-in depuis l'API externe Open Food Facts,
  `routes.php` ~255). `IngredientApiController` expose le meme CRUD et les memes actions
  de stock en JSON sous `/admin/api/ingredients/...`, dont
  `GET /admin/api/ingredients/{id}/movements` (`stock.read` ; le champ `actor` n'est
  renvoye qu'aux detenteurs de `stock.manage`, absent sinon — pas seulement vide) ; PAS
  d'equivalent JSON pour ENRICH, HTML seulement.
- `ProductRepository` : composition (`product_ingredient`), `setComposition`
  (delete-and-reinsert tx), `isOrderable` (RG-T21), `autoUnavailableIds`.
- Editeur de recette (`ProductController::recipeForm/saveRecipe`, `ingredient.manage`).

## Regles metier
- RG-T13 : INVENTORY_COUNT et ADJUST (mouvement `adjustment`) sont les actions sensibles
  du stock (PIN equipier — une baisse non attribuee masquerait de la demarque) ; succes ->
  `stock_movement.user_id`, **sans** `audit_log` (RG-T14 : le mouvement EST la trace).
  RESTOCK, THRESHOLDS et CRUD ingredient ne sont PAS sensibles (sans PIN).
- RG-T22 : echec PIN inventaire -> `pin.failed` + throttle dans une transaction.
- RG-T21 : disponibilite produit calculee (cf. [ADR-0003](../adr/0003-stock-pourcentage-dispo-calculee.md)).
- FK : `product_ingredient`/`stock_movement` RESTRICT sur l'ingredient (hard-delete -> 409) ;
  `product_ingredient.product_id` CASCADE (trace du nombre de lignes a la suppression, dette #27).

- Revue des allergenes (F11b) : l'ecran ingredient porte la matrice des 14 categories
  INCO + une **source obligatoire**. Le geste pose `allergens_reviewed_at` et
  `allergens_source`, et ecrit une ligne `audit_log` (`ingredient.allergens`) dans la
  meme transaction. Permission `ingredient.manage`, SANS PIN (ni argent ni stock, donc
  hors ensemble sensible RG-T13). Une ecriture d'allergenes ne touche NI
  `stock_quantity` NI `stock_movement` : verrouille par test unitaire et par test
  d'integration sur base reelle.
- La page Stock signale en une phrase combien d'ingredients n'ont pas de revue : sans ce
  rappel, un ingredient ajoute plus tard ferait basculer des produits en "information non
  disponible" sur la borne sans que personne le voie.

## Decisions
[ADR-0003](../adr/0003-stock-pourcentage-dispo-calculee.md) (stock % + RG-T21),
[ADR-0004](../adr/0004-pin-action-sensible-audit.md) / RG-T14 (attribution sans double-journal),
[ADR-0015](../adr/0015-allergenes-calcules-par-produit.md) (allergenes calcules + etat de revue).

## Tables
`ingredient` (dont `allergens_reviewed_at` / `allergens_source`, migration 0011),
`product_ingredient`, `stock_movement`, `allergen`, `ingredient_allergen` (peuplee par le
seed 0008 a partir de sources publiques). Detail : `docs/merise/mlt.md` sections 8.8 + 9.
