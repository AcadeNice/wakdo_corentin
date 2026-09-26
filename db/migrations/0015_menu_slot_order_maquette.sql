-- db/migrations/0015_menu_slot_order_maquette.sql
-- =============================================================================
-- Wakdo - Migration 0015 : ordre des etapes du composeur de menu aligne maquette
-- =============================================================================
-- Purpose : audit maquette vs front avant soutenance (A10) -- le seed 0002 posait
--           les slots du composeur de menu dans l'ordre Boisson (display_order=1),
--           Accompagnement (display_order=2), Sauce (display_order=3). La maquette
--           de l'ecole enchaine Format -> Accompagnement -> Boisson -> (Sauce),
--           confirme par capture (annexe-maquette.md, ecran 4). MenuRepository::
--           slotsWithOptions() trie par `s.display_order, s.id` (aucune logique
--           applicative a changer, cf. rapport audit A10) : seule la donnee doit
--           bouger. Cette migration corrige les LIGNES DEJA EN BASE (installation
--           existante) ; le seed 0002 porte directement le bon ordre pour une
--           installation neuve.
-- Idempotence : chaque UPDATE est GARDE par le display_order D'ORIGINE (celui du
--               seed non corrige) : un slot dont l'ordre a ete change depuis (par un
--               admin, en production, ou une reconfiguration manuelle du menu) ne
--               correspond plus a ce garde et n'est PAS touche. Une fois corrige (ou
--               modifie a la main), plus aucun garde ne matche : re-jouable sans
--               effet. Aucun DDL. Pas de contrainte UNIQUE sur (menu_id,
--               display_order) (voir menu_slot, migration 0001) : les deux UPDATE
--               peuvent se chevaucher un instant sans violer de contrainte.
-- Target  : MariaDB 11.4 LTS, InnoDB, utf8mb4 / utf8mb4_unicode_ci.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

UPDATE menu_slot SET display_order = 2 WHERE slot_type = 'drink' AND display_order = 1;
UPDATE menu_slot SET display_order = 1 WHERE slot_type = 'side'  AND display_order = 2;
