USE leren;

ALTER TABLE tests ADD UNIQUE KEY uq_tests_topic_title(topic_id,title);

ALTER TABLE questions
    ADD COLUMN question_type ENUM('multiple_choice','open') NOT NULL DEFAULT 'multiple_choice' AFTER question_text;

CREATE TABLE IF NOT EXISTS open_question_answers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id INT UNSIGNED NOT NULL,
    answer_text VARCHAR(1000) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    KEY idx_open_answers_question(question_id),
    CONSTRAINT fk_open_answers_question FOREIGN KEY(question_id)
        REFERENCES questions(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE attempt_answers
    ADD COLUMN answer_text TEXT NULL AFTER selected_option_id;
