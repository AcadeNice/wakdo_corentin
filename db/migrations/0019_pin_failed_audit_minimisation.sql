-- db/migrations/0019_pin_failed_audit_minimisation.sql
-- =============================================================================
-- Wakdo - Migration 0019 : le journal d'audit n'ecrit plus l'adresse tentee
-- =============================================================================
-- Purpose : ecart de minimisation des donnees (RGPD art. 5.1.c) releve en audit.
--           A chaque PIN d'action sensible echoue, les 7 points d'ecriture de
--           `pin.failed` (PinGate::resolve() pour l'API JSON + les 6 controleurs
--           HTML) tracaient l'adresse SAISIE en clair dans `summary`
--           ("... (email tenté: <adresse>)"). Ce champ texte libre echappe a la
--           retention (AUDIT_LOG_RETENTION_DAYS, 365 jours par defaut) comme a
--           l'effacement RGPD d'un compte (`UserRepository::anonymise()` ne
--           touche QUE la ligne `user`, jamais les lignes `audit_log` deja
--           ecrites) : une adresse tapee -- par erreur, ou lors d'une tentative
--           de brute-force -- y survivait indefiniment, y compris pour une
--           adresse qui ne correspond a AUCUN compte.
--
--           Le code applicatif est corrige (PinGate::auditFailedPin(), point
--           d'ecriture desormais UNIQUE) : il n'ecrit plus l'adresse, seulement
--           le CONTEXTE de l'action dans `summary` et, quand l'adresse
--           correspondait a un compte existant, son identifiant dans
--           `details.target_user_id` (JSON). Cette migration nettoie les LIGNES
--           DEJA ECRITES par l'ancien code, sur une installation en service :
--           elle retire la partie "(email tenté: ...)" du `summary` des lignes
--           `pin.failed` qui la portent encore, sans y ecrire retroactivement
--           `target_user_id` (l'etat du compte au moment de la tentative n'est
--           plus observable avec certitude aujourd'hui -- on retire ce qui ne
--           doit plus etre la, on ne reconstruit pas une donnee qu'on n'a plus).
--
--           `auth.login_failed` (AuthService::recordFailure()) est verifie ne
--           JAMAIS avoir ecrit l'adresse tentee (summary fixe "Échec de
--           connexion", confirme par relecture de tout l'historique git du
--           fichier) : aucune ligne a nettoyer de ce cote.
--
-- Idempotence : le WHERE ne selectionne QUE les lignes `pin.failed` dont le
--               `summary` contient encore le suffixe "(email tenté:" -- une fois
--               retire, ce suffixe n'existe plus et un rejeu ne trouve plus
--               aucune ligne a modifier (0 ligne affectee). Aucune autre colonne
--               n'est touchee (ni `entity_id`, ni `details`, ni les lignes dont
--               l'`action_code` differe de `pin.failed`). Aucun DDL.
-- Target  : MariaDB 11.4 LTS, InnoDB, utf8mb4 / utf8mb4_unicode_ci.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

UPDATE audit_log
   SET summary = TRIM(SUBSTRING(summary, 1, LOCATE(' (email tenté:', summary) - 1))
 WHERE action_code = 'pin.failed'
   AND summary LIKE '%(email tenté:%)';
