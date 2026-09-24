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
--           Texte en francais (F40 point 6 / E14) : aligne sur le seed 0001, qui porte
--           desormais directement cette description correcte pour une installation
--           neuve -- ce fichier ne fait plus que la reconduire, pour rester idempotent
--           quel que soit l'ordre reel d'execution.
-- Idempotence : UPDATE par code de role ; re-jouable sans effet.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

UPDATE role
   SET description = 'Écran cuisine (KDS) des commandes actives ; fait avancer l''état de préparation (en préparation puis prête) via order.read, et effectue l''inventaire. N''effectue pas la remise finale (order.deliver).'
 WHERE code = 'kitchen';
