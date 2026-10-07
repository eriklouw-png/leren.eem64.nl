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


-- AI-instructies per vak en sub-testtype
CREATE TABLE IF NOT EXISTS ai_test_rules (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 subject_id INT UNSIGNED NOT NULL,
 test_type VARCHAR(40) NOT NULL,
 label VARCHAR(120) NOT NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 1,
 allow_summary TINYINT(1) NOT NULL DEFAULT 0,
 allow_images TINYINT(1) NOT NULL DEFAULT 0,
 allow_multiple_choice TINYINT(1) NOT NULL DEFAULT 0,
 allow_open TINYINT(1) NOT NULL DEFAULT 1,
 recognition_instructions TEXT NULL,
 generation_instructions TEXT NULL,
 sort_order INT NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL,
 UNIQUE KEY uq_ai_test_rules_subject_type(subject_id,test_type),
 KEY idx_ai_test_rules_subject(subject_id),
 CONSTRAINT fk_ai_test_rules_subject FOREIGN KEY(subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ai_test_rules
(subject_id,test_type,label,enabled,allow_summary,allow_images,allow_multiple_choice,allow_open,recognition_instructions,generation_instructions,sort_order)
SELECT id,'vocabulary','Woordjes oefenen',1,0,0,0,1,
'Herken duidelijke woordenlijsten en woordparen. De opmaak hoeft niet uit twee kolommen te bestaan. Herken ook lijsten met meerdere blokken of secties. Neem expliciet vermelde enkelvoud-, meervouds- en vrouwelijke vormen als afzonderlijke leeritems op.',
'Maak beide richtingen: brontaal naar Nederlands en Nederlands naar brontaal. Toon grammaticale labels zoals (mannelijk), (vrouwelijk) en (meervoud) wanneer de bron dit duidelijk ondersteunt. Verzin geen vormen die niet in de bron staan.',1
FROM subjects WHERE LOWER(name)='duits';

INSERT IGNORE INTO ai_test_rules
(subject_id,test_type,label,enabled,allow_summary,allow_images,allow_multiple_choice,allow_open,recognition_instructions,generation_instructions,sort_order)
SELECT id,'sentences','Zinnen oefenen',1,0,0,0,1,
'Herken pagina’s waarop volledige zinnen of voorbeeldzinnen met vertaling centraal staan. De zinnen hoeven niet in twee kolommen te staan.',
'Neem de volledige zinnen inclusief relevante leestekens over en maak beide vertaalrichtingen. Verander de inhoud van de bronzinnen niet.',2
FROM subjects WHERE LOWER(name)='duits';

INSERT IGNORE INTO ai_test_rules
(subject_id,test_type,label,enabled,allow_summary,allow_images,allow_multiple_choice,allow_open,recognition_instructions,generation_instructions,sort_order)
SELECT id,'grammar','Grammatica',1,0,0,1,1,
'Herken grammatica-uitleg, tabellen, regels, voorbeelden en vervoegingen. Behandel een grammaticapagina niet als woordenlijst of zinnenlijst tenzij dat aantoonbaar het hoofddoel van de pagina is.',
'Maak een schoolse oefentoets over de grammaticale regels en voorbeelden uit de bron. Vraag zowel herkenning/toepassing als het zelfstandig toepassen van de regel. Gebruik alleen informatie uit de bron.',3
FROM subjects WHERE LOWER(name)='duits';
