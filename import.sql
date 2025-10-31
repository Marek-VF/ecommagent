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

-- Indexe zur Performance-Optimierung
CREATE INDEX idx_email ON users(email);
CREATE INDEX idx_verification_token ON users(verification_token);
CREATE INDEX idx_reset_token ON users(reset_token);

-- ======================================================
-- USER STATE  (aktueller Zustand je User)
-- ======================================================
CREATE TABLE IF NOT EXISTS user_state (
  user_id INT UNSIGNED NOT NULL,
  last_status ENUM('ok','warn','error') NULL,
  last_message VARCHAR(255) NULL,
  last_image_url TEXT NULL,
  last_payload_summary TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_user_state_user FOREIGN KEY (user_id)
    REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Falls Spalten fehlen (MySQL 8.4 unterstützt kein IF NOT EXISTS bei ADD COLUMN)
-- → nur ausführen, wenn sie fehlen:
SET @dbname := DATABASE();

SET @stmt := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'user_state' AND COLUMN_NAME = 'last_status'
    ),
    'SELECT 1',
    'ALTER TABLE user_state ADD COLUMN last_status ENUM(\'ok\',\'warn\',\'error\') NULL AFTER user_id'
  )
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @stmt := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'user_state' AND COLUMN_NAME = 'last_message'
    ),
    'SELECT 1',
    'ALTER TABLE user_state ADD COLUMN last_message VARCHAR(255) NULL AFTER last_status'
  )
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @stmt := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'user_state' AND COLUMN_NAME = 'last_image_url'
    ),
    'SELECT 1',
    'ALTER TABLE user_state ADD COLUMN last_image_url TEXT NULL AFTER last_message'
  )
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @stmt := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'user_state' AND COLUMN_NAME = 'last_payload_summary'
    ),
    'SELECT 1',
    'ALTER TABLE user_state ADD COLUMN last_payload_summary TEXT NULL AFTER last_image_url'
  )
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @stmt := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'user_state' AND COLUMN_NAME = 'updated_at'
    ),
    'SELECT 1',
    'ALTER TABLE user_state ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER last_payload_summary'
  )
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Eindeutiger Index absichern
CREATE UNIQUE INDEX IF NOT EXISTS idx_user_state_user ON user_state(user_id);

-- ======================================================
-- STATUS LOGS  (Historie eingehender Events)
-- ======================================================
CREATE TABLE IF NOT EXISTS status_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  level ENUM('info','warn','error') NOT NULL DEFAULT 'info',
  status_code INT NULL,
  message VARCHAR(255) NOT NULL,
  payload_excerpt TEXT NULL,
  source VARCHAR(50) NOT NULL DEFAULT 'receiver',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_status_logs_user FOREIGN KEY (user_id)
    REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS idx_logs_user_created ON status_logs(user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_logs_level ON status_logs(level);
CREATE INDEX IF NOT EXISTS idx_logs_source ON status_logs(source);

-- ======================================================
-- SMOKE-TESTS (lokal optional)
-- ======================================================
INSERT INTO user_state (user_id, last_status, last_message)
VALUES (1, 'ok', 'Init')
ON DUPLICATE KEY UPDATE
  last_status = VALUES(last_status),
  last_message = VALUES(last_message),
  updated_at = CURRENT_TIMESTAMP;

INSERT INTO status_logs (user_id, level, status_code, message, source)
SELECT 1, 'info', 200, 'DB-Basis angelegt', 'migration'
WHERE EXISTS (SELECT 1 FROM users WHERE id = 1);
