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

-- ##################################################################################################################
-- GITHUB_REPO_STAT (cached star count per tracked repo, lazily refreshed on read)
-- A single row per repo. The web tier reads this on every page; if
-- last_checked_at is older than the refresh window, the read path tries one
-- live GitHub API call before returning. last_checked_at is bumped on every
-- attempt (success or failure) so a flaky API does not get hammered.
-- ##################################################################################################################
CREATE TABLE network.github_repo_stat
(
    repo              varchar(128) PRIMARY KEY,
    stargazers_count  integer      NOT NULL DEFAULT 0,
    last_checked_at   timestamptz  NOT NULL DEFAULT to_timestamp(0),
    last_success_at   timestamptz  NULL
);
