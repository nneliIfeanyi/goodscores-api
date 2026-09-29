-- GoodScores Schema (Phase 0 + 1 + 2)
-- MySQL 8+ / MariaDB 10.5+
-- Schools
CREATE TABLE IF NOT EXISTS schools (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(255) NOT NULL,
    logo                VARCHAR(500) NULL,
    address             TEXT NULL,
    contact_email       VARCHAR(255) NULL,
    contact_phone       VARCHAR(50) NULL,
    school_code         VARCHAR(20) NOT NULL UNIQUE,
    paper_settings      JSON NULL,
    credit_balance      INT NOT NULL DEFAULT 0,
    is_unlimited        TINYINT(1) NOT NULL DEFAULT 0,
    subscription_expiry DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_school_code (school_code)
) ENGINE=InnoDB;

-- Users
CREATE TABLE IF NOT EXISTS users (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(150) NOT NULL,
    email               VARCHAR(255) NOT NULL UNIQUE,
    password            VARCHAR(255) NOT NULL,
    role                ENUM('school_admin', 'teacher', 'individual') NOT NULL DEFAULT 'individual',
    school_id           INT UNSIGNED NULL,
    credits             INT NOT NULL DEFAULT 200,
    is_pro              TINYINT(1) NOT NULL DEFAULT 0,
    plan                ENUM('free','pro','pro_plus') NOT NULL DEFAULT 'free',
    offline_unlocked    TINYINT(1) NOT NULL DEFAULT 0,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    subscription_expiry DATETIME NULL,
    pdf_font_size       DECIMAL(4,1) NOT NULL DEFAULT 11.0,
    pdf_font_family     VARCHAR(40) NOT NULL DEFAULT 'dejavusans',
    pdf_show_marks      TINYINT(1) NOT NULL DEFAULT 1,
    pdf_settings        JSON NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL,
    INDEX idx_email (email),
    INDEX idx_school (school_id)
) ENGINE=InnoDB;

-- Question bank backup history
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

-- One-time password reset tokens. Store only token hashes, never raw tokens.
CREATE TABLE IF NOT EXISTS password_resets (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_password_reset_user (user_id),
    INDEX idx_password_reset_expiry (expires_at)
) ENGINE=InnoDB;

-- Credit transactions log
CREATE TABLE IF NOT EXISTS credit_transactions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NULL,
    school_id       INT UNSIGNED NULL,
    amount          INT NOT NULL,
    reason          VARCHAR(255) NOT NULL,
    reference       VARCHAR(100) NULL,
    type            ENUM('credit', 'deduct', 'unlimited') NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_school (school_id)
) ENGINE=InnoDB;

-- ========== PHASE 2 ==========

-- Subjects (global + school-scoped)
CREATE TABLE IF NOT EXISTS subjects (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id  INT UNSIGNED NULL,             -- NULL = shared/default metadata
    school_id   INT UNSIGNED NULL,          -- NULL = global default
    name        VARCHAR(120) NOT NULL,
    code        VARCHAR(20) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_teacher (teacher_id),
    INDEX idx_school (school_id)
) ENGINE=InnoDB;

-- Classes / Levels (e.g. Primary 1, JSS 2, SS 3)
CREATE TABLE IF NOT EXISTS classes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id  INT UNSIGNED NULL,             -- NULL = shared/default metadata
    school_id   INT UNSIGNED NULL,
    name        VARCHAR(80) NOT NULL,
    sort_order  SMALLINT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_teacher (teacher_id),
    INDEX idx_school (school_id)
) ENGINE=InnoDB;

