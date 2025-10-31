-- Reapplied schema bootstrap after previous empty import.sql commit
-- =========================================
-- USERS (Auth-Basis)
-- =========================================
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
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_verification_token` (`verification_token`),
  KEY `idx_reset_token` (`reset_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- USER STATE (aktueller Zustand je User)
-- =========================================
CREATE TABLE IF NOT EXISTS `user_state` (
  `user_id` INT UNSIGNED NOT NULL,
  `last_status` ENUM('ok','warn','error') NULL,
  `last_message` VARCHAR(255) NULL,
  `last_image_url` TEXT NULL,
  `last_payload_summary` TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_user_state_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- STATUS LOGS (Historie von Events)
-- =========================================
CREATE TABLE IF NOT EXISTS `status_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `level` ENUM('info','warn','error') NOT NULL DEFAULT 'info',
  `status_code` INT NULL,
  `message` VARCHAR(255) NOT NULL,
  `payload_excerpt` TEXT NULL,
  `source` VARCHAR(50) NOT NULL DEFAULT 'receiver',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_logs_user_created` (`user_id`,`created_at`),
  KEY `idx_logs_level` (`level`),
  KEY `idx_logs_source` (`source`),
  CONSTRAINT `fk_status_logs_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- (Optional) Minimaler Smoke-Test – nur ausführen, wenn user_id=1 existiert
-- =========================================
INSERT INTO `user_state` (`user_id`,`last_status`,`last_message`)
SELECT 1, 'ok', 'Init'
WHERE EXISTS (SELECT 1 FROM `users` WHERE `id`=1)
ON DUPLICATE KEY UPDATE
  `last_status`=VALUES(`last_status`),
  `last_message`=VALUES(`last_message`),
  `updated_at`=CURRENT_TIMESTAMP;

INSERT INTO `status_logs` (`user_id`,`level`,`status_code`,`message`,`source`)
SELECT 1, 'info', 200, 'DB-Basis angelegt', 'migration'
WHERE EXISTS (SELECT 1 FROM `users` WHERE `id`=1);
