-- Reapplied schema bootstrap after previous empty import.sql commit
-- =========================================
-- USERS (Auth-Basis)
-- =========================================
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `image_ratio_preference` VARCHAR(50) NOT NULL DEFAULT 'original',
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
  `last_status` ENUM('ok','warn','error','running','finished','idle','pending') NULL,
  `last_message` VARCHAR(255) NULL,
  `last_image_url` TEXT NULL,
  `last_payload_summary` TEXT NULL,
  `current_run_id` INT UNSIGNED NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_user_state_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- WORKFLOW RUNS (Historie je Benutzer)
-- =========================================
CREATE TABLE IF NOT EXISTS `workflow_runs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` DATETIME NULL,
  `status` ENUM('pending','running','finished','error') NOT NULL DEFAULT 'pending',
  `last_message` TEXT NULL,
  `original_image` VARCHAR(1024) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_workflow_runs_user` (`user_id`),
  KEY `idx_workflow_runs_status` (`status`),
  CONSTRAINT `fk_workflow_runs_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- STATUS LOGS (Historie von Events)
-- =========================================
CREATE TABLE IF NOT EXISTS `status_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `run_id` INT UNSIGNED NULL,
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
  KEY `idx_logs_run` (`run_id`),
  CONSTRAINT `fk_status_logs_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_status_logs_run`
    FOREIGN KEY (`run_id`) REFERENCES `workflow_runs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- ITEM NOTES (Produktinformationen je Lauf)
-- =========================================
CREATE TABLE IF NOT EXISTS `item_notes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `run_id` INT UNSIGNED NULL,
  `product_name` VARCHAR(255) NULL,
  `product_description` MEDIUMTEXT NULL,
  `source` ENUM('n8n','user') NOT NULL DEFAULT 'n8n',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notes_user_created` (`user_id`,`created_at`),
  KEY `idx_notes_run` (`run_id`),
  CONSTRAINT `fk_item_notes_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_notes_run` FOREIGN KEY (`run_id`) REFERENCES `workflow_runs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- ITEM IMAGES (Bild-URLs zu einer Note)
-- =========================================
CREATE TABLE IF NOT EXISTS `item_images` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `run_id` INT UNSIGNED NULL,
  `note_id` INT UNSIGNED NULL,
  `url` VARCHAR(1024) NOT NULL,
  `position` TINYINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_images_note_pos` (`note_id`,`position`),
  KEY `idx_images_user_created` (`user_id`,`created_at`),
  KEY `idx_images_run_created` (`run_id`,`created_at`),
  CONSTRAINT `fk_item_images_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_images_run` FOREIGN KEY (`run_id`) REFERENCES `workflow_runs`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_item_images_note` FOREIGN KEY (`note_id`) REFERENCES `item_notes`(`id`) ON DELETE SET NULL
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

-- =========================================
-- ALTERS (Bestandsdatenbanken aktualisieren)
-- =========================================
ALTER TABLE `item_notes`
  ADD COLUMN IF NOT EXISTS `run_id` INT UNSIGNED NULL AFTER `user_id`;

ALTER TABLE `item_images`
  ADD COLUMN IF NOT EXISTS `run_id` INT UNSIGNED NULL AFTER `user_id`;

ALTER TABLE `status_logs`
  ADD COLUMN IF NOT EXISTS `run_id` INT UNSIGNED NULL AFTER `user_id`;

ALTER TABLE `workflow_runs`
  ADD COLUMN IF NOT EXISTS `original_image` VARCHAR(1024) NULL AFTER `last_message`;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `image_ratio_preference` VARCHAR(50) NOT NULL DEFAULT 'original' AFTER `password_hash`;
