-- WhatsApp notification queue for the Node.js whatsapp-web.js worker.
--
-- PHP records an incident and inserts a job here; the Node worker claims jobs,
-- sends them through WhatsApp Web and writes the outcome. Keeping the handoff in
-- MySQL means a saved incident is never lost because WhatsApp was offline.
--
-- Apply with:  mysql -u root -P <port> <database> < database/migration_whatsapp_queue.sql

CREATE TABLE IF NOT EXISTS notification_queue (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id        INT UNSIGNED    NULL,
    parent_phone      VARCHAR(30)     NOT NULL,
    message           TEXT            NOT NULL,
    notification_type VARCHAR(50)     NOT NULL DEFAULT 'discipline_incident',
    -- Unique key per incident so a resubmitted form cannot queue a duplicate send.
    dedupe_key        VARCHAR(120)    NULL,
    status            ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
    attempts          INT UNSIGNED    NOT NULL DEFAULT 0,
    max_attempts      INT UNSIGNED    NOT NULL DEFAULT 5,
    next_attempt_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at           DATETIME        NULL,
    last_error        TEXT            NULL,
    created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notification_queue_dedupe (dedupe_key),
    KEY idx_notification_queue_status (status, next_attempt_at),
    KEY idx_notification_queue_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_logs (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue_id      BIGINT UNSIGNED NOT NULL,
    parent_phone  VARCHAR(30)     NOT NULL,
    message       TEXT            NULL,
    status        VARCHAR(30)     NOT NULL,
    error_message TEXT            NULL,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notification_logs_queue (queue_id),
    KEY idx_notification_logs_created (created_at),
    CONSTRAINT fk_notification_logs_queue
        FOREIGN KEY (queue_id) REFERENCES notification_queue (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
