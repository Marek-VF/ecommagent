-- Step 1: introduce run_images table and migrate existing data
CREATE TABLE IF NOT EXISTS `run_images` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` INT UNSIGNED NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_run_images_run` (`run_id`),
  CONSTRAINT `fk_run_images_run`
    FOREIGN KEY (`run_id`) REFERENCES `workflow_runs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `run_images` (`run_id`, `file_path`, `original_name`)
SELECT wr.id,
       wr.original_image,
       NULL
  FROM `workflow_runs` wr
 WHERE wr.original_image IS NOT NULL
   AND TRIM(wr.original_image) <> ''
   AND NOT EXISTS (
         SELECT 1
           FROM `run_images` ri
          WHERE ri.run_id = wr.id
            AND ri.file_path = wr.original_image
       );
