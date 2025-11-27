CREATE TABLE credit_transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  run_id INT UNSIGNED NULL,
  amount DECIMAL(10,3) NOT NULL,
  reason VARCHAR(50) NOT NULL,
  meta TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_credit_transactions_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_credit_transactions_run FOREIGN KEY (run_id) REFERENCES workflow_runs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
