CREATE DATABASE IF NOT EXISTS leren CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE leren;
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS study_sessions;
DROP TABLE IF EXISTS topic_summaries;
DROP TABLE IF EXISTS attempt_answers;
DROP TABLE IF EXISTS open_question_answers;
DROP TABLE IF EXISTS attempts;
DROP TABLE IF EXISTS question_options;
DROP TABLE IF EXISTS questions;
DROP TABLE IF EXISTS tests;
DROP TABLE IF EXISTS topics;
DROP TABLE IF EXISTS subjects;
DROP TABLE IF EXISTS manager_students;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE users (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 image_mime VARCHAR(50) NULL,
 image_data MEDIUMBLOB NULL,
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('admin','beheerder','student') NOT NULL DEFAULT 'student',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE manager_students (
 manager_id INT UNSIGNED NOT NULL,
 student_id INT UNSIGNED NOT NULL,
 PRIMARY KEY (manager_id,student_id),
 KEY idx_manager_students_student(student_id),
 CONSTRAINT fk_manager_students_manager FOREIGN KEY(manager_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
 CONSTRAINT fk_manager_students_student FOREIGN KEY(student_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subjects (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(150) NOT NULL UNIQUE,
 image_mime VARCHAR(50) NULL,
 image_data MEDIUMBLOB NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE topics (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 subject_id INT UNSIGNED NOT NULL,
 name VARCHAR(150) NOT NULL,
 test_date DATE NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 use_summary TINYINT(1) NOT NULL DEFAULT 0,
 summary TEXT NULL,
 summary_updated_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_topics_subject_name(subject_id,name),
 CONSTRAINT fk_topics_subject FOREIGN KEY(subject_id) REFERENCES subjects(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE topic_summaries (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 topic_id INT UNSIGNED NOT NULL,
 name VARCHAR(200) NOT NULL,
 summary TEXT NOT NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL,
 deleted_at DATETIME NULL,
 KEY idx_topic_summaries_topic(topic_id),
 KEY idx_topic_summaries_active(topic_id,is_active),
 CONSTRAINT fk_topic_summaries_topic FOREIGN KEY(topic_id) REFERENCES topics(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tests (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 topic_id INT UNSIGNED NULL,
 title VARCHAR(200) NOT NULL,
 description TEXT NULL,
 test_type ENUM('vocabulary','multiple_choice','open','mixed') NOT NULL DEFAULT 'mixed',
 vocab_left_label VARCHAR(80) NULL,
 vocab_right_label VARCHAR(80) NULL,
 vocab_direction ENUM('both','left_to_right','right_to_left') NOT NULL DEFAULT 'both',
 shuffle_questions TINYINT(1) NOT NULL DEFAULT 1,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_tests_topic(topic_id),
 UNIQUE KEY uq_tests_topic_title(topic_id,title),
 CONSTRAINT fk_tests_topic FOREIGN KEY(topic_id) REFERENCES topics(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE questions (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 test_id INT UNSIGNED NOT NULL,
 question_text TEXT NOT NULL,
 image_path VARCHAR(500) NULL,
 question_type ENUM('multiple_choice','open') NOT NULL DEFAULT 'multiple_choice',
 vocab_direction ENUM('left_to_right','right_to_left') NULL,
 explanation TEXT NULL,
 grammar_label VARCHAR(255) NULL,
 sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_questions_test_order(test_id,sort_order),
 KEY idx_questions_type(question_type),
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

CREATE TABLE open_question_answers (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 question_id INT UNSIGNED NOT NULL,
 answer_text VARCHAR(1000) NOT NULL,
 sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 KEY idx_open_answers_question(question_id),
 CONSTRAINT fk_open_answers_question FOREIGN KEY(question_id) REFERENCES questions(id) ON DELETE CASCADE ON UPDATE CASCADE
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

CREATE TABLE study_sessions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 session_token CHAR(64) NOT NULL UNIQUE,
 student_id INT UNSIGNED NULL,
 test_id INT UNSIGNED NULL,
 activity_type ENUM('test','summary') NOT NULL DEFAULT 'test',
 summary_id INT UNSIGNED NULL,
 attempt_id BIGINT UNSIGNED NULL,
 started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 active_seconds INT UNSIGNED NOT NULL DEFAULT 0,
 ended_at DATETIME NULL,
 KEY idx_study_sessions_started(started_at),
 KEY idx_study_sessions_test(test_id),
 KEY idx_study_sessions_attempt(attempt_id),
 KEY idx_study_sessions_student(student_id),
 KEY idx_study_sessions_summary(summary_id),
 KEY idx_study_sessions_student_activity(student_id,activity_type),
 CONSTRAINT fk_study_sessions_test FOREIGN KEY(test_id) REFERENCES tests(id) ON DELETE CASCADE ON UPDATE CASCADE,
 CONSTRAINT fk_study_sessions_attempt FOREIGN KEY(attempt_id) REFERENCES attempts(id) ON DELETE SET NULL ON UPDATE CASCADE,
 CONSTRAINT fk_study_sessions_student FOREIGN KEY(student_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
 CONSTRAINT fk_study_sessions_summary FOREIGN KEY(summary_id) REFERENCES topic_summaries(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attempt_answers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 attempt_id BIGINT UNSIGNED NOT NULL,
 question_id INT UNSIGNED NOT NULL,
 selected_option_id INT UNSIGNED NULL,
 answer_text TEXT NULL,
 is_correct TINYINT(1) NULL,
 answered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_attempt_question(attempt_id,question_id),
 KEY idx_attempt_answers_question(question_id),
 KEY idx_attempt_answers_option(selected_option_id),
 CONSTRAINT fk_attempt_answers_attempt FOREIGN KEY(attempt_id) REFERENCES attempts(id) ON DELETE CASCADE ON UPDATE CASCADE,
 CONSTRAINT fk_attempt_answers_question FOREIGN KEY(question_id) REFERENCES questions(id) ON DELETE CASCADE ON UPDATE CASCADE,
 CONSTRAINT fk_attempt_answers_option FOREIGN KEY(selected_option_id) REFERENCES question_options(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO subjects(name) VALUES ('Geschiedenis');
INSERT INTO topics(subject_id,name) SELECT id,'Oefenen' FROM subjects WHERE name='Geschiedenis' LIMIT 1;
INSERT INTO tests(topic_id,title,description) SELECT id,'Voorbeeldtoets','Eerste test om de installatie te controleren.' FROM topics WHERE name='Oefenen' LIMIT 1;
INSERT INTO questions(test_id,question_text,question_type,sort_order) SELECT id,'Welke kleur heeft gras meestal?','multiple_choice',1 FROM tests WHERE title='Voorbeeldtoets' LIMIT 1;
INSERT INTO question_options(question_id,option_text,is_correct,sort_order) SELECT id,'Groen',1,1 FROM questions WHERE question_text='Welke kleur heeft gras meestal?' LIMIT 1;
INSERT INTO question_options(question_id,option_text,is_correct,sort_order) SELECT id,'Paars',0,2 FROM questions WHERE question_text='Welke kleur heeft gras meestal?' LIMIT 1;
INSERT INTO question_options(question_id,option_text,is_correct,sort_order) SELECT id,'Zwart',0,3 FROM questions WHERE question_text='Welke kleur heeft gras meestal?' LIMIT 1;
INSERT INTO question_options(question_id,option_text,is_correct,sort_order) SELECT id,'Oranje',0,4 FROM questions WHERE question_text='Welke kleur heeft gras meestal?' LIMIT 1;


CREATE TABLE IF NOT EXISTS ai_general_instructions (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  source_validation_instructions TEXT NULL,
  analysis_instructions TEXT NULL,
  language_instructions TEXT NULL,
  question_generation_instructions TEXT NULL,
  summary_instructions TEXT NULL,
  image_generation_instructions TEXT NULL,
  image_validation_instructions TEXT NULL,
  rule_generation_instructions TEXT NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ai_general_instructions (
  id,source_validation_instructions,analysis_instructions,language_instructions,
  question_generation_instructions,summary_instructions,image_generation_instructions,
  image_validation_instructions,rule_generation_instructions
)
VALUES (
  1,
  'Controleer iedere geüploade foto voordat deze als bron voor een toets wordt gebruikt. De afbeelding moet duidelijk schoolboekmateriaal, een werkblad of ander lesmateriaal zijn dat inhoudelijk bij het gekozen vak past. Keur een willekeurige foto, selfie, portret/gezichtsfoto of materiaal voor een ander vak af. Een gezicht dat onderdeel is van een relevante schoolboekpagina is toegestaan. Als de foto niet duidelijk bij het gekozen vak hoort, keur hem af.',
  'Analyseer de aangeleverde opdracht of schoolboekpagina’s voor het maken van een oefentoets. Bepaal vak, onderwerp, leerpunten en passende sub-testtypen. Gebruik de beheerde vakconfiguratie om te bepalen welke typen bij de bron passen. Geef uitsluitend JSON volgens het opgegeven schema.',
  'Bij een herkende woordenlijst is vocabulary_language de taal die geleerd wordt. Zet source altijd in de leertaal en translation in het Nederlands, of omgekeerd als Nederlands de leertaal is. Maak van iedere expliciet op de bron vermelde grammaticale vorm een afzonderlijke vocabulary_pair. Alleen wanneer zowel enkelvoud als meervoud op de bron staan, maak je een afzonderlijke entry voor beide vormen. Alleen wanneer zowel een mannelijke als vrouwelijke variant expliciet op de bron staat, maak je een afzonderlijke entry voor beide varianten. Maak geen dubbele entries op basis van aannames of algemene taalkennis. Zorg dat een expliciet vermelde meervoudsvorm ook een bijbehorende meervoudsvertaling krijgt wanneer die betrouwbaar kan worden afgeleid; bijvoorbeeld "het kopje - die Tasse - die Tassen" wordt "het kopje" ↔ "die Tasse" (enkelvoud) én "de kopjes" ↔ "die Tassen" (meervoud). Behoud alle relevante grammaticale kenmerken per entry in grammatical_label. Deze regels gelden voor iedere taal, ook toekomstige talen die nog niet in de configuratie bestaan.',
  'Maak schooltoetsvragen geschikt voor een leerling van ongeveer 12-15 jaar. Bij multiple choice zijn er exact vier opties en is exact één optie correct. Bij open vragen geef je één of meer inhoudelijk gelijkwaardige geaccepteerde antwoorden. Gebruik verschillende inhoudelijk passende vraagvormen en invalshoeken wanneer de bron dat ondersteunt en vermijd vrijwel identieke vragen.',
  'Maak een Nederlandse samenvatting van foto’s van schoolboekpagina’s. Gebruik uitsluitend informatie die zichtbaar of leesbaar in de aangeleverde pagina’s staat. Verzin niets en gebruik geen algemene kennis om ontbrekende informatie aan te vullen. De samenvatting is bedoeld voor een leerling van ongeveer 12-15 jaar en moet overzichtelijk, leerbaar en inhoudelijk volledig zijn. Behoud belangrijke begrippen, namen, processen, voorbeelden en jaartallen uit de bron. Deel de samenvatting op in logische onderwerpen. Elk nieuw onderwerp MOET beginnen met een Markdown-kopje in exact dit formaat: ## Onderwerp. Dus twee hekjes, één spatie en daarna de titel. Gebruik geen # of ### kopjes.',
  'Maak een eenvoudige educatieve illustratie voor een schoolvraag. Gebruik een rustige, duidelijke compositie, weinig details en geen decoratieve elementen. Zet geen tekst, labels of antwoorden in de afbeelding tenzij de afbeelding dat inhoudelijk noodzakelijk maakt. De afbeelding moet vooral functioneel en direct herkenbaar zijn.',
  'Je bent een strenge kwaliteitscontroleur voor educatieve afbeeldingen. Beoordeel uitsluitend of de afbeelding inhoudelijk klopt en bruikbaar is voor de opgegeven vraag. Geef geen cosmetische kritiek.',
  'Je maakt een eerste set beheerde AI-instructies voor een nieuw schoolvak in een Nederlandse oefentoets-app. Gebruik bestaande vakconfiguraties als voorbeelden. Zoek vooral een inhoudelijk vergelijkbaar vak en neem daarvan de structuur en het detailniveau over. Maak alleen typen die voor dit vak logisch zijn. Gebruik bij taalvakken de bestaande taalstructuur als uitgangspunt. Neem de centrale taal- en woordenlijstregels mee in iedere relevante taalregel, zodat expliciet vermelde enkelvoud/meervoud- en mannelijk/vrouwelijkvarianten volgens de centrale regels worden verwerkt. Voor gewone schoolvakken is meestal één mixed-regel voldoende. Neem samenvattingen, afbeeldingen, multiple choice en open vragen alleen op als ze voor het vak zinvol zijn. Schrijf compacte, concrete Nederlandse instructies die een docent direct kan bewerken. Verzin geen specifieke methode, lesboek of leerstof die je niet uit de vaknaam kunt afleiden. Geef uitsluitend JSON terug volgens het gevraagde schema.'
)
ON DUPLICATE KEY UPDATE id=id;
