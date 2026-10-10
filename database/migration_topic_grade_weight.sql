-- Eenmalig uitvoeren na migration_topic_grades.sql.
ALTER TABLE topic_grades ADD COLUMN weight TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER grade;
