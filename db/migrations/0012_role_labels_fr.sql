-- db/migrations/0012_role_labels_fr.sql
-- =============================================================================
-- Wakdo - Migration 0012 : libelles et descriptions des roles en francais
-- =============================================================================
-- Purpose : audit visuel avant soutenance (F40, section "Textes techniques ou en
--           anglais" de defauts-visibles.md) -- les 5 roles (admin, manager,
--           kitchen, counter, drive) portaient un libelle et une description en
--           ANGLAIS (seed 0001), visibles dans le back-office (topbar "Administrator",
--           liste des roles, formulaire de role) alors que les utilisateurs de
--           l'application sont des equipiers francophones. Cette migration met a jour
--           les DONNEES DEJA EN PLACE (installation existante, seed 0001 deja joue) ;
--           le seed 0001 porte directement le texte francais pour une installation
--           neuve (seeds/0001_rbac_and_reference.sql).
--
--           E14 (audit schemas 6.3) : la description du role kitchen n'est pas une
--           simple traduction de l'original anglais -- celui-ci disait a tort "ne fait
--           aucune transition de statut" (seed 0001) alors que le KDS avance l'etat de
--           preparation (paid -> preparing -> ready) via order.read depuis le retour
--           oral #8 (seed 0007, deja appliquee sur cette base). Le texte francais ci-
--           dessous reprend la capacite REELLE, pas la description perimee.
--
--           code, default_route et order_source restent des identifiants techniques,
--           jamais affiches en clair aux equipiers : inchanges par cette migration.
-- Idempotence : chaque UPDATE est GARDE par le label ET la description ANGLAIS
--               D'ORIGINE (label = ... AND description = ...) : un role dont le
--               libelle a ete modifie depuis le back-office (par un admin, en
--               production) ne correspond plus a ce garde et n'est PAS touche --
--               une migration ne doit jamais ecraser une personnalisation faite
--               apres coup. Le role kitchen a deux descriptions anglaises possibles
--               selon que le seed 0007 (retour oral #8) a deja tourne ou non sur
--               cette base : les deux sont acceptees (description IN (...)). Une
--               fois en francais (ou modifie a la main), la ligne ne matche plus
--               aucun garde et la migration redevient un no-op : re-jouable sans
--               effet. Aucun DDL.
-- Target  : MariaDB 11.4 LTS, InnoDB, utf8mb4 / utf8mb4_unicode_ci.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

UPDATE role SET label = 'Administrateur', description = 'Accès complet au back-office : gestion CRUD complète du catalogue (y compris les suppressions), gestion des utilisateurs, rôles et permissions (RBAC), stock, statistiques, création/remise/annulation de commande.'
 WHERE code = 'admin'
   AND label = 'Administrator'
   AND description = 'Full back-office access: complete catalogue CRUD (incl. deletes), user/role/permission (RBAC) management, stock, stats, order create/deliver/cancel.';

UPDATE role SET label = 'Responsable', description = 'Création et mise à jour du catalogue, gestion des ingrédients et du stock (réapprovisionnement et inventaire), statistiques. Ni administration des utilisateurs/rôles, ni annulation de commande.'
 WHERE code = 'manager'
   AND label = 'Manager'
   AND description = 'Catalogue create/update, ingredient and stock management (restock + inventory), statistics. No user/RBAC administration, no order cancellation.';

UPDATE role SET label = 'Équipier cuisine', description = 'Écran cuisine (KDS) des commandes actives ; fait avancer l''état de préparation (en préparation puis prête) via order.read, et effectue l''inventaire. N''effectue pas la remise finale (order.deliver).'
 WHERE code = 'kitchen'
   AND label = 'Kitchen Staff'
   AND description IN (
     'Read-only kitchen display (KDS) of paid orders sorted by paid_at ascending, plus inventory counting. Performs no order status transition.',
     'Kitchen display (KDS) of active orders; advances preparation state (preparing then ready) via order.read, plus inventory counting. Does not perform the final handover (order.deliver).'
   );

UPDATE role SET label = 'Équipier comptoir', description = 'Prend les commandes au comptoir, les remet au client, peut annuler. Effectue l''inventaire. Source de commande taguée automatiquement comptoir.'
 WHERE code = 'counter'
   AND label = 'Counter Staff'
   AND description = 'Takes orders at the counter, delivers them to the customer, can cancel. Inventory counting. source auto-tagged as counter.';

UPDATE role SET label = 'Équipier drive', description = 'Prend les commandes au drive (interphone et casque), les remet au client, peut annuler. Effectue l''inventaire. Source de commande taguée automatiquement drive.'
 WHERE code = 'drive'
   AND label = 'Drive Staff'
   AND description = 'Takes orders at the drive-thru (intercom + headset), delivers them, can cancel. Inventory counting. source auto-tagged as drive.';
