ALTER TABLE exam_papers
    ADD COLUMN updated_by INT UNSIGNED NULL AFTER user_id,
    ADD INDEX idx_school_papers (school_id, updated_at),
    ADD CONSTRAINT fk_exam_papers_updated_by
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS exam_paper_revisions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    paper_id        INT UNSIGNED NOT NULL,
    school_id       INT UNSIGNED NOT NULL,
    revision_number INT UNSIGNED NOT NULL,
    changed_by      INT UNSIGNED NULL,
    snapshot        JSON NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (paper_id) REFERENCES exam_papers(id) ON DELETE CASCADE,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_exam_paper_revision (paper_id, revision_number),
    INDEX idx_exam_paper_revisions_school (school_id, paper_id, revision_number)
) ENGINE=InnoDB;
