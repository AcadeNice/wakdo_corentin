# Domaine — RBAC (roles & permissions)

## Perimetre
Gestion des roles et de la matrice role/permission (mlt 10.4 MANAGE_RBAC), permission
`role.manage`. Catalogue de permissions fige au seed (lecture seule).

## Ce qui est livre
- `RoleRepository` (App\Auth) : roles (CRUD, code immuable), permissions (lecture),
  matrice (`permissionIdsFor`/`permissionCodesFor`, `setPermissions` tx +
  `replacePermissions` raw), `role_visible_source` (`setVisibleSources` / raw).
- `RoleController` (`role.manage`) : index, create/store (role custom RG-4), edit/update
  (champs role + matrice + sources visibles en UNE transaction). Vues `admin/roles/{index,form}`.
- Matrice soumise en champs **scalaires** (`perm_<id>`, `source_<enum>`) : `Request::formBody`
  ne garde que les scalaires (pas de `name[]`, pas de JS).

## Regles metier
- RG-6 (mlt 10.4) : PIN equipier + `audit_log` (`role.manage`) dans une transaction ;
  `details` JSON = **diff** des codes de permission (ajoutes/retires), calcule avant la
  reecriture delete-and-reinsert.
- `Authorizer::can` recharge les permissions a chaque verification (effet immediat).
- Garde-fous anti-lockout : le role `admin` conserve `role.manage` ET reste actif ;
  `code` immuable apres creation ; `order_source` borne a l'ENUM ; code dupli -> 409.
- `default_route` (page d'accueil apres connexion) : n'accepte qu'un chemin local (un seul
  `/` en tete, pas de schema type `mot:`) — corrige le 2026-09-30 (revue adversariale,
  `App\Auth\RedirectPath::isLocal`) ; avant ce correctif, seule la longueur (120) etait
  verifiee, une valeur `https://...`/`//...` aurait pu rediriger tous les comptes du role
  hors du site apres connexion.
- Elevation de privilege (D-4, corrige le 2026-09-30) : un acteur sans `role.manage` ne peut
  affecter un role, ni agir sur un compte dont le role courant deborde le sien, QUE si les
  permissions du role vise sont toutes incluses dans les siennes (`array_diff`) — sinon
  `403`. Verifie avant la demande de PIN, y compris si le role vise est desactive. `role.manage`
  reste le court-circuit total : il autorise de toute facon a editer n'importe quel role.

## Decisions
[ADR-0004](../adr/0004-pin-action-sensible-audit.md) (PIN + audit),
[ADR-0006](../adr/0006-http-409-conflit-422-validation.md) (409).

## Tables
`role`, `permission`, `role_permission`, `role_visible_source`, `audit_log`. Detail :
`docs/merise/mlt.md` section 10.4.
