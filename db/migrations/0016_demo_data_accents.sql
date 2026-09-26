-- db/migrations/0016_demo_data_accents.sql
-- =============================================================================
-- Wakdo - Migration 0016 : accents des donnees de demonstration affichees
-- =============================================================================
-- Purpose : les libelles AFFICHES au client (borne) et aux equipiers (back-office)
--           etaient saisis sans accents dans les seeds 0001/0002/0003 : "Cereales",
--           "Oeufs", "Graines de sesame", "Ice Tea Peche", "Cesar Classic",
--           "Filet de poulet pane", "Steak hache", unite "piece". Le texte des
--           allergenes est le plus visible : il est lu par le client dans la modale
--           allergenes de la borne (preuve DOM
--           docs/soutenance/preuves/w3c/dom-rendu/produits-modale-allergenes.html).
--           Cette migration corrige les DONNEES DEJA EN PLACE (installation
--           existante) ; les seeds portent directement le texte accentue pour une
--           installation neuve.
--
--           Perimetre : uniquement les colonnes de PRESENTATION (allergen.name,
--           allergen.description, product.name, ingredient.name, ingredient.unit).
--           Aucun identifiant technique n'est touche : allergen.code, category.slug
--           et permission.code restent inchanges. Aucun DDL.
--
--           Hors perimetre, volontairement : "Sunday" (dessert) et l'apostrophe
--           absente de "Ptit Wrap" ne sont pas des fautes d'accent mais
--           l'orthographe de la source ecole (docs/merise/_sources/produits.json),
--           conservee telle quelle. Seul l'accent manquant est pose ici.
--
-- Idempotence : chaque UPDATE est GARDE par la valeur NON ACCENTUEE d'origine. Un
--               libelle modifie depuis le back-office ne correspond plus au garde et
--               n'est PAS touche. La collation utf8mb4_unicode_ci etant insensible
--               aux accents, le garde matche encore la ligne DEJA corrigee, mais
--               l'UPDATE y ecrit alors la valeur identique : MariaDB compte 0 ligne
--               modifiee (rowCount = lignes CHANGEES, pas lignes trouvees), donc un
--               rejeu reste bien un non-evenement mesurable.
-- Target  : MariaDB 11.4 LTS, InnoDB, utf8mb4 / utf8mb4_unicode_ci.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 1. allergen.name — 5 des 14 libelles portaient un nom commun francais sans
--    accent. Garde par code (identifiant stable) ET par le libelle d'origine.
-- -----------------------------------------------------------------------------
UPDATE allergen SET name = 'Crustacés'         WHERE code = 'crustaceans' AND name = 'Crustaces';
UPDATE allergen SET name = 'Œufs'              WHERE code = 'eggs'        AND name = 'Oeufs';
UPDATE allergen SET name = 'Céleri'            WHERE code = 'celery'      AND name = 'Celeri';
UPDATE allergen SET name = 'Graines de sésame' WHERE code = 'sesame'      AND name = 'Graines de sesame';
UPDATE allergen SET name = 'Fruits à coque'    WHERE code = 'nuts'        AND name = 'Fruits a coque';

-- -----------------------------------------------------------------------------
-- 2. allergen.description — les 14 descriptions contenaient au moins "a base de"
--    sans accent. Texte repris de l'annexe II du reglement (UE) 1169/2011.
-- -----------------------------------------------------------------------------
UPDATE allergen SET description = 'Céréales contenant du gluten (blé, seigle, orge, avoine, épeautre, kamut) et produits à base de ces céréales.'
    WHERE code = 'gluten' AND description = 'Cereales contenant du gluten (ble, seigle, orge, avoine, epeautre, kamut) et produits a base de ces cereales.';
UPDATE allergen SET description = 'Crustacés et produits à base de crustacés.'
    WHERE code = 'crustaceans' AND description = 'Crustaces et produits a base de crustaces.';
UPDATE allergen SET description = 'Œufs et produits à base d''œufs.'
    WHERE code = 'eggs' AND description = 'Oeufs et produits a base d''oeufs.';
UPDATE allergen SET description = 'Poissons et produits à base de poissons.'
    WHERE code = 'fish' AND description = 'Poissons et produits a base de poissons.';
