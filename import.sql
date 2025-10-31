-- Datenbankschema für Benutzer-Authentifizierungssystem
-- Datenbank: ecommagent

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `verification_token` VARCHAR(64) DEFAULT NULL,
  `verified` TINYINT(1) NOT NULL DEFAULT 0,
  `reset_token` VARCHAR(64) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indexe zur Performance-Optimierung (idempotent über INFORMATION_SCHEMA)
SET @db := DATABASE();

SET @idx_missing := (
  SELECT COUNT(*) = 0
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_email'
);
SET @sql := IF(@idx_missing, 'CREATE INDEX idx_email ON users(email)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_missing := (
  SELECT COUNT(*) = 0
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_verification_token'
);
SET @sql := IF(@idx_missing, 'CREATE INDEX idx_verification_token ON users(verification_token)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_missing := (
  SELECT COUNT(*) = 0
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_reset_token'
);
SET @sql := IF(@idx_missing, 'CREATE INDEX idx_reset_token ON users(reset_token)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ===================================================================
-- USER STATE  (aktueller Zustand je User)
-- ===================================================================
CREATE TABLE IF NOT EXISTS user_state (
  user_id INT UNSIGNED NOT NULL,
  last_status ENUM('ok','warn','error') NULL,
  last_message VARCHAR(255) NULL,
  last_image_url TEXT NULL,
  last_payload_summary TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- user_state: last_status ----------
SET @col_missing := (
  SELECT COUNT(*) = 0
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_state' AND COLUMN_NAME = 'last_status'
);
SET @sql := IF(@col_missing, 'ALTER TABLE user_state ADD COLUMN last_status ENUM(''ok'',''warn'',''error'') NULL AFTER user_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- user_state: last_message ----------
SET @col_missing := (
  SELECT COUNT(*) = 0
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_state' AND COLUMN_NAME = 'last_message'
);
SET @sql := IF(@col_missing, 'ALTER TABLE user_state ADD COLUMN last_message VARCHAR(255) NULL AFTER last_status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- user_state: last_image_url ----------
SET @col_missing := (
  SELECT COUNT(*) = 0
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_state' AND COLUMN_NAME = 'last_image_url'
);
SET @sql := IF(@col_missing, 'ALTER TABLE user_state ADD COLUMN last_image_url TEXT NULL AFTER last_message', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- user_state: last_payload_summary ----------
SET @col_missing := (
  SELECT COUNT(*) = 0
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_state' AND COLUMN_NAME = 'last_payload_summary'
);
SET @sql := IF(@col_missing, 'ALTER TABLE user_state ADD COLUMN last_payload_summary TEXT NULL AFTER last_image_url', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- user_state: updated_at ----------
SET @col_missing := (
  SELECT COUNT(*) = 0
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_state' AND COLUMN_NAME = 'updated_at'
);
SET @sql := IF(
  @col_missing,
  'ALTER TABLE user_state ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER last_payload_summary',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ===================================================================
-- STATUS LOGS  (Historie eingehender Events)
-- ===================================================================
CREATE TABLE IF NOT EXISTS status_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  level ENUM('info','warn','error') NOT NULL DEFAULT 'info',
  status_code INT NULL,
  message VARCHAR(255) NOT NULL,
  payload_excerpt TEXT NULL,
  source VARCHAR(50) NOT NULL DEFAULT 'receiver',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- Indizes (nur anlegen, wenn nicht vorhanden)
-- ===================================================================

-- status_logs: (user_id, created_at)
SET @idx_missing := (
  SELECT COUNT(*) = 0
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'status_logs' AND INDEX_NAME = 'idx_logs_user_created'
);
SET @sql := IF(@idx_missing, 'CREATE INDEX idx_logs_user_created ON status_logs(user_id, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- status_logs: level
SET @idx_missing := (
  SELECT COUNT(*) = 0
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'status_logs' AND INDEX_NAME = 'idx_logs_level'
);
SET @sql := IF(@idx_missing, 'CREATE INDEX idx_logs_level ON status_logs(level)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- status_logs: source
SET @idx_missing := (
  SELECT COUNT(*) = 0
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'status_logs' AND INDEX_NAME = 'idx_logs_source'
);
SET @sql := IF(@idx_missing, 'CREATE INDEX idx_logs_source ON status_logs(source)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- user_state: Eindeutigkeit pro user absichern, falls alte Tabelle ohne PK existiert
SET @has_pk := (
  SELECT COUNT(*) > 0
  FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_state' AND CONSTRAINT_TYPE = 'PRIMARY KEY'
);
SET @idx_missing := (
  SELECT COUNT(*) = 0
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_state' AND INDEX_NAME = 'idx_user_state_user'
);
SET @sql := IF(@has_pk, 'SELECT 1', IF(@idx_missing, 'ALTER TABLE user_state ADD UNIQUE INDEX idx_user_state_user (user_id)', 'SELECT 1'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ===================================================================
-- Foreign Keys (nur anlegen, wenn nicht vorhanden)
-- ===================================================================

-- user_state → users
SET @fk_missing := (
  SELECT COUNT(*) = 0
  FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = @db AND CONSTRAINT_NAME = 'fk_user_state_user'
);
SET @sql := IF(
  @fk_missing,
  'ALTER TABLE user_state ADD CONSTRAINT fk_user_state_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- status_logs → users
SET @fk_missing := (
  SELECT COUNT(*) = 0
  FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = @db AND CONSTRAINT_NAME = 'fk_status_logs_user'
);
SET @sql := IF(
  @fk_missing,
  'ALTER TABLE status_logs ADD CONSTRAINT fk_status_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ===================================================================
-- Optional: Smoke-Tests lokal (keine Pflicht)
-- ===================================================================
INSERT INTO user_state (user_id, last_status, last_message)
SELECT 1, 'ok', 'Init'
WHERE EXISTS (SELECT 1 FROM users WHERE id = 1)
ON DUPLICATE KEY UPDATE
  last_status = VALUES(last_status),
  last_message = VALUES(last_message),
  updated_at = CURRENT_TIMESTAMP;

INSERT INTO status_logs (user_id, level, status_code, message, source)
SELECT 1, 'info', 200, 'DB-Basis angelegt', 'migration'
WHERE EXISTS (SELECT 1 FROM users WHERE id = 1);
