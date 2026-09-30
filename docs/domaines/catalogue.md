# Domaine — Catalogue (categories, produits, menus)

## Perimetre
CRUD des categories, produits et menus composes (borne de base + slots). Base du
catalogue consomme par la borne.

## Ce qui est livre
- Repositories : `CategoryRepository`, `ProductRepository`, `MenuRepository`.
- Controleurs : `CategoryController` (`category.manage`), `ProductController`
  (`product.read/create/update/delete`), `MenuController` (`menu.read/create/update/delete`).
- Menus composes : burger de base + `menu_slot` / `menu_slot_option`, editeur slots en
  JS vanilla CSP-safe (champ cache `slots_json`). Mise a jour = reconciliation en place
  en transaction (appariement par position au sein du meme `slot_type`, corrige le
  2026-09-29 : l'ancien delete-and-reinsert levait une erreur serveur 500 sur un menu
  deja commande, FK `order_item_selection.menu_slot_id` RESTRICT) ; `409` si un
  emplacement retire est deja reference par une commande. **Corrige le 2026-09-29**
  (commit `fce3085`, branche `fix/sec-order`, fusionnee par `86306ef` — ce meme
  comportement est repris sur `main` par le commit `9e22a21`) : `GET /api/menus/{id}` (`CatalogueController`)
  expose desormais, par option de slot, `option_is_orderable` (meme regle de
  disponibilite RG-T21 que le burger du menu) et `option_names` — avant ce correctif,
  une option retiree ou en rupture restait proposee sans indication, seule
  l'appartenance au slot etait exposee.
- Deux LECTURES du catalogue cote back-office, meme permission `product.read` :
  la liste plate (`/admin/products`, seule a montrer et gerer les variantes de taille ligne
  par ligne, plus le CRUD) et la vue groupee par categorie (`/admin/products/by-category`,
  `ProductController::byCategory` + `ProductRepository::basesByCategory`) qui range le
  catalogue dans l'ordre des onglets de la borne, replie les variantes sur leur base et
  inclut les menus. Etats et compteurs resolus cote serveur, vue declarative.

## Regles metier
- RG-T16 (allowlist colonnes), RG-T18 (validation serveur bornee : prix > 0, TVA dans
  {55,100}, etc.), RG-T15 (sorties echappees).
- Produit : PIN equipier + audit UNIQUEMENT si prix ou TVA change (mlt 8.2 RG-4) ;
  suppression = PIN + audit (8.3). Menu : suppression = PIN + audit (8.6).
- Pas de suppression dure si reference (FK RESTRICT depuis order_item / menu / selection)
  -> 409, alternative = desactivation (`is_available`).

## Decisions
[ADR-0002](../adr/0002-back-office-mvc-rendu-serveur.md) (MVC serveur),
[ADR-0006](../adr/0006-http-409-conflit-422-validation.md) (409/422),
[ADR-0004](../adr/0004-pin-action-sensible-audit.md) (PIN + audit),
[ADR-0013](../adr/0013-vue-produits-groupee-par-categorie.md) (vue groupee par categorie).

## Tables
`category`, `product`, `menu`, `menu_slot`, `menu_slot_option`. Detail :
`docs/merise/mlt.md` section 8.
