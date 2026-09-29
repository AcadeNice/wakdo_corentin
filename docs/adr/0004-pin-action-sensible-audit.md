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
- (-) Surface d'attaque PIN (4 chiffres) -> necessite un throttle dedie (voir ADR-0005).
- Brique : `App\Auth\PinVerifier`. Regle : `docs/merise/mlt.md` RG-T13/RG-T14.

## Errata
- Erratum (ecrit le 2026-09-29 par BYAN, `02609c5`, constat de l'audit #195 du 28/09) : la consequence ci-dessus disait « PIN (4 chiffres) » ;
  le PIN est en realite long de 4 a 12 chiffres (`STAFF_PIN_MIN_LENGTH`/
  `STAFF_PIN_MAX_LENGTH`, `App\Auth\PinVerifier` lignes 134/139), vrai depuis la creation
  de la brique (commit `7c35f8e`, 2026-06-15 — le meme jour que cette fiche). Le corps
  d'origine est restaure ci-dessus pour l'enregistrement ; le fait exact est ici.
- Erratum (ecrit le 2026-09-29 par BYAN, `02609c5`, constat de l'audit #195 du 28/09) : « un manager peut autoriser sur le poste d'un autre
  sans relog » peut laisser croire que le PIN delegue un droit. Ce n'est pas le cas : le
  PIN identifie l'ACTEUR pour `audit_log`, mais la permission (`role_permission`) reste
  verifiee sur la SESSION, AVANT la demande de PIN (`guard('order.cancel')` avant le PIN
  dans `OrderAdminController::cancel()`, ligne 225 au 29/09). Un manager qui entre son PIN sur un
  poste sans `order.cancel` ne debloque donc rien ; voir la Precision (ADR-0020) en tete de
  fiche, qui le dit deja explicitement.
- Complement (2026-09-29, contre-audit independant) : cette fiche etablit que le PIN
  resout l'acteur ecrit dans `audit_log`, sans preciser le contenu de la ligne. Un ecart
  de minimisation des donnees a ete corrige sur ce point le meme jour : la ligne `pin.failed`
  (echec de PIN) ecrivait jusque-la l'adresse SAISIE au formulaire en clair dans `summary`,
  y compris pour une adresse qui ne correspond a aucun compte ; ce champ texte libre
  echappait a la retention (`AUDIT_LOG_RETENTION_DAYS`) comme a l'effacement RGPD d'un
  compte. Corrige (RGPD art. 5.1.c) : point d'ecriture unique `PinGate::auditFailedPin()`,
  qui n'ecrit plus que le contexte de l'action et, quand l'adresse correspond a un compte
  existant, son identifiant stable ; migration `0019_pin_failed_audit_minimisation.sql`
  purge les lignes deja ecrites. Detail : `docs/merise/mlt.md` RG-T14.
