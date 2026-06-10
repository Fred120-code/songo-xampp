-- =============================================
-- SONGO — Schéma MySQL
-- Importer via phpMyAdmin ou :
--   mysql -u root -p < songo.sql
-- =============================================

CREATE DATABASE IF NOT EXISTS songo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE songo;

CREATE TABLE IF NOT EXISTS games (
    id            VARCHAR(36)  PRIMARY KEY,           -- UUID de la partie
    code          VARCHAR(6)   NOT NULL UNIQUE,        -- Code à partager (ex: AB12CD)
    board         TEXT         NOT NULL,               -- JSON : [[5,5,5,5,5,5,5],[5,5,5,5,5,5,5]]
    scores        TEXT         NOT NULL,               -- JSON : [0, 0]
    current_player TINYINT     NOT NULL DEFAULT 1,     -- 1=Sud, 2=Nord
    status        ENUM('waiting','playing','finished') NOT NULL DEFAULT 'waiting',
    result        TEXT         NULL,                   -- JSON : {winner, reason} ou NULL
    token_sud     VARCHAR(36)  NOT NULL,               -- Token UUID du joueur Sud
    token_nord    VARCHAR(36)  NULL,                   -- Token UUID du joueur Nord (null avant join)
    log           TEXT         NOT NULL,               -- JSON : tableau de messages
    highlight     TEXT         NULL,                   -- JSON : {last, captured}
    last_move_at  DATETIME     NULL,
    last_activity DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Index pour retrouver une partie par code
CREATE INDEX idx_code ON games(code);

-- Index pour le nettoyage automatique
CREATE INDEX idx_activity ON games(last_activity);
