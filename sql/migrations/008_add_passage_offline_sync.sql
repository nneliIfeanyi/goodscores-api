ALTER TABLE passages
    ADD COLUMN offline_id VARCHAR(64) NULL AFTER id,
    ADD UNIQUE KEY uq_passage_user_offline (user_id, offline_id);
