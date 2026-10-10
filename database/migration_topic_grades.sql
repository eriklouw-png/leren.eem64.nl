-- Eenmalig uitvoeren op de bestaande database 'leren'.
CREATE TABLE IF NOT EXISTS topic_grades (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 topic_id INT UNSIGNED NOT NULL,
 student_id INT UNSIGNED NOT NULL,
 grade DECIMAL(3,1) NOT NULL,
 recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_topic_grades_student_topic (student_id,topic_id),
 CONSTRAINT fk_topic_grades_topic FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE,
 CONSTRAINT fk_topic_grades_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
