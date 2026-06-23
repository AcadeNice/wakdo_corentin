# ADR-0010 — Cookie de session Secure conditionnel au HTTPS

- Statut : Accepte
- Date : 2026-06-17

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
