USE leren;

CREATE TABLE IF NOT EXISTS topic_summaries (
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
    CONSTRAINT fk_topic_summaries_topic FOREIGN KEY(topic_id)
        REFERENCES topics(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO topic_summaries(topic_id,name,summary,is_active,created_at,updated_at)
SELECT tp.id,'Samenvatting',tp.summary,1,
       COALESCE(tp.summary_updated_at,tp.created_at),
       tp.summary_updated_at
FROM topics tp
WHERE tp.summary IS NOT NULL
  AND TRIM(tp.summary) <> ''
  AND NOT EXISTS (
      SELECT 1 FROM topic_summaries ts
      WHERE ts.topic_id=tp.id
  );
