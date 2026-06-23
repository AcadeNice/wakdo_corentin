-- db/migrations/0002_pin_throttle.sql
-- =============================================================================
-- Wakdo - Migration 0002 : pin_throttle (entite 22, RG-T22)
-- =============================================================================
-- Purpose : Throttle des tentatives de PIN d'action sensible, par UTILISATEUR
--           AGISSANT (identite de session authentifiee, GuardResult->userId).
--           STRICTEMENT SEPARE des compteurs de connexion
--           (user.failed_login_attempts / user.lockout_until / login_throttle)
--           pour qu'un echec de PIN ne verrouille jamais la CONNEXION d'un
--           compte (pas d'escalade DoS sur la surface plus sensible). Sibling de
--           login_throttle (4.21) : meme forme, dimension differente (l'acteur,
--           pas l'IP). Le runner db/migrate.sh applique *.sql dans l'ordre
--           lexicographique via la table schema_migrations.
-- Target  : MariaDB 11.4 LTS, InnoDB, utf8mb4 / utf8mb4_unicode_ci.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE pin_throttle (
    id                INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    actor_user_id     INT UNSIGNED      NOT NULL,
    failed_attempts   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lockout_until     DATETIME              NULL,
    last_attempt_at   DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_pin_throttle_actor_user_id (actor_user_id),
    KEY idx_pin_throttle_lockout_until (lockout_until),
    CONSTRAINT fk_pin_throttle_actor_user_id FOREIGN KEY (actor_user_id)
        REFERENCES user (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note : pas de seed. La cle est l'acteur (un user back-office authentifie), donc
-- la FK ON DELETE CASCADE est sure (contrairement a login_throttle, dont la cle
-- est une IP arbitraire et qui n'a pas de FK). La purge cron des lignes sans
-- verrou actif au-dela de THROTTLE_PURGE_AFTER_HOURS s'aligne sur login_throttle :
--   DELETE FROM pin_throttle
--   WHERE (lockout_until IS NULL OR lockout_until < NOW())
--     AND last_attempt_at < NOW() - INTERVAL <THROTTLE_PURGE_AFTER_HOURS> HOUR;
