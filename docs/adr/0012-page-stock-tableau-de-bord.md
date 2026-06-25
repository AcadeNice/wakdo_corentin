# ADR-0012 — Page Stock en tableau de bord (alertes + reapprovisionnement en avant)

- Statut : Accepte
- Date : 2026-06-25

## Contexte
La page d'accueil Ingredients/Stock (domaine 8.8 + 9) etait une liste-CRUD exhaustive :
tous les ingredients, avec les actions creer/modifier/supprimer en premier plan. Elle
etait jugee trop chargee et opaque pour un equipier non-technique. Le besoin metier
quotidien est de voir vite ce qui manque et de reapprovisionner ; l'edition de fiches est
rare. De plus, le lien entre stock et disponibilite borne (un ingredient requis sous le
seuil critique rend indisponibles les produits qui l'utilisent, RG-T21, cf. ADR-0003)
n'etait pas visible a l'ecran. Options : garder la liste exhaustive triable ; basculer
vers un tableau de bord oriente action ; deux pages distinctes (dashboard + gestion).

## Decision
La page Stock devient un **tableau de bord oriente action** (#105) : un bandeau explicite
le lien stock -> disponibilite borne (RG-T21) ; un resume compte les ingredients
critiques / en alerte / au-dessus du seuil ; une section "A reapprovisionner" met en avant
les ingredients bas (critiques d'abord) avec barre de niveau et bouton de
reapprovisionnement direct. La liste complete passe au second plan et le CRUD est relegue.
Les compteurs par etat sont **calcules cote serveur** dans `IngredientController::index()`
a partir du `stock_band` deja resolu par le depot, pour garder la vue declarative et la
valeur directement testable. Les sous-pages (reappro, inventaire, mouvements, creation)
restent inchangees.

## Consequences
- (+) L'ecran s'aligne sur le geste quotidien (reperer le manque, reapprovisionner) plutot
  que sur l'edition de fiches.
- (+) Le lien stock -> disponibilite borne (RG-T21) devient explicite a l'ecran, pas
  seulement dans le code.
- (+) Compteurs testables (la logique de comptage est cote serveur, pas dans la vue) :
  +4 cas de test `IngredientController` (bandeau, promotion d'un critique en section
  reappro, etat vide positif, compteurs par etat).
- (-) La liste exhaustive et le CRUD sont moins immediats (un cran plus loin) : choix
  assume, ces operations etant moins frequentes que la lecture d'alerte.
- (-) Le tri/filtre avance de l'ancienne liste n'est pas reconduit en premier plan.
- Coherent avec ADR-0002 (MVC rendu serveur) et ADR-0003 (disponibilite calculee depuis
  le stock). Fichiers : `src/app/Controllers/IngredientController.php`,
  `src/app/Views/admin/ingredients/index.php`, `src/public/admin/assets/css/admin.css`.
  Detail : journal `docs/journal/2026-06-25--audit-remediation-et-features-94-105.md`.
