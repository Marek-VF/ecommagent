CREATE TABLE `options` (
  `opt_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `opt_value` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`opt_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `options` (`opt_key`, `opt_value`)
VALUES
  ('pricing.factor', '1'),
  ('pricing.credits_per_eur', '1');
