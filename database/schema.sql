CREATE DATABASE IF NOT EXISTS leren CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE leren;
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS attempt_answers;
DROP TABLE IF EXISTS attempts;
DROP TABLE IF EXISTS question_options;
DROP TABLE IF EXISTS questions;
DROP TABLE IF EXISTS tests;
DROP TABLE IF EXISTS topics;
DROP TABLE IF EXISTS subjects;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE users (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('admin','student') NOT NULL DEFAULT 'student',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subjects (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(150) NOT NULL UNIQUE,
 description TEXT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE topics (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 subject_id INT UNSIGNED NOT NULL,
 name VARCHAR(150) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_topics_subject_name(subject_id,name),
 CONSTRAINT fk_topics_subject FOREIGN KEY(subject_id) REFERENCES subjects(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tests (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 topic_id INT UNSIGNED NULL,
 title VARCHAR(200) NOT NULL,
 description TEXT NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_tests_topic(topic_id),
 CONSTRAINT fk_tests_topic FOREIGN KEY(topic_id) REFERENCES topics(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE questions (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 test_id INT UNSIGNED NOT NULL,
 question_text TEXT NOT NULL,
 explanation TEXT NULL,
 sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_questions_test_order(test_id,sort_order),
 CONSTRAINT fk_questions_test FOREIGN KEY(test_id) REFERENCES tests(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE question_options (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 question_id INT UNSIGNED NOT NULL,
 option_text VARCHAR(1000) NOT NULL,
 is_correct TINYINT(1) NOT NULL DEFAULT 0,
 sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 KEY idx_options_question_order(question_id,sort_order),
 CONSTRAINT fk_options_question FOREIGN KEY(question_id) REFERENCES questions(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 test_id INT UNSIGNED NOT NULL,
 student_id INT UNSIGNED NULL,
 score DECIMAL(5,2) NULL,
 started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 finished_at DATETIME NULL,
 KEY idx_attempts_test(test_id),
 KEY idx_attempts_student(student_id),
 CONSTRAINT fk_attempts_test FOREIGN KEY(test_id) REFERENCES tests(id) ON DELETE CASCADE ON UPDATE CASCADE,
 CONSTRAINT fk_attempts_student FOREIGN KEY(student_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attempt_answers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 attempt_id BIGINT UNSIGNED NOT NULL,
 question_id INT UNSIGNED NOT NULL,
 selected_option_id INT UNSIGNED NULL,
 is_correct TINYINT(1) NULL,
 answered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_attempt_question(attempt_id,question_id),
 KEY idx_attempt_answers_question(question_id),
 KEY idx_attempt_answers_option(selected_option_id),
 CONSTRAINT fk_attempt_answers_attempt FOREIGN KEY(attempt_id) REFERENCES attempts(id) ON DELETE CASCADE ON UPDATE CASCADE,
 CONSTRAINT fk_attempt_answers_question FOREIGN KEY(question_id) REFERENCES questions(id) ON DELETE CASCADE ON UPDATE CASCADE,
 CONSTRAINT fk_attempt_answers_option FOREIGN KEY(selected_option_id) REFERENCES question_options(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO subjects(name,description) VALUES ('Geschiedenis','Voorbeeldvak');
INSERT INTO topics(subject_id,name) SELECT id,'Oefenen' FROM subjects WHERE name='Geschiedenis' LIMIT 1;
INSERT INTO tests(topic_id,title,description) SELECT id,'Voorbeeldtoets','Eerste test om de installatie te controleren.' FROM topics WHERE name='Oefenen' LIMIT 1;
INSERT INTO questions(test_id,question_text,sort_order) SELECT id,'Welke kleur heeft gras meestal?',1 FROM tests WHERE title='Voorbeeldtoets' LIMIT 1;
INSERT INTO question_options(question_id,option_text,is_correct,sort_order) SELECT id,'Groen',1,1 FROM questions WHERE question_text='Welke kleur heeft gras meestal?' LIMIT 1;
INSERT INTO question_options(question_id,option_text,is_correct,sort_order) SELECT id,'Paars',0,2 FROM questions WHERE question_text='Welke kleur heeft gras meestal?' LIMIT 1;
INSERT INTO question_options(question_id,option_text,is_correct,sort_order) SELECT id,'Zwart',0,3 FROM questions WHERE question_text='Welke kleur heeft gras meestal?' LIMIT 1;
INSERT INTO question_options(question_id,option_text,is_correct,sort_order) SELECT id,'Oranje',0,4 FROM questions WHERE question_text='Welke kleur heeft gras meestal?' LIMIT 1;
