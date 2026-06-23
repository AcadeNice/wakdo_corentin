# ADR-0006 — HTTP 409 (conflit) vs 422 (validation)

- Statut : Accepte
- Date : 2026-06-17

## Contexte
Les controleurs renvoyaient 422 a la fois pour une validation qui echoue ET pour un
conflit d'etat (unicite, suppression bloquee par FK RESTRICT). Le contrat documente
(`byan-api.md`) attendait 409 pour les conflits. Derive a corriger.

## Decision
Convention harmonisee sur tous les controleurs :
- **422** : requete bien formee mais **semantiquement invalide** (validation serveur,
  RG-T18) ;
- **409** : **conflit d'etat** (violation d'unicite SQLSTATE 23000, hard-delete bloque
  par FK RESTRICT) ;
- **403** : CSRF invalide ou permission manquante ; **404** : ressource introuvable.

## Consequences
- (+) Statuts semantiquement justes (RFC 9110), testables, coherents entre Category /
  Product / Menu / Ingredient / User / Role.
- (+) Aligne le code sur le contrat d'API documente.
- (-) Pages rendues serveur : un 200-avec-erreurs "marcherait" visuellement, mais le
  statut correct est verrouille par les tests (un oubli = test rouge).
- Remediation : PR #33 (Category/Product/Menu) ; les controleurs suivants naissent en 409.
