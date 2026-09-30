# Domaine — Authentification & sessions

## Perimetre
Connexion back-office, deconnexion, reinitialisation de mot de passe, garde de session,
PIN d'action sensible. Pas d'auth cote borne (front public).

## Ce qui est livre
- `App\Auth\AuthService` (login 12.1 / logout 12.2), `PasswordResetService` (12.3),
  `PasswordResetThrottle` (throttle de la demande de reinitialisation par adresse et par
  IP, ajoute le 2026-09-29, commit `ef7fd37`).
- `SessionManager` (seul a toucher `$_SESSION`/cookie, mode test memoire), `SessionGuard`
  (RG-6/RG-T02 : idle 4h, absolu 10h, `is_active`, `role_id` et `session_epoch` relus en
  base a CHAQUE requete depuis le 2026-09-29 — avant cette date, seul `is_active` l'etait,
  `role_id` restant celui de la connexion jusqu'a une reconnexion), `SessionRoutePolicy`
  (une session PHP n'est ouverte que pour les routes qui en ont besoin : auth HTML,
  back-office, `POST /admin/api/auth/login` — l'API kiosk publique sous `/api/*`, y
  compris `/api/health`, n'ouvre aucune session ni cookie, corrige le 2026-09-29), `Csrf`
  (jeton synchroniseur).
- `PasswordHasher` (argon2id + leurre de timing), `PinVerifier` (PIN de 4 a 12 chiffres),
  `PinThrottle`, `ThrottlePolicy` (backoff degressif, partagee avec `PasswordResetThrottle`),
  `PinGate` (primitif PIN + audit reutilise par l'API JSON, [ADR-0017](../adr/0017-api-admin-json.md)).
- Controleurs `AuthController`, `PasswordResetController`, `ProfileController` (set-PIN
  self-service), `MeController` (`GET /admin/me`), `AuthApiController` (connexion JSON :
  `POST /admin/api/auth/login`, `POST /admin/api/auth/logout`, `GET /admin/api/auth/me`,
  addendum [ADR-0017](../adr/0017-api-admin-json.md) du 2026-09-26).

## Regles metier
- RG-6 / RG-T02 : session valide (idle + absolu + compte actif, role et epoch de session
  relus en base a chaque requete) sinon 302 `/login`. Un role desactive retire les
  permissions des la requete suivante, mais NE FERME PAS la session : les pages sans
  permission dediee (tableau de bord, `/admin/me`, `/admin/profile/pin`, `/admin/privacy`,
  `App\Health\RouteSecurity`) restent accessibles sur une session deja ouverte.
- RG-8 / RG-9 : throttle login par compte (`user.failed_login_attempts` / `lockout_until`) + par IP (`login_throttle`), backoff degressif. Corrige le 2026-09-30 (revue adversariale) :
  une connexion reussie ne remet plus a zero le compteur IP (seul le compteur du compte
  l'est) ; l'increment du compteur par compte se fait desormais par une instruction SQL
  atomique (`failed_login_attempts = failed_login_attempts + 1`), relue sous le verrou de
  ligne qu'elle prend, comme la dimension IP. La re-verification du mot de passe sur
  `/admin/profile/pin` (`App\Auth\AccountLockout`) compte desormais sur ce MEME budget
  (limite assumee : un collegue peut y faire verrouiller la connexion du titulaire, pas pire
  qu'avant depuis `/login`, voir `SECURITY.md`).
- RESET_PASSWORD (12.3) : throttle par adresse et par IP (`password_reset_throttle`,
  migration `0020_session_invalidation.sql`) avant tout travail ; la confirmation
  incremente `user.session_epoch`, ce qui invalide immediatement les sessions ouvertes
  avant la reinitialisation. Depuis `680820f` (29/09), l'adresse y est stockee en empreinte
  SHA-256 (migration `0021`), la table est purgee par le cron, et un administrateur qui change
  le mot de passe d'un compte incremente aussi son `session_epoch` (sessions fermees).
- RG-T13 : PIN d'action sensible (voir [users](users.md), [rbac](rbac.md), stock).
- Anti-enumeration : reponses neutres (reset, login, throttle de reset) ; leurre de
  timing argon2id.

## Decisions
[ADR-0001](../adr/0001-php-from-scratch-sans-composer.md) (from scratch),
[ADR-0004](../adr/0004-pin-action-sensible-audit.md) (PIN),
[ADR-0005](../adr/0005-throttle-pin-separe-du-login.md) (throttle PIN).

## Tables
`user` (dont `session_epoch`, migration `0020_session_invalidation.sql`), `login_throttle`,
`pin_throttle`, `password_reset_throttle` (meme migration 0020), `audit_log` (`auth.login_success`,
`auth.login_failed`, `auth.password_reset`, `pin.set`, `pin.failed`). Detail : `docs/merise/mlt.md` section 12 (authentification) et section 2
« Regles de gestion transverses » (RG-T02 relecture session, RG-T13 PIN, RG-T22 throttle
du PIN — le document n'a pas de section 22).
