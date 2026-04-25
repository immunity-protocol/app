-- ##################################################################################################################
-- STAT (time-series snapshots of network-wide metrics)
-- ##################################################################################################################
CREATE TABLE network.stat
(
    id           bigserial PRIMARY KEY,
    metric       varchar(64) NOT NULL,
    value        numeric(20, 6) NOT NULL,
    captured_at  timestamptz NOT NULL DEFAULT now(),
    metadata     jsonb NOT NULL DEFAULT '{}'::jsonb
);

CREATE INDEX stat_metric_captured_at_idx ON network.stat (metric, captured_at DESC);
CREATE INDEX stat_captured_at_idx        ON network.stat (captured_at DESC);
