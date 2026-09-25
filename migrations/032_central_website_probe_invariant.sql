UPDATE websites
SET central_probe_enabled = TRUE
WHERE central_probe_enabled = FALSE;

ALTER TABLE websites
    ADD CONSTRAINT websites_central_probe_always_enabled
    CHECK (central_probe_enabled = TRUE);
