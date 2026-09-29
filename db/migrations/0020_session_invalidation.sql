-- db/migrations/0020_session_invalidation.sql
-- =============================================================================
-- Wakdo - Migration 0020 : marqueur d'invalidation de session (RG-6 + RG-T02)
--         et throttle des demandes de reinitialisation de mot de passe
-- =============================================================================
-- Purpose : deux ecarts de securite (relecture adverse, session/authentification) :
--
--           1. `user.session_epoch` -- App\Auth\SessionGuard::check() relisait
--              `is_active` en base a chaque requete (RG-T02) mais reprenait
--              `role_id` DEPUIS LA SESSION, pose une seule fois a la connexion
--              (App\Auth\AuthService::authenticate()) : un changement de role
--              n'etait applique qu'a la PROCHAINE connexion, jamais a une session
--              deja ouverte. Meme defaut pour une reinitialisation de mot de
--              passe (App\Auth\PasswordResetService::confirmReset()) : le nouveau
--              hash ne fermait aucune session deja ouverte du compte -- une
--              session volee AVANT la reinitialisation restait valide apres.
--              `session_epoch` est un compteur pose en session a la connexion
--              (valeur lue en base a cet instant) et compare, dans la MEME lecture
--              que `is_active`/`role_id`, a la valeur courante en base a CHAQUE
--              requete : la reinitialisation de mot de passe l'incremente, ce qui
--              rend immediatement invalide toute session ouverte AVANT elle (y
--              compris celle qui vient de reinitialiser, si elle etait deja
--              connectee -- ce flux n'a normalement pas de session active, voir
--              le docblock de PasswordResetService::confirmReset()). `role_id`
--              lui-meme n'a plus besoin d'etre versionne : il est desormais relu
--              en base a CHAQUE requete (plus jamais fait confiance a la session),
--              donc un changement de role prend deja effet sans reconnexion.
--              DEFAULT 0 : une installation existante n'a subi aucune
--              reinitialisation depuis ce lot, ses sessions deja ouvertes
--              (implicitement a l'epoch 0) restent valides.
--
--           2. `password_reset_throttle` -- POST /forgot_password
--              (PasswordResetController::submitRequest()) n'avait AUCUNE limite :
--              30 demandes consecutives pour la meme adresse passaient toutes (et,
--              SMTP configure, inondaient la boite du compte vise a chaque fois).
--              Meme forme que `login_throttle`/`pin_throttle` (4.21 / migration
--              0002), dimension differente : deux bornes ORTHOGONALES (l'adresse
--              email demandee ET l'IP source), distinguees par `throttle_kind`
--              pour tenir dans UNE seule table plutot que deux (meme motif que
--              `role_visible_source`, discriminant + identifiant generique). PAS
--              de FK (l'identifiant peut etre une adresse email qui ne resout vers
--              AUCUN compte -- l'anti-enumeration exige de throttler une adresse
--              inconnue exactement comme une adresse connue, cf. RG-1 12.3).
--
-- Idempotence : colonne ajoutee seulement si absente (meme garde
--               information_schema que 0003/0006/0007/0011/0017) ; table creee
--               seulement si absente (CREATE TABLE IF NOT EXISTS, comme 0002).
--               Un rejeu ne modifie pas le schema resultant.
-- Target  : MariaDB 11.4 LTS, InnoDB, utf8mb4 / utf8mb4_unicode_ci.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'user' AND column_name = 'session_epoch'
);

SET @ddl := IF(
    @col_exists = 0,
    'ALTER TABLE user
        ADD COLUMN session_epoch INT UNSIGNED NOT NULL DEFAULT 0 AFTER password_reset_expires_at',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS password_reset_throttle (
    id                INT UNSIGNED       NOT NULL AUTO_INCREMENT,
    throttle_kind     ENUM('email','ip') NOT NULL,
    identifier        VARCHAR(254)       NOT NULL,
    failed_attempts   SMALLINT UNSIGNED  NOT NULL DEFAULT 0,
    window_started_at DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lockout_until     DATETIME               NULL,
    last_attempt_at   DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_password_reset_throttle_kind_identifier (throttle_kind, identifier),
    KEY idx_password_reset_throttle_lockout_until (lockout_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note : pas de seed, pas de FK (cf. purpose ci-dessus). Purge cron a aligner sur
-- login_throttle/pin_throttle (THROTTLE_PURGE_AFTER_HOURS) :
--   DELETE FROM password_reset_throttle
--   WHERE (lockout_until IS NULL OR lockout_until < NOW())
--     AND last_attempt_at < NOW() - INTERVAL <THROTTLE_PURGE_AFTER_HOURS> HOUR;
