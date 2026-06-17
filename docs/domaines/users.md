# Domaine — Comptes utilisateurs

## Perimetre
Gestion des comptes back-office (mlt domaine 10.1-10.3 + 10.5) : creation, edition,
desactivation, reinitialisation de PIN, effacement RGPD.

## Ce qui est livre
- `UserRepository` (App\Auth) : all (JOIN role) / find / emailExists / activeRoleExists /
  create / update (allowlist) / setPasswordHash / clearPin / deactivate / anonymise /
  activeAdminCount / isAdmin.
- `UserController` : index (`user.read`), create/store (`user.create`), edit/update
  (`user.update`), deactivate (`user.deactivate`), reset-pin, erase-PII. Vues
  `admin/users/{index,form,confirm}`.

## Regles metier
- RG-T13/14 : **toutes** les mutations sont sensibles -> PIN equipier + `audit_log`
  (`user.create/update/deactivate/erase_pii`) dans la meme transaction ; `details` JSON =
  noms de champs / role (pas de PII). Throttle RG-T22.
- RG-T16 : allowlist (email/prenom/nom/role_id/is_active) ; `is_active` pose serveur a
  la creation. Unicite email -> 409.
- Self-protection : pas d'auto-desactivation (403 SELF_DEACTIVATION) ; on ne retire pas
  le statut du **dernier admin actif** (update/deactivate/erase) ; effacement deja fait -> 409.

## Decisions
[ADR-0004](../adr/0004-pin-action-sensible-audit.md) (PIN + audit),
[ADR-0007](../adr/0007-rgpd-anonymisation-tombstone.md) (anonymisation RGPD),
[ADR-0006](../adr/0006-http-409-conflit-422-validation.md) (409/422).

## Tables
`user` (+ `anonymized_at` pour RGPD), `audit_log`, `role` (FK). Detail :
`docs/merise/mlt.md` section 10.1-10.5.
