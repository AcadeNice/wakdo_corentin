# ADR-0007 — Effacement RGPD par anonymisation (tombstone), pas DELETE

- Statut : Accepte
- Date : 2026-06-17

## Contexte
Le droit a l'effacement (RGPD, Cr 3.d) s'applique aux comptes back-office. Un `DELETE`
dur casserait l'integrite referentielle (FK entrantes depuis `stock_movement.user_id`,
`customer_order.acting_user_id`, `audit_log.actor_user_id`) et effacerait la trace
d'imputabilite des actes passes.

## Decision
**Anonymisation** (mlt 10.5), pas suppression : en une transaction, vider la PII de la
ligne `user` (email -> `anon-<id>@wakdo.invalid` RFC 2606, prenom/nom vides, hash vide,
PIN/reset NULL), poser `anonymized_at`, `is_active = 0`. La ligne **persiste** comme
tombstone. Idempotent (clause `anonymized_at IS NULL`). Trace : `audit_log`
`user.erase_pii`.

## Consequences
- (+) FK preservees ; les actes passes restent imputables a un principal anonymise
  (qui-en-tant-qu-id), sans PII.
- (+) Email unique conserve, non identifiant.
- (-) La ligne reste en base (tombstone) : a documenter dans le registre de traitement.
- Garde-fou : interdit d'anonymiser le dernier admin actif / soi-meme (anti-lockout).