UPDATE allergen SET description = 'Arachides et produits à base d''arachides.'
    WHERE code = 'peanuts' AND description = 'Arachides et produits a base d''arachides.';
UPDATE allergen SET description = 'Soja et produits à base de soja.'
    WHERE code = 'soybeans' AND description = 'Soja et produits a base de soja.';
UPDATE allergen SET description = 'Lait et produits à base de lait (y compris le lactose).'
    WHERE code = 'milk' AND description = 'Lait et produits a base de lait (y compris le lactose).';
UPDATE allergen SET description = 'Fruits à coque : amandes, noisettes, noix, noix de cajou, de pécan, du Brésil, pistaches, noix de Macadamia.'
    WHERE code = 'nuts' AND description = 'Fruits a coque : amandes, noisettes, noix, noix de cajou, de pecan, du Bresil, pistaches, noix de Macadamia.';
UPDATE allergen SET description = 'Céleri et produits à base de céleri.'
    WHERE code = 'celery' AND description = 'Celeri et produits a base de celeri.';
UPDATE allergen SET description = 'Moutarde et produits à base de moutarde.'
    WHERE code = 'mustard' AND description = 'Moutarde et produits a base de moutarde.';
UPDATE allergen SET description = 'Graines de sésame et produits à base de graines de sésame.'
    WHERE code = 'sesame' AND description = 'Graines de sesame et produits a base de graines de sesame.';
UPDATE allergen SET description = 'Anhydride sulfureux et sulfites en concentration supérieure à 10 mg/kg ou 10 mg/l (exprimés en SO2).'
    WHERE code = 'sulphites' AND description = 'Anhydride sulfureux et sulfites en concentration superieure a 10 mg/kg ou 10 mg/l (exprimes en SO2).';
UPDATE allergen SET description = 'Lupin et produits à base de lupin.'
    WHERE code = 'lupin' AND description = 'Lupin et produits a base de lupin.';
UPDATE allergen SET description = 'Mollusques et produits à base de mollusques.'
    WHERE code = 'molluscs' AND description = 'Mollusques et produits a base de mollusques.';

-- -----------------------------------------------------------------------------
-- 3. product.name — 4 produits de base + la variante 50 cl derivee par le seed
--    0005 (CONCAT(name, ' 50cl')), qui porte donc la meme faute.
-- -----------------------------------------------------------------------------
UPDATE product SET name = 'Ice Tea Pêche'      WHERE name = 'Ice Tea Peche';
UPDATE product SET name = 'Ice Tea Pêche 50cl' WHERE name = 'Ice Tea Peche 50cl';
UPDATE product SET name = 'César Classic'      WHERE name = 'Cesar Classic';
UPDATE product SET name = 'MC Wrap Chèvre'     WHERE name = 'MC Wrap Chevre';
UPDATE product SET name = 'Ptit Wrap Chèvre'   WHERE name = 'Ptit Wrap Chevre';

-- -----------------------------------------------------------------------------
-- 4. ingredient.name — noms affiches dans la page stock et dans la composition
--    d'un produit. "Dose Jus d Orange" perdait aussi son apostrophe.
-- -----------------------------------------------------------------------------
UPDATE ingredient SET name = 'Dose Ice Tea Pêche'    WHERE name = 'Dose Ice Tea Peche';
UPDATE ingredient SET name = 'Dose Jus d''Orange'    WHERE name = 'Dose Jus d Orange';
UPDATE ingredient SET name = 'Filet de poulet pané'  WHERE name = 'Filet de poulet pane';
UPDATE ingredient SET name = 'Fromage de chèvre'     WHERE name = 'Fromage de chevre';
UPDATE ingredient SET name = 'Pain sésame'           WHERE name = 'Pain sesame';
UPDATE ingredient SET name = 'Steak haché'           WHERE name = 'Steak hache';

-- -----------------------------------------------------------------------------
-- 5. ingredient.unit — texte libre AFFICHE tel quel a cote du stock
--    (Views/admin/ingredients/adjust.php), et dont le formulaire propose deja
--    "pièce" accentué en exemple. Les 5 autres unites (dose, dosette, portion,
--    rondelle, tranche) n'ont pas d'accent.
-- -----------------------------------------------------------------------------
UPDATE ingredient SET unit = 'pièce' WHERE unit = 'piece';
