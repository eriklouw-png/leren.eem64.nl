-- Run after backing up the database, before deploying owner-aware PHP code.
-- Assign all existing subjects to Teun Louw; fail if user is absent or ambiguous.
DELIMITER //
CREATE PROCEDURE migrate_subject_owner()
BEGIN
 DECLARE teun_id INT UNSIGNED;
 DECLARE matches INT DEFAULT 0;
 SELECT COUNT(*),MIN(id) INTO matches,teun_id FROM users WHERE name='Teun Louw' AND role='student';
 IF matches <> 1 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Expected exactly one student named Teun Louw';
 END IF;
 ALTER TABLE subjects ADD COLUMN user_id INT UNSIGNED NULL AFTER id;
 UPDATE subjects SET user_id=teun_id WHERE user_id IS NULL;
 ALTER TABLE subjects MODIFY user_id INT UNSIGNED NOT NULL;
 ALTER TABLE subjects DROP INDEX name;
 ALTER TABLE subjects ADD UNIQUE KEY uq_subjects_owner_name(user_id,name);
 ALTER TABLE subjects ADD CONSTRAINT fk_subjects_owner FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE;
END//
DELIMITER ;
CALL migrate_subject_owner();
DROP PROCEDURE migrate_subject_owner;
