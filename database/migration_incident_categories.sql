-- Incident categories for discipline actions (deduction / reward presets).
-- The app also creates and seeds this table at runtime (models/IncidentCategory.php),
-- so this file is for fresh installs and documentation.
--
-- This is NOT the `categories` table, which holds academic tracks
-- (Junior Secondary / Senior Secondary) and is referenced by classes and students.

CREATE TABLE IF NOT EXISTS incident_categories (
    id            INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100)   NOT NULL,
    action_type   VARCHAR(10)    NOT NULL DEFAULT 'deduct',
    default_marks TINYINT UNSIGNED NOT NULL DEFAULT 1,
    signal_type   VARCHAR(20)    DEFAULT NULL,
    is_active     TINYINT(1)     NOT NULL DEFAULT 1,
    sort_order    INT            NOT NULL DEFAULT 0,
    created_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_incident_category_name (name),
    KEY idx_incident_category_signal (signal_type),
    KEY idx_incident_category_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- signal_type drives the attendance tracking on the discipline ledger:
--   'late' / 'absence' -> attendance signals, 'positive' -> reward signal,
--   'conduct' -> ordinary behaviour incident.

INSERT IGNORE INTO incident_categories (name, action_type, default_marks, signal_type, sort_order) VALUES
    ('Attendance',          'deduct', 2, 'absence',  5),
    ('Late Arrival',        'deduct', 1, 'late',    10),
    ('Absenteeism',         'deduct', 3, 'absence', 20),
    ('Truancy',             'deduct', 5, 'absence', 30),
    ('Uniform',             'deduct', 1, 'conduct', 40),
    ('Classroom Behaviour', 'deduct', 2, 'conduct', 50),
    ('Insubordination',     'deduct', 4, 'conduct', 60),
    ('Academic Integrity',  'deduct', 5, 'conduct', 70),
    ('Fighting',            'deduct', 8, 'conduct', 80),
    ('Bullying',            'deduct', 6, 'conduct', 90),
    ('Property Damage',     'deduct', 5, 'conduct', 100),
    ('Positive Behaviour',  'add',    2, 'positive',110),
    ('Punctuality',         'add',    1, 'positive',120),
    ('Leadership',          'add',    3, 'positive',130),
    ('Community Service',   'add',    3, 'positive',140),
    ('Academic Achievement','add',    5, 'positive',150);
