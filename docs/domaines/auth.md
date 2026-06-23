# Domaine — Authentification & sessions

## Perimetre
Connexion back-office, deconnexion, reinitialisation de mot de passe, garde de session,
PIN d'action sensible. Pas d'auth cote borne (front public).

## Ce qui est livre
- `App\Auth\AuthService` (login 12.1 / logout 12.2), `PasswordResetService` (12.3).
- `SessionManager` (seul a toucher `$_SESSION`/cookie, mode test memoire), `SessionGuard`
  (RG-6/RG-T02 : idle 4h, absolu 10h, `is_active`), `Csrf` (jeton synchroniseur).
- `PasswordHasher` (argon2id + leurre de timing), `PinVerifier`, `PinThrottle`,
  `ThrottlePolicy` (backoff degressif).
- Controleurs `AuthController`, `PasswordResetController`, `ProfileController` (set-PIN
  self-service), `MeController` (`/api/me`).

## Regles metier
- RG-6 / RG-T02 : session valide (idle + absolu + compte actif) sinon 302 `/login`.
- RG-8 / RG-9 : throttle login par compte + par IP (`login_throttle`), backoff degressif.
- RG-T13 : PIN d'action sensible (voir [users](users.md), [rbac](rbac.md), stock).
- Anti-enumeration : reponses neutres (reset, login) ; leurre de timing argon2id.

## Decisions
[ADR-0001](../adr/0001-php-from-scratch-sans-composer.md) (from scratch),
[ADR-0004](../adr/0004-pin-action-sensible-audit.md) (PIN),
[ADR-0005](../adr/0005-throttle-pin-separe-du-login.md) (throttle PIN).

## Tables
`user`, `login_throttle`, `pin_throttle`, `audit_log` (login + pin.failed). Detail :
`docs/merise/mlt.md` section 12 + 22.
