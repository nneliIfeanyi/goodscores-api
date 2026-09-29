-- Enable question bank backup history on an existing installation.
CREATE TABLE IF NOT EXISTS question_bank_backups (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    revision        INT UNSIGNED NOT NULL,
    question_count  INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_question_backup_revision (user_id, revision),
    INDEX idx_question_backup_user (user_id),
    INDEX idx_question_backup_created (created_at)
) ENGINE=InnoDB;