USE leren;

ALTER TABLE tests
    MODIFY COLUMN test_type ENUM('vocabulary','multiple_choice','open','mixed') NOT NULL DEFAULT 'mixed';
