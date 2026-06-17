# Domaine — Catalogue (categories, produits, menus)

## Perimetre
CRUD des categories, produits et menus composes (borne de base + slots). Base du
catalogue consomme par la borne.

## Ce qui est livre
- Repositories : `CategoryRepository`, `ProductRepository`, `MenuRepository`.
- Controleurs : `CategoryController` (`category.manage`), `ProductController`
  (`product.read/create/update/delete`), `MenuController` (`menu.read/create/update/delete`).
- Menus composes : burger de base + `menu_slot` / `menu_slot_option`, editeur slots en
  JS vanilla CSP-safe (champ cache `slots_json`), reecriture delete-and-reinsert en tx.

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
[ADR-0004](../adr/0004-pin-action-sensible-audit.md) (PIN + audit).

## Tables
`category`, `product`, `menu`, `menu_slot`, `menu_slot_option`. Detail :
`docs/merise/mlt.md` section 8.
