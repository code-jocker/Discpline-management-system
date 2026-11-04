-- ============================================================
-- SBMS Core Schema
-- Run this FIRST, then auth_support.sql, then saas_platform.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── Roles ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS roles (
    id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(80)     NOT NULL UNIQUE,
    description TEXT            DEFAULT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ── Permissions ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS permissions (
    id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120)    NOT NULL UNIQUE,
    description TEXT            DEFAULT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ── Role ↔ Permission mapping ────────────────────────────────
CREATE TABLE IF NOT EXISTS role_permissions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    UNIQUE  KEY uq_role_permission (role_id, permission_id),
    CONSTRAINT fk_rp_role       FOREIGN KEY (role_id)       REFERENCES roles(id)       ON DELETE CASCADE,
    CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
);

-- ── Users ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id         INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    full_name  VARCHAR(150)  NOT NULL,
    email      VARCHAR(180)  NOT NULL UNIQUE,
    password_hash VARCHAR(255)  NOT NULL,
    role_id    INT UNSIGNED  NOT NULL,
    tenant_id  BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_users_role   (role_id),
    INDEX idx_users_tenant (tenant_id),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
);

-- ── Categories (academic tracks) ─────────────────────────────
CREATE TABLE IF NOT EXISTS categories (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    tenant_id  BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_categories_tenant (tenant_id)
);

-- ── Classes ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS classes (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                  VARCHAR(120) NOT NULL,
    category_id           INT UNSIGNED NOT NULL,
    tenant_id             BIGINT UNSIGNED DEFAULT NULL,
    class_teacher_user_id INT UNSIGNED DEFAULT NULL,
    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_classes_category (category_id),
    INDEX idx_classes_tenant   (tenant_id),
    CONSTRAINT fk_classes_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
);

-- ── Students ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS students (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_code   VARCHAR(30)  NOT NULL,
    full_name      VARCHAR(150) NOT NULL,
    class_id       INT UNSIGNED NOT NULL,
    category_id    INT UNSIGNED NOT NULL,
    parent_contact VARCHAR(30)  NOT NULL,
    tenant_id      BIGINT UNSIGNED DEFAULT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_students_class_code (class_id, student_code),
    INDEX idx_students_class    (class_id),
    INDEX idx_students_category (category_id),
    INDEX idx_students_tenant   (tenant_id),
    CONSTRAINT fk_students_class    FOREIGN KEY (class_id)    REFERENCES classes(id)     ON DELETE RESTRICT,
    CONSTRAINT fk_students_category FOREIGN KEY (category_id) REFERENCES categories(id)  ON DELETE RESTRICT
);

