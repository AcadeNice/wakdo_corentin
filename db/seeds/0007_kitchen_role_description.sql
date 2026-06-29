-- db/seeds/0007_kitchen_role_description.sql
-- =============================================================================
-- Wakdo - Seed 0007 : description du role kitchen (avance la preparation)
-- =============================================================================
-- Purpose : retour oral #8 -- le KDS avance desormais l'etat de preparation
--           (paid -> preparing -> ready), garde par order.read (l'acces au KDS). La
--           description initiale du role kitchen ("Performs no order status transition",
--           seed 0001) est devenue FAUSSE : on la corrige pour qu'elle reflete la
--           capacite reelle. PAS de nouvelle permission (le catalogue reste fige a 23) :
--           avancer la preparation fait partie de l'operation du KDS, couverte par
--           order.read ; la remise finale reste sous order.deliver (que kitchen n'a pas).
-- Idempotence : UPDATE par code de role ; re-jouable sans effet.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

UPDATE role
   SET description = 'Kitchen display (KDS) of active orders; advances preparation state (preparing then ready) via order.read, plus inventory counting. Does not perform the final handover (order.deliver).'
 WHERE code = 'kitchen';
