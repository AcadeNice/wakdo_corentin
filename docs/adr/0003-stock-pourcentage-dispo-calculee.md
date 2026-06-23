# ADR-0003 — Stock en pourcentage + disponibilite produit calculee (RG-T21)

- Statut : Accepte
- Date : 2026-06-12

## Contexte
Modeliser le stock des ingredients et la commandabilite des produits. Un stock en
quantites absolues seules rend les seuils d'alerte arbitraires d'un ingredient a
l'autre ; et marquer la disponibilite produit "en dur" exige une cascade a maintenir
a chaque mouvement de stock.

## Decision
Stock ancre sur une **`stock_capacity`** (reference 100%, `CHECK > 0`) ; `stock_pct` et
les 3 bandes (normal / alerte / critique) sont **calcules**, pas stockes. La
**disponibilite produit (RG-T21)** est derivee : commandable si `is_available = 1` ET
chaque ingredient non retirable est au-dessus de la bande critique. Aucune colonne
stockee, aucune cascade.

## Consequences
- (+) Seuils homogenes (en %) ; un reappro au-dessus du critique rend le produit
  commandable de lui-meme, sans ecriture.
- (+) `stock_quantity` signe (survente assumee, remontee manager) : le systeme ne bloque
  pas une commande sur une lecture de stock.
- (-) Le calcul de dispo se fait a la lecture (jointure composition) ; borne par requete.
- Source unique de la derivation : `IngredientRepository::stockBand`. Voir `docs/merise/`.
