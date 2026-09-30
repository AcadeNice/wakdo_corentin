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
- Self-protection : pas d'auto-desactivation ni d'auto-effacement (403 — HTML : page de
  confirmation avec le message « Vous ne pouvez pas desactiver/anonymiser votre propre
  compte. » ; API JSON : `403 FORBIDDEN`, meme message, aucun code dedie du type
  `SELF_DEACTIVATION`) ; on ne retire pas le statut du **dernier admin actif**
  (update/deactivate/erase) ; effacement deja fait -> 409 ; pas de changement de son propre
  `role_id` depuis l'ecran d'edition.
- Elevation de privilege (corrige le 2026-09-30, revue adversariale) : creer ou modifier un
  compte, lui affecter un role, ou agir sur un compte dont le role courant deborde celui de
  l'acteur (desactivation, reinitialisation de PIN, anonymisation) exige que l'acteur
  detienne lui-meme toutes les permissions de ce role, sinon `role.manage`
  (`UserController::roleExceedsActorPermissions`) — avant ce correctif, seul
  `activeRoleExists()` etait verifie, ce qui permettait a un role personnalise dote de
  `user.update` de s'affecter le role `admin`. Aucun role du jeu de demonstration n'est
  concerne (seul `admin` porte `user.*` au seed). La garde compare aussi, depuis une 2e
  revue adversariale le meme jour, la portee des sources de commande visibles
  (`role_visible_source`) : un role limite a un canal ne peut plus affecter ni garder un
  role qui voit davantage de canaux, meme a permissions identiques par ailleurs
  (`c5a8fc4`).

## Decisions
[ADR-0004](../adr/0004-pin-action-sensible-audit.md) (PIN + audit),
[ADR-0007](../adr/0007-rgpd-anonymisation-tombstone.md) (anonymisation RGPD),
[ADR-0006](../adr/0006-http-409-conflit-422-validation.md) (409/422).

## Tables
`user` (+ `anonymized_at` pour RGPD), `audit_log`, `role` (FK). Detail :
`docs/merise/mlt.md` section 10.1-10.5.
