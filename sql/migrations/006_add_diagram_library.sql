-- Add per-user diagram library for reusable SVG and illustration assets.
CREATE TABLE IF NOT EXISTS diagrams (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    title           VARCHAR(180) NOT NULL,
    description     TEXT NULL,
    mode            ENUM('precise_diagram', 'illustration') NOT NULL,
    source          ENUM('ai', 'upload') NOT NULL DEFAULT 'ai',
    diagram_spec    JSON NULL,
    image_path      VARCHAR(500) NULL,
    mime_type       VARCHAR(80) NULL,
    file_size       INT UNSIGNED NULL,
    is_deleted      TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_diagrams_user (user_id),
    INDEX idx_diagrams_mode (mode)
) ENGINE=InnoDB;
