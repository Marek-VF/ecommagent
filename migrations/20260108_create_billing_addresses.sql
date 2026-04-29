-- Billing address storage per user
CREATE TABLE IF NOT EXISTS billing_addresses (
    user_id INT UNSIGNED NOT NULL,
    company_name VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    street VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    house_number VARCHAR(32) COLLATE utf8mb4_unicode_ci NOT NULL,
    postal_code VARCHAR(16) COLLATE utf8mb4_unicode_ci NOT NULL,
    city VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    vat_id VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_billing_addresses_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