-- ── Student marks (one row per student per term) ─────────────
CREATE TABLE IF NOT EXISTS student_marks (
    id         INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED   NOT NULL,
    term       TINYINT UNSIGNED NOT NULL,
    marks      SMALLINT       NOT NULL DEFAULT 40,
    tenant_id  BIGINT UNSIGNED DEFAULT NULL,
    UNIQUE KEY uq_student_term (student_id, term),
    INDEX idx_marks_student (student_id),
    CONSTRAINT fk_marks_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- ── Notifications ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notifications (
    id         INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED    NOT NULL,
    class_id   INT UNSIGNED    DEFAULT NULL,
    message    TEXT            NOT NULL,
    status     ENUM('unread', 'read') DEFAULT 'unread',
    timestamp  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user (user_id),
    INDEX idx_notifications_class (class_id),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ── Audit Logs ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_logs (
    id         INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED    DEFAULT NULL,
    action     VARCHAR(255)    NOT NULL,
    description TEXT           DEFAULT NULL,
    ip_address VARCHAR(45)     NOT NULL,
    timestamp  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user (user_id)
);

-- ── Remember Tokens ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS remember_tokens (
    id               INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED    NOT NULL,
    selector         CHAR(12)        NOT NULL,
    hashed_validator CHAR(64)        NOT NULL,
    expires_at       DATETIME        NOT NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_selector (selector),
    CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ── Deductions History ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS deductions_history (
    id             INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    student_id     INT UNSIGNED    NOT NULL,
    term           TINYINT UNSIGNED NOT NULL,
    marks_deducted SMALLINT        NOT NULL,
    reason         TEXT            NOT NULL,
    performed_by   INT UNSIGNED    DEFAULT NULL,
    evidence_file_id INT UNSIGNED   DEFAULT NULL,
    timestamp      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_history_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_history_evidence FOREIGN KEY (evidence_file_id) REFERENCES stored_files(id) ON DELETE SET NULL
);

-- ── Student Risk Profiles ────────────────────────────────────
CREATE TABLE IF NOT EXISTS student_risk_profiles (
    student_id             INT UNSIGNED    PRIMARY KEY,
    risk_level             VARCHAR(20)     NOT NULL,
    incidents_count        INT UNSIGNED    DEFAULT 0,
    total_marks_deducted   INT UNSIGNED    DEFAULT 0,
    avg_deduction_per_week DECIMAL(5,2)    DEFAULT 0.00,
    model_version          VARCHAR(60)     DEFAULT NULL,
    predicted_at           DATETIME        DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_risk_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- ── Password Resets (for password recovery) ──────────────────
CREATE TABLE IF NOT EXISTS password_resets (
    user_id    INT UNSIGNED    NOT NULL,
    token      VARCHAR(255)    NOT NULL,
    expires_at DATETIME        NOT NULL, -- When the token becomes invalid
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_password_resets_user (user_id),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ── Stored Files (for uploads) ───────────────────────────────
CREATE TABLE IF NOT EXISTS stored_files (
    id             INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    tenant_id      BIGINT UNSIGNED DEFAULT NULL,
    user_id        INT UNSIGNED    DEFAULT NULL,
    storage_disk   VARCHAR(50)     NOT NULL DEFAULT 'local',
    original_name  VARCHAR(255)    NOT NULL,
    storage_path   VARCHAR(255)    NOT NULL,
    mime_type      VARCHAR(100)    NOT NULL,
    size_bytes     INT UNSIGNED    NOT NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stored_files_tenant (tenant_id),
    INDEX idx_stored_files_user   (user_id),
    CONSTRAINT fk_stored_files_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Seed Data
-- ============================================================

INSERT IGNORE INTO roles (name, description) VALUES
    ('Admin',                          'Full platform access'),
    ('Headteacher',                    'School leadership with full oversight'),
    ('Teacher',                        'Classroom teacher with limited write access'),
    ('Deputy Headteacher Discipline',  'Discipline-focused leadership role'),
    ('IT',                             'Technical administrator'),
    ('Patron',                         'Student patron'),
    ('Matron',                         'Student matron'),
    ('Bursar',                         'Financial administration'),
    ('DOS',                            'Director of Studies'),
    ('Librarian',                      'Library management'),
    ('Secretary',                      'Administrative secretary');

INSERT IGNORE INTO permissions (name, description) VALUES
    ('deduct_marks',           'Can record discipline mark deductions'),
    ('view_students',          'Can view student records'),
    ('manage_students',        'Can create, update, and delete students'),
    ('view_discipline_history','Can access the deduction history'),
    ('manage_users',           'Can create and manage user accounts'),
    ('view_dashboard',         'Can access analytics dashboard'),
    ('manage_subscriptions',   'Can manage tenant subscriptions'),
    ('upload_files',           'Can upload files to the platform'),
    ('generate_reports',       'Can generate student and class reports'),
    ('assess_risk',            'Can trigger AI risk assessments');

-- Grant ALL permissions to Admin, Headteacher, IT
INSERT IGNORE INTO role_permissions (role_id, permission_id)
    SELECT r.id, p.id
    FROM   roles r
    CROSS  JOIN permissions p
    WHERE  LOWER(r.name) IN ('admin', 'headteacher', 'it');

-- Discipline + read permissions for Teacher and Deputy Headteacher Discipline
INSERT IGNORE INTO role_permissions (role_id, permission_id)
    SELECT r.id, p.id
    FROM   roles r
    CROSS  JOIN permissions p
    WHERE  LOWER(r.name) IN ('teacher', 'deputy headteacher discipline')
      AND  LOWER(p.name)  IN (
               'deduct_marks', 'view_students',
               'view_discipline_history', 'view_dashboard',
               'generate_reports', 'assess_risk'
           );

-- ── Default plan data ────────────────────────────────────────
-- (Requires saas_platform.sql to have been run first.)
-- INSERT IGNORE INTO plans (name, code, price, billing_cycle, max_students) VALUES
--     ('Starter',    'starter',    9.00,  'monthly', 100),
--     ('School',     'school',     49.00, 'monthly', 1000),
--     ('District',   'district',   199.00,'monthly', 10000),
--     ('Enterprise', 'enterprise', 0.00,  'yearly',  0);

-- ── Sample categories + classes (comment out for production) ─
INSERT IGNORE INTO categories (id, name) VALUES
    (1, 'Junior Secondary'),
    (2, 'Senior Secondary');

INSERT IGNORE INTO classes (id, name, category_id) VALUES
    (1,  'JSS 1A', 1), (2,  'JSS 1B', 1),
    (3,  'JSS 2A', 1), (4,  'JSS 2B', 1),
    (5,  'JSS 3A', 1), (6,  'JSS 3B', 1),
    (7,  'SSS 1A', 2), (8,  'SSS 1B', 2),
    (9,  'SSS 2A', 2), (10, 'SSS 2B', 2),
    (11, 'SSS 3A', 2), (12, 'SSS 3B', 2);

-- Settings table
CREATE TABLE IF NOT EXISTS settings (
  key_name VARCHAR(100) NOT NULL PRIMARY KEY,
  value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS school_years (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(20) NOT NULL,
  start_year SMALLINT UNSIGNED NOT NULL,
  end_year SMALLINT UNSIGNED NOT NULL,
  start_month TINYINT UNSIGNED NOT NULL DEFAULT 9,
  end_month TINYINT UNSIGNED NOT NULL DEFAULT 7,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  is_locked TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_school_year_name (name),
  KEY idx_school_years_active (is_active, is_archived, is_locked)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
