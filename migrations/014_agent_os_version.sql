-- Reported by native agents; UI exposure is intentionally deferred.
ALTER TABLE servers
    ADD COLUMN os_version VARCHAR(255);
