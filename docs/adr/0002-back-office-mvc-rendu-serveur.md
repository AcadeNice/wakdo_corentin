# ADR-0002 — Back-office en MVC rendu serveur (pas de SPA)

- Statut : Accepte, complete le 2026-09-25 par [ADR-0017](0017-api-admin-json.md)
- Date : 2026-06-15

> **Complement (ADR-0017).** Cette fiche fixait un back-office SANS API JSON exposee :
> « L'API REST (`/api/*`) reste interne, consommee par la borne. Login = vue PHP, pas un
> endpoint JSON. » ADR-0017 a ajoute, en PARALLELE du MVC rendu serveur ici decide (sans
> le remplacer), une API JSON d'administration complete sous `/admin/api/...` (57 routes),
> dont une connexion JSON (`POST /admin/api/auth/login`, addendum du 2026-09-26). Le MVC
> reste la surface principale du back-office ; l'API JSON sert le jury et d'eventuels
> clients HTTP additionnels.

## Contexte
Le back-office (login, CRUD catalogue, stock, users, RBAC, stats) doit etre construit.
Options : SPA JS consommant une API JSON ; pages rendues serveur (MVC PHP) ; hybride.
La borne client, elle, est deja un front statique distinct (Bloc 1).

## Decision
Le back-office est en **MVC rendu serveur** : formulaires POST + redirections, vues PHP
injectees dans un layout commun. L'API REST (`/api/*`) reste interne, consommee par la
borne. Login = vue PHP, pas un endpoint JSON (l'ajout d'une API JSON d'administration
sous `/admin/api/...`, distincte de `/api/*`, est une decision posterieure : voir
[ADR-0017](0017-api-admin-json.md)).

## Consequences
- (+) CSRF, sessions, garde de permission et echappement de sortie se branchent
  naturellement sur chaque page ; demontre le MVC sans build front.
- (+) Pas de duplication d'etat client/serveur pour l'admin.
- (-) Interactions riches (matrice RBAC, editeur recette) gerees en JS vanilla cible,
  CSP-safe (champs caches / cases scalaires), sans framework front.
- Controleurs non-`final` (seam de test) ; vues sous `src/app/Views/admin`.
