# ADR-0004 — PIN d'action sensible (equipier) + audit dans la meme transaction

- Statut : Accepte
- Date : 2026-06-15

## Contexte
Les postes back-office sont partages (session ouverte au comptoir). Pour les operations
sensibles (annulation, changement prix/TVA, suppressions, inventaire, gestion
utilisateur, RBAC, effacement PII), il faut imputer l'acte a une personne, pas a la
session partagee.

## Decision
Modele **identifiant equipier + PIN** : l'operation sensible exige email + PIN, verifies
contre `user.pin_hash` (argon2id). Le `user_id` ainsi resolu est l'**acteur** ecrit dans
`audit_log` (RG-T14), dans la **meme transaction** que l'effet (RG-T08). Le set sensible
est defini par RG-T13. Les operations de stock tracent via `stock_movement.user_id`
(pas de double-journal).

## Consequences
- (+) Imputabilite reelle sur poste partage ; trace immuable et atomique (pas d'effet
  sans audit, ni l'inverse).
- (+) Le PIN n'identifie pas la session : un manager peut autoriser sur le poste d'un
  autre sans relog.
- (-) Surface d'attaque PIN (4 chiffres) -> necessite un throttle dedie (voir ADR-0005).
- Brique : `App\Auth\PinVerifier`. Regle : `docs/merise/mlt.md` RG-T13/RG-T14.
