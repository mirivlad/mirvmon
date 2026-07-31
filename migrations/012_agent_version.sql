-- Reported by the agent with each sample, so an outdated host is visible.
ALTER TABLE servers
    ADD COLUMN agent_version VARCHAR(32);
