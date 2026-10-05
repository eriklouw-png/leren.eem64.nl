USE leren;

ALTER TABLE topics
    ADD COLUMN test_date DATE NULL AFTER name;
