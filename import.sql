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

SET @schema_name := DATABASE();

-- Index idx_email nur anlegen, falls noch nicht vorhanden
SET @idx_exists := (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_email'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_email ON users(email);', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index idx_verification_token nur anlegen, falls noch nicht vorhanden
SET @idx_exists := (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_verification_token'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_verification_token ON users(verification_token);', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index idx_reset_token nur anlegen, falls noch nicht vorhanden
SET @idx_exists := (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_reset_token'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_reset_token ON users(reset_token);', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(120) NOT NULL,
  email          VARCHAR(190) NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NOT NULL,
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  role           ENUM('user','admin') NOT NULL DEFAULT 'user',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Webhook-/API-Tokens (nur Hash speichern!)
CREATE TABLE IF NOT EXISTS webhook_tokens (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  token_hash   BINARY(32) NOT NULL COMMENT 'SHA-256 hash of the token (binary storage)',
  label        VARCHAR(120) NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  last_used_at DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_wt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_wt_user (user_id),
  UNIQUE INDEX idx_webhook_tokens_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User-Einstellungen (z. B. individuelle Workflow-URL)
CREATE TABLE IF NOT EXISTS user_settings (
  user_id          INT UNSIGNED PRIMARY KEY,
  workflow_webhook VARCHAR(500) NULL,
  preferences_json JSON NULL,
  CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uploads je User (physische Dateien unter /uploads/{user_id}/)
CREATE TABLE IF NOT EXISTS uploads (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  stored_name  VARCHAR(255) NOT NULL,
  url          VARCHAR(500) NOT NULL,
  field_key    ENUM('image_1','image_2','image_3') NOT NULL,
  mime_type    VARCHAR(120) NULL,
  size_bytes   BIGINT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_up_user (user_id),
  INDEX idx_up_field (field_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_state (
  user_id INT UNSIGNED NOT NULL,
  last_status ENUM('ok','warn','error') NULL,
  last_message VARCHAR(255) NULL,
  last_image_url TEXT NULL,
  last_payload_summary TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_user_state_user FOREIGN KEY (user_id)
    REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE user_state
  ADD COLUMN IF NOT EXISTS last_status ENUM('ok','warn','error') NULL AFTER user_id;

ALTER TABLE user_state
  ADD COLUMN IF NOT EXISTS last_message VARCHAR(255) NULL AFTER last_status;

ALTER TABLE user_state
  ADD COLUMN IF NOT EXISTS last_image_url TEXT NULL AFTER last_message;

ALTER TABLE user_state
  ADD COLUMN IF NOT EXISTS last_payload_summary TEXT NULL AFTER last_image_url;

ALTER TABLE user_state
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER last_payload_summary;

ALTER TABLE user_state
  ADD UNIQUE KEY IF NOT EXISTS idx_user_state_user (user_id);

ALTER TABLE webhook_tokens
  MODIFY COLUMN token_hash BINARY(32) NOT NULL;

ALTER TABLE webhook_tokens
  ADD UNIQUE KEY IF NOT EXISTS idx_webhook_tokens_token_hash (token_hash);

SET @table_exists := (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'user_state'
);

SET @col_exists := (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'user_state' AND COLUMN_NAME = 'state_json'
);
SET @sql := IF(@table_exists = 0 OR @col_exists = 0, 'SELECT 1', 'ALTER TABLE user_state DROP COLUMN state_json');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Status-Log (ersetzt statuslog aus data.json)
CREATE TABLE IF NOT EXISTS status_logs (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  level           ENUM('info','warn','error') NOT NULL,
  status_code     INT NOT NULL,
  message         VARCHAR(500) NOT NULL,
  payload_excerpt VARCHAR(800) NULL,
  source          VARCHAR(50) NOT NULL DEFAULT 'receiver',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_sl_user_created (user_id, created_at),
  INDEX idx_sl_level (level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Align legacy status_logs tables with the new structure
SET @table_exists := (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'status_logs'
);

SET @has_level := (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'status_logs' AND COLUMN_NAME = 'level'
);
SET @has_type := (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'status_logs' AND COLUMN_NAME = 'type'
);
SET @sql := IF(@table_exists = 0 OR @has_level = 1, 'SELECT 1',
  IF(@has_type = 1,
     'ALTER TABLE status_logs CHANGE type level ENUM(''info'',''warn'',''error'') NOT NULL',
     'ALTER TABLE status_logs ADD COLUMN level ENUM(''info'',''warn'',''error'') NOT NULL AFTER user_id'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(@table_exists = 0, 'SELECT 1',
  'ALTER TABLE status_logs MODIFY level ENUM(''info'',''warn'',''error'') NOT NULL');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'status_logs' AND COLUMN_NAME = 'status_code'
);
SET @sql := IF(@table_exists = 0 OR @col_exists = 1, 'SELECT 1',
  'ALTER TABLE status_logs ADD COLUMN status_code INT NOT NULL AFTER level');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(@table_exists = 0, 'SELECT 1',
  'ALTER TABLE status_logs MODIFY status_code INT NOT NULL');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(@table_exists = 0, 'SELECT 1',
  'ALTER TABLE status_logs MODIFY message VARCHAR(500) NOT NULL');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'status_logs' AND COLUMN_NAME = 'payload_excerpt'
);
SET @sql := IF(@table_exists = 0 OR @col_exists = 1, 'SELECT 1',
  'ALTER TABLE status_logs ADD COLUMN payload_excerpt VARCHAR(800) NULL AFTER message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'status_logs' AND COLUMN_NAME = 'source'
);
SET @sql := IF(@table_exists = 0 OR @col_exists = 1, 'SELECT 1',
  'ALTER TABLE status_logs ADD COLUMN source VARCHAR(50) NOT NULL DEFAULT ''receiver'' AFTER payload_excerpt');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(@table_exists = 0, 'SELECT 1',
  'ALTER TABLE status_logs MODIFY source VARCHAR(50) NOT NULL DEFAULT ''receiver''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'status_logs' AND INDEX_NAME = 'idx_sl_user_created'
);
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE status_logs ADD INDEX idx_sl_user_created (user_id, created_at);', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'status_logs' AND INDEX_NAME = 'idx_sl_level'
);
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE status_logs ADD INDEX idx_sl_level (level);', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @old_idx_exists := (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'status_logs' AND INDEX_NAME = 'idx_sl_type'
);
SET @sql := IF(@old_idx_exists = 0, 'SELECT 1', 'ALTER TABLE status_logs DROP INDEX idx_sl_type');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

