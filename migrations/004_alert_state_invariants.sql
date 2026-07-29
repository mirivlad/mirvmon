CREATE UNIQUE INDEX alerts_one_active_metric_idx
    ON alerts(server_id, metric_id)
    WHERE resolved = FALSE AND kind = 'metric';

CREATE UNIQUE INDEX alerts_one_active_service_idx
    ON alerts(server_id, subject)
    WHERE resolved = FALSE AND kind = 'service';

CREATE UNIQUE INDEX alerts_one_active_offline_idx
    ON alerts(server_id)
    WHERE resolved = FALSE AND kind = 'offline';
