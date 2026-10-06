ALTER TABLE users
    ADD COLUMN image_mime VARCHAR(50) NULL AFTER email,
    ADD COLUMN image_data MEDIUMBLOB NULL AFTER image_mime;
