CREATE TABLE network_stats (
    id           bigserial PRIMARY KEY,
    metric       varchar(64) NOT NULL,
    value        numeric(20, 6) NOT NULL,
    captured_at  timestamptz NOT NULL DEFAULT now(),
    metadata     jsonb NOT NULL DEFAULT '{}'::jsonb
);

CREATE INDEX network_stats_metric_captured_at_idx
    ON network_stats (metric, captured_at DESC);

CREATE INDEX network_stats_captured_at_idx
    ON network_stats (captured_at DESC);
