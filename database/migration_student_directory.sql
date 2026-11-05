-- Student Directory: stream, dormitory, academic year and lifecycle status.
-- These columns back the real filters, table columns, and transfer/promote/
-- graduate/archive workflows in students/index.php. Apply once:
--
--   mysql -u root -P 3307 sbms_legacy_run < database/migration_student_directory.sql
--
-- The application checks for each column at runtime, so pages keep rendering
-- before this migration is applied.

ALTER TABLE students
    ADD COLUMN stream      VARCHAR(40) NULL AFTER class_id,
    ADD COLUMN dormitory   VARCHAR(60) NULL AFTER stream,
    ADD COLUMN status      ENUM('active','transferred','graduated','archived') NOT NULL DEFAULT 'active' AFTER dormitory,
    ADD COLUMN school_year VARCHAR(20) NULL AFTER parent_contact;

-- Backfill the academic year from the latest term marks, then the school
-- setting, so existing records appear under the active year.
UPDATE students s
SET s.school_year = COALESCE(
    (
        SELECT NULLIF(sm.school_year, '')
        FROM student_marks sm
        WHERE sm.student_id = s.id
          AND sm.school_year IS NOT NULL
          AND sm.school_year <> ''
        ORDER BY sm.term DESC, sm.id DESC
        LIMIT 1
    ),
    (SELECT NULLIF(value, '') FROM settings WHERE key_name = 'selected_school_year' LIMIT 1),
    (SELECT NULLIF(value, '') FROM settings WHERE key_name = 'active_academic_year' LIMIT 1)
)
WHERE s.school_year IS NULL OR s.school_year = '';

CREATE INDEX idx_students_directory_status ON students (status, school_year);
CREATE INDEX idx_students_directory_stream ON students (stream);
CREATE INDEX idx_students_directory_dormitory ON students (dormitory);
