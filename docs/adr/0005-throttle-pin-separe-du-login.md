# ADR-0005 — Throttle du PIN separe des compteurs de connexion (RG-T22)

- Statut : Accepte, complete le 2026-09-29 (meme motif reutilise par `password_reset_throttle`)
- Date : 2026-06-15

## Contexte
Le PIN d'action sensible (ADR-0004) est court (4 chiffres) : il faut limiter le
brute-force. Question : reutiliser les compteurs de login (`user.lockout_until` /
`login_throttle`) ou un compteur dedie ? Et sur quelle dimension compter ?

## Decision
Table **`pin_throttle`** dediee, **separee** des compteurs de connexion. La dimension
est l'**utilisateur agissant** (la session authentifiee qui soumet le PIN), pas l'email
cible (contournable par rotation) ni l'IP (collateral sur poste partage). Backoff
degressif, bornes propres plus permissives que le login. Verrou evalue AVANT la
verification ; sous verrou actif, pas de nouvelle ligne `pin.failed` (anti-amplification).

## Consequences
- (+) Spammer le PIN d'une victime ne verrouille pas sa CONNEXION (pas d'escalade DoS
  sur une surface plus sensible).
- (+) Detection : un pic de `pin.failed` reste alertable.
- (-) Un compteur de plus a purger (cron, comme `login_throttle`).
- Brique : `App\Auth\PinThrottle`. Regle : RG-T22. Cf. ADR-0004.

## Errata
- Erratum (ecrit le 2026-09-29 par BYAN, `02609c5`, constat de l'audit #195 du 28/09) : le contexte ci-dessus dit « PIN (ADR-0004) est court
  (4 chiffres) » ; le PIN est en realite long de 4 a 12 chiffres
  (`STAFF_PIN_MIN_LENGTH`/`STAFF_PIN_MAX_LENGTH`, `App\Auth\PinVerifier` lignes 134/139),
  vrai depuis la creation de la brique (commit `7c35f8e`, 2026-06-15). Le raisonnement de
  cette fiche (compteur dedie, dimension utilisateur agissant) reste valide quelle que soit
  la longueur exacte du PIN.
- Complement (2026-09-29, commit `ef7fd37`) : le meme raisonnement (table dediee, bornes
  propres, backoff degressif) est reutilise une troisieme fois par `password_reset_throttle`
  (POST `/forgot_password`), avec DEUX dimensions cette fois (adresse demandee ET IP source,
  discriminees par `throttle_kind`) au lieu d'une seule — voir `docs/merise/dictionary.md`
  3.24. Avant ce correctif, cette route n'avait aucune limite ; trouve par la suite de tests
  de securite executables (`docs/soutenance/preuves/10-tests-securite.md`, ecart m6).
