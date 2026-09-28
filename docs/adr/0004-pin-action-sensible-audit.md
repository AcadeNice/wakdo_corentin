# ADR-0004 — PIN d'action sensible (equipier) + audit dans la meme transaction

- Statut : Accepte, precise le 2026-09-27 par [ADR-0020](0020-responsable-annule-commande.md)
- Date : 2026-06-15

> **Precision (ADR-0020).** Le PIN designe QUI a agi (l'acteur inscrit dans `audit_log`),
> il ne decide pas SI l'action est permise : la permission (`role_permission`) est verifiee
> sur la SESSION, avant toute demande de PIN (ex. `guard('order.cancel')` avant le PIN dans
> `OrderAdminController::cancel`). Le PIN d'un responsable ne debloque donc rien que la
> session en poste n'ait deja : il ne peut pas etre utilise comme une autorisation
> deleguee. Faire porter la permission par l'acteur resolu par le PIN plutot que par la
> session est nomme comme evolution possible, non retenue pour ce lot (ADR-0020,
> alternative « Faire autoriser l'annulation par le code personnel d'un responsable »).

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
- (-) Surface d'attaque PIN (court, 4 a 12 chiffres) -> necessite un throttle dedie (voir
  ADR-0005).
- Brique : `App\Auth\PinVerifier`. Regle : `docs/merise/mlt.md` RG-T13/RG-T14.
