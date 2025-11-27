-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: db003363.mydbserver.com
-- Erstellungszeit: 26. Nov 2025 um 11:59
-- Server-Version: 8.4.6-6
-- PHP-Version: 8.4.13

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Datenbank: `usr_p689217_4`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `item_images`
--

CREATE TABLE `item_images` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `run_id` int UNSIGNED DEFAULT NULL,
  `note_id` int UNSIGNED NOT NULL,
  `url` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL,
  `position` tinyint UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `item_notes`
--

CREATE TABLE `item_notes` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `run_id` int UNSIGNED DEFAULT NULL,
  `product_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_description` mediumtext COLLATE utf8mb4_unicode_ci,
  `source` enum('n8n','user') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'n8n',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `prompt_categories`
--

CREATE TABLE `prompt_categories` (
  `id` int UNSIGNED NOT NULL,
  `category_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `prompt_variants`
--

CREATE TABLE `prompt_variants` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `category_id` int UNSIGNED NOT NULL,
  `variant_slot` tinyint UNSIGNED NOT NULL,
  `location` text COLLATE utf8mb4_unicode_ci,
  `lighting` text COLLATE utf8mb4_unicode_ci,
  `mood` text COLLATE utf8mb4_unicode_ci,
  `season` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model_pose` text COLLATE utf8mb4_unicode_ci,
  `view_mode` enum('full_body','garment_closeup') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'full_body',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `run_images`
--

CREATE TABLE `run_images` (
  `id` int UNSIGNED NOT NULL,
  `run_id` int UNSIGNED NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `status_logs`
--

CREATE TABLE `status_logs` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `run_id` int UNSIGNED DEFAULT NULL,
  `level` enum('info','warn','error') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
  `status_code` int DEFAULT NULL,
  `message` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_excerpt` text COLLATE utf8mb4_unicode_ci,
  `source` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'receiver',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `users`
--

CREATE TABLE `users` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_ratio_preference` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'original',
  `prompt_category_id` int UNSIGNED DEFAULT NULL,
  `credits_balance` decimal(10,3) NOT NULL DEFAULT 0.000,
  `verification_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verified` tinyint(1) NOT NULL DEFAULT '0',
  `reset_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `user_state`
--

CREATE TABLE `user_state` (
  `user_id` int UNSIGNED NOT NULL,
  `last_status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'idle',
  `last_message` text COLLATE utf8mb4_unicode_ci,
  `last_image_url` text COLLATE utf8mb4_unicode_ci,
  `last_payload_summary` text COLLATE utf8mb4_unicode_ci,
  `current_run_id` int UNSIGNED DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `workflow_runs`
--

CREATE TABLE `workflow_runs` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` datetime DEFAULT NULL,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `last_message` text COLLATE utf8mb4_unicode_ci,
  `credits_spent` decimal(10,3) NOT NULL DEFAULT 0.000,
  `original_image` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `credit_transactions`
--

CREATE TABLE `credit_transactions` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `run_id` int UNSIGNED DEFAULT NULL,
  `amount` decimal(10,3) NOT NULL,
  `reason` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `meta` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_credit_transactions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_credit_transactions_run` FOREIGN KEY (`run_id`) REFERENCES `workflow_runs` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indizes der exportierten Tabellen
--

--
-- Indizes für die Tabelle `item_images`
--
ALTER TABLE `item_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_images_note_pos` (`note_id`,`position`),
  ADD KEY `idx_images_user_created` (`user_id`,`created_at`);

--
-- Indizes für die Tabelle `item_notes`
--
ALTER TABLE `item_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notes_user_created` (`user_id`,`created_at`);

--
-- Indizes für die Tabelle `prompt_categories`
--
ALTER TABLE `prompt_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `category_key` (`category_key`);

--
-- Indizes für die Tabelle `prompt_variants`
--
ALTER TABLE `prompt_variants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_category_slot` (`user_id`,`category_id`,`variant_slot`),
  ADD KEY `fk_prompt_variants_category` (`category_id`);

--
-- Indizes für die Tabelle `run_images`
--
ALTER TABLE `run_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_run_images_run` (`run_id`);

--
-- Indizes für die Tabelle `status_logs`
--
ALTER TABLE `status_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_logs_user_created` (`user_id`,`created_at`),
  ADD KEY `idx_logs_level` (`level`),
  ADD KEY `idx_logs_source` (`source`);

--
-- Indizes für die Tabelle `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_verification_token` (`verification_token`),
  ADD KEY `idx_reset_token` (`reset_token`),
  ADD KEY `fk_users_prompt_category` (`prompt_category_id`);

--
-- Indizes für die Tabelle `user_state`
--
ALTER TABLE `user_state`
  ADD PRIMARY KEY (`user_id`);

--
-- Indizes für die Tabelle `workflow_runs`
--
ALTER TABLE `workflow_runs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_workflow_runs_user` (`user_id`),
  ADD KEY `idx_workflow_runs_status` (`status`);

--
-- AUTO_INCREMENT für exportierte Tabellen
--

--
-- AUTO_INCREMENT für Tabelle `item_images`
--
ALTER TABLE `item_images`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `item_notes`
--
ALTER TABLE `item_notes`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `prompt_categories`
--
ALTER TABLE `prompt_categories`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `prompt_variants`
--
ALTER TABLE `prompt_variants`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `run_images`
--
ALTER TABLE `run_images`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `status_logs`
--
ALTER TABLE `status_logs`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `users`
--
ALTER TABLE `users`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `workflow_runs`
--
ALTER TABLE `workflow_runs`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints der exportierten Tabellen
--

--
-- Constraints der Tabelle `item_images`
--
ALTER TABLE `item_images`
  ADD CONSTRAINT `fk_item_images_note` FOREIGN KEY (`note_id`) REFERENCES `item_notes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_item_images_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `item_notes`
--
ALTER TABLE `item_notes`
  ADD CONSTRAINT `fk_item_notes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `prompt_variants`
--
ALTER TABLE `prompt_variants`
  ADD CONSTRAINT `fk_prompt_variants_category` FOREIGN KEY (`category_id`) REFERENCES `prompt_categories` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_prompt_variants_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `run_images`
--
ALTER TABLE `run_images`
  ADD CONSTRAINT `fk_run_images_run` FOREIGN KEY (`run_id`) REFERENCES `workflow_runs` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `status_logs`
--
ALTER TABLE `status_logs`
  ADD CONSTRAINT `fk_status_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_prompt_category` FOREIGN KEY (`prompt_category_id`) REFERENCES `prompt_categories` (`id`) ON DELETE SET NULL;

--
-- Constraints der Tabelle `user_state`
--
ALTER TABLE `user_state`
  ADD CONSTRAINT `fk_user_state_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `workflow_runs`
--
ALTER TABLE `workflow_runs`
  ADD CONSTRAINT `fk_workflow_runs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
