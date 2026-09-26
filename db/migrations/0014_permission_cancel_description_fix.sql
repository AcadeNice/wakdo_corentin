-- db/migrations/0014_permission_cancel_description_fix.sql
-- =============================================================================
-- Wakdo - Migration 0014 : description de order.cancel a jour (etats de cuisine)
-- =============================================================================
-- Purpose : audit des schemas avant soutenance (E15, section 6.3) -- la description
--           du catalogue de permissions (seed 0001) disait que order.cancel
--           "Cancel a pending or paid order", alors que le domaine
--           (OrderRepository::cancel, migration 0009) accepte aussi les etats de
--           cuisine preparing et ready depuis l'ajout du parcours KDS. Cette
--           description est devenue incomplete des cette migration 0009 ; elle
--           n'est affichee nulle part dans l'interface aujourd'hui (RoleRepository::
--           allPermissions() ne selectionne pas `description`), mais elle reste la
--           documentation de reference du catalogue -- elle doit dire le vrai.
-- Idempotence : l'UPDATE est GARDE par la description D'ORIGINE (celle-ci-dessous) :
--               une description modifiee depuis (a la main, ou par un admin) ne
--               correspond plus a ce garde et n'est PAS touchee. Une fois a jour (ou
--               modifiee a la main), plus aucun garde ne matche : re-jouable sans
--               effet. Aucun DDL.
-- Target  : MariaDB 11.4 LTS, InnoDB, utf8mb4 / utf8mb4_unicode_ci.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

UPDATE permission
   SET description = 'Cancel a non-terminal order (pending, paid, preparing or ready ; restocks ingredients if it was paid).'
 WHERE code = 'order.cancel'
   AND description = 'Cancel a pending or paid order (restocks ingredients if paid).';
