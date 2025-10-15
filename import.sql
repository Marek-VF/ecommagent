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
