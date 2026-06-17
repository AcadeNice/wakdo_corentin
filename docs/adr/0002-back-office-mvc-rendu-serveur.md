# ADR-0002 — Back-office en MVC rendu serveur (pas de SPA)

- Statut : Accepte
- Date : 2026-06-15

## Contexte
Le back-office (login, CRUD catalogue, stock, users, RBAC, stats) doit etre construit.
Options : SPA JS consommant une API JSON ; pages rendues serveur (MVC PHP) ; hybride.
La borne client, elle, est deja un front statique distinct (Bloc 1).

## Decision
Le back-office est en **MVC rendu serveur** : formulaires POST + redirections, vues PHP
injectees dans un layout commun. L'API REST (`/api/*`) reste interne, consommee par la
borne. Login = vue PHP, pas un endpoint JSON.

## Consequences
- (+) CSRF, sessions, garde de permission et echappement de sortie se branchent
  naturellement sur chaque page ; demontre le MVC sans build front.
- (+) Pas de duplication d'etat client/serveur pour l'admin.
- (-) Interactions riches (matrice RBAC, editeur recette) gerees en JS vanilla cible,
  CSP-safe (champs caches / cases scalaires), sans framework front.
- Controleurs non-`final` (seam de test) ; vues sous `src/app/Views/admin`.
