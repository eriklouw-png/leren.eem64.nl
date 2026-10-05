USE leren;

ALTER TABLE subjects
    ADD COLUMN image_path VARCHAR(255) NULL AFTER description;
