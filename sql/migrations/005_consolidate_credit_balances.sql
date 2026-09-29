-- Consolidate feature-specific credit pools into the original shared balance.
-- Preserve the lowest remaining pool so old usage is not restored.
UPDATE users
SET credits = LEAST(credits, ai_credits, ocr_credits, export_credits);

UPDATE schools
SET credit_balance = LEAST(credit_balance, ai_credits, ocr_credits, export_credits);

ALTER TABLE users
    DROP COLUMN ai_credits,
    DROP COLUMN ocr_credits,
    DROP COLUMN export_credits;

ALTER TABLE schools
    DROP COLUMN ai_credits,
    DROP COLUMN ocr_credits,
    DROP COLUMN export_credits;
