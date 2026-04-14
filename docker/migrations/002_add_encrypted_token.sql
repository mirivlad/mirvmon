-- 002: Добавляем encrypted_token в agent_tokens

ALTER TABLE agent_tokens ADD COLUMN IF NOT EXISTS encrypted_token TEXT AFTER token_hash;
