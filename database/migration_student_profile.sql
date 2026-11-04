-- Student profile fields needed by the printable ID card.
-- The app checks for these columns at runtime (models/Student.php), so pages keep
-- working before this migration is applied and light up the new fields after it.
--
-- Apply with:  mysql -u root -P <port> <database> < database/migration_student_profile.sql

ALTER TABLE students
    ADD COLUMN date_of_birth DATE         NULL AFTER full_name,
    ADD COLUMN address       VARCHAR(255) NULL AFTER date_of_birth,
    ADD COLUMN photo_path    VARCHAR(255) NULL AFTER address;

-- Card lookups are always "one class, ordered by name"; the directory already
-- filters on class_id, so only the photo path needs an index-free scan.
