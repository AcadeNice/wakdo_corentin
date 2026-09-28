# Domaine — Statistiques

## Perimetre
Tableau de bord de pilotage (mlt domaine 11), permission `stats.read`. Landing par
defaut du role manager.

## Ce qui est livre
- `StatsRepository` (App\Catalogue) : `counts()` (compteurs catalogue : produits/menus/
  categories/ingredients, total + actifs/disponibles), `stockHealth()` (repartition des
  ingredients actifs par bande RG-T21 + liste d'alerte triee du plus critique).
- `OrderQueryRepository::salesKpis()` : chiffre d'affaires **encaisse** — somme de
  `total_ttc_cents` sur les commandes aux statuts `paid`/`preparing`/`ready`/`delivered`
  (une commande `pending_payment` ou `cancelled` ne compte pas) — nombre de commandes
  encaissees, panier moyen (entier, division tronquee), le meme sous-ensemble limite au
  **jour calendaire** (`created_at >= CURDATE()`, l'horloge de la base, pas un
  `service_day` a heure fixe), le nombre total de commandes tous statuts confondus, et la
  repartition par statut (`by_status`). `OrderQueryRepository::salesByDay()` : CA et
  nombre de commandes encaissees par jour sur une fenetre glissante de 7 jours (zero-fill
  des jours sans vente), pour le mini-graphe du tableau de bord.
- `StatsController` (`stats.read`) -> `/admin/stats` + vue `admin/stats/index` (cartes
  KPI + mini-graphe 7 jours + table d'alerte stock) + lien nav "Pilotage".
  `StatsApiController` (`stats.read`) -> `GET /admin/api/stats`, meme perimetre en JSON.

## Regles metier / perimetre
- Sante catalogue + stock **et** KPIs de vente sont livres : le domaine commande n'est
  plus en schema seul (voir [commande.md](commande.md)). **Ferme le 404** du landing
  manager (`role.default_route = /admin/stats`).
- Ce qui n'est **pas** livre, a verifier avant de l'annoncer en soutenance : pas de
  `service_day` (le jour d'affaires reste le jour calendaire, pas une frontiere a 10h),
  pas de top produits/palmares des ventes, pas de taux d'annulation, pas de temps moyen
  de remise (delai `paid` -> `delivered`).
- Sante stock = reutilise `IngredientRepository::stockBand` (source unique RG-T21).

## Decisions
[ADR-0003](../adr/0003-stock-pourcentage-dispo-calculee.md) (bandes RG-T21).

## Tables
Lecture seule : `product`, `menu`, `category`, `ingredient` (compteurs + bandes),
`customer_order` (KPIs de vente, `by_status`, mini-graphe 7 jours). Detail :
`docs/merise/mlt.md` section 11.
