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

## Decisions
[ADR-0004](../adr/0004-pin-action-sensible-audit.md) (PIN + audit),
[ADR-0006](../adr/0006-http-409-conflit-422-validation.md) (409).

## Tables
`role`, `permission`, `role_permission`, `role_visible_source`, `audit_log`. Detail :
`docs/merise/mlt.md` section 10.4.
