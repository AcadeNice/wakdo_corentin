# ADR-0010 — Cookie de session Secure conditionnel au HTTPS

- Statut : Accepte, complete le 2026-09-29 (perimetre des routes qui ouvrent une session)
- Date : 2026-06-17

> **Complement (2026-09-29, commit `ef7fd37`).** Cette fiche traite de l'attribut `secure`
> du cookie ; elle ne traitait pas de QUAND une session (et donc un cookie) est ouverte.
> Jusqu'a cette date, `src/public/admin/index.php` demarrait une session pour toute
> requete sans condition, y compris chaque appel anonyme de l'API kiosk sous `/api/*`
> (borne, sonde `/api/health`) : chaque appel recevait un `Set-Cookie: WAKDO_SID` inutile
> (la session restait vide) et ouvrait un fichier de session cote serveur, sans elevation
> de droit mais avec un stockage qui grossit avec le trafic anonyme. `App\Auth\
> SessionRoutePolicy::needsSession()` decide desormais, avant le dispatch, si la route
> visitee a besoin d'une session (auth HTML, back-office, connexion JSON) — `/api/*`
> n'en ouvre plus. Trouve par la suite de tests de securite executables
> (`docs/soutenance/preuves/10-tests-securite.md`, ecart m1).

## Contexte
Le cookie de session du back-office etait pose avec `secure => true` en dur
(security-by-design). Or un cookie `Secure` n'est emis/renvoye par le navigateur que
sur HTTPS : en HTTP (dev, stack standalone locale, E2E sans TLS) la session ne tenait
pas d'une requete a l'autre, donc le login admin echouait ("Session expiree" au POST,
le jeton CSRF ne pouvant matcher une session perdue). Revele par le parcours E2E admin.
En prod le souci n'apparait pas : Traefik termine le TLS.

## Decision
`secure` devient **conditionnel au schema** : vrai si la requete est HTTPS, faux sinon.
Detection (`SessionManager::cookieSecure()`) : `X-Forwarded-Proto: https` (pose par
Traefik en prod) en priorite, sinon la variable serveur `HTTPS`, sinon le port 443.
Applique aux deux points (pose du cookie + expiration au logout).

## Consequences
- (+) Le back-office est utilisable en **HTTP local** (dev, standalone, E2E) ; prod
  **inchange** (derriere Traefik -> `X-Forwarded-Proto=https` -> `Secure` reste pose).
- (+) Comportement standard (les frameworks derivent `Secure` du schema).
- Confiance en `X-Forwarded-Proto` : sure ici car l'app n'est joignable que par le
  reverse proxy sur le reseau interne (aucun acces client direct).
- (-) Un deploiement en **HTTP nu** (sans proxy TLS) n'aurait pas `Secure` — mais servir
  l'authentification en HTTP nu est de toute facon a proscrire (independant de ce flag).
- `httponly` et `SameSite=Strict` restent inconditionnels. Revele par [E2E admin](../domaines/auth.md).

## Errata
- Erratum (ecrit le 2026-09-29 par BYAN, `02609c5`, constat de l'audit #195 du 28/09) : le lien « [E2E admin](../domaines/auth.md) » pointait
  vers une fiche de domaine qui ne documente pas ce parcours E2E. Le test reel est
  `tests/e2e/admin.spec.js` (`garde -> login -> dashboard -> logout`).
