-- ============================================================
-- SBMS Feature Expansion Migration (2026)
-- Adds support for:
-- - Behavioral Rewards & Gamification
-- - Collaborative Notes & Comments  
-- - Calendar Events & Scheduling
-- - Parent Notifications & Communication
-- - Custom Report Templates
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── Student Achievements (Badges & Milestones) ────────────────
CREATE TABLE IF NOT EXISTS achievements (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id      INT UNSIGNED NOT NULL,
    achievement_type VARCHAR(50)  NOT NULL, -- 'marks_milestone', 'streak', 'improvement', 'attendance'
    title           VARCHAR(120) NOT NULL,
    description     TEXT,
    points_awarded  INT UNSIGNED DEFAULT 10,
    badge_icon      VARCHAR(100) DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_achievements_student (student_id),
    CONSTRAINT fk_achievements_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- ── Student Rewards Points (Cumulative) ───────────────────────
CREATE TABLE IF NOT EXISTS student_rewards (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id      INT UNSIGNED NOT NULL UNIQUE,
    total_points    INT UNSIGNED DEFAULT 0,
    current_streak  INT UNSIGNED DEFAULT 0, -- Days/weeks of good behavior
    last_update     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rewards_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- ── Monthly Leaderboard Cache (denormalized for performance) ──
CREATE TABLE IF NOT EXISTS monthly_leaderboards (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id      INT UNSIGNED NOT NULL,
    month_year      CHAR(7) NOT NULL, -- '2026-07' format
    rank            INT UNSIGNED,
    points          INT UNSIGNED,
    marks_avg       DECIMAL(5,2),
    incidents_count INT UNSIGNED,
    INDEX idx_leaderboard_month (month_year),
    INDEX idx_leaderboard_student (student_id),
    CONSTRAINT fk_leaderboard_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- ── Collaborative Notes & Comments ───────────────────────────
CREATE TABLE IF NOT EXISTS incident_comments (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    deduction_id      INT UNSIGNED NOT NULL,
    user_id           INT UNSIGNED NOT NULL,
    comment_text      TEXT NOT NULL,
    is_internal       BOOLEAN DEFAULT FALSE, -- Private staff note
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_comments_deduction (deduction_id),
    INDEX idx_comments_user (user_id),
    CONSTRAINT fk_comments_deduction FOREIGN KEY (deduction_id) REFERENCES deductions_history(id) ON DELETE CASCADE,
    CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
);

-- ── Incident Follow-up Actions (Action Items) ────────────────
CREATE TABLE IF NOT EXISTS incident_actions (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    deduction_id      INT UNSIGNED NOT NULL,
    assigned_to_id    INT UNSIGNED NOT NULL,
    action_title      VARCHAR(255) NOT NULL,
    action_type       VARCHAR(50) NOT NULL, -- 'follow_up', 'parent_contact', 'counseling', 'detention', 'custom'
    description       TEXT,
    due_date          DATE,
    status            ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    completed_at      DATETIME DEFAULT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_actions_deduction (deduction_id),
    INDEX idx_actions_assigned (assigned_to_id),
    INDEX idx_actions_status (status),
    CONSTRAINT fk_actions_deduction FOREIGN KEY (deduction_id) REFERENCES deductions_history(id) ON DELETE CASCADE,
    CONSTRAINT fk_actions_assigned FOREIGN KEY (assigned_to_id) REFERENCES users(id) ON DELETE RESTRICT
);

-- ── Calendar Events & Scheduling ─────────────────────────────
CREATE TABLE IF NOT EXISTS calendar_events (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type      VARCHAR(50) NOT NULL, -- 'letter_follow_up', 'parent_meeting', 'counseling', 'detention', 'custom'
    event_title     VARCHAR(255) NOT NULL,
    description     TEXT,
    student_id      INT UNSIGNED,
    class_id        INT UNSIGNED,
    related_deduction_id INT UNSIGNED,
    event_date      DATE NOT NULL,
    event_time      TIME,
    location        VARCHAR(255),
    created_by      INT UNSIGNED NOT NULL,
    tenant_id       BIGINT UNSIGNED DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_calendar_student (student_id),
    INDEX idx_calendar_class (class_id),
    INDEX idx_calendar_date (event_date),
    INDEX idx_calendar_deduction (related_deduction_id),
    CONSTRAINT fk_calendar_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL,
    CONSTRAINT fk_calendar_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
    CONSTRAINT fk_calendar_deduction FOREIGN KEY (related_deduction_id) REFERENCES deductions_history(id) ON DELETE SET NULL,
    CONSTRAINT fk_calendar_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
);

-- ── Parent/Guardian Contact Information ──────────────────────
CREATE TABLE IF NOT EXISTS parent_contacts (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id          INT UNSIGNED NOT NULL,
    parent_name         VARCHAR(150) NOT NULL,
    relationship        VARCHAR(30), -- 'mother', 'father', 'guardian', 'other'
    email               VARCHAR(180),
    phone_number        VARCHAR(20),
    whatsapp_number     VARCHAR(20),
    is_primary          BOOLEAN DEFAULT FALSE,
    contact_preference  VARCHAR(30) DEFAULT 'email', -- 'email', 'sms', 'whatsapp'
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_parent_student (student_id),
    CONSTRAINT fk_parent_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- ── Parent Notification Logs ──────────────────────────────────
CREATE TABLE IF NOT EXISTS parent_notifications_log (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_contact_id   INT UNSIGNED NOT NULL,
    student_id          INT UNSIGNED NOT NULL,
    notification_type   VARCHAR(50) NOT NULL, -- 'incident_alert', 'achievement', 'report', 'general'
    subject             VARCHAR(255) NOT NULL,
    message_body        TEXT NOT NULL,
    delivery_method     VARCHAR(30), -- 'email', 'sms', 'whatsapp'
    delivery_status     ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    sent_at             DATETIME,
    failed_reason       TEXT,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_parent_log_contact (parent_contact_id),
    INDEX idx_parent_log_student (student_id),
    INDEX idx_parent_log_status (delivery_status),
    CONSTRAINT fk_parent_log_contact FOREIGN KEY (parent_contact_id) REFERENCES parent_contacts(id) ON DELETE CASCADE,
    CONSTRAINT fk_parent_log_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- ── Custom Report Templates ──────────────────────────────────
CREATE TABLE IF NOT EXISTS report_templates (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       BIGINT UNSIGNED DEFAULT NULL,
    template_name   VARCHAR(255) NOT NULL,
    template_type   VARCHAR(50) NOT NULL, -- 'term_report', 'incident_report', 'progress_report', 'custom'
    description     TEXT,
    report_config   JSON, -- Stores field selections and format preferences
    created_by      INT UNSIGNED NOT NULL,
    is_default      BOOLEAN DEFAULT FALSE,
    is_public       BOOLEAN DEFAULT FALSE, -- Can other teachers use it?
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_templates_tenant (tenant_id),
    INDEX idx_templates_creator (created_by),
    CONSTRAINT fk_templates_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
);

-- ── Generated Reports (Audit Trail) ──────────────────────────
CREATE TABLE IF NOT EXISTS generated_reports (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id     INT UNSIGNED,
    report_type     VARCHAR(50) NOT NULL,
    student_id      INT UNSIGNED,
    class_id        INT UNSIGNED,
    output_format   VARCHAR(20) NOT NULL, -- 'pdf', 'excel', 'csv'
    file_id         INT UNSIGNED,
    generated_by    INT UNSIGNED NOT NULL,
    generation_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    download_count  INT UNSIGNED DEFAULT 0,
    last_downloaded DATETIME DEFAULT NULL,
    INDEX idx_reports_student (student_id),
    INDEX idx_reports_class (class_id),
    INDEX idx_reports_generator (generated_by),
    INDEX idx_reports_date (generation_date),
    CONSTRAINT fk_reports_template FOREIGN KEY (template_id) REFERENCES report_templates(id) ON DELETE SET NULL,
    CONSTRAINT fk_reports_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL,
    CONSTRAINT fk_reports_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
    CONSTRAINT fk_reports_file FOREIGN KEY (file_id) REFERENCES stored_files(id) ON DELETE SET NULL,
    CONSTRAINT fk_reports_generator FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE RESTRICT
);

-- ── Analytics Cache (for dashboard performance) ──────────────
CREATE TABLE IF NOT EXISTS analytics_cache (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    metric_type     VARCHAR(100) NOT NULL, -- 'class_avg_marks', 'school_trends', 'incident_by_category'
    filter_params   JSON, -- Stores class_id, term, date_range, etc.
    metric_value    JSON, -- Stores the calculated metric
    cache_date      DATE NOT NULL,
    generated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME,
    UNIQUE KEY uq_metric_filter (metric_type, MD5(CAST(filter_params AS CHAR))),
    INDEX idx_analytics_date (cache_date)
);

-- ── Saved Search Filters (User Preferences) ──────────────────
CREATE TABLE IF NOT EXISTS saved_filters (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    filter_name     VARCHAR(255) NOT NULL,
    filter_type     VARCHAR(50) NOT NULL, -- 'student_search', 'incident_search', 'report'
    filter_config   JSON NOT NULL,
    is_default      BOOLEAN DEFAULT FALSE,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_filters_user (user_id),
    CONSTRAINT fk_filters_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ── Email Digest Subscriptions ───────────────────────────────
CREATE TABLE IF NOT EXISTS email_digest_subscriptions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    digest_type     VARCHAR(50) NOT NULL, -- 'weekly', 'monthly'
    frequency       VARCHAR(20), -- 'monday', 'friday', 'end_of_month'
    include_incidents BOOLEAN DEFAULT TRUE,
    include_achievements BOOLEAN DEFAULT TRUE,
    include_analytics BOOLEAN DEFAULT TRUE,
    last_sent       DATETIME,
    is_active       BOOLEAN DEFAULT TRUE,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_digest_subscription (user_id, digest_type),
    CONSTRAINT fk_digest_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

SET FOREIGN_KEY_CHECKS = 1;
