CREATE TABLE `credit_packages` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `package_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `credits` decimal(10,3) NOT NULL DEFAULT '0.000',
  `price` decimal(10,2) NOT NULL,
  `purchase_price` decimal(10,2) DEFAULT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'EUR',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int UNSIGNED NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_credit_packages_key` (`package_key`),
  KEY `idx_credit_packages_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `credit_packages`
  (`package_key`, `label`, `credits`, `price`, `purchase_price`, `currency`, `active`, `sort_order`)
VALUES
  ('starter', '50 Credits', 50.000, 9.99, NULL, 'EUR', 1, 10),
  ('pro', '120 Credits', 120.000, 19.99, NULL, 'EUR', 1, 20),
  ('business', '260 Credits', 260.000, 39.99, NULL, 'EUR', 1, 30);
