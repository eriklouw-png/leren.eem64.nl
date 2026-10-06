USE leren;

ALTER TABLE topics
    ADD COLUMN use_summary TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active,
    ADD COLUMN summary TEXT NULL AFTER use_summary,
    ADD COLUMN summary_updated_at DATETIME NULL AFTER summary;
