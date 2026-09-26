-- =============================================================================
-- Wakdo — Seed 0009 : comptes de demonstration RBAC (soutenance du jury)
-- =============================================================================
-- Purpose : la production Wakdo n'a jusqu'ici qu'un seul compte utilisateur, admin
--           (seed 0001), cree pour l'exploitation, pas pour la demonstration. Le
--           jury de soutenance doit pouvoir se connecter LUI-MEME avec un compte
--           par poste et constater ce que le RBAC autorise et refuse pour chaque
--           role -- sans qu'on le lui affirme. Ce seed cree donc un compte par
--           role non-admin du seed 0001 (manager, kitchen, counter, drive), plus
--           un second compte comptoir (voir note plus bas), avec des identifiants
--           PUBLICS au meme titre que admin@wakdo.local (docs/demo/comptes-demo.md
--           le rappelle explicitement).
--
-- Pourquoi un second compte comptoir : au comptoir, plusieurs equipiers se
-- relaient sur le meme poste physique (session partagee). Le PIN d'action
-- sensible (mlt.md RG-T13 / PinVerifier::resolveActingUser) existe justement pour
-- distinguer QUI, parmi les equipiers d'un meme role, a realise une action
-- sensible (annulation, RG-T14 -> audit_log.actor_user_id) -- pas seulement QUEL
-- role l'a fait. Un seul compte comptoir ne peut pas demontrer cette distinction ;
-- deux comptes du meme role, avec des PIN distincts, le peuvent : le jury peut
-- annuler une commande sous chaque identite et voir l'audit distinguer les deux
-- acteurs alors que leur role et leurs permissions sont identiques.
--
-- Mots de passe / PIN : voir docs/demo/comptes-demo.md pour le detail par compte.
-- Hash argon2id generes HORS seed, avec les memes parametres que .env.example /
-- PasswordHasher::options() (memory_cost=65536 KiB, time_cost=4, threads=1), dans
-- un conteneur php:8.3-fpm-alpine3.20 JETABLE (meme image de base que
-- docker/php-fpm/Dockerfile) -- jamais sur un conteneur de production, meme
-- principe que la note du seed 0001 pour admin@wakdo.local.
--
-- Phase   : P4 — demo/reference seed, applique APRES le seed 0001 (les 5 roles et
--           leurs role_permission doivent exister ; role_id resolu par sous-
--           requete sur role.code, jamais d'id en dur, meme convention que 0001).
-- Target  : MariaDB 11.4 LTS, via db/seed.sh / db/migrate-container.sh
--           (suivi seeds_applied).
--
-- Idempotence : INSERT IGNORE sur uk_user_email (db/migrations/0001_init_schema.sql).
--   Rejouer ce fichier n'insere jamais de doublon (seeds_applied s'en charge deja
--   au niveau fichier), ET si un compte existe deja avec cet email -- y compris
--   parce que le jury ou un formateur a change son mot de passe/PIN depuis le
--   back-office -- IGNORE ne l'ecrase pas : la modification humaine est
--   respectee, jamais reinitialisee dans le dos de qui l'a faite.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- manager@wakdo.local — role manager (catalogue + stock + stats, PAS de RBAC/
-- utilisateurs, PAS d'annulation de commande : decision D5, seed 0001).
-- Mot de passe : WakdoManager2026!   PIN : 1010
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO user (email, password_hash, pin_hash, first_name, last_name, role_id, is_active)
SELECT
    'manager@wakdo.local',
    '$argon2id$v=19$m=65536,t=4,p=1$cTQwckMxeVhBUkMxSjJYMQ$Pcy+vB8asLIVMWiz7H+jPLPcWrks1Q0OFwRCCIAJflU',
    '$argon2id$v=19$m=65536,t=4,p=1$bXRhQWxXNzFRaUVmdXRjaA$l47u9pD0IAxYXpyf9GsjvJbHIlLK8qHpsLoU2LfiXR0',
    'Wakdo',
    'Manager',
    r.id,
    1
FROM role r
WHERE r.code = 'manager';