-- Terms (1st, 2nd, 3rd / Mid-term etc.)
CREATE TABLE IF NOT EXISTS terms (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_id   INT UNSIGNED NULL,
    name        VARCHAR(60) NOT NULL,
    sort_order  SMALLINT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Topics under a subject
CREATE TABLE IF NOT EXISTS topics (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_id  INT UNSIGNED NOT NULL,
    name        VARCHAR(150) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    INDEX idx_subject (subject_id)
) ENGINE=InnoDB;

-- Questions
-- Reusable comprehension passages
CREATE TABLE IF NOT EXISTS passages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    school_id   INT UNSIGNED NULL,
    subject_id  INT UNSIGNED NULL,
    class_id    INT UNSIGNED NULL,
    term_id     INT UNSIGNED NULL,
    title       VARCHAR(255) NOT NULL,
    body        TEXT NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
    FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE SET NULL,
    INDEX idx_passage_owner (user_id),
    INDEX idx_passage_context (subject_id, class_id, term_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS questions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    school_id       INT UNSIGNED NULL,
    subject_id      INT UNSIGNED NULL,
    topic_id        INT UNSIGNED NULL,
    class_id        INT UNSIGNED NULL,
    term_id         INT UNSIGNED NULL,
    passage_id      INT UNSIGNED NULL,
    diagram_request JSON NULL,
    diagram_spec    JSON NULL,
    content_type    VARCHAR(40) NOT NULL DEFAULT 'standard',
    type            ENUM('mcq', 'fill', 'theory') NOT NULL DEFAULT 'mcq',
    body            TEXT NOT NULL,                    -- question text (can contain simple HTML)
    options         JSON NULL,                        -- for MCQ: [{"key":"A","text":"..."}, ...]
    answer          TEXT NULL,                        -- correct answer / marking guide
    marks           DECIMAL(5,2) NOT NULL DEFAULT 1,
    difficulty      ENUM('easy', 'medium', 'hard') NULL DEFAULT 'medium',
    is_deleted      TINYINT(1) NOT NULL DEFAULT 0,
    offline_id      VARCHAR(64) NULL,                 -- client-side UUID for offline sync
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
    FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE SET NULL,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
    FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE SET NULL,
    FOREIGN KEY (passage_id) REFERENCES passages(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_school (school_id),
    INDEX idx_subject (subject_id),
    INDEX idx_type (type),
    INDEX idx_offline (offline_id),
    UNIQUE KEY uq_question_offline (user_id, offline_id)
) ENGINE=InnoDB;

-- Question images / diagrams
CREATE TABLE IF NOT EXISTS question_images (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id     INT UNSIGNED NOT NULL,
    file_path       VARCHAR(500) NOT NULL,            -- relative path or URL
    original_name   VARCHAR(255) NULL,
    mime_type       VARCHAR(80) NULL,
    file_size       INT UNSIGNED NULL,
    type            ENUM('diagram', 'label', 'option_image', 'general', 'ai_illustration') NOT NULL DEFAULT 'diagram',
    position        VARCHAR(40) NULL,                 -- inline | after_body | option_A etc.
    caption         VARCHAR(255) NULL,
    sort_order      SMALLINT NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
    INDEX idx_question (question_id)
) ENGINE=InnoDB;

-- Exam papers (drafts & final)
CREATE TABLE IF NOT EXISTS exam_papers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    school_id       INT UNSIGNED NULL,
    title           VARCHAR(255) NOT NULL,
    subject_id      INT UNSIGNED NULL,
    class_id        INT UNSIGNED NULL,
    term_id         INT UNSIGNED NULL,
    header_override JSON NULL,                        -- for individual / Pro Plus multi-header
    paper_settings  JSON NULL,                        -- margins, orientation overrides
    question_ids    JSON NOT NULL,                    -- ordered list of question IDs
    total_marks     DECIMAL(8,2) NULL,
    status          ENUM('draft', 'final') NOT NULL DEFAULT 'draft',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL,
    INDEX idx_user (user_id)
) ENGINE=InnoDB;

-- Paper headers (Pro Plus multi-header)
CREATE TABLE IF NOT EXISTS paper_headers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    label           VARCHAR(120) NOT NULL DEFAULT 'Default',
    school_name     VARCHAR(255) NOT NULL,
    extra_line      VARCHAR(255) NULL,
    logo_path       VARCHAR(500) NULL,
    is_default      TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB;

-- Seed global terms; subjects and classes are created by each user.
INSERT IGNORE INTO terms (id, school_id, name, sort_order) VALUES
(1, NULL, '1st Term', 1),
(2, NULL, '2nd Term', 2),
(3, NULL, '3rd Term', 3),
(4, NULL, 'Mid-Term', 4),
(5, NULL, 'Mock Exam', 5);

