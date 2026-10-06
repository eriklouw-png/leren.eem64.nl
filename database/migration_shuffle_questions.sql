USE leren;

ALTER TABLE tests
    ADD COLUMN shuffle_questions TINYINT(1) NOT NULL DEFAULT 1 AFTER vocab_direction;
