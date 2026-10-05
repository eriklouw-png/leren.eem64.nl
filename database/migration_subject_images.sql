USE leren;

ALTER TABLE subjects
    ADD COLUMN image_mime VARCHAR(50) NULL AFTER description,
    ADD COLUMN image_data MEDIUMBLOB NULL AFTER image_mime;
