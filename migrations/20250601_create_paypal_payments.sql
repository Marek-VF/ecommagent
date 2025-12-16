CREATE TABLE paypal_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  package_id VARCHAR(64) NOT NULL,
  paypal_order_id VARCHAR(64) NOT NULL,
  paypal_capture_id VARCHAR(64) NULL,
  amount DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL,
  credits DECIMAL(10,3) NOT NULL,
  status VARCHAR(30) NOT NULL,
  credit_transaction_id INT UNSIGNED NULL,
  raw_payload MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_paypal_payments_user FOREIGN KEY (user_id) REFERENCES users(id),
  UNIQUE KEY uq_paypal_order (paypal_order_id),
  UNIQUE KEY uq_paypal_capture (paypal_capture_id),
  KEY idx_paypal_payments_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
