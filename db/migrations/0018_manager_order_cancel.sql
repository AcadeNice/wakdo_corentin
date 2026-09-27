-- db/migrations/0018_manager_order_cancel.sql
-- =============================================================================
-- Wakdo - Migration 0018 : le responsable lit et annule les commandes (ADR-0020)
-- =============================================================================
-- Purpose : ADR-0020 (docs/adr/0020-responsable-annule-commande.md) remplace la
--           decision D5 sur le seul point de l'annulation de commande par le role
--           manager (« Responsable ») : celui-ci recoit desormais order.read ET
--           order.cancel. order.read est indispensable a order.cancel seul : sans
--           elle, le responsable pourrait avoir le droit d'annuler mais pas ouvrir
--           la liste des commandes pour trouver celle a annuler. Le comptoir et
--           le drive gardent leur order.cancel -- cette migration ajoute un droit,
--           elle n'en retire aucun. Aucune permission n'est creee : le catalogue
--           reste a 23 lignes (order.read et order.cancel existent deja depuis le
--           seed 0001) ; seule la table de jointure role_permission gagne deux
--           lignes pour le role manager. Le responsable n'a par ailleurs aucune
--           ligne role_visible_source (comme admin) : sa vue des commandes reste
--           globale, sans filtre de canal.
-- Idempotence : INSERT IGNORE sur la cle primaire composite (role_id,
--               permission_id) de role_permission -- un rejeu n'insere jamais de
--               doublon. Resolue par sous-requetes sur role.code / permission.code
--               (meme convention que le seed 0001), jamais d'id en dur.
--
--               Sur une installation NEUVE, les migrations s'executent TOUTES
--               avant TOUS les seeds (db/migrate.sh) : role et permission sont
--               donc encore VIDES au moment ou cette migration tourne, les deux
--               SELECT ci-dessous ne remontent aucune ligne et l'INSERT IGNORE ne
--               fait rien. Ne pas echouer sur des tables vides est le
--               comportement CORRECT ici -- c'est le seed 0001 qui pose alors
--               directement, pour une base neuve, un role_permission manager deja
--               a jour.
--
--               L'UPDATE de description est GARDE par le texte francais EXACT
--               pose par la migration 0012 (label = 'Responsable' AND
--               description = '...', voir ci-dessous) : un role dont la
--               description a ete modifiee depuis (par un admin, en production)
--               ne correspond plus a ce garde et n'est PAS touche -- une
--               migration ne doit jamais ecraser une personnalisation faite apres
--               coup. Note sur la collation : utf8mb4_unicode_ci est insensible a
--               la casse ET AUX ACCENTS ; le garde compare donc en COLLATE
--               utf8mb4_bin, pour qu'une description retouchee sur un seul accent
--               ne soit pas prise pour le texte d'origine et ecrasee (verifie par
--               ManagerOrderCancelMigrationDbTest::testGuardIsByteExactSoAnAccentOnlyEditIsNotOverwritten).
-- Target  : MariaDB 11.4 LTS, InnoDB, utf8mb4 / utf8mb4_unicode_ci.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1. Grants : order.read + order.cancel pour le role manager (ADR-0020).
INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id
FROM role r
JOIN permission p ON p.code IN ('order.read', 'order.cancel')
WHERE r.code = 'manager';

-- 2. Description a jour : mentionne desormais la lecture et l'annulation des
--    commandes (tous canaux), et precise que la creation/remise en reste
--    exclue -- garde par le texte francais exact pose par la migration 0012.
--    La comparaison se fait en COLLATE utf8mb4_bin, octet par octet : la
--    collation de la colonne (utf8mb4_unicode_ci) est insensible aux accents, et
--    un simple `=` ecraserait une description retouchee a la main sur un seul
--    accent.
UPDATE role
   SET description = 'Création et mise à jour du catalogue, gestion des ingrédients et du stock (réapprovisionnement et inventaire), statistiques, lecture et annulation des commandes (tous canaux, sans filtre). Ni création ni remise de commande, ni administration des utilisateurs/rôles.'
 WHERE code = 'manager'
   AND label = 'Responsable'
   AND description COLLATE utf8mb4_bin = 'Création et mise à jour du catalogue, gestion des ingrédients et du stock (réapprovisionnement et inventaire), statistiques. Ni administration des utilisateurs/rôles, ni annulation de commande.' COLLATE utf8mb4_bin;
