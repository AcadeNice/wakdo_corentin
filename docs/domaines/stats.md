# Domaine — Statistiques

## Perimetre
Tableau de bord de pilotage (mlt domaine 11), permission `stats.read`. Landing par
defaut du role manager.

## Ce qui est livre
- `StatsRepository` : `counts()` (compteurs catalogue : produits/menus/categories/
  ingredients, total + actifs/disponibles), `stockHealth()` (repartition des ingredients
  actifs par bande RG-T21 + liste d'alerte triee du plus critique).
- `StatsController` (`stats.read`) -> `/admin/stats` + vue `admin/stats/index` (cartes
  KPI + table d'alerte stock) + lien nav "Pilotage".

## Regles metier / perimetre
- KPIs sur les **donnees disponibles** en P3 : sante catalogue + stock. **Ferme le 404**
  du landing manager (`role.default_route = /admin/stats`).
- KPIs de **vente** (CA, volumes, `service_day`) = **P4** : ils dependent du domaine
  commande (encore en schema seul).
- Sante stock = reutilise `IngredientRepository::stockBand` (source unique RG-T21).

## Decisions
[ADR-0003](../adr/0003-stock-pourcentage-dispo-calculee.md) (bandes RG-T21).

## Tables
Lecture seule : `product`, `menu`, `category`, `ingredient` (compteurs + bandes).
KPIs vente (P4) : `customer_order`, `order_item`. Detail : `docs/merise/mlt.md` section 11.
