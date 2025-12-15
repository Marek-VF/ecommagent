ALTER TABLE item_images
    ADD COLUMN error_message TEXT NULL;

ALTER TABLE item_images_staging
    ADD COLUMN error_message TEXT NULL;
