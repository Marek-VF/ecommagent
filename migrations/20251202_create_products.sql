CREATE TABLE `products` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('step','package') COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `credits` decimal(10,3) NOT NULL DEFAULT '0.000',
  `price` decimal(10,2) DEFAULT NULL,
  `purchase_price` decimal(10,2) DEFAULT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `group_id` int UNSIGNED DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int UNSIGNED NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_products_key` (`product_key`),
  KEY `idx_products_type_active` (`type`, `active`),
  KEY `idx_products_group` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `products`
  (`product_key`, `type`, `label`, `credits`, `price`, `purchase_price`, `currency`, `group_id`, `active`, `sort_order`)
VALUES
  ('analysis', 'step', 'Analysis', 0.500, NULL, NULL, NULL, 1, 1, 10),
  ('image_1', 'step', 'Image 1', 0.750, NULL, NULL, NULL, 1, 1, 20),
  ('image_2', 'step', 'Image 2', 0.750, NULL, NULL, NULL, 1, 1, 30),
  ('image_3', 'step', 'Image 3', 0.750, NULL, NULL, NULL, 1, 1, 40),
  ('starter', 'package', '50 Credits', 50.000, 9.99, NULL, 'EUR', NULL, 1, 10),
  ('pro', 'package', '120 Credits', 120.000, 19.99, NULL, 'EUR', NULL, 1, 20),
  ('business', 'package', '260 Credits', 260.000, 39.99, NULL, 'EUR', NULL, 1, 30);
