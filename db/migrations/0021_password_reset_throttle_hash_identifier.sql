-- db/migrations/0021_password_reset_throttle_hash_identifier.sql
-- =============================================================================
-- Wakdo - Migration 0021 : empreinte au lieu de l'adresse en clair dans
--         password_reset_throttle (RGPD art. 5.1.c, minimisation)
-- =============================================================================
-- Purpose : ecart de minimisation des donnees releve en audit (contre-audit du
--           2026-09-29). `password_reset_throttle.identifier` (migration 0020)
--           stockait l'ADRESSE demandee EN CLAIR pour la dimension
--           `throttle_kind = 'email'`, y compris pour une adresse qui ne
--           correspond a AUCUN compte (RG-2 anti-enumeration : le throttle doit
--           traiter une adresse inconnue exactement comme une adresse connue,
--           donc ne peut pas se contenter de hacher les seules adresses
--           existantes). Cette table ne sert JAMAIS a retrouver ou envoyer une
--           adresse -- uniquement a COMPARER deux tentatives entre elles par
--           egalite exacte (upsert sur la cle unique (throttle_kind,
--           identifier)) -- une empreinte a sens unique suffit et retire
--           l'adresse en clair de la table.
--
--           App\Auth\PasswordResetThrottle::hashEmail() ecrit desormais
--           SHA-256(adresse normalisee : minuscules, espaces de bord retires)
--           pour la dimension 'email'. Cette migration CONVERTIT les lignes
--           DEJA ECRITES par l'ancien code sur une installation en service,
--           plutot que de les vider : la dimension 'email' porte un COMPTEUR de
--           tentatives (failed_attempts/lockout_until) que vider remettrait a
--           zero un verrou eventuellement actif au moment du deploiement (une
--           fenetre de service degradee, meme courte, pour un throttle
--           anti-abus n'est pas sans consequence) ; convertir en place
--           preserve ce compteur sans jamais relire l'adresse en clair au-dela
--           de cette seule instruction. La dimension 'ip' n'est PAS concernee
--           (l'audit porte sur l'ADRESSE demandee, pas sur l'IP source) : la
--           clause WHERE la filtre explicitement (throttle_kind = 'email'),
--           pour ne jamais faire glisser une adresse IP existante vers une
--           empreinte que le code applicatif (dimension 'ip', valeur brute) ne
--           saurait plus jamais reproduire lors d'un futur upsert.
--
-- Idempotence : la clause WHERE ne convertit que les lignes dont `identifier`
--               NE RESSEMBLE PAS DEJA a une empreinte SHA-256 (64 caracteres
--               hexadecimaux minuscules) -- une adresse email n'a normalement
--               aucune chance de matcher ce motif exact. Une fois converti,
--               `identifier` matche le motif et un rejeu ne trouve plus aucune
--               ligne a modifier (0 ligne affectee). Aucun DDL (la colonne
--               VARCHAR(254) existante accueille sans modification les 64
--               caracteres hexadecimaux du digest).
-- Target  : MariaDB 11.4 LTS, InnoDB, utf8mb4 / utf8mb4_unicode_ci.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

UPDATE password_reset_throttle
   SET identifier = SHA2(LOWER(TRIM(identifier)), 256)
 WHERE throttle_kind = 'email'
   AND identifier NOT REGEXP '^[0-9a-f]{64}$';
