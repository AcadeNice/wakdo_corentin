-- db/migrations/0013_category_names_titlecase.sql
-- =============================================================================
-- Wakdo - Migration 0013 : libelles de categorie en casse normale
-- =============================================================================
-- Purpose : audit visuel avant soutenance (F40, section "Textes techniques ou en
--           anglais") -- les 9 categories de demonstration (seed 0002) portaient un
--           `name` (libelle AFFICHE aux equipiers et aux clients) tout en minuscules
--           ("menus", "boissons"...), copie du `slug` (identifiant technique de
--           routage, cote borne comme cote back-office). Cette migration met a jour
--           les DONNEES DEJA EN PLACE (installation existante) ; le seed 0002 porte
--           directement le libelle capitalise pour une installation neuve.
--
--           `slug` reste EN MINUSCULES, inchange : il n'est jamais affiche en clair
--           (mapping slot_type -> categories de MenuController::SLOT_CATEGORIES,
--           routage borne CATEGORY_ID_TO_SLUG). Seul `name` est un texte de
--           presentation.
-- Idempotence : chaque UPDATE est GARDE par slug ET par le `name` D'ORIGINE
--               (le libelle brut en minuscules) : une categorie dont le libelle a
--               ete modifie depuis le back-office (par un admin, en production, ou
--               une categorie creee a la main) ne correspond plus a ce garde et
--               n'est PAS touchee. Une fois capitalise (ou modifie a la main), plus
--               aucun garde ne matche : re-jouable sans effet. Aucun DDL.
-- Target  : MariaDB 11.4 LTS, InnoDB, utf8mb4 / utf8mb4_unicode_ci.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

UPDATE category SET name = 'Menus'    WHERE slug = 'menus'    AND name = 'menus';
UPDATE category SET name = 'Boissons' WHERE slug = 'boissons' AND name = 'boissons';
UPDATE category SET name = 'Burgers'  WHERE slug = 'burgers'  AND name = 'burgers';
UPDATE category SET name = 'Frites'   WHERE slug = 'frites'   AND name = 'frites';
UPDATE category SET name = 'Encas'    WHERE slug = 'encas'    AND name = 'encas';
UPDATE category SET name = 'Wraps'    WHERE slug = 'wraps'    AND name = 'wraps';
UPDATE category SET name = 'Salades'  WHERE slug = 'salades'  AND name = 'salades';
UPDATE category SET name = 'Desserts' WHERE slug = 'desserts' AND name = 'desserts';
UPDATE category SET name = 'Sauces'   WHERE slug = 'sauces'   AND name = 'sauces';
