USE leren;

ALTER TABLE tests
    ADD COLUMN vocab_left_label VARCHAR(80) NULL AFTER test_type,
    ADD COLUMN vocab_right_label VARCHAR(80) NULL AFTER vocab_left_label,
    ADD COLUMN vocab_direction ENUM('both','left_to_right','right_to_left') NOT NULL DEFAULT 'both' AFTER vocab_right_label;
