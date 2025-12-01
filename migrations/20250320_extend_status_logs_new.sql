ALTER TABLE `status_logs_new`
    ADD COLUMN `source` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'n8n' AFTER `message`,
    ADD COLUMN `severity` enum('info','success','warning','error') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info' AFTER `source`,
    ADD COLUMN `code` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `severity`;