-- -----------------------------------------------------------------------------
-- cuisine@wakdo.local — role kitchen (ecran KDS, avance la preparation via
-- order.read, inventaire ; pas de remise finale : pas order.deliver).
-- Mot de passe : WakdoCuisine2026!   PIN : 2020
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO user (email, password_hash, pin_hash, first_name, last_name, role_id, is_active)
SELECT
    'cuisine@wakdo.local',
    '$argon2id$v=19$m=65536,t=4,p=1$QkkyVjA4N0FCZElWVmNFWg$CJsNmKhaayeQQyP051ng6PHcJRRA/njrr/+Akk8et4Q',
    '$argon2id$v=19$m=65536,t=4,p=1$aktQck9VUVo0YmNmVXVMaA$Qxj+OOKcdxvKR3MiCzNd1buCnIvqhi5bgaBd64OfP0w',
    'Wakdo',
    'Cuisine',
    r.id,
    1
FROM role r
WHERE r.code = 'kitchen';

-- -----------------------------------------------------------------------------
-- comptoir@wakdo.local — role counter, premier equipier (source de commande
-- taguee comptoir ; cycle de vie complet : creer/remettre/annuler + inventaire).
-- Mot de passe : WakdoComptoir2026!   PIN : 3030
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO user (email, password_hash, pin_hash, first_name, last_name, role_id, is_active)
SELECT
    'comptoir@wakdo.local',
    '$argon2id$v=19$m=65536,t=4,p=1$eTI3ZUxmYWYwazZqUjZCNQ$e64lqhlOHwfdveBqKSLzq8CMj2kwG2H1rcCUZoAHLhk',
    '$argon2id$v=19$m=65536,t=4,p=1$Vm5qeGZCaWZNbEUwbTA3Yg$FmH5myxcdhe0xFuiy3zbq8I1j5rDR7QwQRAHjZo1Ug0',
    'Wakdo',
    'Comptoir A',
    r.id,
    1
FROM role r
WHERE r.code = 'counter';

-- -----------------------------------------------------------------------------
-- comptoir2@wakdo.local — role counter, second equipier (memes permissions que
-- comptoir@wakdo.local -- voir note d'en-tete : demontre que le PIN distingue les
-- deux acteurs dans audit_log malgre un role et des droits identiques).
-- Mot de passe : WakdoComptoirB2026!   PIN : 3131
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO user (email, password_hash, pin_hash, first_name, last_name, role_id, is_active)
SELECT
    'comptoir2@wakdo.local',
    '$argon2id$v=19$m=65536,t=4,p=1$UVJqelBkM0d2NVpuNWlqOA$A1O3tXRgKF9Yif25vWZnGRsAiV+D47DWeGmvFnyhW0k',
    '$argon2id$v=19$m=65536,t=4,p=1$WWdYallIVTFIOVI3eE1mVw$/yG+bN5rvNNjbo6B4npYNbh6USfuUExjVcCilg9UN9o',
    'Wakdo',
    'Comptoir B',
    r.id,
    1
FROM role r
WHERE r.code = 'counter';

-- -----------------------------------------------------------------------------
-- drive@wakdo.local — role drive (memes droits que counter, source de commande
-- taguee automatiquement drive : RG-T12, role_visible_source du seed 0001).
-- Mot de passe : WakdoDrive2026!   PIN : 4040
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO user (email, password_hash, pin_hash, first_name, last_name, role_id, is_active)
SELECT
    'drive@wakdo.local',
    '$argon2id$v=19$m=65536,t=4,p=1$di9HODUySzlNUnA1Zjg1RQ$GaaIuBexLCBysKJCwV2VvI8zVAFTl3D/bAoalrD1Apg',
    '$argon2id$v=19$m=65536,t=4,p=1$R1UuLmRuYlVFQ3B6bkZDUg$2o9SFWZCGl2cv3PA8e8OILDITSZPpy7GxgP9KtdRkz8',
    'Wakdo',
    'Drive',
    r.id,
    1
FROM role r
WHERE r.code = 'drive';
