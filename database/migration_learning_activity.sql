USE leren;

ALTER TABLE study_sessions
    MODIFY COLUMN test_id INT UNSIGNED NULL,
    ADD COLUMN student_id INT UNSIGNED NULL AFTER session_token,
    ADD COLUMN activity_type ENUM('test','summary') NOT NULL DEFAULT 'test' AFTER test_id,
    ADD COLUMN summary_id INT UNSIGNED NULL AFTER activity_type,
    ADD KEY idx_study_sessions_student(student_id),
    ADD KEY idx_study_sessions_summary(summary_id),
    ADD KEY idx_study_sessions_student_activity(student_id,activity_type);

UPDATE study_sessions ss
JOIN attempts a ON a.id=ss.attempt_id
SET ss.student_id=a.student_id
WHERE ss.student_id IS NULL;

ALTER TABLE study_sessions
    ADD CONSTRAINT fk_study_sessions_student FOREIGN KEY(student_id)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    ADD CONSTRAINT fk_study_sessions_summary FOREIGN KEY(summary_id)
        REFERENCES topic_summaries(id) ON DELETE CASCADE ON UPDATE CASCADE;
