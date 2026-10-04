USE leren;

CREATE TABLE IF NOT EXISTS study_sessions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 session_token CHAR(64) NOT NULL UNIQUE,
 test_id INT UNSIGNED NOT NULL,
 attempt_id BIGINT UNSIGNED NULL,
 started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 active_seconds INT UNSIGNED NOT NULL DEFAULT 0,
 ended_at DATETIME NULL,
 KEY idx_study_sessions_started(started_at),
 KEY idx_study_sessions_test(test_id),
 KEY idx_study_sessions_attempt(attempt_id),
 CONSTRAINT fk_study_sessions_test FOREIGN KEY(test_id) REFERENCES tests(id) ON DELETE CASCADE ON UPDATE CASCADE,
 CONSTRAINT fk_study_sessions_attempt FOREIGN KEY(attempt_id) REFERENCES attempts(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
