USE leren;

ALTER TABLE topics
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER test_date;
