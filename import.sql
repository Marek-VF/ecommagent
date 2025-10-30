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

-- Benutzer
CREATE TABLE IF NOT EXISTS users (
  id             BIGINT AUTO_INCREMENT PRIMARY KEY,
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
  user_id      BIGINT NOT NULL,
  token_hash   CHAR(64) NOT NULL,                -- SHA-256 des Tokens
  label        VARCHAR(120) NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  last_used_at DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_wt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_wt_user (user_id),
  INDEX idx_wt_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User-Einstellungen (z. B. individuelle Workflow-URL)
CREATE TABLE IF NOT EXISTS user_settings (
  user_id          BIGINT PRIMARY KEY,
  workflow_webhook VARCHAR(500) NULL,
  preferences_json JSON NULL,
  CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uploads je User (physische Dateien unter /uploads/{user_id}/)
CREATE TABLE IF NOT EXISTS uploads (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT NOT NULL,
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

-- Zustand je User (ersetzt data.json)
CREATE TABLE IF NOT EXISTS user_state (
  user_id    BIGINT PRIMARY KEY,
  state_json JSON NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_us_state_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Status-Log (ersetzt statuslog aus data.json)
CREATE TABLE IF NOT EXISTS status_logs (
  id        BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id   BIGINT NOT NULL,
  type      ENUM('success','warning','error','info') NOT NULL,
  message   TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_sl_user (user_id),
  INDEX idx_sl_type (type),
  INDEX idx_sl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

