ALTER TABLE agent_tokens
    ADD COLUMN token_generation BIGINT;

COMMENT ON COLUMN agent_tokens.token_generation IS
    'Deterministic token generation; NULL marks a pre-generation legacy token.';
