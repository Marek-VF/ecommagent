ALTER TABLE `status_logs_new`
    ADD KEY `idx_status_logs_new_user_created` (`user_id`, `created_at`),
    ADD KEY `idx_status_logs_new_user_id` (`user_id`, `id`);
