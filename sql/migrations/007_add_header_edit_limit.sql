ALTER TABLE paper_headers
    ADD COLUMN details_edit_used TINYINT(1) NOT NULL DEFAULT 0 AFTER is_default;
