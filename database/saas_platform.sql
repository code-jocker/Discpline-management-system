CREATE TABLE IF NOT EXISTS tenants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    domain VARCHAR(180) DEFAULT NULL UNIQUE,
    status ENUM('active', 'suspended', 'trial') NOT NULL DEFAULT 'trial',
    contact_email VARCHAR(160) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(60) NOT NULL UNIQUE,
    price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    billing_cycle ENUM('monthly', 'yearly', 'lifetime') NOT NULL DEFAULT 'monthly',
    max_students INT UNSIGNED NOT NULL DEFAULT 0,
    features JSON DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    status ENUM('trial', 'active', 'past_due', 'cancelled', 'expired') NOT NULL DEFAULT 'trial',
    starts_at DATETIME NOT NULL,
    ends_at DATETIME DEFAULT NULL,
    trial_ends_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_subscriptions_tenant (tenant_id),
    INDEX idx_subscriptions_status (status),
    CONSTRAINT fk_subscriptions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_subscriptions_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED DEFAULT NULL,
    provider VARCHAR(60) NOT NULL,
    provider_reference VARCHAR(120) NOT NULL UNIQUE,
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    status ENUM('pending', 'paid', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
    paid_at DATETIME DEFAULT NULL,
    metadata JSON DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payments_tenant (tenant_id),
    CONSTRAINT fk_payments_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_payments_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED DEFAULT NULL,
    user_id INT NOT NULL,
    class_id INT DEFAULT NULL,
    message TEXT NOT NULL,
    status ENUM('unread', 'read') NOT NULL DEFAULT 'unread',
    `timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user_status (user_id, status),
    INDEX idx_notifications_tenant (tenant_id),
    INDEX idx_notifications_class (class_id)
);

CREATE TABLE IF NOT EXISTS deductions_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED DEFAULT NULL,
    student_id INT NOT NULL,
    term TINYINT NOT NULL,
    marks_deducted INT NOT NULL,
    reason TEXT NOT NULL,
    performed_by INT NOT NULL,
    `timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_deductions_student (student_id),
    INDEX idx_deductions_term (term),
    INDEX idx_deductions_tenant (tenant_id)
);

CREATE TABLE IF NOT EXISTS student_risk_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED DEFAULT NULL,
    student_id INT NOT NULL UNIQUE,
    risk_level ENUM('Excellent', 'Warning', 'Critical') NOT NULL DEFAULT 'Excellent',
    incidents_count INT NOT NULL DEFAULT 0,
    total_marks_deducted INT NOT NULL DEFAULT 0,
    avg_deduction_per_week DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    model_version VARCHAR(60) DEFAULT NULL,
    predicted_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_risk_level (risk_level),
    INDEX idx_risk_tenant (tenant_id)
);

CREATE TABLE IF NOT EXISTS queue_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED DEFAULT NULL,
    queue_name VARCHAR(80) NOT NULL,
    payload JSON NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME DEFAULT NULL,
    failed_at DATETIME DEFAULT NULL,
    failure_reason TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_queue_status_available (status, available_at),
    INDEX idx_queue_tenant (tenant_id)
);

CREATE TABLE IF NOT EXISTS stored_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id INT DEFAULT NULL,
    storage_disk VARCHAR(40) NOT NULL DEFAULT 'local',
    original_name VARCHAR(255) NOT NULL,
    storage_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_files_tenant (tenant_id),
    CONSTRAINT fk_files_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);
