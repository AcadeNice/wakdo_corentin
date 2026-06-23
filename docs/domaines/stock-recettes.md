# Domaine — Stock & recettes (ingredients)

## Perimetre
Gestion des ingredients, du stock (reappro + inventaire), des mouvements de stock, et de
la composition des produits (recettes). Sous-tend la disponibilite produit calculee.

## Ce qui est livre
- `IngredientRepository` : CRUD, stock %/bande calcules, `restock` (tx), `inventoryCount`
  (tx, ecrit une ligne meme a delta=0, RG-3), `movements` (borne), `isReferenced`.
- `IngredientController` : CRUD (`ingredient.manage`, sans PIN), RESTOCK (`stock.manage`,
  sans PIN), INVENTORY_COUNT (`stock.count` + PIN), mouvements (`stock.read`).
- `ProductRepository` : composition (`product_ingredient`), `setComposition`
  (delete-and-reinsert tx), `isOrderable` (RG-T21), `autoUnavailableIds`.
- Editeur de recette (`ProductController::recipeForm/saveRecipe`, `ingredient.manage`).

## Regles metier
- RG-T13 : INVENTORY_COUNT seule action sensible du stock (PIN equipier) ; succes ->
  `stock_movement.user_id`, **sans** `audit_log` (RG-T14 : le mouvement EST la trace).
  RESTOCK et CRUD ingredient ne sont PAS sensibles.
- RG-T22 : echec PIN inventaire -> `pin.failed` + throttle dans une transaction.
- RG-T21 : disponibilite produit calculee (cf. [ADR-0003](../adr/0003-stock-pourcentage-dispo-calculee.md)).
- FK : `product_ingredient`/`stock_movement` RESTRICT sur l'ingredient (hard-delete -> 409) ;
  `product_ingredient.product_id` CASCADE (trace du nombre de lignes a la suppression, dette #27).

## Decisions
[ADR-0003](../adr/0003-stock-pourcentage-dispo-calculee.md) (stock % + RG-T21),
[ADR-0004](../adr/0004-pin-action-sensible-audit.md) / RG-T14 (attribution sans double-journal).

## Tables
`ingredient`, `product_ingredient`, `stock_movement`, `allergen`, `ingredient_allergen`
(mapping differe). Detail : `docs/merise/mlt.md` sections 8.8 + 9.
