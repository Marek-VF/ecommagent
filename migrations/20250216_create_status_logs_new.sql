-- Neues Statuslog-System: Tabelle für unveränderte Statusmeldungen pro Run
CREATE TABLE IF NOT EXISTS status_logs_new (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status_logs_new_run (run_id),
    KEY idx_status_logs_new_user (user_id),
    CONSTRAINT fk_status_logs_new_run FOREIGN KEY (run_id) REFERENCES workflow_runs (id) ON DELETE CASCADE,
    CONSTRAINT fk_status_logs_new_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
