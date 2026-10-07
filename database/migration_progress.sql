USE leren;

ALTER TABLE attempts
    ADD COLUMN status ENUM('in_progress','finished') NOT NULL DEFAULT 'in_progress' AFTER student_id,
    ADD COLUMN mode ENUM('normal','mistakes') NOT NULL DEFAULT 'normal' AFTER status,
    ADD COLUMN source_attempt_id BIGINT UNSIGNED NULL AFTER mode,
    ADD COLUMN browser_token CHAR(64) NULL AFTER source_attempt_id,
    ADD KEY idx_attempts_browser_status(browser_token,status),
    ADD KEY idx_attempts_source(source_attempt_id);

UPDATE attempts SET status='finished' WHERE finished_at IS NOT NULL;

ALTER TABLE attempts
    ADD CONSTRAINT fk_attempts_source FOREIGN KEY(source_attempt_id)
    REFERENCES attempts(id) ON DELETE SET NULL ON UPDATE CASCADE;

CREATE TABLE IF NOT EXISTS attempt_questions (
    attempt_id BIGINT UNSIGNED NOT NULL,
    question_id INT UNSIGNED NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY(attempt_id,question_id),
    KEY idx_attempt_questions_question(question_id),
    CONSTRAINT fk_attempt_questions_attempt FOREIGN KEY(attempt_id)
        REFERENCES attempts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_attempt_questions_question FOREIGN KEY(question_id)
        REFERENCES questions(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO attempt_questions(attempt_id,question_id,sort_order)
SELECT a.id,q.id,q.sort_order
FROM attempts a
JOIN questions q ON q.test_id=a.test_id
LEFT JOIN attempt_questions aq ON aq.attempt_id=a.id AND aq.question_id=q.id
WHERE aq.attempt_id IS NULL;


-- Zinnen oefenen als apart sub-testtype
ALTER TABLE tests MODIFY COLUMN test_type ENUM('vocabulary','sentences','multiple_choice','open','mixed') NOT NULL DEFAULT 'mixed';
