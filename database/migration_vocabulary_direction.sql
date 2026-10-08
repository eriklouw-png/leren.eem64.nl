USE leren;

ALTER TABLE questions
    ADD COLUMN vocab_direction ENUM('left_to_right','right_to_left') NULL AFTER question_type;

UPDATE questions q
JOIN tests t ON t.id=q.test_id
SET q.vocab_direction='left_to_right'
WHERE t.test_type IN ('vocabulary','sentences')
  AND q.vocab_direction IS NULL
  AND t.vocab_right_label IS NOT NULL
  AND q.explanation LIKE CONCAT('Vertaal naar ',t.vocab_right_label,'.%');

UPDATE questions q
JOIN tests t ON t.id=q.test_id
SET q.vocab_direction='right_to_left'
WHERE t.test_type IN ('vocabulary','sentences')
  AND q.vocab_direction IS NULL
  AND t.vocab_left_label IS NOT NULL
  AND q.explanation LIKE CONCAT('Vertaal naar ',t.vocab_left_label,'.%');

UPDATE questions q
JOIN tests t ON t.id=q.test_id
SET q.vocab_direction=CASE WHEN MOD(q.sort_order,2)=1 THEN 'left_to_right' ELSE 'right_to_left' END
WHERE t.test_type IN ('vocabulary','sentences')
  AND q.vocab_direction IS NULL;
