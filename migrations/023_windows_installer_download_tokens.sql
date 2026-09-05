CREATE TABLE windows_installer_download_tokens (
    id BIGSERIAL PRIMARY KEY,
    server_id BIGINT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMPTZ NOT NULL,
    consumed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX windows_installer_download_tokens_active_idx
    ON windows_installer_download_tokens(token_hash, expires_at)
    WHERE consumed_at IS NULL;
