USE leren;

ALTER TABLE tests
    ADD COLUMN test_type ENUM('vocabulary','multiple_choice','mixed') NOT NULL DEFAULT 'mixed' AFTER description;
